<?php
/**
 * Images attached to a question: validation, storage, and the content blocks
 * the Messages API wants back.
 *
 * What an image costs is set by its dimensions, not its file size: the model
 * bills roughly (width × height) / 750 tokens for it. A phone screenshot is
 * about 4,000 tokens — around a quarter of a baht at current Sonnet rates,
 * meaning a turn with a picture in it costs about half again what the same
 * question costs without one. That is affordable once. It is not affordable
 * ten times, which is what happens if every later turn resends the same
 * picture, so `nova_history_content` below stops replaying old ones.
 *
 * Re-encoding here would need GD and would buy nothing anyway — recompressing
 * a JPEG changes its byte count and not its pixel count, so the token bill is
 * identical. The browser resizes before uploading (see src/lib/image-resize.js);
 * this file only refuses what arrives too big.
 */

declare(strict_types=1);

/**
 * Sonnet 5 reads up to 2576px on the long edge and downscales anything larger
 * itself, so pixels past this line are paid for in upload time and bought
 * nothing. The browser caps there; this is the backstop for anything that
 * posts to the endpoint directly.
 */
const NOVA_IMAGE_MAX_EDGE = 2576;

/** Per question. Four screenshots is already ~16,000 tokens of prompt. */
const NOVA_IMAGE_MAX_COUNT = 4;

/** Per image, after the browser has resized. Also the Anthropic limit. */
const NOVA_IMAGE_MAX_BYTES = 5 * 1024 * 1024;

/**
 * How many earlier questions still carry their pictures into the prompt.
 *
 * History is replayed in full on every turn, so without a cap a photo attached
 * on Monday is re-billed on every question asked after it — twenty turns later
 * that one image has been paid for twenty times. Two is enough for "and what
 * about the second column", which is the follow-up people actually ask; older
 * pictures are replaced with a line saying they were attached and can be
 * attached again.
 */
const NOVA_IMAGE_REPLAY_TURNS = 2;

/** The formats Anthropic accepts, keyed by what getimagesize() reports. */
const NOVA_IMAGE_TYPES = [
    IMAGETYPE_JPEG => 'image/jpeg',
    IMAGETYPE_PNG  => 'image/png',
    IMAGETYPE_GIF  => 'image/gif',
    IMAGETYPE_WEBP => 'image/webp',
];

/** Absolute path of the upload root, created on first use. */
function nova_image_root(): string
{
    return __DIR__ . '/../uploads';
}

/**
 * Checks every attachment and hands back the decoded bytes.
 *
 * Called before the SSE headers go out, so a rejected image is an ordinary 400
 * the client can show as an error rather than a stream that opens and dies.
 *
 * The declared media type is ignored in favour of what the bytes actually are.
 * A caller can claim anything; getimagesizefromstring reads the header, which
 * both rejects a disguised upload and keeps a file that is not an image at all
 * from ever reaching the disk.
 *
 * @param  array $images  raw `images` from the request body
 * @return list<array{data:string,media_type:string,width:int,height:int}>
 * @throws RuntimeException with a message meant for staff to read
 */
function nova_validate_images(array $images): array
{
    if (count($images) > NOVA_IMAGE_MAX_COUNT) {
        throw new RuntimeException('แนบรูปได้ไม่เกิน ' . NOVA_IMAGE_MAX_COUNT . ' รูปต่อข้อความ');
    }

    $out = [];
    foreach ($images as $i => $image) {
        $label = 'รูปที่ ' . ($i + 1);

        if (!is_array($image) || !is_string($image['data'] ?? null)) {
            throw new RuntimeException("$label ส่งมาไม่ครบ");
        }

        // The browser sends the payload of a data: URL, but a caller that pasted
        // the whole thing should get a working request rather than a puzzle.
        $raw = $image['data'];
        if (($comma = strpos($raw, ',')) !== false && str_starts_with($raw, 'data:')) {
            $raw = substr($raw, $comma + 1);
        }

        $bytes = base64_decode($raw, true);
        if ($bytes === false || $bytes === '') {
            throw new RuntimeException("$label เสียหาย อ่านไม่ได้");
        }
        if (strlen($bytes) > NOVA_IMAGE_MAX_BYTES) {
            throw new RuntimeException("$label ใหญ่เกิน 5 MB");
        }

        $info = @getimagesizefromstring($bytes);
        if (!$info || !isset(NOVA_IMAGE_TYPES[$info[2]])) {
            throw new RuntimeException("$label ไม่ใช่ไฟล์ภาพที่รองรับ (JPEG, PNG, GIF, WebP)");
        }

        [$width, $height] = $info;
        if (max($width, $height) > NOVA_IMAGE_MAX_EDGE) {
            throw new RuntimeException(
                "$label ใหญ่เกิน " . NOVA_IMAGE_MAX_EDGE . ' พิกเซล ย่อก่อนแนบ'
            );
        }

        $out[] = [
            'data'       => $bytes,
            'media_type' => NOVA_IMAGE_TYPES[$info[2]],
            'width'      => (int)$width,
            'height'     => (int)$height,
        ];
    }

    return $out;
}

/**
 * Writes validated images to disk and records them against a message.
 *
 * Filenames are generated here and never derived from anything the caller sent,
 * so no request can steer where a file lands. Foldered by month to keep any one
 * directory small enough to list.
 *
 * @param  list<array{data:string,media_type:string,width:int,height:int}> $images
 * @return list<int>  the new ai_message_images ids, in the order given
 */
function nova_store_images(PDO $pdo, int $messageId, array $images): array
{
    if (!$images) {
        return [];
    }

    $folder = date('Y/m');
    $dir = nova_image_root() . '/' . $folder;
    if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
        throw new RuntimeException('เขียนไฟล์ภาพไม่ได้');
    }

    $stmt = $pdo->prepare(
        'INSERT INTO ai_message_images
             (message_id, path, media_type, width, height, bytes)
         VALUES (?, ?, ?, ?, ?, ?)'
    );

    $ids = [];
    foreach ($images as $image) {
        $ext = match ($image['media_type']) {
            'image/png'  => 'png',
            'image/gif'  => 'gif',
            'image/webp' => 'webp',
            default      => 'jpg',
        };
        $name = bin2hex(random_bytes(16)) . '.' . $ext;

        if (@file_put_contents("$dir/$name", $image['data']) === false) {
            throw new RuntimeException('เขียนไฟล์ภาพไม่ได้');
        }

        $stmt->execute([
            $messageId,
            "$folder/$name",
            $image['media_type'],
            $image['width'],
            $image['height'],
            strlen($image['data']),
        ]);
        $ids[] = (int)$pdo->lastInsertId();
    }

    return $ids;
}

/**
 * Carries images from a question being rewritten onto its replacement.
 *
 * Editing supersedes the old row, and its image rows would go with it — so a
 * corrected typo would silently drop the screenshot the question was about.
 * The new rows point at the same files rather than copying them; nothing
 * deletes an upload, so two rows sharing one file is safe.
 *
 * Ids are checked against the conversation the caller already owns, so an id
 * belonging to somebody else's chat re-links nothing.
 *
 * @param  list<int> $ids  ai_message_images ids
 * @return list<int>       the ids of the new rows
 */
function nova_relink_images(PDO $pdo, int $conversationId, int $messageId, array $ids): array
{
    $ids = array_values(array_filter(array_map('intval', $ids)));
    if (!$ids) {
        return [];
    }
    $ids = array_slice($ids, 0, NOVA_IMAGE_MAX_COUNT);

    $slots = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $pdo->prepare(
        "SELECT img.path, img.media_type, img.width, img.height, img.bytes
           FROM ai_message_images img
           JOIN ai_messages msg ON msg.id = img.message_id
          WHERE img.id IN ($slots) AND msg.conversation_id = ?
          ORDER BY img.id"
    );
    $stmt->execute([...$ids, $conversationId]);

    $insert = $pdo->prepare(
        'INSERT INTO ai_message_images
             (message_id, path, media_type, width, height, bytes)
         VALUES (?, ?, ?, ?, ?, ?)'
    );

    $new = [];
    foreach ($stmt->fetchAll() as $row) {
        $insert->execute([
            $messageId, $row['path'], $row['media_type'],
            $row['width'], $row['height'], $row['bytes'],
        ]);
        $new[] = (int)$pdo->lastInsertId();
    }

    return $new;
}

/**
 * Image rows for a set of messages, grouped by message id.
 *
 * One query rather than one per message: the history read is on the critical
 * path of every question.
 *
 * @param  list<int|string> $messageIds
 * @return array<int, list<array>>
 */
function nova_load_images(PDO $pdo, array $messageIds): array
{
    $ids = array_values(array_unique(array_filter(array_map('intval', $messageIds))));
    if (!$ids) {
        return [];
    }

    $slots = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $pdo->prepare(
        "SELECT id, message_id, path, media_type, width, height
           FROM ai_message_images
          WHERE message_id IN ($slots)
          ORDER BY id"
    );
    $stmt->execute($ids);

    $grouped = [];
    foreach ($stmt->fetchAll() as $row) {
        $grouped[(int)$row['message_id']][] = $row;
    }
    return $grouped;
}

/**
 * Builds the `image` content blocks for one message's attachments.
 *
 * A file that has gone missing is skipped rather than fatal: the question and
 * the rest of the thread are still answerable, and a hard failure here would
 * make one lost upload break a whole conversation for good.
 *
 * @param  list<array> $rows  rows from nova_load_images
 * @return list<array>        Messages API content blocks
 */
function nova_image_blocks(array $rows): array
{
    $blocks = [];
    foreach ($rows as $row) {
        $file = nova_image_root() . '/' . $row['path'];
        $bytes = is_file($file) ? @file_get_contents($file) : false;

        if ($bytes === false) {
            error_log('nova: missing upload ' . $row['path']);
            continue;
        }

        $blocks[] = [
            'type'   => 'image',
            'source' => [
                'type'       => 'base64',
                'media_type' => $row['media_type'],
                'data'       => base64_encode($bytes),
            ],
        ];
    }
    return $blocks;
}

/**
 * The content for one history turn — a plain string when there is nothing
 * attached, an image-and-text block list when there is.
 *
 * `$replay` decides whether the pictures themselves go back to the model or
 * whether the turn is described in words instead. Saying so in the transcript
 * matters: without it the model sees a question about "this rate sheet" with
 * no rate sheet in front of it and answers from the surrounding conversation
 * as though it could still see one.
 *
 * @param  list<array> $rows  rows from nova_load_images
 * @return string|list<array>
 */
function nova_history_content(string $text, array $rows, bool $replay): string|array
{
    if (!$rows) {
        return $text;
    }

    $blocks = $replay ? nova_image_blocks($rows) : [];

    if (!$blocks) {
        $text = trim($text . sprintf(
            "\n\n[แนบรูปไว้ %d รูปในข้อความนี้ ตอนนี้ไม่ได้ส่งรูปมาด้วย " .
            'ถ้าต้องดูอีกครั้งให้บอกผู้ใช้แนบใหม่]',
            count($rows)
        ));
    }

    // An empty text block is rejected by the API, and a question can be nothing
    // but a screenshot.
    if ($text !== '') {
        $blocks[] = ['type' => 'text', 'text' => $text];
    }

    return $blocks ?: $text;
}

/**
 * Which of the given message ids still replay their images: the newest few.
 *
 * Sorted here rather than trusting the caller — the grouping in
 * nova_load_images is ordered by image id, and an edit re-links an old file
 * under a new one, so image order and message order are not the same thing.
 *
 * @param  list<int>        $withImages  message ids that have attachments
 * @return array<int, true>              lookup keyed by message id
 */
function nova_replayable_ids(array $withImages): array
{
    $ids = array_map('intval', $withImages);
    sort($ids);

    $recent = array_slice($ids, -NOVA_IMAGE_REPLAY_TURNS);
    return $recent ? array_fill_keys($recent, true) : [];
}
