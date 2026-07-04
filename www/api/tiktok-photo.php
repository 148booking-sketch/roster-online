<?php
/**
 * GET /api/tiktok-photo.php?u=<user_id>&i=<0-2>
 * Relay della cover di un video TikTok (stats.tiktok_videos[i], solo l'id è salvato).
 * Gli URL firmati di tikwm scadono in 1-2 giorni, troppo poco per la cache settimanale
 * delle stat: qui usiamo una cache su file (stesso pattern di _geo.php/geocode.json),
 * chiave = video_id, TTL breve. Serve due scopi:
 *  1) non richiamare tikwm ad ogni singola visualizzazione della pagina;
 *  2) se tikwm è lento/irraggiungibile in un dato momento, servire comunque l'ultima
 *     copia buona invece di un'immagine rotta (prima capitava che, sotto carico o con
 *     tikwm intermittente, alcune delle 3 miniature sparissero — onerror le nasconde).
 */
require_once __DIR__ . '/_http.php';
require_once __DIR__ . '/_stats.php';

function tiktok_cover_cache_dir(): string {
  $dir = __DIR__ . '/cache/tiktok';
  if (!is_dir($dir)) @mkdir($dir, 0775, true);
  return $dir;
}

$uid = (int) ($_GET['u'] ?? 0);
$idx = (int) ($_GET['i'] ?? -1);
if ($uid <= 0 || $idx < 0 || $idx > 3) { http_response_code(400); exit; }

$st = db()->prepare('SELECT stats, socials FROM artist_profiles WHERE user_id = ?');
$st->execute([$uid]);
$row = $st->fetch();
if (!$row) { http_response_code(404); exit; }
$stats   = json_decode($row['stats'] ?? '', true) ?: [];
$socials = json_decode($row['socials'] ?? '', true) ?: [];
$vid = $stats['tiktok_videos'][$idx] ?? null;
$h   = tiktok_handle((string) ($socials['tiktok'] ?? ''));
if (!$vid || !preg_match('/^\d+$/', (string) $vid) || !$h) { http_response_code(404); exit; }

$dir      = tiktok_cover_cache_dir();
$binPath  = "$dir/$vid.bin";
$ctPath   = "$dir/$vid.ct";
$fresh    = is_file($binPath) && (time() - filemtime($binPath) < 12 * 3600);

if (!$fresh) {
  $r = http_get('https://www.tikwm.com/api/?url=' . rawurlencode("https://www.tiktok.com/@$h/video/$vid"), 8);
  $cover = null;
  if ($r['code'] === 200 && $r['body'] !== '') {
    $d = json_decode($r['body'], true);
    if (($d['code'] ?? -1) === 0) $cover = $d['data']['cover'] ?? null;
  }
  if ($cover) {
    $img = http_get($cover, 10, UA_BROWSER);
    if ($img['code'] === 200 && $img['body'] !== '') {
      @file_put_contents($binPath, $img['body']);
      @file_put_contents($ctPath, $img['ct'] ?: 'image/jpeg');
    }
  }
  // se il refresh fallisce ma esiste comunque una copia precedente (anche scaduta), va bene:
  // la serviamo lo stesso sotto invece di rispondere errore.
}

if (!is_file($binPath)) { http_response_code(502); exit; }

header('Content-Type: ' . (is_file($ctPath) ? trim((string) @file_get_contents($ctPath)) : 'image/jpeg'));
// il video_id non cambia mai contenuto: cache lunga lato client, invalidata dal ?v=
// (stats_updated_at) quando l'elenco tiktok_videos cambia (vedi artista.html).
header('Cache-Control: public, max-age=604800, immutable');
readfile($binPath);
