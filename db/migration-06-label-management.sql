-- Migrazione 06: label, casa discografica, management (email) nel profilo artista
ALTER TABLE artist_profiles
  ADD COLUMN IF NOT EXISTS label             VARCHAR(160) DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS casa_discografica VARCHAR(160) DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS management        VARCHAR(190) DEFAULT NULL;
