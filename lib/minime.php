<?php
// Optional MiniMe-T5 client (DwemerDistro port 8082). Matches how CHIM/HerikaServer calls it:
// GET {base}/extract?text=..., /detectMemory, /command, /topic, /task (see lib/minimet5_service.php).
// Off by default (minime.enabled). When the service is down, every call returns a soft miss and
// the turn keeps its current behaviour. This file only defines functions and constants.

require_once __DIR__ . '/app.php';
require_once __DIR__ . '/events.php';

const MINIME_DEFAULT_URL = 'http://127.0.0.1:8082';
const MINIME_MAX_RESPONSE_BYTES = 16384;
const MINIME_MAX_INPUT_CHARS = 500;
const MINIME_DEFAULT_TIMEOUT = 2;

// CHIM /command names (Skyrim) plus our own action names, mapped onto the Minecraft allowlist.
// "Talk" (and unknowns) mean no action hint; the model decides, as it does when Minime is off.
const MINIME_ACTION_MAP = [
    'talk' => null,
    'follow' => 'follow_player',
    'followme' => 'follow_player',
    'follow_player' => 'follow_player',
    'takeaseat' => 'hold_position',
    'sit' => 'hold_position',
    'wait' => 'hold_position',
    'holdposition' => 'hold_position',
    'hold_position' => 'hold_position',
    'attack' => 'attack_hostile',
    'attack_hostile' => 'attack_hostile',
    'attack_target' => 'attack_target',
    'exchangeitems' => 'give_item',
    'give_item' => 'give_item',
    'go_to' => 'go_to',
    'mount' => 'mount',
    'dismount' => 'dismount',
    'equip' => 'equip',
    'use_item' => 'use_item',
    'pick_up' => 'pick_up',
    'inspect_item' => 'inspect_item',
    'talk_to_villager' => 'talk_to_villager',
];

final class MinimeError extends RuntimeException
{
}

// The minime settings when they are switched on, otherwise null.
function minime_settings(array $config): ?array
{
    $settings = $config['minime'] ?? null;
    return is_array($settings) && !empty($settings['enabled']) ? $settings : null;
}

// A validated http(s) base URL with no trailing slash, or the DwemerDistro default.
function minime_base_url(array $settings): string
{
    $url = rtrim(trim((string)($settings['url'] ?? '')), '/');
    $parts = $url === '' ? false : parse_url($url);
    if (!is_array($parts) || !in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true)
        || ($parts['host'] ?? '') === '' || isset($parts['user']) || isset($parts['pass'])) {
        return MINIME_DEFAULT_URL;
    }
    return $url;
}

function minime_timeout(array $settings): int
{
    return max(1, min(10, (int)($settings['timeout_seconds'] ?? MINIME_DEFAULT_TIMEOUT)));
}

// GET {base}/{path}?text=..., matching CHIM's _minimeServiceEndpoint(). Throws MinimeError.
function minime_get(array $settings, string $path, string $text): array
{
    $text = mb_substr(trim($text), 0, MINIME_MAX_INPUT_CHARS);
    if ($text === '') {
        throw new MinimeError('bad_input');
    }
    $url = minime_base_url($settings) . '/' . ltrim($path, '/') . '?' . http_build_query(['text' => $text], '', '&', PHP_QUERY_RFC3986);
    try {
        [$status, $body] = http_get($url, ['Accept: application/json'], minime_timeout($settings), MINIME_MAX_RESPONSE_BYTES);
    } catch (Throwable $error) {
        app_log('minime request failed: ' . $error->getMessage());
        throw new MinimeError('request_failed');
    }
    if ($status !== 200) {
        app_log("minime returned HTTP $status for $path");
        throw new MinimeError($status === 404 ? 'not_found' : 'http_error');
    }
    $data = json_decode($body, true, 8);
    if (!is_array($data) || $data === [] || array_is_list($data) || isset($data['error'])) {
        throw new MinimeError('bad_response');
    }
    return $data;
}

// A health probe like the DwemerDistro launcher test: GET /extract?text=hello must return
// is_memory_recall. Used by the dashboard; turns just call extract and fall back on failure.
function minime_probe(array $settings): array
{
    try {
        $data = minime_get($settings, 'extract', 'hello');
    } catch (MinimeError $error) {
        return ['ok' => false, 'error' => $error->getMessage(), 'detail' => 'Minime did not answer /extract.'];
    }
    if (!array_key_exists('is_memory_recall', $data)) {
        return ['ok' => false, 'error' => 'bad_response', 'detail' => 'Minime answered, but not with a classification result.'];
    }
    return ['ok' => true, 'error' => null, 'detail' => 'Minime classified a test phrase.', 'data' => $data];
}

// CHIM DataSearchMemory: /extract returns is_memory_recall "Yes"|"No" and optional generated_tags.
function minime_extract(array $settings, string $text): array
{
    return minime_get($settings, 'extract', $text);
}

// CHIM DataSearchMemoryByVector uses /detectMemory (extract with the detector only).
function minime_detect_memory(array $settings, string $text): array
{
    return minime_get($settings, 'detectMemory', $text);
}

// CHIM's /command is deprecated in current HerikaServer, but the service may still offer it.
function minime_command(array $settings, string $text): array
{
    return minime_get($settings, 'command', $text);
}

function minime_topic(array $settings, string $text): array
{
    return minime_get($settings, 'topic', $text);
}

// Map a MiniMe /command name onto an allowed action, or null when the model should decide alone.
function minime_map_action(mixed $command, array $allowedActions): ?string
{
    if (!is_string($command) || trim($command) === '') {
        return null;
    }
    $key = strtolower(preg_replace('/[^A-Za-z0-9_]+/', '', $command) ?? '');
    if ($key === '' || $key === 'talk' || $key === 'null') {
        return null;
    }
    $name = MINIME_ACTION_MAP[$key] ?? (in_array($command, $allowedActions, true) ? $command : null);
    return $name !== null && in_array($name, $allowedActions, true) ? $name : null;
}

// One turn's MiniMe pass: whether to run a memory search, optional tags to add to the query,
// and an optional action hint. Never throws; a soft miss leaves recall unknown (current behaviour)
// and gives no action hint, so the language model is the fallback.
function minime_classify(array $settings, string $text, array $allowedActions = []): array
{
    $done = ['recall' => null, 'tags' => '', 'command' => null, 'action' => null, 'error' => null];
    try {
        $extracted = minime_extract($settings, $text);
    } catch (MinimeError $error) {
        $done['error'] = $error->getMessage();
        return $done;
    }
    $recall = $extracted['is_memory_recall'] ?? null;
    if (is_string($recall)) {
        $done['recall'] = strcasecmp($recall, 'No') === 0 ? false : (strcasecmp($recall, 'Yes') === 0 ? true : null);
    }
    $tags = $extracted['generated_tags'] ?? '';
    $done['tags'] = is_string($tags) ? mb_substr(trim($tags), 0, 200) : '';
    try {
        $command = minime_command($settings, $text);
        $name = $command['is_command'] ?? null;
        $done['command'] = is_string($name) ? mb_substr($name, 0, 40) : null;
        $done['action'] = minime_map_action($done['command'], $allowedActions);
    } catch (MinimeError $error) {
        // /command is optional (deprecated in current CHIM). extract already succeeded.
        if ($error->getMessage() !== 'not_found') {
            app_log('minime /command skipped: ' . $error->getMessage());
        }
    }
    return $done;
}
