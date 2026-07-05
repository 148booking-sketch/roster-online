-- Migration 23 · trattativa riservata (2026-07-05)
-- Auto-applicata dal codice al primo uso (ensure_trattativa_col in _http.php).
-- Solo gli artisti VERIFICATI possono attivarla: nasconde cachet, promo e condizioni
-- viaggi da tutte le viste pubbliche (le UI mostrano "Trattativa riservata").
ALTER TABLE artist_profiles
  ADD COLUMN trattativa_riservata TINYINT(1) NOT NULL DEFAULT 0 AFTER cachet_trattabile;
