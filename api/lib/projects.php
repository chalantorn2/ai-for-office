<?php
/**
 * Project ownership, shared by projects.php, conversations.php and assistant.php.
 *
 * Every one of them takes a project id from the client and has to answer the
 * same question before using it — otherwise a chat could be filed into someone
 * else's folder, and the folder's owner would see a chat they cannot open.
 */

declare(strict_types=1);

/**
 * Resolves a client-supplied project id for this user.
 *
 * Returns 0 for "no project", which is what null, 0 and an empty string all
 * mean — a conversation outside any folder. Fails with 404 rather than 403 on
 * an id belonging to someone else: the caller should not learn it exists.
 */
function nova_project_id(PDO $pdo, mixed $raw, int $userId): int
{
    if ($raw === null || $raw === '' || (int)$raw <= 0) {
        return 0;
    }

    $id = (int)$raw;
    $stmt = $pdo->prepare('SELECT id FROM ai_projects WHERE id = ? AND user_id = ? LIMIT 1');
    $stmt->execute([$id, $userId]);

    if (!$stmt->fetch()) {
        json_error(404, 'project_not_found');
    }
    return $id;
}
