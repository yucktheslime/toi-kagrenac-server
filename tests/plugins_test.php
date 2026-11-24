<?php
// Unit and hook tests for the plugin system. No running HTTP server required.
// Usage: php tests/plugins_test.php
// Exits 0 on success, 1 on failure. This file is CLI-only.

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require __DIR__ . '/../lib/app.php';
require __DIR__ . '/../lib/llm.php';
require __DIR__ . '/../lib/plugins.php';

$failures = 0;
$passes = 0;

function expect(string $name, mixed $got, mixed $want): void
{
    global $failures, $passes;
    if ($got === $want) {
        echo "PASS $name\n";
        $passes++;
        return;
    }
    echo "FAIL $name: got " . var_export($got, true) . " want " . var_export($want, true) . "\n";
    $failures++;
}

function expect_true(string $name, mixed $got): void
{
    expect($name, (bool)$got, true);
}

function expect_contains(string $name, string $haystack, string $needle): void
{
    expect_true($name, str_contains($haystack, $needle));
}

$tmp = sys_get_temp_dir() . '/toi-plugin-test-' . bin2hex(random_bytes(4));
mkdir($tmp, 0700);
putenv('EXAMPLE_AI_CONFIG=' . $tmp . '/config.php');
file_put_contents($tmp . '/config.php', "<?php return ['token' => '0123456789abcdef'];\n");

// --- manifest validation ---
$good = [
    'id' => 'mod_item_knowledge',
    'name' => 'Mod item knowledge',
    'version' => '1.0.0',
    'description' => 'Adds facts.',
    'hooks' => ['prompt'],
    'settings' => ['facts' => ['type' => 'textarea', 'label' => 'Facts', 'default' => 'A']],
];
$parsed = plugins_parse_manifest($good, 'mod_item_knowledge');
expect_true('valid manifest', is_array($parsed) && $parsed['id'] === 'mod_item_knowledge');
expect_true('bad id', is_string(plugins_parse_manifest(['id' => 'NoCaps'] + $good)));
expect_true('folder mismatch', is_string(plugins_parse_manifest($good, 'other_id')));
expect_true('empty hooks', is_string(plugins_parse_manifest(['hooks' => []] + $good)));
expect_true('unknown hook', is_string(plugins_parse_manifest(['hooks' => ['shell']] + $good)));
expect_true('path-like id', is_string(plugins_parse_manifest(['id' => '../etc'] + $good)));

$schema = plugins_parse_settings_schema($good['settings']);
expect_true('settings schema', is_array($schema) && isset($schema['facts']));
$coerced = plugins_coerce_settings($schema, ['facts' => "one\ntwo", 'ignored' => 'x']);
expect('coerced drops unknown', array_keys($coerced), ['facts']);
expect_contains('coerced keeps lines', $coerced['facts'], "one\ntwo");

// --- GitHub URL mapping (no network) ---
expect('github repo', plugins_github_zip_url('https://github.com/acme/my-plugin'),
    'https://github.com/acme/my-plugin/archive/refs/heads/main.zip');
expect('github tree', plugins_github_zip_url('https://github.com/acme/my-plugin/tree/dev'),
    'https://github.com/acme/my-plugin/archive/refs/heads/dev.zip');
expect('github release zip', plugins_github_zip_url('https://github.com/acme/my-plugin/releases/download/v1.0.0/p.zip'),
    'https://github.com/acme/my-plugin/releases/download/v1.0.0/p.zip');
expect('reject http', plugins_github_zip_url('http://github.com/acme/my-plugin'), false);
expect('reject query', plugins_github_zip_url('https://github.com/acme/my-plugin?foo=1'), false);
expect('reject userinfo', plugins_github_zip_url('https://evil@github.com/acme/my-plugin'), false);
expect('reject other host', plugins_github_zip_url('https://example.com/acme/my-plugin'), false);
expect('reject traversal path', plugins_github_zip_url('https://github.com/acme/my-plugin/archive/refs/heads/../main.zip'), false);

// --- bundled discovery and hooks (no database) ---
$discovered = plugins_discover();
expect_true('bundled item knowledge', isset($discovered['mod_item_knowledge']) && $discovered['mod_item_knowledge']['manifest']);
expect_true('bundled wave', isset($discovered['sample_wave']) && $discovered['sample_wave']['manifest']);
expect('bundled start disabled', $discovered['mod_item_knowledge']['enabled'], false);

$item = $discovered['mod_item_knowledge'];
$item['enabled'] = true;
$item['settings'] = plugins_coerce_settings($item['manifest']['settings'], []);
$itemHooks = plugins_load_hooks($item);
expect_true('item knowledge prompt hook', isset($itemHooks['prompt']));
$prompt = $itemHooks['prompt'](['settings' => $item['settings'], 'npc_id' => 'n', 'player_text' => 'hi', 'context' => []]);
expect_contains('prompt mentions Create', (string)$prompt, 'Andesite Alloy');
expect_true('prompt has no action tags', !str_contains((string)$prompt, '[ACTION'));

$wave = $discovered['sample_wave'];
$wave['enabled'] = true;
$wave['settings'] = [];
$waveHooks = plugins_load_hooks($wave);
expect_true('wave action hook', isset($waveHooks['action']) && isset($waveHooks['game_event']));
$actions = $waveHooks['action'](['plugin_id' => 'sample_wave', 'settings' => []]);
expect('wave action name', $actions[0]['name'] ?? null, 'wave');

ActionCatalog::resetExtras();
expect_true('register wave', ActionCatalog::register('wave', $actions[0]));
expect('refuse built-in override', ActionCatalog::register('follow_player', $actions[0]), false);
$allowed = plugins_extend_allowed(['follow_player']);
expect_true('allowlist gained wave', in_array('wave', $allowed, true));

$raw = mock_reply(['mode' => 'mock', 'mock_delay_seconds' => 0],
    ['text' => 'please [ACTION:wave]', 'npc_name' => 'Smokey', 'player_name' => 'Tester', 'context' => []],
    ['bio' => null, 'facts' => []]);
[$reply, $parsedActions, $rejected] = extract_actions($raw, $allowed, 'npc_smoke');
expect('wave extracted', $parsedActions[0]['name'] ?? null, 'wave');
expect('wave target from request', $parsedActions[0]['args']['target_id'] ?? null, 'npc_smoke');
expect('wave not rejected', $rejected, []);
expect_true('spoken text dropped the tag', !str_contains($reply, '[ACTION'));

$knowledge = ['bio' => null, 'facts' => [], 'plugin_context' => plugins_prompt_from_hook($itemHooks, $item['settings'])];
expect_contains('knowledge_prompt includes plugin text', knowledge_prompt($knowledge), 'Andesite Alloy');
$mock = mock_reply(['mode' => 'mock', 'mock_delay_seconds' => 0],
    ['text' => 'what mod items do you know?', 'npc_name' => 'Smokey', 'player_name' => 'Tester', 'context' => []],
    $knowledge);
expect_contains('mock llm echoes plugin facts', $mock, 'Plugin notes:');
expect_contains('mock llm names an item', $mock, 'Andesite Alloy');

$fired = false;
$waveHooks['game_event'] = function (array $event) use (&$fired): void {
    $fired = ($event['type'] ?? '') === 'item_given';
};
// Call the shipped hook (logs only) and a recorder.
$waveHooks['game_event'](['type' => 'item_given', 'text' => 'secret should not be required']);
// The shipped hook is still on the original $waveHooks before we overwrote it; reload and call it.
$shipped = plugins_load_hooks($wave);
$shipped['game_event'](['type' => 'entity_died', 'text' => 'A zombie died.']);
expect_true('game_event hook is callable', is_callable($shipped['game_event']));

function plugins_prompt_from_hook(array $hooks, array $settings): string
{
    $text = $hooks['prompt'](['settings' => $settings, 'npc_id' => 'n', 'player_text' => 'mod items', 'context' => []]);
    return mb_substr(strtr(clean_line((string)$text), '[]', '()'), 0, 2000);
}

// --- zip install and rejection ---
function test_write_zip(string $path, array $files, array $meta = []): void
{
    $script = sys_get_temp_dir() . '/toi-zip-' . bin2hex(random_bytes(4)) . '.py';
    $payload = json_encode(['path' => $path, 'files' => $files, 'meta' => $meta ?: new stdClass()], JSON_UNESCAPED_SLASHES);
    $py = <<<'PY'
import json, sys, zipfile
spec = json.loads(sys.stdin.read())
with zipfile.ZipFile(spec["path"], "w") as z:
    for name, data in spec["files"].items():
        info = zipfile.ZipInfo(name)
        info.create_system = 3
        meta = spec.get("meta", {}).get(name, {})
        mode = meta.get("mode", 0o100644)
        info.external_attr = mode << 16
        z.writestr(info, data)
PY;
    file_put_contents($script, $py);
    $proc = proc_open(['python3', $script], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, null, ['bypass_shell' => true]);
    fwrite($pipes[0], $payload);
    fclose($pipes[0]);
    $out = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $status = proc_close($proc);
    @unlink($script);
    if ($status !== 0) {
        throw new RuntimeException("zip helper failed: $out");
    }
}

$pluginJson = json_encode([
    'id' => 'zip_demo',
    'name' => 'Zip demo',
    'version' => '1.0.0',
    'description' => 'Installed from a test zip.',
    'hooks' => ['prompt'],
], JSON_UNESCAPED_SLASHES);
$pluginPhp = "<?php return ['prompt' => function (array \$c): string { return 'ZIP_FACT glowberry tart'; }];\n";

$goodZip = $tmp . '/good.zip';
test_write_zip($goodZip, ['plugin.json' => $pluginJson, 'plugin.php' => $pluginPhp, 'README.md' => 'hi']);
$installed = plugins_install_from_zip($goodZip, plugins_user_dir(), 'zip', 'good.zip');
expect_true('install good zip', is_array($installed) && ($installed['id'] ?? '') === 'zip_demo');
expect_true('user plugin on disk', is_file(plugins_user_dir() . '/zip_demo/plugin.php'));

$again = plugins_install_from_zip($goodZip, plugins_user_dir(), 'zip', 'good.zip');
expect_true('refuse duplicate id', is_string($again) && str_contains($again, 'already installed'));

$travZip = $tmp . '/trav.zip';
test_write_zip($travZip, ['../evil.php' => '<?php echo 1;', 'plugin.json' => $pluginJson, 'plugin.php' => $pluginPhp]);
expect_true('refuse path traversal zip', is_string(plugins_zip_list($travZip)) || is_string(plugins_zip_root(is_array(plugins_zip_list($travZip)) ? plugins_zip_list($travZip) : [])));
$list = plugins_zip_list($travZip);
expect_true('traversal caught at list or root', is_string($list) || (is_array($list) && is_string(plugins_zip_root($list))));

$linkZip = $tmp . '/link.zip';
test_write_zip($linkZip, [
    'plugin.json' => $pluginJson,
    'plugin.php' => $pluginPhp,
    'link.php' => '/tmp/x',
], ['link.php' => ['mode' => 0o120777]]);
expect_true('refuse symlink zip', is_string(plugins_zip_list($linkZip)));

$badFileZip = $tmp . '/badfile.zip';
test_write_zip($badFileZip, ['plugin.json' => $pluginJson, 'plugin.php' => $pluginPhp, 'evil.sh' => '#!/bin/sh']);
$badFile = plugins_install_from_zip($badFileZip, plugins_user_dir(), 'zip', 'badfile.zip');
expect_true('refuse unexpected file', is_string($badFile) && str_contains($badFile, 'not allowed'));

$shadowZip = $tmp . '/shadow.zip';
$shadowJson = json_encode([
    'id' => 'mod_item_knowledge',
    'name' => 'Shadow',
    'version' => '9.9.9',
    'description' => 'Should not replace the bundled plugin.',
    'hooks' => ['prompt'],
], JSON_UNESCAPED_SLASHES);
test_write_zip($shadowZip, ['plugin.json' => $shadowJson, 'plugin.php' => $pluginPhp]);
$shadow = plugins_install_from_zip($shadowZip, plugins_user_dir(), 'zip', 'shadow.zip');
expect_true('refuse shadowing bundled id', is_string($shadow) && str_contains($shadow, 'already installed'));

// Load the installed zip plugin's prompt hook.
$after = plugins_discover();
expect_true('discover user zip plugin', isset($after['zip_demo']) && $after['zip_demo']['origin'] === 'user');
$after['zip_demo']['enabled'] = true;
$zipHooks = plugins_load_hooks($after['zip_demo']);
expect_contains('zip plugin prompt', (string)$zipHooks['prompt'](['settings' => []]), 'ZIP_FACT glowberry tart');

// Token helper
expect('token ok', plugins_token_ok(['token' => '0123456789abcdef'], '0123456789abcdef'), true);
expect('token reject', plugins_token_ok(['token' => '0123456789abcdef'], '0123456789abcdee'), false);

// Cleanup
$dest = plugins_user_dir();
if (is_dir($dest . '/zip_demo')) {
    plugins_remove_tree($dest, $dest . '/zip_demo');
}
@unlink($tmp . '/config.php');
@unlink($goodZip);
@unlink($travZip);
@unlink($linkZip);
@unlink($badFileZip);
@unlink($shadowZip);
@rmdir($dest);
@rmdir($tmp);

ActionCatalog::resetExtras();

if ($failures > 0) {
    echo "$failures failed, $passes passed.\n";
    exit(1);
}
echo "All $passes plugin checks passed.\n";
