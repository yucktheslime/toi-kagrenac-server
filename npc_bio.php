<?php
// A Trade of Immersion addition: POST the game's NPC bios so they show on the dashboard's
// NPC bios page and reach the model like any stored bio. See PROTOCOL.md, "POST npc_bio.php".
// The game is not trusted: ids, names and text are validated and size-capped like a turn.
// A bio an operator saved (dashboard, import or seed; source 'operator') is never overwritten.

require_once __DIR__ . '/lib/app.php';

const MAX_BIOS_PER_REQUEST = 16;

$config = start_request('POST');
$input = read_json_body();
$npcs = $input['npcs'] ?? null;
if (!is_array($npcs) || !array_is_list($npcs) || count($npcs) < 1 || count($npcs) > MAX_BIOS_PER_REQUEST) {
    fail(400, 'bad_request', 'npcs must be a list of 1-' . MAX_BIOS_PER_REQUEST . ' objects.');
}
$rows = [];
foreach ($npcs as $i => $npc) {
    if (!is_array($npc)) {
        fail(400, 'bad_request', "npcs[$i] must be an object.");
    }
    $id = require_id($npc['id'] ?? null, "npcs[$i].id");
    $role = isset($npc['role']) && $npc['role'] !== '' ? require_text($npc['role'], "npcs[$i].role", 64) : '';
    $rows[$id] = [$id, require_text($npc['name'] ?? null, "npcs[$i].name", 64), $role,
        require_text($npc['bio'] ?? null, "npcs[$i].bio", 1000)];
}

$db = db_connect($config);
require_schema($db);
$stored = 0;
$kept = 0;
foreach ($rows as $row) {
    // Insert, or refresh a game-written row; an operator row is left as it is.
    $result = db_query($db,
        "INSERT INTO ex_npc_bios (npc_id, name, role, bio, source) VALUES ($1, $2, $3, $4, 'game')
         ON CONFLICT (npc_id) DO UPDATE SET name = $2, role = $3, bio = $4, updated_at = now()
         WHERE ex_npc_bios.source = 'game'
           AND (ex_npc_bios.name, ex_npc_bios.role, ex_npc_bios.bio) IS DISTINCT FROM ($2, $3, $4)
         RETURNING 1", $row);
    if (pg_num_rows($result) > 0) {
        $stored++;
    } else {
        $kept++;
    }
}
send_json(200, ['ok' => true, 'stored' => $stored, 'unchanged' => $kept]);
