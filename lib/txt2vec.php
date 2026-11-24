<?php
// Optional TXT2VEC / txtai client (DwemerDistro port 8082). Matches how CHIM stores and
// searches memories: POST {base}/embed with {"text":"..."} and read "embedding" (see
// HerikaServer lib/memory_helper_vectordb.php and DataSearchMemoryByVector).
// Off by default (txt2vec.enabled). This file only defines functions and constants.

require_once __DIR__ . '/app.php';
require_once __DIR__ . '/embeddings.php';

const TXT2VEC_DEFAULT_URL = 'http://127.0.0.1:8082';
const TXT2VEC_MAX_RESPONSE_BYTES = 4000000;
const TXT2VEC_DEFAULT_TIMEOUT = 5;

// The txt2vec settings when they are switched on, otherwise null.
function txt2vec_settings(array $config): ?array
{
    $settings = $config['txt2vec'] ?? null;
    return is_array($settings) && !empty($settings['enabled']) ? $settings : null;
}

// A validated http(s) base URL with no trailing slash, or the DwemerDistro default.
function txt2vec_base_url(array $settings): string
{
    $url = rtrim(trim((string)($settings['url'] ?? '')), '/');
    // A pasted full /embed endpoint is accepted and trimmed back to the base, so either form works.
    if (str_ends_with(strtolower($url), '/embed')) {
        $url = substr($url, 0, -strlen('/embed'));
    }
    $parts = $url === '' ? false : parse_url($url);
    if (!is_array($parts) || !in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true)
        || ($parts['host'] ?? '') === '' || isset($parts['user']) || isset($parts['pass'])) {
        return TXT2VEC_DEFAULT_URL;
    }
    return $url;
}

function txt2vec_timeout(array $settings): int
{
    return max(1, min(30, (int)($settings['timeout_seconds'] ?? TXT2VEC_DEFAULT_TIMEOUT)));
}

// One unit-length vector for $text. Throws EmbeddingError with a short code, never retries,
// and never logs the text or the provider body.
function txt2vec_embed(array $settings, string $text): array
{
    $text = mb_substr($text, 0, EMBEDDING_MAX_INPUT_CHARS);
    if (trim($text) === '') {
        throw new EmbeddingError('bad_input');
    }
    $url = txt2vec_base_url($settings) . '/embed';
    $dims = max(0, min(EMBEDDING_MAX_DIMS, (int)($settings['dims'] ?? 0)));
    $body = json_encode(['text' => $text], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    try {
        [$status, $response] = http_post($url, ['Content-Type: application/json', 'Accept: application/json'],
            $body, txt2vec_timeout($settings), TXT2VEC_MAX_RESPONSE_BYTES);
    } catch (Throwable $error) {
        app_log('txt2vec request failed: ' . $error->getMessage());
        throw new EmbeddingError('request_failed');
    }
    if ($status !== 200) {
        app_log("txt2vec returned HTTP $status");
        throw new EmbeddingError('http_error');
    }
    $data = json_decode($response, true, 8);
    if (!is_array($data) || !array_key_exists('embedding', $data)) {
        throw new EmbeddingError('bad_response');
    }
    $raw = $data['embedding'];
    if (is_string($raw)) {
        $raw = json_decode($raw, true, 3);
    }
    $vector = embedding_validate($raw, $dims);
    if ($vector === null) {
        throw new EmbeddingError($dims > 0 ? 'bad_vector_or_dims' : 'bad_vector');
    }
    return $vector;
}

// A health probe: POST /embed {"text":"hello"} must return a usable vector.
function txt2vec_probe(array $settings): array
{
    try {
        $vector = txt2vec_embed($settings, 'hello');
    } catch (EmbeddingError $error) {
        return ['ok' => false, 'error' => $error->getMessage(), 'detail' => 'TXT2VEC did not return an embedding.'];
    }
    return ['ok' => true, 'error' => null, 'detail' => 'TXT2VEC returned a ' . count($vector) . '-dimension vector.',
        'dims' => count($vector)];
}

// embeddings.provider 'txt2vec' (Memory settings) or the txt2vec.enabled section (this page)
// become one settings array for embed_texts() and memory-vector search. TXT2VEC wins when both
// are on, because that is the local DwemerDistro service this feature is for.
function embedding_active_settings(array $config): ?array
{
    $txt = txt2vec_settings($config);
    if ($txt !== null) {
        return [
            'enabled' => true,
            'provider' => 'txt2vec',
            'url' => txt2vec_base_url($txt),
            'timeout_seconds' => txt2vec_timeout($txt),
            'dims' => max(0, min(EMBEDDING_MAX_DIMS, (int)($txt['dims'] ?? 0))),
            'auto_detect' => !array_key_exists('auto_detect', $txt) || !empty($txt['auto_detect']),
        ];
    }
    return embedding_settings($config);
}
