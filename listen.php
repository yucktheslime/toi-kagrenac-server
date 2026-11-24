<?php
// OPTIONAL VOICE: POST raw WAV audio (Content-Type: audio/wav) and get {"text": "..."} back.
// lib/stt.php sends it to stt.provider: 'parakeet' (DwemerDistro Parakeet, port 8022,
// OpenAI-style /v1/audio/transcriptions), 'fasterwhisper' (DwemerDistro, port 9876),
// 'openai' (any OpenAI-compatible /v1/audio/transcriptions), 'whispercpp' (/inference) or
// 'auto' (finds the DwemerDistro service on this machine).
// When STT is off, fails or hears nothing usable, the reply is a JSON error and the game
// should let the player type.
// Optional headers (protocol 1 additions): X-Request-Id (made here when missing) and
// X-Parent-Request-Id (the turn the transcript is for). After the reply, one connector-audit
// row is written with ids, status and timing only: never the audio or the transcript.

require_once __DIR__ . '/lib/app.php';
require_once __DIR__ . '/lib/audit.php';
require_once __DIR__ . '/lib/stt.php';

const MAX_UPLOAD_BYTES = 10000000;
const MAX_HEARD_CHARS = 1000;  // same limit as turn.php text

$started = (float)($_SERVER['REQUEST_TIME_FLOAT'] ?? microtime(true));
$config = start_request('POST');
$requestId = trace_start($_SERVER['HTTP_X_REQUEST_ID'] ?? null);
$parentId = ($_SERVER['HTTP_X_PARENT_REQUEST_ID'] ?? '') !== ''
    ? require_id($_SERVER['HTTP_X_PARENT_REQUEST_ID'], 'X-Parent-Request-Id', 8) : null;
$stt = $config['stt'] ?? [];
// Configs from before stt.provider existed are faster-whisper.
$provider = SttService::provider($stt);
$audit = ['kind' => 'stt', 'provider' => $provider, 'request_id' => $requestId, 'parent_request_id' => $parentId];
// The reply goes first; the audit row is written after it and can never change it.
$finish = function (int $status, array $data, array $row) use ($config, $started, &$audit): never {
    send_json_then($status, $data, fn() => audit_record($config, $row + ['duration_ms' => event_duration_ms($started)] + $audit));
};
$failed = fn(int $status, string $code, string $message, string $auditStatus, ?string $detail = null) => $finish($status,
    ['ok' => false, 'error' => ['code' => $code, 'message' => $message]],
    ['status' => $auditStatus, 'error_code' => $code, 'detail' => $detail]);
if (empty($stt['enabled'])) {
    $failed(503, 'stt_disabled', 'Speech to text is turned off on the server.', 'disabled');
}

$wav = read_body(MAX_UPLOAD_BYTES);
if (!is_wav($wav)) {
    fail(400, 'bad_audio', 'The body must be WAV audio.');
}
$result = SttService::transcribe($stt, $wav);
$audit['provider'] = $result['provider'];
if (!$result['ok']) {
    app_log('STT failed: ' . $result['detail']);
    $message = in_array($result['detail'], ['empty transcript', 'transcript too long'], true)
        ? 'No usable speech was recognised. Type instead.' : 'Speech to text failed. Type instead.';
    $failed(502, 'stt_unavailable', $message, 'failed', $result['detail']);
}
$text = clean_line($result['text']);
if ($text === '' || mb_strlen($text) > MAX_HEARD_CHARS) {
    app_log('STT text was empty or longer than ' . MAX_HEARD_CHARS . ' characters');
    $failed(502, 'stt_unavailable', 'No usable speech was recognised. Type instead.', 'failed', 'empty or too long');
}

$finish(200, ['ok' => true, 'text' => $text], ['status' => 'ok', 'detail' => 'WAV ' . strlen($wav) . ' bytes, ' . mb_strlen($text) . ' characters heard']);
