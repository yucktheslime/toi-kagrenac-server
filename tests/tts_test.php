<?php
// Tests lib/speech.php against tests/pockettts_stub.py (a stand-in for DwemerDistro PocketTTS).
// Starts and stops the stub itself on 127.0.0.1 ports 8024, 8020 and 8086; they must be free.
//   php tests/tts_test.php
// Needs php-curl and python3. No database: profile lookups are skipped.

if (!function_exists('pg_connect')) {
    function pg_connect(...$args) { return false; }
}
require __DIR__ . '/../lib/speech.php';
ini_set('error_log', '/dev/null');

$failures = 0;
$stubs = [];
$log = tempnam(sys_get_temp_dir(), 'toi-stub-log');

function check(string $name, bool $ok, string $detail = ''): void
{
    global $failures;
    echo ($ok ? 'PASS ' : 'FAIL ') . $name . ($ok || $detail === '' ? '' : " ($detail)") . PHP_EOL;
    $failures += $ok ? 0 : 1;
}

function stub(int $port, string $mode = 'xtts', string $speakers = ''): void
{
    global $stubs, $log;
    $cmd = sprintf('exec python3 %s --port %d --mode %s --log %s %s', escapeshellarg(__DIR__ . '/pockettts_stub.py'),
        $port, $mode, escapeshellarg($log), $speakers !== '' ? '--speakers ' . escapeshellarg($speakers) : '');
    $stubs[$port] = proc_open($cmd, [0 => ['pipe', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
    for ($i = 0; $i < 50; $i++) {
        if (@fsockopen('127.0.0.1', $port, $errno, $errstr, 0.1)) {
            return;
        }
        usleep(100000);
    }
    throw new RuntimeException("stub on $port did not start");
}

function stop_all(): void
{
    global $stubs;
    foreach ($stubs as $port => $proc) {
        proc_terminate($proc);
        proc_close($proc);
        for ($i = 0; $i < 30 && @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.05); $i++) {
            usleep(50000);
        }
    }
    $stubs = [];
}

function reset_case(): void
{
    global $log;
    foreach (glob(sys_get_temp_dir() . '/toi-tts-*.json') ?: [] as $file) {
        @unlink($file);
    }
    file_put_contents($log, '');
}

function last_post(): ?array
{
    global $log;
    $lines = array_filter(explode("\n", (string)file_get_contents($log)));
    return $lines ? json_decode(end($lines), true) : null;
}

function speak(array $tts, ?string $npc = null, ?string $hint = null, array $config = []): array
{
    $config += ['database' => ['host' => '127.0.0.1', 'port' => 1], 'voice_hints' => [
        'female' => ['alba', 'fantine', 'cosette', 'eponine', 'azelma'], 'male' => ['marius', 'javert', 'jean'], 'neutral' => ['alba', 'marius']]];
    $speech = SpeechService::forNpc($config, $tts + ['enabled' => true, 'timeout_seconds' => 10], $npc, $hint);
    return $speech->synthesize('Hello traveler.');
}

register_shutdown_function('stop_all');

// 1. DwemerDistro PocketTTS on 8024, auto provider: CHIM's request shape.
reset_case();
stub(8024);
[$wav, $detail] = speak(['provider' => 'auto', 'url' => 'http://127.0.0.1:8024', 'voice' => 'marius']);
$post = last_post();
check('auto finds PocketTTS on 8024 and gets a WAV', is_string($wav) && is_wav($wav), $detail);
check('request goes to /tts_to_audio', ($post['path'] ?? '') === '/tts_to_audio', json_encode($post));
check('body is exactly {text, speaker_wav, language}', ($post['body'] ?? null) === ['text' => 'Hello traveler.', 'speaker_wav' => 'marius', 'language' => 'en'], json_encode($post));
check('sent as JSON', str_starts_with((string)($post['content_type'] ?? ''), 'application/json'));
check('audit detail names the route', str_contains($detail, 'xtts port 8024') && !str_contains($detail, '127.0.0.1') && str_contains($detail, 'marius'), $detail);

// 2. Missing voice: tts.voice, then the first speaker.
[$wav, $detail] = speak(['provider' => 'auto', 'url' => 'http://127.0.0.1:8024', 'voice' => 'lydia']);
check('missing voice falls back to the first speaker', (last_post()['body']['speaker_wav'] ?? '') === 'alba', $detail);
check('case and .wav are tolerated', SpeechService::mapVoice('Marius.wav', [], ['alba', 'marius']) === 'marius');

// 3. Language handling.
speak(['provider' => 'auto', 'url' => 'http://127.0.0.1:8024', 'voice' => 'alba', 'language' => 'de']);
check('language de is sent', (last_post()['body']['language'] ?? '') === 'de');
speak(['provider' => 'auto', 'url' => 'http://127.0.0.1:8024', 'voice' => 'alba', 'language' => '']);
check('blank language is left out', !array_key_exists('language', last_post()['body'] ?? ['language' => 1]));
speak(['provider' => 'auto', 'url' => 'http://127.0.0.1:8024', 'voice' => 'alba', 'language' => 'klingon']);
check('unknown language becomes en', (last_post()['body']['language'] ?? '') === 'en');

// 4. Hints pick from the real speakers (built-in alba/marius/javert).
$male = [];
foreach (['npc_a', 'npc_b', 'npc_c', 'npc_d', 'npc_e'] as $npc) {
    speak(['provider' => 'auto', 'url' => 'http://127.0.0.1:8024', 'voice' => 'alba'], $npc, 'male');
    $male[] = last_post()['body']['speaker_wav'] ?? '';
}
check('male hint only uses marius/javert', array_diff($male, ['marius', 'javert']) === [] && count(array_unique($male)) === 2, implode(',', $male));
speak(['provider' => 'auto', 'url' => 'http://127.0.0.1:8024', 'voice' => 'alba'], 'npc_a', 'male');
check('same NPC keeps its voice', (last_post()['body']['speaker_wav'] ?? '') === $male[0]);
speak(['provider' => 'auto', 'url' => 'http://127.0.0.1:8024', 'voice' => 'marius'], 'npc_f', 'female');
check('female hint uses alba (the only listed female speaker)', (last_post()['body']['speaker_wav'] ?? '') === 'alba');
stop_all();

// 5. CHIM-style speaker names (uploaded samples), none of the hint voices present.
reset_case();
stub(8024, 'xtts', 'femaleeventoned,femalenord,malebrute,malenord,svenja');
$picked = ['female' => [], 'male' => []];
foreach (['npc_1', 'npc_2', 'npc_3', 'npc_4', 'npc_5', 'npc_6'] as $npc) {
    foreach (['female', 'male'] as $hint) {
        speak(['provider' => 'xtts', 'url' => 'http://127.0.0.1:8024/tts_to_audio', 'voice' => 'alba'], $npc, $hint);
        $picked[$hint][] = last_post()['body']['speaker_wav'] ?? '';
    }
}
check('female hint picks female* speakers', array_diff($picked['female'], ['femaleeventoned', 'femalenord']) === [], implode(',', $picked['female']));
check('male hint picks male* speakers, never female*', array_diff($picked['male'], ['malebrute', 'malenord']) === [], implode(',', $picked['male']));
speak(['provider' => 'xtts', 'url' => 'http://127.0.0.1:8024/', 'voice' => 'alba']);
check('xtts with a missing configured voice uses the first speaker', (last_post()['body']['speaker_wav'] ?? '') === 'femaleeventoned');
speak(['provider' => 'dwemer-pockettts', 'url' => 'http://127.0.0.1:8024', 'voice' => 'svenja']);
check('dwemer-pockettts is the same provider', (last_post()['body']['speaker_wav'] ?? '') === 'svenja');
$described = SpeechService::describe(['voice_hints' => ['male' => ['marius']]], ['provider' => 'auto', 'url' => 'http://127.0.0.1:8024', 'voice' => 'alba']);
check('describe lists speakers and hint picks', $described['ok'] && $described['mode'] === 'xtts' && count($described['speakers']) === 5
    && ((array)$described['hints'])['male'] === ['malebrute', 'malenord'] && $described['voice'] === 'femaleeventoned', json_encode($described));
stop_all();

// 6. An older config (pockettts, 8086) while only PocketTTS on 8024 runs: the user's case.
reset_case();
stub(8024);
[$wav, $detail] = speak(['provider' => 'pockettts', 'url' => 'http://127.0.0.1:8086/v1/audio/speech', 'model' => 'pocket-tts', 'voice' => 'javert']);
check('old pockettts/8086 config falls over to 8024', is_string($wav) && (last_post()['path'] ?? '') === '/tts_to_audio'
    && (last_post()['body']['speaker_wav'] ?? '') === 'javert', $detail);
[$wav, $detail] = speak(['provider' => 'auto', 'url' => 'http://127.0.0.1:8086/v1/audio/speech', 'voice' => 'javert']);
check('auto with the old URL also finds 8024', is_string($wav), $detail);
stop_all();

// 7. Only audio.cpp PocketTTS on 8086: auto uses the OpenAI-style route.
reset_case();
stub(8086, 'audio_cpp');
[$wav, $detail] = speak(['provider' => 'auto', 'url' => 'http://127.0.0.1:8024', 'voice' => 'alba']);
$post = last_post();
check('auto falls back to audio.cpp on 8086', is_string($wav) && ($post['path'] ?? '') === '/v1/audio/speech', $detail);
check('audio.cpp body', ($post['body']['model'] ?? '') === 'pocket-tts' && ($post['body']['input'] ?? '') === 'Hello traveler.' && ($post['body']['voice'] ?? '') === 'alba', json_encode($post));
stop_all();

// 8. Nothing running.
reset_case();
[$wav, $detail] = speak(['provider' => 'auto', 'url' => 'http://127.0.0.1:8024', 'voice' => 'alba']);
check('nothing running fails cleanly', $wav === null && str_contains($detail, 'no voice service found'), $detail);
$described = SpeechService::describe([], ['provider' => 'auto', 'url' => 'http://127.0.0.1:8024']);
check('describe explains what to start', !$described['ok'] && str_contains($described['message'], 'Start PocketTTS'), json_encode($described));
[$wav, $detail] = speak(['provider' => 'xtts', 'url' => 'http://127.0.0.1:8024', 'voice' => 'alba']);
check('xtts with nothing running fails cleanly', $wav === null && str_contains($detail, 'HTTP 0'), $detail);

@unlink($log);
echo $failures === 0 ? "All TTS tests passed.\n" : "$failures TTS test(s) failed.\n";
exit($failures === 0 ? 0 : 1);
