<?php
// The turn pipeline, shared by turn.php and the dialogue form of event.php ("respond": true).
// This file only defines functions, so requesting it over HTTP prints nothing.
// Callers validate the request first; run_turn() then does the duplicate check, generation,
// profile, history, knowledge and memory, model call, stale check, save and event log, sends the
// reply, then runs any opt-in memory updates.

require_once __DIR__ . '/app.php';
require_once __DIR__ . '/llm.php';
require_once __DIR__ . '/action_catalog.php';
require_once __DIR__ . '/dialogue_history.php';
require_once __DIR__ . '/knowledge.php';
require_once __DIR__ . '/events.php';
require_once __DIR__ . '/profiles.php';
require_once __DIR__ . '/embeddings.php';
require_once __DIR__ . '/txt2vec.php';
require_once __DIR__ . '/minime.php';
require_once __DIR__ . '/memory_vectors.php';
require_once __DIR__ . '/memory.php';
require_once __DIR__ . '/audit.php';
require_once __DIR__ . '/plugins.php';

const MAX_OFFERED_ACTIONS = 24;

// The optional "context" object: at most 10 entries, keys a-z and _ (max 32), text values (max 200).
function require_context(mixed $context): array
{
    if (!is_array($context) || count($context) > 10) {
        fail(400, 'bad_request', 'context must be an object with at most 10 entries.');
    }
    $clean = [];
    foreach ($context as $key => $value) {
        if (!is_string($key) || !preg_match('/^[a-z_]{1,32}$/', $key)) {
            fail(400, 'bad_request', 'context keys must be 1-32 characters of a-z and _.');
        }
        $clean[$key] = require_text($value, "context.$key", 200);
    }
    return $clean;
}

// The optional "offered_actions" list (protocol 1 addition): the action names the game can run
// right now, at most MAX_OFFERED_ACTIONS (16 here: A Trade of Immersion offers more actions than the
// template's 8; check "minecraft_actions" in health capabilities). Missing means the game did not say (the allowlist alone applies); an
// empty list means it can run none. Anything else, including null, is refused rather than read as missing.
function require_offered_actions(array $input): ?array
{
    if (!array_key_exists('offered_actions', $input)) {
        return null;
    }
    $offered = $input['offered_actions'];
    if (!is_array($offered) || !array_is_list($offered) || count($offered) > MAX_OFFERED_ACTIONS) {
        fail(400, 'bad_request', 'offered_actions must be a list of at most ' . MAX_OFFERED_ACTIONS . ' action names.');
    }
    foreach ($offered as $name) {
        if (!is_string($name) || !preg_match('/^[A-Za-z0-9_]{1,40}$/D', $name)) {
            fail(400, 'bad_request', 'offered_actions must hold action names (1-40 of A-Z a-z 0-9 _).');
        }
    }
    return array_values(array_unique($offered));
}

// $turn holds npc_name, player_name, text and context. $eventType is set when an explicit
// event.php dialogue request started this turn; it is logged, the pipeline is the same.
function run_turn(array $config, float $started, string $requestId, string $sessionId, string $npcId,
                  array $turn, ?string $eventType = null): never
{
    trace_id($requestId);
    $historyLimit = max(0, min(50, (int)($config['history_limit'] ?? 10)));
    $memorySettings = memory_settings($config);
    $db = db_connect($config);
    require_schema($db);
    plugins_bootstrap($db);
    $allowedActions = $config['allowed_actions'] ?? ActionCatalog::DEFAULT_ALLOWED;
    $allowedActions = plugins_extend_allowed(is_array($allowedActions) ? $allowedActions : []);
    // The game's offered_actions can only narrow the allowlist: the prompt offers, and
    // extract_actions() returns, names in both.
    if (isset($turn['offered_actions'])) {
        $allowedActions = array_values(array_intersect($allowedActions, $turn['offered_actions']));
    }
    $dialogue = new DialogueHistory($db);

    // Every outcome after validation is logged (lib/events.php), outside any transaction.
    // "complete" means the server finished this turn, not that the game received or ran it.
    $event = [
        'kind' => 'turn',
        'request_id' => $requestId,
        'session_id' => $sessionId,
        'npc_id' => $npcId,
        'provider' => event_provider($config),
        'player_text' => $turn['text'],
        'context' => $turn['context'],
        'event_type' => $eventType,
    ];
    $logged = function (string $status, array $extra = []) use ($db, $config, &$event, $started): bool {
        $row = ['status' => $status, 'duration_ms' => event_duration_ms($started)] + $extra + $event;
        return event_log($db, $config, $row) !== null;
    };

    // 1. Each request id may be used once (turns and game events share this). Old ids are
    //    forgotten after a day.
    if (!claim_request_id($db, $requestId, $sessionId, $npcId)) {
        // Logged as its own failed event; the original request's event is unchanged.
        $logged('failed', ['error_code' => 'duplicate_request']);
        fail(409, 'duplicate_request', 'This request_id was already used.');
    }
    forget_old_request_ids($db);

    // 2. Start a new generation. A newer turn or a cancel makes this one stale.
    $generation = next_generation($db, $sessionId, $npcId);
    $event['generation'] = $generation;

    // 3. This NPC's profile (assigned, else the default, else none) decides the system prompt
    //    and any model or mode override; empty overrides keep the config (lib/profiles.php).
    $profile = profile_for_npc($db, $npcId);
    if ($profile === false) {
        $logged('failed', ['error_code' => 'internal_error']);
        fail(500, 'internal_error', 'Internal server error.');
    }
    $llm = profile_llm(is_array($config['llm'] ?? null) ? $config['llm'] : [], $profile);
    $llm['game_name'] = is_string($config['game_name'] ?? null) ? mb_substr($config['game_name'], 0, 40) : 'Minecraft';
    $event['provider'] = event_provider(['llm' => $llm]);

    // 4. Load the most recent history for this NPC, oldest first.
    $history = $dialogue->recent($sessionId, $npcId, $historyLimit);

    // 5. Optional MiniMe classification and vector search, outside any transaction and never
    //    retried. MiniMe (CHIM's /extract and optional /command) may say a memory lookup is not
    //    needed, or hint an action; any failure leaves the current behaviour in place. Vector
    //    search (TXT2VEC, OpenAI or mock) finds facts, and also diary notes and game events
    //    when a memory lookup is allowed. Keyword / newest-first search is always the fallback.
    $vectorHits = [];
    $vector = null;
    $embedding = 'off';
    $minimeResult = ['recall' => null, 'tags' => '', 'command' => null, 'action' => null, 'error' => null];
    $minime = minime_settings($config);
    if ($minime !== null) {
        $callStarted = microtime(true);
        $minimeResult = minime_classify($minime, $turn['text'], is_array($allowedActions) ? $allowedActions : []);
        audit_insert($db, $config, ['kind' => 'minime', 'provider' => 'minime',
            'status' => $minimeResult['error'] === null ? 'ok' : 'fallback', 'error_code' => $minimeResult['error'],
            'duration_ms' => event_duration_ms($callStarted), 'request_id' => audit_child_id('mm'),
            'parent_request_id' => $requestId, 'session_id' => $sessionId, 'npc_id' => $npcId,
            'detail' => $minimeResult['error'] === null
                ? ('recall=' . ($minimeResult['recall'] === null ? 'unknown' : ($minimeResult['recall'] ? 'yes' : 'no'))
                    . ($minimeResult['action'] !== null ? ', action ' . $minimeResult['action'] : ''))
                : 'minime missed; model used as fallback']);
    }
    $embeddingSettings = embedding_active_settings($config);
    $queryText = $turn['text'];
    if ($minimeResult['tags'] !== '') {
        $queryText = trim($queryText . ' ' . $minimeResult['tags']);
    }
    if ($embeddingSettings !== null) {
        $callStarted = microtime(true);
        $code = null;
        try {
            [$vector] = embed_texts($embeddingSettings, [$queryText]);
            $vectorHits = embedding_search($db, $npcId, $vector, embedding_model_id($embeddingSettings), MAX_FACTS);
            $embedding = 'ok';
        } catch (EmbeddingError $error) {
            [$embedding, $code] = ['failed', $error->getMessage()];
        }
        audit_insert($db, $config, ['kind' => 'embedding', 'provider' => $embeddingSettings['provider'] ?? 'mock',
            'status' => $embedding === 'ok' ? 'ok' : 'failed', 'error_code' => $code, 'duration_ms' => event_duration_ms($callStarted),
            'request_id' => audit_child_id('emb'), 'parent_request_id' => $requestId, 'session_id' => $sessionId, 'npc_id' => $npcId,
            'detail' => $embedding === 'ok' ? count($vectorHits) . ' vector matches' : 'keyword search used instead']);
    }

    // 6. Load this NPC's stored bio and up to three facts (NPC or global): vector matches first,
    //    then keyword matches. With advanced memory on, add this session's summary and newest
    //    diary entries. Keep a copy of exactly what was retrieved for the event log.
    $trace = null;
    $knowledge = load_knowledge($db, $npcId, $turn['text'], $trace, $vectorHits);
    if ($memorySettings['enabled']) {
        $memory = memory_load($db, $sessionId, $npcId);
        if ($memory === null) {
            $logged('failed', ['error_code' => 'internal_error']);
            fail(500, 'internal_error', 'Internal server error.');
        }
        // When TXT2VEC (or another embeddings provider) is up and Minime did not decline a
        // recall, replace the newest diary entries with the closest matches. The current
        // summary is always kept. A miss or a "No" from Minime leaves the newest entries.
        if ($memory !== null && $embedding === 'ok' && $vector !== null && $minimeResult['recall'] !== false
            && $embeddingSettings !== null) {
            $diaryHits = memory_vector_search_notes($db, $sessionId, $npcId, $vector,
                embedding_model_id($embeddingSettings), MEMORY_DIARY_IN_PROMPT);
            if ($diaryHits) {
                $memory['diary'] = array_values(array_filter(array_map(
                    fn($hit) => memory_clean($hit['text'], MEMORY_MAX_DIARY), $diaryHits)));
            }
        }
        $knowledge['memory'] = $memory;
    }
    // A Trade of Immersion: the newest log-only game events of this session (deaths, damage,
    // gifts, villager talk) so the NPC knows what just happened around it. Quoted data like the rest.
    // With a query vector and a memory lookup allowed, relevant older events can replace the
    // newest-only list; recent events fill any remaining places.
    $recentLimit = max(0, min(12, (int)($config['recent_events_limit'] ?? 6)));
    $knowledge['recent_events'] = recent_game_events($db, $sessionId, $npcId, $recentLimit);
    if ($embedding === 'ok' && $vector !== null && $minimeResult['recall'] !== false
        && $embeddingSettings !== null && $recentLimit > 0) {
        $semanticEvents = memory_vector_search_events($db, $sessionId, $npcId, $vector,
            embedding_model_id($embeddingSettings), $recentLimit);
        if ($semanticEvents) {
            $seen = array_column($semanticEvents, 'text');
            foreach ($knowledge['recent_events'] as $event) {
                if (count($semanticEvents) >= $recentLimit) {
                    break;
                }
                if (!in_array($event['text'], $seen, true)) {
                    $semanticEvents[] = $event;
                    $seen[] = $event['text'];
                }
            }
            $knowledge['recent_events'] = array_slice($semanticEvents, 0, $recentLimit);
        }
    }
    if ($minimeResult['action'] !== null) {
        $knowledge['action_hint'] = $minimeResult['action'];
    }
    $pluginContext = plugins_prompt_context(['npc_id' => $npcId, 'player_text' => $turn['text'], 'context' => $turn['context']]);
    if ($pluginContext !== '') {
        $knowledge['plugin_context'] = $pluginContext;
    }
    $facts = [];
    foreach ($knowledge['facts'] as $i => $fact) {
        $facts[] = ($trace['facts'][$i] ?? []) + $fact;
    }
    $event['retrieval'] = [
        'terms' => $trace['terms'] ?? '',
        'bio' => $knowledge['bio'],
        'facts' => $facts,
        'history_lines' => count($history),
        'recent_events' => count($knowledge['recent_events']),
        'embedding' => $embedding,
        'profile' => profile_trace($profile),
        'memory' => isset($knowledge['memory']) ? ['summary' => $knowledge['memory']['summary'] !== null,
            'diary_entries' => count($knowledge['memory']['diary'])] : null,
        'minime' => $minime === null ? null : ['recall' => $minimeResult['recall'],
            'action' => $minimeResult['action'], 'error' => $minimeResult['error']],
        'plugins' => array_keys(array_filter(plugins_state()['plugins'], fn($plugin) => !empty($plugin['enabled']))),
    ];

    // 7. Ask the model. This can be slow, so it happens outside any transaction.
    try {
        $raw = generate_reply($llm, $turn, $history, $knowledge, $profile, $allowedActions);
    } catch (Throwable $error) {
        app_log($error->getMessage());
        $logged('failed', ['error_code' => 'llm_unavailable']);
        fail(502, 'llm_unavailable', 'The language model did not answer.');
    }
    [$reply, $actions, $rejected] = extract_actions($raw, $allowedActions, $npcId);
    $event['reply'] = $reply;
    $event['actions'] = array_column($actions, 'name');
    $event['rejected_actions'] = $rejected;

    // 8. Save the exchange only if this turn is still the current generation.
    //    The session lock comes first, so a checkpoint restore cannot interleave.
    db_query($db, 'BEGIN');
    lock_session($db, $sessionId);
    $current = db_query($db,
        'SELECT generation FROM ex_generations WHERE session_id = $1 AND npc_id = $2 FOR UPDATE',
        [$sessionId, $npcId]);
    if ((int)pg_fetch_result($current, 0, 0) !== $generation) {
        db_query($db, 'ROLLBACK');
        $logged(event_stale_status($db, $sessionId, $npcId, $generation), ['error_code' => 'stale']);
        fail(409, 'stale', 'This turn was cancelled or replaced by a newer turn.');
    }
    $dialogue->appendExchange($sessionId, $npcId, $turn['text'], $reply, $historyLimit);
    db_query($db, 'COMMIT');
    $eventLogged = $logged('complete');

    $data = [
        'ok' => true,
        'request_id' => $requestId,
        'npc_id' => $npcId,
        'generation' => $generation,
        'reply' => $reply,
        'actions' => $actions,
        'rejected_actions' => $rejected,
        // Optional (protocol 1 addition): false when the event could not be stored, so
        // result.php will not accept a report for this request.
        'logged' => $eventLogged,
    ];
    if (!$memorySettings['auto_notes'] && !$memorySettings['auto_extract']) {
        send_json(200, $data);
    }
    // 9. Opt-in memory updates run after the reply was sent, so the game never waits for them.
    //    Each saves only while this turn is still the current generation (lib/memory.php).
    send_json_then(200, $data, fn() => memory_after_turn($db, $config, $llm, [
        'request_id' => $requestId, 'session_id' => $sessionId, 'npc_id' => $npcId, 'npc_name' => $turn['npc_name'],
        'text' => $turn['text'], 'reply' => $reply, 'generation' => $generation, 'history_limit' => max(2, $historyLimit),
    ]));
}
