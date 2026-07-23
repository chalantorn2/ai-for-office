<?php
/**
 * GET /api/usage.php            -> this month, the caller's own figures
 * GET /api/usage.php?scope=all  -> this month, everyone (admins only)
 *
 * The point of this endpoint is that the office can see what Nova costs before
 * the invoice arrives. Everything is costed in SQL from the tokens stored on
 * each assistant turn, using the one pricing table in lib/usage.php.
 */

declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/usage.php';

$user = require_user();
$pdo = nova_db();

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    json_error(405, 'method_not_allowed');
}

$wantsAll = ($_GET['scope'] ?? '') === 'all';
if ($wantsAll && $user['role'] !== 'admin') {
    json_error(403, 'forbidden', 'ดูยอดรวมของทุกคนได้เฉพาะผู้ดูแลระบบ');
}

$cost = nova_cost_sql('m');
$monthStart = date('Y-m-01');

/** Totals for one scope over the current calendar month. */
function nova_usage_totals(PDO $pdo, string $cost, string $monthStart, ?int $userId): array
{
    $where = ["m.role = 'assistant'", 'm.created_at >= ?'];
    $args = [$monthStart];

    if ($userId !== null) {
        $where[] = 'c.user_id = ?';
        $args[] = $userId;
    }

    $stmt = $pdo->prepare(
        "SELECT COUNT(*) AS turns,
                COALESCE(SUM(m.input_tokens), 0)       AS input_tokens,
                COALESCE(SUM(m.output_tokens), 0)      AS output_tokens,
                COALESCE(SUM(m.cache_read_tokens), 0)  AS cache_read_tokens,
                COALESCE(SUM(m.cache_write_tokens), 0) AS cache_write_tokens,
                COALESCE(SUM(m.web_searches), 0)       AS web_searches,
                COALESCE(SUM($cost), 0)                AS cost_thb
           FROM ai_messages m
           JOIN ai_conversations c ON c.id = m.conversation_id
          WHERE " . implode(' AND ', $where)
    );
    $stmt->execute($args);
    $row = $stmt->fetch() ?: [];

    return [
        'turns'              => (int)($row['turns'] ?? 0),
        'input_tokens'       => (int)($row['input_tokens'] ?? 0),
        'output_tokens'      => (int)($row['output_tokens'] ?? 0),
        'cache_read_tokens'  => (int)($row['cache_read_tokens'] ?? 0),
        'cache_write_tokens' => (int)($row['cache_write_tokens'] ?? 0),
        'web_searches'       => (int)($row['web_searches'] ?? 0),
        'cost_thb'           => round((float)($row['cost_thb'] ?? 0), 2),
    ];
}

$response = [
    'month'  => date('Y-m'),
    'budget' => [
        'monthly_thb'    => nova_monthly_budget_thb(),
        'daily_per_user' => nova_daily_message_limit(),
    ],
    'me'     => nova_usage_totals($pdo, $cost, $monthStart, $user['id']),
];

// Messages the caller has sent since midnight, so the UI can show how much of
// the daily allowance is left rather than only reporting it once it is gone.
$stmt = $pdo->prepare(
    "SELECT COUNT(*)
       FROM ai_messages m
       JOIN ai_conversations c ON c.id = m.conversation_id
      WHERE c.user_id = ? AND m.role = 'user' AND m.created_at >= CURDATE()"
);
$stmt->execute([$user['id']]);
$response['me']['messages_today'] = (int)$stmt->fetchColumn();

if ($wantsAll) {
    $response['office'] = nova_usage_totals($pdo, $cost, $monthStart, null);

    $stmt = $pdo->prepare(
        "SELECT u.username, u.full_name, u.nickname, u.office, u.position,
                COUNT(*) AS turns,
                COALESCE(SUM($cost), 0) AS cost_thb
           FROM ai_messages m
           JOIN ai_conversations c ON c.id = m.conversation_id
           JOIN users u ON u.id = c.user_id
          WHERE m.role = 'assistant' AND m.created_at >= ?
          GROUP BY u.id, u.username, u.full_name, u.nickname, u.office, u.position
          ORDER BY cost_thb DESC"
    );
    $stmt->execute([$monthStart]);

    // Named the way the office names people. An admin reading a spend table
    // should not have to work out who `indosmile_rod` is.
    $response['by_user'] = array_map(fn($r) => [
        'username' => $r['username'],
        'name'     => nova_user_display($r)['display_name'],
        'turns'    => (int)$r['turns'],
        'cost_thb' => round((float)$r['cost_thb'], 2),
    ], $stmt->fetchAll());
}

json_out($response);
