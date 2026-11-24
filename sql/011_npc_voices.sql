-- A Trade of Immersion: one voice profile per NPC (companions and MCA villagers), and the voice
-- samples uploaded on the dashboard. Safe to run more than once.
--
-- ex_npc_voices: npc_id is the id the game sends (c-<uuid> companions, v-<uuid> villagers).
-- source 'auto' rows are picked by the server the first time an NPC speaks (a distinct speaker,
-- by gender when the speaker names say so); 'operator' rows were set on the dashboard and 'game'
-- rows with /npc voice in game. An auto pick never replaces an operator or game choice.
CREATE TABLE IF NOT EXISTS ex_npc_voices (
    npc_id text PRIMARY KEY CHECK (npc_id ~ '^[A-Za-z0-9_.:-]{1,64}$'),
    npc_name text NOT NULL DEFAULT '' CHECK (char_length(npc_name) <= 64),
    kind text NOT NULL DEFAULT 'npc' CHECK (kind IN ('companion', 'villager', 'npc')),
    gender text NOT NULL DEFAULT '' CHECK (gender IN ('', 'female', 'male', 'neutral')),
    voice text CHECK (voice ~ '^[A-Za-z0-9_.-]{1,64}$'),
    language text CHECK (language ~ '^[a-z]{2}(-[a-z]{2})?$'),
    pitch real CHECK (pitch BETWEEN 0.5 AND 2.0),
    source text NOT NULL DEFAULT 'auto' CHECK (source IN ('auto', 'operator', 'game')),
    last_seen timestamptz,
    updated_at timestamptz NOT NULL DEFAULT now()
);
CREATE INDEX IF NOT EXISTS ex_npc_voices_voice_idx ON ex_npc_voices (voice);
CREATE INDEX IF NOT EXISTS ex_npc_voices_name_idx ON ex_npc_voices (lower(npc_name));

-- Samples uploaded on the dashboard (one by one or in a zip). The audio itself is kept in
-- config/voices/ (never served by the web server) and forwarded to PocketTTS /upload_sample.
CREATE TABLE IF NOT EXISTS ex_voice_samples (
    name text PRIMARY KEY CHECK (name ~ '^[A-Za-z0-9][A-Za-z0-9_.-]{0,63}$'),
    filename text NOT NULL CHECK (char_length(filename) <= 200),
    bytes integer NOT NULL CHECK (bytes >= 0),
    sha256 text NOT NULL CHECK (sha256 ~ '^[0-9a-f]{64}$'),
    forwarded boolean NOT NULL DEFAULT false,
    detail text NOT NULL DEFAULT '' CHECK (char_length(detail) <= 300),
    uploaded_at timestamptz NOT NULL DEFAULT now()
);
