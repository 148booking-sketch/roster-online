<?php
/**
 * Impostazioni statistiche (solo admin). Le chiavi si salvano in app_meta e
 * hanno priorità solo se config.php non le definisce già.
 *   GET  /api/admin-settings.php            → valori attuali + stato "attivo"
 *   POST /api/admin-settings.php  { key: value, ... }  → salva
 */
require_once __DIR__ . '/_admin.php';
require_once __DIR__ . '/_stats.php';   // meta_get/meta_set + stats_cred
require_admin();

$keys = ['spotify_client_id', 'spotify_client_secret', 'youtube_api_key', 'apify_token'];

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
  $in = body();
  $saved = [];
  foreach ($keys as $k) {
    if (array_key_exists($k, $in)) { meta_set($k, trim((string)$in[$k])); $saved[] = $k; }
  }
  ok(['saved' => $saved]);
}

// GET: mostra i valori impostati via app_meta + se la chiave risulta attiva (config o meta).
$cfg = config();
$out = [];
foreach ($keys as $k) {
  $meta = meta_get($k);
  $out[$k] = [
    'value'      => $meta ?? '',                               // solo il valore da app_meta (editabile)
    'from_config'=> trim((string)($cfg[$k] ?? '')) !== '',     // già definita in config.php
    'active'     => stats_cred($k) !== '',                     // effettivamente utilizzabile
  ];
}
ok(['settings' => $out]);
