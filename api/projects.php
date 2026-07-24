<?php
/**
 * Projects — folders over chat history. Scoped to the caller exactly the way
 * conversations are: a project is only ever visible to the person who made it.
 *
 * GET    /api/projects.php              -> { projects: [{ id, name, chat_count, … }] }
 * POST   /api/projects.php   { name? }  -> { project }
 * PATCH  /api/projects.php?id=N { name }-> { project }
 * DELETE /api/projects.php?id=N         -> 204   (chats inside are released, not deleted)
 */

declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/projects.php';

$user = require_user();
$pdo = nova_db();
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

/** The name shown in the sidebar. Empty is allowed; the client labels it. */
function project_name(): string
{
    return mb_substr(trim((string)(json_body()['name'] ?? '')), 0, 120);
}

/** Loads one project with its chat count, only if it belongs to the caller. */
function own_project(PDO $pdo, int $id, int $userId): array
{
    $stmt = $pdo->prepare(
        'SELECT p.id, p.name, p.created_at, p.updated_at,
                (SELECT COUNT(*) FROM ai_conversations c WHERE c.project_id = p.id) AS chat_count
           FROM ai_projects p
          WHERE p.id = ? AND p.user_id = ?
          LIMIT 1'
    );
    $stmt->execute([$id, $userId]);
    $row = $stmt->fetch();

    if (!$row) {
        json_error(404, 'not_found');
    }
    $row['chat_count'] = (int)$row['chat_count'];
    return $row;
}

switch ($method) {
    case 'GET':
        $stmt = $pdo->prepare(
            'SELECT p.id, p.name, p.created_at, p.updated_at,
                    (SELECT COUNT(*) FROM ai_conversations c WHERE c.project_id = p.id) AS chat_count
               FROM ai_projects p
              WHERE p.user_id = ?
              ORDER BY p.created_at
              LIMIT 100'
        );
        $stmt->execute([$user['id']]);

        // The count comes back from PDO as a string; the left operand of `+`
        // wins, so the cast has to be on that side or it is silently discarded.
        $projects = array_map(
            static fn(array $p): array => ['chat_count' => (int)$p['chat_count']] + $p,
            $stmt->fetchAll()
        );
        json_out(['projects' => $projects]);

        // no break — json_out never returns

    case 'POST':
        $stmt = $pdo->prepare('INSERT INTO ai_projects (user_id, name) VALUES (?, ?)');
        $stmt->execute([$user['id'], project_name()]);

        json_out(['project' => own_project($pdo, (int)$pdo->lastInsertId(), $user['id'])], 201);

    case 'PATCH':
        if ($id <= 0) {
            json_error(400, 'missing_id');
        }
        own_project($pdo, $id, $user['id']);

        $name = project_name();
        if ($name === '') {
            json_error(400, 'missing_name');
        }

        $stmt = $pdo->prepare('UPDATE ai_projects SET name = ? WHERE id = ? AND user_id = ?');
        $stmt->execute([$name, $id, $user['id']]);

        json_out(['project' => own_project($pdo, $id, $user['id'])]);

    case 'DELETE':
        if ($id <= 0) {
            json_error(400, 'missing_id');
        }
        own_project($pdo, $id, $user['id']);

        // The conversations inside are released, not removed: the foreign key is
        // ON DELETE SET NULL, so they reappear under their date heading with
        // every message — and every recorded cost — intact. See 005.
        $stmt = $pdo->prepare('DELETE FROM ai_projects WHERE id = ? AND user_id = ?');
        $stmt->execute([$id, $user['id']]);

        http_response_code(204);
        exit;

    default:
        json_error(405, 'method_not_allowed');
}
