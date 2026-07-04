-- ============================================================
-- BOOKING ROSTER — migration 18: livelli admin (super vs ridotto)
-- Un admin "ridotto" può fare tutto tranne: creare/modificare/eliminare altri
-- account admin, ed eliminare artisti/promoter/agenzie (può solo aggiornarli).
-- Default 1 (super) così gli admin già esistenti mantengono i privilegi pieni
-- senza bisogno di un backfill esplicito.
-- ============================================================
ALTER TABLE users
  ADD COLUMN admin_super TINYINT(1) NOT NULL DEFAULT 1;
