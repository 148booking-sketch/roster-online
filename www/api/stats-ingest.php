<?php
/**
 * POST /api/stats-ingest.php
 *   Body: { token, items: [ { user_id, stats: {...} }, ... ] }
 * Riceve le statistiche calcolate dal worker cloud DI ROSTER e le salva.
 * Le stat "cloud-only" (spotify_listeners, IG, FB) vengono unite a quelle già
 * presenti così il worker non deve ricalcolare TikTok/Twitch/YouTube ad ogni giro.
 */
require_once __DIR__ . '/_http.php';
only('POST');
$in = body();

$token = config()['stats_token'] ?? '';
if ($token === '' || ($in['token'] ?? '') !== $token) fail('forbidden', 403);

$items = $in['items'] ?? [];
if (!is_array($items)) fail('bad_items');

$allowed = ['spotify_followers','spotify_listeners','youtube_subs','youtube_video',
            'tiktok_followers','tiktok_videos','twitch_followers','twitch_video','instagram_followers','facebook_followers',
            'instagram_photos'];

$sel = db()->prepare('SELECT stats FROM artist_profiles WHERE user_id = ?');
$upd = db()->prepare('UPDATE artist_profiles SET stats = ?, stats_updated_at = NOW() WHERE user_id = ?');
$n = 0;
foreach ($items as $it) {
  $uid = (int) ($it['user_id'] ?? 0);
  if ($uid <= 0) continue;
  $sel->execute([$uid]);
  $stats = json_decode($sel->fetchColumn() ?: '', true) ?: [];   // parti dalle esistenti
  foreach (($it['stats'] ?? []) as $k => $v) {
    if (in_array($k, $allowed, true) && $v !== null && $v !== '') $stats[$k] = is_numeric($v) ? (int) $v : $v;
  }
  $upd->execute([$stats ? json_encode($stats, JSON_UNESCAPED_UNICODE) : null, $uid]);
  $n++;
}
ok(['updated' => $n]);
