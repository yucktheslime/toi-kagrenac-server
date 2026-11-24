# Minecraft port record (A Trade of Immersion)

The game side lives outside this folder. It is the Java mod at the repository root: `common/`,
`neoforge/`, `fabric/`. The main classes are `ai/KagrenacBackend.java` (protocol client and reply
validation), `ai/KagrenacWorld.java` (event log and bio sync), `ai/CompanionBrain.java` (turns,
cancel, results), `voice/VoiceSync.java` and `client/voice/*` (positional voice).

## Integration

| Part | How |
|---|---|
| Player input | Normal Minecraft chat, addressed by name, by looking at the NPC, or as the owner within 10 blocks. `/npc say` works from anywhere. With 2 or more of your own companions near and none chosen, `decision.php` picks one. |
| NPC identity | Companions are `c-<entity uuid>`, MCA villagers `v-<entity uuid>`. The session is `mc-<world name>`, or `kagrenacSessionId`. |
| HTTP off the game thread | `java.net.http` async; results are applied on the server thread. |
| Reply display | Chat line `<Name> text`. Villager lines go through MCA's own chat. |
| Audio | The server sends `VoiceSync` packets to clients within 64 blocks. Each client fetches `speak.php` and streams the WAV, downmixed to mono, through `SoundSource.VOICE`. The sound follows the entity (linear attenuation over `voiceRange`). |
| Game awareness | `context`: location (biome, coordinates, dimension), time of day and weather, health, held items, task, nearby creatures and things, nearby villagers. `recent_events` comes from the event log. |
| Action bridge | Config allowlist ∩ server capabilities → `offered_actions`. Each reply is validated again on the client (names offered, 4 max, no repeats, `target_id` = this NPC, `value` pattern, required values), then run by the mod's `ActionExecutor`. Outcomes go to `result.php` as handled, rejected or failed. Later outcomes (arrived, mounted, picked up) are logged as `action_result` events. |
| Saves, offline, cancel | The mod's own memory files stay the offline fallback. With the server down, the status shows `unreachable` and replies fall back to the mock when `aiFallbackToMock` is set. A new chat line during a pending reply calls `cancel.php`, and the reply that is cancelled or replaced is dropped. |

## Evidence (2026-11-23)

Setup: the NeoForge dev client (`runClient`, MCA 7.7.36). The server was this folder on `php -S`
with `PHP_CLI_SERVER_WORKERS=4`, PostgreSQL 17 and **`llm.mode` = `mock`** (no real model). TTS was
real Kyutai pocket-tts behind a small OpenAI-compatible wrapper on port 8086, not the DwemerDistro
PocketTTS build. Audio was captured with OpenAL Soft's wave-file output, not heard by a person.

| Check | Result (rows in `ex_events`) |
|---|---|
| `/npc ai status` | `ready, server 0.3.1-toi, schema 008_game_bios, Minecraft actions (13 offered); token set; voice on` |
| `follow_player`, `hold_position` | handled (`following Dev`, `staying here`) |
| `mount` / `dismount` (boat) | handled, plus the logged action_result `mounted Oak Boat` |
| `go_to: home` | failed `can't see 'home'` (no home set), reported to result.php, and the NPC reacted to it |
| `pick_up: bread` | handled. Logged `picked up 2 item stack(s)` |
| `equip: iron sword` | handled `equipped Iron Sword in mainhand` |
| `use_item: bread` | handled (the result read "ate Air"; fixed: the item name is now read before eating) |
| `give_item: bread` | failed `no 'bread' to give` (correct: the only bread had been eaten) |
| `inspect_item: iron sword` | handled, with tooltip and stats |
| `attack_target: zombie` | handled. Then `npc_hurt` events, then an `entity_died` event (`Zombie was killed by Elara`) |
| "what happened?" | the reply was built from `recent_events` (the mount and the kill) |
| `talk_to_villager: Guillaume` (summoned MCA villager) | handled. The villager answered through its own `v-` turn, `villager_spoke` was logged, the companion reacted, and the exchange stopped at the limit |
| Bios | Both companions and the villager are in `ex_npc_bios` with `source = game`. The villager's bio includes "Family: child of Jovo and Becky." |
| Cancel | a second line during a 3 s mock delay: `cancel` complete, the first turn `cancelled`/`stale`, the second turn answered |
| Decision | an unnamed line with two companions near: a `decision` connector call (mock → baseline) |
| Voice | `voiced Elara: 1.7s of audio`. The captured stereo output had voice energy at the lines' times, with unequal left and right levels (positional) |
| Not tested | `attack_hostile` on its own; a real OpenRouter model; Apache/DwemerDistro deployment; the Fabric client; a dedicated server with remote clients |
