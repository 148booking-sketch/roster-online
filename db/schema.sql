-- ============================================================
-- 148 ROSTER — schema MySQL
-- Piattaforma di matching artisti emergenti ↔ promoter
-- Dominio: artisti.148booking.it
-- Modello: vetrina/matching disintermediato (no pagamenti in piattaforma)
--
-- Import:  mysql -u web01207_148roster -p web01207_148roster < schema.sql
-- oppure incolla in phpMyAdmin > SQL.
-- Charset utf8mb4 per emoji/accenti. Motore InnoDB per le FK.
-- ============================================================

SET NAMES utf8mb4;
SET foreign_key_checks = 0;

-- ------------------------------------------------------------
-- UTENTI (base comune a artisti e promoter)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS users (
  id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
  email          VARCHAR(190) NOT NULL,
  password_hash  VARCHAR(255) NOT NULL,
  role           ENUM('artist','promoter','management','admin') NOT NULL,
  display_name   VARCHAR(120) NOT NULL DEFAULT '',
  status         ENUM('active','pending','blocked') NOT NULL DEFAULT 'active',
  email_verified TINYINT(1) NOT NULL DEFAULT 0,
  verify_token   VARCHAR(64) DEFAULT NULL,
  reset_token    VARCHAR(64) DEFAULT NULL,
  reset_expires  DATETIME DEFAULT NULL,
  created_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  last_login     DATETIME DEFAULT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_email (email),
  KEY idx_role (role)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- GENERI musicali (tabella di riferimento)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS genres (
  id    SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT,
  slug  VARCHAR(60) NOT NULL,
  name  VARCHAR(80) NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_slug (slug)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- META applicativa (coppie chiave/valore, es. credenziali statistiche
-- impostabili da admin-settings.php quando non sono in config.php)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS app_meta (
  k VARCHAR(60) NOT NULL,
  v VARCHAR(255) DEFAULT NULL,
  PRIMARY KEY (k)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- PROFILO ARTISTA (1:1 con users)
-- cachet in euro interi; rimborsi modellati in modo flessibile.
-- lat/lng = comune "base" dell'artista (geocodificato alla registrazione).
-- travel_max_km = raggio entro cui l'artista è disposto a suonare.
--
-- NB: questa tabella riflette lo stato ATTUALE in produzione, cioè lo schema
-- base + tutte le migration-*.sql applicate in ordine (02→17). formazione
-- storicamente si chiamava "formazione" (solista/duo/trio/band/dj/altro) ma
-- oggi rappresenta il "Tipo di Show" (vedi show_types() in api/_gear.php) —
-- i valori sono stati cambiati direttamente in produzione senza una
-- migration dedicata, così come calendar_url/calendar_busy/calendar_updated_at,
-- cachet_promo/promo_until (promo a tempo) e top8 (featured in home): non
-- esiste un file migration-*.sql per queste colonne, sono qui SOLO perché
-- verificate contro il codice reale che le usa.
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS artist_profiles (
  user_id             INT UNSIGNED NOT NULL,
  manager_user_id     INT UNSIGNED DEFAULT NULL,   -- booking/management che lo gestisce (NULL = artista autonomo)
  stage_name          VARCHAR(140) NOT NULL DEFAULT '',
  slug                VARCHAR(160) DEFAULT NULL,
  formazione          ENUM('live_dj','dj_set','acustico','live_band','meet_greet') NOT NULL DEFAULT 'live_band',  -- "Tipo di Show"
  componenti          TINYINT UNSIGNED DEFAULT NULL,   -- "On Stage": numero componenti sul palco
  bio                 TEXT,
  bio_from_spotify    TINYINT(1) NOT NULL DEFAULT 0,   -- bio sincronizzata dall'Artist's Pick Spotify
  phone               VARCHAR(40) DEFAULT NULL,
  comune              VARCHAR(120) DEFAULT NULL,
  provincia           CHAR(2) DEFAULT NULL,
  regione             VARCHAR(60) DEFAULT NULL,
  lat                 DECIMAL(9,6) DEFAULT NULL,
  lng                 DECIMAL(9,6) DEFAULT NULL,
  cachet_min          INT UNSIGNED DEFAULT NULL,   -- € minimo richiesto ("Cachet a serata")
  cachet_max          INT UNSIGNED DEFAULT NULL,   -- € massimo / "ideale"
  cachet_trattabile   TINYINT(1) NOT NULL DEFAULT 1,
  cachet_promo        INT UNSIGNED DEFAULT NULL,   -- € prezzo promo (opzionale, mostra badge PROMO)
  promo_until         DATE DEFAULT NULL,           -- validità della promo (NULL = senza scadenza)
  rimborso_tipo       ENUM('incluso','a_km','forfait','da_concordare') NOT NULL DEFAULT 'da_concordare',
  rimborso_km         DECIMAL(5,2) DEFAULT NULL,   -- €/km se rimborso_tipo='a_km'
  rimborso_forfait    INT UNSIGNED DEFAULT NULL,   -- € fissi se rimborso_tipo='forfait'
  travel_max_km       INT UNSIGNED DEFAULT NULL,   -- raggio disponibilità (NULL = ovunque)
  durata_set_min      INT UNSIGNED DEFAULT NULL,   -- durata set in minuti
  website             VARCHAR(255) DEFAULT NULL,
  socials             JSON DEFAULT NULL,           -- {"instagram":"...","spotify":"...","youtube":"...","applemusic":"..."}
  custom_links        JSON DEFAULT NULL,           -- fino a 3 link manuali {title,url} per la pagina "link in bio"
  photo_url           VARCHAR(255) DEFAULT NULL,
  video_url           VARCHAR(255) DEFAULT NULL,
  label               VARCHAR(160) DEFAULT NULL,
  management          VARCHAR(190) DEFAULT NULL,   -- booking/management (etichetta libera, testo)
  tech_sheet_url      VARCHAR(255) DEFAULT NULL,   -- scheda tecnica (URL Drive/Dropbox/PDF)
  gear_bring          JSON DEFAULT NULL,           -- cosa porta l'artista
  gear_need           JSON DEFAULT NULL,           -- cosa deve esserci sul posto
  calendar_url        VARCHAR(255) DEFAULT NULL,   -- link iCal privato (mai esposto pubblicamente)
  calendar_busy       JSON DEFAULT NULL,           -- cache date occupate, calcolata da calendar_url
  calendar_updated_at DATETIME DEFAULT NULL,
  stats               JSON DEFAULT NULL,           -- statistiche social (vedi api/_stats.php)
  stats_updated_at    DATETIME DEFAULT NULL,
  verified            TINYINT(1) NOT NULL DEFAULT 0,
  top8                TINYINT(1) NOT NULL DEFAULT 0,   -- sempre tra gli artisti in evidenza in home
  published           TINYINT(1) NOT NULL DEFAULT 1,   -- visibile nella ricerca
  published_at        DATETIME DEFAULT NULL,           -- prima transizione 0→1 (per il digest email "nuovi artisti")
  updated_at          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (user_id),
  UNIQUE KEY uq_slug (slug),
  KEY idx_geo (lat, lng),
  KEY idx_cachet (cachet_min, cachet_max),
  KEY idx_pub (published, verified),
  KEY idx_manager (manager_user_id),
  CONSTRAINT fk_artist_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_artist_manager FOREIGN KEY (manager_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- ARTISTA ↔ GENERI (molti-a-molti)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS artist_genres (
  artist_user_id INT UNSIGNED NOT NULL,
  genre_id       SMALLINT UNSIGNED NOT NULL,
  PRIMARY KEY (artist_user_id, genre_id),
  KEY idx_genre (genre_id),
  CONSTRAINT fk_ag_artist FOREIGN KEY (artist_user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_ag_genre  FOREIGN KEY (genre_id) REFERENCES genres(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- PROFILO PROMOTER (1:1 con users)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS promoter_profiles (
  user_id            INT UNSIGNED NOT NULL,
  org_name           VARCHAR(140) NOT NULL DEFAULT '',  -- locale / associazione / agenzia
  tipo               ENUM('locale','festival','associazione','agenzia','privato','altro') NOT NULL DEFAULT 'locale',
  phone              VARCHAR(40) DEFAULT NULL,
  comune             VARCHAR(120) DEFAULT NULL,
  provincia          CHAR(2) DEFAULT NULL,
  regione            VARCHAR(60) DEFAULT NULL,
  lat                DECIMAL(9,6) DEFAULT NULL,
  lng                DECIMAL(9,6) DEFAULT NULL,
  website            VARCHAR(255) DEFAULT NULL,
  verified           TINYINT(1) NOT NULL DEFAULT 0,   -- approvazione admin: finché 0 non vede i cachet
  email_freq         ENUM('off','daily','weekly','monthly') NOT NULL DEFAULT 'off',
  email_consent_at   DATETIME DEFAULT NULL,   -- ultimo consenso esplicito alle email
  email_last_sent_at DATETIME DEFAULT NULL,   -- ultimo digest inviato
  email_unsub_token  VARCHAR(64) DEFAULT NULL,
  updated_at         TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (user_id),
  CONSTRAINT fk_promoter_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- LOCALI del promoter (un promoter può avere più venue salvate)
-- La ricerca "per distanza" parte dalle coordinate del venue scelto.
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS venues (
  id                INT UNSIGNED NOT NULL AUTO_INCREMENT,
  promoter_user_id  INT UNSIGNED NOT NULL,
  name              VARCHAR(160) NOT NULL,
  address           VARCHAR(255) DEFAULT NULL,
  comune            VARCHAR(120) DEFAULT NULL,
  provincia         CHAR(2) DEFAULT NULL,
  lat               DECIMAL(9,6) DEFAULT NULL,
  lng               DECIMAL(9,6) DEFAULT NULL,
  capienza          INT UNSIGNED DEFAULT NULL,
  created_at        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_promoter (promoter_user_id),
  CONSTRAINT fk_venue_promoter FOREIGN KEY (promoter_user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- RICHIESTE DI BOOKING (promoter → artista)
-- Traccia il contatto; l'accordo vero avviene fuori piattaforma.
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS booking_requests (
  id                INT UNSIGNED NOT NULL AUTO_INCREMENT,
  promoter_user_id  INT UNSIGNED NOT NULL,
  artist_user_id    INT UNSIGNED NOT NULL,
  venue_id          INT UNSIGNED DEFAULT NULL,
  event_date        DATE DEFAULT NULL,
  message           TEXT,
  proposed_fee      INT UNSIGNED DEFAULT NULL,
  status            ENUM('inviata','vista','accettata','rifiutata','ritirata') NOT NULL DEFAULT 'inviata',
  created_at        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  responded_at      DATETIME DEFAULT NULL,
  PRIMARY KEY (id),
  KEY idx_artist (artist_user_id, status),
  KEY idx_promoter (promoter_user_id, status),
  CONSTRAINT fk_req_promoter FOREIGN KEY (promoter_user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_req_artist   FOREIGN KEY (artist_user_id)   REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_req_venue    FOREIGN KEY (venue_id)         REFERENCES venues(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET foreign_key_checks = 1;

-- ------------------------------------------------------------
-- SEED generi musicali di base (modificabili)
-- ------------------------------------------------------------
-- "Acustico" NON è più qui: è un valore di formazione ("Tipo di Show"), non un genere
-- (rimosso dai generi in migration-09). "Hard" e "Format" aggiunti da migration-11/13.
INSERT IGNORE INTO genres (slug, name) VALUES
  ('pop','Pop'), ('rock','Rock'), ('indie','Indie'), ('cantautore','Cantautorato'),
  ('rap-hiphop','Rap / Hip-Hop'), ('trap','Trap'), ('elettronica','Elettronica'),
  ('house-techno','House / Techno'), ('jazz','Jazz'), ('blues','Blues'),
  ('funk-soul','Funk / Soul'), ('reggae','Reggae'), ('folk','Folk'),
  ('metal','Metal'), ('punk','Punk'), ('classica','Classica'),
  ('tributo-cover','Tributo / Cover'),
  ('dance-commerciale','Dance commerciale'), ('world','World / Etnica'),
  ('hard','Hard'), ('format','Format');
