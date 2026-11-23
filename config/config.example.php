<?php
// A Trade of Immersion server settings (a copy of the Kagrenac template). scripts/install.sh copies this to config/config.php
// (only if that file is missing) and fills in a random token.
// config/config.php holds secrets: never commit it. Being a PHP file, it prints
// nothing if someone requests it through Apache.

return [
    // Shared secret. The game sends it as "Authorization: Bearer <token>".
    'token' => 'CHANGE_ME_TOKEN',

    // This example's own database. The installer creates it once.
    'database' => [
        'host' => 'localhost',
        'port' => 5432,
        'name' => 'trade_of_immersion_ai',
        'user' => 'dwemer',
        'password' => 'dwemer',
    ],

    'llm' => [
        // 'mock'       needs no model and gives predictable replies.
        // 'openai'     OpenAI, or any OpenAI-compatible API at base_url (+ /chat/completions).
        // 'openrouter' https://openrouter.ai/api/v1/chat/completions. Needs api_key.
        // 'local'      Your own OpenAI-compatible server at base_url, e.g. http://127.0.0.1:1234/v1
        // 'dwemerllm'  DwemerDistro LLM Studio at http://127.0.0.1:1234/v1/chat/completions. No key.
        // Every mode except mock needs an explicit model. See CONNECTORS.md.
        // A Trade of Immersion ships ready for OpenRouter with Gemma. Paste your OpenRouter key on
        // the dashboard (Configuration > API keys), never in chat or in a repository. Until a key
        // is set, turns answer llm_unavailable and the game falls back to its offline replies.
        // Use 'mock' for predictable test replies without any key.
        'mode' => 'openrouter',
        'mock_delay_seconds' => 0,  // 0-10. Set to 3 to try cancellation by hand.
        'base_url' => '',           // openai (optional, defaults to https://api.openai.com/v1) and local
        'model' => 'google/gemma-4-31b-it',
        'api_key' => '',            // Optional except for openrouter. Never sent in dwemerllm mode.
        'timeout_seconds' => 30,
        'max_tokens' => 250,
    ],

    // How many past lines (player + NPC) per NPC are sent to the model. 0-50.
    'history_limit' => 20,

    // A Trade of Immersion: the game named in the system prompt, and how many recent log-only game
    // events (deaths, damage, gifts, villager talk; last 30 minutes) each turn sees. 0-12.
    'game_name' => 'Minecraft',
    'recent_events_limit' => 6,

    // Event log (Control Panel > Logs): how many finished turns, cancels and restores to keep,
    // newest first. 100-100000. Independent of history_limit and checkpoints.
    'event_log_limit' => 10000,

    // The only actions the server will ever return. The game checks its own list too, and a
    // game that sends offered_actions narrows this list per turn (PROTOCOL.md). Every name below
    // has a handler in the A Trade of Immersion mod (ports/minecraft/README.md); remove a name to
    // forbid that action. All act on the request's npc.id.
    'allowed_actions' => ['follow_player', 'hold_position', 'attack_hostile', 'attack_target', 'go_to', 'mount',
        'dismount', 'equip', 'use_item', 'give_item', 'pick_up', 'inspect_item', 'talk_to_villager'],

    // OPTIONAL VOICE. Off by default. The game always gets text first. Start the matching
    // DwemerDistro service from the launcher, then set 'enabled' => true (or use the Voice page).
    'tts' => [
        'enabled' => false,
        // 'pockettts' DwemerDistro PocketTTS (audio.cpp). 'openai' any OpenAI-compatible
        // /v1/audio/speech endpoint that can return WAV (model is then required).
        'provider' => 'pockettts',
        'url' => 'http://127.0.0.1:8086/v1/audio/speech',
        'model' => 'pocket-tts',
        'voice' => 'alba',
        'api_key' => '',            // Optional, openai provider only.
        'timeout_seconds' => 30,
    ],
    'stt' => [
        'enabled' => false,
        // 'parakeet' DwemerDistro Parakeet (port 8022, sends form fields "file" and "model").
        // 'fasterwhisper' DwemerDistro faster-whisper: use
        // 'url' => 'http://127.0.0.1:9876/api/v0/transcribe' and 'form_field' => 'audio_file'.
        'provider' => 'parakeet',
        'url' => 'http://127.0.0.1:8022/v1/audio/transcriptions',
        'model' => 'whisper-1',
        'timeout_seconds' => 60,
    ],

    // A Trade of Immersion: when an NPC has no profile voice, the game's voice_hint (its gender)
    // picks one of these voices, the same one every time for that NPC. Names are PocketTTS voices;
    // change them to voices your TTS service has. An empty list uses tts.voice for everyone.
    'voice_hints' => [
        'female' => ['alba', 'fantine', 'cosette', 'eponine', 'azelma'],
        'male' => ['marius', 'javert', 'jean'],
        'neutral' => ['alba', 'marius'],
    ],

    // OPTIONAL NPC SELECTION (decision.php). Off by default: it then always answers with
    // the game's baseline NPC. It only picks who should answer; it never sends chat or actions.
    // 'mock' picks an offered NPC named in the transcript. 'jev' asks the OpenRouter
    // decisions API (needs api_key; 1.5 second limit; any failure falls back to baseline).
    'decision' => [
        // A Trade of Immersion turns this on with 'mock' (picks the companion named in the line).
        // For smarter picks use 'jev' and put your OpenRouter key in decision.api_key on API keys.
        'enabled' => true,
        'provider' => 'mock',
        'url' => 'https://openrouter.ai/api/alpha/decisions',
        'model' => 'typesafe/jev-1.13',
        'api_key' => '',
    ],

    // OPTIONAL ADVANCED MEMORY (Configuration > Memory). Off by default: turns then use only
    // bios, facts and history, as before. 'enabled' gives the model this session's saved summary
    // and newest diary entries (edit them on Roleplay > Memory). 'auto_notes' rewrites them after
    // each successful turn; 'auto_extract' proposes candidate facts that wait for your review.
    // Both call the NPC's model after the reply was sent (extra calls that may cost money).
    'memory' => [
        // On for A Trade of Immersion (CHIM-like: each NPC keeps a summary and diary per world).
        // auto_notes makes one extra model call after each reply (small extra cost).
        'enabled' => true,
        'auto_notes' => true,
        'auto_extract' => false,
    ],

    // OPTIONAL VECTOR SEARCH for facts. Off by default; the keyword search is then used alone, and
    // it is always the fallback. 'mock' needs no service (hashed words: deterministic, not
    // semantic). 'openai' posts to any OpenAI-compatible /v1/embeddings URL (no redirects); its
    // key is set on API keys. dims 0 accepts the provider's vector size; a number refuses any
    // other size. Press Reindex on the Memory settings page after enabling or changing the model.
    'embeddings' => [
        'enabled' => false,
        'provider' => 'mock',
        'url' => 'https://api.openai.com/v1/embeddings',
        'model' => 'text-embedding-3-small',
        'dims' => 0,
        'api_key' => '',
        'timeout_seconds' => 5,
    ],

    // Optional private folder for dashboard backups (Control Panel > Backups): an absolute
    // path outside the web root, owned by the web server user with mode 0700. Empty uses an
    // app-scoped folder in the system temp directory, which may be emptied on restart.
    'backup_dir' => '',
];
