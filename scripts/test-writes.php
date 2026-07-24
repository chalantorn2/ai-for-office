<?php
/**
 * Checks the write path — everything up to the moment a change is applied.
 *
 * Proposing is safe to test against production: it writes to `ai_record_writes`
 * and nothing else, and this script cleans up after itself by deleting the
 * conversation it made, which takes its proposals with it. Applying is not, so
 * the confirm branch is exercised only through cancel, and the records used are
 * read back at the end to prove they were never touched.
 *
 * What that leaves untested is the INSERT and UPDATE themselves. The statements
 * are prepared against MySQL — which catches a mistyped column — but never
 * executed. The first real one should be a small edit through the UI, checked in
 * ContactRate afterwards.
 *
 * Run:  php scripts/test-writes.php
 */

declare(strict_types=1);

require_once __DIR__ . '/../api/config.php';
require_once __DIR__ . '/../api/lib/writes.php';

$pdo = nova_db();
$failures = 0;

function check(string $what, bool $ok, string $detail = ''): void
{
    global $failures;
    if (!$ok) {
        $failures++;
    }
    echo ($ok ? '  ok   ' : '  FAIL ') . $what . ($detail !== '' ? "  — $detail" : '') . "\n";
}

/** The error code a proposal came back with, or its status when it succeeded. */
function outcome(array $out): string
{
    return (string)($out['error'] ?? $out['status'] ?? 'unknown');
}

/**
 * The statement the confirm button would run, sent to the server to be parsed
 * and then thrown away. Emulated prepares are off, so MySQL really does compile
 * it — a mistyped column or a stray backtick fails here — and nothing is
 * executed, so the record is no more written to than by a SELECT.
 */
function prepares(PDO $pdo, array $statement): string
{
    [$sql, $args] = $statement;

    $placeholders = substr_count($sql, '?');
    if ($placeholders !== count($args)) {
        return "$placeholders placeholders for " . count($args) . ' arguments';
    }

    try {
        $pdo->prepare($sql);
        return '';
    } catch (Throwable $e) {
        return $e->getMessage();
    }
}

/** The stored diff of a proposal, as the apply step would read it back. */
function stored_changes(PDO $pdo, int $id): array
{
    $stmt = $pdo->prepare('SELECT changes FROM ai_record_writes WHERE id = ?');
    $stmt->execute([$id]);
    return json_decode((string)$stmt->fetchColumn(), true) ?: [];
}

// ---------------------------------------------------------------- fixtures

$user = $pdo->query('SELECT id, username FROM users ORDER BY id LIMIT 1')->fetch();
if (!$user) {
    exit("no users in the database\n");
}

$tour = $pdo->query(
    'SELECT * FROM tours WHERE supplier_id IS NOT NULL AND adult_price > 0 ORDER BY id LIMIT 1'
)->fetch();
$supplier = $pdo->query('SELECT * FROM suppliers ORDER BY id LIMIT 1')->fetch();
if (!$tour || !$supplier) {
    exit("no usable tour or supplier to test against\n");
}

$pdo->prepare('INSERT INTO ai_conversations (user_id, title) VALUES (?, ?)')
    ->execute([$user['id'], '[test-writes] ' . date('c')]);
$conversationId = (int)$pdo->lastInsertId();

$ctx = [
    'user_id'         => (int)$user['id'],
    'username'        => (string)$user['username'],
    'conversation_id' => $conversationId,
];

// Who the apply step runs as — the shape require_user() hands the endpoint.
$actor = ['id' => (int)$user['id'], 'username' => (string)$user['username']];

$tourBefore = $tour;
$supplierBefore = $supplier;
$tourId = (int)$tour['id'];
$supplierId = (int)$supplier['id'];

echo "tour #{$tourId} — {$tour['tour_name']}\n";
echo "supplier #{$supplierId} — {$supplier['name']}\n";
echo "conversation #{$conversationId} (deleted at the end)\n\n";

// -------------------------------------------------------- tours: rejects

echo "tours — rejects bad input\n";

check(
    'unknown tour',
    outcome(nova_propose_write($pdo, $ctx, 'tour', 'update', ['tour_id' => 99999999])) === 'not_found'
);

check(
    'supplier that does not exist',
    outcome(nova_propose_write($pdo, $ctx, 'tour', 'update', [
        'tour_id' => $tourId, 'supplier_id' => 99999999,
    ])) === 'invalid_value'
);

check(
    'province not already in the data',
    outcome(nova_propose_write($pdo, $ctx, 'tour', 'update', [
        'tour_id' => $tourId, 'destination' => 'Atlantis',
    ])) === 'invalid_value'
);

check(
    'price outside the plausible range',
    outcome(nova_propose_write($pdo, $ctx, 'tour', 'update', [
        'tour_id' => $tourId, 'adult_price' => 9999999,
    ])) === 'invalid_value'
);

check(
    'price that is not a number',
    outcome(nova_propose_write($pdo, $ctx, 'tour', 'update', [
        'tour_id' => $tourId, 'adult_price' => 'ประมาณพันนึง',
    ])) === 'invalid_value'
);

check(
    'date in the wrong format',
    outcome(nova_propose_write($pdo, $ctx, 'tour', 'update', [
        'tour_id' => $tourId, 'start_date' => '15/05/2026',
    ])) === 'invalid_value'
);

check(
    'period that ends before it starts',
    outcome(nova_propose_write($pdo, $ctx, 'tour', 'update', [
        'tour_id' => $tourId, 'start_date' => '2026-06-01', 'end_date' => '2026-05-01',
    ])) === 'invalid_value'
);

check(
    'create with no name',
    outcome(nova_propose_write($pdo, $ctx, 'tour', 'create', ['adult_price' => 500])) === 'invalid_value'
);

// The same name under the same supplier is nearly always the same tour twice.
check(
    'same tour name under the same supplier',
    outcome(nova_propose_write($pdo, $ctx, 'tour', 'create', [
        'tour_name'   => (string)$tour['tour_name'],
        'supplier_id' => (int)$tour['supplier_id'],
        'adult_price' => 500,
    ])) === 'invalid_value'
);

// -------------------------------------------------------- tours: no-change

echo "\ntours — ignores values that are already what the record says\n";

$echoed = nova_propose_write($pdo, $ctx, 'tour', 'update', [
    'tour_id'     => $tourId,
    'tour_name'   => $tour['tour_name'],
    'adult_price' => (float)$tour['adult_price'],
    'supplier_id' => (int)$tour['supplier_id'],
]);
check('whole record echoed back', outcome($echoed) === 'no_change');

// A price the model sends as an int where the column holds "500.00" is the same
// price. Left as a diff, every proposal would carry rows nobody asked to change.
check(
    'decimal column against an integer argument',
    outcome(nova_propose_write($pdo, $ctx, 'tour', 'update', [
        'tour_id' => $tourId, 'adult_price' => (int)(float)$tour['adult_price'],
    ])) === 'no_change'
);

// ------------------------------------------------------- tours: a real diff

echo "\ntours — proposes\n";

$newPrice = round((float)$tour['adult_price'] + 50, 2);
$proposed = nova_propose_write($pdo, $ctx, 'tour', 'update', [
    'tour_id'     => $tourId,
    'adult_price' => $newPrice,
    // Same value, different case. Stored in the spelling already in use, so it
    // stays visible to a search that filters on exact equality.
    'destination' => strtolower((string)$tour['destination']),
]);

check('came back pending', outcome($proposed) === 'pending_confirmation');

$card = $proposed['_card'] ?? [];
check('one field on the card', count($card['changes'] ?? []) === 1, json_encode(array_column($card['changes'] ?? [], 'field')));
check('card is pending', ($card['status'] ?? '') === 'pending');
check('card has no link yet', ($card['link'] ?? null) === null);
check('card knows what it is', ($card['entity'] ?? '') === 'tour' && ($card['entity_label'] ?? '') === 'ทัวร์');
check('the change is the price', ($card['changes'][0]['field'] ?? '') === 'adult_price');
check(
    'both sides shown',
    str_contains((string)($card['changes'][0]['from'] ?? ''), number_format((float)$tour['adult_price'], 0))
    && str_contains((string)($card['changes'][0]['to'] ?? ''), number_format($newPrice, 0)),
    ($card['changes'][0]['from'] ?? '') . ' → ' . ($card['changes'][0]['to'] ?? '')
);

$updateSql = nova_write_statement('tour', 'update', $tourId, stored_changes($pdo, (int)$card['id']), $actor['username']);
$error = prepares($pdo, $updateSql);
check('UPDATE compiles', $error === '', $error ?: $updateSql[0]);
check(
    'UPDATE stamps who did it',
    str_contains($updateSql[0], '`updated_by` = ?') && in_array($actor['username'], $updateSql[1], true)
);
check('UPDATE is scoped to one row', str_ends_with($updateSql[0], 'WHERE id = ?'));

nova_apply_write($pdo, $actor, (int)$card['id'], false);

// ----------------------------------------------------- suppliers: rejects

echo "\nsuppliers — rejects bad input\n";

check(
    'unknown supplier',
    outcome(nova_propose_write($pdo, $ctx, 'supplier', 'update', ['supplier_id' => 99999999])) === 'not_found'
);

check(
    'name that already exists',
    outcome(nova_propose_write($pdo, $ctx, 'supplier', 'create', [
        'name' => (string)$supplier['name'],
    ])) === 'invalid_value'
);

check(
    'email that is not one',
    outcome(nova_propose_write($pdo, $ctx, 'supplier', 'update', [
        'supplier_id' => $supplierId, 'email' => 'ส่งเมลมาที่ออฟฟิศ',
    ])) === 'invalid_value'
);

check(
    'phone with no digits in it',
    outcome(nova_propose_write($pdo, $ctx, 'supplier', 'update', [
        'supplier_id' => $supplierId, 'phone' => 'โทรหาพี่เอ',
    ])) === 'invalid_value'
);

check(
    'website that is not a link',
    outcome(nova_propose_write($pdo, $ctx, 'supplier', 'update', [
        'supplier_id' => $supplierId, 'website' => 'facebook เขาน่ะ',
    ])) === 'invalid_value'
);

check(
    'create with no name',
    outcome(nova_propose_write($pdo, $ctx, 'supplier', 'create', ['phone' => '081-000-0000'])) === 'invalid_value'
);

// A phone number written the way the office writes them has to survive. 36 of
// the 37 rows hold something in this shape, and a stricter rule would reject
// numbers that are already in the system and correct.
check(
    'a phone number as staff actually write it',
    outcome(nova_propose_write($pdo, $ctx, 'supplier', 'update', [
        'supplier_id' => $supplierId, 'phone_5' => '+66 81 234 5678 (คุณเอ)',
    ])) === 'pending_confirmation'
);

// --------------------------------------------------- suppliers: a real diff

echo "\nsuppliers — proposes a new one\n";

$newSupplier = nova_propose_write($pdo, $ctx, 'supplier', 'create', [
    'name'    => '[test] Sriracha Crocodile Farm ' . date('H:i:s'),
    'phone'   => '038-411-8xx',
    'email'   => 'sales@example.com',
    'website' => 'https://example.com',
    'address' => 'Sriracha, Chonburi',
]);
check('came back pending', outcome($newSupplier) === 'pending_confirmation');

$supplierCard = $newSupplier['_card'] ?? [];
check('five fields on the card', count($supplierCard['changes'] ?? []) === 5, (string)count($supplierCard['changes'] ?? []));
check(
    'card knows what it is',
    ($supplierCard['entity'] ?? '') === 'supplier' && ($supplierCard['entity_label'] ?? '') === 'ซัพพลายเออร์'
);
check(
    'no previous values',
    array_filter(array_column($supplierCard['changes'] ?? [], 'from')) === []
);

$insertSql = nova_write_statement('supplier', 'create', null, stored_changes($pdo, (int)$supplierCard['id']), $actor['username']);
$error = prepares($pdo, $insertSql);
check('INSERT compiles', $error === '', $error ?: $insertSql[0]);
// `suppliers` has no such column. Naming it anyway would fail at the moment the
// button is pressed, which is the worst possible time to find out.
check(
    'INSERT does not name a column suppliers lacks',
    !str_contains($insertSql[0], 'updated_by'),
    $insertSql[0]
);

echo "\nsuppliers — proposes an edit\n";

$supplierEdit = nova_propose_write($pdo, $ctx, 'supplier', 'update', [
    'supplier_id' => $supplierId,
    'name'        => (string)$supplier['name'],   // unchanged — must drop out
    'line'        => '@' . strtolower(preg_replace('/[^a-zA-Z]/', '', (string)$supplier['name']) ?: 'test'),
]);
check('came back pending', outcome($supplierEdit) === 'pending_confirmation');
check(
    'the unchanged name is not on the card',
    count($supplierEdit['_card']['changes'] ?? []) === 1,
    json_encode(array_column($supplierEdit['_card']['changes'] ?? [], 'field'))
);

$editSql = nova_write_statement('supplier', 'update', $supplierId, stored_changes($pdo, (int)$supplierEdit['_card']['id']), $actor['username']);
$error = prepares($pdo, $editSql);
check('UPDATE compiles', $error === '', $error ?: $editSql[0]);
check('UPDATE does not stamp a column suppliers lacks', !str_contains($editSql[0], 'updated_by'), $editSql[0]);

// ------------------------------------------------------------ nothing moved

echo "\nnothing was written\n";

$tourAfter = $pdo->query('SELECT * FROM tours WHERE id = ' . $tourId)->fetch();
check(
    'the tour is untouched',
    $tourAfter == $tourBefore,
    'columns that moved: ' . implode(', ', array_keys(array_diff_assoc($tourAfter ?: [], $tourBefore)))
);

$supplierAfter = $pdo->query('SELECT * FROM suppliers WHERE id = ' . $supplierId)->fetch();
check(
    'the supplier is untouched',
    $supplierAfter == $supplierBefore,
    'columns that moved: ' . implode(', ', array_keys(array_diff_assoc($supplierAfter ?: [], $supplierBefore)))
);

check(
    'no new supplier was created',
    (int)$pdo->query("SELECT COUNT(*) FROM suppliers WHERE name LIKE '[test]%'")->fetchColumn() === 0
);

// ------------------------------------------------------------------ reload

echo "\nreloads and decides\n";

$reloaded = nova_conversation_writes($pdo, $conversationId);
// One tour edit, and three on suppliers: the phone-format check, the create, and
// the edit. Everything else above was rejected before it reached the table.
check('every proposal comes back', count($reloaded) === 4, count($reloaded) . ' found');
check(
    'both kinds are in there',
    count(array_unique(array_column($reloaded, 'entity'))) === 2,
    implode(', ', array_unique(array_column($reloaded, 'entity')))
);

foreach ($reloaded as $pending) {
    if ($pending['status'] === 'pending') {
        nova_apply_write($pdo, $actor, (int)$pending['id'], false);
    }
}
check(
    'all cancelled',
    array_values(array_unique(array_column(nova_conversation_writes($pdo, $conversationId), 'status'))) === ['cancelled']
);

// ----------------------------------------------------------------- cleanup

$pdo->prepare('DELETE FROM ai_conversations WHERE id = ?')->execute([$conversationId]);
$left = $pdo->prepare('SELECT COUNT(*) FROM ai_record_writes WHERE conversation_id = ?');
$left->execute([$conversationId]);
check("\ncleanup takes the proposals with the chat", (int)$left->fetchColumn() === 0);

echo "\n" . ($failures === 0 ? "all checks passed\n" : "$failures check(s) failed\n");
exit($failures === 0 ? 0 : 1);
