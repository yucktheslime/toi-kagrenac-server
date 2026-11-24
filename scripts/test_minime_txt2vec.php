<?php
// CLI checks for Minime and TXT2VEC clients, vector storage, and migration 009.
// Starts a local stub, then optionally migrates a throwaway Postgres database twice.
// Usage:
//   php scripts/test_minime_txt2vec.php
//   EXAMPLE_AI_CONFIG=/path/to/config.php php scripts/test_minime_txt2vec.php
// Exit 0 on success, 1 on failure.

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require __DIR__ . '/../lib/app.php';
require __DIR__ . '/../lib/minime.php';
require __DIR__ . '/../lib/txt2vec.php';
require __DIR__ . '/../lib/memory_vectors.php';

$failures = 0;
$stubProc = null;

function fail_check(string $name, string $detail): void
{
    global $failures;
    $failures++;
    fwrite(STDERR, "FAIL $name: $detail\n");
}

function pass_check(string $name): void
{
    echo "PASS $name\n";
}

function expect_true(string $name, bool $ok, string $detail = ''): void
{
    $ok ? pass_check($name) : fail_check($name, $detail !== '' ? $detail : 'expected true');
}

function find_free_port(): int
{
    $sock = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
    if ($sock === false) {
        throw new RuntimeException("cannot bind test port: $errstr");
    }
    $name = stream_socket_get_name($sock, false);
    fclose($sock);
    $port = (int)substr(strrchr($name, ':'), 1);
    if ($port < 1) {
        throw new RuntimeException('cannot read test port');
    }
    return $port;
}

function start_stub(): array
{
    global $stubProc;
    $port = find_free_port();
    $cmd = [PHP_BINARY, '-S', "127.0.0.1:$port", __DIR__ . '/minime_txt2vec_stub.php'];
    $stubProc = proc_open($cmd, [0 => ['pipe', 'r'], 1 => ['file', '/tmp/toi-minime-stub.out', 'w'],
        2 => ['file', '/tmp/toi-minime-stub.err', 'w']], $pipes, __DIR__ . '/..');
    if (!is_resource($stubProc)) {
        throw new RuntimeException('cannot start stub server');
    }
    fclose($pipes[0]);
    $deadline = microtime(true) + 5;
    $ok = false;
    while (microtime(true) < $deadline) {
        $errno = 0;
        $errstr = '';
        $conn = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.2);
        if (is_resource($conn)) {
            fclose($conn);
            $ok = true;
            break;
        }
        usleep(50000);
    }
    if (!$ok) {
        throw new RuntimeException('stub server did not start');
    }
    return ['url' => "http://127.0.0.1:$port", 'timeout_seconds' => 2, 'dims' => 0];
}

function stop_stub(): void
{
    global $stubProc;
    if (is_resource($stubProc)) {
        $status = proc_get_status($stubProc);
        if (!empty($status['pid'])) {
            posix_kill($status['pid'], SIGTERM);
        }
        proc_close($stubProc);
        $stubProc = null;
    }
}

register_shutdown_function('stop_stub');

// --- client tests against the stub ---
$settings = start_stub();

$probe = minime_probe($settings);
expect_true('minime probe', !empty($probe['ok']), $probe['detail'] ?? 'probe failed');

$extracted = minime_extract($settings, 'Do you remember the closed bridge?');
expect_true('minime extract recall yes', ($extracted['is_memory_recall'] ?? '') === 'Yes',
    json_encode($extracted));

$chatter = minime_extract($settings, 'Hello there friend');
expect_true('minime extract recall no', ($chatter['is_memory_recall'] ?? '') === 'No',
    json_encode($chatter));

$classified = minime_classify($settings, 'Please follow me', ['follow_player', 'hold_position', 'attack_hostile', 'give_item']);
expect_true('minime maps follow', $classified['action'] === 'follow_player' && $classified['error'] === null,
    json_encode($classified));

$sit = minime_classify($settings, 'Please sit down and wait', ['follow_player', 'hold_position']);
expect_true('minime maps TakeASeat to hold_position', $sit['action'] === 'hold_position', json_encode($sit));

$txtProbe = txt2vec_probe($settings);
expect_true('txt2vec probe', !empty($txtProbe['ok']) && ($txtProbe['dims'] ?? 0) === 32, $txtProbe['detail'] ?? '');

$bridge = txt2vec_embed($settings, 'the closed stone bridge');
$other = txt2vec_embed($settings, 'a green apple pie');
$again = txt2vec_embed($settings, 'remember the closed stone bridge nearby');
$bridgeScore = 0.0;
$otherScore = 0.0;
foreach ($bridge as $i => $value) {
    $bridgeScore += $value * $again[$i];
    $otherScore += $value * $other[$i];
}
expect_true('txt2vec similar texts score higher', $bridgeScore > $otherScore && $bridgeScore >= 0.35,
    "bridge=$bridgeScore other=$otherScore");

$batched = embed_texts(['provider' => 'txt2vec', 'url' => $settings['url'], 'timeout_seconds' => 2],
    ['alpha wolf', 'beta fish']);
expect_true('embed_texts txt2vec batch', count($batched) === 2 && count($batched[0]) === 32, 'bad batch');

expect_true('minime action map talk is empty', minime_map_action('Talk', ['follow_player']) === null, 'Talk mapped');

// --- fallback when the stub is stopped ---
stop_stub();
try {
    minime_extract($settings, 'hello');
    fail_check('minime down throws', 'expected MinimeError');
} catch (MinimeError $error) {
    pass_check('minime down throws');
}
$soft = minime_classify($settings, 'follow me', ['follow_player']);
expect_true('minime classify falls back', $soft['error'] !== null && $soft['action'] === null && $soft['recall'] === null,
    json_encode($soft));
try {
    txt2vec_embed($settings, 'hello');
    fail_check('txt2vec down throws', 'expected EmbeddingError');
} catch (EmbeddingError $error) {
    pass_check('txt2vec down throws');
}

// --- migration twice + vector store/search, when a test config is available ---
$configFile = getenv('EXAMPLE_AI_CONFIG') ?: '';
if ($configFile === '' || !is_file($configFile)) {
    echo "SKIP database tests: set EXAMPLE_AI_CONFIG to a test config.php\n";
} else {
    $config = load_config();
    $db = db_connect($config);
    $migrate = [PHP_BINARY, __DIR__ . '/migrate.php'];
    for ($i = 1; $i <= 2; $i++) {
        $env = getenv();
        $env['EXAMPLE_AI_CONFIG'] = $configFile;
        $proc = proc_open($migrate, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, __DIR__ . '/..', $env);
        $out = stream_get_contents($pipes[1]);
        $err = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $code = proc_close($proc);
        $combined = trim($out . "\n" . $err);
        if ($code !== 0) {
            fail_check("migrate pass $i", "exit $code: $combined");
            break;
        }
        if ($i === 1) {
            expect_true('migrate first pass', str_contains($combined, '009_memory_vectors') || str_contains($combined, 'already applied: 009_memory_vectors'),
                $combined);
        } else {
            expect_true('migrate second pass is a no-op', str_contains($combined, 'already applied: 009_memory_vectors')
                && !preg_match('/^applied: /m', $combined), $combined);
        }
    }
    $problem = schema_problem($db);
    expect_true('schema ready', $problem === null, $problem[0] ?? 'schema_problem');

    $session = 'test-minime-sess';
    $npc = 'npc_test_minime';
    db_query($db, "DELETE FROM ex_memory_notes WHERE session_id = $1", [$session]);
    db_query($db, "DELETE FROM ex_events WHERE session_id = $1", [$session]);
    $summary = pg_fetch_result(db_query($db,
        "INSERT INTO ex_memory_notes (session_id, npc_id, kind, text, source)
         VALUES ($1, $2, 'summary', 'They talked about a closed stone bridge.', 'mock') RETURNING id",
        [$session, $npc]), 0, 0);
    $bridgeNote = pg_fetch_result(db_query($db,
        "INSERT INTO ex_memory_notes (session_id, npc_id, kind, text, source)
         VALUES ($1, $2, 'diary', 'I remember the closed stone bridge west of town.', 'mock') RETURNING id",
        [$session, $npc]), 0, 0);
    $pieNote = pg_fetch_result(db_query($db,
        "INSERT INTO ex_memory_notes (session_id, npc_id, kind, text, source)
         VALUES ($1, $2, 'diary', 'We ate a green apple pie at the inn.', 'mock') RETURNING id",
        [$session, $npc]), 0, 0);
    $eventId = pg_fetch_result(db_query($db,
        "INSERT INTO ex_events (kind, status, request_id, session_id, npc_id, event_type, player_text)
         VALUES ('game_event', 'complete', 'req-bridge-1', $1, $2, 'world_note', 'The stone bridge was closed.')
         RETURNING id", [$session, $npc]), 0, 0);

    $mock = ['enabled' => true, 'provider' => 'mock'];
    $modelId = embedding_model_id($mock);
    foreach ([
        [(int)$summary, 'summary: They talked about a closed stone bridge.'],
        [(int)$bridgeNote, 'diary: I remember the closed stone bridge west of town.'],
        [(int)$pieNote, 'diary: We ate a green apple pie at the inn.'],
    ] as [$id, $text]) {
        [$vector] = embed_texts($mock, [$text]);
        expect_true("store note $id", memory_vector_store($db, 'ex_memory_notes', $id, $vector, $modelId), "store failed for $id");
    }
    [$eventVector] = embed_texts($mock, ['world_note: The stone bridge was closed.']);
    expect_true('store event', memory_vector_store($db, 'ex_events', (int)$eventId, $eventVector, $modelId), 'event store failed');

    [$query] = embed_texts($mock, ['Do you remember the closed stone bridge?']);
    $notes = memory_vector_search_notes($db, $session, $npc, $query, $modelId, 3);
    $topText = $notes[0]['text'] ?? '';
    expect_true('semantic diary prefers the bridge', str_contains($topText, 'bridge') && !str_contains($topText, 'apple pie'),
        json_encode($notes));

    $events = memory_vector_search_events($db, $session, $npc, $query, $modelId, 3);
    expect_true('semantic events finds the bridge', isset($events[0]['text']) && str_contains($events[0]['text'], 'bridge'),
        json_encode($events));

    $stats = memory_vector_stats($db, $modelId);
    expect_true('vector stats count indexed rows', $stats !== null && $stats['notes_indexed'] >= 3 && $stats['events_indexed'] >= 1,
        json_encode($stats));

    $hasPg = memory_vector_has_pgvector($db, 'ex_memory_notes');
    echo ($hasPg ? 'INFO' : 'INFO') . ' pgvector on ex_memory_notes: ' . ($hasPg ? 'yes' : 'no (PHP cosine used)') . "\n";

    db_query($db, "DELETE FROM ex_memory_notes WHERE session_id = $1", [$session]);
    db_query($db, "DELETE FROM ex_events WHERE session_id = $1", [$session]);
}

if ($failures > 0) {
    echo "$failures check(s) failed.\n";
    exit(1);
}
echo "All Minime/TXT2VEC checks passed.\n";
