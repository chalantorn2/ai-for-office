<?php
/**
 * GET /api/image.php?id=N  ->  the image bytes
 *
 * Serves one attachment from api/uploads/, which is closed to direct requests
 * (see uploads/.htaccess). An image is part of the conversation it was attached
 * to and is exactly as private: the id is resolved through ai_messages to
 * ai_conversations and checked against the caller, so a guessed id from someone
 * else's chat is a 404 like any other.
 *
 * The browser cannot put an Authorization header on an <img src>, so the client
 * fetches this with the token and renders the blob (see src/lib/api.js).
 */

declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/images.php';

$user = require_user();

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
    json_error(405, 'method_not_allowed');
}

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($id <= 0) {
    json_error(400, 'missing_id');
}

$stmt = nova_db()->prepare(
    'SELECT img.path, img.media_type
       FROM ai_message_images img
       JOIN ai_messages      msg ON msg.id = img.message_id
       JOIN ai_conversations c   ON c.id   = msg.conversation_id
      WHERE img.id = ? AND c.user_id = ?
      LIMIT 1'
);
$stmt->execute([$id, $user['id']]);
$row = $stmt->fetch();

// 404 rather than 403, so the caller does not learn that this id exists.
if (!$row) {
    json_error(404, 'not_found');
}

// The path comes from a column this application is the only writer of, but it
// is concatenated into a filesystem path — so it is confirmed to still be under
// the upload root before anything is opened.
$root = realpath(nova_image_root());
$file = realpath($root . '/' . $row['path']);

if ($root === false || $file === false || !str_starts_with($file, $root . DIRECTORY_SEPARATOR)) {
    error_log('nova: upload outside root for image ' . $id);
    json_error(404, 'not_found');
}

header('Content-Type: ' . $row['media_type']);
header('Content-Length: ' . (string)filesize($file));
// The bytes at an id never change, and the id is only readable by its owner.
header('Cache-Control: private, max-age=31536000, immutable');
header('X-Content-Type-Options: nosniff');
// Defence in depth against a stored payload in a file that sniffs as an image:
// nothing here is ever rendered as a document.
header("Content-Security-Policy: default-src 'none'; sandbox");

readfile($file);
