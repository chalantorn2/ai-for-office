<?php
/**
 * The tools Nova can call, and the code that runs them.
 *
 * Design rules, all of which follow from what the data actually looks like
 * (verified against production 2026-07-22 — see PROJECT_NOTES.md):
 *
 *  - Tours are the main body of work: 280 rows against 30 hotels. `search_tours`
 *    is the tool most questions will reach for.
 *  - `tours.destination` is clean (7 values); `hotels.destination` is free text
 *    with 19 variants, so hotel search has to match loosely.
 *  - Responses stay narrow. Returning every column for 30 hotels costs ~10k
 *    tokens to answer a price question; each tool selects only what its
 *    question needs, and the model calls a detail tool when it needs more.
 *  - Absent is not the same as empty. When a filter matches nothing, the tool
 *    says so in a way the model can repeat to the user rather than guessing.
 *  - No tool states a count it has not just measured. Descriptions and notes
 *    used to carry figures ("12 of 30 hotels have no rates", "the system holds
 *    37 suppliers") that were correct on the day they were typed and checked by
 *    nothing afterwards. Live counts come from nova_data_stats().
 *
 * Every query here is read-only and fully parameterised.
 */

declare(strict_types=1);

require_once __DIR__ . '/stats.php';

const NOVA_MAX_ROWS = 40;

/**
 * The main ContactRate app. Every record Nova mentions exists on a page there,
 * and staff read a price in Nova then go to that page to act on it — so each
 * tool hands back the link and the model puts it behind the record's name.
 */
const NOVA_APP_URL = 'https://contactrate.sevensmiletourandticket.com';

function nova_tour_link(int $id): string
{
    return NOVA_APP_URL . '/edit/' . $id;
}

function nova_hotel_link(string $slug): string
{
    return NOVA_APP_URL . '/hotel/view/' . rawurlencode($slug);
}

function nova_supplier_link(int $id): string
{
    return NOVA_APP_URL . '/suppliers/' . $id;
}

/** Uploads are served from the main app's docroot, not from Nova's. */
function nova_file_link(string $path): string
{
    return NOVA_APP_URL . '/' . ltrim($path, '/');
}

/**
 * Pinned to the newest version so the tool's own defaults and fields are the
 * current ones — but note that both features _20260318 adds over basic search
 * are deliberately switched off below. See `allowed_callers`.
 */
const NOVA_WEB_SEARCH_TYPE = 'web_search_20260318';

/**
 * A factual lookup takes 1–3 searches; a wide comparison can take ten or more.
 * Capping at 3 keeps a vague question from turning into a $0.10 turn. Going
 * over is reported to the model as a tool error and is not billed.
 */
const NOVA_WEB_SEARCH_MAX_USES = 3;

/**
 * Server-side tools: Anthropic runs these, so they never reach nova_run_tool.
 * Kept separate from the client tools for exactly that reason — the list below
 * is not something this file knows how to execute.
 */
function nova_server_tool_definitions(): array
{
    return [
        [
            'type'     => NOVA_WEB_SEARCH_TYPE,
            'name'     => 'web_search',
            'max_uses' => NOVA_WEB_SEARCH_MAX_USES,

            // Search is called directly, NOT through code execution.
            //
            // From _20260209 onward this field defaults to code execution, which
            // turns on dynamic filtering: Anthropic writes code that filters the
            // results before they reach the context window. That is a real saving
            // when a turn pulls in a wall of search results — and a loss here,
            // measured on production data 2026-07-23:
            //
            //   park-fee lookup, 1 search   filtered 3.45 THB   direct 1.45 THB
            //   average tour price, 0 searches   filtered 10.59 THB   direct 3.74 THB
            //
            // Two reasons. Nova's searches are single factual lookups, so there is
            // little to filter, and the filtering code itself costs output tokens
            // plus a cache write. Worse, opting in provisions a code execution
            // sandbox for the whole turn, and the model then reaches for it on
            // questions that never involved the web at all — the second row above
            // is arithmetic over our own tour prices.
            //
            // Revisit if Nova ever needs broad multi-source research. For lookups,
            // direct wins on both paths.
            'allowed_callers' => ['direct'],

            // `response_inclusion` is not set: it only governs results consumed by
            // a code execution call, and direct results always come back in full.
            // No `user_location` either — Anthropic rejects country code TH with a
            // 400, so localisation rides the query text. The system prompt already
            // tells the model where the office is.
        ],
    ];
}

/**
 * Tool schemas sent to the model. Descriptions carry the trigger condition —
 * when to call — not just what the tool does.
 */
function nova_tool_definitions(): array
{
    return [
        [
            'name' => 'search_tours',
            'description' =>
                'ค้นหาทัวร์ในระบบ ContactRate. เรียกใช้เมื่อผู้ใช้ถามถึงทัวร์ ' .
                'ราคาทัวร์ โปรแกรมทัวร์ หรือทัวร์ในจังหวัดใดจังหวัดหนึ่ง. ' .
                'Search tours. Call this whenever the user asks about tours, tour prices, ' .
                'or what tours exist in a province. Returns id, name, destination, ' .
                'type, adult/child price, and validity dates.',
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'destination' => [
                        'type' => 'string',
                        'description' =>
                            'Province filter. Exact values in the data: Phuket, Krabi, ' .
                            'Pattaya, Samui, Phang Nga, Bangkok. Omit to search all.',
                    ],
                    'query' => [
                        'type' => 'string',
                        'description' =>
                            'Free-text match against the tour name, e.g. "phi phi", ' .
                            '"เกาะพีพี", "james bond". Omit to list all in the destination.',
                    ],
                    'tour_type' => [
                        'type' => 'string',
                        'description' => 'Tour type filter, e.g. "Join", "Private".',
                    ],
                    'max_adult_price' => [
                        'type' => 'number',
                        'description' => 'Only tours with adult price at or below this (THB).',
                    ],
                    'min_adult_price' => [
                        'type' => 'number',
                        'description' => 'Only tours with adult price at or above this (THB).',
                    ],
                    'valid_month' => [
                        'type' => 'integer',
                        'description' =>
                            'Month 1-12. Returns tours whose validity period covers any part '
                            . 'of that month. Tours with no validity dates recorded are always '
                            . 'included — a blank period means year-round, not expired.',
                    ],
                    'valid_year' => [
                        'type' => 'integer',
                        'description' => 'Year for valid_month. Defaults to the current year.',
                    ],
                    'sort' => [
                        'type' => 'string',
                        'enum' => ['price_asc', 'price_desc', 'name'],
                        'description' => 'Result ordering. Defaults to name.',
                    ],
                ],
            ],
        ],

        [
            'name' => 'get_tour_details',
            'description' =>
                'ดูรายละเอียดทัวร์รายการเดียว รวมหมายเหตุ ค่าอุทยาน ท่าเรือ และซัพพลายเออร์. ' .
                'Call after search_tours when the user asks for detail on one specific tour — ' .
                'notes, park fees, pier, departure point, or which supplier provides it.',
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'tour_id' => ['type' => 'integer', 'description' => 'The tour id from search_tours.'],
                ],
                'required' => ['tour_id'],
            ],
        ],

        [
            'name' => 'search_hotels',
            'description' =>
                'ค้นหาโรงแรมในระบบ. เรียกใช้เมื่อผู้ใช้ถามถึงโรงแรม ที่พัก หรือรีสอร์ท. ' .
                'Search hotels. Note that hotel `destination` is free text ' .
                '("Patong Beach, Phuket", "Phuket", "Karon Beach, Phuket"), so a location ' .
                'filter matches loosely. Returns id, name, destination, stars, and how many ' .
                'rates each hotel has loaded — some have none, which is missing data rather ' .
                'than a price of zero.',
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'query' => [
                        'type' => 'string',
                        'description' => 'Free-text match against hotel name.',
                    ],
                    'location' => [
                        'type' => 'string',
                        'description' =>
                            'Loose location match, e.g. "Phuket", "Patong", "Bangkok". ' .
                            'Matched against the destination text.',
                    ],
                    'stars' => ['type' => 'integer', 'description' => 'Exact star rating.'],
                    'only_with_rates' => [
                        'type' => 'boolean',
                        'description' => 'True to return only hotels that have rates loaded.',
                    ],
                ],
            ],
        ],

        [
            'name' => 'get_hotel_rates',
            'description' =>
                'ดูราคาห้องพักของโรงแรม กรองตามเดือนหรือช่วงวันที่ได้. ' .
                'Get room rates for one hotel. Call when the user asks for a price, ' .
                'a rate for a month, or a rate table. period_start/period_end are real ' .
                'dates, so month filtering is exact.',
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'hotel_id' => ['type' => 'integer', 'description' => 'Hotel id from search_hotels.'],
                    'month' => [
                        'type' => 'integer',
                        'description' => 'Month 1-12. Returns rates whose period covers any part of that month.',
                    ],
                    'year' => [
                        'type' => 'integer',
                        'description' => 'Year for the month filter. Defaults to the current year.',
                    ],
                    'room_type' => ['type' => 'string', 'description' => 'Free-text room type match.'],
                ],
                'required' => ['hotel_id'],
            ],
        ],

        [
            'name' => 'get_hotel_details',
            'description' =>
                'ดูรายละเอียดโรงแรม สิ่งอำนวยความสะดวก นโยบายเด็ก เงื่อนไขราคา. ' .
                'Call when the user asks about a hotel beyond its price — amenities, ' .
                'child policy, room types, rate terms, or current notices.',
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'hotel_id' => ['type' => 'integer', 'description' => 'Hotel id from search_hotels.'],
                ],
                'required' => ['hotel_id'],
            ],
        ],

        [
            'name' => 'search_suppliers',
            'description' =>
                'ค้นหาซัพพลายเออร์ และดูว่าแต่ละรายมีทัวร์กี่รายการ. ' .
                'Search suppliers. Call when the user asks who supplies something, ' .
                'for supplier contact details, or to compare suppliers.',
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'query' => ['type' => 'string', 'description' => 'Free-text match against supplier name.'],
                ],
            ],
        ],

        [
            'name' => 'get_supplier_files',
            'description' =>
                'ดูไฟล์ของซัพพลายเออร์ โดยเฉพาะใบ contact rate และ QR code. ' .
                'Get a supplier\'s uploaded files. Call when the user asks for a supplier\'s ' .
                'contact rate sheet, price list document, QR code, or "the file/PDF from X". ' .
                'Returns a download link per file — these are the original rate sheets, which ' .
                'often carry terms and conditions that are not in the tour records.',
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'supplier_id' => [
                        'type' => 'integer',
                        'description' => 'Supplier id from search_suppliers.',
                    ],
                    'category' => [
                        'type' => 'string',
                        'enum' => ['contact_rate', 'qr_code', 'general'],
                        'description' => 'Filter by file kind. Omit to return all files.',
                    ],
                ],
                'required' => ['supplier_id'],
            ],
        ],
    ];
}

/**
 * Runs a tool call and returns a compact array for the model.
 * Never throws — a failure is reported as data so the conversation continues.
 *
 * Only client tools arrive here. Web search is a server tool: the model emits a
 * `server_tool_use` block, not `tool_use`, and Anthropic has already run it by
 * the time the response reaches us — so there is nothing to dispatch.
 */
function nova_run_tool(PDO $pdo, string $name, array $input): array
{
    try {
        return match ($name) {
            'search_tours'       => nova_search_tours($pdo, $input),
            'get_tour_details'   => nova_tour_details($pdo, $input),
            'search_hotels'      => nova_search_hotels($pdo, $input),
            'get_hotel_rates'    => nova_hotel_rates($pdo, $input),
            'get_hotel_details'  => nova_hotel_details($pdo, $input),
            'search_suppliers'   => nova_search_suppliers($pdo, $input),
            'get_supplier_files' => nova_supplier_files($pdo, $input),
            default              => ['error' => "unknown tool: $name"],
        };
    } catch (Throwable $e) {
        error_log("nova: tool $name failed: " . $e->getMessage());
        return ['error' => 'tool_failed', 'detail' => $e->getMessage()];
    }
}

function nova_search_tours(PDO $pdo, array $in): array
{
    $where = ['1=1'];
    $args = [];

    if (!empty($in['destination'])) {
        $where[] = 't.destination = ?';
        $args[] = $in['destination'];
    }
    if (!empty($in['query'])) {
        $where[] = 't.tour_name LIKE ?';
        $args[] = '%' . $in['query'] . '%';
    }
    if (!empty($in['tour_type'])) {
        $where[] = 't.tour_type LIKE ?';
        $args[] = '%' . $in['tour_type'] . '%';
    }
    if (isset($in['max_adult_price'])) {
        $where[] = 't.adult_price <= ?';
        $args[] = (float)$in['max_adult_price'];
    }
    if (isset($in['min_adult_price'])) {
        $where[] = 't.adult_price >= ?';
        $args[] = (float)$in['min_adult_price'];
    }

    // Month filter as a period-overlap test. A third of the tours carry no
    // validity dates at all, and a plain BETWEEN would drop every one of them —
    // silently hiding most of the catalogue from any question that mentions a
    // month. A blank period means year-round here, so NULL passes.
    if (!empty($in['valid_month'])) {
        $year = (int)($in['valid_year'] ?? date('Y'));
        $month = max(1, min(12, (int)$in['valid_month']));
        $from = sprintf('%04d-%02d-01', $year, $month);
        $to = date('Y-m-t', strtotime($from));

        $where[] = '(t.start_date IS NULL OR t.start_date <= ?)
                AND (t.end_date   IS NULL OR t.end_date   >= ?)';
        $args[] = $to;
        $args[] = $from;
    }

    $order = match ($in['sort'] ?? 'name') {
        'price_asc'  => 't.adult_price ASC',
        'price_desc' => 't.adult_price DESC',
        default      => 't.tour_name ASC',
    };

    $sql = 'SELECT t.id, t.tour_name, t.destination, t.tour_type,
                   t.adult_price, t.child_price, t.start_date, t.end_date,
                   s.name AS supplier
              FROM tours t
              LEFT JOIN suppliers s ON s.id = t.supplier_id
             WHERE ' . implode(' AND ', $where) . '
             ORDER BY ' . $order . '
             LIMIT ' . (NOVA_MAX_ROWS + 1);

    $stmt = $pdo->prepare($sql);
    $stmt->execute($args);
    $rows = $stmt->fetchAll();

    $truncated = count($rows) > NOVA_MAX_ROWS;
    if ($truncated) {
        array_pop($rows);
    }

    if (!$rows) {
        $stats = nova_data_stats($pdo);
        $known = implode(', ', array_keys($stats['tours']['destinations']));

        return [
            'tours' => [],
            'note'  => 'No tours match these filters. This means the filters returned nothing — '
                     . 'not that the data is missing. Say so plainly; do not invent tours. '
                     . "Tours exist for these destinations: $known.",
        ];
    }

    foreach ($rows as &$row) {
        $row['link'] = nova_tour_link((int)$row['id']);
    }
    unset($row);

    return array_filter([
        'count'     => count($rows),
        'truncated' => $truncated ?: null,
        'tours'     => $rows,
    ], fn($v) => $v !== null);
}

function nova_tour_details(PDO $pdo, array $in): array
{
    $stmt = $pdo->prepare(
        'SELECT t.*, s.name AS supplier_name, s.phone AS supplier_phone,
                s.email AS supplier_email
           FROM tours t
           LEFT JOIN suppliers s ON s.id = t.supplier_id
          WHERE t.id = ?
          LIMIT 1'
    );
    $stmt->execute([(int)$in['tour_id']]);
    $tour = $stmt->fetch();

    if (!$tour) {
        return ['error' => 'not_found', 'note' => 'No tour with that id exists.'];
    }

    unset($tour['created_at'], $tour['updated_at'], $tour['updated_by']);
    $tour['link'] = nova_tour_link((int)$tour['id']);

    // Brochures are the part staff actually ask for; the gallery is decoration
    // and would only cost tokens. Counted here rather than listed, with the
    // brochure links attached, so a tour with 40 photos does not flood the turn.
    $stmt = $pdo->prepare(
        "SELECT file_category, file_path, original_name
           FROM tour_files
          WHERE tour_id = ?
          ORDER BY file_category, id
          LIMIT 60"
    );
    $stmt->execute([(int)$in['tour_id']]);

    $brochures = [];
    $gallery = 0;
    foreach ($stmt->fetchAll() as $file) {
        if (str_starts_with((string)$file['file_category'], 'brochure')) {
            $brochures[] = [
                'name' => $file['original_name'],
                'link' => nova_file_link((string)$file['file_path']),
            ];
        } else {
            $gallery++;
        }
    }

    return array_filter([
        'tour'          => $tour,
        'brochures'     => $brochures ?: null,
        'gallery_images' => $gallery ?: null,
    ], fn($v) => $v !== null);
}

function nova_search_hotels(PDO $pdo, array $in): array
{
    // Inactive hotels are withdrawn, not merely unlisted — surfacing one would
    // put a rate in front of staff that the office no longer sells.
    $where = ['h.is_active = 1'];
    $args = [];

    if (!empty($in['query'])) {
        $where[] = 'h.name LIKE ?';
        $args[] = '%' . $in['query'] . '%';
    }
    // destination is free text ("Patong Beach, Phuket" vs "Phuket"), so match loosely.
    if (!empty($in['location'])) {
        $where[] = 'h.destination LIKE ?';
        $args[] = '%' . $in['location'] . '%';
    }
    if (!empty($in['stars'])) {
        $where[] = 'h.stars = ?';
        $args[] = (int)$in['stars'];
    }

    $having = !empty($in['only_with_rates']) ? 'HAVING rate_count > 0' : '';

    $sql = 'SELECT h.id, h.name, h.slug, h.destination, h.stars,
                   (SELECT COUNT(*) FROM hotel_rates r
                     WHERE r.hotel_id = h.id AND r.is_active = 1) AS rate_count
              FROM hotels h
             WHERE ' . implode(' AND ', $where) . "
             $having
             ORDER BY h.name ASC
             LIMIT " . (NOVA_MAX_ROWS + 1);

    $stmt = $pdo->prepare($sql);
    $stmt->execute($args);
    $rows = $stmt->fetchAll();

    $truncated = count($rows) > NOVA_MAX_ROWS;
    if ($truncated) {
        array_pop($rows);
    }

    if (!$rows) {
        $stats = nova_data_stats($pdo);
        $places = [];
        foreach ($stats['hotels']['provinces'] as $name => $count) {
            $places[] = "$name ($count)";
        }
        // Sub-locality names too: a note that listed only provinces would have
        // the model deny we have Pattaya hotels because the column says Chonburi.
        $places = array_merge($places, array_keys($stats['hotels']['areas']));

        return [
            'hotels' => [],
            'note'   => 'No hotels match. The system currently holds '
                      . $stats['hotels']['total'] . ' hotels, and the only places '
                      . 'recorded against them are: ' . implode(', ', $places)
                      . '. If the user asked about somewhere not on that list, say '
                      . 'plainly that the system has no hotels there; otherwise say '
                      . 'the filter matched nothing. Do not invent hotels either way.',
        ];
    }

    foreach ($rows as &$row) {
        $row['link'] = nova_hotel_link((string)$row['slug']);
        unset($row['slug']);
    }
    unset($row);

    $withoutRates = array_filter($rows, fn($h) => (int)$h['rate_count'] === 0);

    return array_filter([
        'count'          => count($rows),
        'truncated'      => $truncated ?: null,
        'no_rates_count' => $withoutRates ? count($withoutRates) : null,
        'no_rates_note'  => $withoutRates
            ? 'Some hotels here have no rates loaded in the system. That is missing '
            . 'data, not a price of zero — tell the user rather than omitting them silently.'
            : null,
        'hotels'         => $rows,
    ], fn($v) => $v !== null);
}

function nova_hotel_rates(PDO $pdo, array $in): array
{
    $hotelId = (int)$in['hotel_id'];

    $stmt = $pdo->prepare(
        'SELECT name, slug, destination FROM hotels WHERE id = ? AND is_active = 1 LIMIT 1'
    );
    $stmt->execute([$hotelId]);
    $hotel = $stmt->fetch();

    if (!$hotel) {
        return ['error' => 'not_found', 'note' => 'No active hotel with that id exists.'];
    }

    $where = ['r.hotel_id = ?', 'r.is_active = 1'];
    $args = [$hotelId];

    // period_start/period_end are real DATEs, so a month filter is an overlap test.
    if (!empty($in['month'])) {
        $year = (int)($in['year'] ?? date('Y'));
        $month = (int)$in['month'];
        $from = sprintf('%04d-%02d-01', $year, $month);
        $to = date('Y-m-t', strtotime($from));

        $where[] = 'r.period_start <= ? AND r.period_end >= ?';
        $args[] = $to;
        $args[] = $from;
    }
    if (!empty($in['room_type'])) {
        $where[] = 'r.room_type LIKE ?';
        $args[] = '%' . $in['room_type'] . '%';
    }

    $sql = 'SELECT r.room_type, r.period_label, r.period_start, r.period_end,
                   r.meal_plan, r.price, r.currency
              FROM hotel_rates r
             WHERE ' . implode(' AND ', $where) . '
             ORDER BY r.room_type, r.period_start
             LIMIT 200';

    $stmt = $pdo->prepare($sql);
    $stmt->execute($args);
    $rates = $stmt->fetchAll();

    if (!$rates) {
        // Distinguish "this hotel has no rates at all" from "no rates in that period".
        $stmt = $pdo->prepare(
            'SELECT COUNT(*) FROM hotel_rates WHERE hotel_id = ? AND is_active = 1'
        );
        $stmt->execute([$hotelId]);
        $total = (int)$stmt->fetchColumn();

        return [
            'hotel' => $hotel['name'],
            'link'  => nova_hotel_link((string)$hotel['slug']),
            'rates' => [],
            'note'  => $total === 0
                ? 'This hotel has no rates loaded in the system at all. Tell the user the '
                . 'rates are missing — do not estimate a price.'
                : "This hotel has $total active rates, but none in the requested period. "
                . 'Say the period is not covered, and offer the periods that are.',
        ];
    }

    return [
        'hotel'  => $hotel['name'],
        'link'   => nova_hotel_link((string)$hotel['slug']),
        'source' => 'net cost rates from ContactRate — internal figures',
        'rates'  => $rates,
    ];
}

function nova_hotel_details(PDO $pdo, array $in): array
{
    $hotelId = (int)$in['hotel_id'];

    $stmt = $pdo->prepare('SELECT * FROM hotels WHERE id = ? AND is_active = 1 LIMIT 1');
    $stmt->execute([$hotelId]);
    $hotel = $stmt->fetch();

    if (!$hotel) {
        return ['error' => 'not_found', 'note' => 'No active hotel with that id exists.'];
    }

    // Sync bookkeeping and the image blobs answer no question staff can ask and
    // cost thousands of tokens on a details call.
    unset(
        $hotel['created_at'], $hotel['updated_at'], $hotel['source_id'],
        $hotel['source_created_at'], $hotel['source_updated_at'], $hotel['synced_at'],
        $hotel['images'], $hotel['main_image'], $hotel['is_active'], $hotel['is_featured']
    );
    $hotel['link'] = nova_hotel_link((string)$hotel['slug']);
    unset($hotel['slug']);

    $stmt = $pdo->prepare(
        'SELECT * FROM hotel_notices WHERE hotel_id = ? ORDER BY id DESC LIMIT 5'
    );
    $stmt->execute([$hotelId]);

    return array_filter([
        'hotel'    => $hotel,
        'notices'  => $stmt->fetchAll() ?: null,
    ], fn($v) => $v !== null);
}

function nova_search_suppliers(PDO $pdo, array $in): array
{
    $where = ['1=1'];
    $args = [];

    if (!empty($in['query'])) {
        $where[] = 's.name LIKE ?';
        $args[] = '%' . $in['query'] . '%';
    }

    $sql = 'SELECT s.id, s.name, s.phone, s.email,
                   (SELECT COUNT(*) FROM tours t WHERE t.supplier_id = s.id) AS tour_count
              FROM suppliers s
             WHERE ' . implode(' AND ', $where) . '
             ORDER BY tour_count DESC, s.name ASC
             LIMIT ' . NOVA_MAX_ROWS;

    $stmt = $pdo->prepare($sql);
    $stmt->execute($args);
    $rows = $stmt->fetchAll();

    if (!$rows) {
        $stats = nova_data_stats($pdo);
        return [
            'suppliers' => [],
            'note'      => 'No suppliers match that name. The system holds '
                         . $stats['suppliers'] . ' suppliers in total.',
        ];
    }

    foreach ($rows as &$row) {
        $row['link'] = nova_supplier_link((int)$row['id']);
    }
    unset($row);

    return ['count' => count($rows), 'suppliers' => $rows];
}

/**
 * A supplier's uploaded documents.
 *
 * The contact rate sheets matter most: they are the original PDFs the office
 * negotiates against, and they carry terms that never made it into the tour
 * rows. Nova cannot read them, but pointing staff at the right one saves the
 * hunt through the supplier page.
 */
function nova_supplier_files(PDO $pdo, array $in): array
{
    $supplierId = (int)$in['supplier_id'];

    $stmt = $pdo->prepare('SELECT id, name FROM suppliers WHERE id = ? LIMIT 1');
    $stmt->execute([$supplierId]);
    $supplier = $stmt->fetch();

    if (!$supplier) {
        return ['error' => 'not_found', 'note' => 'No supplier with that id exists.'];
    }

    $where = ['f.supplier_id = ?'];
    $args = [$supplierId];

    if (!empty($in['category'])) {
        $where[] = 'f.file_category = ?';
        $args[] = (string)$in['category'];
    }

    $stmt = $pdo->prepare(
        'SELECT f.original_name, f.label, f.file_category, f.file_type, f.file_path,
                f.uploaded_at
           FROM supplier_files f
          WHERE ' . implode(' AND ', $where) . '
          ORDER BY f.uploaded_at DESC
          LIMIT 30'
    );
    $stmt->execute($args);
    $rows = $stmt->fetchAll();

    if (!$rows) {
        return [
            'supplier' => $supplier['name'],
            'link'     => nova_supplier_link($supplierId),
            'files'    => [],
            'note'     => empty($in['category'])
                ? 'This supplier has no files uploaded. That is missing data — say so '
                . 'rather than implying the rate sheet exists somewhere else.'
                : 'This supplier has no files in that category. Other categories may '
                . 'still hold something; call again without the filter to check.',
        ];
    }

    $files = array_map(fn($f) => array_filter([
        'name'        => $f['label'] ?: $f['original_name'],
        'category'    => $f['file_category'],
        'type'        => $f['file_type'],
        'uploaded_at' => substr((string)$f['uploaded_at'], 0, 10),
        'link'        => nova_file_link((string)$f['file_path']),
    ], fn($v) => $v !== null && $v !== ''), $rows);

    return [
        'supplier' => $supplier['name'],
        'link'     => nova_supplier_link($supplierId),
        'count'    => count($files),
        'files'    => $files,
        'note'     => 'Nova cannot read the contents of these files. Give the user the '
                    . 'link; do not describe or summarise what is inside them.',
    ];
}
