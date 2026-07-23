<?php
/**
 * Checks the hand-rolled JWT and the password-upgrade rule. No database, no network.
 *
 * Run:  php scripts/test-jwt.php
 */

declare(strict_types=1);

require_once __DIR__ . '/../api/lib/jwt.php';

$pass = 0;
$fail = 0;

function check(string $name, bool $ok): void
{
    global $pass, $fail;
    if ($ok) {
        $pass++;
        echo "  PASS  $name\n";
    } else {
        $fail++;
        echo "  FAIL  $name\n";
    }
}

$secret = 'test-secret-not-used-anywhere';
$other = 'a-different-secret';

$token = jwt_issue(['sub' => 7, 'username' => 'somchai', 'role' => 'user'], $secret, 3600);
$claims = jwt_verify($token, $secret);

check('valid token verifies', $claims !== null);
check('sub survives round-trip', ($claims['sub'] ?? null) === 7);
check('username survives round-trip', ($claims['username'] ?? null) === 'somchai');
check('exp is set', isset($claims['exp']));

check('wrong secret rejected', jwt_verify($token, $other) === null);
check('garbage rejected', jwt_verify('not.a.token', $secret) === null);
check('empty rejected', jwt_verify('', $secret) === null);
check('truncated rejected', jwt_verify(substr($token, 0, -4), $secret) === null);

// Payload tampering must fail even though the attacker controls the payload segment.
[$h, $p, $s] = explode('.', $token);
$forgedPayload = b64url_encode(json_encode(['sub' => 1, 'username' => 'admin', 'exp' => time() + 3600]));
check('tampered payload rejected', jwt_verify("$h.$forgedPayload.$s", $secret) === null);

// The alg:none downgrade attack.
$noneHeader = b64url_encode(json_encode(['alg' => 'none', 'typ' => 'JWT']));
check('alg:none rejected', jwt_verify("$noneHeader.$p.", $secret) === null);

// Expiry.
$expired = jwt_issue(['sub' => 1, 'username' => 'x'], $secret, -10);
check('expired token rejected', jwt_verify($expired, $secret) === null);

// Password upgrade rule: a stored value starting with $ is treated as a hash,
// anything else as legacy plaintext.
$hash = password_hash('correct horse', PASSWORD_BCRYPT);
check('bcrypt stored value detected', str_starts_with($hash, '$'));
check('bcrypt verifies', password_verify('correct horse', $hash));
check('bcrypt rejects wrong password', !password_verify('wrong', $hash));
check('plaintext not mistaken for hash', !str_starts_with('mypassword123', '$'));

echo "\n$pass passed, $fail failed\n";
exit($fail === 0 ? 0 : 1);
