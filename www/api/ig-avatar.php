<?php
/**
 * GET /api/ig-avatar.php?u=<user_id>
 * Relay dell'avatar Instagram salvato in artist_profiles.stats.instagram_avatar, usato come
 * photo_url per chi non ha Spotify collegato. Stesso motivo di ig-photo.php: il CDN di
 * Instagram manda "Cross-Origin-Resource-Policy: same-origin", quindi l'hotlink diretto da
 * un altro dominio viene bloccato dal browser. Nessun salvataggio su disco, solo relay.
 */
require_once __DIR__ . '/_http.php';
require_once __DIR__ . '/_social.php';

$uid = (int) ($_GET['u'] ?? 0);
if ($uid <= 0) { http_response_code(400); exit; }

$st = db()->prepare('SELECT stats FROM artist_profiles WHERE user_id = ?');
$st->execute([$uid]);
$stats = json_decode($st->fetchColumn() ?: '', true) ?: [];
$url = $stats['instagram_avatar'] ?? null;
if (!$url) { http_response_code(404); exit; }

$r = http_get($url, 12, UA_BROWSER);
if ($r['code'] !== 200 || $r['body'] === '') { http_response_code(502); exit; }

header('Content-Type: ' . ($r['ct'] ?: 'image/jpeg'));
// photo_url (colonna DB) punta qui SENZA versioning: usato ovunque nel sito (home, ricerca,
// mappa, admin) come stringa fissa, quindi cache moderata invece di immutable a lungo termine.
header('Cache-Control: public, max-age=21600'); // 6h
echo $r['body'];
