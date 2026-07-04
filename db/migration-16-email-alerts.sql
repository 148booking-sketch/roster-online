-- Migrazione 16: alert email per i promoter (nuovi artisti, artisti in promo, richieste
-- evase) con gestione esplicita del consenso e della frequenza (off/daily/weekly/monthly).

ALTER TABLE promoter_profiles
  ADD COLUMN email_freq         ENUM('off','daily','weekly','monthly') NOT NULL DEFAULT 'off',
  ADD COLUMN email_consent_at   DATETIME DEFAULT NULL,   -- quando è stato dato l'ultimo consenso esplicito
  ADD COLUMN email_last_sent_at DATETIME DEFAULT NULL,   -- ultimo digest inviato (finestra "cosa c'è di nuovo")
  ADD COLUMN email_unsub_token  VARCHAR(64) DEFAULT NULL;

-- Token di disiscrizione per i promoter già esistenti (uno-click, nessun login richiesto).
-- Generato con random_bytes(32) lato PHP per i nuovi (collisione trascurabile), qui via SHA2/RAND per il backfill.
UPDATE promoter_profiles
   SET email_unsub_token = SHA2(CONCAT(user_id, '-', NOW(), '-', RAND()), 256)
 WHERE email_unsub_token IS NULL;

-- Data di prima pubblicazione, per individuare i "nuovi artisti" nel digest.
ALTER TABLE artist_profiles
  ADD COLUMN published_at DATETIME DEFAULT NULL;

-- Backfill: gli artisti già pubblicati non devono comparire come "nuovi" nel primo digest.
UPDATE artist_profiles SET published_at = updated_at WHERE published = 1 AND published_at IS NULL;
