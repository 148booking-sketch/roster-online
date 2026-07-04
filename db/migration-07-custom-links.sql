-- Migration 07: fino a 3 link manuali (titolo + url) per la pagina "multi link" dell'artista.
ALTER TABLE artist_profiles ADD COLUMN custom_links JSON DEFAULT NULL AFTER socials;
