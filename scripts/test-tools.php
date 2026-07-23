<?php
/**
 * Exercises every tool against the real database and prints what the model
 * would receive. No Anthropic API key needed — this checks the half of the
 * system that has to be right before the model can be.
 *
 * Run:  php scripts/test-tools.php
 */

declare(strict_types=1);

require_once __DIR__ . '/../api/config.php';
require_once __DIR__ . '/../api/lib/tools.php';

$pdo = nova_db();

/** Prints a tool call and a compact view of its result. */
function probe(PDO $pdo, string $name, array $input): void
{
    echo "\n" . str_repeat('=', 72) . "\n";
    echo "$name(" . json_encode($input, JSON_UNESCAPED_UNICODE) . ")\n";
    echo str_repeat('-', 72) . "\n";

    $t = microtime(true);
    $out = nova_run_tool($pdo, $name, $input);
    $ms = round((microtime(true) - $t) * 1000);

    $json = json_encode($out, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    $approxTokens = (int)ceil(mb_strlen($json) / 3.5);

    // Show the head of the payload; the point is shape and grounding, not volume.
    $lines = explode("\n", $json);
    echo implode("\n", array_slice($lines, 0, 24));
    if (count($lines) > 24) {
        echo "\n  … (" . (count($lines) - 24) . " more lines)";
    }
    echo "\n\n  [{$ms}ms · ~{$approxTokens} tokens]\n";
}

// The driving example, split into the two halves the data answers differently.
probe($pdo, 'search_tours', ['destination' => 'Krabi', 'max_adult_price' => 1500, 'sort' => 'price_asc']);
probe($pdo, 'search_hotels', ['location' => 'Krabi']);

// The hotel half that does work.
probe($pdo, 'search_hotels', ['location' => 'Patong', 'only_with_rates' => true]);
probe($pdo, 'search_tours', ['query' => 'phi phi', 'sort' => 'price_asc']);
probe($pdo, 'search_suppliers', []);

// Grounding edges: a hotel with no rates, and a period with no coverage.
$stmt = $pdo->query(
    'SELECT id FROM hotels h
      WHERE NOT EXISTS (SELECT 1 FROM hotel_rates r WHERE r.hotel_id = h.id AND r.is_active = 1)
      LIMIT 1'
);
if ($emptyHotel = $stmt->fetchColumn()) {
    probe($pdo, 'get_hotel_rates', ['hotel_id' => (int)$emptyHotel]);
}

$stmt = $pdo->query(
    'SELECT hotel_id FROM hotel_rates WHERE is_active = 1 GROUP BY hotel_id LIMIT 1'
);
if ($ratedHotel = $stmt->fetchColumn()) {
    probe($pdo, 'get_hotel_rates', ['hotel_id' => (int)$ratedHotel, 'month' => 10]);
    probe($pdo, 'get_hotel_rates', ['hotel_id' => (int)$ratedHotel, 'month' => 2, 'year' => 2035]);
}

// Month filtering on tours. The count matters more than the rows: a third of
// the tours have no validity dates, and treating those as expired would quietly
// hide most of the catalogue from any question that names a month.
probe($pdo, 'search_tours', ['destination' => 'Krabi', 'valid_month' => 10]);

$dated = $pdo->query(
    'SELECT COUNT(*) FROM tours WHERE start_date IS NOT NULL OR end_date IS NOT NULL'
)->fetchColumn();
$undated = $pdo->query(
    'SELECT COUNT(*) FROM tours WHERE start_date IS NULL AND end_date IS NULL'
)->fetchColumn();
echo "\n  tours with dates: $dated · without: $undated (the second group must "
   . "survive every month filter)\n";

// Files. Contact rate sheets are the ones staff ask for by name.
$supplier = $pdo->query(
    "SELECT supplier_id FROM supplier_files WHERE file_category = 'contact_rate' LIMIT 1"
)->fetchColumn();
if ($supplier) {
    probe($pdo, 'get_supplier_files', ['supplier_id' => (int)$supplier]);
    probe($pdo, 'get_supplier_files', ['supplier_id' => (int)$supplier, 'category' => 'qr_code']);
}

$tourWithBrochure = $pdo->query(
    "SELECT tour_id FROM tour_files WHERE file_category LIKE 'brochure%' LIMIT 1"
)->fetchColumn();
if ($tourWithBrochure) {
    probe($pdo, 'get_tour_details', ['tour_id' => (int)$tourWithBrochure]);
}

// The stats that replaced the prompt's hardcoded counts.
require_once __DIR__ . '/../api/lib/stats.php';
echo "\n" . str_repeat('=', 72) . "\nLive data summary fed to the system prompt\n"
   . str_repeat('-', 72) . "\n";
echo nova_stats_prompt(nova_data_stats($pdo)) . "\n";

echo "\n" . str_repeat('=', 72) . "\nAll tools ran.\n";
