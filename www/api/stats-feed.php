<?php
/**
 * GET /api/stats-feed.php?token=STATS_TOKEN
 * Elenco artisti pubblicati con i loro social — consumato dal worker cloud DI ROSTER
 * (GitHub Actions del progetto roster, separato dal 148) che calcola le statistiche
 * e le rimanda a stats-ingest.php.
 */
require_once __DIR__ . '/_http.php';
header('Content-Type: application/json; charset=utf-8');

$token = config()['stats_token'] ?? '';
if ($token === '' || !hash_equals($token, (string)($_GET['token'] ?? ''))) { http_response_code(403); exit('{"ok":false,"error":"forbidden"}'); }

$rows = db()->query(
  "SELECT user_id, stage_name, socials FROM artist_profiles
   WHERE published = 1 AND socials IS NOT NULL"
)->fetchAll();

$out = [];
foreach ($rows as $r) {
  $soc = json_decode($r['socials'] ?? '', true) ?: [];
  $soc = array_filter($soc, fn($v) => is_string($v) && trim($v) !== '');
  if ($soc) $out[] = ['user_id' => (int) $r['user_id'], 'name' => $r['stage_name'], 'socials' => $soc];
}

echo json_encode(['ok' => true, 'artists' => $out], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
