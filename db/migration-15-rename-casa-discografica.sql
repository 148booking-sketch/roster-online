-- Migrazione 15: rinomina la colonna "casa_discografica" in "management" per allinearla
-- all'etichetta mostrata in UI ("Booking / Management"), che non è più una casa discografica.
--
-- ATTENZIONE se rieseguita da zero (fresh install con schema.sql + replay 02→17): la migration-06
-- aveva già creato una colonna "management" (email, mai usata in UI) sulla stessa tabella. Su
-- produzione la colonna vecchia va tolta PRIMA di questo CHANGE, altrimenti MySQL risponde
-- "Duplicate column name 'management'". schema.sql riflette solo lo stato finale (una sola
-- colonna "management" testuale, quella qui sotto) e nasconde questo passaggio intermedio.
ALTER TABLE artist_profiles DROP COLUMN management;
ALTER TABLE artist_profiles CHANGE casa_discografica management VARCHAR(160) DEFAULT NULL;
