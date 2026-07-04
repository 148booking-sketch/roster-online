<?php
/**
 * 148 ROSTER — configurazione (TEMPLATE)
 * Copia questo file in `config.php` e inserisci i valori reali.
 * `config.php` è in .gitignore: NON deve mai finire su Git/FTP pubblico.
 */
return [
  // --- Database MySQL ---
  'db_host' => 'localhost',            // su hosting condiviso quasi sempre "localhost"
  'db_name' => 'web01207_148roster',
  'db_user' => 'web01207_148roster',
  'db_pass' => 'INSERISCI_LA_PASSWORD',
  'db_charset' => 'utf8mb4',

  // --- App ---
  'app_url'  => 'https://artisti.148booking.it',
  'app_name' => '148 Roster',

  // --- Email (per verifica account / notifiche richieste) ---
  'mail_from'      => 'noreply@148booking.it',
  'mail_from_name' => '148 Roster',

  // --- Sicurezza ---
  'session_name' => 'roster_sid',
  // Token usa-e-getta per creare il PRIMO admin via /api/admin-bootstrap.php.
  // Impostane uno lungo e casuale; poi puoi svuotarlo (disabilita il bootstrap).
  'admin_setup_token' => 'CAMBIA_QUESTO_TOKEN_ADMIN',

  // --- Statistiche social ---
  'stats_token'     => 'CAMBIA_QUESTO_TOKEN',   // protegge stats-cron.php
  'youtube_api_key' => '',                       // opzionale: YouTube Data API v3
  'spotify_client_id'     => '',                 // opzionale: Spotify Web API (follower)
  'spotify_client_secret' => '',
  'apify_token'           => '',                 // opzionale: Apify (follower Instagram + Facebook)

  // --- Alert email promoter ---
  'email_digest_token' => 'CAMBIA_QUESTO_TOKEN',   // protegge email-digest-cron.php
];
