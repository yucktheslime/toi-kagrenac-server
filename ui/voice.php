<?php
// Optional voice settings (tts and stt sections of the private config) and explicit tests.
// Tests go through api.php to the existing speak.php / listen.php handlers, which add their
// own size and time limits; the server token is added in PHP, never in this page.
require __DIR__ . '/common.php';
require __DIR__ . '/../lib/config_writer.php';
require __DIR__ . '/../lib/speech.php';
require __DIR__ . '/../lib/stt.php';

const TTS_PROVIDERS = [
    'auto' => 'Automatic (DwemerDistro PocketTTS)',
    'xtts' => 'PocketTTS / XTTS API (port 8024, like CHIM)',
    'pockettts' => 'PocketTTS audio.cpp (older, port 8086 /v1/audio/speech)',
    'openai' => 'OpenAI-compatible /v1/audio/speech (WAV)',
];
const TTS_DEFAULT_URLS = [
    'auto' => SpeechService::DEFAULT_URL,
    'xtts' => SpeechService::DEFAULT_URL,
    'pockettts' => SpeechService::AUDIO_CPP_URL,
    'openai' => '',
];
const TTS_LANGUAGES = ['' => 'Keep what PocketTTS has loaded', 'en' => 'English', 'de' => 'German', 'fr' => 'French',
    'es' => 'Spanish', 'it' => 'Italian', 'pt' => 'Portuguese', 'pl' => 'Polish', 'tr' => 'Turkish', 'ru' => 'Russian',
    'nl' => 'Dutch', 'cs' => 'Czech', 'ar' => 'Arabic', 'zh-cn' => 'Chinese', 'ja' => 'Japanese', 'hu' => 'Hungarian',
    'ko' => 'Korean', 'hi' => 'Hindi'];
const STT_PROVIDERS = ['auto' => 'Automatic (finds Parakeet, faster-whisper or whisper.cpp here)',
    'parakeet' => 'Parakeet (DwemerDistro, port 8022)', 'fasterwhisper' => 'faster-whisper (DwemerDistro, port 9876)',
    'openai' => 'OpenAI-compatible /v1/audio/transcriptions', 'whispercpp' => 'whisper.cpp server (/inference)'];
// What speak.php sends to PocketTTS when tts.model / tts.voice are not set.
const POCKETTTS_DEFAULTS = ['model' => 'pocket-tts', 'voice' => 'alba'];
const STT_DEFAULT_URLS = SttService::DEFAULT_URLS;

$config = ui_config();
$tts = is_array($config['tts'] ?? null) ? $config['tts'] : [];
$stt = is_array($config['stt'] ?? null) ? $config['stt'] : [];
// No stt section yet: suggest Parakeet. A saved section without a provider is faster-whisper,
// the same as listen.php.
$sttProvider = 'parakeet';
$sttUrl = STT_DEFAULT_URLS['parakeet'];
if ($stt !== []) {
    $sttProvider = (string)($stt['provider'] ?? 'fasterwhisper');
    $sttUrl = (string)($stt['url'] ?? '');
}
$form = [
    'tts_enabled' => !empty($tts['enabled']),
    // No tts section yet: automatic. A saved section without a provider is PocketTTS, like speak.php.
    'tts_provider' => $tts === [] ? 'auto' : (string)SpeechService::provider($tts),
    'tts_url' => $tts === [] ? SpeechService::DEFAULT_URL : (string)($tts['url'] ?? ''),
    'tts_language' => array_key_exists('language', $tts) ? (string)$tts['language'] : 'en',
    'tts_model' => (string)($tts['model'] ?? ''),
    'tts_voice' => (string)($tts['voice'] ?? ''),
    'tts_timeout' => (string)($tts['timeout_seconds'] ?? 30),
    'stt_enabled' => !empty($stt['enabled']),
    'stt_provider' => $sttProvider,
    'stt_url' => $sttUrl,
    'stt_model' => (string)($stt['model'] ?? 'whisper-1'),
    'stt_field' => (string)($stt['form_field'] ?? 'audio_file'),
    'stt_timeout' => (string)($stt['timeout_seconds'] ?? 60),
];
// New or older configs without a PocketTTS model or voice: show what speak.php would use.
if ($form['tts_provider'] !== 'openai') {
    $form['tts_model'] = $form['tts_model'] !== '' ? $form['tts_model'] : POCKETTTS_DEFAULTS['model'];
    $form['tts_voice'] = $form['tts_voice'] !== '' ? $form['tts_voice'] : POCKETTTS_DEFAULTS['voice'];
}
$message = '';
$errorField = '';
$action = $_POST['action'] ?? '';

function post_text(string $key): string
{
    return is_string($_POST[$key] ?? null) ? trim($_POST[$key]) : '';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $config !== null) {
    if (!check_csrf()) {
        http_response_code(403);
        $message = 'This form expired. Reload and try again.';
    } elseif ($action === 'save_tts') {
        $form = array_merge($form, ['tts_enabled' => form_checked('tts_enabled'), 'tts_provider' => post_text('tts_provider'),
            'tts_url' => post_text('tts_url'), 'tts_model' => post_text('tts_model'), 'tts_voice' => post_text('tts_voice'),
            'tts_language' => post_text('tts_language'), 'tts_timeout' => post_text('tts_timeout')]);
        // Switching provider without editing the URL (no JavaScript): use the new default URL.
        if (isset(TTS_PROVIDERS[$form['tts_provider']]) && ($form['tts_url'] === '' || in_array($form['tts_url'], TTS_DEFAULT_URLS, true))) {
            $form['tts_url'] = TTS_DEFAULT_URLS[$form['tts_provider']];
        }
        // PocketTTS needs both; a blank field saves the documented default, never ''.
        if ($form['tts_provider'] !== 'openai') {
            $form['tts_model'] = $form['tts_model'] !== '' ? $form['tts_model'] : POCKETTTS_DEFAULTS['model'];
            $form['tts_voice'] = $form['tts_voice'] !== '' ? $form['tts_voice'] : POCKETTTS_DEFAULTS['voice'];
        }
        $values = [
            'enabled' => $form['tts_enabled'],
            'provider' => isset(TTS_PROVIDERS[$form['tts_provider']]) ? $form['tts_provider'] : null,
            'url' => form_url($form['tts_url']),
            'model' => form_name($form['tts_model'], 200),
            'voice' => form_name($form['tts_voice'], 64),
            'language' => isset(TTS_LANGUAGES[$form['tts_language']]) ? $form['tts_language'] : null,
            'timeout_seconds' => form_int($form['tts_timeout'], 1, 120),
        ];
        if ($values['provider'] === null) {
            [$errorField, $message] = ['tts_provider', 'Choose a TTS provider from the list.'];
        } elseif ($values['url'] === null || ($values['enabled'] && $values['url'] === '')) {
            [$errorField, $message] = ['tts_url', 'TTS URL must be an http(s) URL (required when enabled).'];
        } elseif ($values['model'] === null || ($values['provider'] === 'openai' && $values['model'] === '')) {
            [$errorField, $message] = ['tts_model', 'Model must be letters, digits and _ . : / @ + - (required for the openai provider).'];
        } elseif ($values['voice'] === null) {
            [$errorField, $message] = ['tts_voice', 'Voice must be at most 64 characters of letters, digits and _ . : / @ + -'];
        } elseif ($values['language'] === null) {
            [$errorField, $message] = ['tts_language', 'Choose a language from the list.'];
        } elseif ($values['timeout_seconds'] === null) {
            [$errorField, $message] = ['tts_timeout', 'Timeout must be 1-120 seconds.'];
        } else {
            session_write_close();
            $message = save_and_redirect(function (array $latest) use ($values): array {
                // tts.api_key and unknown keys stay as they are.
                $latest['tts'] = $values + (is_array($latest['tts'] ?? null) ? $latest['tts'] : []);
                return $latest;
            }, 'voice.php', 'tts');
        }
    } elseif ($action === 'save_stt') {
        $form = array_merge($form, ['stt_enabled' => form_checked('stt_enabled'), 'stt_provider' => post_text('stt_provider'),
            'stt_url' => post_text('stt_url'), 'stt_model' => post_text('stt_model'), 'stt_field' => post_text('stt_field'),
            'stt_timeout' => post_text('stt_timeout')]);
        $provider = isset(STT_PROVIDERS[$form['stt_provider']]) ? $form['stt_provider'] : null;
        // Switching provider without editing the URL (no JavaScript): use the new default URL.
        if ($provider !== null && in_array($form['stt_url'], STT_DEFAULT_URLS, true)) {
            $form['stt_url'] = STT_DEFAULT_URLS[$provider];
        }
        $values = [
            'enabled' => $form['stt_enabled'],
            'provider' => $provider,
            'url' => form_url($form['stt_url']),
            'timeout_seconds' => form_int($form['stt_timeout'], 1, 120),
        ];
        // Each provider has its own extra field; the other one is removed when the provider changes.
        $staleKey = 'model';
        if ($provider === 'fasterwhisper') {
            $values['form_field'] = preg_match('/^[A-Za-z0-9_]{1,64}$/D', $form['stt_field']) ? $form['stt_field'] : null;
        } elseif ($provider === 'whispercpp') {
            $staleKey = '';
        } else {
            $values['model'] = form_name($form['stt_model'], 200, false);
            $staleKey = 'form_field';
        }
        if ($values['provider'] === null) {
            [$errorField, $message] = ['stt_provider', 'Choose an STT provider from the list.'];
        } elseif ($values['url'] === null || ($values['enabled'] && $values['url'] === '' && $provider !== 'auto')) {
            [$errorField, $message] = ['stt_url', 'STT URL must be an http(s) URL (required when enabled, except for Automatic).'];
        } elseif (array_key_exists('model', $values) && $values['model'] === null) {
            [$errorField, $message] = ['stt_model', 'Model is required: letters, digits and _ . : / @ + - (Parakeet accepts whisper-1).'];
        } elseif (array_key_exists('form_field', $values) && $values['form_field'] === null) {
            [$errorField, $message] = ['stt_field', 'Form field must be 1-64 letters, digits or underscores.'];
        } elseif ($values['timeout_seconds'] === null) {
            [$errorField, $message] = ['stt_timeout', 'Timeout must be 1-120 seconds.'];
        } else {
            session_write_close();
            $message = save_and_redirect(function (array $latest) use ($values, $staleKey): array {
                $previous = is_array($latest['stt'] ?? null) ? $latest['stt'] : [];
                $latest['stt'] = $values + $previous;
                if (($previous['provider'] ?? 'fasterwhisper') !== $values['provider'] && $staleKey !== '') {
                    unset($latest['stt'][$staleKey]);
                }
                return $latest;
            }, 'voice.php', 'stt');
        }
    } else {
        $message = 'Unknown form action.';
    }
    if ($message !== '' && http_response_code() === 200) {
        http_response_code(400);
    }
}

function voice_field(string $id, string $errorField): string
{
    return $id === $errorField ? ' aria-invalid="true" aria-describedby="voice-error"' : '';
}

$saved = ['tts' => 'Text to speech settings saved.', 'stt' => 'Speech to text settings saved.'][$_GET['saved'] ?? ''] ?? '';
$title = 'Voice';
$active = 'voice';
require __DIR__ . '/tmpl/header.php';
?>
<p class="chim-panel-intro">Optional voice, off by default. The game always gets text first. Saved to the private server config; the TTS key is set on <a href="api_keys.php">API keys</a>.</p>
<?php if ($config === null): ?>
<section class="chim-panel"><p class="notice" role="alert">Server configuration is missing or invalid.</p></section>
<?php else: ?>
<?php if ($saved !== ''): ?><p class="notice" role="status"><?= e($saved) ?></p><?php endif; ?>
<?php if ($message !== ''): ?><p class="notice" role="alert" id="voice-error"><?= e($message) ?></p><?php endif; ?>
<div class="llm-layout voice-layout">
    <section class="connector-card">
        <h2>Text to speech</h2>
        <form method="post">
            <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
            <input type="hidden" name="action" value="save_tts">
            <div class="setting-row"><span>Status</span><label><input type="checkbox" name="tts_enabled" value="1"<?= $form['tts_enabled'] ? ' checked' : '' ?>> Enabled</label></div>
            <div class="setting-row"><label for="tts_provider">Provider</label><select id="tts_provider" name="tts_provider"<?= voice_field('tts_provider', $errorField) ?>>
                <?php foreach (TTS_PROVIDERS as $value => $label): ?><option value="<?= e($value) ?>"<?= $form['tts_provider'] === $value ? ' selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?>
            </select></div>
            <div class="setting-row"><label for="tts_url">URL</label><input type="url" id="tts_url" name="tts_url" maxlength="300" value="<?= e($form['tts_url']) ?>" placeholder="<?= e(TTS_DEFAULT_URLS[$form['tts_provider']] ?? SpeechService::DEFAULT_URL) ?>"<?= voice_field('tts_url', $errorField) ?>></div>
            <div class="setting-row" id="tts-model-row"<?= in_array($form['tts_provider'], ['auto', 'xtts'], true) ? ' hidden' : '' ?>><label for="tts_model">Model <small>(audio.cpp: blank saves pocket-tts; openai: required)</small></label><input type="text" id="tts_model" name="tts_model" maxlength="200" value="<?= e($form['tts_model']) ?>"<?= voice_field('tts_model', $errorField) ?>></div>
            <div class="setting-row"><label for="tts_speaker">Speaker <small>(from the TTS service)</small></label><span class="speaker-picker"><select id="tts_speaker" disabled><option value="">Press Find speakers</option></select> <button type="button" id="tts-find">Find speakers</button></span></div>
            <div class="setting-row"><label for="tts_voice">Voice <small>(a speaker name; blank saves alba)</small></label><input type="text" id="tts_voice" name="tts_voice" maxlength="64" list="tts-speaker-list" value="<?= e($form['tts_voice']) ?>"<?= voice_field('tts_voice', $errorField) ?>><datalist id="tts-speaker-list"></datalist></div>
            <div class="setting-row" id="tts-language-row"<?= $form['tts_provider'] === 'openai' ? ' hidden' : '' ?>><label for="tts_language">Language</label><select id="tts_language" name="tts_language"<?= voice_field('tts_language', $errorField) ?>>
                <?php foreach (TTS_LANGUAGES as $value => $label): ?><option value="<?= e($value) ?>"<?= $form['tts_language'] === $value ? ' selected' : '' ?>><?= e($label . ($value !== '' ? " ($value)" : '')) ?></option><?php endforeach; ?>
            </select></div>
            <p class="provider-subtext" id="tts-found" role="status">Start PocketTTS in the DwemerDistro launcher, then press Find speakers. A voice the service does not have falls back to its first speaker.</p>
            <p class="provider-subtext" id="tts-hints" hidden></p>
            <div class="setting-row"><label for="tts_timeout">Timeout (seconds, 1-120)</label><input type="number" id="tts_timeout" name="tts_timeout" min="1" max="120" required value="<?= e($form['tts_timeout']) ?>"<?= voice_field('tts_timeout', $errorField) ?>></div>
            <div class="preview-actions"><button type="submit">Save text to speech</button></div>
        </form>
        <form id="tts-test" class="preview-output">
            <h3>Test speech</h3>
            <label for="tts-text">Text to speak (1-1000 characters)</label>
            <textarea id="tts-text" rows="2" maxlength="1000" required>Hello traveler, this is a voice test.</textarea>
            <label for="tts-npc">NPC ID <small>(optional: uses that NPC's profile voice, if it has one)</small></label>
            <input type="text" id="tts-npc" maxlength="64" pattern="[A-Za-z0-9_.:\-]{1,64}">
            <label for="tts-hint">Voice hint <small>(optional: what the game sends for the NPC's gender)</small></label>
            <select id="tts-hint"><option value="">none</option><option value="female">female</option><option value="male">male</option><option value="neutral">neutral</option></select>
            <div class="preview-actions"><button type="submit" id="tts-send">Speak</button><span id="tts-status" role="status">Uses the saved settings (save first).</span></div>
            <audio id="tts-audio" controls hidden></audio>
        </form>
    </section>
    <section class="connector-card">
        <h2>Speech to text</h2>
        <form method="post">
            <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
            <input type="hidden" name="action" value="save_stt">
            <div class="setting-row"><span>Status</span><label><input type="checkbox" name="stt_enabled" value="1"<?= $form['stt_enabled'] ? ' checked' : '' ?>> Enabled</label></div>
            <p class="provider-subtext">Start Parakeet (or faster-whisper) from the DwemerDistro launcher first, then tick Enabled. Players use it by holding the push-to-talk key (B) in Minecraft.<?php $sttFound = SttService::probe(); ?>
                Found on this machine: <?= e(implode(', ', array_map(fn($name, $up) => $name . ($up ? ' (running)' : ' (not running)'), array_keys($sttFound), $sttFound))) ?>.</p>
            <div class="setting-row"><label for="stt_provider">Provider</label><select id="stt_provider" name="stt_provider"<?= voice_field('stt_provider', $errorField) ?>>
                <?php foreach (STT_PROVIDERS as $value => $label): ?><option value="<?= e($value) ?>"<?= $form['stt_provider'] === $value ? ' selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?>
            </select></div>
            <div class="setting-row"><label for="stt_url">URL</label><input type="url" id="stt_url" name="stt_url" maxlength="300" value="<?= e($form['stt_url']) ?>" placeholder="<?= e(STT_DEFAULT_URLS[$form['stt_provider']] ?? STT_DEFAULT_URLS['parakeet']) ?>"<?= voice_field('stt_url', $errorField) ?>></div>
            <div class="setting-row" id="stt-model-row"<?= in_array($form['stt_provider'], ['fasterwhisper', 'whispercpp'], true) ? ' hidden' : '' ?>><label for="stt_model">Model</label><input type="text" id="stt_model" name="stt_model" maxlength="200" value="<?= e($form['stt_model']) ?>"<?= voice_field('stt_model', $errorField) ?>></div>
            <div class="setting-row" id="stt-field-row"<?= $form['stt_provider'] === 'fasterwhisper' ? '' : ' hidden' ?>><label for="stt_field">Upload form field</label><input type="text" id="stt_field" name="stt_field" maxlength="64" value="<?= e($form['stt_field']) ?>"<?= voice_field('stt_field', $errorField) ?>></div>
            <div class="setting-row"><label for="stt_timeout">Timeout (seconds, 1-120)</label><input type="number" id="stt_timeout" name="stt_timeout" min="1" max="120" required value="<?= e($form['stt_timeout']) ?>"<?= voice_field('stt_timeout', $errorField) ?>></div>
            <div class="preview-actions"><button type="submit">Save speech to text</button></div>
        </form>
        <form id="stt-test" class="preview-output">
            <h3>Test transcription</h3>
            <label for="stt-file">WAV file (at most 10 MB)</label>
            <input type="file" id="stt-file" accept=".wav,audio/wav,audio/x-wav" required>
            <div class="preview-actions"><button type="submit" id="stt-send">Transcribe</button><span id="stt-status" role="status">Uses the saved settings.</span></div>
            <div class="preview-actions"><button type="button" id="stt-record">Record 5 seconds from the microphone</button></div>
            <label for="stt-text">Transcript (edit or type here if recognition fails)</label>
            <textarea id="stt-text" rows="3" maxlength="1000"></textarea>
        </form>
    </section>
</div>
<input type="hidden" id="csrf" value="<?= e($csrf) ?>">
<script>
// TTS: move an untouched default URL with the provider, and fill the speaker list from the service.
const ttsDefaultUrls = <?= json_encode(TTS_DEFAULT_URLS, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG) ?>;
const ttsProvider = document.getElementById('tts_provider');
ttsProvider.addEventListener('change', () => {
    const provider = ttsProvider.value;
    const url = document.getElementById('tts_url');
    if (url.value === '' || Object.values(ttsDefaultUrls).includes(url.value)) url.value = ttsDefaultUrls[provider] || '';
    url.placeholder = ttsDefaultUrls[provider] || '';
    document.getElementById('tts-model-row').hidden = provider === 'auto' || provider === 'xtts';
    document.getElementById('tts-language-row').hidden = provider === 'openai';
});
const speakerSelect = document.getElementById('tts_speaker');
speakerSelect.addEventListener('change', () => { if (speakerSelect.value) document.getElementById('tts_voice').value = speakerSelect.value; });
async function findSpeakers() {
    const found = document.getElementById('tts-found');
    const hints = document.getElementById('tts-hints');
    const voice = document.getElementById('tts_voice');
    if (ttsProvider.value === 'openai') { found.textContent = 'OpenAI-compatible services do not list speakers; type the voice name.'; return; }
    found.textContent = 'Looking for the TTS service…';
    const form = new URLSearchParams({provider: ttsProvider.value, url: document.getElementById('tts_url').value, voice: voice.value});
    let result = null;
    try {
        const response = await fetch('voice_speakers.php', {method: 'POST', headers: {'X-CSRF-Token': document.getElementById('csrf').value}, body: form});
        result = await response.json();
        if (!response.ok) throw new Error(result?.error?.message || 'HTTP ' + response.status);
    } catch (error) { found.textContent = 'Could not ask the server: ' + error.message; return; }
    speakerSelect.replaceChildren();
    const list = document.getElementById('tts-speaker-list');
    list.replaceChildren();
    if (!result.ok) { found.textContent = result.message; speakerSelect.disabled = true; speakerSelect.add(new Option('No speakers', '')); hints.hidden = true; return; }
    if (result.mode === 'audio_cpp') {
        found.textContent = 'Found PocketTTS audio.cpp at ' + result.endpoint + '. It does not list speakers; type the voice name.';
        speakerSelect.disabled = true; speakerSelect.add(new Option('Not listed by audio.cpp', '')); hints.hidden = true; return;
    }
    for (const name of result.speakers) { speakerSelect.add(new Option(name, name)); list.append(new Option(name)); }
    speakerSelect.disabled = result.speakers.length === 0;
    const current = result.speakers.find(name => name.toLowerCase() === voice.value.toLowerCase());
    speakerSelect.value = current || result.voice || '';
    found.textContent = 'Found PocketTTS (XTTS API) at ' + result.endpoint + ' with ' + result.speakers.length + ' speaker(s).'
        + (current ? '' : ' "' + (voice.value || 'alba') + '" is not one of them, so speech uses ' + result.voice + '. Pick a speaker and save.');
    const parts = Object.entries(result.hints).map(([hint, names]) => hint + ': ' + (names.length ? names.join(', ') : 'uses the voice above'));
    hints.textContent = 'Voice hints pick, the same one every time for each NPC, from ' + parts.join('; ') + '.';
    hints.hidden = parts.length === 0;
}
document.getElementById('tts-find').addEventListener('click', findSpeakers);
if (ttsProvider.value !== 'openai') findSpeakers();
// Show only the selected STT provider's field and move an untouched default URL along with it.
const sttDefaultUrls = <?= json_encode(STT_DEFAULT_URLS, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG) ?>;
document.getElementById('stt_provider').addEventListener('change', event => {
    const provider = event.target.value;
    const url = document.getElementById('stt_url');
    if (url.value === '' || Object.values(sttDefaultUrls).includes(url.value)) url.value = sttDefaultUrls[provider] || '';
    url.placeholder = sttDefaultUrls[provider] || '';
    document.getElementById('stt-model-row').hidden = provider === 'fasterwhisper' || provider === 'whispercpp';
    document.getElementById('stt-field-row').hidden = provider !== 'fasterwhisper';
});
// Both tests send ordinary input and the CSRF token; PHP adds the server token.
async function voiceRequest(operation, body, contentType) {
    const response = await fetch('api.php?operation=' + operation, {
        method: 'POST', headers: {'Content-Type': contentType, 'X-CSRF-Token': document.getElementById('csrf').value}, body
    });
    // The server names every voice request (X-Request-Id); it is shown so the call can be found
    // in Logs > Connector calls, on success and on failure.
    const id = response.headers.get('X-Request-Id') || 'not reported';
    if (response.ok && (response.headers.get('Content-Type') || '').startsWith('audio/')) {
        return {blob: await response.blob(), id, voice: response.headers.get('X-Voice-Source') || 'config'};
    }
    let result = null;
    try { result = await response.json(); } catch (error) { result = null; }
    if (!response.ok || !result || !result.ok) throw new Error((result?.error?.message || 'Request failed (HTTP ' + response.status + ').') + ' Request ' + id + '.');
    return result;
}
let audioUrl = '';
document.getElementById('tts-test').addEventListener('submit', async event => {
    event.preventDefault();
    const send = document.getElementById('tts-send');
    const status = document.getElementById('tts-status');
    const audio = document.getElementById('tts-audio');
    send.disabled = true;
    status.textContent = 'Generating speech…';
    try {
        const body = {protocol: 1, text: document.getElementById('tts-text').value};
        const npc = document.getElementById('tts-npc').value.trim();
        if (npc) body.npc_id = npc;
        const hint = document.getElementById('tts-hint').value;
        if (hint) body.voice_hint = hint;
        const result = await voiceRequest('speak', JSON.stringify(body), 'application/json');
        const blob = result.blob;
        if (!(blob instanceof Blob)) throw new Error('No audio was returned.');
        if (audioUrl) URL.revokeObjectURL(audioUrl);
        audioUrl = URL.createObjectURL(blob);
        audio.src = audioUrl;
        audio.hidden = false;
        status.textContent = 'Audio received (' + Math.round(blob.size / 1024) + ' KB, voice from ' + result.voice + ', request ' + result.id + '). Press play.';
    } catch (error) {
        audio.hidden = true;
        status.textContent = error.message;
    } finally { send.disabled = false; }
});
document.getElementById('stt-test').addEventListener('submit', async event => {
    event.preventDefault();
    const send = document.getElementById('stt-send');
    const status = document.getElementById('stt-status');
    const file = document.getElementById('stt-file').files[0];
    if (!file) { status.textContent = 'Choose a WAV file first.'; return; }
    if (file.size > 10000000) { status.textContent = 'The file is larger than 10 MB. Type instead.'; return; }
    send.disabled = true;
    status.textContent = 'Transcribing…';
    try {
        const result = await voiceRequest('listen', file, 'audio/wav');
        document.getElementById('stt-text').value = result.text;
        status.textContent = 'Transcript received (request ' + result.request_id + '). You can edit it.';
    } catch (error) {
        status.textContent = error.message;
        document.getElementById('stt-text').focus();
    } finally { send.disabled = false; }
});
// Record from the browser's microphone and send it as 16 kHz mono WAV (what the game sends too).
function encodeWav(samples, rate) {
    const view = new DataView(new ArrayBuffer(44 + samples.length * 2));
    const text = (offset, value) => { for (let i = 0; i < value.length; i++) view.setUint8(offset + i, value.charCodeAt(i)); };
    text(0, 'RIFF'); view.setUint32(4, 36 + samples.length * 2, true); text(8, 'WAVE'); text(12, 'fmt ');
    view.setUint32(16, 16, true); view.setUint16(20, 1, true); view.setUint16(22, 1, true); view.setUint32(24, rate, true);
    view.setUint32(28, rate * 2, true); view.setUint16(32, 2, true); view.setUint16(34, 16, true); text(36, 'data');
    view.setUint32(40, samples.length * 2, true);
    for (let i = 0; i < samples.length; i++) view.setInt16(44 + i * 2, Math.max(-1, Math.min(1, samples[i])) * 0x7fff, true);
    return new Blob([view], {type: 'audio/wav'});
}
document.getElementById('stt-record').addEventListener('click', async () => {
    const button = document.getElementById('stt-record');
    const status = document.getElementById('stt-status');
    if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
        status.textContent = 'This browser cannot record here (open the dashboard at http://127.0.0.1 or localhost). Use a WAV file.';
        return;
    }
    button.disabled = true;
    let stream;
    try {
        stream = await navigator.mediaDevices.getUserMedia({audio: {channelCount: 1}});
        const context = new AudioContext({sampleRate: 16000});
        const source = context.createMediaStreamSource(stream);
        const processor = context.createScriptProcessor(4096, 1, 1);
        const chunks = [];
        processor.onaudioprocess = event => chunks.push(new Float32Array(event.inputBuffer.getChannelData(0)));
        source.connect(processor);
        processor.connect(context.destination);
        status.textContent = 'Recording… speak now (5 seconds).';
        await new Promise(resolve => setTimeout(resolve, 5000));
        processor.disconnect(); source.disconnect();
        const rate = context.sampleRate;
        await context.close();
        const samples = new Float32Array(chunks.reduce((n, c) => n + c.length, 0));
        let offset = 0;
        for (const c of chunks) { samples.set(c, offset); offset += c.length; }
        status.textContent = 'Transcribing…';
        const result = await voiceRequest('listen', encodeWav(samples, rate), 'audio/wav');
        document.getElementById('stt-text').value = result.text;
        status.textContent = 'Transcript received (request ' + result.request_id + ').';
    } catch (error) {
        status.textContent = error.message || String(error);
    } finally {
        if (stream) stream.getTracks().forEach(track => track.stop());
        button.disabled = false;
    }
});
</script>
<?php endif; ?>
<?php require __DIR__ . '/tmpl/footer.php'; ?>
