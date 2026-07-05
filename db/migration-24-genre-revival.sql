-- Migration 24 · genere Revival (2026-07-05)
-- Auto-applicata da www/api/genres.php al primo uso (INSERT IGNORE idempotente).
INSERT IGNORE INTO genres (slug, name) VALUES ('revival','Revival');
