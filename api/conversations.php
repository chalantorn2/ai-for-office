<?php
/**
 * Chat history. Every statement here filters on user_id — a conversation is
 * only ever visible to the person who had it.
 *
 * GET    /api/conversations.php            -> { conversations: [...] }
 * GET    /api/conversations.php?id=N       -> { conversation, messages }
 * POST   /api/conversations.php            { title? }        -> { conversation }
 * PATCH  /api/conversations.php?id=N       { title }         -> { conversation }
 * DELETE /api/conversations.php?id=N       -> 204
 */

declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/lib/auth.php';

$user = require_user();
$pdo = nova_db();
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

/** Loads a conversation only if it belongs to the caller. */
function own_conversation(PDO $pdo, int $id, int $userId): array
{
    $stmt = $pdo->prepare(
        'SELECT id, title, created_at, updated_at
           FROM ai_conversations
          WHERE id = ? AND user_id = ?
          LIMIT 1'
    );
    $stmt->execute([$id, $userId]);
    $row = $stmt->fetch();

    // 404 rather than 403: the caller should not learn that this id exists.
    if (!$row) {
        json_error(404, 'not_found');
    }
    return $row;
}

switch ($method) {
    case 'GET':
        if ($id > 0) {
            $conversation = own_conversation($pdo, $id, $user['id']);

            $stmt = $pdo->prepare(
                // Superseded turns are the ones an edit replaced. They stay in
                // the table so the spend they cost still counts (see 004), but
                // they are not part of the conversation any more.
                'SELECT id, role, content, created_at
                   FROM ai_messages
                  WHERE conversation_id = ? AND superseded_at IS NULL
                  ORDER BY id'
            );
            $stmt->execute([$id]);

            json_out([
                'conversation' => $conversation,
                'messages'     => $stmt->fetchAll(),
            ]);
        }

        $stmt = $pdo->prepare(
            'SELECT id, title, created_at, updated_at
               FROM ai_conversations
              WHERE user_id = ?
              ORDER BY updated_at DESC
              LIMIT 200'
        );
        $stmt->execute([$user['id']]);
        json_out(['conversations' => $stmt->fetchAll()]);

        // no break — json_out never returns

    case 'POST':
        $title = trim((string)(json_body()['title'] ?? ''));
        $stmt = $pdo->prepare('INSERT INTO ai_conversations (user_id, title) VALUES (?, ?)');
        $stmt->execute([$user['id'], mb_substr($title, 0, 255)]);

        json_out([
            'conversation' => own_conversation($pdo, (int)$pdo->lastInsertId(), $user['id']),
        ], 201);

    case 'PATCH':
        if ($id <= 0) {
            json_error(400, 'missing_id');
        }
        own_conversation($pdo, $id, $user['id']);

        $title = trim((string)(json_body()['title'] ?? ''));
        if ($title === '') {
            json_error(400, 'missing_title');
        }

        $stmt = $pdo->prepare(
            'UPDATE ai_conversations SET title = ? WHERE id = ? AND user_id = ?'
        );
        $stmt->execute([mb_substr($title, 0, 255), $id, $user['id']]);

        json_out(['conversation' => own_conversation($pdo, $id, $user['id'])]);

    case 'DELETE':
        if ($id <= 0) {
            json_error(400, 'missing_id');
        }
        own_conversation($pdo, $id, $user['id']);

        // ai_messages rows go with it via ON DELETE CASCADE.
        $stmt = $pdo->prepare('DELETE FROM ai_conversations WHERE id = ? AND user_id = ?');
        $stmt->execute([$id, $user['id']]);

        http_response_code(204);
        exit;

    default:
        json_error(405, 'method_not_allowed');
}
