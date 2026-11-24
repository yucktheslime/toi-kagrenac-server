<?php
// Speech to text for listen.php (and the dashboard test): sends a WAV to the configured
// service and returns the transcript.
//
// Providers (stt.provider):
//   auto          DwemerDistro services on this machine, tried in order: Parakeet (8022),
//                 faster-whisper (9876), whisper.cpp server (8080). With stt.url set, the URL's
//                 path picks the request format instead.
//   parakeet      DwemerDistro Parakeet: OpenAI-style POST /v1/audio/transcriptions, form
//                 fields "file" and "model" (port 8022).
//   fasterwhisper DwemerDistro faster-whisper: POST /api/v0/transcribe, one file field
//                 (stt.form_field, default "audio_file"; port 9876).
//   openai        Any OpenAI-compatible /v1/audio/transcriptions (OpenAI, faster-whisper-server,
//                 LocalAI, speaches): "file", "model", response_format=json and, when
//                 stt.api_key is set, a Bearer key.
//   whispercpp    whisper.cpp's example server: POST /inference, "file" and response_format=json.
// Replies must be JSON with "text" (whisper.cpp may also send plain text).

require_once __DIR__ . '/app.php';

final class SttService
{
    public const PROVIDERS = ['auto', 'parakeet', 'fasterwhisper', 'openai', 'whispercpp'];
    public const DEFAULT_URLS = [
        'auto' => '',
        'parakeet' => 'http://127.0.0.1:8022/v1/audio/transcriptions',
        'fasterwhisper' => 'http://127.0.0.1:9876/api/v0/transcribe',
        'openai' => '',
        'whispercpp' => 'http://127.0.0.1:8080/inference',
    ];
    /** What auto tries, in order, when stt.url is empty. */
    public const AUTO_ORDER = ['parakeet', 'fasterwhisper', 'whispercpp'];
    public const MAX_TEXT = 1000;

    /** Provider of a saved stt section: configs from before stt.provider existed are faster-whisper. */
    public static function provider(array $stt): string
    {
        $p = (string)($stt['provider'] ?? 'fasterwhisper');
        return in_array($p, self::PROVIDERS, true) ? $p : 'unknown';
    }

    /** The request format a URL needs (for auto with a URL). */
    public static function providerForUrl(string $url): string
    {
        $path = strtolower((string)parse_url($url, PHP_URL_PATH));
        if (str_contains($path, '/api/v0/transcribe')) {
            return 'fasterwhisper';
        }
        if (str_ends_with(rtrim($path, '/'), '/inference')) {
            return 'whispercpp';
        }
        return 'openai';
    }

    /** Multipart fields for one request. */
    public static function form(string $provider, array $stt, string $wav): array
    {
        $file = new CURLStringFile($wav, 'speech.wav', 'audio/wav');
        $model = (string)($stt['model'] ?? 'whisper-1');
        return match ($provider) {
            'parakeet' => ['file' => $file, 'model' => $model],
            'fasterwhisper' => [(string)($stt['form_field'] ?? 'audio_file') => $file],
            'whispercpp' => ['file' => $file, 'response_format' => 'json', 'temperature' => '0.0'],
            default => ['file' => $file, 'model' => $model, 'response_format' => 'json'],
        };
    }

    /** The transcript in a reply body, or null. */
    public static function parse(string $body): ?string
    {
        $trimmed = trim($body);
        if ($trimmed === '') {
            return null;
        }
        $json = json_decode($trimmed, true);
        if (is_array($json)) {
            foreach (['text', 'transcript', 'result'] as $key) {
                if (isset($json[$key]) && is_string($json[$key])) {
                    return self::clean($json[$key]);
                }
            }
            return null;
        }
        if ($trimmed[0] === '<' || $trimmed[0] === '{' || $trimmed[0] === '[') {
            return null;
        }
        return self::clean($trimmed);
    }

    /** One line: whisper's [BLANK_AUDIO]-style tags removed, whitespace collapsed; null when nothing is left. */
    public static function clean(string $text): ?string
    {
        if (!mb_check_encoding($text, 'UTF-8')) {
            return null;
        }
        $text = preg_replace('/\[[A-Z_ ]{3,30}\]|\((?:[a-z ]{3,30})\)/u', ' ', $text) ?? '';
        $text = trim(preg_replace('/\s+/u', ' ', $text) ?? '');
        if ($text === '' || !preg_match('/[\p{L}\p{N}]/u', $text)) {
            return null;
        }
        return $text;
    }

    /**
     * Transcribe. Returns ['ok' => true, 'text', 'provider', 'url'] or
     * ['ok' => false, 'detail', 'provider', 'url', 'status'] (detail never holds the audio or a key).
     */
    public static function transcribe(array $stt, string $wav): array
    {
        $provider = self::provider($stt);
        $url = trim((string)($stt['url'] ?? ''));
        if ($provider === 'unknown') {
            return ['ok' => false, 'detail' => 'unknown provider', 'provider' => (string)($stt['provider'] ?? ''), 'url' => $url, 'status' => 0];
        }
        $timeout = (int)($stt['timeout_seconds'] ?? 60);
        if ($provider !== 'auto') {
            return self::call($provider, $url !== '' ? $url : self::DEFAULT_URLS[$provider], $stt, $wav, $timeout);
        }
        if ($url !== '') {
            return self::call(self::providerForUrl($url), $url, $stt, $wav, $timeout);
        }
        $last = ['ok' => false, 'detail' => 'no speech service answers on ports 8022 (Parakeet), 9876 (faster-whisper) or 8080 (whisper.cpp)',
            'provider' => 'auto', 'url' => '', 'status' => 0];
        foreach (self::AUTO_ORDER as $candidate) {
            $candidateUrl = self::DEFAULT_URLS[$candidate];
            if (!self::listening($candidateUrl)) {
                continue;
            }
            $result = self::call($candidate, $candidateUrl, $stt, $wav, $timeout);
            if ($result['ok'] || $result['status'] !== 404) {
                return $result;
            }
            $last = $result;
        }
        return $last;
    }

    /** Which of the auto candidates accept a TCP connection now (for the dashboard). */
    public static function probe(): array
    {
        $found = [];
        foreach (self::AUTO_ORDER as $candidate) {
            $found[$candidate] = self::listening(self::DEFAULT_URLS[$candidate]);
        }
        return $found;
    }

    private static function listening(string $url): bool
    {
        $host = (string)parse_url($url, PHP_URL_HOST);
        $port = (int)(parse_url($url, PHP_URL_PORT) ?: 80);
        $socket = @fsockopen($host, $port, $errno, $error, 0.3);
        if ($socket === false) {
            return false;
        }
        fclose($socket);
        return true;
    }

    private static function call(string $provider, string $url, array $stt, string $wav, int $timeout): array
    {
        $headers = [];
        $key = (string)($stt['api_key'] ?? '');
        if ($provider === 'openai' && $key !== '') {
            $headers[] = 'Authorization: Bearer ' . $key;
        }
        try {
            [$status, $response] = http_post($url, $headers, self::form($provider, $stt, $wav), $timeout, 100000);
        } catch (Throwable $error) {
            return ['ok' => false, 'detail' => $error->getMessage(), 'provider' => $provider, 'url' => $url, 'status' => 0];
        }
        $text = $status === 200 ? self::parse($response) : null;
        if ($text === null) {
            return ['ok' => false, 'detail' => $status === 200 ? 'empty transcript' : "provider HTTP $status", 'provider' => $provider, 'url' => $url, 'status' => $status];
        }
        if (mb_strlen($text) > self::MAX_TEXT) {
            return ['ok' => false, 'detail' => 'transcript too long', 'provider' => $provider, 'url' => $url, 'status' => $status];
        }
        return ['ok' => true, 'text' => $text, 'provider' => $provider, 'url' => $url];
    }
}
