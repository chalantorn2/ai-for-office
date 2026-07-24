<?php
/**
 * Confirming or cancelling a change Nova proposed — to a tour or a supplier.
 *
 * GET  /api/writes.php?conversation_id=N   -> { writes: [...] }
 * POST /api/writes.php                     { write_id, confirm } -> { write }
 *
 * This is the only path in Nova that changes a ContactRate table, and it is a
 * button press rather than anything the model can reach. `require_user()` names
 * the person doing it, `lib/writes.php` checks that the proposal is theirs and
 * still current, and `tours.updated_by` ends up carrying their username — the
 * same name the main app would have written had they typed the edit in by hand.
 *
 * GET is what puts the cards back when a chat is reopened: the proposals are
 * rows, not text, so they do not survive in the transcript on their own.
 */

declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/writes.php';

$user = require_user();
$pdo = nova_db();

switch ($_SERVER['REQUEST_METHOD'] ?? 'GET') {
    case 'GET':
        $conversationId = (int)($_GET['conversation_id'] ?? 0);
        if ($conversationId <= 0) {
            json_error(400, 'missing_conversation_id');
        }

        // Scoped through the conversation's owner rather than the proposal's, so
        // this cannot be used to walk ids belonging to somebody else's chat.
        $stmt = $pdo->prepare(
            'SELECT id FROM ai_conversations WHERE id = ? AND user_id = ? LIMIT 1'
        );
        $stmt->execute([$conversationId, $user['id']]);
        if (!$stmt->fetch()) {
            json_error(404, 'not_found');
        }

        json_out(['writes' => nova_conversation_writes($pdo, $conversationId)]);

        // no break — json_out never returns

    case 'POST':
        $body = json_body();
        $writeId = (int)($body['write_id'] ?? 0);

        if ($writeId <= 0) {
            json_error(400, 'missing_write_id');
        }
        // Absent is not false. A client that forgot the field must not be read
        // as having cancelled — or, worse, as having confirmed.
        if (!array_key_exists('confirm', $body) || !is_bool($body['confirm'])) {
            json_error(400, 'missing_confirm');
        }

        $result = nova_apply_write($pdo, $user, $writeId, $body['confirm']);
        json_out(['write' => $result['card']]);

        // no break — json_out never returns

    default:
        json_error(405, 'method_not_allowed');
}
