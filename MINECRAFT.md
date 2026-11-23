# A Trade of Immersion server (Minecraft)

This folder is the server half of [A Trade of Immersion](../README.md), a Minecraft 1.21.1 MCA Reborn
addon. It is a copy of the [Kagrenac](https://github.com/Dwemer-Dynamics/Kagrenac) server template
by Dwemer Dynamics, imported at upstream commit `978519f`, without the template's `client/` folder.
It runs in DwemerDistro, the same way CHIM's server does. The rest of this folder's documentation
is the upstream template's and still applies.

**Players:** follow [docs/KAGRENAC_SETUP.md](../docs/KAGRENAC_SETUP.md). It is a step-by-step guide
for Windows and CurseForge.

## What differs from the template

| Area | Change |
|---|---|
| Actions | 13 Minecraft actions in `lib/action_catalog.php`, all on in `config/config.example.php` `allowed_actions`. Up to 16 offered, 4 per reply. |
| Action arguments | `[ACTION:name: argument]` becomes `args.value` ([PROTOCOL.md](PROTOCOL.md#a-trade-of-immersion-extension-minecraft)). |
| Events | Extra event.php types: `entity_died`, `npc_hurt`, `player_hurt`, `villager_spoke`, `relationship`, `action_result`, `world_note`. Recent log-only events go into the prompt. |
| Bios | `npc_bio.php` stores game-generated bios (companions and MCA villagers: personality, profession, family). Migration `008_game_bios` adds `ex_npc_bios.source`. The game never overwrites a bio saved on the dashboard. |
| Voice | `speak.php` `voice_hint` with per-gender voice lists (`voice_hints` in config). |
| Defaults | Database `trade_of_immersion_ai`. LLM `openrouter` / `google/gemma-4-31b-it`, with the key set on the dashboard. NPC selection on. Memory on. `game_name` `Minecraft`. |
| Mock | The mock LLM knows a keyword for each action (for tests without a key). It never turns a game event's text into actions (it echoes the event instead). |
| Install | `scripts/deploy_toi.sh` installs from a clone of the mod repository (see below). |
| Tests | `scripts/smoke_minecraft.sh` (14 checks) next to the template's `scripts/smoke.sh`. |

`health.php` reports `server_version` `0.3.0-toi`. Next to the template's capabilities it lists
`minecraft_actions`, `action_args`, `minecraft_events`, `bio_sync` and `recent_events`. Its
`service` stays `example-ai-server`, the template's fixed compatibility identity.

## Install and update

The server lives in a subfolder of the mod repository, so the launcher route (which needs
`dwemer-mod.json` at the repository root) is not used. Use the manual route, inside DwemerDistro
as the `dwemer` user:

```bash
git clone https://github.com/yucktheslime/a-trade-of-immersion.git ~/a-trade-of-immersion
bash ~/a-trade-of-immersion/kagrenac-server/scripts/deploy_toi.sh
```

`deploy_toi.sh` runs these steps:

1. `sudo ddistro_custom_mod setup-web --protect-project TradeOfImmersionServer`, before any file
   reaches the web root.
2. Copies this folder to `/var/www/html/custom-mods/TradeOfImmersionServer`. It never copies or
   overwrites `config/config.php`, its lock, `.env` files or `config.json`.
3. Runs `sudo bash scripts/install.sh`: private config with a random token, the database, migrations.
4. Runs a health check and checks that `config/config.php` is not served.

To update, run `git pull` in the clone, then the same script. Do not use `scripts/update.sh` for this
install: the server copy is not a git checkout.

The server answers at `http://127.0.0.1:19000/custom-mods/TradeOfImmersionServer`. The dashboard is
the same URL in a browser.

## Testing without DwemerDistro (developers)

The test setup on Linux used PHP 8.4 and PostgreSQL 17, with `php -S` serving a folder that holds
`custom-mods/TradeOfImmersionServer`. Set `PHP_CLI_SERVER_WORKERS=4` so `cancel.php` can run while a
turn is open. Use `llm.mode` `mock`. Then:

```bash
bash scripts/smoke.sh http://127.0.0.1:19000/custom-mods/TradeOfImmersionServer
bash scripts/smoke_minecraft.sh http://127.0.0.1:19000/custom-mods/TradeOfImmersionServer
```

With `php -S`, the only `smoke.sh` check that fails is "config not served": that check needs
Apache's deny rules. See [ports/minecraft/README.md](ports/minecraft/README.md) for the in-game
evidence.

## Licence

This folder is GPL-3.0-only, the same as the upstream template ([LICENSE](LICENSE),
[THIRD_PARTY_NOTICES.md](THIRD_PARTY_NOTICES.md)). The Minecraft mod in the rest of the repository
is a separate program, LGPL-3.0-or-later. The two only talk over HTTP.
