<?php
/**
 * GET /api/ig-avatar.php?u=<user_id>[&role=promoter]
 * Relay dell'avatar Instagram salvato in artist_profiles.stats.instagram_avatar (default) o in
 * promoter_profiles.instagram_avatar (role=promoter, per agenzie/promoter), usato come photo_url.
 * Il CDN di Instagram manda "Cross-Origin-Resource-Policy: same-origin", quindi l'hotlink diretto
 * da un altro dominio viene bloccato dal browser: da qui il relay. Nessun salvataggio su disco.
 */
require_once __DIR__ . '/_http.php';
require_once __DIR__ . '/_social.php';

$uid  = (int) ($_GET['u'] ?? 0);
$role = ($_GET['role'] ?? '') === 'promoter' ? 'promoter' : 'artist';
if ($uid <= 0) { http_response_code(400); exit; }

if ($role === 'promoter') {
  $st = db()->prepare('SELECT instagram_avatar FROM promoter_profiles WHERE user_id = ?');
  $st->execute([$uid]);
  $url = $st->fetchColumn() ?: null;
} else {
  $st = db()->prepare('SELECT stats FROM artist_profiles WHERE user_id = ?');
  $st->execute([$uid]);
  $stats = json_decode($st->fetchColumn() ?: '', true) ?: [];
  $url = $stats['instagram_avatar'] ?? null;
}
if (!$url) { http_response_code(404); exit; }

$r = http_get($url, 12, UA_BROWSER);
if ($r['code'] !== 200 || $r['body'] === '') { http_response_code(502); exit; }

header('Content-Type: ' . ($r['ct'] ?: 'image/jpeg'));
// photo_url (colonna DB) punta qui SENZA versioning: usato ovunque nel sito (home, ricerca,
// mappa, admin) come stringa fissa, quindi cache moderata invece di immutable a lungo termine.
header('Cache-Control: public, max-age=21600'); // 6h
echo $r['body'];
