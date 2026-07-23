<?php
/**
 * What the data actually contains right now.
 *
 * The system prompt used to assert its own figures — "30 hotels", "there are no
 * Krabi hotels", "37 suppliers". Every one of those was true when it was
 * written and none of them is checked. The failure is quiet and total: add a
 * Krabi hotel to ContactRate and Nova keeps telling staff it does not exist,
 * with no error anywhere. So the counts are read from the database and folded
 * into the prompt at request time.
 *
 * Cached to a file for an hour. The queries are cheap, but they run on every
 * turn and the answer moves about once a week.
 */

declare(strict_types=1);

const NOVA_STATS_TTL = 3600;

/**
 * @return array{
 *   tours: array{total:int, destinations:array<string,int>, no_destination:int},
 *   hotels: array{total:int, with_rates:int, provinces:array<string,int>},
 *   rates: int,
 *   suppliers: int
 * }
 */
function nova_data_stats(PDO $pdo): array
{
    // The filename carries a version. Changing the shape of the returned array
    // without changing it would serve a stale cache that is missing the new
    // keys — a deploy-time bug that only shows up an hour later, once the old
    // entry expires and hides the evidence. Bump it whenever the shape changes.
    $cache = sys_get_temp_dir() . '/nova-stats-v2.json';

    if (is_file($cache) && time() - filemtime($cache) < NOVA_STATS_TTL) {
        $cached = json_decode((string)file_get_contents($cache), true);
        if (is_array($cached) && isset($cached['tours'], $cached['hotels']['areas'])) {
            return $cached;
        }
    }

    $stats = nova_read_stats($pdo);

    // A failed write is not worth failing the turn over — it only means the
    // next request recomputes. @ because a read-only temp dir is a valid
    // deployment, just a slower one.
    @file_put_contents($cache, json_encode($stats, JSON_UNESCAPED_UNICODE), LOCK_EX);

    return $stats;
}

function nova_read_stats(PDO $pdo): array
{
    $tours = ['total' => 0, 'destinations' => [], 'no_destination' => 0];
    $rows = $pdo->query(
        "SELECT destination, COUNT(*) AS c
           FROM tours
          GROUP BY destination"
    )->fetchAll();

    foreach ($rows as $row) {
        $name = trim((string)($row['destination'] ?? ''));
        $count = (int)$row['c'];
        $tours['total'] += $count;

        if ($name === '') {
            $tours['no_destination'] += $count;
        } else {
            $tours['destinations'][$name] = $count;
        }
    }
    arsort($tours['destinations']);

    // hotels.destination is free text with no schema beyond commas — "Phuket",
    // "Patong Beach, Phuket", "North Pattaya, Chonburi, Thailand". Split in PHP
    // rather than SQL because the useful segment is not at a fixed position: the
    // last one is the province on two-part rows and the useless word "Thailand"
    // on three-part ones.
    //
    // Both halves are kept. `provinces` is the compact answer to "where do we
    // have hotels"; `areas` carries the sub-locality names — Pattaya, Patong,
    // Karon — because those are what staff actually type, and a summary that
    // only said "Chonburi" would have the model denying we have Pattaya hotels.
    $hotels = ['total' => 0, 'with_rates' => 0, 'provinces' => [], 'areas' => []];
    $rows = $pdo->query(
        "SELECT h.destination,
                EXISTS (
                    SELECT 1 FROM hotel_rates r
                     WHERE r.hotel_id = h.id AND r.is_active = 1
                ) AS has_rates
           FROM hotels h
          WHERE h.is_active = 1"
    )->fetchAll();

    foreach ($rows as $row) {
        $hotels['total']++;
        $hotels['with_rates'] += (int)$row['has_rates'];

        $parts = array_values(array_filter(
            array_map('trim', explode(',', (string)($row['destination'] ?? ''))),
            // A country name is true of every row and distinguishes nothing.
            fn($p) => $p !== '' && strcasecmp($p, 'Thailand') !== 0
        ));

        if (!$parts) {
            $hotels['provinces']['ไม่ระบุ'] = ($hotels['provinces']['ไม่ระบุ'] ?? 0) + 1;
            continue;
        }

        $province = array_pop($parts);
        $hotels['provinces'][$province] = ($hotels['provinces'][$province] ?? 0) + 1;

        foreach ($parts as $area) {
            $hotels['areas'][$area] = ($hotels['areas'][$area] ?? 0) + 1;
        }
    }
    arsort($hotels['provinces']);
    arsort($hotels['areas']);

    return [
        'tours'     => $tours,
        'hotels'    => $hotels,
        'rates'     => (int)$pdo->query(
            'SELECT COUNT(*) FROM hotel_rates WHERE is_active = 1'
        )->fetchColumn(),
        'suppliers' => (int)$pdo->query('SELECT COUNT(*) FROM suppliers')->fetchColumn(),
    ];
}

/**
 * The stats as prompt text.
 *
 * Written so the model can answer "do we have hotels in X" from the province
 * list alone, without a tool call — and so the answer changes the day the data
 * does.
 */
function nova_stats_prompt(array $stats): string
{
    $tourDests = [];
    foreach ($stats['tours']['destinations'] as $name => $count) {
        $tourDests[] = "$name $count";
    }
    if ($stats['tours']['no_destination'] > 0) {
        $tourDests[] = 'ไม่ระบุจังหวัด ' . $stats['tours']['no_destination'];
    }

    $hotelProvinces = [];
    foreach ($stats['hotels']['provinces'] as $name => $count) {
        $hotelProvinces[] = "$name $count";
    }

    $hotelTotal = $stats['hotels']['total'];
    $withRates = $stats['hotels']['with_rates'];
    $noRates = $hotelTotal - $withRates;

    $lines = [
        '- ทัวร์ทั้งหมด ' . $stats['tours']['total'] . ' รายการ แยกตามจังหวัด: '
            . implode(' · ', $tourDests),
        '- โรงแรมทั้งหมด ' . $hotelTotal . ' แห่ง อยู่ในจังหวัด: '
            . implode(' · ', $hotelProvinces),
    ];

    if ($stats['hotels']['areas']) {
        $lines[] = '- ย่านที่โรงแรมตั้งอยู่ (ตามที่บันทึกไว้): '
                 . implode(' · ', array_keys($stats['hotels']['areas']));
    }

    $lines[] = '- โรงแรมที่มีราคาโหลดไว้ ' . $withRates . ' แห่ง จาก ' . $hotelTotal
             . ' แห่ง (อีก ' . $noRates . ' แห่งยังไม่มีราคา)';
    $lines[] = '- ราคาห้องพักที่ใช้งานอยู่ ' . $stats['rates'] . ' รายการ';
    $lines[] = '- ซัพพลายเออร์ ' . $stats['suppliers'] . ' ราย';

    // These lists are the whole answer to "do we have hotels in X", and saying
    // so explicitly is what replaces the old hardcoded claim that Krabi hotels
    // do not exist. That claim was true, but frozen — it would have outlived the
    // fact without anyone noticing.
    $lines[] = '- จังหวัดและย่านด้านบนคือ**ทั้งหมด**ที่มีในระบบ ณ ตอนนี้ '
             . 'ถ้าผู้ใช้ถามถึงที่ที่ไม่อยู่ในรายการ แปลว่าระบบยังไม่มีข้อมูลที่นั่น '
             . 'ให้บอกตรงๆ ว่าไม่มี แล้วเสนอสิ่งที่มี (เช่น ไม่มีโรงแรม แต่มีทัวร์)';

    return implode("\n", $lines);
}
