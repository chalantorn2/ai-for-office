# Nova — Project Notes

Internal assistant for the SevenSmile / INDO Smile offices. Handoff from the planning
conversation held in the `contactrate-web-sevensmile` repo (2026-07-22), revised
2026-07-22 after querying production directly and again 2026-07-23 after adding
spend controls.

> **A note on this document.** Several claims here went stale within a day — the
> open items still listed `git init` and "deploy" long after both were done, and
> the system prompt asserted hotel counts that nothing rechecked. Figures that
> describe the data are now computed at runtime (`api/lib/stats.php`); figures
> written here are a snapshot with a date on them. When the two disagree, the
> code is right.

## Goal

An internal assistant that answers questions over the office's existing data instead
of staff hand-searching and hand-building tables.

Driving example from the user:

> "โรงแรมติดหาดในกระบี่ ขอราคาเดือนตุลา"

Today a person opens the web app, filters, opens each hotel, copies rates, and formats
a table by hand. The assistant should return that table directly, with links back into
the main app for detail.

## Decisions (2026-07-22)

| | |
|---|---|
| Name | **Nova** |
| Shape | ChatGPT-style single chat page — sidebar of past chats, centered thread, composer at the bottom. No dashboard, no filter forms (that is what the main app already is). |
| Scope | Everything in ContactRate: tours, hotels, rates, suppliers — not hotels alone. |
| Offices | Seven Smile and INDO Smile share one database. No tenant switcher. |
| Auth | Required. JWT + bcrypt. |
| Password migration | On first login, verify against the existing plaintext value, then store a bcrypt hash. Staff keep their current password and notice nothing; `users` converts itself as people sign in. Forcing a reset was rejected — it pushes staff toward weaker passwords and leaves the plaintext column populated anyway. |
| Chat history | Stored, per user. Two new tables, `ai_conversations` and `ai_messages`. Every query must filter on `user_id` — history is private. |
| Language | Answer in the language asked: Thai in, Thai out; English in, English out. |
| UI kit | Tailwind v4 + shadcn/ui (new-york, neutral, JSX not TSX). |

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

### Spend controls (added 2026-07-23)

Until this landed there was no ceiling of any kind: one member of staff could ask
two hundred questions in an afternoon and nothing in the system would notice. Two
limits now run before a turn is allowed to start (`nova_rate_limit_check`), both
checked *before* the SSE headers go out so a refusal is an ordinary 429 rather
than a stream that opens and dies:

| Limit | Default | Bounds |
|---|---|---|
| `daily_message_limit` | 80 messages per person per day | one person, or a wedged client retrying |
| `monthly_budget_thb` | 3,000 THB across the office per calendar month | the bill |

Both are overridable in `api/config.local.php` under `limits`, which takes effect
on the next request — the refusal messages tell staff to ask an admin to raise the
cap, and that had to be something an admin could actually do without a deploy.
`config.local.php` is the one file `deploy.ps1` never overwrites, so a raised cap
survives every deploy.

**Pricing lives in exactly one place**, `api/lib/usage.php`: rate table, the
cache-read (10%) and cache-write (125%) multipliers, `$10`-per-1,000 web search,
and the USD→THB rate. A turn is priced at the rate in force on the day it ran, so
August's history does not silently reprice itself in September when the
introductory rate lapses. `scripts/test-assistant.php` used to carry its own copy
of that table and now calls the same function — and asserts that the cost the API
reports for a turn matches what the shared code computes, because a test with its
own rate table will cheerfully confirm a number the product does not produce.

`GET /api/usage.php` reports the month; `?scope=all` adds the office total and a
per-user breakdown and is 403 for non-admins. The UI reaches it from the user menu.

**What `ai_messages` stores.** 001 recorded `input_tokens` and `output_tokens`
only — the two smallest numbers in a cached turn. `input_tokens` counts just the
*uncached* remainder, so a turn with the system prompt cached reports ~150 input
tokens and looks nearly free; the cache reads and writes are most of the bill. 002
adds `cache_read_tokens`, `cache_write_tokens`, `web_searches` and `model`.

Rows written before 002 have those columns NULL, so **historical spend reads low**
and is not recoverable — the data was never captured. Figures from before
2026-07-23 are a floor, not a total.

### Editing an earlier question (added 2026-07-23)

Hovering a question shows a pencil; editing it drops that question and every
turn after it, and asks the rewritten one. No branching — there is no version
switcher and no way back to what was replaced, which is why the count of what
is about to go is on screen while the question is being typed.

Three things this had to get right:

- **The delete and the re-ask are one request.** `assistant.php` takes
  `replace_from` (an `ai_messages` id) and supersedes from there before storing
  the new question. A separate endpoint would leave a window where a
  conversation has lost its tail and gained no replacement.
- **Superseded, not deleted.** `ai_messages` is the spend ledger as well as the
  transcript. `DELETE` would have let someone edit a question ten turns back and
  take that day's message count and that month's THB total down with it —
  silently, and in the direction that hides overspending. 004 adds
  `superseded_at`; the transcript filters on it, `nova_rate_limit_check` and
  `usage.php` deliberately do not. That money was spent.
- **The client needs real ids.** A message created locally carries a `local-…`
  key until the stream reports the row it became, so `meta` now carries
  `user_message_id` and `done` carries `assistant_message_id`. The local key
  stays as React's key and the server id rides alongside it — swapping the key
  remounts the bubble and replays its entrance mid-thread.

Editing is not offered while a reply is streaming: the reply being written is
one of the messages the edit would delete.

The same change replaced the `window.confirm` behind chat deletion with an
`AlertDialog`. Warnings inside the composer are inline for a reason — every
edit deletes something, so a modal on each one is a click to dismiss and
nothing more — but deleting a whole chat is rare and worth stopping for.

### Web search (added 2026-07-23)

Nova has `web_search` as a server-side tool, for the outside world only — weather,
ferry schedules, park fees, holidays. Office data still comes exclusively from the
ContactRate tools; the system prompt is explicit that a web result must never fill a
missing price, and the model labels external figures in the sentence itself.

Billed **$10 per 1,000 searches** on top of tokens. Measured locally against
production data, 2026-07-23, standard pricing:

| Turn | Cost |
|---|---|
| DB tools only (Phi Phi tour list) | ~1.8 THB |
| Web search, 1 search | ~2.4 THB |
| Answered from the system prompt, no tools | ~0.5 THB |

So a searching turn runs ~30% above a DB turn. Two things hold it down, both in
`nova_server_tool_definitions()`:

- **`allowed_callers: ['direct']`** — the important one, and counter-intuitive.
  From `_20260209` on, this field *defaults* to code execution, enabling dynamic
  filtering. Measured, that default is worse on both paths:

  | | filtered (default) | direct |
  |---|---|---|
  | park-fee lookup, 1 search | 3.45 THB | 1.45 THB |
  | average tour price, **0 searches** | 10.59 THB | 3.74 THB |

  Filtering earns its keep when a turn drags in a wall of results; Nova does single
  factual lookups, so there is little to filter and the filter code itself costs
  output tokens plus a cache write. The second row is the real trap: opting in
  provisions a code-execution sandbox for the whole turn, and the model then uses
  it on questions that never touched the web — that row is arithmetic over our own
  tour prices, at 7x a normal turn. Revisit only if Nova needs broad multi-source
  research.
- `max_uses: 3`. A vague question can otherwise run ten-plus searches. Going over is
  reported to the model as a tool error and is not billed.

History is also stored as plain markdown, so search results are never replayed on
later turns. Nova re-searches if it needs them again — cheaper than carrying 20k
tokens of encrypted result content through every turn.

Traps found while wiring it up:

- `user_location` rejects country code `TH` with a 400. Localisation has to ride the
  query text instead.
- **Do not relabel `code_execution` as web search in the UI.** Under dynamic
  filtering the first `server_tool_use` block is named `code_execution` with the
  search nested inside, which invites exactly that shortcut — it was written and it
  was wrong. The model uses the same sandbox for plain arithmetic, so staff saw
  "กำลังค้นหาข้อมูลจากเว็บ" on questions that never left our own data (`web_searches`
  was 0 while the indicator fired four times). Report server tools under their real
  names; the labels live in `chat-message.jsx`.
- A long search turn can stop with `stop_reason: "pause_turn"`. The loop has to send
  the assistant turn back unchanged and continue; the old code broke out of the loop
  and truncated the reply.
- `server_tool_use` streams its input as JSON fragments exactly like `tool_use`. It
  needs the same reassembly, or echoing the turn back on a `tool_use`/`pause_turn`
  round is a 400.
- `input_tokens` counts only the *uncached* remainder. With the system prompt cached,
  a turn reports ~150 input tokens and looks nearly free — the cache read/write
  counts have to be priced in separately or every estimate is an order of magnitude
  low. `scripts/test-assistant.php` now does this.

Enable web search for the org in the Console (`/settings/privacy`) or requests fail
with a 400 saying it is not enabled. No domain allowlist is set: Nova's external
questions are open-ended (weather, transport, news), and an allowlist narrow enough
to matter for cost would break the useful cases. `max_uses` is the cap instead.

## Data reality — verified, not assumed

Queried against production on 2026-07-22 via `scripts/db-query.ps1`.

| table | rows | note |
|---|---|---|
| **tours** | **280** | the largest table by far — the earlier draft of this note missed it entirely |
| tour_files | 528 | |
| hotel_rates | 494 | |
| supplier_files | 122 | |
| suppliers | 37 | |
| hotels | 30 | |
| users | 9 | |
| hotel_notices | 3 | |
| package_tours | **0** | empty — no tool needed |
| package_tour_items | 0 | empty |

Three findings that changed the plan:

1. **Tours are the main body of work, not hotels** — 280 rows against 30. The first tool
   should be `search_tours`, not `search_hotels`.
2. **Krabi exists — as tours, not hotels.** 45 Krabi tours, ฿200–18,000. The driving
   example fails only for the hotel half of the question.
3. **`tours.destination` is clean; `hotels.destination` is not.**

```
tours    Phuket 172 · Krabi 45 · Pattaya 16 · Samui 12 · Phang Nga 9 · Bangkok 3 · NULL 23
hotels   "Karon Beach, Phuket" · "Patong, Phuket" · "Patong Beach, Phuket" · "Phuket" … 19 variants
```

Tours can be filtered by province directly. Hotels need `LIKE '%Phuket%'` and still will
not answer "beachfront" — that is not a field anywhere.

Relevant schema (from `database/add_hotels_table.sql`, `add_hotel_rates_table.sql` in the
main repo):

- `hotels`: `destination`, `stars`, `amenities` (JSON array as TEXT), `room_types`,
  `rate_validity`, `child_policy`, `rate_terms`, `description`
- `hotel_rates`: `hotel_id`, `room_type`, `period_label`, `period_start`, `period_end`,
  `meal_plan` (RO/RB/NULL), `price`, `currency`, `is_active`
- `tours`: `supplier_id`, `tour_name`, `departure_from`, `destination`, `pier`,
  `tour_type`, `adult_price`, `child_price`, `start_date`, `end_date`, `notes`,
  `park_fee_included`, `park_fee_adult`, `park_fee_child`, `map_url`

`period_start`/`period_end` are real DATEs, so month filtering ("October") works directly.
That part is in good shape.

### The open design problem

The driving example cannot currently be answered, and not because of the model:

1. **No Krabi *hotels* exist.** All 30 are Phuket / Bangkok / Pattaya. (Krabi *tours* do
   exist — 45 of them.)
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

### How this was resolved — counted, not asserted (2026-07-23)

The prompt used to state the answers: "30 hotels", "37 suppliers", "there are **no
Krabi hotels**". Every one was correct the day it was typed and checked by nothing
afterwards. The failure mode is quiet and total — add a Krabi hotel to ContactRate
and Nova goes on denying it exists, with no error anywhere.

`api/lib/stats.php` reads the counts at request time and folds them into the
prompt, cached to a temp file for an hour. The same figures replace the hardcoded
numbers in the tools' "no results" notes. The output today happens to match what
was written by hand — 280 tours, 30 hotels, 18 with rates, 494 rates, 37 suppliers
— which is the point: same answer, but now it moves when the data does.

Two things the derivation had to get right:

- **The last comma-separated segment is not always the province.** Two-part rows
  end in it ("Karon Beach, Phuket"); three-part rows end in "Thailand" ("North
  Pattaya, Chonburi, Thailand"). Splitting in SQL produced a "Thailand" bucket;
  the split moved to PHP, dropping the country name first.
- **Province alone would have been worse than the bug it replaced.** The column
  says *Chonburi*; staff say *Pattaya*. A summary listing only provinces would
  have had Nova denying we have Pattaya hotels. Sub-locality names are listed
  alongside, and `search_hotels` still matches loosely against the whole string.

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
4. **`api/config.php` is corrupted locally — confirmed.** Every `c` is an `o`
   (`funotion`, `looalhost`, `applioation`). The file cannot parse. `api/users.php` and
   `api/public/*.php` require it. Unconfirmed whether the deployed copy is intact; check
   whether User Management still works in production.
5. **DB credentials are hardcoded in `api/config.php`**, which is *not* in `.gitignore`
   (only `deploy.secret.ps1` is). If that repo was ever pushed to GitHub, the production
   password is public.
6. **MySQL 3306 accepts connections from the internet.** Verified — this machine connects
   to `203.170.190.139:3306` directly. Combined with finding 5, anyone with the repo has
   full read/write on production.

Note that an assistant makes exposure worse, not better: it turns "know the URL" into
"ask in Thai and get a formatted table". Auth must land before the LINE phase, since a
LINE bot is reachable by anyone who adds it.

## Dev access to the data

There is **no local copy of the database.** The main app's `.env.local` points at
`https://contactrate.sevensmiletourandticket.com/api`, so even local development has
always read from production. XAMPP is installed but holds no `sevensmile_contactrate`
schema. The `sevensmile_contactrate.sql` dump in the main repo is from 2026-06-25 and
predates the hotel tables entirely.

Development therefore queries production directly, through a guarded script:

```
./scripts/db-query.ps1 "SELECT ... FROM tours WHERE destination = 'Krabi'"
```

`scripts/db-query.php` rejects anything that is not a single SELECT / SHOW / DESCRIBE /
EXPLAIN, and opens the session with `SET SESSION TRANSACTION READ ONLY`. Credentials come
from the main repo's gitignored `deploy.secret.ps1` and are passed through the
environment, never on a command line. Writes to production are deliberately impossible
through this path — schema changes go through a separate, reviewed step.

## Plan

| Phase | Work | Est. | Status |
|---|---|---|---|
| 0 | Tailwind + shadcn, chat UI shell | — | **done** |
| 1 | JWT + bcrypt auth, `ai_conversations` / `ai_messages`, login page | 2-3 days | **done** — except the read-only DB user |
| 2 | `assistant.php` tool loop, streaming, wire the UI to it | 2-3 days | **done** — verified end to end |
| 2.5 | Spend controls, live data counts, deep links, chat rename | 1 day | **done** 2026-07-23 |
| 2.6 | Character, per-person profiles, office context | — | **done** 2026-07-23 |
| 3 | LINE OA webhook + LINE-user-to-staff mapping | 2-3 days | not started |

Phase 1 left open: the `ai_readonly` MySQL user. The application credentials hold no
GRANT OPTION (`SHOW GRANTS` confirms USAGE plus full DML/DDL on the one schema, nothing
more), so the user has to be created from the hosting control panel. Until then the API
connects with the existing read-write account.

### Measured behaviour (2026-07-22, local against production data)

| Question | Tools | Time | Cost |
|---|---|---|---|
| ทัวร์กระบี่ ราคาไม่เกิน 1500 | `search_tours` | 18.6s | ~1.37 THB |
| ภูเก็ตเรามีอะไรบ้าง | `search_tours` + `search_hotels` | 25s | ~1.60 THB |
| 4-turn conversation | 9 calls total | — | ~2.76 THB |

Roughly 1.4–1.6 THB per substantive question, matching the original estimate. A greeting
costs almost nothing. First byte is consistently ~0.2s — the UI starts streaming long
before the answer is complete.

### Things that are known and deliberate

- **`search_tours` returning 36 rows costs ~3,700 tokens.** If spend runs high, drop
  `start_date`/`end_date` from the list result (~25% saving) and let the model call
  `get_tour_details` when validity actually matters.
- **The area list in the prompt includes street names.** Deriving hotel locations from
  free text yields "Soi Chalermphakiat 2" alongside "Patong Beach". Filtering by
  heuristic would be fragile and the list is inside the cached prefix, so
  completeness won that trade.
- **Tool calls are not replayed into later turns.** History stores the final text of each
  turn only, which keeps context small; the tradeoff is that Nova cannot refer back to a
  specific row it fetched three turns ago without fetching it again.

### The production host eats `Authorization` (found 2026-07-23)

Auth worked in local development and had **never worked in production** — nobody
had signed in on the server until today. The symptom was precise and misleading:
login succeeded, then the very next request 401'd, and the UI did what it was
built to do with a 401 and logged the person out mid-sentence.

Measured with a temporary probe endpoint rather than guessed at. Production is
nginx in front of PHP-FPM (`fpm-fcgi`, `X-Accel-Internal` in the request), and it
drops `Authorization` specifically while passing everything else through. A
single request carrying both `Authorization` and `X-Nova-Token` arrived at PHP
with only the second:

```
HTTP_AUTHORIZATION           absent
REDIRECT_HTTP_AUTHORIZATION  absent
HTTP_X_NOVA_TOKEN            present
```

`jwt_from_request()` already had an `apache_request_headers()` fallback for this
class of problem. It does not help: the header is gone before PHP is reached, so
every way of asking PHP for it returns nothing.

The fix is a second header, `X-Nova-Token`, carrying the same JWT. Both are sent
and either is accepted — `Authorization` stays because it is the correct header
and works everywhere else, including local dev. The custom header is not a weaker
credential; it is the same token, verified the same way, in an envelope this host
does not open.

Two things worth knowing:

- A query parameter would also have survived (`?token=` arrives intact) and was
  rejected: tokens in URLs end up in access logs and `Referer` headers.
- The tidier fix is on the host, not in the code — `fastcgi_param
  HTTP_AUTHORIZATION $http_authorization;` in Plesk's additional nginx
  directives. Worth doing if the panel allows it, but the code must not depend
  on it: a setting that lives only in a control panel is one restore away from
  breaking login again with no trace in the repo.

### A trap worth remembering

Sonnet 5 runs adaptive thinking by default. When a turn ends in `tool_use`, the thinking
blocks must be echoed back **including their `signature`**, which arrives as its own
`signature_delta` SSE event. Dropping it produces a 400 on the *next* round — so simple
questions work and anything needing thought plus a tool fails. `api/lib/anthropic.php`
accumulates `thinking_delta` and `signature_delta` for this reason; do not "simplify" them
away because the thinking text is never displayed.

Relatedly: an HTTP error from the API arrives as a plain JSON body, not as SSE, so an SSE
frame parser sees nothing and the real message is lost. The client keeps the first 4KB raw
for exactly this case.

~6-9 working days total.

Tool order follows the data: `search_tours` first (280 rows, clean `destination`), then
`search_hotels` + `get_hotel_rates`, then `search_suppliers`. Skip `package_tours` — empty.
Keep responses compact: select only the fields the question needs. Returning full
`amenities` JSON for 30 hotels burns ~10k tokens answering a price question.

### Tools as they stand (2026-07-23)

`search_tours` · `get_tour_details` · `search_hotels` · `get_hotel_rates` ·
`get_hotel_details` · `search_suppliers` · `get_supplier_files`, plus server-side
`web_search`.

Three things added on 2026-07-23, each because of what the data turned out to be:

- **`valid_month` on `search_tours`.** `period_start`/`period_end` on rates were
  always real DATEs, but tours are messier: **99 of 280 have no validity dates at
  all**. A plain `BETWEEN` would have hidden a third of the catalogue from every
  question that names a month, silently and plausibly. NULL passes the filter — a
  blank period means year-round, not expired — and the prompt says so, because
  "no dates" reads as "expired" to a person too.
- **`get_supplier_files`.** 119 of the 122 supplier files are contact rate sheets:
  the original PDFs the office negotiates against, carrying terms that never made
  it into the tour rows. Nova cannot read them and says so; pointing at the right
  one still saves the hunt.
- **Brochures on `get_tour_details`.** Only 34 of 280 tours have files and 487 of
  the 528 are gallery photos, so this is a count plus the brochure links rather
  than a tool of its own — a tour with 40 photos would otherwise flood the turn.

`search_hotels`, `get_hotel_rates` and `get_hotel_details` now filter
`is_active = 1`. All 30 hotels are active today, so this changes nothing yet;
without it, a withdrawn hotel's rate would eventually reach staff as a live price.

## Open items

- [x] Anthropic API key — in `api/config.local.php`, working
- [x] **`git init`** — done; two commits on `main`.
- [x] **Deploy** — live at https://ai.sevensmiletourandticket.com since 22 Jul 17:02.
      Verify what is actually *on* the server before believing this line: the
      deployed bundle can lag the local build by days, and the symptom is silent
      (staff simply use an older Nova). `./scripts/ftp-ls.ps1 /ai.sevensmiletourandticket.com/api`
      shows the server's file timestamps; the asset hash in the live `index.html`
      should match `dist/assets/`.
- [ ] **Delete the test row in production.** `tours` id 309, "Test from Claude",
      `updated_by = claude-code`, inserted 2026-07-22 09:30 by an earlier session. It is
      real production pollution sitting among 280 genuine tours and Nova surfaces it in
      Krabi searches. Needs a hand-run statement — `scripts/db-migrate.php` rejects
      DELETE and anything outside `ai_` tables, deliberately:
      `DELETE FROM tours WHERE id = 309 AND tour_name = 'Test from Claude';`
- [ ] Create the `ai_readonly` MySQL user from the hosting control panel
- [ ] Confirm whether User Management works in production (the `config.php` question)
- [ ] LINE Channel Access Token + Channel Secret (phase 3 only)

## Repo state (2026-07-23)

Frontend and backend both complete and wired end to end. Under git on `main`.

```
api/
  config.php                   bootstrap: config, PDO, JSON helpers, CORS allowlist
  config.example.php           template — copy to config.local.php (gitignored)
  auth.php                     POST login -> JWT; GET ?me
  conversations.php            history CRUD, every statement scoped to user_id
  assistant.php                SSE tool loop, rate limit, usage persistence
  usage.php                    month-to-date spend; ?scope=all is admin-only
  lib/jwt.php                  hand-rolled HS256 (no Composer on shared hosting)
  lib/auth.php                 require_user(), plaintext -> bcrypt upgrade
  lib/anthropic.php            streaming Messages API client over cURL
  lib/tools.php                the six ContactRate tools + web search definition
  lib/stats.php                live data counts for the prompt, file-cached 1h
  lib/usage.php                the one pricing table + both spending limits
src/
  App.jsx                      session gate, chat state, streaming send
  lib/api.js                   fetch wrapper, token in localStorage
  lib/table-export.js          reads a rendered table to CSV / TSV
  components/
    login-page.jsx             username + password form
    nova-mark.jsx              the four-point star used as logo and avatar
    chat/
      chat-sidebar.jsx         history grouped by age, inline rename, user menu
      chat-message.jsx         markdown, per-table copy/export, tool indicator,
                               inline editor for an earlier question
      chat-composer.jsx        auto-growing textarea, Enter sends
      empty-state.jsx          greeting + 4 suggestion cards
      usage-panel.jsx          month-to-date spend against the budget
    ui/                        shadcn components (eslint-ignored — generated)
database/
  001_ai_tables.sql            applied to production 2026-07-22
  002_usage_tracking.sql       applied to production 2026-07-23
  003_user_profiles.sql        applied to production 2026-07-23
  004_message_edits.sql        applied to production 2026-07-23
  profiles.json                who each person is, hand-edited, no UI
scripts/
  db-query.ps1 / .php          read-only production query runner
  db-migrate.ps1 / .php        additive-only migration runner (ai_ tables only)
  sync-profiles.ps1 / .php     upserts profiles.json into ai_user_profiles
  ftp-ls.ps1                   read-only remote listing — what is actually deployed
  setup-local-config.ps1       generates api/config.local.php + a random JWT key
  make-server-config.ps1       generates the server's secrets file, incl. limits
  test-jwt.php                 15 checks: signing, tampering, alg:none, expiry
  test-tools.php               every tool against real data; prints token cost
  test-assistant.php           end-to-end SSE; asserts reported cost matches
```

### How staff are named (2026-07-23)

`users` gained `full_name`, `nickname`, `office` (`sevensmile` | `indosmile` |
`both`) and `position`. Nova addresses people the way the office does:

| | shown as |
|---|---|
| one office | nickname · office — *เลย์ · Seven Smile* |
| `both` | nickname · position — *พี่ไข่ตุ๋น · GM* |

`both` takes the position instead because the office name is exactly the thing
that fails to distinguish those people. Composed once in `nova_user_display()`
(`api/lib/auth.php`) and used by the login response, the chat sidebar, the
per-user spend table, and the system prompt — one rule, one place.

Every part is nullable in the database, so each step falls back rather than
rendering a gap: nickname → full name → username, and an absent suffix is
dropped. Two of the nine accounts have no nickname today and correctly show
their login instead.

**`require_user()` now reads `users` on every request** instead of trusting the
token's claims. That is one primary-key lookup, and it buys three things: a
nickname edit appears immediately rather than when the 12-hour token expires,
revoking an admin takes effect at once rather than at their next login, and a
deleted account's outstanding token stops working. The JWT still carries
`username` and `role`, but nothing reads them — only `sub` is load-bearing.

Nova is also told the person's office and position. Seven Smile and INDO Smile
share one database, and an accountant and a tour operator want different things
from the same rate.

### Character, and knowing who is asking (2026-07-23)

Nova is **โนวา** in Thai: male, speaks with ครับ/ผม, the junior in an office where
almost everyone is older. Friendly in tone, serious about the work.

The one rule that makes this safe is written into the prompt as the first line of
the list: **เป็นกันเองที่น้ำเสียง ไม่ใช่ที่ข้อมูล.** A warm persona pulls a model toward
filling gaps and hedging, which is exactly what the Grounding section forbids —
so the friendliness is scoped to voice and explicitly denied over facts. A second
section states that Nova is not a developer: no talk of the database or tools, no
offering to fix anything (it is read-only), and questions outside the job get a
short "not my job" plus what it *can* do.

`position` in `users` was never enough to answer well. It does not say that
พี่หนุ่ย handles flight tickets and now books tours too, or that พี่ดา has done this
long enough not to want the basics explained, or that การ์ตูน is new and needs
telling that the prices are net cost. **`ai_user_profiles`** (003) holds one free
prose paragraph per account, folded into the system prompt for that person only.

- A separate `ai_` table rather than a column on `users`: the old ContactRate app
  owns that table and has a User Management screen over it, and
  `db-migrate.php` refuses anything outside `ai_` on purpose.
- Source of truth is **`database/profiles.json`**, edited by hand — no UI, by
  request. `./scripts/sync-profiles.ps1` upserts it and shows a NEW/UPDATE/same
  diff first; `-DryRun` sends nothing. Changes apply to the next question with no
  deploy.
- Every text is billed on every turn that person takes, so they are a few lines
  each.
- Optional throughout. A new account with no entry still works — Nova falls back
  to nickname, office and position.
- **`about` never reaches the browser.** `require_user()` reads it, and
  `GET /api/auth.php?me` unsets it before responding.

Nova also gets a **roster** of everyone else — name, office, job, nothing more —
so it can say "ตั๋วเครื่องบินถามพี่หนุ่ยได้" instead of stopping at "ไม่มีข้อมูล". Other
people's `about` is deliberately withheld: that text is written to shape how Nova
answers *that* person and is not everyone's to read. The roster skips accounts
with no name at all (the shared `sevensmile` login) and lists one line per person
rather than per login, since เลย์ has two.

The office structure — two companies, one GM, INDO Bangkok splitting Krabi to
Seven Smile and Phuket to INDO Smile — is static prompt text, not derived. It is
also fenced: it is context for understanding who does what, **never a filter.**
Biasing search results by the asker's office would hide real rows from staff who
would never know it happened. ContactRate holds no booking data anyway, so Nova
cannot tell which agent a job came from; the split becomes actionable only if the
booking system is wired in later.

**Ordering trap:** the profile is read with a `LEFT JOIN ai_user_profiles` on the
request that authenticates every call. Deploy this code before running 003 and
every request fails on a missing table. Migration first, then deploy.

### Deep links back to ContactRate

Every tool result now carries a `link`, and the prompt requires the record's name
to be a markdown link to it. Staff read a price in Nova and act on it in the main
app, and the driving example always assumed that hop.

```
tour      /edit/{id}                 hotel  /hotel/view/{slug}
supplier  /suppliers/{id}            file   /{file_path} (uploads/… on the main docroot)
```

Nova never builds a URL itself — it uses the one the tool returned, or none. The
id stays inside the href; staff see the name.

### A layout bug worth remembering

Radix's `ScrollArea` wraps viewport children in a `display: table` div, which
sizes to content rather than to the panel. The sidebar rows came out ~25px wider
than the 256px sidebar, so the row's delete button sat under the clipped edge —
present, hoverable by the accessibility tree, invisible to a person. `truncate`
also needs a definite width and was not getting one. The fix is a variant on the
`ScrollArea` in `chat-sidebar.jsx` forcing that wrapper back to `block`; it cannot
go in `components/ui/scroll-area.jsx`, which shadcn regenerates.

Local development runs two servers: `bun run dev` (5173/5174) and
`php -S 127.0.0.1:8000 -t .`; `vite.config.js` proxies `/api` to the latter.

What is still mocked: the assistant's reply. `App.jsx` marks it `TODO(phase 2)` — that is
where `api/assistant.php` plugs in, and it will also be what writes turns into
`ai_messages`. Conversations themselves are already created and listed for real.

Note for phase 2: PowerShell 5.1 writes UTF-8 **with** a BOM, and a BOM ahead of `<?php`
is echoed into the response body and corrupts every JSON payload. `setup-local-config.ps1`
uses `UTF8Encoding($false)` for this reason — any script that generates PHP must do the same.
