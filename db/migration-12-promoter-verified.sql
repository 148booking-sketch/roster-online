-- Migrazione 12: flag "Verificato" per i promoter (approvazione manuale dell'admin).
-- Finché non è verificato, il promoter può accedere e inviare richieste ma non vede i cachet.
ALTER TABLE promoter_profiles
  ADD COLUMN verified TINYINT(1) NOT NULL DEFAULT 0;
