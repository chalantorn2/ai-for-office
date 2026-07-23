<?php
/**
 * Push database/profiles.json into `ai_user_profiles`.
 *
 * The second path in this project that writes to production, and narrow for the
 * same reason db-migrate.php is: it touches exactly one table, one column, and
 * never deletes. Usernames are resolved to ids by lookup, so a typo is an error
 * before anything is written rather than a row nobody notices is missing.
 *
 * Removing someone is deliberately not automated — drop their key from the JSON
 * and Nova falls back to nickname/office/position, which is the right behaviour
 * for a person who is simply undescribed. Deleting the row itself is a hand-run
 * statement.
 *
 * Usage:  ./scripts/sync-profiles.ps1 [-DryRun]
 */

declare(strict_types=1);

$file = __DIR__ . '/../database/profiles.json';
$dryRun = ($argv[1] ?? '') === '--dry-run';

$raw = @file_get_contents($file);
if ($raw === false) {
    fwrite(STDERR, "profiles file not found: $file\n");
    exit(2);
}

$profiles = json_decode($raw, true);
if (!is_array($profiles)) {
    fwrite(STDERR, "profiles.json is not valid JSON: " . json_last_error_msg() . "\n");
    exit(2);
}

// Keys beginning with `_` are notes to whoever edits the file, not people.
$profiles = array_filter(
    $profiles,
    static fn($k) => $k[0] !== '_',
    ARRAY_FILTER_USE_KEY
);

foreach ($profiles as $username => $about) {
    if (!is_string($about) || trim($about) === '') {
        fwrite(STDERR, "REJECTED $username: `about` must be a non-empty string\n");
        exit(3);
    }
}

if (!$profiles) {
    fwrite(STDERR, "no profiles found in $file\n");
    exit(2);
}

$dsn = sprintf(
    'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
    getenv('DBH'), getenv('DBP'), getenv('DBN')
);

try {
    $pdo = new PDO($dsn, getenv('DBU'), getenv('DBW'), [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_TIMEOUT            => 20,
    ]);
} catch (Throwable $e) {
    fwrite(STDERR, "CONNECT FAILED: " . $e->getMessage() . "\n");
    exit(1);
}

// Resolve every username first. A profile written for an account that does not
// exist is a mistake in the file, and finding out after a partial write is worse
// than finding out now.
$lookup = $pdo->prepare('SELECT id FROM users WHERE username = ? LIMIT 1');
$targets = [];
$missing = [];

foreach ($profiles as $username => $about) {
    $lookup->execute([$username]);
    $row = $lookup->fetch();
    if (!$row) {
        $missing[] = $username;
        continue;
    }
    $targets[(int)$row['id']] = ['username' => $username, 'about' => trim($about)];
}

if ($missing) {
    fwrite(STDERR, "REJECTED: no such user(s) in `users`: " . implode(', ', $missing) . "\n");
    exit(3);
}

// Show what will change before changing it.
$existing = [];
foreach ($pdo->query('SELECT user_id, about FROM ai_user_profiles') as $row) {
    $existing[(int)$row['user_id']] = (string)$row['about'];
}

$new = $changed = $same = 0;
foreach ($targets as $id => $t) {
    $state = !isset($existing[$id]) ? 'NEW    '
        : ($existing[$id] !== $t['about'] ? 'UPDATE ' : 'same   ');
    if ($state === 'NEW    ') { $new++; } elseif ($state === 'UPDATE ') { $changed++; } else { $same++; }
    printf("  %s %-16s %s…\n", $state, $t['username'], mb_substr($t['about'], 0, 45));
}
printf("\n%d new, %d updated, %d unchanged\n\n", $new, $changed, $same);

$orphans = array_diff(array_keys($existing), array_keys($targets));
if ($orphans) {
    echo "Note: " . count($orphans) . " row(s) in ai_user_profiles have no entry in "
       . "profiles.json (user_id " . implode(', ', $orphans) . "). They are left "
       . "untouched — remove them by hand if that is what you want.\n\n";
}

if ($dryRun) {
    echo "DRY RUN — nothing was sent to the server.\n";
    exit(0);
}

if ($new === 0 && $changed === 0) {
    echo "Nothing to do.\n";
    exit(0);
}

$upsert = $pdo->prepare(
    'INSERT INTO ai_user_profiles (user_id, about) VALUES (?, ?)
     ON DUPLICATE KEY UPDATE about = VALUES(about)'
);

$pdo->beginTransaction();
try {
    foreach ($targets as $id => $t) {
        $upsert->execute([$id, $t['about']]);
    }
    $pdo->commit();
} catch (Throwable $e) {
    $pdo->rollBack();
    fwrite(STDERR, "FAILED: " . $e->getMessage() . "\n");
    exit(1);
}

echo "Synced " . count($targets) . " profile(s).\n";
