# Writing Kagrenac server plugins

Plugins add optional behaviour to this server: extra NPC actions, extra prompt
context (for example knowledge of another Minecraft mod's items), and handlers
for `game_event`. They are PHP code that runs with the same privileges as the
server itself.

**Only install plugins you wrote or that you fully trust.** A plugin can read
`config/config.php` (including API keys and the server token) and the database.
The dashboard shows this warning, and every install, enable, settings-save and
remove action also requires the server token from `config/config.php`.

## Where plugins live

There are two roots:

| Root | Who writes it | Survives a launcher update? | Served over HTTP? |
|---|---|---|---|
| `lib/plugins/bundled/<id>/` | This repository (example plugins) | Yes, it is tracked and shipped in the server package | No. Core denies `lib/` on Kagrenac-style installs. |
| `config/plugins/<id>/` | You, from a zip or a GitHub URL | Yes. Core's release update keeps the private config, its lock, and unknown files. `config/` is already untracked, like `config.php`. | No. Core denies `config/` (and `config/plugins/` under it). |

Do not put plugin PHP under a served path such as the project root or `ui/`.
DwemerDistro Core's web protection only denies `client/`, `ports/`, `docs/`,
`config/`, `lib/`, `sql/` and `scripts/` at the project root, plus `config.json`
and `.env` at any depth. User plugins are therefore stored next to the private
config on purpose.

`scripts/install.sh` creates `config/plugins/` with the same owner as
`config/config.php` (the web user, checkout-owner group). `scripts/backup.sh`
copies that folder into the backup as `plugins.tar`.

Enabled state and settings are stored in the `ex_plugins` table, so they
survive both code updates and a plugin file being replaced.

## Using the dashboard

1. Update the server (launcher **Update**, or `git pull` plus `sudo bash scripts/install.sh` / `php scripts/migrate.php`) so migration `010_plugins` is applied.
2. Open the dashboard: **Configuration → Plugins**.
3. Read the warning. Enter the server token.
4. Enable **Mod item knowledge** and, if you want, edit the fact list and save.
5. Enable **Sample wave action** if you want the extra `[ACTION:wave]` tag.
6. To add a third-party plugin, upload a zip or paste a GitHub URL. New plugins are installed **disabled**. Review the files under `config/plugins/<id>/`, then enable them.

In game, run `/npc ai status` so the client refreshes `health.php` (it will list
enabled plugin action names in `plugin_actions`). Then talk to a companion.

A plugin action is offered to the model only when it is enabled, added to the
server allowlist, *and* included in the game's `offered_actions`. The Minecraft
mod adds `plugin_actions` from health to that list and treats unknown plugin
actions as a simple gesture (the NPC swings an arm). Prompt injectors apply to
every turn once the plugin is enabled; you do not need a game change for those.

## Layout of a plugin

A plugin is a folder whose name is the plugin id, containing at least:

```
my_plugin/
  plugin.json
  plugin.php
  README.md          (optional)
  LICENSE            (optional)
```

Only those names, plus other `*.md` or `*.txt` files, are accepted. No
subfolders, symbolic links, or other extensions. The folder name must be the
same as `id` in the manifest.

### plugin.json

```json
{
  "id": "my_plugin",
  "name": "My plugin",
  "version": "1.0.0",
  "description": "One or two sentences the dashboard can show.",
  "hooks": ["prompt", "action", "game_event"],
  "settings": {
    "facts": {
      "type": "textarea",
      "label": "Facts",
      "description": "Shown under the field on the Plugins page.",
      "max": 4000,
      "default": "A fact."
    }
  }
}
```

Rules:

- `id` is 2-40 characters: a lowercase letter, then `a-z`, `0-9` or `_`.
- `version` is 1-32 characters of `A-Z a-z 0-9 . _ + -`.
- `hooks` is a non-empty list of unique values from `prompt`, `action`,
  `game_event`.
- `settings` is optional. At most 16 keys, each 1-32 characters of `a-z`,
  `0-9` and `_`. Types: `text`, `textarea`, `number`, `checkbox`, `select`
  (select needs an `options` list).

### plugin.php

The file is included only after the manifest is validated and only while the
plugin is enabled. It must `return` either an associative array of hook
callables, or an object with methods of the same names:

```php
<?php
return [
    'prompt' => function (array $context): ?string {
        $facts = trim((string)($context['settings']['facts'] ?? ''));
        return $facts !== '' ? $facts : null;
    },
    'action' => function (array $context): array {
        return [
            [
                'name' => 'wave',
                'when' => 'only when you agree to wave or greet someone',
                'arg' => 'optional',       // none | optional | required
                'arg_hint' => 'who you are waving at',
            ],
        ];
    },
    'game_event' => function (array $event): void {
        // $event has type, text, context, npc_id, session_id, settings.
        // Do not log untrusted text; a type code is enough.
    },
];
```

Context passed to `prompt` and `action` includes `plugin_id`, `settings`,
and for `prompt` also `npc_id`, `player_text` and the turn's game `context`.
Hook exceptions are caught and logged; they do not fail the turn. Prompt text
is cleaned (control characters stripped, `[` and `]` replaced so it cannot
form an `[ACTION:...]` tag) and length-capped before it is added to the
model's reference data as `plugin_context`.

Action names must be 1-40 characters of `A-Z a-z 0-9 _` and must not replace
a built-in action such as `follow_player`. At most eight actions per plugin
are registered. They are merged into the server allowlist when the plugin is
enabled; the game can still narrow the list with `offered_actions`.

## Installing from a zip or GitHub

The zip must be at most 2 MB and unpack to at most 8 MB and 50 files. Only
STORE and DEFLATE are accepted. Path traversal, absolute paths, Zip64,
encryption, symbolic links and special files are refused. `plugin.json` and
`plugin.php` must sit in the same folder, at the zip root or one wrapper
folder down (GitHub's `repo-branch/` archive layout).

Accepted GitHub URLs (HTTPS, `github.com` only, no query string or user
info):

- `https://github.com/owner/repo` (tries the `main` branch, then `master`)
- `https://github.com/owner/repo/tree/branch`
- `https://github.com/owner/repo/archive/refs/heads/branch.zip`
- `https://github.com/owner/repo/releases/download/tag/file.zip`

The download follows redirects only to `github.com`, `codeload.github.com`
and GitHub release asset hosts.

A plugin id that is already present (bundled or user-installed) is refused;
remove the existing one first. Bundled examples cannot be deleted, only
disabled.

## Testing

Against a mock-mode install, after enabling the example plugins:

- A turn whose text contains `mod item` should mention the configured facts
  (the mock echoes `plugin_context`).
- A turn whose text contains `[ACTION:wave]`, with `wave` in
  `offered_actions`, should return `actions: [{ "name": "wave", ... }]`.
- `GET health.php` lists `"plugins"` in `capabilities` and the enabled
  action names in `plugin_actions`.

Without a running server:

```bash
php tests/plugins_test.php
```

That checks manifest validation, hook behaviour with the mock LLM, zip
install, path traversal, symlinks, unexpected files, and GitHub URL parsing
(no network).

## Shipped examples

- **Mod item knowledge** (`mod_item_knowledge`): a `prompt` hook. The
  settings box is a list of facts, one per line, injected into every turn.
- **Sample wave action** (`sample_wave`): an `action` hook that offers
  `wave`, and a `game_event` hook that writes only the event type to the
  PHP error log.
