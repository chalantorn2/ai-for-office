<?php
/**
 * Chat history. Every statement here filters on user_id — a conversation is
 * only ever visible to the person who had it.
 *
 * GET    /api/conversations.php            -> { conversations: [...] }
 * GET    /api/conversations.php?id=N       -> { conversation, messages, writes }
 * POST   /api/conversations.php            { title?, project_id? }      -> { conversation }
 * PATCH  /api/conversations.php?id=N       { title?, project_id? }      -> { conversation }
 * DELETE /api/conversations.php?id=N       -> 204
 *
 * PATCH takes either field on its own: a rename sends `title`, and moving a
 * chat into or out of a folder sends `project_id` (null for "no folder").
 */

declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/projects.php';
require_once __DIR__ . '/lib/images.php';
require_once __DIR__ . '/lib/writes.php';

$user = require_user();
$pdo = nova_db();
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

/** Loads a conversation only if it belongs to the caller. */
function own_conversation(PDO $pdo, int $id, int $userId): array
{
    $stmt = $pdo->prepare(
        'SELECT id, project_id, title, created_at, updated_at
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
    // NULL means "no folder"; the client groups on it, so keep it distinct
    // from the integer ids rather than letting it arrive as the string "0".
    $row['project_id'] = $row['project_id'] === null ? null : (int)$row['project_id'];
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
            $messages = $stmt->fetchAll();

            // Ids and dimensions only — the bytes are fetched one at a time from
            // image.php, so reopening a chat with a dozen screenshots in it is
            // still one small response. The dimensions let the thumbnail reserve
            // its space before the image lands, which stops the whole thread
            // jumping as each one arrives.
            $images = nova_load_images($pdo, array_column($messages, 'id'));
            foreach ($messages as &$message) {
                $message['images'] = array_map(
                    static fn(array $img): array => [
                        'id'     => (int)$img['id'],
                        'width'  => (int)$img['width'],
                        'height' => (int)$img['height'],
                    ],
                    $images[(int)$message['id']] ?? []
                );
            }
            unset($message);

            json_out([
                'conversation' => $conversation,
                'messages'     => $messages,
                // Proposed tour changes are rows rather than text, so nothing in
                // the transcript above would bring a still-unconfirmed card
                // back. Sent whatever their state: a card that says "ยืนยันแล้ว"
                // is the only record in the chat that the change happened at all.
                'writes'       => nova_conversation_writes($pdo, $id),
            ]);
        }

        $stmt = $pdo->prepare(
            'SELECT id, project_id, title, created_at, updated_at
               FROM ai_conversations
              WHERE user_id = ?
              ORDER BY updated_at DESC
              LIMIT 200'
        );
        $stmt->execute([$user['id']]);

        $conversations = array_map(
            static fn(array $c): array =>
                ['project_id' => $c['project_id'] === null ? null : (int)$c['project_id']] + $c,
            $stmt->fetchAll()
        );
        json_out(['conversations' => $conversations]);

        // no break — json_out never returns

    case 'POST':
        $body = json_body();
        $title = trim((string)($body['title'] ?? ''));
        $projectId = nova_project_id($pdo, $body['project_id'] ?? null, $user['id']);

        $stmt = $pdo->prepare(
            'INSERT INTO ai_conversations (user_id, project_id, title) VALUES (?, ?, ?)'
        );
        $stmt->execute([$user['id'], $projectId ?: null, mb_substr($title, 0, 255)]);

        json_out([
            'conversation' => own_conversation($pdo, (int)$pdo->lastInsertId(), $user['id']),
        ], 201);

    case 'PATCH':
        if ($id <= 0) {
            json_error(400, 'missing_id');
        }
        own_conversation($pdo, $id, $user['id']);

        $body = json_body();
        $renaming = array_key_exists('title', $body);
        $moving = array_key_exists('project_id', $body);

        if (!$renaming && !$moving) {
            json_error(400, 'nothing_to_update');
        }

        if ($renaming) {
            $title = trim((string)$body['title']);
            if ($title === '') {
                json_error(400, 'missing_title');
            }

            $stmt = $pdo->prepare(
                'UPDATE ai_conversations SET title = ? WHERE id = ? AND user_id = ?'
            );
            $stmt->execute([mb_substr($title, 0, 255), $id, $user['id']]);
        }

        if ($moving) {
            $projectId = nova_project_id($pdo, $body['project_id'], $user['id']);

            // `updated_at` is assigned to itself to suppress ON UPDATE
            // CURRENT_TIMESTAMP. The column orders the sidebar and means "last
            // asked in", so filing a chat into a folder must not send a
            // three-week-old conversation back to the top of today.
            $stmt = $pdo->prepare(
                'UPDATE ai_conversations
                    SET project_id = ?, updated_at = updated_at
                  WHERE id = ? AND user_id = ?'
            );
            $stmt->execute([$projectId ?: null, $id, $user['id']]);
        }

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
