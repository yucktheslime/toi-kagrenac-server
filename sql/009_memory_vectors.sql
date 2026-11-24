-- Optional vector columns for semantic memory recall (Minime/TXT2VEC or any embeddings provider).
-- Safe to run more than once; keeps every existing row and adds none.
--
-- JSON arrays (1-4096 unit-length numbers) are always stored. If this Postgres has the pgvector
-- extension (CHIM's DwemerDistro usually does), a parallel vector column is also added so
-- searches can use cosine distance in SQL. If the extension is missing or cannot be created,
-- only the JSON columns are added and PHP compares vectors instead.
--
-- Vectors belong to one embedding_model id, the same rule as ex_knowledge in migration 006.
-- Changing a note's text, or a game event's text or type, clears its vector.

ALTER TABLE ex_memory_notes ADD COLUMN IF NOT EXISTS embedding jsonb
    CHECK (embedding IS NULL OR (CASE WHEN jsonb_typeof(embedding) = 'array'
        THEN jsonb_array_length(embedding) BETWEEN 1 AND 4096 AND char_length(embedding::text) <= 100000
        ELSE false END));
ALTER TABLE ex_memory_notes ADD COLUMN IF NOT EXISTS embedding_model text
    CHECK (char_length(embedding_model) BETWEEN 1 AND 120);

ALTER TABLE ex_events ADD COLUMN IF NOT EXISTS embedding jsonb
    CHECK (embedding IS NULL OR (CASE WHEN jsonb_typeof(embedding) = 'array'
        THEN jsonb_array_length(embedding) BETWEEN 1 AND 4096 AND char_length(embedding::text) <= 100000
        ELSE false END));
ALTER TABLE ex_events ADD COLUMN IF NOT EXISTS embedding_model text
    CHECK (char_length(embedding_model) BETWEEN 1 AND 120);

CREATE OR REPLACE FUNCTION ex_memory_notes_clear_embedding() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    IF NEW.text IS DISTINCT FROM OLD.text THEN
        NEW.embedding := NULL;
        NEW.embedding_model := NULL;
        BEGIN
            NEW.embedding_vector := NULL;
        EXCEPTION WHEN undefined_column THEN
            NULL;
        END;
    END IF;
    RETURN NEW;
END
$$;
DROP TRIGGER IF EXISTS ex_memory_notes_clear_embedding ON ex_memory_notes;
CREATE TRIGGER ex_memory_notes_clear_embedding BEFORE UPDATE ON ex_memory_notes
    FOR EACH ROW EXECUTE FUNCTION ex_memory_notes_clear_embedding();

CREATE OR REPLACE FUNCTION ex_events_clear_embedding() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    IF NEW.player_text IS DISTINCT FROM OLD.player_text
       OR NEW.event_type IS DISTINCT FROM OLD.event_type THEN
        NEW.embedding := NULL;
        NEW.embedding_model := NULL;
        BEGIN
            NEW.embedding_vector := NULL;
        EXCEPTION WHEN undefined_column THEN
            NULL;
        END;
    END IF;
    RETURN NEW;
END
$$;
DROP TRIGGER IF EXISTS ex_events_clear_embedding ON ex_events;
CREATE TRIGGER ex_events_clear_embedding BEFORE UPDATE ON ex_events
    FOR EACH ROW EXECUTE FUNCTION ex_events_clear_embedding();


-- Keep the 006 fact-clear trigger in sync: a topic/fact edit also drops a pgvector copy.
CREATE OR REPLACE FUNCTION ex_knowledge_clear_embedding() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    IF NEW.topic IS DISTINCT FROM OLD.topic OR NEW.fact IS DISTINCT FROM OLD.fact THEN
        NEW.embedding := NULL;
        NEW.embedding_model := NULL;
        BEGIN
            NEW.embedding_vector := NULL;
        EXCEPTION WHEN undefined_column THEN
            NULL;
        END;
    END IF;
    RETURN NEW;
END
$$;
DROP TRIGGER IF EXISTS ex_knowledge_clear_embedding ON ex_knowledge;
CREATE TRIGGER ex_knowledge_clear_embedding BEFORE UPDATE ON ex_knowledge
    FOR EACH ROW EXECUTE FUNCTION ex_knowledge_clear_embedding();

-- Best-effort pgvector: never fail the migration if the extension is absent or not allowed.
DO $ext$
BEGIN
    BEGIN
        CREATE EXTENSION IF NOT EXISTS vector;
    EXCEPTION WHEN OTHERS THEN
        RAISE NOTICE 'pgvector is not available: %', SQLERRM;
    END;

    IF EXISTS (SELECT 1 FROM pg_extension WHERE extname = 'vector') THEN
        ALTER TABLE ex_knowledge ADD COLUMN IF NOT EXISTS embedding_vector vector;
        ALTER TABLE ex_memory_notes ADD COLUMN IF NOT EXISTS embedding_vector vector;
        ALTER TABLE ex_events ADD COLUMN IF NOT EXISTS embedding_vector vector;

        CREATE OR REPLACE FUNCTION ex_sync_embedding_vector() RETURNS trigger LANGUAGE plpgsql AS $fn$
        BEGIN
            IF NEW.embedding IS NULL THEN
                NEW.embedding_vector := NULL;
            ELSE
                BEGIN
                    NEW.embedding_vector := (NEW.embedding::text)::vector;
                EXCEPTION WHEN OTHERS THEN
                    NEW.embedding_vector := NULL;
                END;
            END IF;
            RETURN NEW;
        END
        $fn$;

        DROP TRIGGER IF EXISTS ex_knowledge_sync_embedding_vector ON ex_knowledge;
        CREATE TRIGGER ex_knowledge_sync_embedding_vector BEFORE INSERT OR UPDATE ON ex_knowledge
            FOR EACH ROW EXECUTE FUNCTION ex_sync_embedding_vector();
        DROP TRIGGER IF EXISTS ex_memory_notes_sync_embedding_vector ON ex_memory_notes;
        CREATE TRIGGER ex_memory_notes_sync_embedding_vector BEFORE INSERT OR UPDATE ON ex_memory_notes
            FOR EACH ROW EXECUTE FUNCTION ex_sync_embedding_vector();
        DROP TRIGGER IF EXISTS ex_events_sync_embedding_vector ON ex_events;
        CREATE TRIGGER ex_events_sync_embedding_vector BEFORE INSERT OR UPDATE ON ex_events
            FOR EACH ROW EXECUTE FUNCTION ex_sync_embedding_vector();

        -- Copy any JSON vectors already stored on facts (migration 006) into the new column.
        UPDATE ex_knowledge SET embedding_vector = (embedding::text)::vector
            WHERE embedding IS NOT NULL AND embedding_vector IS NULL;
    END IF;
END
$ext$;

-- Connector audit may record MiniMe calls (lib/audit.php AUDIT_KINDS).
ALTER TABLE ex_connector_calls DROP CONSTRAINT IF EXISTS ex_connector_calls_kind_check;
ALTER TABLE ex_connector_calls ADD CONSTRAINT ex_connector_calls_kind_check
    CHECK (kind IN ('tts', 'stt', 'decision', 'embedding', 'memory', 'extraction', 'minime'));
