-- Migrazione 13: nuovo genere musicale "Format" (per spettacoli vari).
INSERT INTO genres (slug, name) VALUES ('format', 'Format')
ON DUPLICATE KEY UPDATE name = VALUES(name);
