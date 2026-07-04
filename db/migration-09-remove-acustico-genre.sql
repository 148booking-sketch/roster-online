-- Migrazione 09: rimuove "Acustico" dai generi musicali (resta solo come Tipo di Show).
-- Verificato: 0 artisti hanno questo genere selezionato, nessuna riga da spostare.
DELETE FROM genres WHERE slug = 'acustico';
