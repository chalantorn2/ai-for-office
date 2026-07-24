<?php
/**
 * End-to-end check: asks Nova a real question and prints the SSE stream.
 *
 * Issues its own JWT from the configured signing key rather than logging in,
 * so no staff password is needed. Requires the local PHP server:
 *
 *   php -S 127.0.0.1:8000 -t .
 *
 * Pass several questions to exercise a multi-turn conversation — history replay
 * is where thinking-block and message-ordering bugs actually surface.
 *
 * Run:  php scripts/test-assistant.php "ทัวร์กระบี่ ราคาไม่เกิน 1500"
 *       php scripts/test-assistant.php "สวัสดี" "โรงแรมกระบี่มีมั้ย" "ภูเก็ตมีอะไรบ้าง"
 */

declare(strict_types=1);

$CONFIG = require __DIR__ . '/../api/config.local.php';
require_once __DIR__ . '/../api/lib/jwt.php';
require_once __DIR__ . '/../api/lib/usage.php';

if (empty($CONFIG['anthropic_key'])) {
    fwrite(STDERR, "anthropic_key is empty in api/config.local.php\n");
    exit(2);
}

$questions = array_slice($argv, 1);
if (!$questions) {
    $questions = ['ทัวร์กระบี่ ราคาผู้ใหญ่ไม่เกิน 1500 มีอะไรบ้าง'];
}

// Borrow a real user id so the conversation lands under a valid foreign key.
$db = $CONFIG['db'];
$pdo = new PDO(
    "mysql:host={$db['host']};port={$db['port']};dbname={$db['name']};charset=utf8mb4",
    $db['user'], $db['pass'],
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
);
$user = $pdo->query('SELECT id, username, role FROM users ORDER BY id LIMIT 1')->fetch();

$token = jwt_issue(
    ['sub' => (int)$user['id'], 'username' => $user['username'], 'role' => $user['role']],
    $CONFIG['jwt_secret'],
    600
);

/**
 * Priced through api/lib/usage.php, the same code the API and the usage report
 * use. This script used to carry its own copy of the rate table, which is how a
 * test ends up cheerfully confirming a number the product does not produce.
 *
 * Introductory pricing ($2/$10 per MTok) runs to 2026-08-31. Turns are reported
 * at both rates so the figure staff plan against is the one that survives.
 */
function turn_cost_thb(array $u, bool $standard = false): float
{
    return nova_turn_cost_thb($u, $standard ? '2099-01-01' : null);
}

$conversationId = null;
$failures = 0;
$grand = [
    'input_tokens'       => 0,
    'output_tokens'      => 0,
    'cache_read_tokens'  => 0,
    'cache_write_tokens' => 0,
    'web_searches'       => 0,
];

foreach ($questions as $turn => $question) {
echo "\n" . str_repeat('=', 72) . "\n";
echo 'Q' . ($turn + 1) . ": $question\n";
echo str_repeat('-', 72) . "\n";

$started = microtime(true);
$firstByte = null;
$buffer = '';
$events = ['tool' => [], 'write' => [], 'meta' => null, 'done' => null, 'error' => null];

$ch = curl_init('http://127.0.0.1:8000/api/assistant.php');
curl_setopt_array($ch, [
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => json_encode(
        ['message' => $question, 'conversation_id' => $conversationId],
        JSON_UNESCAPED_UNICODE
    ),
    CURLOPT_HTTPHEADER     => [
        'Content-Type: application/json',
        'Authorization: Bearer ' . $token,
    ],
    CURLOPT_RETURNTRANSFER => false,
    CURLOPT_TIMEOUT        => 300,
    CURLOPT_WRITEFUNCTION  => function ($ch, string $chunk) use (
        &$buffer, &$events, &$firstByte, $started
    ): int {
        $firstByte ??= microtime(true) - $started;

        $buffer .= $chunk;
        while (($pos = strpos($buffer, "\n\n")) !== false) {
            $frame = substr($buffer, 0, $pos);
            $buffer = substr($buffer, $pos + 2);

            $event = 'message';
            $data = '';
            foreach (explode("\n", $frame) as $line) {
                if (str_starts_with($line, 'event:')) $event = trim(substr($line, 6));
                elseif (str_starts_with($line, 'data:')) $data .= trim(substr($line, 5));
            }
            $payload = json_decode($data, true);
            if (!is_array($payload)) continue;

            switch ($event) {
                case 'text':  echo $payload['delta']; break;
                case 'tool':  $events['tool'][] = $payload['name']; echo "\n  [tool: {$payload['name']}]\n"; break;
                // A change waiting on a button press. Printed in full: the whole
                // point of the card is that a person reads it before agreeing,
                // and this is the only place to see what they would be shown.
                case 'write':
                    $card = $payload['card'];
                    $events['write'][] = $card;
                    echo "\n  [proposed #{$card['id']} · {$card['action']} {$card['entity']} · {$card['record_name']}]\n";
                    foreach ($card['changes'] as $change) {
                        echo "    {$change['label']}: " . ($change['from'] ?? '(ใหม่)') . " → {$change['to']}\n";
                    }
                    break;
                case 'meta':  $events['meta'] = $payload; break;
                case 'done':  $events['done'] = $payload; break;
                case 'error': $events['error'] = $payload; echo "\n  [ERROR: {$payload['message']}]\n"; break;
            }
        }
        return strlen($chunk);
    },
]);

curl_exec($ch);
$status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
$err = curl_error($ch);
curl_close($ch);

$total = round(microtime(true) - $started, 1);

echo "\n" . str_repeat('-', 72) . "\n";
if ($err) {
    echo "TRANSPORT ERROR: $err\n";
    exit(1);
}
echo "HTTP $status · first byte " . round($firstByte ?? 0, 1) . "s · total {$total}s\n";
echo 'tools called: ' . (implode(', ', $events['tool']) ?: '(none)') . "\n";

if ($events['error']) {
    $failures++;
}

if ($events['done']) {
    $u = $events['done']['usage'];
    foreach ($grand as $k => $_) {
        $grand[$k] += $u[$k] ?? 0;
    }
    printf(
        "tokens: %d in (+%d cache read, %d cache write), %d out · %d web search(es)\n"
        . "cost: ~%.2f THB now · ~%.2f THB at standard pricing\n",
        $u['input_tokens'], $u['cache_read_tokens'] ?? 0, $u['cache_write_tokens'] ?? 0,
        $u['output_tokens'], $u['web_searches'] ?? 0,
        turn_cost_thb($u), turn_cost_thb($u, true)
    );

    // The API prices the same turn on its way out and stores it. If the two
    // disagree, one of them is wrong and every report built on the stored
    // number inherits it.
    $reported = $events['done']['cost_thb'] ?? null;
    if ($reported === null || abs($reported - turn_cost_thb($u)) > 0.005) {
        echo "  MISMATCH: server reported " . var_export($reported, true)
           . " THB for this turn\n";
        $failures++;
    }
}

// Keep the same conversation for the next turn — this is what exercises replay.
$conversationId ??= $events['meta']['conversation_id'] ?? null;
echo "conversation_id: " . var_export($conversationId, true) . "\n";
}

echo "\n" . str_repeat('=', 72) . "\n";
printf(
    "%d turns · %d failed · %d in (+%d cached), %d out · %d search(es)\n"
    . "~%.2f THB total now · ~%.2f THB at standard pricing\n",
    count($questions), $failures,
    $grand['input_tokens'], $grand['cache_read_tokens'] + $grand['cache_write_tokens'],
    $grand['output_tokens'], $grand['web_searches'],
    turn_cost_thb($grand), turn_cost_thb($grand, true)
);

exit($failures ? 1 : 0);
