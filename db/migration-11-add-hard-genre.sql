-- Migrazione 11: nuovo genere musicale "Hard" (sonorità dure/aggressive).
INSERT INTO genres (slug, name) VALUES ('hard', 'Hard')
ON DUPLICATE KEY UPDATE name = VALUES(name);
