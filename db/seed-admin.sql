-- ============================================================
-- Crea/aggiorna l'account admin di 148 Roster.
-- Esegui in phpMyAdmin → DB web01207_148roster → scheda SQL.
--
-- TEMPLATE — non mettere qui una password in chiaro o un hash reale: questo
-- file finisce su git (repo pubblico). Genera l'hash bcrypt localmente:
--   php -r 'echo password_hash("LA-TUA-PASSWORD", PASSWORD_DEFAULT), PHP_EOL;'
-- e incollalo al posto di <HASH_BCRYPT_QUI> solo nella query che esegui in
-- phpMyAdmin, senza salvarlo in questo file.
-- ============================================================
INSERT INTO users (email, password_hash, role, display_name, status, email_verified)
VALUES (
  '<EMAIL_ADMIN_QUI>',
  '<HASH_BCRYPT_QUI>',
  'admin',
  '<NOME_QUI>',
  'active',
  1
)
ON DUPLICATE KEY UPDATE
  password_hash = VALUES(password_hash),
  role          = 'admin',
  status        = 'active',
  email_verified = 1;
