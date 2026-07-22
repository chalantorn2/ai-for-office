# SevenSmile Office AI — Project Notes

Handoff from the planning conversation held in the `contactrate-web-sevensmile` repo
(2026-07-22). Read this first; it is the only record of those decisions.

## Goal

An internal assistant that answers questions over the office's existing data instead
of staff hand-searching and hand-building tables.

Driving example from the user:

> "โรงแรมติดหาดในกระบี่ ขอราคาเดือนตุลา"

Today a person opens the web app, filters, opens each hotel, copies rates, and formats
a table by hand. The assistant should return that table directly, with links back into
the main app for detail.

## Why a separate project

The existing app (`contactrate-web-sevensmile`) is deployed over FTP to shared hosting
and is in daily use. Building here means:

- no risk to the working system
- proper auth from day one instead of retrofitting it (see Security below)
- independent deploys

Trade-off accepted: staff will have two logins for a while. Both read the same `users`
table but the password hashes differ (old app stores plaintext, this one will use bcrypt).

## Stack (decided)

Production runs PHP 8.3.32 on the shared host. That was the deciding fact — modern PHP
makes a PHP backend a good choice, not a fallback. Node/Bun cannot run there; bun is
local dev only.

```
ai.sevensmiletourandticket.com/
  index.html + assets/   <- Vite + React 19 (the scaffold already in this repo)
  api/assistant.php      <- tool-calling loop + Anthropic API via cURL
  api/auth.php           <- JWT + password_hash/password_verify
  api/line.php           <- LINE webhook (phase 3)
              |
              v  localhost, dedicated read-only MySQL user
       sevensmile_contactrate
```

Deploy reuses the FTP pattern from the main repo (`deploy.ps1` / `deploy.common.ps1`).

Rejected: Node/Hono (host cannot run it), cloud backend on Vercel/Railway (would require
exposing MySQL 3306 to the internet from rotating serverless IPs).

## Approach: tool-calling, not RAG

The data is structured MySQL, so give the model tools that query it rather than embedding
documents into a vector store. Vector search cannot answer "which rates are under 1500"
or aggregate across rows; SQL can. Tools also keep answers live without re-indexing, and
let permissions be enforced in the query layer.

Start read-only. No write tools until the read path is trusted.

Hard requirement: a dedicated MySQL user with `SELECT` only.

```sql
GRANT SELECT ON sevensmile_contactrate.* TO 'ai_readonly'@'localhost';
```

Safety should come from grants, not from asking the model nicely.

## Cost

Model: **Claude Sonnet 5**, not Opus. The work is fetch-and-format, which Sonnet handles;
Opus costs ~2.5x for no benefit here. Revisit only if specific questions measurably fail.

Per question, assuming a 3-round tool loop (~12k fresh input, ~9k cached read, ~1.7k output):

| | Sonnet 5 (intro) | Sonnet 5 (standard) |
|---|---|---|
| per query | ~$0.043 (~1.5 THB) | ~$0.065 (~2.3 THB) |
| 300 queries/mo | ~450 THB | ~670 THB |
| 1,000 queries/mo | ~1,500 THB | ~2,240 THB |
| 3,000 queries/mo | ~4,500 THB | ~6,700 THB |

Budget ~2,000 THB/month for an office of this size.

Two things to watch:

- Intro pricing ($2/$10 per MTok) ends **31 Aug 2026**, then $3/$15 — a 50% rise.
  Budget against the standard column.
- Cost scales with how much data the tools stuff into context, not just model choice.
  Returning full `amenities` JSON for 30 hotels burns ~10k tokens answering a price
  question. Compact tool responses can cut spend ~40%.

## Data reality — verified, not assumed

Queried against production on 2026-07-22.

| fact | value |
|---|---|
| hotels | 30 |
| distinct `destination` values | 19 |
| `hotel_rates` rows | 494 |
| hotels that have any rate | **18 of 30** |

Relevant schema (from `database/add_hotels_table.sql`, `add_hotel_rates_table.sql` in the
main repo):

- `hotels`: `destination`, `stars`, `amenities` (JSON array as TEXT), `room_types`,
  `rate_validity`, `child_policy`, `rate_terms`, `description`
- `hotel_rates`: `hotel_id`, `room_type`, `period_label`, `period_start`, `period_end`,
  `meal_plan` (RO/RB/NULL), `price`, `currency`, `is_active`

`period_start`/`period_end` are real DATEs, so month filtering ("October") works directly.
That part is in good shape.

### The open design problem

The driving example cannot currently be answered, and not because of the model:

1. **No Krabi hotels exist.** All 30 are Phuket / Bangkok / Pattaya.
2. **"Beachfront" is not a field.** `destination` is free text — `"Karon Beach, Phuket"`,
   `"Patong Beach, Phuket"` hint at it, but three rows are just `"Phuket"`. `amenities`
   is prose with no beachfront tag.
3. **40% of hotels have no rates at all.**

So the first real design decision is not prompt wording — it is how the assistant behaves
when the data cannot support the question. It must say "no Krabi hotels in the system" and
"12 hotels have no rates loaded", never infer or fill gaps. Grounding rules and tool
responses that distinguish *absent* from *empty* matter more than model choice.

Longer term this argues for structuring `destination` (province/area) and adding real
amenity tags. Worth scoping separately.

## Security findings in the existing system

Found while checking whether this project could reuse the old auth. It cannot — there is
none. These are defects in `contactrate-web-sevensmile`, not blockers here, but they are
unfixed and should be tracked on their own.

1. **No authentication on any endpoint.** No token, session, or `Authorization` handling
   anywhere in `api/*.php`. `api/auth.php` returns the user object on login and issues
   nothing, so later requests cannot identify the caller.
2. **Data endpoints are publicly reachable.** e.g. `api/hotel-rates.php` sets
   `Access-Control-Allow-Origin: *` with no authorization check, exposing
   `GET ?hotel_id=N` (net cost rates — competitive data) and `DELETE ?hotel_id=N`
   (destroys all rates for a hotel) to anyone who knows the URL.
3. **Plaintext passwords.** `api/auth.php` compares `$user['password'] !== $data['password']`.
4. **`api/config.php` is corrupted locally** — every `c` is an `o` (`funotion`, `oatoh`,
   `looalhost`). It cannot parse. `api/users.php` and `api/public/*.php` require it.
   Unconfirmed whether the deployed copy is intact; check whether User Management still
   works in production.

Note that an assistant makes exposure worse, not better: it turns "know the URL" into
"ask in Thai and get a formatted table". Auth must land before the LINE phase, since a
LINE bot is reachable by anyone who adds it.

## Plan

| Phase | Work | Est. |
|---|---|---|
| 1 | Project setup, JWT + bcrypt auth, read-only DB user, first tools | 2-3 days |
| 2 | `assistant.php` tool loop, chat UI, streaming | 2-3 days |
| 3 | LINE OA webhook + LINE-user-to-staff mapping | 2-3 days |

~6-9 working days total.

Phase 2 ships the two tools that answer the driving example: `search_hotels` and
`get_hotel_rates`. Keep responses compact — select only the fields the question needs.

## Needed from the user

- [ ] Anthropic API key (console.anthropic.com; $5 credit is enough to test)
- [ ] Confirm whether User Management works in production (the `config.php` question)
- [ ] LINE Channel Access Token + Channel Secret (phase 3 only)

The API key is only required for end-to-end testing, so phases can start without it.

## Repo state as of this note

Bare Vite + React 19 scaffold (bun lockfile, ESLint). No git repo initialized. No backend
yet. Nothing here is load-bearing — the scaffold is a fine starting point for the frontend.
