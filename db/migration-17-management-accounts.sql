-- ============================================================
-- BOOKING ROSTER — migration 17: account "management / booking"
-- Terzo tipo di account: stessi privilegi del promoter (vede i cachet
-- da "active", può inviare richieste di booking) MA può anche inserire
-- e gestire nuovi artisti sotto il proprio profilo.
--   - Gli artisti creati da un booking sono "gestiti" (nessun login proprio,
--     email/password auto-generate) e nascono verificati (idoneità iTunes).
--   - manager_user_id traccia il booking proprietario dell'artista.
--   - I profili management riusano la tabella promoter_profiles (stessa forma).
--
-- Import:  mysql -u web01207_148roster -p web01207_148roster < migration-17-management-accounts.sql
-- oppure incolla in phpMyAdmin > SQL.
-- ============================================================

-- 1) Nuovo valore nell'ENUM ruoli (ordine: prima di admin).
ALTER TABLE users
  MODIFY role ENUM('artist','promoter','management','admin') NOT NULL;

-- 2) Proprietà artista → booking che lo gestisce (NULL = artista autonomo/admin).
ALTER TABLE artist_profiles
  ADD COLUMN manager_user_id INT UNSIGNED DEFAULT NULL AFTER user_id,
  ADD KEY idx_manager (manager_user_id),
  ADD CONSTRAINT fk_artist_manager
      FOREIGN KEY (manager_user_id) REFERENCES users(id) ON DELETE SET NULL;
