<?php
/**
 * Minimal HS256 JWT. Shared hosting has no Composer, so this is hand-rolled —
 * deliberately small, and it only supports the one algorithm we issue.
 */

declare(strict_types=1);

function b64url_encode(string $bin): string
{
    return rtrim(strtr(base64_encode($bin), '+/', '-_'), '=');
}

function b64url_decode(string $s): string
{
    return base64_decode(strtr($s, '-_', '+/')) ?: '';
}

function jwt_issue(array $claims, string $secret, int $ttl): string
{
    $now = time();
    $payload = $claims + ['iat' => $now, 'exp' => $now + $ttl];

    $h = b64url_encode(json_encode(['alg' => 'HS256', 'typ' => 'JWT']));
    $p = b64url_encode(json_encode($payload, JSON_UNESCAPED_UNICODE));
    $sig = b64url_encode(hash_hmac('sha256', "$h.$p", $secret, true));

    return "$h.$p.$sig";
}

/**
 * Verifies signature and expiry. Returns the claims, or null if the token is
 * malformed, forged, or expired — callers must not distinguish between those.
 */
function jwt_verify(string $token, string $secret): ?array
{
    $parts = explode('.', $token);
    if (count($parts) !== 3) {
        return null;
    }
    [$h, $p, $sig] = $parts;

    $expected = b64url_encode(hash_hmac('sha256', "$h.$p", $secret, true));
    if (!hash_equals($expected, $sig)) {
        return null;
    }

    $header = json_decode(b64url_decode($h), true);
    if (!is_array($header) || ($header['alg'] ?? '') !== 'HS256') {
        return null;
    }

    $claims = json_decode(b64url_decode($p), true);
    if (!is_array($claims) || !isset($claims['exp']) || time() >= (int)$claims['exp']) {
        return null;
    }

    return $claims;
}

/**
 * Reads the token from the request, or null when absent.
 *
 * `Authorization` is the right header and is tried first. It does not survive
 * the production host: nginx sits in front of PHP-FPM there and drops it, while
 * passing every other header through — a probe sent both `Authorization` and
 * `X-Nova-Token` and PHP saw only the second. Left unhandled, that made login
 * succeed and every request after it 401, which the UI treats as a dead session
 * and logs the person out mid-sentence.
 *
 * So a fallback header carries the same token. It is not a lesser credential —
 * same JWT, same verification — just one the host does not eat.
 */
function jwt_from_request(): ?string
{
    $header = $_SERVER['HTTP_AUTHORIZATION'] ?? '';

    // Some Apache configurations strip Authorization from the CGI environment.
    if ($header === '' && function_exists('apache_request_headers')) {
        foreach (apache_request_headers() as $k => $v) {
            if (strcasecmp($k, 'Authorization') === 0) {
                $header = $v;
                break;
            }
        }
    }

    if (preg_match('/^Bearer\s+(\S+)$/i', $header, $m)) {
        return $m[1];
    }

    $fallback = trim((string)($_SERVER['HTTP_X_NOVA_TOKEN'] ?? ''));
    if ($fallback !== '') {
        // Sent bare, but tolerate a Bearer prefix rather than fail obscurely.
        return preg_match('/^Bearer\s+(\S+)$/i', $fallback, $m) ? $m[1] : $fallback;
    }

    return null;
}
