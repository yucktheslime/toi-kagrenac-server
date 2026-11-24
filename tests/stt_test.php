<?php
// Tests lib/stt.php against tests/stt_stub.py (stand-ins for DwemerDistro Parakeet on 8022,
// faster-whisper on 9876 and a whisper.cpp server on 8080). Those ports must be free.
//   php tests/stt_test.php
// Needs php-curl, php-mbstring and python3.

require __DIR__ . '/../lib/stt.php';
ini_set('error_log', '/dev/null');

$failures = 0;
$stubs = [];

function check(string $name, bool $ok, string $detail = ''): void
{
    global $failures;
    echo ($ok ? 'PASS ' : 'FAIL ') . $name . ($ok || $detail === '' ? '' : " ($detail)") . PHP_EOL;
    $failures += $ok ? 0 : 1;
}

function stub(int $port, string $mode): void
{
    global $stubs;
    $cmd = sprintf('exec python3 %s --port %d --mode %s', escapeshellarg(__DIR__ . '/stt_stub.py'), $port, $mode);
    $stubs[$port] = proc_open($cmd, [0 => ['pipe', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
    for ($i = 0; $i < 50; $i++) {
        if (@fsockopen('127.0.0.1', $port, $errno, $errstr, 0.1)) {
            return;
        }
        usleep(100000);
    }
    throw new RuntimeException("stub on $port did not start");
}

function stop(int $port): void
{
    global $stubs;
    if (!isset($stubs[$port])) {
        return;
    }
    proc_terminate($stubs[$port]);
    proc_close($stubs[$port]);
    unset($stubs[$port]);
    for ($i = 0; $i < 30 && @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.05); $i++) {
        usleep(50000);
    }
}

function wav(bool $silent = false): string
{
    $pcm = '';
    for ($i = 0; $i < 1600; $i++) {
        $pcm .= pack('v', $silent ? 0 : (int)(8000 * sin($i / 5)) & 0xFFFF);
    }
    return 'RIFF' . pack('V', 36 + strlen($pcm)) . 'WAVEfmt ' . pack('VvvVVvv', 16, 1, 1, 16000, 32000, 2, 16) . 'data' . pack('V', strlen($pcm)) . $pcm;
}

try {
    // parsing and cleaning
    check('parse json text', SttService::parse('{"text":"  follow   me "}') === 'follow me');
    check('parse plain text', SttService::parse("hello there\n") === 'hello there');
    check('parse html is null', SttService::parse('<html>oops</html>') === null);
    check('blank audio tag is empty', SttService::parse('{"text":"[BLANK_AUDIO]"}') === null);
    check('url picks format', SttService::providerForUrl('http://x:9/inference') === 'whispercpp'
        && SttService::providerForUrl('http://x:9/api/v0/transcribe') === 'fasterwhisper'
        && SttService::providerForUrl('http://x:9/v1/audio/transcriptions') === 'openai');
    check('old configs are faster-whisper', SttService::provider(['enabled' => true]) === 'fasterwhisper');

    // nothing running: auto fails cleanly
    $r = SttService::transcribe(['provider' => 'auto'], wav());
    check('auto with nothing running fails', !$r['ok'] && str_contains($r['detail'], '8022'), json_encode($r));

    stub(8022, 'parakeet');
    $r = SttService::transcribe(['provider' => 'parakeet', 'url' => 'http://127.0.0.1:8022/v1/audio/transcriptions', 'model' => 'whisper-1'], wav());
    check('parakeet', $r['ok'] && $r['text'] === 'hello from parakeet fields=model auth=no', json_encode($r));
    $r = SttService::transcribe(['provider' => 'parakeet'], wav(true));
    check('parakeet silence is an error', !$r['ok'] && $r['detail'] === 'empty transcript', json_encode($r));
    $r = SttService::transcribe(['provider' => 'auto'], wav());
    check('auto finds parakeet', $r['ok'] && $r['provider'] === 'parakeet', json_encode($r));
    $r = SttService::transcribe(['provider' => 'openai', 'url' => 'http://127.0.0.1:8022/v1/audio/transcriptions', 'model' => 'm', 'api_key' => 'k'], wav());
    check('openai-compatible with key', $r['ok'] && $r['text'] === 'hello from parakeet fields=model,response_format auth=yes', json_encode($r));
    $r = SttService::transcribe(['provider' => 'parakeet', 'url' => 'http://127.0.0.1:8022/wrong'], wav());
    check('wrong path is HTTP 404', !$r['ok'] && $r['status'] === 404, json_encode($r));
    stop(8022);

    stub(9876, 'fasterwhisper');
    $r = SttService::transcribe(['provider' => 'fasterwhisper', 'url' => 'http://127.0.0.1:9876/api/v0/transcribe'], wav());
    check('faster-whisper', $r['ok'] && str_starts_with($r['text'], 'hello from fasterwhisper'), json_encode($r));
    $r = SttService::transcribe(['provider' => 'auto'], wav());
    check('auto finds faster-whisper', $r['ok'] && $r['provider'] === 'fasterwhisper', json_encode($r));
    stop(9876);

    stub(8080, 'whispercpp');
    $r = SttService::transcribe(['provider' => 'whispercpp'], wav());
    check('whisper.cpp', $r['ok'] && $r['text'] === 'hello from whispercpp fields=response_format,temperature auth=no', json_encode($r));
    $r = SttService::transcribe(['provider' => 'auto', 'url' => 'http://127.0.0.1:8080/inference'], wav());
    check('auto with a whisper.cpp URL', $r['ok'] && $r['provider'] === 'whispercpp', json_encode($r));
    stop(8080);
} finally {
    foreach (array_keys($stubs) as $port) {
        stop($port);
    }
}
echo $failures === 0 ? "All STT tests passed\n" : "$failures STT test(s) failed\n";
exit($failures === 0 ? 0 : 1);
