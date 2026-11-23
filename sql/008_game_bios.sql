-- A Trade of Immersion: bios the game pushes through npc_bio.php. Safe to run more than once.
-- source 'operator' (the default: dashboard, import_content.php, seed) is never overwritten by the
-- game; source 'game' rows are refreshed by the game until an operator saves the bio on the
-- dashboard, which makes it 'operator'.
ALTER TABLE ex_npc_bios ADD COLUMN IF NOT EXISTS source text NOT NULL DEFAULT 'operator';
ALTER TABLE ex_npc_bios DROP CONSTRAINT IF EXISTS ex_npc_bios_source_check;
ALTER TABLE ex_npc_bios ADD CONSTRAINT ex_npc_bios_source_check CHECK (source IN ('operator', 'game'));
