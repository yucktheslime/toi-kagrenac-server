<?php
// A Trade of Immersion addition: the game's /npc voice command. POST with the server token.
//   {"protocol":1,"action":"list"}                                   speakers PocketTTS has, and known NPCs with their voices
//   {"protocol":1,"action":"get","npc":"<npc id or name>"}           one NPC's voice
//   {"protocol":1,"action":"set","npc":"<npc id or name>","voice":"<speaker or empty>",
//    "language":"<optional code>","pitch":<optional 0.5-2.0>,"npc_name":"<optional>"}
// A set from the game is stored with source 'game'; an empty voice clears it so the NPC gets an
// automatic one again. Names may match several NPCs; then nothing changes and the matches are listed.

require_once __DIR__ . '/lib/app.php';
require_once __DIR__ . '/lib/speech.php';
require_once __DIR__ . '/lib/npc_voices.php';

$config = start_request('POST');
$input = read_json_body();
$action = is_string($input['action'] ?? null) ? $input['action'] : '';
$db = db_connect($config);
require_schema($db);
$tts = is_array($config['tts'] ?? null) ? $config['tts'] : [];

$resolve = function () use ($db, $input): array {
    $key = $input['npc'] ?? null;
    if (!is_string($key) || trim($key) === '' || mb_strlen($key) > 64) {
        fail(400, 'bad_request', 'npc must be an NPC id or name (max 64 characters).');
    }
    $key = trim($key);
    $found = npc_voice_find($db, $key);
    if ($found === [] && preg_match('/^[cv]-[A-Za-z0-9_.:-]{1,62}$/D', $key)) {
        $found = [['npc_id' => $key, 'npc_name' => '']];
    }
    return $found;
};

if ($action === 'list') {
    $npcs = array_map(fn($r) => ['id' => $r['npc_id'], 'name' => $r['npc_name'], 'kind' => $r['kind'],
        'voice' => $r['voice'], 'source' => $r['source'], 'language' => $r['language'], 'pitch' => $r['pitch']],
        npc_voice_list($db, 100));
    $speakers = SpeechService::speakerList($tts, true);
    send_json(200, ['ok' => true, 'speakers' => $speakers ?? [], 'tts_found' => $speakers !== null, 'npcs' => $npcs]);
}
if ($action === 'get' || $action === 'set') {
    $found = $resolve();
    if ($found === []) {
        fail(404, 'not_found', 'No NPC with that name or id has spoken to this server yet.');
    }
    if (count($found) > 1) {
        send_json(409, ['ok' => false, 'error' => ['code' => 'ambiguous', 'message' => 'Several NPCs have that name. Use the id.'],
            'matches' => array_map(fn($r) => ['id' => $r['npc_id'], 'name' => $r['npc_name']], $found)]);
    }
    $npcId = $found[0]['npc_id'];
    if ($action === 'set') {
        $checked = npc_voice_validate($input['voice'] ?? '', $input['language'] ?? '', $input['pitch'] ?? '');
        if (is_string($checked)) {
            fail(400, 'bad_request', $checked);
        }
        [$voice, $language, $pitch] = $checked;
        $speakers = SpeechService::speakerList($tts, true);
        if ($voice !== null && is_array($speakers) && $speakers !== []
            && !in_array(strtolower($voice), array_map('strtolower', $speakers), true)) {
            send_json(400, ['ok' => false, 'error' => ['code' => 'unknown_voice', 'message' => "PocketTTS has no speaker '$voice'."],
                'speakers' => $speakers]);
        }
        // Language and pitch not sent are kept as they are.
        $current = npc_voice_get($db, $npcId);
        if (!array_key_exists('language', $input) && is_array($current)) {
            $language = $current['language'];
        }
        if (!array_key_exists('pitch', $input) && is_array($current)) {
            $pitch = $current['pitch'];
        }
        $name = is_string($input['npc_name'] ?? null) ? $input['npc_name'] : '';
        if (!npc_voice_set($db, $npcId, $voice, $language, $pitch, 'game', $name)) {
            fail(500, 'save_failed', 'The voice could not be saved.');
        }
    }
    $row = npc_voice_get($db, $npcId);
    send_json(200, ['ok' => true, 'npc' => ['id' => $npcId, 'name' => is_array($row) ? $row['npc_name'] : $found[0]['npc_name'],
        'voice' => is_array($row) ? $row['voice'] : null, 'source' => is_array($row) ? $row['source'] : 'auto',
        'language' => is_array($row) ? $row['language'] : null, 'pitch' => is_array($row) ? $row['pitch'] : null]]);
}
fail(400, 'bad_request', 'action must be list, get or set.');
