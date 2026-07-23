<?php
/**
 * Thin Anthropic Messages API client over cURL.
 *
 * The shared host has no Composer, so the official PHP SDK is not an option —
 * this speaks the wire format directly. It handles exactly what Nova needs:
 * one streaming request, with text deltas handed to a callback as they arrive
 * and the assembled message returned at the end.
 */

declare(strict_types=1);

const NOVA_API_URL     = 'https://api.anthropic.com/v1/messages';
const NOVA_API_VERSION = '2023-06-01';

// Sonnet 5 was chosen over Opus deliberately: the work is fetch-and-format,
// which Sonnet handles, at roughly 40% of the cost. See PROJECT_NOTES.md.
const NOVA_MODEL = 'claude-sonnet-5';

const NOVA_MAX_TOKENS  = 8000;
const NOVA_EFFORT      = 'medium';
const NOVA_MAX_ROUNDS  = 6;

/**
 * Streams one Messages API request.
 *
 * @param callable  $onText        fn(string $delta): void — called per text delta
 * @param ?callable $onServerTool  fn(string $name): void — called when Anthropic
 *                                 starts a server-side tool, so the UI can say
 *                                 what is happening during the pause
 * @return array{content: array, stop_reason: ?string, usage: array}
 * @throws RuntimeException on transport or API error
 */
function anthropic_stream(
    string $apiKey,
    array $messages,
    string $system,
    array $tools,
    callable $onText,
    ?callable $onServerTool = null
): array {
    $payload = [
        'model'      => NOVA_MODEL,
        'max_tokens' => NOVA_MAX_TOKENS,
        'system'     => [[
            'type' => 'text',
            'text' => $system,
            // The system prompt and tool schemas are byte-identical on every
            // request, so caching them turns most of the fixed prefix into
            // cache reads at ~10% of input cost.
            'cache_control' => ['type' => 'ephemeral'],
        ]],
        'messages'      => $messages,
        'tools'         => $tools,
        'stream'        => true,
        // Sonnet 5 runs adaptive thinking by default; naming it is explicit,
        // and `effort` is what actually controls depth and spend.
        'thinking'      => ['type' => 'adaptive'],
        'output_config' => ['effort' => NOVA_EFFORT],
    ];

    // Accumulated across SSE events, keyed by content-block index.
    $blocks = [];
    $stopReason = null;
    $usage = [];
    $apiError = null;
    $buffer = '';
    // An HTTP error is returned as a plain JSON body, not as SSE, so the frame
    // parser never sees it. Keep the raw bytes to report what actually failed.
    $raw = '';

    $handle = curl_init(NOVA_API_URL);
    curl_setopt_array($handle, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE),
        CURLOPT_HTTPHEADER     => [
            'content-type: application/json',
            'x-api-key: ' . $apiKey,
            'anthropic-version: ' . NOVA_API_VERSION,
        ],
        CURLOPT_RETURNTRANSFER => false,
        CURLOPT_TIMEOUT        => 300,
        CURLOPT_WRITEFUNCTION  => function ($ch, string $chunk) use (
            &$buffer, &$raw, &$blocks, &$stopReason, &$usage, &$apiError, $onText, $onServerTool
        ): int {
            if (strlen($raw) < 4096) {
                $raw .= $chunk;
            }
            $buffer .= $chunk;

            // SSE frames are separated by a blank line; a frame can straddle
            // chunk boundaries, so only complete frames are consumed.
            while (($pos = strpos($buffer, "\n\n")) !== false) {
                $frame = substr($buffer, 0, $pos);
                $buffer = substr($buffer, $pos + 2);

                foreach (explode("\n", $frame) as $line) {
                    if (!str_starts_with($line, 'data:')) {
                        continue;
                    }
                    $data = json_decode(trim(substr($line, 5)), true);
                    if (!is_array($data)) {
                        continue;
                    }
                    anthropic_apply_event(
                        $data, $blocks, $stopReason, $usage, $apiError, $onText, $onServerTool
                    );
                }
            }

            return strlen($chunk);
        },
    ]);

    $ok = curl_exec($handle);
    $err = curl_error($handle);
    $status = (int)curl_getinfo($handle, CURLINFO_HTTP_CODE);
    curl_close($handle);

    if ($ok === false) {
        throw new RuntimeException('connection to Anthropic failed: ' . $err);
    }
    if ($apiError !== null) {
        throw new RuntimeException('Anthropic API error: ' . $apiError);
    }
    if ($status >= 400) {
        $detail = json_decode($raw, true)['error']['message'] ?? trim($raw);
        error_log("nova: Anthropic HTTP $status — $raw");
        throw new RuntimeException("Anthropic API returned HTTP $status: $detail");
    }

    ksort($blocks);

    return [
        'content'     => array_values(array_map('anthropic_finalise_block', $blocks)),
        'stop_reason' => $stopReason,
        'usage'       => $usage,
    ];
}

/** Folds a single SSE event into the accumulating message state. */
function anthropic_apply_event(
    array $data,
    array &$blocks,
    ?string &$stopReason,
    array &$usage,
    ?string &$apiError,
    callable $onText,
    ?callable $onServerTool = null
): void {
    switch ($data['type'] ?? '') {
        case 'content_block_start':
            $blocks[$data['index']] = $data['content_block'];
            // tool_use arrives as a stream of JSON fragments; collect them raw.
            $blocks[$data['index']]['_json'] = '';

            // Server tools run inside the request: the stream simply stalls
            // while Anthropic searches. Announce it so the wait is explained.
            if (($data['content_block']['type'] ?? '') === 'server_tool_use' && $onServerTool) {
                $onServerTool((string)($data['content_block']['name'] ?? 'server_tool'));
            }
            break;

        case 'content_block_delta':
            $i = $data['index'];
            $delta = $data['delta'];

            switch ($delta['type'] ?? '') {
                case 'text_delta':
                    $blocks[$i]['text'] = ($blocks[$i]['text'] ?? '') . $delta['text'];
                    $onText($delta['text']);
                    break;

                case 'input_json_delta':
                    $blocks[$i]['_json'] .= $delta['partial_json'];
                    break;

                // Thinking blocks are never shown to staff, but when a turn ends
                // in tool_use they have to be echoed back verbatim — including
                // the signature, which arrives as its own delta. Dropping it
                // makes the next request fail with a 400.
                case 'thinking_delta':
                    $blocks[$i]['thinking'] = ($blocks[$i]['thinking'] ?? '') . $delta['thinking'];
                    break;

                case 'signature_delta':
                    $blocks[$i]['signature'] = ($blocks[$i]['signature'] ?? '') . $delta['signature'];
                    break;

                // Web search always cites. The citation rides the text block it
                // belongs to, so keep it there — the caller reads them off the
                // finished content to build the sources list.
                case 'citations_delta':
                    $blocks[$i]['citations'][] = $delta['citation'];
                    break;
            }
            break;

        case 'message_delta':
            $stopReason = $data['delta']['stop_reason'] ?? $stopReason;
            $usage = array_merge($usage, $data['usage'] ?? []);
            break;

        case 'message_start':
            $usage = array_merge($usage, $data['message']['usage'] ?? []);
            break;

        case 'error':
            $apiError = $data['error']['message'] ?? 'unknown error';
            break;
    }
}

/**
 * Pulls web-search citations off finished content into a url => title map.
 *
 * Anthropic requires cited sources to be shown when search output is put in
 * front of a person, and staff need to judge whether a figure came from a
 * trustworthy page. Keyed by url so a page cited five times is listed once.
 *
 * @param array<string, string> $sources  accumulated across rounds
 */
function anthropic_collect_sources(array $content, array &$sources): void
{
    foreach ($content as $block) {
        foreach ($block['citations'] ?? [] as $citation) {
            $url = $citation['url'] ?? '';
            if ($url !== '' && !isset($sources[$url])) {
                $sources[$url] = (string)($citation['title'] ?? $url);
            }
        }
    }
}

/** Turns an accumulated block into the shape the API expects on the way back. */
function anthropic_finalise_block(array $block): array
{
    // `server_tool_use` streams its input the same way `tool_use` does. We never
    // execute it, but a turn that ends in tool_use or pause_turn is echoed back
    // verbatim — and an input left empty there is a 400.
    if (in_array($block['type'] ?? '', ['tool_use', 'server_tool_use'], true)) {
        $decoded = json_decode($block['_json'] ?: '{}', true);
        $decoded = is_array($decoded) ? $decoded : [];

        // A tool called with no arguments decodes to an empty PHP array, which
        // json_encode writes as `[]`. The API requires `input` to be an object,
        // and rejects the echoed turn with a 400 — so hand back `{}` instead.
        $block['input'] = $decoded === [] ? new stdClass() : $decoded;
    }
    unset($block['_json']);

    return $block;
}
