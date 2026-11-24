<?php
// Semantic retrieval for memory notes and game events (migration 009). Uses the active
// embeddings provider (TXT2VEC, OpenAI or mock). Prefers a pgvector cosine search when the
// column exists; otherwise compares JSON arrays in PHP, the same way lib/embeddings.php
// searches facts. This file only defines functions and constants.

require_once __DIR__ . '/app.php';
require_once __DIR__ . '/embeddings.php';
require_once __DIR__ . '/txt2vec.php';
require_once __DIR__ . '/events.php';

const MEMORY_VECTOR_SCAN_LIMIT = 200;
const MEMORY_VECTOR_MIN_SCORE = 0.35;
const MEMORY_VECTOR_REINDEX_LIMIT = 64;

// Cached: whether this connection has a usable embedding_vector column on $table.
function memory_vector_has_pgvector(\PgSql\Connection $db, string $table = 'ex_memory_notes'): bool
{
    static $cache = [];
    if (!preg_match('/^ex_(memory_notes|events|knowledge)$/', $table)) {
        return false;
    }
    if (array_key_exists($table, $cache)) {
        return $cache[$table];
    }
    $result = @pg_query($db, "SELECT 1 FROM information_schema.columns
        WHERE table_schema = current_schema() AND table_name = '$table' AND column_name = 'embedding_vector'");
    $cache[$table] = $result !== false && pg_num_rows($result) === 1;
    return $cache[$table];
}

// Writes one unit vector onto a notes or events row. JSON is always stored; a pgvector copy
// is filled by the 009 trigger when that column exists. Returns whether the row was updated.
function memory_vector_store(\PgSql\Connection $db, string $table, int $id, array $vector, string $modelId): bool
{
    if (!preg_match('/^ex_(memory_notes|events)$/', $table) || ($vector = embedding_validate($vector)) === null) {
        return false;
    }
    $saved = db_try($db, "UPDATE $table SET embedding = $2::jsonb, embedding_model = $3 WHERE id = $1",
        [$id, json_encode(array_values($vector)), $modelId]);
    return $saved !== null && pg_affected_rows($saved) === 1;
}

// Cosine similarity in PHP for one stored JSON vector, or null when it cannot be used.
function memory_vector_score(mixed $json, array $query): ?float
{
    $vector = embedding_validate(is_string($json) ? json_decode($json, true, 3) : $json, count($query));
    if ($vector === null) {
        return null;
    }
    $score = 0.0;
    foreach ($query as $i => $value) {
        $score += $value * $vector[$i];
    }
    return $score;
}

// Notes (this session and NPC) closest to $query, best first. The current summary is returned
// separately even when it is not a close match, so the ongoing recap is never dropped.
// Each hit is ['id', 'kind', 'text', 'score'].
function memory_vector_search_notes(\PgSql\Connection $db, string $sessionId, string $npcId, array $query,
                                    string $modelId, int $limit): array
{
    $limit = max(1, $limit);
    if (memory_vector_has_pgvector($db, 'ex_memory_notes')) {
        $literal = '[' . implode(',', $query) . ']';
        $result = db_try($db,
            "SELECT id, kind, text, 1 - (embedding_vector <=> $2::vector) AS score
             FROM ex_memory_notes
             WHERE session_id = $1 AND npc_id = $3 AND embedding_vector IS NOT NULL AND embedding_model = $4
             ORDER BY embedding_vector <=> $2::vector, id DESC
             LIMIT $5",
            [$sessionId, $literal, $npcId, $modelId, MEMORY_VECTOR_SCAN_LIMIT]);
        if ($result === null) {
            return [];
        }
        $hits = [];
        foreach (pg_fetch_all($result) as $row) {
            $score = is_numeric($row['score']) ? (float)$row['score'] : null;
            if ($score === null || $score < MEMORY_VECTOR_MIN_SCORE) {
                continue;
            }
            $hits[] = ['id' => (int)$row['id'], 'kind' => $row['kind'], 'text' => $row['text'], 'score' => round($score, 3)];
        }
    } else {
        $result = db_try($db,
            'SELECT id, kind, text, embedding::text AS embedding FROM ex_memory_notes
             WHERE session_id = $1 AND npc_id = $2 AND embedding IS NOT NULL AND embedding_model = $3
             ORDER BY id DESC LIMIT $4',
            [$sessionId, $npcId, $modelId, MEMORY_VECTOR_SCAN_LIMIT]);
        if ($result === null) {
            return [];
        }
        $hits = [];
        foreach (pg_fetch_all($result) as $row) {
            $score = memory_vector_score($row['embedding'], $query);
            if ($score !== null && $score >= MEMORY_VECTOR_MIN_SCORE) {
                $hits[] = ['id' => (int)$row['id'], 'kind' => $row['kind'], 'text' => $row['text'], 'score' => round($score, 3)];
            }
        }
        usort($hits, fn($a, $b) => [$b['score'], $b['id']] <=> [$a['score'], $a['id']]);
    }
    $diary = array_values(array_filter($hits, fn($hit) => $hit['kind'] === 'diary'));
    return array_slice($diary, 0, $limit);
}

// Game events of this session (this NPC or none) closest to $query, best first, already cleaned.
// Same shape as recent_game_events(): ['type', 'text', 'minutes_ago', 'score'].
function memory_vector_search_events(\PgSql\Connection $db, string $sessionId, string $npcId, array $query,
                                     string $modelId, int $limit): array
{
    if ($limit <= 0) {
        return [];
    }
    $selectTime = "floor(extract(epoch FROM now() - created_at) / 60)::int AS minutes_ago";
    if (memory_vector_has_pgvector($db, 'ex_events')) {
        $literal = '[' . implode(',', $query) . ']';
        $result = db_try($db,
            "SELECT event_type, player_text, $selectTime, 1 - (embedding_vector <=> $2::vector) AS score
             FROM ex_events
             WHERE session_id = $1 AND kind = 'game_event' AND status = 'complete'
               AND (npc_id IS NULL OR npc_id = $3)
               AND embedding_vector IS NOT NULL AND embedding_model = $4
             ORDER BY embedding_vector <=> $2::vector, id DESC
             LIMIT $5",
            [$sessionId, $literal, $npcId, $modelId, MEMORY_VECTOR_SCAN_LIMIT]);
        if ($result === null) {
            return [];
        }
        $hits = [];
        foreach (pg_fetch_all($result) as $row) {
            $score = is_numeric($row['score']) ? (float)$row['score'] : null;
            if ($score === null || $score < MEMORY_VECTOR_MIN_SCORE) {
                continue;
            }
            $text = mb_substr(clean_line((string)$row['player_text']), 0, 300);
            if ($text !== '') {
                $hits[] = ['type' => (string)$row['event_type'], 'text' => $text,
                    'minutes_ago' => (int)$row['minutes_ago'], 'score' => round($score, 3)];
            }
        }
        return array_slice($hits, 0, $limit);
    }
    $result = db_try($db,
        "SELECT event_type, player_text, $selectTime, embedding::text AS embedding
         FROM ex_events
         WHERE session_id = $1 AND kind = 'game_event' AND status = 'complete'
           AND (npc_id IS NULL OR npc_id = $2)
           AND embedding IS NOT NULL AND embedding_model = $3
         ORDER BY id DESC LIMIT $4",
        [$sessionId, $npcId, $modelId, MEMORY_VECTOR_SCAN_LIMIT]);
    if ($result === null) {
        return [];
    }
    $hits = [];
    foreach (pg_fetch_all($result) as $row) {
        $score = memory_vector_score($row['embedding'], $query);
        if ($score === null || $score < MEMORY_VECTOR_MIN_SCORE) {
            continue;
        }
        $text = mb_substr(clean_line((string)$row['player_text']), 0, 300);
        if ($text !== '') {
            $hits[] = ['type' => (string)$row['event_type'], 'text' => $text,
                'minutes_ago' => (int)$row['minutes_ago'], 'score' => round($score, 3)];
        }
    }
    usort($hits, fn($a, $b) => [$b['score'], $b['minutes_ago'] * -1] <=> [$a['score'], $a['minutes_ago'] * -1]);
    return array_slice($hits, 0, $limit);
}

// How many notes and game events have a vector for $modelId, and how many still need one.
function memory_vector_stats(\PgSql\Connection $db, string $modelId): ?array
{
    $notes = @pg_query_params($db,
        "SELECT count(*) FILTER (WHERE kind IN ('summary', 'diary')),
                count(*) FILTER (WHERE kind IN ('summary', 'diary') AND embedding IS NOT NULL AND embedding_model = $1)
         FROM ex_memory_notes", [$modelId]);
    $events = @pg_query_params($db,
        "SELECT count(*) FILTER (WHERE kind = 'game_event' AND status = 'complete' AND player_text IS NOT NULL),
                count(*) FILTER (WHERE kind = 'game_event' AND status = 'complete' AND player_text IS NOT NULL
                    AND embedding IS NOT NULL AND embedding_model = $1)
         FROM ex_events", [$modelId]);
    if ($notes === false || $events === false) {
        return null;
    }
    [$noteTotal, $noteIndexed] = array_map('intval', pg_fetch_row($notes));
    [$eventTotal, $eventIndexed] = array_map('intval', pg_fetch_row($events));
    return [
        'notes_total' => $noteTotal, 'notes_indexed' => $noteIndexed, 'notes_missing' => $noteTotal - $noteIndexed,
        'events_total' => $eventTotal, 'events_indexed' => $eventIndexed, 'events_missing' => $eventTotal - $eventIndexed,
    ];
}

function memory_vector_index_text(array $row): string
{
    if (($row['kind'] ?? '') === 'game_event' || isset($row['event_type'])) {
        $type = trim((string)($row['event_type'] ?? ''));
        $text = trim((string)($row['player_text'] ?? $row['text'] ?? ''));
        return trim($type === '' ? $text : "$type: $text");
    }
    $kind = (string)($row['kind'] ?? 'note');
    return $kind . ': ' . (string)($row['text'] ?? '');
}

// Embeds up to MEMORY_VECTOR_REINDEX_LIMIT notes and game events that have no vector for the
// current model. Same contract as embedding_reindex(): batches, no transaction, stop at the
// first failure. $audit receives one connector-audit row per batch.
function memory_vector_reindex(\PgSql\Connection $db, array $settings, callable $audit): array
{
    $modelId = embedding_model_id($settings);
    $notes = pg_fetch_all(db_query($db,
        "SELECT id, kind, text, 'note' AS source FROM ex_memory_notes
         WHERE embedding IS NULL OR embedding_model IS DISTINCT FROM $1
         ORDER BY id LIMIT $2", [$modelId, MEMORY_VECTOR_REINDEX_LIMIT])) ?: [];
    $events = pg_fetch_all(db_query($db,
        "SELECT id, 'game_event' AS kind, event_type, player_text, 'event' AS source FROM ex_events
         WHERE kind = 'game_event' AND status = 'complete' AND player_text IS NOT NULL
           AND (embedding IS NULL OR embedding_model IS DISTINCT FROM $1)
         ORDER BY id LIMIT $2", [$modelId, MEMORY_VECTOR_REINDEX_LIMIT])) ?: [];
    $done = ['notes' => 0, 'events' => 0, 'error' => null];
    foreach ([['note', $notes, 'ex_memory_notes', 'notes'], ['event', $events, 'ex_events', 'events']] as [$label, $rows, $table, $countKey]) {
        foreach (array_chunk($rows, EMBEDDING_BATCH) as $batch) {
            $started = microtime(true);
            try {
                $vectors = embed_texts($settings, array_map(memory_vector_index_text(...), $batch));
            } catch (EmbeddingError $error) {
                $done['error'] = $error->getMessage();
                $audit(['status' => 'failed', 'error_code' => $done['error'], 'duration_ms' => event_duration_ms($started),
                    'detail' => "reindex $label batch of " . count($batch)]);
                return $done;
            }
            foreach ($batch as $i => $row) {
                if (memory_vector_store($db, $table, (int)$row['id'], $vectors[$i], $modelId)) {
                    $done[$countKey]++;
                }
            }
            $audit(['status' => 'ok', 'duration_ms' => event_duration_ms($started),
                'detail' => "reindex $label batch of " . count($batch)]);
        }
    }
    return $done;
}

// Best-effort: embed one already-saved note or game event. Failures are logged, never thrown.
function memory_vector_index_row(\PgSql\Connection $db, array $settings, string $table, int $id, string $text): void
{
    if ($text === '') {
        return;
    }
    try {
        [$vector] = embed_texts($settings, [$text]);
        memory_vector_store($db, $table, $id, $vector, embedding_model_id($settings));
    } catch (Throwable $error) {
        app_log('memory vector index skipped: ' . ($error instanceof EmbeddingError ? $error->getMessage() : 'error'));
    }
}
