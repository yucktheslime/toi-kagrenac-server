<?php
// Plugins: list, enable, configure, install (zip or GitHub) and remove. Mutating actions
// need the server token as well as the CSRF token, because plugin code runs on this server.
require __DIR__ . '/common.php';
require_once __DIR__ . '/../lib/plugins.php';

$config = ui_config();
$db = $config !== null ? ui_database($config) : null;
$message = '';
$saved = $_GET['saved'] ?? '';

if ($db !== null && $config !== null) {
    $schema = schema_problem($db);
    if ($schema !== null) {
        $message = $schema[1];
        $db = null;
    }
}

plugins_state(['loaded' => false, 'plugins' => []]);
$plugins = $db !== null ? plugins_bootstrap($db) : plugins_discover();

function plugins_ui_token(array $config): bool
{
    $given = $_POST['admin_token'] ?? '';
    return plugins_token_ok($config, is_string($given) ? $given : '');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $config !== null && $db !== null) {
    $action = is_string($_POST['action'] ?? null) ? $_POST['action'] : '';
    if (!check_csrf()) {
        http_response_code(403);
        $message = 'This form expired. Reload and try again.';
    } elseif (!plugins_ui_token($config)) {
        http_response_code(403);
        $message = 'The server token was missing or wrong. It is the token in config/config.php (the same one the game uses).';
    } else {
        session_write_close();
        if ($action === 'toggle') {
            $id = is_string($_POST['plugin_id'] ?? null) ? $_POST['plugin_id'] : '';
            $plugin = $plugins[$id] ?? null;
            if ($plugin === null || $plugin['manifest'] === null) {
                $message = 'That plugin is not installed or its manifest is invalid.';
            } else {
                $enable = isset($_POST['enable']);
                if (plugins_upsert($db, $id, $enable, $plugin['settings'],
                    $plugin['origin'] === 'bundled' ? 'bundled' : ($plugin['source'] === 'github' ? 'github' : 'zip'),
                    $plugin['source'] ?? null)) {
                    header('Location: plugins.php?saved=toggle', true, 303);
                    exit;
                }
                $message = 'The plugin state could not be saved.';
            }
        } elseif ($action === 'save_settings') {
            $id = is_string($_POST['plugin_id'] ?? null) ? $_POST['plugin_id'] : '';
            $plugin = $plugins[$id] ?? null;
            if ($plugin === null || $plugin['manifest'] === null) {
                $message = 'That plugin is not installed or its manifest is invalid.';
            } else {
                $schema = $plugin['manifest']['settings'] ?? [];
                $settings = plugins_coerce_settings($schema, is_array($_POST['settings'] ?? null) ? $_POST['settings'] : []);
                if (plugins_upsert($db, $id, $plugin['enabled'], $settings,
                    $plugin['origin'] === 'bundled' ? 'bundled' : ($plugin['source'] === 'github' ? 'github' : 'zip'),
                    $plugin['source'] ?? null)) {
                    header('Location: plugins.php?saved=settings', true, 303);
                    exit;
                }
                $message = 'The settings could not be saved.';
            }
        } elseif ($action === 'remove') {
            $id = is_string($_POST['plugin_id'] ?? null) ? $_POST['plugin_id'] : '';
            $plugin = $plugins[$id] ?? null;
            if ($plugin === null) {
                $message = 'That plugin is not installed.';
            } elseif ($plugin['origin'] === 'bundled') {
                $message = 'Bundled example plugins cannot be removed. You can disable them instead.';
            } else {
                $dir = plugins_user_dir();
                if (!plugins_remove_tree($dir, $dir . '/' . $id) && is_dir($dir . '/' . $id)) {
                    $message = 'The plugin files could not be deleted.';
                } elseif (!plugins_delete_row($db, $id)) {
                    $message = 'The plugin files were removed, but the database row could not be deleted.';
                } else {
                    header('Location: plugins.php?saved=removed', true, 303);
                    exit;
                }
            }
        } elseif ($action === 'install_zip') {
            $file = $_FILES['plugin_zip'] ?? null;
            if (!is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
                $message = 'Choose a zip file to upload.';
            } elseif (($file['error'] ?? 0) !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'] ?? '')) {
                $message = 'The zip upload failed.';
            } elseif (($file['size'] ?? 0) > PLUGIN_MAX_ZIP_BYTES) {
                $message = 'The zip is larger than 2 MB.';
            } else {
                $result = plugins_install_from_zip($file['tmp_name'], plugins_user_dir(), 'zip', $file['name'] ?? null);
                if (is_string($result)) {
                    $message = $result;
                } elseif (!plugins_upsert($db, $result['id'], false, plugins_coerce_settings($result['manifest']['settings'], []),
                    'zip', is_string($file['name'] ?? null) ? $file['name'] : null)) {
                    $message = 'The plugin was unpacked, but its database row could not be saved. It is installed and left disabled.';
                } else {
                    header('Location: plugins.php?saved=installed', true, 303);
                    exit;
                }
            }
        } elseif ($action === 'install_github') {
            $url = is_string($_POST['github_url'] ?? null) ? trim($_POST['github_url']) : '';
            $result = plugins_install_from_github($url);
            if (is_string($result)) {
                $message = $result;
            } elseif (!plugins_upsert($db, $result['id'], false, plugins_coerce_settings($result['manifest']['settings'], []),
                'github', $result['source'] ?? $url)) {
                $message = 'The plugin was unpacked, but its database row could not be saved. It is installed and left disabled.';
            } else {
                header('Location: plugins.php?saved=installed', true, 303);
                exit;
            }
        } else {
            $message = 'Unknown action.';
        }
        plugins_state(['loaded' => false, 'plugins' => []]);
        $plugins = plugins_bootstrap($db);
    }
}

$notices = [
    'toggle' => 'Plugin enable state saved. It applies to the next turn or event.',
    'settings' => 'Plugin settings saved. They apply to the next turn or event.',
    'removed' => 'Plugin removed.',
    'installed' => 'Plugin installed and left disabled. Review it, then enable it.',
];
$title = 'Plugins';
$active = 'plugins';
require __DIR__ . '/tmpl/header.php';
?>
<section class="chim-panel">
    <p class="notice" role="alert"><strong>Plugins run PHP code on this server</strong> with the same access as the dashboard and the game API. Only install plugins you wrote or that you trust. A malicious plugin can read your config, your API keys and your database. Install, enable, configure and remove actions also need the <strong>server token</strong> from <code>config/config.php</code>.</p>
    <p>User-installed plugins are stored in <code>config/plugins/</code>, next to the private config, so a launcher update keeps them and Apache (DwemerDistro Core) does not serve the PHP files. Bundled examples live in <code>lib/plugins/bundled/</code> and cannot be deleted. See <code>docs/PLUGINS.md</code> to write your own.</p>
    <?php if (isset($notices[$saved])): ?><p class="notice" role="status"><?= e($notices[$saved]) ?></p><?php endif; ?>
    <?php if ($message !== ''): ?><p class="notice" role="alert"><?= e($message) ?></p><?php endif; ?>
    <?php if ($config === null): ?>
    <p class="notice">Server configuration is missing or invalid.</p>
    <?php elseif ($db === null): ?>
    <p class="notice">The database is unavailable or has not been migrated, so plugin state cannot be changed. Bundled plugins are listed below as disabled.</p>
    <?php endif; ?>

    <?php foreach ($plugins as $plugin): ?>
    <article class="chim-panel" style="border:1px solid var(--chim-border, #444); padding:16px; margin-top:16px;">
        <h2><?= e($plugin['manifest']['name'] ?? $plugin['id']) ?> <code><?= e($plugin['id']) ?></code></h2>
        <?php if ($plugin['manifest']): ?>
        <p><?= e($plugin['manifest']['description']) ?></p>
        <p class="help">Version <?= e($plugin['manifest']['version']) ?> · <?= e($plugin['origin'] === 'bundled' ? 'bundled example' : 'installed') ?> · hooks: <?= e(implode(', ', $plugin['manifest']['hooks'])) ?><?= $plugin['enabled'] ? ' · enabled' : ' · disabled' ?><?= $plugin['error'] ? ' · error: ' . e($plugin['error']) : '' ?></p>
        <?php else: ?>
        <p class="notice" role="alert">This plugin could not be loaded: <?= e($plugin['error'] ?? 'invalid manifest') ?></p>
        <?php endif; ?>

        <?php if ($config !== null && $db !== null && $plugin['manifest']): ?>
        <form method="post" class="narrow" style="margin-top:12px;">
            <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
            <input type="hidden" name="action" value="toggle">
            <input type="hidden" name="plugin_id" value="<?= e($plugin['id']) ?>">
            <label><input type="checkbox" name="enable" value="1"<?= $plugin['enabled'] ? ' checked' : '' ?>> Enable this plugin</label>
            <label for="token-toggle-<?= e($plugin['id']) ?>">Server token</label>
            <input type="password" id="token-toggle-<?= e($plugin['id']) ?>" name="admin_token" required autocomplete="off">
            <button type="submit">Save enable state</button>
        </form>

        <?php if ($plugin['manifest']['settings']): ?>
        <form method="post" class="narrow" style="margin-top:16px;">
            <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
            <input type="hidden" name="action" value="save_settings">
            <input type="hidden" name="plugin_id" value="<?= e($plugin['id']) ?>">
            <fieldset>
                <legend>Settings</legend>
                <?php foreach ($plugin['manifest']['settings'] as $key => $spec):
                    $value = $plugin['settings'][$key] ?? ($spec['default'] ?? '');
                    $idAttr = 'setting-' . $plugin['id'] . '-' . $key;
                ?>
                <?php if ($spec['type'] === 'checkbox'): ?>
                <label><input type="checkbox" name="settings[<?= e($key) ?>]" value="1"<?= !empty($value) ? ' checked' : '' ?>> <?= e($spec['label']) ?></label>
                <?php elseif ($spec['type'] === 'textarea'): ?>
                <label for="<?= e($idAttr) ?>"><?= e($spec['label']) ?></label>
                <textarea id="<?= e($idAttr) ?>" name="settings[<?= e($key) ?>]" rows="8" maxlength="<?= (int)$spec['max'] ?>"><?= e(is_string($value) ? $value : '') ?></textarea>
                <?php elseif ($spec['type'] === 'select'): ?>
                <label for="<?= e($idAttr) ?>"><?= e($spec['label']) ?></label>
                <select id="<?= e($idAttr) ?>" name="settings[<?= e($key) ?>]">
                    <?php foreach ($spec['options'] as $option): ?>
                    <option value="<?= e($option) ?>"<?= (string)$value === $option ? ' selected' : '' ?>><?= e($option) ?></option>
                    <?php endforeach; ?>
                </select>
                <?php elseif ($spec['type'] === 'number'): ?>
                <label for="<?= e($idAttr) ?>"><?= e($spec['label']) ?></label>
                <input type="number" id="<?= e($idAttr) ?>" name="settings[<?= e($key) ?>]" value="<?= e((string)$value) ?>">
                <?php else: ?>
                <label for="<?= e($idAttr) ?>"><?= e($spec['label']) ?></label>
                <input type="text" id="<?= e($idAttr) ?>" name="settings[<?= e($key) ?>]" maxlength="<?= (int)$spec['max'] ?>" value="<?= e(is_string($value) ? $value : '') ?>">
                <?php endif; ?>
                <?php if ($spec['description'] !== ''): ?><p class="help"><?= e($spec['description']) ?></p><?php endif; ?>
                <?php endforeach; ?>
            </fieldset>
            <label for="token-settings-<?= e($plugin['id']) ?>">Server token</label>
            <input type="password" id="token-settings-<?= e($plugin['id']) ?>" name="admin_token" required autocomplete="off">
            <button type="submit">Save settings</button>
        </form>
        <?php endif; ?>
        <?php endif; ?>

        <?php if ($config !== null && $db !== null && $plugin['origin'] !== 'bundled'): ?>
        <form method="post" class="narrow" style="margin-top:16px;" onsubmit="return confirm('Remove this plugin and its files?');">
            <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
            <input type="hidden" name="action" value="remove">
            <input type="hidden" name="plugin_id" value="<?= e($plugin['id']) ?>">
            <label for="token-remove-<?= e($plugin['id']) ?>">Server token</label>
            <input type="password" id="token-remove-<?= e($plugin['id']) ?>" name="admin_token" required autocomplete="off">
            <button type="submit">Remove plugin</button>
        </form>
        <?php endif; ?>
    </article>
    <?php endforeach; ?>

    <?php if ($plugins === []): ?>
    <p>No plugins were found. Bundled examples should appear under <code>lib/plugins/bundled/</code>.</p>
    <?php endif; ?>
</section>

<?php if ($config !== null && $db !== null): ?>
<section class="chim-panel">
    <h2>Install a plugin</h2>
    <p>The zip or GitHub repository must contain <code>plugin.json</code> and <code>plugin.php</code> in the same folder. Symbolic links, path traversal, subfolders and unexpected file types are refused. New plugins are installed disabled.</p>
    <form method="post" enctype="multipart/form-data" class="narrow">
        <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
        <input type="hidden" name="action" value="install_zip">
        <label for="plugin-zip">Plugin zip (at most 2 MB)</label>
        <input type="file" id="plugin-zip" name="plugin_zip" accept=".zip,application/zip" required>
        <label for="token-zip">Server token</label>
        <input type="password" id="token-zip" name="admin_token" required autocomplete="off">
        <button type="submit">Install from zip</button>
    </form>
    <form method="post" class="narrow" style="margin-top:24px;">
        <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
        <input type="hidden" name="action" value="install_github">
        <label for="github-url">GitHub URL</label>
        <input type="url" id="github-url" name="github_url" placeholder="https://github.com/owner/repo" required maxlength="300">
        <p class="help">Accepted: a repository URL (main or master branch), a <code>/tree/branch</code> URL, or a release zip URL on github.com. Nothing else is downloaded.</p>
        <label for="token-github">Server token</label>
        <input type="password" id="token-github" name="admin_token" required autocomplete="off">
        <button type="submit">Install from GitHub</button>
    </form>
</section>
<?php endif; ?>
<?php require __DIR__ . '/tmpl/footer.php'; ?>
