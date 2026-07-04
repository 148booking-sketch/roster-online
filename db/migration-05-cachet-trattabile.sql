-- Migrazione 05: flag "trattabile" del cachet (sostituisce fascia/min-max nel profilo)
ALTER TABLE artist_profiles
  ADD COLUMN IF NOT EXISTS cachet_trattabile TINYINT(1) NOT NULL DEFAULT 1;
