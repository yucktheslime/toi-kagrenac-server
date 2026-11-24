#!/usr/bin/env bash
# A Trade of Immersion checks, on top of scripts/smoke.sh, against a mock-mode install whose
# allowed_actions is the shipped Minecraft list (config/config.example.php).
# Usage: bash scripts/smoke_minecraft.sh [base_url]
#   base_url defaults to http://127.0.0.1:19000/TradeOfImmersionServer
set -uo pipefail

APP_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
CONFIG="${EXAMPLE_AI_CONFIG:-$APP_DIR/config/config.php}"
BASE="${1:-http://127.0.0.1:19000/TradeOfImmersionServer}"
TOKEN="$(php -r '$c = require $argv[1]; echo $c["token"];' "$CONFIG")"
RUN="$(date +%s)$RANDOM"
NPC="c-smoke$RUN"
failures=0

post() {
    curl -sS -m 30 -w '\n%{http_code}' -H 'Content-Type: application/json' -H "Authorization: Bearer $TOKEN" --data "$2" "$BASE/$1"
}
expect() {
    local status="${4##*$'\n'}"
    if [ "$status" = "$2" ] && [[ "$4" == *"$3"* ]]; then
        echo "PASS $1"
    else
        echo "FAIL $1 (wanted HTTP $2 containing $3): ${4//$'\n'/ }"
        failures=$((failures + 1))
    fi
}
OFFER='["follow_player","hold_position","attack_hostile","attack_target","go_to","mount","dismount","equip","use_item","give_item","pick_up","inspect_item","talk_to_villager"]'
turn() { # <request_id> <text>
    printf '{"protocol":1,"request_id":"%s","session_id":"mc-smoke-%s","npc":{"id":"%s","name":"Elara"},"player":{"name":"Steve"},"context":{"biome":"plains"},"text":"%s","offered_actions":%s}' "$1" "$RUN" "$NPC" "$2" "$OFFER"
}

expect "health lists minecraft_actions" 200 '"minecraft_actions"' "$(curl -sS -m 10 -w '\n%{http_code}' "$BASE/health.php")"
expect "health lists plugins" 200 '"plugins"' "$(curl -sS -m 10 -w '\n%{http_code}' "$BASE/health.php")"
expect "health lists plugin_actions" 200 '"plugin_actions"' "$(curl -sS -m 10 -w '\n%{http_code}' "$BASE/health.php")"
expect "13 offered actions accepted" 200 '"name":"follow_player"' "$(post turn.php "$(turn "m-$RUN-1" 'follow me')")"
expect "equip with argument" 200 "{\"name\":\"equip\",\"args\":{\"target_id\":\"$NPC\",\"value\":\"iron sword\"}}" \
    "$(post turn.php "$(turn "m-$RUN-2" 'equip your iron sword')")"
expect "result for argument action" 200 '"repeat":false' "$(post result.php "{\"protocol\":1,\"request_id\":\"m-$RUN-2\",\"session_id\":\"mc-smoke-$RUN\",\"npc_id\":\"$NPC\",\"results\":[{\"name\":\"equip\",\"status\":\"handled\",\"reason\":\"now holding Iron Sword\"}]}")"
expect "talk_to_villager" 200 '"value":"anna"' "$(post turn.php "$(turn "m-$RUN-3" 'talk to anna')")"
expect "optional argument missing is fine" 200 '{"name":"dismount","args":{"target_id"' "$(post turn.php "$(turn "m-$RUN-4" 'get off')")"
expect "bio stored" 200 '"stored":1' "$(post npc_bio.php "{\"protocol\":1,\"npcs\":[{\"id\":\"$NPC\",\"name\":\"Elara\",\"role\":\"Companion\",\"bio\":\"A cheerful explorer who loves boats.\"}]}")"
expect "bio unchanged" 200 '"unchanged":1' "$(post npc_bio.php "{\"protocol\":1,\"npcs\":[{\"id\":\"$NPC\",\"name\":\"Elara\",\"role\":\"Companion\",\"bio\":\"A cheerful explorer who loves boats.\"}]}")"
expect "bio used" 200 'cheerful explorer' "$(post turn.php "$(turn "m-$RUN-5" 'who are you')")"
expect "bio bad id" 400 'bad_request' "$(post npc_bio.php '{"protocol":1,"npcs":[{"id":"bad id","name":"X","bio":"Y"}]}')"
expect "minecraft event log only" 200 '"logged":true' "$(post event.php "{\"protocol\":1,\"request_id\":\"m-$RUN-6\",\"session_id\":\"mc-smoke-$RUN\",\"type\":\"entity_died\",\"npc\":{\"id\":\"$NPC\",\"name\":\"Elara\"},\"text\":\"A zombie died nearby.\"}")"
expect "recent events reach the turn" 200 'A zombie died nearby.' "$(post turn.php "$(turn "m-$RUN-7" 'what happened')")"
expect "unknown event type" 400 'type must be' "$(post event.php "{\"protocol\":1,\"request_id\":\"m-$RUN-8\",\"session_id\":\"mc-smoke-$RUN\",\"type\":\"explode\",\"text\":\"x\"}")"

# Parser checks without HTTP: an argument that is not plain text, or a missing required one, is rejected.
parse="$(php -r 'require $argv[1]; echo json_encode([extract_actions("Ok [ACTION:equip: ../etc/passwd]", ["equip"], "n"),
    extract_actions("Ok [ACTION:equip]", ["equip"], "n"), extract_actions("Ok [ACTION:go_to: 10 64 -5]", ["go_to"], "n")]);' "$APP_DIR/lib/llm.php")"
if [[ "$parse" == '[["Ok",[],["equip"]],["Ok",[],["equip"]],["Ok",[{"name":"go_to","args":{"target_id":"n","value":"10 64 -5"}}],[]]]' ]]; then
    echo "PASS argument parser"
else
    echo "FAIL argument parser: $parse"; failures=$((failures + 1))
fi

# Hook tests (manifest, mock LLM, zip install) do not need the running server.
if php "$APP_DIR/tests/plugins_test.php"; then
    echo "PASS plugin unit tests"
else
    echo "FAIL plugin unit tests"
    failures=$((failures + 1))
fi

if [ "$failures" -gt 0 ]; then
    echo "$failures check(s) failed."
    exit 1
fi
echo "All Minecraft checks passed."
