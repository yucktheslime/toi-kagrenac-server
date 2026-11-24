# A Trade of Immersion server (Minecraft)

This folder is the server half of [A Trade of Immersion](https://github.com/yucktheslime/a-trade-of-immersion), a Minecraft 1.21.1 MCA Reborn
addon. It is a copy of the [Kagrenac](https://github.com/Dwemer-Dynamics/Kagrenac) server template
by Dwemer Dynamics, imported at upstream commit `978519f`, without the template's `client/` folder.
It runs in DwemerDistro, the same way CHIM's server does. The rest of this folder's documentation
is the upstream template's and still applies.

**Players:** follow [KAGRENAC_SETUP.md](https://github.com/yucktheslime/a-trade-of-immersion/blob/main/docs/KAGRENAC_SETUP.md). It is a step-by-step guide
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

`health.php` reports `server_version` `0.3.1-toi`. Next to the template's capabilities it lists
`minecraft_actions`, `action_args`, `minecraft_events`, `bio_sync` and `recent_events`. Its
`service` stays `example-ai-server`, the template's fixed compatibility identity.

## Install and update

The server is published as its own repository,
[yucktheslime/toi-kagrenac-server](https://github.com/yucktheslime/toi-kagrenac-server), built from the
`kagrenac-server/` folder of the mod repository (`scripts/sync_server_repo.sh` there). That repository has
`dwemer-mod.json` (manifest version 2) at its root, GitHub topic `kagrenac-mod` and stable releases with
`kagrenac-release.json`, a server ZIP and a client ZIP (the NeoForge jar).

**Launcher (recommended).** In the DwemerDistro launcher open **Mods > Custom mods > Browse mods**, search
`Trade of Immersion`, select **A Trade of Immersion Server**:

- **Install server** installs only the server. Core puts it in `/var/www/html/TradeOfImmersionServer`
  (`server_folder`), protected before any file is placed, with its own database `custom_trade_of_immersion`
  and a new token. It answers at `http://127.0.0.1:19000/TradeOfImmersionServer`, which is the mod's default
  `kagrenacBaseUrl`. The installed server then shows under **Custom mods** with its dashboard link.
- **Install** with a game folder (your modpack's folder, which holds `options.txt`) also puts the NeoForge jar
  in its `mods/` folder and writes the private pairing file `mods/tradeofimmersion-kagrenac.json`
  (`server_url`, `token`). The mod reads that file, so no token setup is needed.

**Manual (fallback).** Inside DwemerDistro as the `dwemer` user:

```bash
git clone https://github.com/yucktheslime/toi-kagrenac-server.git ~/toi-kagrenac-server
bash ~/toi-kagrenac-server/scripts/deploy_toi.sh
```

`deploy_toi.sh` runs these steps:

1. `sudo ddistro_custom_mod setup-web --protect-project trade-of-immersion`, before any file
   reaches the web root.
2. Moves a copy made by the 0.3.0 script (`custom-mods/TradeOfImmersionServer`, which was not protected)
   to `/var/www/html/custom-mods/trade-of-immersion`, keeping its config, token and database.
3. Copies this folder there. It never copies or overwrites `config/config.php`, its lock, `.env` files or
   `config.json`.
4. Runs `sudo bash scripts/install.sh`: private config with a random token, the database
   `trade_of_immersion_ai`, migrations.
5. Runs a health check and fails unless `config/config.php`, `lib/`, `scripts/` and `sql/` answer 403.

To update, run `git pull` in the clone, then the same script. Do not use `scripts/update.sh` for this
install: the server copy is not a git checkout.

The manual server answers at `http://127.0.0.1:19000/custom-mods/trade-of-immersion`; set that as
`kagrenacBaseUrl`. The dashboard is the same URL in a browser.

## Testing without DwemerDistro (developers)

The test setup on Linux used PHP 8.4 and PostgreSQL 17, with `php -S` serving a folder that holds
`custom-mods/trade-of-immersion`. Set `PHP_CLI_SERVER_WORKERS=4` so `cancel.php` can run while a
turn is open. Use `llm.mode` `mock`. Then:

```bash
bash scripts/smoke.sh http://127.0.0.1:19000/custom-mods/trade-of-immersion
bash scripts/smoke_minecraft.sh http://127.0.0.1:19000/custom-mods/trade-of-immersion
```

With `php -S`, the only `smoke.sh` check that fails is "config not served": that check needs
Apache's deny rules. See [ports/minecraft/README.md](ports/minecraft/README.md) for the in-game
evidence.

## Licence

This folder is GPL-3.0-only, the same as the upstream template ([LICENSE](LICENSE),
[THIRD_PARTY_NOTICES.md](THIRD_PARTY_NOTICES.md)). The Minecraft mod (main repository) is a
separate program, LGPL-3.0-or-later. The two only talk over HTTP.
