<?php
/**
 * Read-only query runner against the production contactrate database.
 *
 * Credentials arrive via environment (set by db-query.ps1) so they never appear
 * on a command line. Only read statements are accepted — anything that could
 * modify production is rejected before it reaches the server.
 *
 * Usage (through the wrapper):  ./scripts/db-query.ps1 "SELECT ..."
 */

$sql = $argv[1] ?? '';
if (trim($sql) === '') {
    fwrite(STDERR, "usage: db-query.ps1 \"<SELECT ...>\"\n");
    exit(2);
}

// Guard: allow a single read statement only.
$normalized = trim($sql);
$normalized = preg_replace('/^\s*--.*$/m', '', $normalized);   // strip line comments
$normalized = preg_replace('#/\*.*?\*/#s', '', $normalized);   // strip block comments
$normalized = trim($normalized);

if (!preg_match('/^(SELECT|SHOW|DESCRIBE|DESC|EXPLAIN)\b/i', $normalized)) {
    fwrite(STDERR, "REJECTED: only SELECT/SHOW/DESCRIBE/EXPLAIN are allowed.\n");
    exit(3);
}
// Reject stacked statements: a semicolon anywhere except as a single trailing one.
if (preg_match('/;\s*\S/', $normalized)) {
    fwrite(STDERR, "REJECTED: multiple statements are not allowed.\n");
    exit(3);
}

$dsn = sprintf(
    'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
    getenv('DBH'), getenv('DBP'), getenv('DBN')
);

try {
    $pdo = new PDO($dsn, getenv('DBU'), getenv('DBW'), [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_TIMEOUT => 15,
    ]);
    $pdo->exec("SET SESSION TRANSACTION READ ONLY");
} catch (Throwable $e) {
    fwrite(STDERR, "CONNECT FAILED: " . $e->getMessage() . "\n");
    exit(1);
}

try {
    $rows = $pdo->query($normalized)->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    fwrite(STDERR, "QUERY FAILED: " . $e->getMessage() . "\n");
    exit(1);
}

if (!$rows) {
    echo "(0 rows)\n";
    exit(0);
}

// Plain aligned table. Values wider than MAXCOL are truncated so one long text
// column cannot destroy the layout; raise it per-call with DBQ_MAXCOL.
$maxCol = (int)(getenv('DBQ_MAXCOL') ?: 40);
$cols = array_keys($rows[0]);
$w = [];
foreach ($cols as $c) {
    $w[$c] = min($maxCol, max(strlen($c), ...array_map(
        fn($r) => strlen((string)($r[$c] ?? 'NULL')), $rows
    )));
}
$line = fn($ch) => implode('-+-', array_map(fn($c) => str_repeat($ch, $w[$c]), $cols));

echo implode(' | ', array_map(fn($c) => str_pad($c, $w[$c]), $cols)) . "\n";
echo $line('-') . "\n";
foreach ($rows as $r) {
    echo implode(' | ', array_map(function ($c) use ($r, $w) {
        $v = $r[$c] ?? 'NULL';
        $v = (string)$v;
        if (strlen($v) > $w[$c]) $v = substr($v, 0, $w[$c] - 1) . '…';
        return str_pad($v, $w[$c]);
    }, $cols)) . "\n";
}
echo "\n(" . count($rows) . " rows)\n";
