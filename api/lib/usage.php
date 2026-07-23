<?php
/**
 * What a turn cost, and whether the caller is allowed another one.
 *
 * Two things live here because they are the same problem seen from both ends:
 * pricing turns tokens into money after the fact, and the limiter stops the
 * bill before it happens. Nova had neither — any member of staff could ask two
 * hundred questions in an afternoon and nothing in the system would notice.
 */

declare(strict_types=1);

/**
 * Anthropic bills in USD; the office budgets in baht. One rate, in one place,
 * so every figure Nova reports moves together when it is updated.
 */
const NOVA_USD_THB = 35.0;

/**
 * Introductory pricing for Sonnet 5 ends 31 Aug 2026, after which input and
 * output both rise 50%. A turn is priced at the rate in force on the day it
 * ran, so August's history does not silently reprice itself in September.
 */
const NOVA_PRICE_INTRO_UNTIL = '2026-08-31';

/** USD per million tokens. */
const NOVA_PRICE = [
    'intro'    => ['in' => 2.00, 'out' => 10.00],
    'standard' => ['in' => 3.00, 'out' => 15.00],
];

/**
 * Cache reads bill at 10% of the input rate, writes at 125%. Both are derived
 * from the input rate rather than listed separately — they track it.
 */
const NOVA_CACHE_READ_RATE  = 0.10;
const NOVA_CACHE_WRITE_RATE = 1.25;

/** Web search is charged per search on top of tokens: $10 per 1,000. */
const NOVA_PRICE_PER_SEARCH = 0.01;

/**
 * Messages one member of staff may send in a day. Sized to be generous for real
 * work and still bound a runaway loop or a wedged client retrying forever —
 * nobody asks eighty genuine questions in a day.
 */
const NOVA_DAILY_MESSAGE_LIMIT = 80;

/**
 * Office-wide ceiling for the calendar month, in baht. The daily cap bounds one
 * person; this bounds the bill. Set above the ~2,000 THB working budget so it
 * is a circuit breaker rather than a monthly speed bump.
 */
const NOVA_MONTHLY_BUDGET_THB = 3000.0;

/**
 * Both limits are overridable from config.local.php — `daily_message_limit` and
 * `monthly_budget_thb`. The refusal messages tell staff to ask an admin to
 * raise the cap, and that has to be something an admin can actually do without
 * a deploy.
 */
function nova_limit(string $key, float $default): float
{
    global $CONFIG;
    $value = $CONFIG['limits'][$key] ?? null;

    return is_numeric($value) ? (float)$value : $default;
}

function nova_daily_message_limit(): int
{
    return (int)nova_limit('daily_message_limit', NOVA_DAILY_MESSAGE_LIMIT);
}

function nova_monthly_budget_thb(): float
{
    return nova_limit('monthly_budget_thb', NOVA_MONTHLY_BUDGET_THB);
}

/**
 * Cost of one turn in baht.
 *
 * `$on` is the date the turn ran (any strtotime-able string), which selects the
 * price list. Pass the row's created_at when costing history.
 */
function nova_turn_cost_thb(array $usage, ?string $on = null): float
{
    $intro = strtotime($on ?? 'now') <= strtotime(NOVA_PRICE_INTRO_UNTIL . ' 23:59:59');
    $price = NOVA_PRICE[$intro ? 'intro' : 'standard'];

    $usd =
        ((int)($usage['input_tokens']       ?? 0) / 1_000_000) * $price['in']
      + ((int)($usage['output_tokens']      ?? 0) / 1_000_000) * $price['out']
      + ((int)($usage['cache_read_tokens']  ?? 0) / 1_000_000) * $price['in'] * NOVA_CACHE_READ_RATE
      + ((int)($usage['cache_write_tokens'] ?? 0) / 1_000_000) * $price['in'] * NOVA_CACHE_WRITE_RATE
      + ((int)($usage['web_searches']       ?? 0) * NOVA_PRICE_PER_SEARCH);

    return $usd * NOVA_USD_THB;
}

/**
 * SQL that costs a set of ai_messages rows in baht, for use inside SUM().
 *
 * Kept as one expression so a report never fetches every row to add them up in
 * PHP. The CASE mirrors nova_turn_cost_thb: rows dated on or before the
 * introductory cut-off price at the introductory rate.
 */
function nova_cost_sql(string $alias = 'm'): string
{
    $t = NOVA_USD_THB;
    $cut = NOVA_PRICE_INTRO_UNTIL;
    $r = NOVA_CACHE_READ_RATE;
    $w = NOVA_CACHE_WRITE_RATE;
    $s = NOVA_PRICE_PER_SEARCH;

    $rate = fn(string $key) => sprintf(
        "(CASE WHEN DATE($alias.created_at) <= '$cut' THEN %F ELSE %F END)",
        NOVA_PRICE['intro'][$key],
        NOVA_PRICE['standard'][$key]
    );

    $in = $rate('in');
    $out = $rate('out');

    return "(
        COALESCE($alias.input_tokens, 0)       / 1000000 * $in
      + COALESCE($alias.output_tokens, 0)      / 1000000 * $out
      + COALESCE($alias.cache_read_tokens, 0)  / 1000000 * $in * $r
      + COALESCE($alias.cache_write_tokens, 0) / 1000000 * $in * $w
      + COALESCE($alias.web_searches, 0) * $s
    ) * $t";
}

/**
 * Checks both limits before a turn is allowed to start.
 *
 * @return null|array{code:string, message:string}  null when the turn may proceed
 */
/**
 * Neither count below filters `superseded_at`, and that is deliberate: a turn
 * an edit replaced was still asked, still ran, and still cost money. Filtering
 * it out would let someone edit their way back under the daily cap and would
 * quietly shrink the month's reported spend. Only the transcript forgets them.
 */
function nova_rate_limit_check(PDO $pdo, int $userId): ?array
{
    $daily = nova_daily_message_limit();

    $stmt = $pdo->prepare(
        "SELECT COUNT(*)
           FROM ai_messages m
           JOIN ai_conversations c ON c.id = m.conversation_id
          WHERE c.user_id = ?
            AND m.role = 'user'
            AND m.created_at >= CURDATE()"
    );
    $stmt->execute([$userId]);

    if ((int)$stmt->fetchColumn() >= $daily) {
        return [
            'code'    => 'daily_limit_reached',
            'message' => 'วันนี้ถามครบ ' . $daily . ' คำถามแล้ว '
                       . 'ถามต่อได้พรุ่งนี้ ถ้าจำเป็นต้องใช้เพิ่มวันนี้ แจ้งผู้ดูแลระบบ',
        ];
    }

    // Month to date, everyone. Costed in SQL so this stays one round trip.
    $budget = nova_monthly_budget_thb();
    $stmt = $pdo->query(
        'SELECT COALESCE(SUM(' . nova_cost_sql('m') . '), 0)
           FROM ai_messages m
          WHERE m.role = \'assistant\'
            AND m.created_at >= DATE_FORMAT(CURDATE(), \'%Y-%m-01\')'
    );
    $spent = (float)$stmt->fetchColumn();

    if ($spent >= $budget) {
        return [
            'code'    => 'monthly_budget_reached',
            'message' => sprintf(
                'เดือนนี้ใช้งบ AI ครบ %s บาทแล้ว Nova จะกลับมาใช้ได้ต้นเดือนหน้า '
                . 'ถ้าต้องใช้ต่อ แจ้งผู้ดูแลระบบให้ปรับเพดาน',
                number_format($budget)
            ),
        ];
    }

    return null;
}
