<?php
/**
 * GET /api/ig-photo.php?u=<user_id>&i=<0-2>
 * Relay dell'immagine Instagram salvata in artist_profiles.stats.instagram_photos[i].
 * Serve perché il CDN di Instagram manda "Cross-Origin-Resource-Policy: same-origin":
 * i browser bloccano l'hotlink diretto <img src="https://scontent-...cdninstagram.com/...">
 * da un altro dominio (ERR_BLOCKED_BY_RESPONSE.NotSameOrigin), quindi va servita dallo
 * stesso dominio del sito. Nessun salvataggio su disco: i byte vengono solo inoltrati
 * (stessa filosofia "hotlink, no download" usata per photo_url).
 */
require_once __DIR__ . '/_http.php';
require_once __DIR__ . '/_social.php';

$uid = (int) ($_GET['u'] ?? 0);
$idx = (int) ($_GET['i'] ?? -1);
if ($uid <= 0 || $idx < 0 || $idx > 3) { http_response_code(400); exit; }

$st = db()->prepare('SELECT stats FROM artist_profiles WHERE user_id = ?');
$st->execute([$uid]);
$stats = json_decode($st->fetchColumn() ?: '', true) ?: [];
$url = $stats['instagram_photos'][$idx] ?? null;
if (!$url) { http_response_code(404); exit; }

$r = http_get($url, 12, UA_BROWSER);
if ($r['code'] !== 200 || $r['body'] === '') { http_response_code(502); exit; }

header('Content-Type: ' . ($r['ct'] ?: 'image/jpeg'));
// L'URL include ?v=<stats_updated_at> (vedi artista.html): cambia ogni volta che le foto
// vengono aggiornate, quindi qui possiamo cachare a lungo senza rischio di mostrare foto vecchie.
header('Cache-Control: public, max-age=604800, immutable');
echo $r['body'];
