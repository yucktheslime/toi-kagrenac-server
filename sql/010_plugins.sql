-- Per-plugin enable state and settings. Safe to run more than once.
-- The plugin code itself lives on disk (bundled under lib/plugins/bundled/,
-- user-installed under config/plugins/), not in the database.

CREATE TABLE IF NOT EXISTS ex_plugins (
    plugin_id text PRIMARY KEY,
    enabled boolean NOT NULL DEFAULT false,
    settings jsonb NOT NULL DEFAULT '{}'::jsonb,
    installed_from text NOT NULL DEFAULT 'bundled',
    source text,
    installed_at timestamptz NOT NULL DEFAULT now(),
    updated_at timestamptz NOT NULL DEFAULT now(),
    CONSTRAINT ex_plugins_id_check CHECK (plugin_id ~ '^[a-z][a-z0-9_]{1,39}$'),
    CONSTRAINT ex_plugins_from_check CHECK (installed_from IN ('bundled', 'zip', 'github'))
);
