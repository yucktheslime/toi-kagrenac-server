<?php
// Server plugins: discover, validate, load and run operator-installed PHP modules.
// This file only defines functions and constants, so requesting it over HTTP prints nothing.
//
// Bundled examples live in lib/plugins/bundled/ (tracked, denied by Core as part of lib/).
// User-installed plugins live in config/plugins/ (untracked, like config.php). Core's
// release update keeps unknown files and the private config; config/ is denied on the web,
// so plugin PHP and uploaded zips are not served. Hooks never run unless the plugin is
// enabled in the database, and every mutating dashboard action needs the server token.

require_once __DIR__ . '/action_catalog.php';

const PLUGIN_ID_PATTERN = '/^[a-z][a-z0-9_]{1,39}$/D';
const PLUGIN_HOOKS = ['prompt', 'action', 'game_event'];
const PLUGIN_SETTING_TYPES = ['text', 'textarea', 'number', 'checkbox', 'select'];
const PLUGIN_MAX = 32;
const PLUGIN_MAX_ZIP_BYTES = 2000000;
const PLUGIN_MAX_UNPACKED_BYTES = 8000000;
const PLUGIN_MAX_FILES = 50;
const PLUGIN_MAX_PROMPT_CHARS = 2000;
const PLUGIN_MAX_SETTING_CHARS = 4000;
const PLUGIN_ALLOWED_NAMES = '/^(plugin\.json|plugin\.php|README\.md|LICENSE|LICENSE\.txt|[A-Za-z0-9_][A-Za-z0-9_.-]{0,60}\.(md|txt))$/D';

// The last plugins_bootstrap() result for this request (enabled plugins with hooks loaded).
function plugins_state(?array $set = null): array
{
    static $state = ['loaded' => false, 'plugins' => []];
    if ($set !== null) {
        $state = $set;
    }
    return $state;
}

function plugins_bundled_dir(): string
{
    return __DIR__ . '/plugins/bundled';
}

// Writable data dir next to the private config. Core already denies config/ and retains
// unknown files under it on release updates, the same way it keeps config.php.
function plugins_user_dir(): string
{
    $config = getenv('EXAMPLE_AI_CONFIG') ?: (__DIR__ . '/../config/config.php');
    return dirname($config) . '/plugins';
}

function plugins_is_valid_id(mixed $id): bool
{
    return is_string($id) && preg_match(PLUGIN_ID_PATTERN, $id);
}

// True when $path is a real directory inside $root, with no symlinks in the path.
function plugins_safe_dir(string $root, string $path): bool
{
    $rootReal = realpath($root);
    $pathReal = realpath($path);
    if ($rootReal === false || $pathReal === false || is_link($path) || !is_dir($pathReal)) {
        return false;
    }
    $rootReal = rtrim($rootReal, '/');
    if ($pathReal !== $rootReal && !str_starts_with($pathReal . '/', $rootReal . '/')) {
        return false;
    }
    $current = $path;
    while (true) {
        if (is_link($current)) {
            return false;
        }
        $parent = dirname($current);
        if ($parent === $current) {
            break;
        }
        $current = $parent;
        $parentReal = realpath($parent);
        if ($parentReal !== false && ($parentReal === $rootReal || strlen($parentReal) < strlen($rootReal))) {
            break;
        }
    }
    return true;
}

function plugins_safe_file(string $root, string $path): bool
{
    if (is_link($path) || !is_file($path)) {
        return false;
    }
    return plugins_safe_dir($root, dirname($path)) && realpath($path) !== false
        && str_starts_with(realpath($path), rtrim(realpath($root), '/') . '/');
}

// Reads and validates plugin.json. Returns the clean manifest or a string error.
function plugins_parse_manifest(mixed $raw, ?string $expectId = null): array|string
{
    if (!is_array($raw) || array_is_list($raw)) {
        return 'plugin.json must be a JSON object.';
    }
    $id = $raw['id'] ?? null;
    if (!plugins_is_valid_id($id)) {
        return 'id must be 2-40 characters: a lowercase letter, then a-z, 0-9 or _.';
    }
    if ($expectId !== null && $id !== $expectId) {
        return "plugin id '$id' does not match the folder name '$expectId'.";
    }
    $name = is_string($raw['name'] ?? null) ? clean_line($raw['name']) : '';
    $version = is_string($raw['version'] ?? null) ? clean_line($raw['version']) : '';
    $description = is_string($raw['description'] ?? null) ? clean_line($raw['description']) : '';
    if ($name === '' || mb_strlen($name) > 80) {
        return 'name must be 1-80 characters.';
    }
    if ($version === '' || !preg_match('/^[A-Za-z0-9._+-]{1,32}$/D', $version)) {
        return 'version must be 1-32 characters of A-Z a-z 0-9 . _ + -.';
    }
    if ($description === '' || mb_strlen($description) > 400) {
        return 'description must be 1-400 characters.';
    }
    $hooks = $raw['hooks'] ?? [];
    if (!is_array($hooks) || !array_is_list($hooks) || $hooks === []) {
        return 'hooks must be a non-empty list.';
    }
    $cleanHooks = [];
    foreach ($hooks as $hook) {
        if (!is_string($hook) || !in_array($hook, PLUGIN_HOOKS, true) || in_array($hook, $cleanHooks, true)) {
            return 'hooks must be unique values from: ' . implode(', ', PLUGIN_HOOKS) . '.';
        }
        $cleanHooks[] = $hook;
    }
    $settings = [];
    if (isset($raw['settings'])) {
        $parsed = plugins_parse_settings_schema($raw['settings']);
        if (is_string($parsed)) {
            return $parsed;
        }
        $settings = $parsed;
    }
    return [
        'id' => $id,
        'name' => $name,
        'version' => $version,
        'description' => $description,
        'hooks' => $cleanHooks,
        'settings' => $settings,
    ];
}

function plugins_parse_settings_schema(mixed $raw): array|string
{
    if (!is_array($raw) || array_is_list($raw) || count($raw) > 16) {
        return 'settings must be an object with at most 16 entries.';
    }
    $schema = [];
    foreach ($raw as $key => $spec) {
        if (!is_string($key) || !preg_match('/^[a-z][a-z0-9_]{0,31}$/D', $key) || !is_array($spec)) {
            return 'each setting key must be 1-32 characters of a-z, 0-9 and _, with an object schema.';
        }
        $type = $spec['type'] ?? 'text';
        if (!is_string($type) || !in_array($type, PLUGIN_SETTING_TYPES, true)) {
            return "settings.$key type must be one of: " . implode(', ', PLUGIN_SETTING_TYPES) . '.';
        }
        $label = is_string($spec['label'] ?? null) ? clean_line($spec['label']) : $key;
        $help = is_string($spec['description'] ?? null) ? clean_line($spec['description']) : '';
        if ($label === '' || mb_strlen($label) > 80 || mb_strlen($help) > 400) {
            return "settings.$key has a label or description that is too long.";
        }
        $max = isset($spec['max']) ? (int)$spec['max'] : ($type === 'textarea' ? PLUGIN_MAX_SETTING_CHARS : 200);
        $max = max(1, min(PLUGIN_MAX_SETTING_CHARS, $max));
        $entry = ['type' => $type, 'label' => $label, 'description' => $help, 'max' => $max];
        if ($type === 'select') {
            $options = $spec['options'] ?? null;
            if (!is_array($options) || !array_is_list($options) || $options === [] || count($options) > 32) {
                return "settings.$key options must be a list of 1-32 strings.";
            }
            $clean = [];
            foreach ($options as $option) {
                if (!is_string($option) || clean_line($option) === '' || mb_strlen($option) > 80) {
                    return "settings.$key options must be short strings.";
                }
                $clean[] = clean_line($option);
            }
            $entry['options'] = $clean;
        }
        if (array_key_exists('default', $spec)) {
            $entry['default'] = $spec['default'];
        }
        $schema[$key] = $entry;
    }
    return $schema;
}

// Coerces posted or stored values against the schema. Unknown keys are dropped.
function plugins_coerce_settings(array $schema, array $input): array
{
    $out = [];
    foreach ($schema as $key => $spec) {
        $raw = $input[$key] ?? ($spec['default'] ?? ($spec['type'] === 'checkbox' ? false : ''));
        $out[$key] = match ($spec['type']) {
            'checkbox' => $raw === true || $raw === 1 || $raw === '1' || $raw === 'on' || $raw === 'true',
            'number' => is_numeric($raw) ? max(-1000000, min(1000000, (float)$raw)) : 0,
            'select' => (is_string($raw) && in_array(clean_line($raw), $spec['options'], true))
                ? clean_line($raw) : ($spec['options'][0] ?? ''),
            default => mb_substr(is_string($raw) ? clean_line($raw) : '', 0, $spec['max']),
        };
        if ($spec['type'] === 'textarea' && is_string($raw)) {
            // Keep line breaks in the facts list; strip only control characters except \n and \t.
            $text = trim(preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]+/u', '', $raw) ?? '');
            $out[$key] = mb_substr($text, 0, $spec['max']);
        }
    }
    return $out;
}

function plugins_read_manifest_file(string $path, ?string $expectId = null): array|string
{
    $json = @file_get_contents($path);
    if (!is_string($json) || strlen($json) > 16000) {
        return 'plugin.json is missing or too large.';
    }
    return plugins_parse_manifest(json_decode($json, true), $expectId);
}

// Scans one root (bundled or user) for plugin folders. Never follows symlinks.
function plugins_scan_root(string $root, string $origin): array
{
    $found = [];
    if (!is_dir($root) || is_link($root)) {
        return $found;
    }
    $list = @scandir($root, SCANDIR_SORT_ASCENDING);
    if (!is_array($list)) {
        return $found;
    }
    foreach ($list as $name) {
        if ($name === '.' || $name === '..' || !plugins_is_valid_id($name)) {
            continue;
        }
        $dir = $root . '/' . $name;
        if (!plugins_safe_dir($root, $dir)) {
            continue;
        }
        $manifestPath = $dir . '/plugin.json';
        $codePath = $dir . '/plugin.php';
        if (!plugins_safe_file($root, $manifestPath) || !plugins_safe_file($root, $codePath)) {
            continue;
        }
        $manifest = plugins_read_manifest_file($manifestPath, $name);
        if (is_string($manifest)) {
            $found[$name] = ['id' => $name, 'origin' => $origin, 'dir' => $dir, 'error' => $manifest,
                'manifest' => null, 'enabled' => false, 'settings' => [], 'source' => $origin];
            continue;
        }
        $found[$name] = ['id' => $name, 'origin' => $origin, 'dir' => $dir, 'error' => null,
            'manifest' => $manifest, 'enabled' => false, 'settings' => [], 'source' => $origin];
    }
    return $found;
}

function plugins_discover(): array
{
    $bundled = plugins_scan_root(plugins_bundled_dir(), 'bundled');
    $user = plugins_scan_root(plugins_user_dir(), 'user');
    // A user install never shadows a bundled id: the bundled copy stays, the user folder is ignored.
    foreach ($user as $id => $plugin) {
        if (!isset($bundled[$id])) {
            $bundled[$id] = $plugin;
        }
    }
    ksort($bundled);
    return array_slice($bundled, 0, PLUGIN_MAX, true);
}

function plugins_table_ready(?\PgSql\Connection $db): bool
{
    if ($db === null) {
        return false;
    }
    $result = @pg_query($db, "SELECT 1 FROM information_schema.tables WHERE table_name = 'ex_plugins'");
    return $result !== false && pg_num_rows($result) > 0;
}

// Loads rows from ex_plugins into the discovered list. Missing rows stay disabled.
function plugins_with_state(array $discovered, ?\PgSql\Connection $db): array
{
    if (!plugins_table_ready($db)) {
        return $discovered;
    }
    $result = @pg_query($db, 'SELECT plugin_id, enabled, settings, source, installed_from FROM ex_plugins');
    if ($result === false) {
        app_log('plugin state query failed: ' . pg_last_error($db));
        return $discovered;
    }
    while (($row = pg_fetch_assoc($result)) !== false) {
        $id = $row['plugin_id'];
        if (!isset($discovered[$id])) {
            continue;
        }
        $settings = json_decode($row['settings'] ?? '{}', true);
        if (!is_array($settings)) {
            $settings = [];
        }
        $schema = $discovered[$id]['manifest']['settings'] ?? [];
        $discovered[$id]['enabled'] = $row['enabled'] === 't';
        $discovered[$id]['settings'] = plugins_coerce_settings($schema, $settings);
        $discovered[$id]['installed_from'] = (string)($row['installed_from'] ?: $discovered[$id]['origin']);
        $discovered[$id]['source'] = (string)($row['source'] ?: $row['installed_from'] ?: $discovered[$id]['origin']);
    }
    return $discovered;
}

function plugins_upsert(\PgSql\Connection $db, string $id, bool $enabled, array $settings, string $from, ?string $source): bool
{
    $json = json_encode((object)$settings, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    $result = @pg_query_params($db,
        'INSERT INTO ex_plugins (plugin_id, enabled, settings, installed_from, source, updated_at)
         VALUES ($1, $2, $3::jsonb, $4, $5, now())
         ON CONFLICT (plugin_id) DO UPDATE SET enabled = EXCLUDED.enabled, settings = EXCLUDED.settings,
             installed_from = EXCLUDED.installed_from, source = COALESCE(EXCLUDED.source, ex_plugins.source),
             updated_at = now()',
        [$id, $enabled ? 't' : 'f', $json, $from, $source]);
    if ($result === false) {
        app_log('plugin upsert failed: ' . pg_last_error($db));
        return false;
    }
    return true;
}

function plugins_delete_row(\PgSql\Connection $db, string $id): bool
{
    $result = @pg_query_params($db, 'DELETE FROM ex_plugins WHERE plugin_id = $1', [$id]);
    return $result !== false;
}

// Includes plugin.php once and returns its hook map (name => callable). Errors become [].
function plugins_load_hooks(array $plugin): array
{
    if ($plugin['error'] !== null || empty($plugin['enabled']) || $plugin['manifest'] === null) {
        return [];
    }
    $code = $plugin['dir'] . '/plugin.php';
    $root = $plugin['origin'] === 'bundled' ? plugins_bundled_dir() : plugins_user_dir();
    if (!plugins_safe_file($root, $code)) {
        return [];
    }
    try {
        $returned = include $code;
    } catch (Throwable $error) {
        app_log('plugin ' . $plugin['id'] . ' failed to load (' . $error::class . ')');
        return [];
    }
    $hooks = [];
    $wanted = $plugin['manifest']['hooks'];
    if (is_array($returned) && !array_is_list($returned)) {
        foreach ($wanted as $name) {
            if (isset($returned[$name]) && is_callable($returned[$name])) {
                $hooks[$name] = $returned[$name];
            }
        }
        return $hooks;
    }
    if (is_object($returned)) {
        foreach ($wanted as $name) {
            if (method_exists($returned, $name) && is_callable([$returned, $name])) {
                $hooks[$name] = [$returned, $name];
            }
        }
    }
    return $hooks;
}

// Discovers plugins, applies DB state, loads enabled hooks and registers extra actions.
// Safe to call more than once in a request.
function plugins_bootstrap(?\PgSql\Connection $db): array
{
    // Always reload. PHP-FPM keeps statics across requests, so a cached "loaded"
    // flag would miss an enable/disable until the worker recycled.
    ActionCatalog::resetExtras();
    $plugins = plugins_with_state(plugins_discover(), $db);
    foreach ($plugins as $id => &$plugin) {
        $plugin['hooks'] = [];
        if (!$plugin['enabled'] || $plugin['manifest'] === null) {
            continue;
        }
        $plugin['hooks'] = plugins_load_hooks($plugin);
        if (in_array('action', $plugin['manifest']['hooks'], true) && isset($plugin['hooks']['action'])) {
            plugins_register_actions($plugin);
        }
    }
    unset($plugin);
    plugins_state(['loaded' => true, 'plugins' => $plugins]);
    return $plugins;
}

function plugins_register_actions(array $plugin): void
{
    try {
        $list = ($plugin['hooks']['action'])([
            'plugin_id' => $plugin['id'],
            'settings' => $plugin['settings'],
        ]);
    } catch (Throwable $error) {
        app_log('plugin ' . $plugin['id'] . ' action hook failed (' . $error::class . ')');
        return;
    }
    if (!is_array($list) || !array_is_list($list)) {
        return;
    }
    foreach (array_slice($list, 0, 8) as $spec) {
        if (!is_array($spec) || !is_string($spec['name'] ?? null)) {
            continue;
        }
        ActionCatalog::register($spec['name'], $spec);
    }
}

// Adds enabled plugin action names to the server allowlist (the game's offered_actions can still narrow it).
function plugins_extend_allowed(array $allowed): array
{
    $names = ActionCatalog::names();
    $builtIn = ActionCatalog::builtInNames();
    foreach ($names as $name) {
        if (!in_array($name, $builtIn, true) && !in_array($name, $allowed, true)) {
            $allowed[] = $name;
        }
    }
    return array_values($allowed);
}

function plugins_enabled_action_names(): array
{
    return array_values(array_diff(ActionCatalog::names(), ActionCatalog::builtInNames()));
}

// Extra reference text from prompt hooks, cleaned so it cannot form an [ACTION:...] tag.
function plugins_prompt_context(array $turnContext): string
{
    $parts = [];
    foreach (plugins_state()['plugins'] as $plugin) {
        if (empty($plugin['hooks']['prompt'])) {
            continue;
        }
        try {
            $text = ($plugin['hooks']['prompt'])([
                'plugin_id' => $plugin['id'],
                'settings' => $plugin['settings'],
                'npc_id' => $turnContext['npc_id'] ?? '',
                'player_text' => $turnContext['player_text'] ?? '',
                'context' => $turnContext['context'] ?? [],
            ]);
        } catch (Throwable $error) {
            app_log('plugin ' . $plugin['id'] . ' prompt hook failed (' . $error::class . ')');
            continue;
        }
        if (!is_string($text)) {
            continue;
        }
        $text = mb_substr(strtr(clean_line($text), '[]', '()'), 0, PLUGIN_MAX_PROMPT_CHARS);
        if ($text !== '') {
            $parts[] = $plugin['manifest']['name'] . ': ' . $text;
        }
    }
    $joined = implode("\n", $parts);
    return mb_substr($joined, 0, PLUGIN_MAX_PROMPT_CHARS * 2);
}

function plugins_dispatch_game_event(array $event): void
{
    foreach (plugins_state()['plugins'] as $plugin) {
        if (empty($plugin['hooks']['game_event'])) {
            continue;
        }
        try {
            ($plugin['hooks']['game_event'])([
                'plugin_id' => $plugin['id'],
                'settings' => $plugin['settings'],
                'type' => $event['type'] ?? '',
                'text' => $event['text'] ?? '',
                'context' => $event['context'] ?? [],
                'npc_id' => $event['npc_id'] ?? null,
                'session_id' => $event['session_id'] ?? '',
            ]);
        } catch (Throwable $error) {
            app_log('plugin ' . $plugin['id'] . ' game_event hook failed (' . $error::class . ')');
        }
    }
}

function plugins_ensure_user_dir(): string|false
{
    $dir = plugins_user_dir();
    if (is_link($dir)) {
        return false;
    }
    if (!is_dir($dir) && !@mkdir($dir, 0770, true) && !is_dir($dir)) {
        return false;
    }
    clearstatcache(true, $dir);
    return is_dir($dir) && !is_link($dir) ? $dir : false;
}

function plugins_remove_tree(string $root, string $dir): bool
{
    if (!plugins_safe_dir($root, $dir)) {
        return false;
    }
    $files = @scandir($dir);
    if (!is_array($files)) {
        return false;
    }
    foreach ($files as $name) {
        if ($name === '.' || $name === '..') {
            continue;
        }
        $path = $dir . '/' . $name;
        if (is_link($path) || !is_file($path) || !plugins_safe_file($root, $path)) {
            return false;
        }
        if (!@unlink($path)) {
            return false;
        }
    }
    return @rmdir($dir);
}

// --- Zip install (no ext-zip required: only STORE and DEFLATE, no symlinks, no Zip64) ---

function plugins_zip_error(string $message): string
{
    return $message;
}

// Reads the central directory of a zip and returns member records, or an error string.
// The limits default to the plugin ones; the voice sample importer (lib/npc_voices.php) passes larger ones.
function plugins_zip_list(string $path, int $maxZip = PLUGIN_MAX_ZIP_BYTES, int $maxUnpacked = PLUGIN_MAX_UNPACKED_BYTES,
    int $maxFiles = PLUGIN_MAX_FILES): array|string
{
    $size = @filesize($path);
    if (!is_int($size) || $size < 22 || $size > $maxZip) {
        return plugins_zip_error('The zip is missing, empty or larger than ' . round($maxZip / 1000000) . ' MB.');
    }
    $fh = @fopen($path, 'rb');
    if ($fh === false) {
        return plugins_zip_error('The zip could not be opened.');
    }
    $read = function (int $offset, int $length) use ($fh, $size): string|false {
        if ($offset < 0 || $length < 0 || $offset + $length > $size) {
            return false;
        }
        if (fseek($fh, $offset) !== 0) {
            return false;
        }
        $data = fread($fh, $length);
        return is_string($data) && strlen($data) === $length ? $data : false;
    };
    $eocd = null;
    $maxComment = min(65535, $size - 22);
    for ($comment = 0; $comment <= $maxComment; $comment++) {
        $offset = $size - 22 - $comment;
        $block = $read($offset, 22);
        if ($block === false) {
            break;
        }
        $head = unpack('Vsig/vdisk/vcdDisk/vthis/vtotal/VcdSize/VcdOffset/vcomment', $block);
        if ($head === false || $head['sig'] !== 0x06054b50) {
            continue;
        }
        if ($head['comment'] !== $comment || $head['disk'] !== 0 || $head['cdDisk'] !== 0
            || $head['this'] !== $head['total'] || $head['total'] < 1) {
            fclose($fh);
            return plugins_zip_error('The zip is not a simple, single-disk archive.');
        }
        $eocd = $head;
        break;
    }
    if ($eocd === null) {
        fclose($fh);
        return plugins_zip_error('The file is not a zip archive.');
    }
    if ($eocd['total'] > $maxFiles || $eocd['cdOffset'] < 0 || $eocd['cdSize'] < 1) {
        fclose($fh);
        return plugins_zip_error('The zip has too many files or a damaged directory.');
    }
    $cd = $read($eocd['cdOffset'], $eocd['cdSize']);
    fclose($fh);
    if ($cd === false) {
        return plugins_zip_error('The zip directory could not be read.');
    }
    if (str_contains($cd, "PK\x06\x06") || str_contains($cd, "PK\x06\x07")) {
        return plugins_zip_error('Zip64 archives are not accepted.');
    }
    $members = [];
    $pos = 0;
    $cdLen = strlen($cd);
    $unpacked = 0;
    for ($i = 0; $i < $eocd['total']; $i++) {
        if ($pos + 46 > $cdLen) {
            return plugins_zip_error('The zip directory is truncated.');
        }
        $head = unpack('Vsig/vverMade/vverNeed/vflags/vmethod/vtime/vdate/Vcrc/Vcomp/Vraw/vnameLen/vextraLen/vcommentLen/vdisk/vinternal/Vexternal/Vlocal',
            substr($cd, $pos, 46));
        if ($head === false || $head['sig'] !== 0x02014b50) {
            return plugins_zip_error('The zip directory is damaged.');
        }
        $pos += 46;
        $name = substr($cd, $pos, $head['nameLen']);
        $pos += $head['nameLen'] + $head['extraLen'] + $head['commentLen'];
        if ($pos > $cdLen || strlen($name) !== $head['nameLen']) {
            return plugins_zip_error('The zip directory is damaged.');
        }
        if ($head['flags'] & 0x0001) {
            return plugins_zip_error('Encrypted zip entries are not accepted.');
        }
        $name = str_replace('\\', '/', $name);
        if ($name === '' || str_starts_with($name, '/') || str_contains($name, ':')
            || str_contains($name, "\0") || str_contains($name, '..')) {
            return plugins_zip_error('The zip has an unsafe path.');
        }
        $isDir = str_ends_with($name, '/');
        $mode = ($head['external'] >> 16) & 0xFFFF;
        if (($mode & 0170000) === 0120000 || ($mode & 0170000) === 0140000 || ($mode & 0170000) === 0010000
            || ($mode & 0170000) === 0020000 || ($mode & 0170000) === 0060000) {
            return plugins_zip_error('The zip contains a symbolic link or special file.');
        }
        if ($isDir) {
            continue;
        }
        if (!in_array($head['method'], [0, 8], true)) {
            return plugins_zip_error('The zip uses a compression method that is not accepted.');
        }
        $unpacked += $head['raw'];
        if ($unpacked > $maxUnpacked || $head['comp'] > $maxUnpacked) {
            return plugins_zip_error('The zip unpacks to more than ' . round($maxUnpacked / 1000000) . ' MB.');
        }
        $members[] = $head + ['name' => $name];
    }
    if ($members === []) {
        return plugins_zip_error('The zip has no files.');
    }
    return $members;
}

function plugins_zip_root(array $members): array|string
{
    $manifests = [];
    foreach ($members as $member) {
        if (str_ends_with($member['name'], '/plugin.json') || $member['name'] === 'plugin.json') {
            if (str_contains($member['name'], '__MACOSX/')) {
                continue;
            }
            $manifests[] = $member['name'];
        }
    }
    if (count($manifests) !== 1) {
        return plugins_zip_error('The zip must contain exactly one plugin.json.');
    }
    $manifest = $manifests[0];
    $root = $manifest === 'plugin.json' ? '' : substr($manifest, 0, -strlen('plugin.json'));
    // At most two folder levels: GitHub's repo-branch/ wrapper, or that plus the plugin folder.
    if (substr_count(trim($root, '/'), '/') > 1) {
        return plugins_zip_error('plugin.json is nested too deeply in the zip.');
    }
    $php = $root . 'plugin.php';
    $hasPhp = false;
    foreach ($members as $member) {
        $name = $member['name'];
        if (!str_starts_with($name, $root)) {
            continue;
        }
        $relative = substr($name, strlen($root));
        if ($relative === '' || str_contains($relative, '/')) {
            if ($relative !== '' && str_contains($relative, '/')) {
                return plugins_zip_error('Plugin files must all sit in the plugin folder, with no subfolders.');
            }
            continue;
        }
        if ($relative === 'plugin.php') {
            $hasPhp = true;
        }
        if (!preg_match(PLUGIN_ALLOWED_NAMES, $relative)) {
            return plugins_zip_error("File '$relative' is not allowed in a plugin zip.");
        }
    }
    if (!$hasPhp) {
        return plugins_zip_error('The zip is missing plugin.php next to plugin.json.');
    }
    return [$root, $php, $manifest];
}

function plugins_zip_extract_member(string $path, array $member): string|false
{
    $fh = @fopen($path, 'rb');
    if ($fh === false || fseek($fh, $member['local']) !== 0) {
        if (is_resource($fh)) {
            fclose($fh);
        }
        return false;
    }
    $header = fread($fh, 30);
    if (!is_string($header) || strlen($header) !== 30) {
        fclose($fh);
        return false;
    }
    $local = unpack('Vsig/vver/vflags/vmethod/vtime/vdate/Vcrc/Vcomp/Vraw/vnameLen/vextraLen', $header);
    if ($local === false || $local['sig'] !== 0x04034b50) {
        fclose($fh);
        return false;
    }
    $skip = $local['nameLen'] + $local['extraLen'];
    if ($skip > 0 && (fread($fh, $skip) === false || feof($fh) && $skip > 0 && $member['comp'] > 0)) {
        // fread of 0 is fine; continue
    }
    $compressed = $member['comp'] === 0 ? '' : fread($fh, $member['comp']);
    fclose($fh);
    if ($member['comp'] > 0 && (!is_string($compressed) || strlen($compressed) !== $member['comp'])) {
        return false;
    }
    if ($member['method'] === 0) {
        $data = $compressed;
    } else {
        $data = @gzinflate($compressed, max(1, $member['raw']));
        if (!is_string($data)) {
            return false;
        }
    }
    if (strlen($data) !== $member['raw'] || ($member['crc'] !== 0 && crc32($data) !== $member['crc'] && crc32($data) !== unpack('N', pack('V', $member['crc']))[1])) {
        // crc32 in PHP is unsigned; ZIP CRC is 32-bit. Compare as unsigned 32-bit.
        if (strlen($data) !== $member['raw'] || ((crc32($data) & 0xFFFFFFFF) !== ($member['crc'] & 0xFFFFFFFF))) {
            return false;
        }
    }
    return $data;
}

// Extracts a validated plugin zip into $dest (an empty directory). Returns the plugin id or an error.
function plugins_install_from_zip(string $zipPath, string $destRoot, string $from, ?string $source = null): array|string
{
    $members = plugins_zip_list($zipPath);
    if (is_string($members)) {
        return $members;
    }
    $roots = plugins_zip_root($members);
    if (is_string($roots)) {
        return $roots;
    }
    [$root] = $roots;
    $files = [];
    foreach ($members as $member) {
        if (!str_starts_with($member['name'], $root)) {
            continue;
        }
        $relative = substr($member['name'], strlen($root));
        if ($relative === '' || str_ends_with($relative, '/')) {
            continue;
        }
        $data = plugins_zip_extract_member($zipPath, $member);
        if ($data === false) {
            return plugins_zip_error('A zip entry could not be extracted.');
        }
        $files[$relative] = $data;
    }
    if (!isset($files['plugin.json'], $files['plugin.php'])) {
        return plugins_zip_error('The zip did not contain plugin.json and plugin.php.');
    }
    $manifest = plugins_parse_manifest(json_decode($files['plugin.json'], true));
    if (is_string($manifest)) {
        return $manifest;
    }
    $id = $manifest['id'];
    $existing = plugins_discover();
    if (isset($existing[$id])) {
        return "A plugin named '$id' is already installed. Remove it first.";
    }
    $destRoot = plugins_ensure_user_dir();
    if ($destRoot === false) {
        return 'The plugin data folder could not be created.';
    }
    $temp = $destRoot . '/.tmp-' . bin2hex(random_bytes(8));
    if (!@mkdir($temp, 0770) || is_link($temp)) {
        return 'A temporary plugin folder could not be created.';
    }
    $ok = true;
    foreach ($files as $name => $data) {
        if (!preg_match(PLUGIN_ALLOWED_NAMES, $name) || str_contains($name, '/')) {
            $ok = false;
            break;
        }
        $ok = $ok && @file_put_contents($temp . '/' . $name, $data) !== false;
    }
    $check = $ok ? plugins_read_manifest_file($temp . '/plugin.json', $id) : 'The plugin files could not be written.';
    if (is_string($check) || !is_file($temp . '/plugin.php')) {
        plugins_remove_tree($destRoot, $temp);
        return is_string($check) ? $check : 'plugin.php is missing after extract.';
    }
    $lint = plugins_php_lint($temp . '/plugin.php');
    if ($lint !== null) {
        plugins_remove_tree($destRoot, $temp);
        return $lint;
    }
    $final = $destRoot . '/' . $id;
    if (!@rename($temp, $final)) {
        plugins_remove_tree($destRoot, $temp);
        return 'The plugin folder could not be moved into place.';
    }
    return ['id' => $id, 'manifest' => $check, 'from' => $from, 'source' => $source, 'dir' => $final];
}

function plugins_php_lint(string $path): ?string
{
    $php = PHP_BINARY !== '' ? PHP_BINARY : 'php';
    $cmd = [$php, '-n', '-d', 'display_errors=0', '-l', $path];
    $proc = @proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, null, ['bypass_shell' => true]);
    if (!is_resource($proc)) {
        return 'plugin.php could not be syntax-checked.';
    }
    $out = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $status = proc_close($proc);
    return $status === 0 ? null : 'plugin.php did not pass a syntax check.';
}

// Accepts only https://github.com/owner/repo[...] URLs and maps them to a zip download URL.
function plugins_github_zip_url(string $url): string|false
{
    $url = trim($url);
    if (strlen($url) > 300 || str_contains($url, '@') || str_contains($url, '\\') || str_contains($url, '..')) {
        return false;
    }
    $parts = parse_url($url);
    if (!is_array($parts) || ($parts['scheme'] ?? '') !== 'https' || ($parts['host'] ?? '') !== 'github.com') {
        return false;
    }
    if (isset($parts['user']) || isset($parts['pass']) || isset($parts['port']) || isset($parts['query'])
        || isset($parts['fragment'])) {
        return false;
    }
    $path = trim($parts['path'] ?? '', '/');
    if (!preg_match('#^([A-Za-z0-9._-]+)/([A-Za-z0-9._-]+)(?:/(.*))?$#', $path, $m)) {
        return false;
    }
    $owner = $m[1];
    $repo = $m[2];
    if (str_starts_with($owner, '.') || str_starts_with($repo, '.') || $owner === 'github' && $repo === 'github') {
        return false;
    }
    $rest = $m[3] ?? '';
    if ($rest === '') {
        return "https://github.com/$owner/$repo/archive/refs/heads/main.zip";
    }
    if (preg_match('#^archive/refs/(heads|tags)/([A-Za-z0-9._/-]+)\.zip$#', $rest, $r)) {
        return "https://github.com/$owner/$repo/archive/refs/{$r[1]}/{$r[2]}.zip";
    }
    if (preg_match('#^releases/download/([A-Za-z0-9._-]+)/([A-Za-z0-9._-]+\.zip)$#', $rest, $r)) {
        return "https://github.com/$owner/$repo/releases/download/{$r[1]}/{$r[2]}";
    }
    if (preg_match('#^tree/([A-Za-z0-9._/-]+)$#', $rest, $r)) {
        return "https://github.com/$owner/$repo/archive/refs/heads/{$r[1]}.zip";
    }
    return false;
}

const PLUGIN_DOWNLOAD_HOSTS = ['github.com', 'codeload.github.com', 'objects.githubusercontent.com',
    'release-assets.githubusercontent.com', 'github-releases.githubusercontent.com'];

function plugins_http_get(string $url, int $maxBytes): string|false
{
    $received = '';
    $curl = curl_init($url);
    curl_setopt_array($curl, [
        CURLOPT_HTTPGET => true,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 4,
        CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
        CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
        CURLOPT_USERAGENT => 'TradeOfImmersion-Kagrenac-plugin-install',
        CURLOPT_WRITEFUNCTION => function ($curl, string $chunk) use (&$received, $maxBytes): int {
            $received .= $chunk;
            return strlen($received) > $maxBytes ? 0 : strlen($chunk);
        },
        CURLOPT_HEADERFUNCTION => function ($curl, string $header) {
            return strlen($header);
        },
    ]);
    $ok = curl_exec($curl);
    $status = (int)curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    $final = (string)curl_getinfo($curl, CURLINFO_EFFECTIVE_URL);
    curl_close($curl);
    $host = parse_url($final, PHP_URL_HOST);
    if ($ok === false || $status !== 200 || !is_string($host) || !in_array(strtolower($host), PLUGIN_DOWNLOAD_HOSTS, true)) {
        return false;
    }
    if ($received === '' || strlen($received) > $maxBytes) {
        return false;
    }
    return $received;
}

function plugins_install_from_github(string $url): array|string
{
    $zipUrl = plugins_github_zip_url($url);
    if ($zipUrl === false) {
        return 'That is not a recognised GitHub URL. Use https://github.com/owner/repo or a zip release URL.';
    }
    $body = plugins_http_get($zipUrl, PLUGIN_MAX_ZIP_BYTES);
    // A repo whose default branch is master: retry once when the URL was the implied main branch.
    if ($body === false && str_ends_with($zipUrl, '/heads/main.zip')) {
        $body = plugins_http_get(substr($zipUrl, 0, -8) . 'master.zip', PLUGIN_MAX_ZIP_BYTES);
        $zipUrl = substr($zipUrl, 0, -8) . 'master.zip';
    }
    if ($body === false) {
        return 'The GitHub zip could not be downloaded.';
    }
    $dir = plugins_ensure_user_dir();
    if ($dir === false) {
        return 'The plugin data folder could not be created.';
    }
    $tmp = $dir . '/.download-' . bin2hex(random_bytes(8)) . '.zip';
    if (@file_put_contents($tmp, $body) === false) {
        return 'The download could not be saved.';
    }
    try {
        return plugins_install_from_zip($tmp, $dir, 'github', $zipUrl);
    } finally {
        @unlink($tmp);
    }
}

function plugins_token_ok(array $config, mixed $given): bool
{
    return is_string($given) && hash_equals((string)$config['token'], $given);
}
