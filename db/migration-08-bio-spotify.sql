-- Migrazione 08: flag "usa bio da Spotify" (sincronizzata dall'Artist's Pick via Apify)
ALTER TABLE artist_profiles
  ADD COLUMN IF NOT EXISTS bio_from_spotify TINYINT(1) NOT NULL DEFAULT 0;
