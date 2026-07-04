-- Migrazione 03: backline & scheda tecnica artista
ALTER TABLE artist_profiles
  ADD COLUMN IF NOT EXISTS tech_sheet_url VARCHAR(255) DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS gear_bring JSON DEFAULT NULL,   -- cosa porta l'artista
  ADD COLUMN IF NOT EXISTS gear_need  JSON DEFAULT NULL;   -- cosa deve esserci sul posto
