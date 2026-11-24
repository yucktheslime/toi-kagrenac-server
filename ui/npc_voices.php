<?php
// A Trade of Immersion: NPC voices. Every NPC (companions c-..., MCA villagers v-...) has its own
// voice profile: a PocketTTS speaker, an optional language and pitch. Upload voice samples one by
// one or as a zip; they are kept in config/voices/ and added to PocketTTS (POST /upload_sample).
// Changes need the server token as well as the CSRF token, like the Plugins page.
require __DIR__ . '/common.php';
require_once __DIR__ . '/../lib/speech.php';
require_once __DIR__ . '/../lib/npc_voices.php';

$config = ui_config();
$db = $config !== null ? ui_database($config) : null;
$message = '';
$results = null;
$saved = is_string($_GET['saved'] ?? null) ? $_GET['saved'] : '';
if ($db !== null) {
    $schema = schema_problem($db);
    if ($schema !== null) {
        $message = $schema[1];
        $db = null;
    }
}
$tts = $config !== null && is_array($config['tts'] ?? null) ? $config['tts'] : [];

function npc_voices_upload_limit(): int
{
    $bytes = function (string $value): int {
        $value = trim($value);
        $unit = strtolower(substr($value, -1));
        $number = (float)$value;
        return (int)match ($unit) { 'g' => $number * 1e9, 'm' => $number * 1e6, 'k' => $number * 1e3, default => $number };
    };
    $limits = array_filter([$bytes((string)ini_get('upload_max_filesize')), $bytes((string)ini_get('post_max_size'))], fn($v) => $v > 0);
    return $limits === [] ? NPC_VOICE_MAX_ZIP_BYTES : min($limits);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $config !== null && $db !== null) {
    $action = is_string($_POST['action'] ?? null) ? $_POST['action'] : '';
    $given = $_POST['admin_token'] ?? '';
    if (!check_csrf()) {
        http_response_code(403);
        $message = 'This form expired. Reload and try again.';
    } elseif (!is_string($given) || !hash_equals((string)$config['token'], $given)) {
        http_response_code(403);
        $message = 'The server token was missing or wrong. It is the token in config/config.php (the same one the game uses).';
    } else {
        session_write_close();
        $base = in_array($action, ['upload_one', 'upload_zip', 'resend'], true) ? SpeechService::xttsBase($tts) : null;
        if ($action === 'save_npc') {
            $npcId = is_string($_POST['npc_id'] ?? null) ? $_POST['npc_id'] : '';
            $checked = npc_voice_validate($_POST['voice'] ?? '', $_POST['language'] ?? '', $_POST['pitch'] ?? '');
            if (!preg_match('/^[A-Za-z0-9_.:-]{1,64}$/D', $npcId)) {
                $message = 'Unknown NPC.';
            } elseif (is_string($checked)) {
                $message = $checked;
            } elseif (npc_voice_set($db, $npcId, $checked[0], $checked[1], $checked[2], 'operator')) {
                header('Location: npc_voices.php?saved=npc#npc-' . rawurlencode($npcId), true, 303);
                exit;
            } else {
                $message = 'The voice could not be saved.';
            }
        } elseif ($action === 'upload_one') {
            $file = $_FILES['sample'] ?? null;
            $name = is_string($_POST['sample_name'] ?? null) && trim($_POST['sample_name']) !== '' ? trim($_POST['sample_name']) : null;
            $assignTo = is_string($_POST['assign_npc'] ?? null) ? $_POST['assign_npc'] : '';
            if (!is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
                $message = 'Choose a voice sample to upload.';
            } elseif (in_array($file['error'] ?? 0, [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)) {
                $message = 'The file is larger than this PHP allows (' . round(npc_voices_upload_limit() / 1e6, 1) . ' MB).';
            } elseif (($file['error'] ?? 0) !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'] ?? '')) {
                $message = 'The upload failed.';
            } elseif ($name !== null && !preg_match(NPC_VOICE_SAMPLE_PATTERN, $name)) {
                $message = 'A voice name is 1-64 letters, numbers, dots, dashes or underscores, starting with a letter or number.';
            } else {
                $bytes = (string)file_get_contents($file['tmp_name'], false, null, 0, NPC_VOICE_MAX_SAMPLE_BYTES + 1);
                $row = npc_voice_add_sample($db, $base, is_string($file['name'] ?? null) ? $file['name'] : 'sample.wav', $bytes, $name);
                $row['assigned'] = '';
                if ($row['ok'] && preg_match('/^[A-Za-z0-9_.:-]{1,64}$/D', $assignTo)) {
                    $current = npc_voice_get($db, $assignTo);
                    if (npc_voice_set($db, $assignTo, $row['name'], is_array($current) ? $current['language'] : null,
                        is_array($current) ? $current['pitch'] : null, 'operator')) {
                        $row['assigned'] = is_array($current) && $current['npc_name'] !== '' ? $current['npc_name'] : $assignTo;
                    }
                }
                $results = [$row];
            }
        } elseif ($action === 'upload_zip') {
            $file = $_FILES['samples_zip'] ?? null;
            if (!is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
                $message = 'Choose a zip of voice samples.';
            } elseif (in_array($file['error'] ?? 0, [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)) {
                $message = 'The zip is larger than this PHP allows (' . round(npc_voices_upload_limit() / 1e6, 1) . ' MB). Split it into smaller zips.';
            } elseif (($file['error'] ?? 0) !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'] ?? '')) {
                $message = 'The zip upload failed.';
            } else {
                $import = npc_voice_import_zip($db, $base, $file['tmp_name'], isset($_POST['assign_by_name']));
                if (isset($import['error'])) {
                    $message = $import['error'];
                } else {
                    $results = $import['results'];
                }
            }
        } elseif ($action === 'resend') {
            $name = is_string($_POST['sample'] ?? null) ? $_POST['sample'] : '';
            [$ok, $detail] = npc_voice_resend($db, $base, $name);
            $message = $name . ': ' . $detail;
        } else {
            $message = 'Unknown action.';
        }
    }
}

$speakers = $config !== null ? SpeechService::speakerList($tts, true) : null;
$npcs = $db !== null ? npc_voice_list($db) : [];
$samples = $db !== null ? npc_voice_samples($db) : [];
$languages = ['' => 'Same as the Voice page'] + array_combine(NPC_VOICE_LANGUAGES, NPC_VOICE_LANGUAGES);
$limit = npc_voices_upload_limit();

$title = 'NPC voices';
$active = 'npc_voices';
require __DIR__ . '/tmpl/header.php';
?>
<section class="chim-panel">
    <p>Every NPC has its own voice: companions and MCA villagers alike. The first time an NPC speaks it gets a
        PocketTTS speaker no other NPC uses yet (a female or male one when the speaker names say so), and keeps it.
        Change any NPC's voice here, or in game with <code>/npc voice &lt;name&gt; &lt;speaker&gt;</code>.
        Upload your own samples below: each becomes a new PocketTTS speaker named after the file.</p>
    <p class="help">Saving needs the <strong>server token</strong> from <code>config/config.php</code> (the same one the game uses).
        Samples are stored in <code>config/voices/</code>, which the web server does not serve, and sent to PocketTTS
        <code>POST /upload_sample</code>. A 5-15 second clean WAV of one speaker works best.</p>
    <?php if ($saved === 'npc'): ?><p class="notice" role="status">Voice saved. The NPC's next line uses it.</p><?php endif; ?>
    <?php if ($message !== ''): ?><p class="notice" role="alert"><?= e($message) ?></p><?php endif; ?>
    <?php if ($config === null): ?>
    <p class="notice">Server configuration is missing or invalid.</p>
    <?php elseif ($db === null): ?>
    <p class="notice">The database is unavailable or has not been migrated (migration 011_npc_voices), so voices cannot be changed.</p>
    <?php endif; ?>
    <?php if ($config !== null): ?>
    <p><?= $speakers === null
        ? '<strong>PocketTTS did not answer</strong> (start it in the DwemerDistro launcher; check the URL on the Voice page). Uploads are kept and can be sent again later.'
        : 'PocketTTS has ' . count($speakers) . ' speaker' . (count($speakers) === 1 ? '' : 's') . ': ' . e(implode(', ', $speakers)) ?></p>
    <?php endif; ?>
</section>

<?php if ($results !== null): ?>
<section class="chim-panel">
    <h2>Upload results</h2>
    <table>
        <thead><tr><th>File</th><th>Voice name</th><th>Result</th><th>Given to</th></tr></thead>
        <tbody>
        <?php foreach ($results as $row): ?>
        <tr>
            <td><code><?= e($row['file']) ?></code></td>
            <td><?= e($row['name']) ?></td>
            <td><?= $row['ok'] ? ($row['forwarded'] ? '✅ ' : '⚠️ ') : '❌ ' ?><?= e($row['detail']) ?></td>
            <td><?= e($row['assigned'] ?? '') ?></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <p class="help"><?= count(array_filter($results, fn($r) => $r['ok'])) ?> of <?= count($results) ?> file(s) stored, <?= count(array_filter($results, fn($r) => $r['forwarded'])) ?> added to PocketTTS.</p>
</section>
<?php endif; ?>

<?php if ($config !== null && $db !== null): ?>
<section class="chim-panel">
    <h2>NPCs</h2>
    <?php if ($npcs === []): ?>
    <p>No NPC has spoken yet. Talk to a companion or villager in game with voice on, then reload.</p>
    <?php else: ?>
    <table>
        <thead><tr><th>NPC</th><th>Kind</th><th>Voice</th><th>Language</th><th>Pitch</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($npcs as $npc): $formId = 'npc-' . $npc['npc_id']; ?>
        <tr id="<?= e($formId) ?>">
            <td><strong><?= e($npc['npc_name'] !== '' ? $npc['npc_name'] : '(no name yet)') ?></strong><br><code class="help"><?= e($npc['npc_id']) ?></code></td>
            <td><?= e($npc['kind']) ?><?= $npc['gender'] !== '' ? ', ' . e($npc['gender']) : '' ?></td>
            <td>
                <select name="voice" form="f-<?= e($formId) ?>">
                    <option value="">(automatic<?= $npc['source'] === 'auto' && $npc['voice'] ? ': ' . e($npc['voice']) : '' ?>)</option>
                    <?php foreach (array_unique(array_merge($speakers ?? [], $npc['voice'] ? [$npc['voice']] : [])) as $speaker): ?>
                    <option value="<?= e($speaker) ?>"<?= $npc['source'] !== 'auto' && $npc['voice'] === $speaker ? ' selected' : '' ?>><?= e($speaker) ?></option>
                    <?php endforeach; ?>
                </select>
                <span class="help"><?= e($npc['source'] === 'auto' ? 'automatic' : ($npc['source'] === 'game' ? 'set in game' : 'set here')) ?></span>
            </td>
            <td>
                <select name="language" form="f-<?= e($formId) ?>">
                    <?php foreach ($languages as $code => $label): ?>
                    <option value="<?= e($code) ?>"<?= (string)($npc['language'] ?? '') === (string)$code ? ' selected' : '' ?>><?= e($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </td>
            <td><input type="number" name="pitch" form="f-<?= e($formId) ?>" min="0.5" max="2" step="0.05" placeholder="1.0" style="width:5em" value="<?= $npc['pitch'] !== null ? e(number_format($npc['pitch'], 2)) : '' ?>"></td>
            <td>
                <form method="post" id="f-<?= e($formId) ?>">
                    <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
                    <input type="hidden" name="action" value="save_npc">
                    <input type="hidden" name="npc_id" value="<?= e($npc['npc_id']) ?>">
                    <input type="password" name="admin_token" placeholder="Server token" required autocomplete="off" style="width:9em">
                    <button type="submit">Save</button>
                </form>
            </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <p class="help">Pitch is applied by the game when it plays the line (1.0 is normal; it also changes speed a little). Language is sent to PocketTTS.</p>
    <?php endif; ?>
</section>

<section class="chim-panel">
    <h2>Upload one voice sample</h2>
    <form method="post" enctype="multipart/form-data" class="narrow">
        <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
        <input type="hidden" name="action" value="upload_one">
        <label for="sample">Sample (.wav, or .mp3/.ogg/.flac when ffmpeg is installed; at most <?= e(round(min($limit, NPC_VOICE_MAX_SAMPLE_BYTES) / 1e6, 1)) ?> MB)</label>
        <input type="file" id="sample" name="sample" accept=".wav,.mp3,.ogg,.flac,audio/*" required>
        <label for="sample-name">Voice name (optional; default: the file name)</label>
        <input type="text" id="sample-name" name="sample_name" maxlength="64" pattern="[A-Za-z0-9][A-Za-z0-9_.\-]{0,63}">
        <label for="assign-npc">Give it to an NPC (optional)</label>
        <select id="assign-npc" name="assign_npc">
            <option value="">(nobody yet)</option>
            <?php foreach ($npcs as $npc): ?>
            <option value="<?= e($npc['npc_id']) ?>"><?= e(($npc['npc_name'] !== '' ? $npc['npc_name'] . ' — ' : '') . $npc['npc_id']) ?></option>
            <?php endforeach; ?>
        </select>
        <label for="token-one">Server token</label>
        <input type="password" id="token-one" name="admin_token" required autocomplete="off">
        <button type="submit">Upload sample</button>
    </form>
</section>

<section class="chim-panel">
    <h2>Upload many samples (zip)</h2>
    <p>A zip of .wav (or .mp3/.ogg/.flac) files. Each file becomes a speaker named after the file
        (<code>Steve.wav</code> → <code>Steve</code>). Folders inside the zip are fine; other files are skipped and listed.
        Zips with unsafe paths, links or encrypted entries are refused. Limits: <?= NPC_VOICE_MAX_ZIP_FILES ?> files,
        <?= NPC_VOICE_MAX_SAMPLE_BYTES / 1e6 ?> MB per sample, <?= NPC_VOICE_MAX_UNPACKED_BYTES / 1e6 ?> MB unpacked, and this
        PHP accepts uploads up to <strong><?= e(round($limit / 1e6, 1)) ?> MB</strong>.</p>
    <form method="post" enctype="multipart/form-data" class="narrow">
        <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
        <input type="hidden" name="action" value="upload_zip">
        <label for="samples-zip">Zip of voice samples</label>
        <input type="file" id="samples-zip" name="samples_zip" accept=".zip,application/zip" required>
        <label><input type="checkbox" name="assign_by_name" value="1" checked> Give each sample to the NPC with the same name or id
            (<code>Steve.wav</code> → the NPC named Steve; <code>Old_Tom.wav</code> → Old Tom)</label>
        <label for="token-zip">Server token</label>
        <input type="password" id="token-zip" name="admin_token" required autocomplete="off">
        <button type="submit">Upload zip</button>
    </form>
</section>

<?php if ($samples !== []): ?>
<section class="chim-panel">
    <h2>Uploaded samples</h2>
    <table>
        <thead><tr><th>Voice</th><th>File</th><th>Size</th><th>PocketTTS</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($samples as $sample): ?>
        <tr>
            <td><strong><?= e($sample['name']) ?></strong></td>
            <td><code><?= e($sample['filename']) ?></code></td>
            <td><?= e(round((int)$sample['bytes'] / 1024)) ?> KB</td>
            <td><?= $sample['forwarded'] === 't' ? '✅ ' : '⚠️ ' ?><?= e($sample['detail']) ?></td>
            <td>
                <form method="post">
                    <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
                    <input type="hidden" name="action" value="resend">
                    <input type="hidden" name="sample" value="<?= e($sample['name']) ?>">
                    <input type="password" name="admin_token" placeholder="Server token" required autocomplete="off" style="width:9em">
                    <button type="submit">Send to PocketTTS again</button>
                </form>
            </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</section>
<?php endif; ?>
<?php endif; ?>
<?php require __DIR__ . '/tmpl/footer.php'; ?>
