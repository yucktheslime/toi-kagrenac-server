<?php
// Voice page helper: which TTS service answers for the provider and URL in the form (saved or not),
// its speakers (GET /speakers_list) and the speakers each voice hint picks from.
// POST with the page's CSRF token; sends no text and changes nothing.
require __DIR__ . '/common.php';
require __DIR__ . '/../lib/config_writer.php';
require __DIR__ . '/../lib/speech.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    fail(405, 'method_not_allowed', 'Use POST.');
}
if (!check_csrf()) {
    fail(403, 'csrf', 'This form expired. Reload the page.');
}
session_write_close();
$config = ui_config();
if ($config === null) {
    fail(500, 'server_not_configured', 'The server is not configured.');
}
$provider = is_string($_POST['provider'] ?? null) ? $_POST['provider'] : '';
$url = form_url(is_string($_POST['url'] ?? null) ? $_POST['url'] : '');
$voice = form_name($_POST['voice'] ?? '', 64);
if (!in_array($provider, SpeechService::PROVIDERS, true)) {
    fail(400, 'bad_request', 'Choose a TTS provider from the list.');
}
if ($url === null) {
    fail(400, 'bad_request', 'TTS URL must be an http(s) URL.');
}
$saved = is_array($config['tts'] ?? null) ? $config['tts'] : [];
$tts = ['provider' => $provider, 'url' => $url, 'voice' => $voice ?? ''] + $saved;
send_json(200, SpeechService::describe($config, $tts));
