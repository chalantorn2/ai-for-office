<?php
/**
 * Apply a migration file to the production contactrate database.
 *
 * This is the only path in this project that writes to production, so it is
 * deliberately narrow:
 *
 *   - every statement must be CREATE TABLE IF NOT EXISTS, CREATE INDEX,
 *     or an ALTER TABLE that only ADDs
 *   - every table named must start with `ai_` — the existing ContactRate tables
 *     cannot be touched through here at all
 *   - DROP, DELETE, TRUNCATE, UPDATE, INSERT, RENAME and GRANT are rejected
 *
 * Anything outside that shape belongs in a reviewed, hand-run change, not a script.
 *
 * Usage:  ./scripts/db-migrate.ps1 database/001_ai_tables.sql [-DryRun]
 */

$file = $argv[1] ?? '';
$dryRun = ($argv[2] ?? '') === '--dry-run';

if (!is_file($file)) {
    fwrite(STDERR, "migration file not found: $file\n");
    exit(2);
}

$sql = file_get_contents($file);
$sql = preg_replace('/^\s*--.*$/m', '', $sql);
$sql = preg_replace('#/\*.*?\*/#s', '', $sql);

$statements = array_values(array_filter(array_map('trim', explode(';', $sql)), 'strlen'));
if (!$statements) {
    fwrite(STDERR, "no statements found in $file\n");
    exit(2);
}

$FORBIDDEN = '/\b(DROP|DELETE|TRUNCATE|UPDATE|INSERT|REPLACE|RENAME|GRANT|REVOKE)\b/i';

// `ON DELETE CASCADE` and `ON UPDATE CURRENT_TIMESTAMP` are column and foreign-key
// clauses, not write statements. Strip them before scanning for real write verbs.
$REFERENTIAL = '/\bON\s+(?:DELETE|UPDATE)\s+(?:CASCADE|RESTRICT|SET\s+NULL|NO\s+ACTION|SET\s+DEFAULT|CURRENT_TIMESTAMP(?:\(\))?)/i';

foreach ($statements as $i => $stmt) {
    $n = $i + 1;

    if (preg_match($FORBIDDEN, preg_replace($REFERENTIAL, '', $stmt), $m)) {
        fwrite(STDERR, "REJECTED statement $n: contains {$m[1]}\n");
        exit(3);
    }
    if (!preg_match('/^(CREATE TABLE IF NOT EXISTS|CREATE INDEX|ALTER TABLE)\b/i', $stmt)) {
        fwrite(STDERR, "REJECTED statement $n: only CREATE TABLE IF NOT EXISTS / CREATE INDEX / ALTER TABLE allowed\n");
        exit(3);
    }
    // The table being created or altered must be one of ours.
    if (!preg_match('/^(?:CREATE TABLE IF NOT EXISTS|CREATE INDEX \S+ ON|ALTER TABLE)\s+`?(\w+)`?/i', $stmt, $m)
        || stripos($m[1], 'ai_') !== 0) {
        fwrite(STDERR, "REJECTED statement $n: target table must be prefixed ai_\n");
        exit(3);
    }
    if (preg_match('/^ALTER TABLE/i', $stmt) && !preg_match('/\bADD\b/i', $stmt)) {
        fwrite(STDERR, "REJECTED statement $n: ALTER TABLE may only ADD\n");
        exit(3);
    }
}

echo "Validated " . count($statements) . " statement(s) in $file\n\n";
foreach ($statements as $i => $stmt) {
    $head = preg_split('/\r?\n/', $stmt)[0];
    echo "  " . ($i + 1) . ". " . trim($head) . " …\n";
}
echo "\n";

if ($dryRun) {
    echo "DRY RUN — nothing was sent to the server.\n";
    exit(0);
}

$dsn = sprintf(
    'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
    getenv('DBH'), getenv('DBP'), getenv('DBN')
);

try {
    $pdo = new PDO($dsn, getenv('DBU'), getenv('DBW'), [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_TIMEOUT => 20,
    ]);
} catch (Throwable $e) {
    fwrite(STDERR, "CONNECT FAILED: " . $e->getMessage() . "\n");
    exit(1);
}

foreach ($statements as $i => $stmt) {
    $n = $i + 1;
    try {
        $pdo->exec($stmt);
        echo "  OK   statement $n\n";
    } catch (Throwable $e) {
        fwrite(STDERR, "  FAIL statement $n: " . $e->getMessage() . "\n");
        exit(1);
    }
}

echo "\nMigration applied.\n";
