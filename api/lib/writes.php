<?php
/**
 * Changing ContactRate — proposed by Nova, applied by a person.
 *
 * Nova reads through the tools in tools.php. These write, and the difference in
 * blast radius is the reason everything here is built the way it is: a wrong
 * search result is a sentence the user can dismiss, a wrong UPDATE is a net cost
 * sitting in the system that somebody quotes off next week. This database has no
 * undo, no soft delete, and no history beyond a single `updated_by` column.
 *
 * So no tool call reaches a ContactRate table. `nova_propose_write` validates the
 * model's arguments, works out the exact diff against the row as it stands, and
 * files it in `ai_record_writes` as pending. The change happens in
 * `nova_apply_write`, which runs from an explicit button press by the person who
 * asked — never inside the assistant turn.
 *
 * Four things follow from that split, each one a way this could have gone wrong:
 *
 *  - Validation is here, not in the tool schema. A schema describes what the
 *    model should send, and there is no version of it that stops the model
 *    sending something else. Every value is re-checked against the database.
 *  - The diff is computed at proposal time and applied verbatim. What the person
 *    confirms is what is written; nothing is re-derived afterwards.
 *  - A row that moved between proposal and confirmation is not applied. Somebody
 *    editing the same record in ContactRate meanwhile is exactly the case where a
 *    stale "before" would silently undo their work.
 *  - Column names come from the tables below and never from the model, so the
 *    interpolation in nova_write_statement() cannot carry anything a person did
 *    not write into this file.
 *
 * What is editable is described as data, in NOVA_WRITE_ENTITIES. Adding hotels
 * would be another entry plus its per-column rules — no new code path, and no
 * second confirm card to keep in step with this one.
 */

declare(strict_types=1);

require_once __DIR__ . '/tools.php';

/** A proposal nobody acted on stops being confirmable. */
const NOVA_WRITE_TTL = 24 * 3600;

/**
 * A price nobody in this office would type. Not a business rule — a typo guard.
 * The most expensive thing in the table is a five-figure liveaboard.
 */
const NOVA_MAX_PRICE = 500000;

/**
 * The columns of `tours` Nova may touch, in the order the confirm card lists
 * them, with the label staff see.
 *
 * Everything else in the table is off limits and not by omission: `id`,
 * `created_at` and `updated_at` are the database's, and `updated_by` is written
 * by the apply step from the session rather than from anything the model said.
 */
const NOVA_TOUR_FIELDS = [
    'tour_name'         => ['label' => 'ชื่อทัวร์',            'type' => 'text'],
    'supplier_id'       => ['label' => 'ซัพพลายเออร์',        'type' => 'supplier'],
    'destination'       => ['label' => 'จังหวัด',              'type' => 'destination'],
    'departure_from'    => ['label' => 'ออกจาก',              'type' => 'text'],
    'pier'              => ['label' => 'ท่าเรือ',               'type' => 'text'],
    'tour_type'         => ['label' => 'ประเภททัวร์',          'type' => 'text'],
    'adult_price'       => ['label' => 'ราคาผู้ใหญ่',           'type' => 'money'],
    'child_price'       => ['label' => 'ราคาเด็ก',             'type' => 'money'],
    'start_date'        => ['label' => 'เริ่มใช้ราคา',          'type' => 'date'],
    'end_date'          => ['label' => 'ถึงวันที่',              'type' => 'date'],
    'park_fee_included' => ['label' => 'รวมค่าอุทยาน',         'type' => 'bool'],
    'park_fee_adult'    => ['label' => 'ค่าอุทยานผู้ใหญ่',      'type' => 'money'],
    'park_fee_child'    => ['label' => 'ค่าอุทยานเด็ก',        'type' => 'money'],
    'notes'             => ['label' => 'หมายเหตุ',             'type' => 'notes'],
    'map_url'           => ['label' => 'ลิงก์แผนที่',           'type' => 'url'],
];

/**
 * The columns of `suppliers`. Five phone numbers is not a mistake: 19 of the 37
 * suppliers have a second one, and the office reaches people on whichever
 * answers.
 */
const NOVA_SUPPLIER_FIELDS = [
    'name'     => ['label' => 'ชื่อ',            'type' => 'text'],
    'phone'    => ['label' => 'เบอร์โทร',        'type' => 'phone'],
    'phone_2'  => ['label' => 'เบอร์โทร 2',      'type' => 'phone'],
    'phone_3'  => ['label' => 'เบอร์โทร 3',      'type' => 'phone'],
    'phone_4'  => ['label' => 'เบอร์โทร 4',      'type' => 'phone'],
    'phone_5'  => ['label' => 'เบอร์โทร 5',      'type' => 'phone'],
    'line'     => ['label' => 'LINE',            'type' => 'text'],
    'whatsapp' => ['label' => 'WhatsApp',        'type' => 'phone'],
    'email'    => ['label' => 'อีเมล',            'type' => 'email'],
    'facebook' => ['label' => 'Facebook',        'type' => 'text'],
    'website'  => ['label' => 'เว็บไซต์',         'type' => 'url'],
    'address'  => ['label' => 'ที่อยู่',           'type' => 'notes'],
];

/**
 * What each kind of record is, in the terms the rest of this file works in.
 *
 * `stamp_column` is where the applying user's name goes. `suppliers` has no such
 * column — it was never added to the main app — so for suppliers the only record
 * of who changed what is `ai_record_writes` itself. That is a real difference in
 * traceability and the reason the row is kept forever.
 */
const NOVA_WRITE_ENTITIES = [
    'tour' => [
        'table'        => 'tours',
        'name_column'  => 'tour_name',
        'stamp_column' => 'updated_by',
        'label'        => 'ทัวร์',
        'fields'       => NOVA_TOUR_FIELDS,
    ],
    'supplier' => [
        'table'        => 'suppliers',
        'name_column'  => 'name',
        'stamp_column' => null,
        'label'        => 'ซัพพลายเออร์',
        'fields'       => NOVA_SUPPLIER_FIELDS,
    ],
];

function nova_entity(string $entity): array
{
    return NOVA_WRITE_ENTITIES[$entity]
        ?? throw new RuntimeException("unknown entity: $entity");
}

/** The record's page in the main app, once there is a record to point at. */
function nova_record_link(string $entity, int $id): ?string
{
    return match ($entity) {
        'tour'     => nova_tour_link($id),
        'supplier' => nova_supplier_link($id),
        default    => null,
    };
}

/**
 * Tool schemas for the write tools. Kept beside the code that enforces them so a
 * field added to one is visibly missing from the other.
 */
function nova_write_tool_definitions(): array
{
    // Spelled out rather than generated from the field maps: the model needs to
    // be told what each field means and when to leave it alone, which is not
    // something a column list knows.
    $tour = [
        'supplier_id' => [
            'type' => 'integer',
            'description' =>
                'Supplier id from search_suppliers. Never guess one — look the '
                . 'supplier up first. If the supplier is not in the system yet, '
                . 'propose adding it with propose_supplier_create before the tour.',
        ],
        'destination' => [
            'type' => 'string',
            'description' =>
                'Province. Must be one of the values already used in the data '
                . '(Phuket, Krabi, Phang Nga, …) — a new spelling would hide the '
                . 'tour from every search that filters by province.',
        ],
        'departure_from' => ['type' => 'string', 'description' => 'Where the tour departs from, e.g. "Krabi".'],
        'pier' => ['type' => 'string', 'description' => 'Pier name, e.g. "Nopparat Thara Pier".'],
        'tour_type' => ['type' => 'string', 'description' => 'Tour type, e.g. "Join", "Private".'],
        'adult_price' => ['type' => 'number', 'description' => 'Adult net cost in THB.'],
        'child_price' => [
            'type' => 'number',
            'description' =>
                'Child net cost in THB. Some rate sheets quote one price for everyone; '
                . 'ask before assuming it applies to children too.',
        ],
        'start_date' => ['type' => 'string', 'description' => 'Validity start, YYYY-MM-DD.'],
        'end_date' => ['type' => 'string', 'description' => 'Validity end, YYYY-MM-DD. Must not be before start_date.'],
        'park_fee_included' => [
            'type' => 'boolean',
            'description' => 'True if the national park fee is already in the price.',
        ],
        'park_fee_adult' => ['type' => 'number', 'description' => 'Adult park fee in THB, when charged separately.'],
        'park_fee_child' => ['type' => 'number', 'description' => 'Child park fee in THB, when charged separately.'],
        'notes' => [
            'type' => 'string',
            'description' =>
                'Free-text notes — operating days, exclusions, pickup zones. '
                . 'On an update this REPLACES the existing notes, so read the '
                . 'current ones with get_tour_details first and send the full new '
                . 'text, never just the part being added.',
        ],
        'map_url' => ['type' => 'string', 'description' => 'Google Maps link for the meeting point.'],
    ];

    $supplier = [
        'phone' => ['type' => 'string', 'description' => 'Main phone number.'],
        'phone_2' => ['type' => 'string', 'description' => 'Second phone number.'],
        'phone_3' => ['type' => 'string', 'description' => 'Third phone number.'],
        'phone_4' => ['type' => 'string', 'description' => 'Fourth phone number.'],
        'phone_5' => ['type' => 'string', 'description' => 'Fifth phone number.'],
        'line' => ['type' => 'string', 'description' => 'LINE id or display name.'],
        'whatsapp' => ['type' => 'string', 'description' => 'WhatsApp number.'],
        'email' => ['type' => 'string', 'description' => 'Email address.'],
        'facebook' => ['type' => 'string', 'description' => 'Facebook page name or link.'],
        'website' => ['type' => 'string', 'description' => 'Website, as a http(s) link.'],
        'address' => ['type' => 'string', 'description' => 'Postal address, as written on the rate sheet.'],
    ];

    return [
        [
            'name' => 'propose_tour_create',
            'description' =>
                'เสนอเพิ่มทัวร์ใหม่เข้าระบบ ContactRate. เรียกเมื่อผู้ใช้บอกให้เพิ่มทัวร์ใหม่. '
                . 'Propose adding a new tour. This does NOT write anything — it puts the '
                . 'proposal in front of the user, who must press confirm before the tour '
                . 'exists. Search first to make sure the tour is not already in the system. '
                . 'Only call this when the user has actually asked for a tour to be added; '
                . 'never offer to add one on your own initiative.',
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'tour_name' => ['type' => 'string', 'description' => 'Tour name, as staff would write it.'],
                    ...$tour,
                ],
                'required' => ['tour_name'],
            ],
        ],

        [
            'name' => 'propose_tour_update',
            'description' =>
                'เสนอแก้ไขข้อมูลทัวร์ที่มีอยู่แล้ว. เรียกเมื่อผู้ใช้บอกให้แก้ราคา วันที่ หรือรายละเอียดทัวร์. '
                . 'Propose changing an existing tour. This does NOT write anything — the user '
                . 'must press confirm first. Send only the fields that should change; anything '
                . 'omitted is left exactly as it is. Look the tour up first so you are editing '
                . 'the right one, and if more than one tour matches what the user said, ask '
                . 'which before proposing.',
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'tour_id' => ['type' => 'integer', 'description' => 'The tour id from search_tours.'],
                    'tour_name' => ['type' => 'string', 'description' => 'New tour name. Omit unless renaming.'],
                    ...$tour,
                ],
                'required' => ['tour_id'],
            ],
        ],

        [
            'name' => 'propose_supplier_create',
            'description' =>
                'เสนอเพิ่มซัพพลายเออร์รายใหม่. เรียกเมื่อผู้ใช้บอกให้เพิ่มซัพพลายเออร์ '
                . 'หรือเมื่อจะเพิ่มทัวร์แต่ซัพพลายเออร์ยังไม่มีในระบบ. '
                . 'Propose adding a supplier. This does NOT write anything — the user must '
                . 'press confirm first. Always run search_suppliers on the name before '
                . 'calling this: a supplier added twice splits its tours across two records, '
                . 'and nothing in the system will point that out later.',
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'name' => [
                        'type' => 'string',
                        'description' =>
                            'Company name, as written on the rate sheet. Keep the legal '
                            . 'suffix if the document has one.',
                    ],
                    ...$supplier,
                ],
                'required' => ['name'],
            ],
        ],

        [
            'name' => 'propose_supplier_update',
            'description' =>
                'เสนอแก้ไขข้อมูลติดต่อของซัพพลายเออร์. เรียกเมื่อผู้ใช้บอกให้แก้เบอร์ อีเมล LINE หรือที่อยู่. '
                . 'Propose changing a supplier. This does NOT write anything — the user must '
                . 'press confirm first. Send only the fields that should change. Note that a '
                . 'new phone number usually belongs in an empty phone_2..phone_5 rather than '
                . 'over the existing one; ask which they mean if it is not clear.',
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'supplier_id' => [
                        'type' => 'integer',
                        'description' => 'The supplier id from search_suppliers.',
                    ],
                    'name' => ['type' => 'string', 'description' => 'New name. Omit unless renaming.'],
                    ...$supplier,
                ],
                'required' => ['supplier_id'],
            ],
        ],
    ];
}

/**
 * Validates one field's value against its column and returns it in the form it
 * would be stored in.
 *
 * @throws RuntimeException with a message written for the model to read and
 *         relay — it comes back as a tool error and ends up on screen.
 */
function nova_clean_field(PDO $pdo, string $entity, string $field, mixed $value): string|int|float|null
{
    $meta = nova_entity($entity)['fields'][$field];
    $label = $meta['label'];

    // An explicit null clears a nullable column — "ลบวันหมดอายุออก" is a real
    // edit. The record's name is the one thing that cannot be emptied.
    if ($value === null || (is_string($value) && trim($value) === '')) {
        if ($field === nova_entity($entity)['name_column']) {
            throw new RuntimeException("$field cannot be empty.");
        }
        return null;
    }

    switch ($meta['type']) {
        case 'money':
            if (!is_numeric($value)) {
                throw new RuntimeException("$field must be a number in THB, got: " . json_encode($value));
            }
            $amount = round((float)$value, 2);
            if ($amount < 0 || $amount > NOVA_MAX_PRICE) {
                throw new RuntimeException(
                    "$field is $amount, which is outside the plausible range (0–"
                    . NOVA_MAX_PRICE . ' THB). Check the figure with the user before proposing again.'
                );
            }
            return $amount;

        case 'date':
            $text = trim((string)$value);
            $date = DateTimeImmutable::createFromFormat('!Y-m-d', $text);
            if (!$date || $date->format('Y-m-d') !== $text) {
                throw new RuntimeException("$field must be a date as YYYY-MM-DD, got: $text");
            }
            return $text;

        case 'bool':
            return $value ? 1 : 0;

        case 'supplier':
            $id = (int)$value;
            $stmt = $pdo->prepare('SELECT id FROM suppliers WHERE id = ? LIMIT 1');
            $stmt->execute([$id]);
            if (!$stmt->fetch()) {
                throw new RuntimeException(
                    "supplier_id $id does not exist. Use search_suppliers to find the right one, "
                    . 'or propose_supplier_create to add it.'
                );
            }
            return $id;

        case 'destination':
            // Matched against what is actually in the table, case-insensitively,
            // and stored in the existing spelling. A tour filed under "phuket"
            // or "Phuket " is invisible to search_tours, which filters on exact
            // equality — and invisible in a way nobody would think to check,
            // because the record looks fine on its own page.
            $known = array_keys(nova_data_stats($pdo)['tours']['destinations']);
            foreach ($known as $canonical) {
                if (strcasecmp($canonical, trim((string)$value)) === 0) {
                    return $canonical;
                }
            }
            throw new RuntimeException(
                'destination must be one of: ' . implode(', ', $known)
                . '. Got: ' . $value . '. If this really is a new province, the tour has to be '
                . 'added in ContactRate directly.'
            );

        case 'phone':
            // Deliberately loose. The column holds things like "081-234-5678",
            // "+66 81 234 5678" and "081 234 5678 (คุณเอ)", and a stricter rule
            // would reject numbers that are already in the system and correct.
            $phone = trim((string)$value);
            if (!preg_match('/\d/', $phone) || mb_strlen($phone) > 50) {
                throw new RuntimeException(
                    "$label must contain digits and be under 50 characters. Got: $phone"
                );
            }
            return $phone;

        case 'email':
            $email = trim((string)$value);
            if (!filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 150) {
                throw new RuntimeException("$label is not a valid email address. Got: $email");
            }
            return $email;

        case 'url':
            $url = trim((string)$value);
            if (!preg_match('~^https?://~i', $url) || mb_strlen($url) > 500) {
                throw new RuntimeException("$label must be a http(s) link under 500 characters.");
            }
            return $url;

        case 'notes':
            return mb_substr(trim((string)$value), 0, 5000);

        default:
            $text = trim((string)$value);
            if (mb_strlen($text) > 255) {
                throw new RuntimeException("$label is too long — 255 characters at most.");
            }
            return $text;
    }
}

/** How a stored value reads on the confirm card. */
function nova_format_value(PDO $pdo, string $entity, string $field, string|int|float|null $value): string
{
    if ($value === null || $value === '') {
        return '—';
    }

    return match (nova_entity($entity)['fields'][$field]['type']) {
        'money'    => number_format((float)$value, 0) . ' ฿',
        'bool'     => $value ? 'รวมแล้ว' : 'ไม่รวม',
        'supplier' => nova_supplier_name($pdo, (int)$value),
        default    => (string)$value,
    };
}

function nova_supplier_name(PDO $pdo, int $id): string
{
    $stmt = $pdo->prepare('SELECT name FROM suppliers WHERE id = ? LIMIT 1');
    $stmt->execute([$id]);
    return (string)($stmt->fetchColumn() ?: "ซัพพลายเออร์ #$id");
}

/**
 * A stored column value in the same shape nova_clean_field() produces, so the two
 * can be compared without a decimal string reading as a change from the float
 * that equals it.
 */
function nova_normalise_stored(string $entity, string $field, mixed $value): string|int|float|null
{
    if ($value === null) {
        return null;
    }

    return match (nova_entity($entity)['fields'][$field]['type']) {
        'money'    => round((float)$value, 2),
        'bool'     => (int)$value ? 1 : 0,
        'supplier' => (int)$value,
        // '' and NULL both mean "not recorded" in these tables, and staff have no
        // way to tell them apart. Treating them as the same value keeps an
        // untouched blank column out of the diff.
        default    => trim((string)$value) === '' ? null : (string)$value,
    };
}

function nova_same_value(string|int|float|null $a, string|int|float|null $b): bool
{
    if ($a === null || $b === null) {
        return $a === $b;
    }
    return is_float($a) || is_float($b) ? abs((float)$a - (float)$b) < 0.005 : (string)$a === (string)$b;
}

/**
 * Checks that hold between fields rather than within one, run against the record
 * as it would end up — an update that moves only `start_date` still has to be
 * checked against the `end_date` already stored.
 *
 * @return string|null the problem, in a sentence the model can relay
 */
function nova_cross_check(PDO $pdo, string $entity, array $changes, array $current, ?int $recordId): ?string
{
    $value = static fn(string $field): mixed => array_key_exists($field, $changes)
        ? $changes[$field]['to']
        : ($current[$field] ?? null);

    if ($entity === 'tour') {
        $start = $value('start_date');
        $end = $value('end_date');
        if ($start && $end && $end < $start) {
            return "The validity period would end ($end) before it starts ($start). Check the dates.";
        }

        // The same tour name under the same supplier is nearly always the same
        // tour twice. Across suppliers it is not — "4 Island Speed Boat" is sold
        // by several of them — so the check is on the pair.
        $name = $value('tour_name');
        $supplier = $value('supplier_id');
        if ($recordId === null && $name && $supplier) {
            $stmt = $pdo->prepare(
                'SELECT id FROM tours WHERE tour_name = ? AND supplier_id = ? LIMIT 1'
            );
            $stmt->execute([$name, $supplier]);
            if ($existing = $stmt->fetchColumn()) {
                return "This supplier already has a tour called \"$name\" (id $existing). "
                     . 'Check whether it should be updated instead of added again.';
            }
        }
    }

    if ($entity === 'supplier') {
        // A supplier added twice splits its tours across two records, and
        // nothing in the system points that out afterwards.
        $name = $value('name');
        if ($recordId === null && $name) {
            $stmt = $pdo->prepare('SELECT id, name FROM suppliers WHERE name = ? LIMIT 1');
            $stmt->execute([$name]);
            if ($existing = $stmt->fetch()) {
                return "A supplier called \"{$existing['name']}\" already exists (id {$existing['id']}). "
                     . 'Use that one, or propose an update to it — do not add a second record.';
            }
        }
    }

    return null;
}

/**
 * Turns a tool call into a pending proposal.
 *
 * @param array{user_id:int, username:string, conversation_id:int} $ctx
 * @return array the tool result the model sees, plus `_card` — the payload for
 *         the browser, which the caller strips before it goes back to the model.
 */
function nova_propose_write(PDO $pdo, array $ctx, string $entity, string $action, array $in): array
{
    $config = nova_entity($entity);
    $creating = $action === 'create';
    $current = [];
    $recordId = null;

    // The id argument is named after the record, not the column: the model is
    // talking about a tour or a supplier, and `tour_id` reads as one.
    $idArgument = $entity . '_id';

    if (!$creating) {
        $recordId = (int)($in[$idArgument] ?? 0);
        $stmt = $pdo->prepare("SELECT * FROM {$config['table']} WHERE id = ? LIMIT 1");
        $stmt->execute([$recordId]);
        $current = $stmt->fetch() ?: [];

        if (!$current) {
            return [
                'error' => 'not_found',
                'note'  => "No {$entity} with that id exists. Search for it first.",
            ];
        }
    }

    // Only fields the model actually sent. `array_key_exists` rather than isset:
    // a null is a request to clear the column, not an absent argument.
    $changes = [];
    foreach ($config['fields'] as $field => $meta) {
        if (!array_key_exists($field, $in)) {
            continue;
        }

        try {
            $to = nova_clean_field($pdo, $entity, $field, $in[$field]);
        } catch (RuntimeException $e) {
            return ['error' => 'invalid_value', 'detail' => $e->getMessage()];
        }

        $from = $creating ? null : nova_normalise_stored($entity, $field, $current[$field] ?? null);

        // A field being "changed" to what it already says is not a change. The
        // model tends to echo back the whole record it just read, and a card
        // listing eight rows of which one differs hides the one that matters.
        if (!$creating && nova_same_value($from, $to)) {
            continue;
        }

        $changes[$field] = [
            'from'      => $from,
            'to'        => $to,
            'label'     => $meta['label'],
            'from_text' => $creating ? null : nova_format_value($pdo, $entity, $field, $from),
            'to_text'   => nova_format_value($pdo, $entity, $field, $to),
        ];
    }

    if (!$changes) {
        return [
            'status' => 'no_change',
            'note'   => $creating
                ? 'Nothing to create — no fields were given.'
                : "Every value given already matches the {$entity}. Nothing was proposed. "
                  . 'Tell the user the record already says that.',
        ];
    }

    if ($problem = nova_cross_check($pdo, $entity, $changes, $current, $recordId)) {
        return ['error' => 'invalid_value', 'detail' => $problem];
    }

    $nameColumn = $config['name_column'];
    $name = array_key_exists($nameColumn, $changes)
        ? (string)$changes[$nameColumn]['to']
        : (string)($current[$nameColumn] ?? '');

    if ($creating && $name === '') {
        return [
            'error'  => 'invalid_value',
            'detail' => "$nameColumn is required to create a {$entity}.",
        ];
    }

    $stmt = $pdo->prepare(
        'INSERT INTO ai_record_writes
             (user_id, conversation_id, entity, action, record_id, record_name, changes)
         VALUES (?, ?, ?, ?, ?, ?, ?)'
    );
    $stmt->execute([
        $ctx['user_id'],
        $ctx['conversation_id'],
        $entity,
        $action,
        $recordId,
        mb_substr($name, 0, 255),
        json_encode($changes, JSON_UNESCAPED_UNICODE),
    ]);

    $card = nova_write_card($pdo, (int)$pdo->lastInsertId());

    return [
        'status'   => 'pending_confirmation',
        'write_id' => $card['id'],
        'entity'   => $entity,
        'action'   => $action,
        'record'   => $name,
        'changes'  => array_map(
            static fn(array $c): array => ['from' => $c['from_text'], 'to' => $c['to_text']],
            $changes
        ),
        // The model has to say what it is about to do without claiming to have
        // done it. Both halves matter: staff who read "แก้ให้แล้ว" stop reading,
        // and the button is then never pressed.
        'note' =>
            'NOTHING HAS BEEN WRITTEN YET. A confirmation card listing these changes is '
            . 'already on the user\'s screen — do not repeat the full list in your reply. '
            . 'Say in one short sentence what will change and ask them to press ยืนยัน. '
            . 'Never say the change is done, saved, or updated; you cannot press the button '
            . 'for them, and you will not be told when they do.',
        '_card' => $card,
    ];
}

/**
 * One proposal as the browser needs it: what changes, in what order, already
 * formatted. The raw `from`/`to` stay out of it — the card is for reading, and
 * everything applied is read back from the table at confirm time anyway.
 */
function nova_write_card(PDO $pdo, int $id): array
{
    $stmt = $pdo->prepare('SELECT * FROM ai_record_writes WHERE id = ? LIMIT 1');
    $stmt->execute([$id]);
    $row = $stmt->fetch();

    return $row ? nova_write_card_row($row) : [];
}

function nova_write_card_row(array $row): array
{
    $entity = (string)$row['entity'];
    $config = nova_entity($entity);
    $changes = json_decode((string)$row['changes'], true) ?: [];

    // Listed in the order of the field map rather than the order the model
    // happened to send them, so two cards for the same kind of edit read the
    // same way.
    $lines = [];
    foreach ($config['fields'] as $field => $meta) {
        if (!isset($changes[$field])) {
            continue;
        }
        $lines[] = [
            'field' => $field,
            'label' => $meta['label'],
            'from'  => $changes[$field]['from_text'] ?? null,
            'to'    => $changes[$field]['to_text'] ?? '—',
        ];
    }

    $recordId = $row['record_id'] === null ? null : (int)$row['record_id'];

    return [
        'id'           => (int)$row['id'],
        'message_id'   => $row['message_id'] === null ? null : (int)$row['message_id'],
        'entity'       => $entity,
        'entity_label' => $config['label'],
        'action'       => (string)$row['action'],
        'record_id'    => $recordId,
        'record_name'  => (string)$row['record_name'],
        'status'       => nova_write_status($row),
        'changes'      => $lines,
        // Only once it exists. A pending create has no page to link to.
        'link'         => $recordId !== null && $row['status'] === 'applied'
            ? nova_record_link($entity, $recordId)
            : null,
        'created_at'   => (string)$row['created_at'],
    ];
}

/**
 * `expired` is derived, not stored. A pending row that nobody confirmed is not
 * worth a cron job to sweep up, but it must stop being confirmable — and it must
 * stop offering a button, because the record it describes may have moved on.
 */
function nova_write_status(array $row): string
{
    if ($row['status'] !== 'pending') {
        return (string)$row['status'];
    }
    return strtotime((string)$row['created_at']) < time() - NOVA_WRITE_TTL ? 'expired' : 'pending';
}

/** Every proposal made in one conversation, so reopening a chat still shows them. */
function nova_conversation_writes(PDO $pdo, int $conversationId): array
{
    $stmt = $pdo->prepare(
        'SELECT * FROM ai_record_writes WHERE conversation_id = ? ORDER BY id'
    );
    $stmt->execute([$conversationId]);

    return array_map(nova_write_card_row(...), $stmt->fetchAll());
}

/** Ties this turn's proposals to the assistant message that made them. */
function nova_link_writes(PDO $pdo, array $writeIds, int $messageId): void
{
    if (!$writeIds || $messageId <= 0) {
        return;
    }

    $in = implode(',', array_fill(0, count($writeIds), '?'));
    $stmt = $pdo->prepare("UPDATE ai_record_writes SET message_id = ? WHERE id IN ($in)");
    $stmt->execute([$messageId, ...$writeIds]);
}

/**
 * The one statement that changes a ContactRate table, and its arguments.
 *
 * Split out from the apply step so it can be prepared against the server without
 * being executed — the only way to prove this SQL is valid without putting a junk
 * row in a live table. Column and table names come from NOVA_WRITE_ENTITIES,
 * never from the model.
 *
 * The applying user's name is appended here rather than being one of the
 * changes: it is the session's, and a proposal has no business naming who
 * applied it. Tables without a `stamp_column` simply do not get one.
 *
 * @param array<string, array{to: mixed}> $changes
 * @return array{0: string, 1: list<mixed>}
 */
function nova_write_statement(
    string $entity,
    string $action,
    ?int $recordId,
    array $changes,
    string $username
): array {
    $config = nova_entity($entity);
    $table = $config['table'];
    $stamp = $config['stamp_column'];
    $fields = array_intersect_key($changes, $config['fields']);

    if ($action === 'update') {
        $set = [];
        $args = [];
        foreach ($fields as $field => $change) {
            $set[] = "`$field` = ?";
            $args[] = $change['to'];
        }
        if ($stamp !== null) {
            $set[] = "`$stamp` = ?";
            $args[] = $username;
        }
        $args[] = $recordId;

        return ["UPDATE $table SET " . implode(', ', $set) . ' WHERE id = ?', $args];
    }

    $columns = [];
    $values = [];
    if ($stamp !== null) {
        $columns[] = $stamp;
        $values[] = $username;
    }
    foreach ($fields as $field => $change) {
        $columns[] = $field;
        $values[] = $change['to'];
    }

    return [
        "INSERT INTO $table (`" . implode('`, `', $columns) . '`) VALUES ('
        . implode(', ', array_fill(0, count($values), '?')) . ')',
        $values,
    ];
}

/**
 * Applies a confirmed proposal, or records that it was turned down.
 *
 * The only place in Nova that writes to a ContactRate table. Runs from a button
 * press, under the session of the person who pressed it — which is what makes
 * `updated_by` mean something on the tables that have it.
 *
 * @param array{id:int, username:string} $user
 * @return array{card:array} on success
 */
function nova_apply_write(PDO $pdo, array $user, int $id, bool $confirm): array
{
    $pdo->beginTransaction();

    try {
        // Locked for the duration: two tabs on the same card, or a double-click
        // on a slow connection, would otherwise both find it pending and apply
        // the change twice — which for a create means two records.
        $stmt = $pdo->prepare('SELECT * FROM ai_record_writes WHERE id = ? AND user_id = ? FOR UPDATE');
        $stmt->execute([$id, $user['id']]);
        $row = $stmt->fetch();

        if (!$row) {
            $pdo->rollBack();
            // 404 rather than 403: someone else's proposal is not theirs to know
            // about. Confirmation stays with the person who asked for the change.
            json_error(404, 'not_found');
        }

        $status = nova_write_status($row);
        if ($status !== 'pending') {
            $pdo->rollBack();
            json_error(409, 'already_decided', match ($status) {
                'applied'   => 'รายการนี้ยืนยันไปแล้ว',
                'cancelled' => 'รายการนี้ยกเลิกไปแล้ว',
                default     => 'รายการนี้หมดอายุแล้ว — ให้โนวาเสนอใหม่อีกครั้ง',
            });
        }

        if (!$confirm) {
            $pdo->prepare(
                "UPDATE ai_record_writes SET status = 'cancelled', decided_at = NOW() WHERE id = ?"
            )->execute([$id]);
            $pdo->commit();

            return ['card' => nova_write_card($pdo, $id)];
        }

        $entity = (string)$row['entity'];
        $config = nova_entity($entity);
        $changes = json_decode((string)$row['changes'], true) ?: [];
        $recordId = $row['record_id'] === null ? null : (int)$row['record_id'];

        if ($row['action'] === 'update') {
            $stmt = $pdo->prepare("SELECT * FROM {$config['table']} WHERE id = ? LIMIT 1");
            $stmt->execute([$recordId]);
            $record = $stmt->fetch();

            if (!$record) {
                $pdo->rollBack();
                json_error(409, 'record_gone', 'รายการนี้ถูกลบไปแล้ว');
            }

            // The record moved after Nova read it. Applying anyway would quietly
            // overwrite whoever edited it in ContactRate in the meantime, and
            // they would have no way of knowing — so this stops instead.
            foreach ($changes as $field => $change) {
                $now = nova_normalise_stored($entity, $field, $record[$field] ?? null);
                if (!nova_same_value($now, $change['from'])) {
                    $pdo->rollBack();
                    json_error(
                        409,
                        'stale',
                        'ข้อมูลเปลี่ยนไปแล้วหลังจากโนวาเสนอ ('
                        . $config['fields'][$field]['label']
                        . ') — ให้โนวาดูข้อมูลล่าสุดแล้วเสนอใหม่'
                    );
                }
            }
        }

        [$sql, $args] = nova_write_statement(
            $entity,
            (string)$row['action'],
            $recordId,
            $changes,
            $user['username']
        );
        $pdo->prepare($sql)->execute($args);

        if ($row['action'] === 'create') {
            $recordId = (int)$pdo->lastInsertId();
        }

        $pdo->prepare(
            "UPDATE ai_record_writes
                SET status = 'applied', decided_at = NOW(), record_id = ?
              WHERE id = ?"
        )->execute([$recordId, $id]);

        $pdo->commit();
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('nova: applying write ' . $id . ' failed: ' . $e->getMessage());
        json_error(500, 'write_failed', 'บันทึกลง ContactRate ไม่สำเร็จ');
    }

    // Read back outside the transaction, so the card reflects what is committed.
    return ['card' => nova_write_card($pdo, $id)];
}
