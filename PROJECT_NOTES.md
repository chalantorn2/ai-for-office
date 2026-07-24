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

**Production runs a 1,500 THB monthly cap** (set 2026-07-23), not the 3,000
default — the server's `config.local.php` had no `limits` block at all until
then, so it was silently running on the code defaults. Raising it means editing
that one file on the server; re-running `make-server-config.ps1` would do it too
but generates a fresh JWT key and logs everyone out, so prefer editing in place.

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

### Voice mode — talking to Nova (added 2026-07-23)

A hands-free loop: listen → ask → speak → listen, until it is closed. The turn
underneath is an ordinary turn — same `assistant.php`, same tools, same rate
limit, same rows in `ai_messages` — so a conversation held out loud is in the
sidebar afterwards and scrolls back like any other. Nova searches ContactRate
mid-conversation exactly as it always did; the only new thing on screen during
the pause is which tool is running.

**Both halves run in the browser, and cost nothing.** The Anthropic API has no
speech of its own, so the alternative was a transcription vendor plus a voice
vendor — each billed per minute, and each another place the office's net cost
rates would have to travel to. Chrome's `SpeechRecognition` and
`speechSynthesis` do both for zero marginal cost: a spoken turn is priced
exactly like a typed one (~90 extra input tokens, below), and `src/lib/voice.js`
is the whole integration.

What that buys is also what it costs:

- **Chrome sends the audio to Google for recognition.** Not a licence Nova
  grants — it is how the Web Speech API works in that browser. Questions name
  hotels, tours and suppliers, so this is a decision for the office rather than
  a technical detail to leave in a file. Whisper would move the same audio to
  OpenAI instead; the only way to keep it in the building is not to do this.
- Recognition needs Chrome or Edge. The button is not rendered elsewhere —
  `VOICE_SUPPORTED` requires *both* halves, since half a conversation is not a
  feature. Safari and Firefox get the composer they already had.
- The Thai voice is whatever the machine ships. Test on the actual office
  machines before promising anything about how it sounds.

**A spoken answer is a different answer.** The house style everywhere else in
the prompt — lead with a markdown table, make every record name a link back to
ContactRate — is exactly wrong out loud: a table has no spoken form, and a link
read aloud is the URL, character by character. `NOVA_VOICE_HINT` in
`assistant.php` asks for one to three spoken sentences, no tables, no links, at
most three items before offering to narrow down.

It rides the **user** turn, not the system prompt. The system prompt and tool
schemas are one cached prefix shared by every request; appending to it would
rewrite that prefix and turn every voice turn into a full cache write, which is
the same reason projects carry no per-project instructions. Sonnet 5 has no
mid-conversation `system` role either (that is Opus 4.8 only). Sitting at the
end of the last user message costs ~90 tokens and invalidates nothing. It is
also deliberately **not** stored in `ai_messages`: the transcript should be the
question the person asked, and editing that question later must not replay it.

Three things this had to get right:

- **The microphone and the speakers never run at once.** They hear each other —
  Nova's own reply arrives as the next question and the loop feeds on itself.
  Each phase owns the audio outright, which is why there is no barge-in: talking
  over Nova means pressing "ข้าม", not shouting.
- **`speak()` has a keep-alive on a timer.** Chrome stops a long utterance after
  ~15 seconds with no event of any kind — the answer goes quiet mid-sentence and
  the loop waits forever for an `onend` that never arrives. Pausing and resuming
  every 10s keeps it running. It is the standard workaround and there is no
  better one.
- **A silent turn is not an error.** `no-speech` fires whenever somebody pauses
  to think; treating it as a failure would put an error on screen every few
  seconds. It restarts listening instead, after a 250ms gap — Chrome refuses a
  `start()` that lands in the same tick as the `stop()`.

`send()` now resolves with the reply text. Voice mode needs it and cannot see
the thread to find it; reading it back out of state would mean chasing a message
through an update that has not necessarily landed. `stripForSpeech()` then
removes the markup and replaces a table with "มีตาราง N รายการอยู่บนหน้าจอ" —
the table really is on screen, because voice mode is a layer over the chat and
closing it puts the reader back in front of the figures. Prices heard once and
misheard are worse than no answer, so the overlay also prints the exchange as it
happens.

Recognition is one utterance per `start()`, not `continuous`. Continuous mode
keeps the microphone open and streams a running transcript, which means deciding
in application code when a sentence has ended; one utterance per start hands that
judgement to the browser's own endpointing.

#### What the first hour of voice found — a data bug, not a voice bug

Answers came back less accurate than in text chat. Asked "เรามีทัวร์ 4 เกาะ
ของใครบ้าง", Nova replied **"เจอทัวร์สี่เกาะแค่รายการเดียว"**. There are seven.
The same for "7 เกาะ". Nothing errored; the count was simply wrong, stated
plainly, and there is nothing on screen that would tell staff otherwise.

**`search_tours` matches `query` against `tour_name` with a plain `LIKE`, and
290 of the 292 tour names are in English.** Searching the Thai words "4 เกาะ"
reaches exactly one row — the one Thai-named longtail tour — and misses
`4 Islands Speed Boat`, `4 Island Catamaran (Upper Deck)` and four more. This was
true before voice mode existed and is true in text chat. What voice changed is
that spoken questions are *always* Thai, so a latent blind spot became the normal
case.

Three changes, at three different distances from the problem:

- **The prompt says so, with counted figures.** `nova_read_stats` now measures
  how many tour names contain Thai characters (`REGEXP '[ก-๙]'`), and
  `nova_stats_prompt` states the split with a translation table for the common
  terms — สี่เกาะ → 4 Island, เรือหางยาว → Longtail, ดำน้ำ → Snorkeling. Counted,
  not asserted, for the same reason as everything else in that function: the day
  someone renames the catalogue into Thai, the advice inverts itself.
- **The tool warns at the point of use.** A `query` containing Thai characters
  now comes back with a `warning` field saying the count is not the real count
  and to search again in English. The prompt is read once at the top of a turn;
  this arrives in the model's hands holding the wrong number. A count is the one
  thing a model repeats without questioning.
- **`query`'s description stopped implying either language works.** It gave
  `"phi phi"` and `"เกาะพีพี"` as equivalent examples. They are not.

The `nova_data_stats` cache filename went to **v3** — the shape changed, and
serving a v2 entry would mean an hour of missing `thai_named`.

Then the voice hint itself, which had made this worse. **Brevity instructions
compress the whole turn, not just its output.** "ตอบสั้น 1-3 ประโยค" and "บอก
รายการได้ไม่เกิน 3 อย่าง", sitting at the end of the user turn where recency
weighs most, cut how hard Nova searched as well as how much it said — fewer tool
calls, fewer wordings tried, and a confident count off the first one. The hint
now separates the two in as many words: *ความสั้นใช้กับคำพูดเท่านั้น ไม่ใช้กับ
การค้นข้อมูล*, plus *จำนวนต้องถูกเสมอ* — say the real total, then describe two or
three.

Also dropped from it: **"ตัวเลขอ่านเป็นคำ"**. Asking the model to render 1,250 as
"หนึ่งพันสองร้อยห้าสิบ" puts a transformation step between the database and the
person, and these are net cost figures. The speech engine reads "1,250 บาท"
perfectly well.

#### Speech quality

Chrome exposes both operating-system voices and Google's network ones, and
`localService === false` is what tells them apart. The Thai difference is not
subtle — the bundled Windows voice is the flat one people mean when they say the
speech sounds bad. `pickVoice()` took the first match for the language, which is
whichever the browser happened to list first; it now prefers a network voice at
the exact tag, then a network voice for the language, then anything.

The overlay prints which voice it ended up with, and offers 0.8× to 1.15×
(default 0.9× — Thai voices run fast at 1.0, and a price is the thing worth
hearing slowly). Both exist so the next report is "it is using Microsoft
Premwadee" rather than "the speech is bad", which is not a thing anyone can act
on. If no network voice is installed, the ceiling is the machine's.

**Transcription mangles proper nouns and there is no fixing it here.**
"รวมมิตร มีทัวร์เจ็ดเกาะ…" arrived as `รมิตร มีทัวร์เก็ตเกาะ…`. Nova handled that
one correctly — said it could not find a supplier by that name and asked for it
again — and the hint now tells it to do that deliberately: names and numbers
mis-transcribe often, and guessing is worse than one clarifying sentence.

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
| 2.7 | Projects — folders over chat history | 0.5 day | **done** 2026-07-23 |
| 2.8 | Voice mode — hands-free, browser speech both ways | 0.5 day | **done** 2026-07-23 — first round of use found a tour-search bug that predated it; see below |
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

### What a deploy sends, and one thing it must not (2026-07-23)

`api/uploads/` is deployment state, not source: it holds images staff attached to
questions, and it is gitignored except for the `.htaccess` that closes it. The
deploy script walked `api/` recursively, so a developer's local test images —
3.1 MB of them — were about to be uploaded into the production folder alongside
real ones. It now skips the directory entirely and sends the `.htaccess` on its
own, creating the folder if it is missing.

**The protection was verified with a real file, and the first result was wrong.**
Fetching a throwaway file from `api/uploads/` over HTTPS returned **500, not
403** — Apache was answering the whole directory with an error rather than a
denial. The cause was `php_flag engine off` in the `.htaccess`: `php_flag` exists
only when PHP runs as an Apache module, and production runs PHP-FPM, where it is
an unknown directive. It happened to deny access, but by breaking, which also
meant nothing had ever proved the `Require all denied` above it worked. Guarded
behind `<IfModule mod_php*.c>`, the same probe returns 403.

Worth doing again after any change to that file, because both outcomes look like
"blocked" from the outside:

```
./scripts/ftp-ls.ps1 /ai.sevensmiletourandticket.com/api   # timestamps
curl -i https://ai.sevensmiletourandticket.com/api/uploads/<any-file>   # want 403
```

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
`web_search`. Four more were added on 2026-07-23 — `propose_tour_create` /
`_update` and `propose_supplier_create` / `_update` — the only tools that change
anything, and even they write nothing on their own. See *Writing back*, below.

`search_tours` also gained a `supplier_id` filter, found by using it: Nova could
say "Cattery มีทัวร์ 7 รายการ" from the count on `search_suppliers` and then had no
way to list them. The count without the rows is worse than no count at all — it
tells staff the data is there and that Nova cannot reach it.

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

### Writing back — adding and editing records (added 2026-07-23)

Nova could only read. It can now add and edit **tours** and **suppliers**, through
four `propose_*` tools — and the name is the design. **None of them writes
anything.** They validate, work out the exact diff, and file it in
`ai_record_writes` as pending; a card appears under the reply showing every field
that would change, old value beside new, and `POST /api/writes.php` applies it
when the person who asked presses the button.

The asymmetry is the whole argument. A wrong search result is a sentence on
screen that the reader can see is wrong. A wrong `UPDATE` is a net cost sitting
in the system that somebody quotes off next week, with nothing to compare it
against — and this database has no undo, no soft delete, and no history beyond a
single `updated_by` column. So the model gets to propose and a person gets to
decide, every time.

What that buys, and what it cost to get right:

- **Validation is server-side, not schema-side.** A tool schema describes what
  the model *should* send and does nothing about what it does send. Every value
  is re-checked in `lib/writes.php`: suppliers must exist, prices must be numbers
  under 500,000 THB, dates must parse and not run backwards.
- **`destination` is matched against what is already in the table**, case
  insensitively, and stored in the existing spelling. `search_tours` filters on
  exact equality, so a tour filed under "krabi" is invisible to every province
  search — and invisible in a way nobody would think to check, because the record
  looks fine on its own page.
- **Echoing the record back is not an edit.** The model tends to resend every
  field it just read. Values equal to what is stored are dropped, so the card
  lists only what actually differs — `500.00` in the column against `500` from
  the model is the same price, and a card with eight rows hides the one that
  matters.
- **A row that moved between proposal and confirmation is refused.** Someone
  editing the same tour in ContactRate meanwhile is exactly the case where a
  stale "before" quietly undoes their work. The apply step re-reads the row,
  compares it against the diff's `from` values, and returns 409 rather than
  guessing.
- **The proposal is what gets applied.** The stored diff is executed verbatim;
  nothing is re-derived from the model's arguments after the person has read the
  card. `nova_write_statement()` builds that SQL and is called by the test as
  well, which prepares it against MySQL without executing — the only way to prove
  the statement compiles without putting a junk row in the live table.
- **The prompt forbids claiming success.** Nova is told to say what *will* change
  and never that it has changed: it cannot press the button and is never told
  whether anyone did. Staff who read "แก้ให้แล้ว" stop reading, and the button is
  then never pressed.
- **Delete is not offered at all**, and neither are hotels or rates. Tours and
  suppliers are flat tables where a field-by-field diff is legible; `hotel_rates`
  is nested periods (room type × period × meal plan) where it is not, and would
  need a card designed for it rather than this one reused.

**What is editable is data, not code.** `NOVA_WRITE_ENTITIES` in `lib/writes.php`
maps each record type to its table, its name column, whether it has a
`updated_by` to stamp, and its per-column rules. Suppliers were added the day
after tours as one more entry — no second code path, and no second confirm card
to keep in step with the first.

`ai_record_writes` is the audit log as well as the queue: who proposed it, in
which chat, the exact before and after, and who pressed which button. Applying is
a status change on the row rather than a copy into a second table that could
disagree with the first, and cancelled proposals are kept — what Nova offered to
do and was told not to is part of the record too. **For suppliers it is the only
record**: that table never got an `updated_by` column, which is why these rows
are kept forever.

Two things the real data forced:

- **`suppliers` has five phone columns** and 19 of the 37 rows use the second. A
  new number usually belongs in an empty one rather than over the existing one,
  so the tool says to ask which is meant.
- **Phone validation is deliberately loose** — digits somewhere, under 50
  characters. The column already holds `+66 81 234 5678 (คุณเอ)`, and a stricter
  rule would reject numbers that are in the system and correct.

Tested by `scripts/test-writes.php` (43 checks; nothing written to `tours` or
`suppliers`, both read back at the end to prove it) and end to end against the
model. Two real sequences, both correct: "แก้ราคาผู้ใหญ่เป็น 550" produced one card
with one changed field, and adding a supplier followed immediately by "แล้วเพิ่ม
ทัวร์ของเจ้านี้เลย" was **refused** — Nova said the supplier is not confirmed yet so
there is nothing to attach the tour to. The `INSERT`/`UPDATE` themselves are
covered only as far as MySQL accepting the statement; the first real one should
be a small edit, checked in ContactRate afterwards.

Related: `scripts/db-migrate.php` was rejecting `ENUM('create','update')` as an
UPDATE statement. String literals are now masked before the forbidden-verb scan.
The checks that decide what actually runs — statement must begin with
`CREATE TABLE IF NOT EXISTS` / `CREATE INDEX` / `ALTER TABLE`, table must be
prefixed `ai_` — still read the statement as written.

## Open items

- [x] Anthropic API key — in `api/config.local.php`, working
- [x] **`git init`** — done; two commits on `main`.
- [x] **Deploy** — live at https://ai.sevensmiletourandticket.com; last pushed
      2026-07-23 18:39, which included projects, writes, images and voice. The
      server had been running the 13:51 build until then, so it is worth saying
      again: verify what is actually *on* the server before believing this line.
      The deployed bundle can lag the local build by days and the symptom is
      silent — staff simply use an older Nova.
      `./scripts/ftp-ls.ps1 /ai.sevensmiletourandticket.com/api` shows the
      server's file timestamps; the asset hash in the live `index.html` should
      match `dist/assets/`; and every endpoint should answer an unauthenticated
      request with 401, not 500.
- [ ] **Delete the test row in production.** `tours` id 309, "Test from Claude",
      `updated_by = claude-code`, inserted 2026-07-22 09:30 by an earlier session. It is
      real production pollution sitting among 280 genuine tours and Nova surfaces it in
      Krabi searches. Needs a hand-run statement — `scripts/db-migrate.php` rejects
      DELETE and anything outside `ai_` tables, deliberately:
      `DELETE FROM tours WHERE id = 309 AND tour_name = 'Test from Claude';`
- [ ] **Drop `ai_tour_writes`.** Superseded by `ai_record_writes` (008) the day after
      it was created; it held one cancelled test row and nothing reads it now.
      `scripts/db-migrate.php` rejects DROP, deliberately:
      `DROP TABLE IF EXISTS ai_tour_writes;`
- [ ] **Thai search over an English catalogue is mitigated, not solved.**
      `search_tours` still does one `LIKE` against `tour_name`, and 290 of 292
      names are English; a Thai question only works because the prompt and a
      runtime warning tell Nova to translate and search again. That costs a
      second tool call on most Thai questions and depends on the model doing as
      it is told. The structural fixes are a searchable Thai name (a column on
      `tours`, which is ContactRate's table and not ours to add) or a synonym
      table in an `ai_` table mapping Thai terms to the English wording — the
      second is doable here and worth scoping. Check with `SELECT COUNT(*) FROM
      tours WHERE tour_name REGEXP '[ก-๙]'` before assuming the numbers still
      hold.
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
  projects.php                 folder CRUD; deleting one releases its chats
  assistant.php                SSE tool loop, rate limit, usage persistence
  usage.php                    month-to-date spend; ?scope=all is admin-only
  lib/jwt.php                  hand-rolled HS256 (no Composer on shared hosting)
  lib/auth.php                 require_user(), plaintext -> bcrypt upgrade
  lib/projects.php             project ownership check, shared by three endpoints
  lib/anthropic.php            streaming Messages API client over cURL
  writes.php                   confirm / cancel a proposed change; lists them
  lib/tools.php                the seven read tools + web search definition
  lib/writes.php               propose + apply; the only writer to a ContactRate table
  lib/stats.php                live data counts for the prompt, file-cached 1h
  lib/usage.php                the one pricing table + both spending limits
src/
  App.jsx                      session gate, chat state, streaming send
  lib/api.js                   fetch wrapper, token in localStorage
  lib/table-export.js          reads a rendered table to CSV / TSV
  lib/voice.js                 browser speech in and out, markdown -> spoken text
  components/
    login-page.jsx             username + password form
    nova-mark.jsx              the four-point star used as logo and avatar
    chat/
      chat-sidebar.jsx         projects, history grouped by age, inline rename,
                               per-row menu, user menu
      chat-message.jsx         markdown, per-table copy/export, tool indicator,
                               inline editor for an earlier question
      write-confirm.jsx        the before/after card and its confirm button
      voice-mode.jsx           hands-free overlay: listen -> ask -> speak -> listen
      chat-composer.jsx        auto-growing textarea, Enter sends
      empty-state.jsx          greeting + 4 suggestion cards
      usage-panel.jsx          month-to-date spend against the budget
    ui/                        shadcn components (eslint-ignored — generated)
database/
  001_ai_tables.sql            applied to production 2026-07-22
  002_usage_tracking.sql       applied to production 2026-07-23
  003_user_profiles.sql        applied to production 2026-07-23
  004_message_edits.sql        applied to production 2026-07-23
  005_projects.sql             applied to production 2026-07-23
  007_tour_writes.sql          applied 2026-07-23 — superseded by 008, drop by hand
  008_record_writes.sql        applied to production 2026-07-23
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
  test-writes.php              43 checks on the write path; writes nothing to ContactRate
  test-assistant.php           end-to-end SSE; asserts reported cost matches
```

### How staff are named (2026-07-23)

`users` gained `full_name`, `nickname`, `office` (`sevensmile` | `indosmile` |
`both`) and `position`. Nova addresses people the way the office does:

| | shown as |
|---|---|
| one office | nickname · office — *พี่หนุ่ย · Seven Smile* |
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
answers *that* person and is not everyone's to read.

**The roster is people, and `users` is logins** (2026-07-23). `dev_lay` is เลย์'s
test account; listing it invents a colleague. `NOVA_NON_STAFF_ACCOUNTS` in
`api/lib/auth.php` names it and the roster drops it — the exclusion is from the
roster only, the account still signs in and works normally. The earlier version
deduplicated by nickname instead, which worked only while the test account was
also called *เลย์*; it has since been renamed **เทส · Tester**, so a rule about
names would have silently stopped catching it. An account with no name at all is
still skipped as well, which covers the next shared login before anyone thinks to
add it to the list.

The shared `sevensmile` login was deleted through ContactRate's User Management
on 2026-07-23 — it was never used, and one account several people type into is
the one case where Nova cannot know who it is talking to. Nothing referenced it:
no conversations, no projects, no writes, and no `created_by`/`uploaded_by` rows
in ContactRate. Deleting a person, if it ever happens again, goes through that
screen: `users` belongs to the old app, and both `db-query.php` (SELECT only) and
`db-migrate.php` (`ai_` tables only) refuse to write it on purpose. The five
`ai_` foreign keys are `ON DELETE CASCADE`, so their rows go with it.

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

### Projects — folders over history (added 2026-07-23)

`ai_projects` (005) plus a nullable `ai_conversations.project_id`. A chat is
either inside one folder or in the date-grouped list, never both. Created and
renamed from the sidebar; a chat is filed through its row menu, or by starting
it from inside a folder.

**Folders only — no per-project instructions.** Instructions would have to be
folded into the system prompt, and anything appended per project sits inside
the cached prefix, so switching projects would break the prompt cache on every
turn. Folders cost nothing per question: no part of a project reaches the model.

Four things this had to get right:

- **`ON DELETE SET NULL`, not CASCADE.** `ai_conversations` cascades into
  `ai_messages`, which is the spend ledger — a CASCADE from a folder would let
  one click erase a month of recorded spend, in the direction that hides it.
  Deleting a folder releases its chats to the date list; the dialog says so,
  because staff have every reason to assume the opposite.
- **`project_id` is nullable with no backfill**, so every conversation that
  already exists stays where it is.
- **Moving a chat pins `updated_at`** (`SET project_id = ?, updated_at =
  updated_at`). The column orders the sidebar and means "last asked in"; without
  the pin, filing a three-week-old chat sends it back to the top of today.
- **Which folder is open is derived, not stored.** The folder holding the chat
  on screen opens by default and the two sets hold only what someone has since
  clicked. Storing it needed an effect that wrote state on every selection, and
  it was wrong on the mobile drawer, which mounts fresh each time it is opened.

Two things found while applying it:

- **`ADD CONSTRAINT IF NOT EXISTS … FOREIGN KEY` is a 1064.** MariaDB's grammar
  is `ADD [CONSTRAINT [symbol]] FOREIGN KEY [IF NOT EXISTS] [index_name] (…)` —
  the guard goes after `FOREIGN KEY`, not after `CONSTRAINT`, which is where it
  goes for a CHECK. `db-migrate.php` applies statements one at a time and stops
  on the first failure, so the column landed and the key did not; the file is
  idempotent, so the fix was to correct it and re-run.
- **The symptom of running the code before the migration is silent.** The
  sidebar said "ยังไม่มีประวัติแชท" — not an error. `conversations.php` selects
  `project_id`, the 500 came back, and `loadConversations()` catches and sets an
  empty list, which is indistinguishable from a new account. Verified after
  applying: 31 conversations, all intact.

The row controls became one `⋯` menu. Filing a chat needs a list of folders,
which no icon can carry, and three targets do not fit beside a title in a 256px
panel without eating the width `truncate` needs.

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
