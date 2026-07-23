<?php
/**
 * POST /api/assistant.php  { conversation_id?, message, replace_from? }
 *
 * Runs the tool-calling loop and streams the reply back as Server-Sent Events:
 *
 *   event: meta   { conversation_id, user_message_id }   once, before any text
 *   event: tool   { name }                      each time a tool is called
 *   event: text   { delta }                     assistant text, as it arrives
 *   event: done   { usage, assistant_message_id }        end of turn
 *   event: error  { message }                   fatal; the turn is over
 *
 * Both turns are persisted to ai_messages before `done` is sent, so a client
 * that drops mid-stream can reload the conversation and see the full reply.
 *
 * `replace_from` is an ai_messages id: everything from it onward is deleted
 * before the new question is stored, which is how editing an earlier question
 * works. Deleting and re-asking in one request means there is no window where
 * a conversation has lost its tail but gained no replacement.
 */

declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/tools.php';
require_once __DIR__ . '/lib/anthropic.php';
require_once __DIR__ . '/lib/usage.php';

$user = require_user();

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    json_error(405, 'method_not_allowed');
}
if (empty($CONFIG['anthropic_key'])) {
    json_error(500, 'missing_api_key', 'ยังไม่ได้ตั้งค่า Anthropic API key ใน config.local.php');
}

$body = json_body();
$message = trim((string)($body['message'] ?? ''));
$conversationId = isset($body['conversation_id']) ? (int)$body['conversation_id'] : 0;
$replaceFrom = isset($body['replace_from']) ? (int)$body['replace_from'] : 0;

if ($message === '') {
    json_error(400, 'empty_message');
}

$pdo = nova_db();

// Checked before the SSE headers go out, so a refusal is an ordinary 429 the
// client can show as an error rather than a stream that opens and immediately
// dies. Every turn past this point costs real money.
$limit = nova_rate_limit_check($pdo, $user['id']);
if ($limit !== null) {
    json_error(429, $limit['code'], $limit['message']);
}

// ---------------------------------------------------------------- SSE setup

// Buffering anywhere in the chain defeats streaming: the reply would arrive in
// one lump at the end. Disable PHP's own, and ask nginx not to add its.
@ini_set('zlib.output_compression', '0');
@ini_set('output_buffering', '0');
@ini_set('implicit_flush', '1');
while (ob_get_level() > 0) {
    ob_end_flush();
}
ob_implicit_flush(true);
set_time_limit(300);
ignore_user_abort(true);

header('Content-Type: text/event-stream; charset=utf-8');
header('Cache-Control: no-cache, no-transform');
header('X-Accel-Buffering: no');

function sse(string $event, array $data): void
{
    echo "event: $event\n";
    echo 'data: ' . json_encode($data, JSON_UNESCAPED_UNICODE) . "\n\n";
    flush();
}

function sse_fatal(string $message): never
{
    sse('error', ['message' => $message]);
    exit;
}

// ------------------------------------------------- conversation persistence

try {
    if ($conversationId > 0) {
        $stmt = $pdo->prepare(
            'SELECT id FROM ai_conversations WHERE id = ? AND user_id = ? LIMIT 1'
        );
        $stmt->execute([$conversationId, $user['id']]);
        if (!$stmt->fetch()) {
            sse_fatal('ไม่พบแชทนี้');
        }
    } else {
        // Provisional title from the first question; good enough, and costs nothing.
        $title = mb_substr($message, 0, 60) . (mb_strlen($message) > 60 ? '…' : '');
        $stmt = $pdo->prepare('INSERT INTO ai_conversations (user_id, title) VALUES (?, ?)');
        $stmt->execute([$user['id'], $title]);
        $conversationId = (int)$pdo->lastInsertId();
    }

    // Editing an earlier question: drop it and every turn after it, then store
    // the rewritten one in its place. Scoped to a conversation whose ownership
    // was just checked above, so an id from another user's thread touches
    // nothing. Ids are globally increasing, so `>=` inside one conversation is
    // exactly "this message and everything after it".
    //
    // Stamped, not deleted: the reply being dropped was paid for, and
    // ai_messages is the spend ledger as well as the transcript (see 004).
    // Nothing else reads a superseded row — it is gone from the conversation
    // for good, with no branch to go back to, and the UI says so beforehand.
    if ($replaceFrom > 0) {
        $stmt = $pdo->prepare(
            'UPDATE ai_messages
                SET superseded_at = NOW()
              WHERE conversation_id = ? AND id >= ? AND superseded_at IS NULL'
        );
        $stmt->execute([$conversationId, $replaceFrom]);

        // Rewriting the very first question leaves the chat titled after a
        // sentence nobody asked. Retitle only in that case: a title edited by
        // hand further down the thread is the person's, not ours to overwrite.
        $stmt = $pdo->prepare(
            'SELECT COUNT(*) FROM ai_messages
              WHERE conversation_id = ? AND superseded_at IS NULL'
        );
        $stmt->execute([$conversationId]);

        if ((int)$stmt->fetchColumn() === 0) {
            $title = mb_substr($message, 0, 60) . (mb_strlen($message) > 60 ? '…' : '');
            $pdo->prepare('UPDATE ai_conversations SET title = ? WHERE id = ?')
                ->execute([$title, $conversationId]);
        }
    }

    $stmt = $pdo->prepare(
        'INSERT INTO ai_messages (conversation_id, role, content) VALUES (?, ?, ?)'
    );
    $stmt->execute([$conversationId, 'user', $message]);
    $userMessageId = (int)$pdo->lastInsertId();
} catch (Throwable $e) {
    error_log('nova: persistence failed: ' . $e->getMessage());
    sse_fatal('บันทึกข้อความไม่สำเร็จ');
}

// The client needs the real id to offer an edit on this question later; until
// it arrives the message is held under a local one.
sse('meta', [
    'conversation_id' => $conversationId,
    'user_message_id' => $userMessageId,
]);

// ------------------------------------------------------------ prompt + loop

/**
 * Prior turns, oldest first. Tool calls are not replayed — only the resulting
 * text — which keeps the history small and avoids resending tool_use blocks
 * whose results are no longer in context.
 */
$stmt = $pdo->prepare(
    'SELECT role, content FROM ai_messages
      WHERE conversation_id = ? AND superseded_at IS NULL
      ORDER BY id DESC
      LIMIT 21'
);
$stmt->execute([$conversationId]);
$history = array_reverse($stmt->fetchAll());

$messages = [];
foreach ($history as $row) {
    $messages[] = ['role' => $row['role'], 'content' => $row['content']];
}

$today = date('j F Y');

// Who is asking. The office and role are not decoration: Seven Smile and INDO
// Smile share one database, and an accountant and a tour operator want
// different things from the same rate. Nova is told, and left to use it.
$who = $user['display_name'];
if ($user['office'] === 'both') {
    $who .= ', who works across both the Seven Smile and INDO Smile offices';
} elseif ($user['position'] !== '') {
    $who .= ' (' . $user['position'] . ')';
}

// Read from the database rather than asserted in the prompt. The previous
// version stated its own counts as fact — "30 hotels", "there are no Krabi
// hotels" — which was accurate when written and checked by nothing since. Add a
// Krabi hotel to ContactRate and Nova would have gone on denying it existed.
$dataSummary = nova_stats_prompt(nova_data_stats($pdo));

// Who this particular person is, written by hand in database/profiles.json and
// synced into ai_user_profiles. Optional by design: a new account with no entry
// still works, Nova just knows nothing beyond their nickname and job.
$about = $user['about'] !== ''
    ? "\n{$user['about']}\n"
    : '';

// The rest of the office, by name and job only — enough to send a question to
// the right person. Deliberately not their profiles: those are written to shape
// how Nova answers each of them, and are not everyone's to read.
$roster = implode("\n", nova_office_roster($pdo, $user['id']));

$system = <<<PROMPT
คุณคือ Nova ผู้ช่วยข้อมูลภายในของออฟฟิศ Seven Smile และ INDO Smile (บริษัททัวร์ในภูเก็ต)
คุณตอบคำถามพนักงานจากข้อมูลจริงในระบบ ContactRate ผ่าน tools ที่มีให้

You are Nova, an internal assistant for the Seven Smile and INDO Smile tour offices.
Today is {$today}. You are talking to {$who}, a member of staff.

## Language
Reply in the language the user wrote in. Thai question, Thai answer. English
question, English answer. Keep tour names, hotel names, and room types in their
original form — never translate them.

## ตัวตน — โนวา
ภาษาไทยเรียกคุณว่า "โนวา" คุณเป็นผู้ชาย ลงท้ายว่า "ครับ" และเรียกตัวเองว่า "ผม"
คุณเป็นรุ่นน้องในออฟฟิศ อายุน้อยกว่าเกือบทุกคนที่คุยด้วย เป็นกันเอง แต่เรื่องงานจริงจัง

- **เป็นกันเองที่น้ำเสียง ไม่ใช่ที่ข้อมูล** ความสนิทไม่เคยแปลว่าเดาแทนให้
  ไม่มีข้อมูลก็คือไม่มี บอกตรงๆ สั้นๆ ไม่ต้องขอโทษยืดยาว
- ทักทายสั้น แล้วเข้าเรื่องเลย ไม่ต้องปิดท้ายด้วย "มีอะไรให้ช่วยอีกไหมครับ" ทุกข้อความ
- คุยเล่นได้ ตอบสั้นแล้วกลับมาที่งาน แต่ในคำตอบที่มีราคาหรือวันที่ ไม่เล่นมุก
- ไม่ประจบ ไม่ "คำถามดีมากครับ" ไม่ "เยี่ยมเลย" เขาถามงาน ไม่ได้ขอกำลังใจ
- ชื่อที่ให้มาเรียกตามนั้นทั้งชื่อ ถ้ามี "พี่" อยู่ในชื่อก็เรียกพร้อมคำนำหน้า

## คนที่คุณกำลังคุยด้วย
{$who}
{$about}
เรียกชื่อเขาให้ติดปาก ทั้งตอนเปิดคำตอบ ตอนยืนยันสิ่งที่เขาถาม และตอนบอกว่าไม่มีข้อมูล
ให้รู้สึกว่าคุยกับคนที่รู้จักกัน ไม่ใช่กับระบบ — แต่อย่ายัดชื่อทุกประโยคจนดูแปลก
ห้ามเรียกด้วย username เด็ดขาด

เขาทำงานคนละอย่างกัน คำตอบจึงควรต่างกันด้วย ทั้งความละเอียด ระดับศัพท์
และสิ่งที่หยิบขึ้นมาก่อน ใช้สิ่งที่รู้เกี่ยวกับเขาข้างบนเป็นตัวกำหนด
ไม่ใช่ตอบแบบเดียวกับทุกคน

## คนอื่นในออฟฟิศ
{$roster}

- รู้ไว้เพื่อชี้ทางว่าเรื่องไหนควรถามใคร เช่นถ้าเจอเรื่องที่นอกเหนือข้อมูลในระบบ
  แต่มีคนในออฟฟิศที่ดูแลเรื่องนั้นอยู่ ก็บอกได้ว่าลองถามคนนั้นดู
- พูดถึงเมื่อเกี่ยวกับคำถามจริงๆ หรือเมื่อเขาเอ่ยถึงเองเท่านั้น ไม่หยิบมาเล่าลอยๆ
- คุณรู้แค่ว่าใครทำอะไร ไม่รู้เรื่องส่วนตัว งานที่ค้างอยู่ หรือบทสนทนาของคนอื่น
  ถ้าถูกถามเกินนั้น บอกว่าไม่รู้

## ออฟฟิศของเรา
Seven Smile Tour And Ticket และ INDO Smile South Services เป็นคนละออฟฟิศ
แต่เจ้าของและ GM คือคนเดียวกัน และใช้ฐานข้อมูล ContactRate ร่วมกัน

Agent หลักที่ทำงานด้วยทั้งสองออฟฟิศคือ INDO Bangkok งานจาก agent นี้จะแบ่งกันโดย
Seven Smile ดูกระบี่ INDO Smile ดูภูเก็ต — เฉพาะเคสของ INDO Bangkok เท่านั้น
งานขายอื่นๆ ทั้งสองออฟฟิศขายจังหวัดไหนก็ได้ ไม่มีการแบ่ง

นี่คือบริบทให้เข้าใจว่าใครทำอะไร **ไม่ใช่กฎสำหรับกรองข้อมูล** เวลาค้นให้ค้นทั้งหมด
ตามที่เขาถามเสมอ ห้ามตัดจังหวัดไหนออกเพราะคิดว่าไม่ใช่งานของออฟฟิศเขา
และคุณไม่มีข้อมูล booking อยู่ในมือ จึงไม่มีทางรู้ว่างานไหนมาจาก agent ไหน

## Grounding — this matters more than anything else
Every fact you state about tours, hotels, rates, or suppliers must come from a
tool result in this conversation. You have no reliable knowledge of this
office's data outside the tools.

- Never invent a tour, hotel, price, or date. Not even a plausible one.
- Never fill a gap with a typical or market figure. A missing price is missing.
- Distinguish "no results for this filter" from "this data is not in the system".
  The tools tell you which; repeat that distinction to the user.
- When a tool reports hotels with no rates loaded, say so explicitly rather than
  quietly leaving them out — staff need to know the data is incomplete.
- If a question cannot be answered from the tools, say what is missing and what
  you would need. Do not guess.

## Web search — outside information only
You also have `web_search`, which reaches the public internet. It is a separate
world from the office data, and the two must never be mixed up.

- **Our tours, hotels, rates, and suppliers only ever come from the ContactRate
  tools.** Never answer a question about our own data with a web result, and
  never fill a missing price from a website. A price on the internet is somebody
  else's price, not ours.
- Search when the answer depends on the outside world and would change over
  time: weather and sea conditions, ferry and flight schedules, holidays and
  festival dates, park closures, entry fees, news affecting a destination, or
  general information about a place we do not sell.
- Do not search for anything the ContactRate tools cover. Do not search to
  double-check a figure a tool already gave you — the tool is the record.
- Answer directly, with no search, for stable knowledge and for anything already
  in this conversation.
- When you do use a web result, say so in the sentence itself — "ตามเว็บ…",
  "according to…" — so staff can tell at a glance which figures are ours and
  which are not.
- If a web result and our data disagree, report both and say which is which.
  Ours is what we sell.

## What the data holds — counted just now, not remembered
{$dataSummary}

Other things about the shape of the data:
- Hotel `destination` is free text ("Patong Beach, Phuket", "Phuket"), so search
  hotels by loose location match, not exact equality.
- "Beachfront" is not a field anywhere. If asked, say the system does not record
  it, and offer what the destination text does suggest — clearly labelled as a
  hint, not a fact.
- Many tours have no validity dates recorded. That means year-round, not
  expired. Never tell staff a tour has lapsed because its dates are blank.

## Answering
- Prices are **net cost** — internal figures. Never present them as customer
  prices, and never add a markup yourself.
- Use markdown tables when comparing several tours, hotels, or rates. Columns
  should be the things staff care about — name, destination, price, dates.
- Call several tools in one turn when a question needs it.

## Links back to ContactRate
Tool results carry a `link` for each tour, hotel, and supplier. Staff read a
price here and then go there to act on it, so make the record's name a markdown
link to it — `[Patong Beach Hotel](…)` — every time you name one. In a table,
link the name in the name column. Never print a bare URL, and never build a link
yourself: use the one the tool gave you, or none at all.

## Who you are talking to
Staff, not engineers. They know tours and hotels; they do not know how the data
is stored, and they should not have to.

- Do not show record ids, table names, column or field names, tool names, or
  SQL. No "id 142", no "hotels table", no "the destination field".
- Refer to things the way staff do: by name. "Patong Beach Hotel", not its id.
- Say "we do not have that in the system" rather than explaining which table or
  field is empty.
- If the user asks for an id or anything technical, give it. Only then.
- Be brief. Lead with the answer, then the table, then any caveat about missing
  data. No preamble.

## คุณไม่ใช่ dev — อยู่ในหน้าที่ตัวเอง
คุณเป็นพนักงานที่ตอบเรื่องข้อมูล ไม่ใช่คนดูแลระบบ

- ไม่อธิบายว่าระบบทำงานยังไง ไม่พูดถึงฐานข้อมูล เครื่องมือ โค้ด หรือ AI
- ไม่เสนอให้แก้ระบบ เพิ่มฟิลด์ หรือปรับข้อมูล และไม่พูดว่า "เดี๋ยวผมจัดการให้" —
  คุณแก้อะไรไม่ได้ คุณอ่านได้อย่างเดียว
- ข้อมูลขาดหรือผิด บอกว่าขาดอะไร แล้วให้เขาไปแก้ใน ContactRate เอง
- ถูกถามเรื่องนอกหน้าที่ (ตั้งราคาขาย ตัดสินใจแทน เรื่องคอมพัง) บอกสั้นๆ ว่าไม่ใช่
  งานคุณ แล้วเสนอสิ่งที่คุณช่วยได้แทน
PROMPT;

$tools = array_merge(nova_tool_definitions(), nova_server_tool_definitions());
$reply = '';
$totalIn = 0;
$totalOut = 0;
// Cached tokens are billed at their own rates — reads at ~10% of input, writes
// at 125%. `input_tokens` counts only the uncached remainder, so leaving these
// out makes a cached turn look an order of magnitude cheaper than it is.
$cacheRead = 0;
$cacheWrite = 0;
// Billed per search on top of tokens, so worth counting on its own.
$searches = 0;
$sources = [];

try {
    for ($round = 0; $round < NOVA_MAX_ROUNDS; $round++) {
        $result = anthropic_stream(
            $CONFIG['anthropic_key'],
            $messages,
            $system,
            $tools,
            function (string $delta) use (&$reply): void {
                $reply .= $delta;
                sse('text', ['delta' => $delta]);
            },
            // Reported under its real name. Enabling web search brings a code
            // execution environment with it (dynamic filtering runs there), and
            // the model will happily use that environment for plain arithmetic
            // with no search involved — so `code_execution` must not be
            // relabelled as searching. Doing that told staff Nova had gone to
            // the web when it had only added up a column.
            function (string $name): void {
                sse('tool', ['name' => $name]);
            }
        );

        $totalIn += (int)($result['usage']['input_tokens'] ?? 0);
        $totalOut += (int)($result['usage']['output_tokens'] ?? 0);
        $cacheRead += (int)($result['usage']['cache_read_input_tokens'] ?? 0);
        $cacheWrite += (int)($result['usage']['cache_creation_input_tokens'] ?? 0);
        $searches += (int)($result['usage']['server_tool_use']['web_search_requests'] ?? 0);
        anthropic_collect_sources($result['content'], $sources);

        // A long-running server tool can stop the turn early. Anthropic resumes
        // where it left off when the same assistant turn is sent back unchanged
        // — no user message, and nothing for us to run.
        if ($result['stop_reason'] === 'pause_turn') {
            $messages[] = ['role' => 'assistant', 'content' => $result['content']];
            continue;
        }

        if ($result['stop_reason'] !== 'tool_use') {
            break;
        }

        $messages[] = ['role' => 'assistant', 'content' => $result['content']];

        // All tool results for one assistant turn go back in a single user
        // message — splitting them teaches the model to stop calling in parallel.
        $toolResults = [];
        foreach ($result['content'] as $block) {
            if (($block['type'] ?? '') !== 'tool_use') {
                continue;
            }

            sse('tool', ['name' => $block['name']]);
            // `input` is an empty stdClass when the tool took no arguments.
            $output = nova_run_tool($pdo, $block['name'], (array)($block['input'] ?? []));

            $toolResults[] = [
                'type'        => 'tool_result',
                'tool_use_id' => $block['id'],
                'content'     => json_encode($output, JSON_UNESCAPED_UNICODE),
                'is_error'    => isset($output['error']),
            ];
        }

        if (!$toolResults) {
            break;
        }
        $messages[] = ['role' => 'user', 'content' => $toolResults];
    }
} catch (Throwable $e) {
    error_log('nova: assistant loop failed: ' . $e->getMessage());
    sse_fatal('เรียก AI ไม่สำเร็จ: ' . $e->getMessage());
}

// ---------------------------------------------------------------- finish up

if (trim($reply) === '') {
    $reply = 'ขออภัย ตอบไม่ได้ในรอบนี้ ลองถามใหม่อีกครั้ง';
    sse('text', ['delta' => $reply]);
}

// Sources go into the reply text rather than a side channel, so they survive a
// page reload — history is stored as plain markdown, with no room for metadata.
if ($sources) {
    $block = "\n\n**แหล่งอ้างอิงจากเว็บ**\n";
    foreach ($sources as $url => $title) {
        $block .= '- [' . str_replace([']', "\n"], '', $title) . '](' . $url . ")\n";
    }
    $reply .= $block;
    sse('text', ['delta' => $block]);
}

// Stays 0 if the write below fails, and the client then leaves the reply under
// its local id — a reply that is not in the table is not one to offer an edit
// against.
$assistantMessageId = 0;

try {
    // All five figures, not just the two smallest. `input_tokens` counts only
    // the uncached remainder, so a row storing it alone reports a turn as
    // roughly free — the cache reads and writes are most of the bill, and each
    // web search is charged on top. Anything less and no spend report built on
    // this table can be believed.
    $stmt = $pdo->prepare(
        'INSERT INTO ai_messages
             (conversation_id, role, content,
              input_tokens, output_tokens, cache_read_tokens, cache_write_tokens,
              web_searches, model)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $stmt->execute([
        $conversationId, 'assistant', $reply,
        $totalIn, $totalOut, $cacheRead, $cacheWrite, $searches, NOVA_MODEL,
    ]);
    $assistantMessageId = (int)$pdo->lastInsertId();

    // Touch the conversation so it sorts to the top of the sidebar.
    $pdo->prepare('UPDATE ai_conversations SET updated_at = NOW() WHERE id = ?')
        ->execute([$conversationId]);
} catch (Throwable $e) {
    error_log('nova: saving reply failed: ' . $e->getMessage());
}

$usage = [
    'input_tokens'       => $totalIn,
    'output_tokens'      => $totalOut,
    'cache_read_tokens'  => $cacheRead,
    'cache_write_tokens' => $cacheWrite,
    // Charged per search on top of tokens, so it is reported separately.
    'web_searches'       => $searches,
];

sse('done', [
    'conversation_id'      => $conversationId,
    'assistant_message_id' => $assistantMessageId,
    'usage'                => $usage,
    // Priced here rather than in the client so there is one pricing table and
    // the browser never has to know what a cache-write multiplier is.
    'cost_thb'             => round(nova_turn_cost_thb($usage), 3),
]);
