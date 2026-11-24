<?php
// Tests lib/npc_voices.php (per-NPC voices and voice sample uploads) without a database, against
// tests/pockettts_stub.py on 127.0.0.1:8024 (which must be free).
//   php tests/npc_voices_test.php
// Needs php-curl and python3 (to start the stub and build test zips).

if (!function_exists('pg_connect')) {
    function pg_connect(...$args) { return false; }
}
$tmp = sys_get_temp_dir() . '/toi-voice-test-' . bin2hex(random_bytes(4));
mkdir($tmp . '/config', 0700, true);
putenv('EXAMPLE_AI_CONFIG=' . $tmp . '/config/config.php');
require __DIR__ . '/../lib/speech.php';
require_once __DIR__ . '/../lib/npc_voices.php';
ini_set('error_log', '/dev/null');

$failures = 0;
function check(string $name, bool $ok, string $detail = ''): void
{
    global $failures;
    echo ($ok ? 'PASS ' : 'FAIL ') . $name . ($ok || $detail === '' ? '' : " ($detail)") . PHP_EOL;
    $failures += $ok ? 0 : 1;
}

function wav_bytes(): string
{
    $data = str_repeat("\0\0", 2400);
    $fmt = pack('vvVVvv', 1, 1, 24000, 48000, 2, 16);
    $body = 'WAVE' . 'fmt ' . pack('V', strlen($fmt)) . $fmt . 'data' . pack('V', strlen($data)) . $data;
    return 'RIFF' . pack('V', strlen($body)) . $body;
}

// Builds a zip with python3: $entries is [name => bytes].
function make_zip(string $path, array $entries): void
{
    $spec = tempnam(sys_get_temp_dir(), 'toi-zip');
    file_put_contents($spec, json_encode(array_map('base64_encode', $entries)));
    $py = 'import base64,json,sys,zipfile' . "\n"
        . 'e=json.load(open(sys.argv[1]))' . "\n"
        . 'z=zipfile.ZipFile(sys.argv[2],"w",zipfile.ZIP_DEFLATED)' . "\n"
        . 'for n,b in e.items():' . "\n"
        . '    z.writestr(zipfile.ZipInfo(n),base64.b64decode(b),compress_type=zipfile.ZIP_DEFLATED)' . "\n"
        . 'z.close()';
    exec('python3 -c ' . escapeshellarg($py) . ' ' . escapeshellarg($spec) . ' ' . escapeshellarg($path), $out, $code);
    unlink($spec);
    if ($code !== 0) {
        throw new RuntimeException('could not build zip');
    }
}

// --- pure helpers ---
$pool = ['alba', 'marius', 'javert', 'cosette'];
$a = npc_voice_pick('c-1111', $pool, []);
check('pick returns a speaker from the pool', in_array($a, $pool, true));
check('pick is stable', npc_voice_pick('c-1111', $pool, []) === $a);
$b = npc_voice_pick('c-2222', $pool, [$a]);
check('pick avoids voices other NPCs use', $b !== null && $b !== $a);
check('pick avoids all used voices', npc_voice_pick('c-3333', $pool, ['alba', 'marius', 'javert']) === 'cosette');
check('pick reuses when every voice is taken', in_array(npc_voice_pick('c-4444', $pool, $pool), $pool, true));
check('pick of empty pool is null', npc_voice_pick('c-1', [], []) === null);

check('name from file', npc_voice_name_from_file('Steve.wav') === 'Steve');
check('name from file with spaces', npc_voice_name_from_file('Old Tom.mp3') === 'Old_Tom');
check('name from file strips folders', npc_voice_name_from_file('../../etc/evil.wav') === 'evil');
check('name from file rejects empty', npc_voice_name_from_file('.wav') === null);

check('sniff wav', npc_voice_sniff(wav_bytes()) === 'wav');
check('sniff mp3', npc_voice_sniff("ID3\x04\0\0\0\0\0\0") === 'mp3');
check('sniff ogg/flac', npc_voice_sniff('OggS....') === 'ogg' && npc_voice_sniff('fLaC....') === 'flac');
check('sniff rejects text', npc_voice_sniff('hello world') === '');

check('validate accepts a voice', npc_voice_validate('Steve.wav', 'en', '1.2') === ['Steve', 'en', 1.2]);
check('validate empty voice is automatic', npc_voice_validate('', '', '') === [null, null, null]);
check('validate rejects bad voice', is_string(npc_voice_validate('../x', '', '')));
check('validate rejects bad pitch', is_string(npc_voice_validate('alba', '', '3')));
check('validate rejects bad language', is_string(npc_voice_validate('alba', 'xx', '')));
check('kind from id', npc_voice_kind('c-1') === 'companion' && npc_voice_kind('v-1') === 'villager' && npc_voice_kind('x') === 'npc');
$row = ['voice' => 'Steve', 'language' => 'de', 'pitch' => 1.1];
check('tts uses the NPC voice and language', npc_voice_tts(['voice' => 'alba'], $row) === ['voice' => 'Steve', 'language' => 'de']);
check('tts without voice keeps language', npc_voice_tts(['voice' => 'alba'], $row, false) === ['voice' => 'alba', 'language' => 'de']);

// --- uploads against the stub ---
$log = tempnam(sys_get_temp_dir(), 'toi-stub-log');
$stub = proc_open(sprintf('exec python3 %s --port 8024 --log %s', escapeshellarg(__DIR__ . '/pockettts_stub.py'), escapeshellarg($log)),
    [0 => ['pipe', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
for ($i = 0; $i < 50 && !@fsockopen('127.0.0.1', 8024, $errno, $errstr, 0.1); $i++) {
    usleep(100000);
}
try {
    $tts = ['provider' => 'xtts', 'url' => 'http://127.0.0.1:8024'];
    $base = SpeechService::xttsBase($tts);
    check('xtts base', $base === 'http://127.0.0.1:8024');

    $one = npc_voice_add_sample(null, $base, 'Grumpy Guard.wav', wav_bytes());
    check('single upload stored and forwarded', $one['ok'] && $one['forwarded'] && $one['name'] === 'Grumpy_Guard', json_encode($one));
    check('single upload file kept in config/voices', is_file($tmp . '/config/voices/Grumpy_Guard.wav'));
    check('single upload rejects non-audio', !npc_voice_add_sample(null, $base, 'x.wav', 'not audio')['ok']);
    check('single upload rejects other extensions', !npc_voice_add_sample(null, $base, 'x.exe', wav_bytes())['ok']);

    $zip = $tmp . '/voices.zip';
    make_zip($zip, ['Steve.wav' => wav_bytes(), 'villagers/Anna Lee.wav' => wav_bytes(), 'readme.txt' => 'hi',
        'broken.wav' => 'nope', '__MACOSX/._Steve.wav' => 'x', 'dup/Steve.wav' => wav_bytes()]);
    $import = npc_voice_import_zip(null, $base, $zip, true);
    $by = [];
    foreach ($import['results'] ?? [] as $r) {
        $by[$r['file']] = $r;
    }
    check('zip imports Steve', ($by['Steve.wav']['forwarded'] ?? false) && $by['Steve.wav']['name'] === 'Steve', json_encode($import));
    check('zip imports from folders', ($by['villagers/Anna Lee.wav']['forwarded'] ?? false) && $by['villagers/Anna Lee.wav']['name'] === 'Anna_Lee');
    check('zip skips other files', isset($by['readme.txt']) && !$by['readme.txt']['ok']);
    check('zip reports bad audio', isset($by['broken.wav']) && !$by['broken.wav']['ok']);
    check('zip ignores __MACOSX', !isset($by['__MACOSX/._Steve.wav']));
    check('zip skips duplicate names', isset($by['dup/Steve.wav']) && !$by['dup/Steve.wav']['ok']);
    $speakers = SpeechService::speakerList($tts, true) ?? [];
    check('PocketTTS lists the uploaded speakers', in_array('Steve', $speakers, true) && in_array('Anna_Lee', $speakers, true), implode(',', $speakers));

    $evil = $tmp . '/evil.zip';
    make_zip($evil, ['../evil.wav' => wav_bytes()]);
    $import = npc_voice_import_zip(null, $base, $evil, false);
    check('zip with path traversal is refused', isset($import['error']), json_encode($import));
    check('nothing written outside config/voices', !is_file($tmp . '/config/evil.wav') && !is_file($tmp . '/evil.wav'));
    $abs = $tmp . '/abs.zip';
    make_zip($abs, ['/tmp/abs.wav' => wav_bytes()]);
    check('zip with absolute path is refused', isset(npc_voice_import_zip(null, $base, $abs, false)['error']));
    $empty = $tmp . '/empty.zip';
    make_zip($empty, ['notes.txt' => 'x']);
    $r = npc_voice_import_zip(null, $base, $empty, false);
    check('zip with no audio lists the skipped file', isset($r['results']) && !$r['results'][0]['ok']);
    file_put_contents($tmp . '/not.zip', 'hello');
    check('not a zip is refused', isset(npc_voice_import_zip(null, $base, $tmp . '/not.zip', false)['error']));

    $down = npc_voice_add_sample(null, 'http://127.0.0.1:9', 'Later.wav', wav_bytes());
    check('sample is kept when PocketTTS is down', $down['ok'] && !$down['forwarded']);
    check('resend works once PocketTTS answers', npc_voice_resend(null, $base, 'Later')[0]);
} finally {
    proc_terminate($stub);
    proc_close($stub);
    @unlink($log);
    exec('rm -rf ' . escapeshellarg($tmp));
}
echo $failures === 0 ? "All NPC voice tests passed.\n" : "$failures NPC voice test(s) failed.\n";
exit($failures === 0 ? 0 : 1);
