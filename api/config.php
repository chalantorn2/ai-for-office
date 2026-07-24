<?php
/**
 * Shared bootstrap for every endpoint: config, database handle, JSON helpers.
 *
 * Secrets live in config.local.php, which is gitignored. Nothing sensitive
 * belongs in this file — the main app hardcoded its database password here and
 * that file is tracked.
 */

declare(strict_types=1);

date_default_timezone_set('Asia/Bangkok');

const NOVA_TOKEN_TTL = 60 * 60 * 12; // 12 hours — one working day

$localConfig = __DIR__ . '/config.local.php';
if (!is_file($localConfig)) {
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(
        ['error' => 'server_misconfigured', 'message' => 'api/config.local.php is missing'],
        JSON_UNESCAPED_UNICODE
    );
    exit;
}

/** @var array{db:array{host:string,port:int,name:string,user:string,pass:string},jwt_secret:string,anthropic_key:string,allowed_origins:string[]} $CONFIG */
$CONFIG = require $localConfig;

/**
 * CORS. Unlike the main app this does not use `*` — an assistant endpoint that
 * answers questions about net cost rates must not be callable from any origin.
 */
function nova_cors(array $allowed): void
{
    $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
    if ($origin !== '' && in_array($origin, $allowed, true)) {
        header("Access-Control-Allow-Origin: $origin");
        header('Vary: Origin');
        header('Access-Control-Allow-Credentials: true');
    }
    header('Access-Control-Allow-Methods: GET, POST, PATCH, DELETE, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Nova-Token');

    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
        http_response_code(204);
        exit;
    }
}

function nova_db(): PDO
{
    static $pdo = null;
    global $CONFIG;

    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $db = $CONFIG['db'];
    $dsn = sprintf(
        'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
        $db['host'], $db['port'], $db['name']
    );

    try {
        $pdo = new PDO($dsn, $db['user'], $db['pass'], [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
    } catch (PDOException $e) {
        error_log('nova: db connect failed: ' . $e->getMessage());
        json_error(500, 'database_unavailable');
    }

    return $pdo;
}

function json_out(mixed $data, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function json_error(int $status, string $code, string $message = ''): never
{
    json_out(array_filter(['error' => $code, 'message' => $message]), $status);
}

/** Decodes the JSON request body, or fails with 400. */
function json_body(): array
{
    $raw = file_get_contents('php://input') ?: '';

    // PHP discards the body of a request over post_max_size and carries on, so
    // the endpoint sees an empty payload rather than an error — a question with
    // screenshots attached would come back as "you didn't type anything". The
    // announced length is still in the headers, which is what gives it away.
    if ($raw === '' && (int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
        json_error(
            413,
            'request_too_large',
            'รูปที่แนบรวมกันใหญ่เกินกว่าที่เซิร์ฟเวอร์รับได้ — ลองแนบทีละรูป'
        );
    }

    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}

nova_cors($CONFIG['allowed_origins']);
