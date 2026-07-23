<?php
/**
 * Request authentication. Every endpoint except auth.php starts with
 * require_user(), which returns the caller or ends the request with 401.
 */

declare(strict_types=1);

require_once __DIR__ . '/jwt.php';

/**
 * The office each account belongs to, as staff would say it out loud.
 * `both` has no label of its own — see nova_user_display().
 */
const NOVA_OFFICE_LABELS = [
    'sevensmile' => 'Seven Smile',
    'indosmile'  => 'INDO Smile',
];

/**
 * How to address someone.
 *
 * Nickname plus the office they work for — "เลย์ · Seven Smile". For the few
 * people who work across both, the office says nothing useful, so their role
 * does the distinguishing instead: "พี่ไข่ตุ๋น · GM".
 *
 * Every part is optional in the database, so each step falls back rather than
 * rendering an empty string: nickname → full name → username, and a missing
 * suffix is simply dropped.
 */
function nova_user_display(array $row): array
{
    $name = trim((string)($row['nickname'] ?? ''))
         ?: trim((string)($row['full_name'] ?? ''))
         ?: (string)$row['username'];

    $office = (string)($row['office'] ?? 'sevensmile');
    $position = trim((string)($row['position'] ?? ''));

    $suffix = $office === 'both'
        ? $position
        : (NOVA_OFFICE_LABELS[$office] ?? '');

    return [
        'name'         => $name,
        'office'       => $office,
        'office_label' => NOVA_OFFICE_LABELS[$office] ?? '',
        'position'     => $position,
        'display_name' => $suffix === '' ? $name : "$name · $suffix",
    ];
}

/**
 * @return array{id:int, username:string, role:string, name:string,
 *               display_name:string, office:string, office_label:string,
 *               position:string}
 *
 * The token proves *who* is calling; everything else is read from `users` on
 * each request. That costs one primary-key lookup and buys three things: a
 * nickname edit shows up immediately instead of after the 12-hour token
 * expires, revoking an admin takes effect at once rather than on their next
 * login, and a deleted account's outstanding token stops working.
 */
function require_user(): array
{
    global $CONFIG;

    $token = jwt_from_request();
    if ($token === null) {
        json_error(401, 'unauthenticated');
    }

    $claims = jwt_verify($token, $CONFIG['jwt_secret']);
    if ($claims === null) {
        json_error(401, 'unauthenticated');
    }

    // `about` rides along on the lookup that already happens. It is only ever
    // read into the system prompt; nothing sends it to the browser.
    $stmt = nova_db()->prepare(
        'SELECT u.id, u.username, u.full_name, u.nickname, u.office, u.position,
                u.role, p.about
           FROM users u
           LEFT JOIN ai_user_profiles p ON p.user_id = u.id
          WHERE u.id = ? LIMIT 1'
    );
    $stmt->execute([(int)$claims['sub']]);
    $row = $stmt->fetch();

    if (!$row) {
        json_error(401, 'unauthenticated');
    }

    return [
        'id'       => (int)$row['id'],
        'username' => (string)$row['username'],
        'role'     => (string)($row['role'] ?? 'user'),
        'about'    => trim((string)($row['about'] ?? '')),
    ] + nova_user_display($row);
}

/**
 * Everyone else in the office — name, office and job, nothing more.
 *
 * Nova is told who the staff are so it can point a question at the right person
 * ("ตั๋วเครื่องบินถามพี่หนุ่ยได้"), which is a real part of knowing an office. What it
 * is deliberately not given is anyone else's `about`: that text is written to
 * shape how Nova answers *that* person, and it is not everyone's to read.
 *
 * Two accounts belong to the same person and one is shared, so the list is of
 * logins rather than of humans. Both are described in profiles.json, which is
 * where the disambiguation belongs.
 */
function nova_office_roster(PDO $pdo, int $excludeUserId): array
{
    $stmt = $pdo->prepare(
        'SELECT username, full_name, nickname, office, position
           FROM users WHERE id <> ? ORDER BY id'
    );
    $stmt->execute([$excludeUserId]);

    $lines = [];
    $seen = [];
    foreach ($stmt->fetchAll() as $row) {
        $d = nova_user_display($row);
        // An account with no nickname falls back to its login, which tells Nova
        // nothing about a person and should not be presented as a colleague.
        if (($row['nickname'] ?? '') === '' && ($row['full_name'] ?? '') === '') {
            continue;
        }
        $office = $d['office'] === 'both'
            ? 'ทั้งสองออฟฟิศ'
            : ($d['office_label'] ?: '');
        // One person, two logins: list them once.
        if (isset($seen[$d['name']])) {
            continue;
        }
        $seen[$d['name']] = true;
        $parts = array_filter([$office, $d['position']]);
        $lines[] = '- ' . $d['name'] . ($parts ? ' — ' . implode(' · ', $parts) : '');
    }

    return $lines;
}

/**
 * Checks a submitted password against the stored value and upgrades legacy
 * plaintext rows to bcrypt in place.
 *
 * The existing ContactRate app stores passwords as plaintext. Rather than force
 * every member of staff to pick a new password — which in practice produces
 * weaker passwords and leaves the plaintext column populated anyway — the first
 * successful login through Nova rehashes the value. `users` converts itself as
 * people sign in, and the old app keeps working throughout because
 * password_verify() is only used here.
 */
function verify_and_upgrade_password(PDO $pdo, array $user, string $submitted): bool
{
    $stored = (string)$user['password'];

    // Already migrated: bcrypt, argon2i, argon2id all start with $.
    if (str_starts_with($stored, '$')) {
        return password_verify($submitted, $stored);
    }

    // Legacy plaintext row.
    if (!hash_equals($stored, $submitted)) {
        return false;
    }

    $hash = password_hash($submitted, PASSWORD_BCRYPT);
    $stmt = $pdo->prepare('UPDATE users SET password = ? WHERE id = ?');
    $stmt->execute([$hash, $user['id']]);

    return true;
}
