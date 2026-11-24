<?php
// Optional MiniMe-T5 and TXT2VEC settings (minime and txt2vec sections of the private config),
// a status probe and a test button for each, and a Reindex for memory notes and game events.
// Saving never contacts a service; Test and Reindex may.
require __DIR__ . '/common.php';
require __DIR__ . '/../lib/config_writer.php';
require __DIR__ . '/../lib/minime.php';
require __DIR__ . '/../lib/txt2vec.php';
require __DIR__ . '/../lib/memory_vectors.php';
require __DIR__ . '/../lib/audit.php';

$config = ui_config();
$minime = is_array($config['minime'] ?? null) ? $config['minime'] : [];
$txt2vec = is_array($config['txt2vec'] ?? null) ? $config['txt2vec'] : [];
$form = [
    'minime_enabled' => !empty($minime['enabled']),
    'minime_auto' => !array_key_exists('auto_detect', $minime) || !empty($minime['auto_detect']),
    'minime_url' => (string)($minime['url'] ?? MINIME_DEFAULT_URL),
    'minime_timeout' => (string)($minime['timeout_seconds'] ?? MINIME_DEFAULT_TIMEOUT),
    'txt_enabled' => !empty($txt2vec['enabled']),
    'txt_auto' => !array_key_exists('auto_detect', $txt2vec) || !empty($txt2vec['auto_detect']),
    'txt_url' => (string)($txt2vec['url'] ?? TXT2VEC_DEFAULT_URL),
    'txt_timeout' => (string)($txt2vec['timeout_seconds'] ?? TXT2VEC_DEFAULT_TIMEOUT),
    'test_text' => 'Do you remember the closed bridge?',
];
$message = '';
$errorField = '';
$testResult = null;
$reindexed = null;
$action = $_POST['action'] ?? '';

function minime_field(string $id, string $errorField): string
{
    return $id === $errorField ? ' aria-invalid="true" aria-describedby="minime-settings-error"' : '';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $config !== null) {
    if (!check_csrf()) {
        http_response_code(403);
        $message = 'This form expired. Reload and try again.';
    } elseif ($action === 'save_minime') {
        $text = fn(string $key) => is_string($_POST[$key] ?? null) ? trim($_POST[$key]) : '';
        $form = array_merge($form, ['minime_enabled' => form_checked('minime_enabled'),
            'minime_auto' => form_checked('minime_auto'), 'minime_url' => $text('minime_url'),
            'minime_timeout' => $text('minime_timeout')]);
        $values = [
            'enabled' => $form['minime_enabled'],
            'auto_detect' => $form['minime_auto'],
            'url' => form_url($form['minime_url']),
            'timeout_seconds' => form_int($form['minime_timeout'], 1, 10),
        ];
        if ($values['url'] === null) {
            [$errorField, $message] = ['minime_url', 'URL must be empty (default http://127.0.0.1:8082) or an http(s) URL without a user name.'];
        } elseif ($values['timeout_seconds'] === null) {
            [$errorField, $message] = ['minime_timeout', 'Timeout must be 1-10 seconds.'];
        } else {
            if ($values['url'] === '') {
                $values['url'] = MINIME_DEFAULT_URL;
            }
            session_write_close();
            $message = save_and_redirect(function (array $latest) use ($values): array {
                $latest['minime'] = $values + (is_array($latest['minime'] ?? null) ? $latest['minime'] : []);
                return $latest;
            }, 'minime.php', 'minime');
        }
    } elseif ($action === 'save_txt2vec') {
        $text = fn(string $key) => is_string($_POST[$key] ?? null) ? trim($_POST[$key]) : '';
        $form = array_merge($form, ['txt_enabled' => form_checked('txt_enabled'),
            'txt_auto' => form_checked('txt_auto'), 'txt_url' => $text('txt_url'),
            'txt_timeout' => $text('txt_timeout')]);
        $values = [
            'enabled' => $form['txt_enabled'],
            'auto_detect' => $form['txt_auto'],
            'url' => form_url($form['txt_url']),
            'timeout_seconds' => form_int($form['txt_timeout'], 1, 30),
        ];
        if ($values['url'] === null) {
            [$errorField, $message] = ['txt_url', 'URL must be empty (default http://127.0.0.1:8082) or an http(s) URL without a user name.'];
        } elseif ($values['timeout_seconds'] === null) {
            [$errorField, $message] = ['txt_timeout', 'Timeout must be 1-30 seconds.'];
        } else {
            if ($values['url'] === '') {
                $values['url'] = TXT2VEC_DEFAULT_URL;
            }
            session_write_close();
            $message = save_and_redirect(function (array $latest) use ($values): array {
                $latest['txt2vec'] = $values + (is_array($latest['txt2vec'] ?? null) ? $latest['txt2vec'] : []);
                return $latest;
            }, 'minime.php', 'txt2vec');
        }
    } elseif ($action === 'test_minime' || $action === 'test_txt2vec') {
        $sample = is_string($_POST['test_text'] ?? null) ? trim($_POST['test_text']) : '';
        $form['test_text'] = $sample;
        if ($sample === '' || mb_strlen($sample) > 300) {
            $message = 'Enter a short test phrase (at most 300 characters).';
        } else {
            session_write_close();
            $probeSettings = $action === 'test_minime'
                ? ['url' => $form['minime_url'] !== '' ? $form['minime_url'] : MINIME_DEFAULT_URL,
                    'timeout_seconds' => (int)$form['minime_timeout']]
                : ['url' => $form['txt_url'] !== '' ? $form['txt_url'] : TXT2VEC_DEFAULT_URL,
                    'timeout_seconds' => (int)$form['txt_timeout']];
            if ($action === 'test_minime') {
                try {
                    $data = minime_extract($probeSettings, $sample);
                    $testResult = ['ok' => isset($data['is_memory_recall']), 'title' => 'Minime classification',
                        'body' => json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)];
                    if (!$testResult['ok']) {
                        $message = 'Minime answered, but not with a classification result.';
                    }
                } catch (MinimeError $error) {
                    $message = 'Minime test failed: ' . $error->getMessage() . '. Is the service started in the DwemerDistro launcher?';
                }
            } else {
                try {
                    $vector = txt2vec_embed($probeSettings, $sample);
                    $testResult = ['ok' => true, 'title' => 'TXT2VEC embedding',
                        'body' => 'Received a ' . count($vector) . '-dimension vector.'];
                } catch (EmbeddingError $error) {
                    $message = 'TXT2VEC test failed: ' . $error->getMessage() . '. Is Minime and TXT2VEC started in the launcher?';
                }
            }
        }
    } elseif ($action === 'reindex') {
        $settings = embedding_active_settings($config);
        $db = ui_database($config);
        if ($settings === null) {
            $message = 'Turn TXT2VEC on (or vector search on Memory settings) and save it before reindexing.';
        } elseif ($db === null || schema_problem($db) !== null) {
            $message = 'The database is unavailable or not migrated. Nothing was indexed.';
        } else {
            session_write_close();
            $requestId = audit_child_id('ui-vec');
            $reindexed = memory_vector_reindex($db, $settings, fn(array $row) => audit_insert($db, $config,
                $row + ['kind' => 'embedding', 'provider' => $settings['provider'] ?? 'txt2vec', 'request_id' => $requestId]));
            $reindexed['request_id'] = $requestId;
        }
    } else {
        $message = 'Unknown form action.';
    }
    if ($message !== '' && http_response_code() === 200) {
        http_response_code(400);
    }
}

// Page-load probes are short and optional, so a stopped service does not stall the dashboard.
$minimeStatus = $form['minime_auto']
    ? minime_probe(['url' => $form['minime_url'] !== '' ? $form['minime_url'] : MINIME_DEFAULT_URL, 'timeout_seconds' => 1])
    : ['ok' => false, 'detail' => 'Health probe skipped (auto-detect is off). Use Test Minime to check.'];
$txtStatus = $form['txt_auto']
    ? txt2vec_probe(['url' => $form['txt_url'] !== '' ? $form['txt_url'] : TXT2VEC_DEFAULT_URL, 'timeout_seconds' => 1])
    : ['ok' => false, 'detail' => 'Health probe skipped (auto-detect is off). Use Test TXT2VEC to check.'];

$stats = null;
$modelId = '';
$settings = $config !== null ? embedding_active_settings($config) : null;
if ($settings !== null) {
    $modelId = embedding_model_id($settings);
    $db ??= ui_database($config);
    $stats = $db !== null && schema_problem($db) === null ? memory_vector_stats($db, $modelId) : null;
}
$saved = ['minime' => 'Minime settings saved. The next turn uses them.',
    'txt2vec' => 'TXT2VEC settings saved. Press Reindex if you just turned it on or changed the URL.'][$_GET['saved'] ?? ''] ?? '';
$title = 'Minime & TXT2VEC';
$active = 'minime';
require __DIR__ . '/tmpl/header.php';
?>
<p class="chim-panel-intro">Optional and off by default. These are DwemerDistro's local text helpers on port 8082, the same ones CHIM uses. Start <strong>Minime and TXT2VEC</strong> in the launcher, then turn each one on here. When a service is off or misses, turns keep their current behaviour and the language model decides.</p>
<?php if ($config === null): ?>
<section class="chim-panel"><p class="notice" role="alert">Server configuration is missing or invalid.</p></section>
<?php else: ?>
<?php if ($saved !== ''): ?><p class="notice" role="status"><?= e($saved) ?></p><?php endif; ?>
<?php if ($message !== ''): ?><p class="notice" role="alert" id="minime-settings-error"><?= e($message) ?></p><?php endif; ?>
<?php if ($testResult !== null && $testResult['ok']): ?><p class="notice" role="status"><?= e($testResult['title']) ?>: <?= e($testResult['body']) ?></p><?php endif; ?>
<div class="llm-layout voice-layout">
    <section class="connector-card">
        <h2>Minime-T5</h2>
        <p class="help">Classifies a player line: whether a memory lookup is needed, and (if the service still offers <code>/command</code>) a possible action. The model is the fallback. The launcher test uses <code>GET /extract?text=...</code> and expects <code>is_memory_recall</code>.</p>
        <p>Status:
            <span class="chim-tag<?= !empty($minimeStatus['ok']) ? ' chim-badge-success' : '' ?>">
                <?= !empty($minimeStatus['ok']) ? 'Reachable' : 'Not reachable' ?>
            </span>
            <?= e($minimeStatus['detail'] ?? '') ?>
        </p>
        <form method="post">
            <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
            <input type="hidden" name="action" value="save_minime">
            <div class="setting-row"><span>Status</span><label class="inline-check"><input type="checkbox" name="minime_enabled" value="1"<?= $form['minime_enabled'] ? ' checked' : '' ?>> Enabled</label></div>
            <div class="setting-row"><span>Auto-detect</span><label class="inline-check"><input type="checkbox" name="minime_auto" value="1"<?= $form['minime_auto'] ? ' checked' : '' ?>> Show a health probe on this page (turns already fall back if a call fails)</label></div>
            <div class="setting-row"><label for="minime_url">Base URL</label><input type="url" id="minime_url" name="minime_url" maxlength="300" value="<?= e($form['minime_url']) ?>" placeholder="<?= e(MINIME_DEFAULT_URL) ?>"<?= minime_field('minime_url', $errorField) ?>></div>
            <div class="setting-row"><label for="minime_timeout">Timeout (seconds, 1-10)</label><input type="number" id="minime_timeout" name="minime_timeout" min="1" max="10" required value="<?= e($form['minime_timeout']) ?>"<?= minime_field('minime_timeout', $errorField) ?>></div>
            <div class="preview-actions"><button type="submit">Save Minime settings</button><span>Saving never contacts the service.</span></div>
        </form>
    </section>
    <section class="connector-card">
        <h2>TXT2VEC</h2>
        <p class="help">Embeds memories and game events so a turn can recall the most relevant ones, not only the newest. CHIM calls <code>POST /embed</code> with <code>{"text":"..."}</code> and stores the <code>embedding</code> array. Vectors are also used for shared facts when this is on.</p>
        <p>Status:
            <span class="chim-tag<?= !empty($txtStatus['ok']) ? ' chim-badge-success' : '' ?>">
                <?= !empty($txtStatus['ok']) ? 'Reachable' : 'Not reachable' ?>
            </span>
            <?= e($txtStatus['detail'] ?? '') ?>
        </p>
        <form method="post">
            <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
            <input type="hidden" name="action" value="save_txt2vec">
            <div class="setting-row"><span>Status</span><label class="inline-check"><input type="checkbox" name="txt_enabled" value="1"<?= $form['txt_enabled'] ? ' checked' : '' ?>> Enabled</label></div>
            <div class="setting-row"><span>Auto-detect</span><label class="inline-check"><input type="checkbox" name="txt_auto" value="1"<?= $form['txt_auto'] ? ' checked' : '' ?>> Show a health probe on this page (turns already fall back if a call fails)</label></div>
            <div class="setting-row"><label for="txt_url">Base URL</label><input type="url" id="txt_url" name="txt_url" maxlength="300" value="<?= e($form['txt_url']) ?>" placeholder="<?= e(TXT2VEC_DEFAULT_URL) ?>"<?= minime_field('txt_url', $errorField) ?>></div>
            <div class="setting-row"><label for="txt_timeout">Timeout (seconds, 1-30)</label><input type="number" id="txt_timeout" name="txt_timeout" min="1" max="30" required value="<?= e($form['txt_timeout']) ?>"<?= minime_field('txt_timeout', $errorField) ?>></div>
            <div class="preview-actions"><button type="submit">Save TXT2VEC settings</button><span>Saving never contacts the service.</span></div>
        </form>
        <h3>Reindex notes and events</h3>
        <?php if ($stats === null): ?>
        <p><?= $form['txt_enabled'] || $settings !== null ? 'Vector counts are unavailable (database not ready).' : 'TXT2VEC and vector search are off.' ?></p>
        <?php else: ?>
        <p><?= e($stats['notes_indexed']) ?> of <?= e($stats['notes_total']) ?> memory notes and
            <?= e($stats['events_indexed']) ?> of <?= e($stats['events_total']) ?> game events have a vector for
            <code><?= e($modelId) ?></code>. <?= e($stats['notes_missing'] + $stats['events_missing']) ?> still need one.</p>
        <?php endif; ?>
        <?php if ($reindexed !== null): ?>
        <p class="notice" role="status">Indexed <?= e($reindexed['notes']) ?> note(s) and <?= e($reindexed['events']) ?> event(s)<?= $reindexed['error'] !== null ? '. Stopped: ' . e($reindexed['error']) . ' (not retried; details only in the server log)' : '' ?>. Request <?= e($reindexed['request_id']) ?> in Logs &gt; Connector calls.</p>
        <?php endif; ?>
        <form method="post" class="preview-actions">
            <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
            <input type="hidden" name="action" value="reindex">
            <button type="submit"<?= $stats === null ? ' disabled' : '' ?>>Reindex up to <?= MEMORY_VECTOR_REINDEX_LIMIT ?> notes and events</button>
            <span>Embeds rows without a current vector, <?= EMBEDDING_BATCH ?> per call. Press again for more. Shared facts are reindexed on <a href="memory_settings.php">Memory settings</a>.</span>
        </form>
    </section>
</div>
<section class="connector-card">
    <h2>Test</h2>
    <p class="help">Sends one request to the URL currently shown above. It does not change settings. Minime is tested with <code>/extract</code> (not TXT2VEC); TXT2VEC is tested with <code>/embed</code>.</p>
    <form method="post">
        <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
        <div class="setting-row"><label for="test_text">Test phrase</label>
            <input type="text" id="test_text" name="test_text" maxlength="300" value="<?= e($form['test_text']) ?>">
        </div>
        <div class="preview-actions">
            <button type="submit" name="action" value="test_minime">Test Minime</button>
            <button type="submit" name="action" value="test_txt2vec">Test TXT2VEC</button>
        </div>
    </form>
</section>
<?php endif; ?>
<?php require __DIR__ . '/tmpl/footer.php'; ?>
