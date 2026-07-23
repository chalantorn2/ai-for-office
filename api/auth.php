<?php
/**
 * POST /api/auth.php          { username, password }  -> { token, user }
 * GET  /api/auth.php?me=1     Authorization: Bearer   -> { user }
 */

declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/lib/auth.php';

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($method === 'GET') {
    $user = require_user();
    // `about` is written for Nova, not for the person it describes. It goes into
    // the system prompt and nowhere near the client.
    unset($user['about']);
    json_out(['user' => $user]);
}

if ($method !== 'POST') {
    json_error(405, 'method_not_allowed');
}

$body = json_body();
$username = trim((string)($body['username'] ?? ''));
$password = (string)($body['password'] ?? '');

if ($username === '' || $password === '') {
    json_error(400, 'missing_credentials', 'ต้องกรอกชื่อผู้ใช้และรหัสผ่าน');
}

$pdo = nova_db();
$stmt = $pdo->prepare(
    'SELECT id, username, password, role, full_name, nickname, office, position
       FROM users WHERE username = ? LIMIT 1'
);
$stmt->execute([$username]);
$user = $stmt->fetch();

// Same response whether the user is unknown or the password is wrong, so the
// endpoint cannot be used to enumerate staff accounts.
if (!$user || !verify_and_upgrade_password($pdo, $user, $password)) {
    // Cost a little time on failure to blunt online guessing.
    usleep(300_000);
    json_error(401, 'invalid_credentials', 'ชื่อผู้ใช้หรือรหัสผ่านไม่ถูกต้อง');
}

// Only the id is load-bearing — every later request re-reads the profile from
// `users`, so nothing here can go stale in a token that lives twelve hours.
$token = jwt_issue(
    [
        'sub'      => (int)$user['id'],
        'username' => $user['username'],
        'role'     => $user['role'] ?? 'user',
    ],
    $CONFIG['jwt_secret'],
    NOVA_TOKEN_TTL
);

json_out([
    'token' => $token,
    'user'  => [
        'id'       => (int)$user['id'],
        'username' => $user['username'],
        'role'     => $user['role'] ?? 'user',
    ] + nova_user_display($user),
]);
