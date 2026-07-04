<?php
/**
 * BOOKING ROSTER — worker statistiche social (progetto INDIPENDENTE, separato dal 148).
 *
 * Metodo ispirato a quello del sito 148 ma reimplementato per roster: gira su un
 * runner cloud (GitHub Actions DI ROSTER) perché dagli IP cloud Spotify serve la
 * pagina completa (ascoltatori mensili scrapabili) — cosa impossibile dall'hosting.
 *
 * Legge gli artisti da roster (stats-feed), calcola le statistiche e le rimanda a
 * roster (stats-ingest). NON dipende dal progetto 148 in alcun modo.
 *
 * Fonti: Spotify (HTML, ascoltatori) · YouTube (Data API: iscritti + RSS: ultimo video)
 *        · TikTok (tikwm) · Twitch (decapi) · Instagram+Facebook (Apify, se APIFY_TOKEN).
 *
 * ENV: ROSTER_URL (default https://artisti.148booking.it) · ROSTER_STATS_TOKEN (obbligatorio)
 *      · YOUTUBE_API_KEY (opz.) · APIFY_TOKEN (opz., per IG/FB).
 * Uso: php worker/social-stats.php
 */
error_reporting(E_ALL & ~E_DEPRECATED);
date_default_timezone_set('Europe/Rome');

function env(string $k, string $d = ''): string { $v = getenv($k); return ($v !== false && $v !== '') ? $v : $d; }
function info(string $m): void { fwrite(STDERR, $m . "\n"); }

$ROSTER_URL = rtrim(env('ROSTER_URL', 'https://artisti.148booking.it'), '/');
$TOKEN      = env('ROSTER_STATS_TOKEN');
$YT_KEY     = env('YOUTUBE_API_KEY');
$APIFY      = env('APIFY_TOKEN');
if ($TOKEN === '') { fwrite(STDERR, "ROSTER_STATS_TOKEN mancante\n"); exit(1); }

const UA = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124 Safari/537.36';
// UA da crawler social: Spotify serve l'HTML con gli ascoltatori mensili solo ai bot dei
// social (con una UA da browser dà la shell vuota, ovunque). Vale sia da hosting che da cloud.
const UA_CRAWLER = 'facebookexternalhit/1.1 (+http://www.facebook.com/externalhit_uatext.php)';
function http_get(string $url, int $t = 12, ?string $ua = null): array {
  $ch = curl_init($url);
  curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_MAXREDIRS => 5,
    CURLOPT_TIMEOUT => $t, CURLOPT_USERAGENT => $ua ?: UA,
    CURLOPT_HTTPHEADER => ['Accept-Language: en'], CURLOPT_COOKIE => 'CONSENT=YES+1',
  ]);
  $b = curl_exec($ch); $c = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
  return [$c, $b ?: ''];
}
function km_to_int(string $num, string $suf): int {
  $n = (float) str_replace(',', '.', preg_replace('/\.(?=\d{3}\b)/', '', $num));
  $suf = strtoupper($suf); if ($suf === 'K') $n *= 1e3; elseif ($suf === 'M') $n *= 1e6;
  return (int) round($n);
}
function spotify_listeners(string $url): ?int {
  if (!preg_match('#/artist/([A-Za-z0-9]+)#', $url, $m)) return null;
  [$c, $h] = http_get("https://open.spotify.com/artist/{$m[1]}", 12, UA_CRAWLER);
  if ($c !== 200 || $h === '') return null;
  if (preg_match('/([\d.,]{3,})\s*(?:monthly listeners|ascoltatori mensili)/i', $h, $mm)) {
    $n = (int) preg_replace('/[.,]/', '', $mm[1]); if ($n > 0) return $n;
  }
  if (preg_match('/og:description"\s+content="[^"]*?·\s*([\d.,]+)\s*([KM])?\s*monthly/i', $h, $mm)) {
    $n = km_to_int($mm[1], $mm[2] ?? ''); if ($n > 0) return $n;
  }
  return null;
}
function tiktok_followers(string $url): ?int {
  if (preg_match('#tiktok\.com/@?([\w.]+)#', $url, $m)) $h = ltrim($m[1], '@');
  else { $h = ltrim(trim($url), '@'); if (!preg_match('/^[\w.]+$/', $h)) return null; }
  if ($h === '') return null;
  [$c, $raw] = http_get('https://www.tikwm.com/api/user/info?unique_id=' . rawurlencode($h));
  if ($c !== 200 || $raw === '') return null;
  $d = json_decode($raw, true);
  if (($d['code'] ?? -1) !== 0) return null;
  $f = $d['data']['stats']['followerCount'] ?? null;
  return is_numeric($f) ? (int) $f : null;
}
function tiktok_latest_videos(string $url, int $count = 3): array {
  if (preg_match('#tiktok\.com/@?([\w.]+)#', $url, $m)) $h = ltrim($m[1], '@');
  else { $h = ltrim(trim($url), '@'); if (!preg_match('/^[\w.]+$/', $h)) return []; }
  if ($h === '') return [];
  [$c, $raw] = http_get('https://www.tikwm.com/api/user/posts?unique_id=' . rawurlencode($h) . '&count=' . $count . '&cursor=0');
  if ($c !== 200 || $raw === '') return [];
  $d = json_decode($raw, true);
  if (($d['code'] ?? -1) !== 0) return [];
  $ids = [];
  foreach ($d['data']['videos'] ?? [] as $v) { if (!empty($v['video_id'])) $ids[] = (string) $v['video_id']; }
  return $ids;
}
function twitch_followers(string $url): ?int {
  if (!preg_match('#twitch\.tv/([A-Za-z0-9_]{2,})#', $url, $m)) return null;
  [$c, $raw] = http_get('https://decapi.me/twitch/followcount/' . rawurlencode(strtolower($m[1])));
  if ($c !== 200) return null; $raw = trim($raw);
  return preg_match('/^\d+$/', $raw) ? (int) $raw : null;
}
function yt_channel_id(string $url): ?string {
  if (preg_match('#/channel/(UC[\w-]{20,})#', $url, $m)) return $m[1];
  [$c, $h] = http_get($url);
  return ($c === 200 && preg_match('/"(?:externalId|channelId)":"(UC[\w-]{20,})"/', $h, $m)) ? $m[1] : null;
}
function yt_subs(string $ucid, string $key): ?int {
  if ($key === '') return null;
  [$c, $j] = http_get("https://www.googleapis.com/youtube/v3/channels?part=statistics&id=$ucid&key=$key");
  if ($c !== 200) return null;
  $st = json_decode($j, true)['items'][0]['statistics'] ?? null;
  if (!$st || ($st['hiddenSubscriberCount'] ?? false)) return null;
  return isset($st['subscriberCount']) ? (int) $st['subscriberCount'] : null;
}
function yt_last_video(string $ucid): ?string {
  [$c, $x] = http_get("https://www.youtube.com/feeds/videos.xml?channel_id=$ucid");
  if ($c !== 200) return null;
  return preg_match('#<yt:videoId>([\w-]{11})</yt:videoId>#', $x, $m) ? $m[1] : null;
}
function apify_run(string $actor, array $input, string $token, int $timeout = 240): array {
  $url = "https://api.apify.com/v2/acts/$actor/run-sync-get-dataset-items?token=" . rawurlencode($token) . "&timeout=$timeout";
  $ch = curl_init($url);
  curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => json_encode($input), CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
    CURLOPT_TIMEOUT => $timeout + 40]);
  $raw = curl_exec($ch); curl_close($ch);
  $j = json_decode($raw ?: '', true);
  return (is_array($j) && !isset($j['error'])) ? $j : [];
}
function ig_handle(string $v): ?string {
  $v = trim($v);
  if (preg_match('~instagram\.com/([^/?#]+)~i', $v, $m)) $v = $m[1];
  $h = ltrim(strtolower($v), '@');
  return preg_match('/^[a-z0-9._]+$/', $h) && !in_array($h, ['p','reel','reels','explore','stories','tv'], true) ? $h : null;
}
function fb_url(string $v): ?string {
  $v = trim($v); if ($v === '') return null;
  return preg_match('#^https?://#i', $v) ? $v : 'https://www.facebook.com/' . ltrim($v, '@');
}
function fb_follow_for(string $url, array $items): ?int {
  $want = strtolower(preg_replace('#^https?://(www\.)?facebook\.com/#i', '', trim($url)));
  foreach ($items as $it) {
    $f = $it['followers'] ?? $it['followersCount'] ?? $it['likes'] ?? null; if (!is_numeric($f)) continue;
    foreach (['pageUrl','facebookUrl','url','inputUrl'] as $kf)
      if (!empty($it[$kf]) && stripos((string)$it[$kf], $want) !== false) return (int) $f;
  }
  return null;
}

// ── main ──────────────────────────────────────────────────────────────────
[$c, $feedRaw] = http_get("$ROSTER_URL/api/stats-feed.php?token=" . rawurlencode($TOKEN), 20);
if ($c !== 200) { fwrite(STDERR, "feed HTTP $c\n"); exit(1); }
$feed = json_decode($feedRaw, true);
if (empty($feed['ok'])) { fwrite(STDERR, "feed non valido\n"); exit(1); }
$artists = $feed['artists'] ?? [];
info("Artisti: " . count($artists));

$igHandles = []; $fbUrls = [];
foreach ($artists as $a) {
  if (!empty($a['socials']['instagram']) && ($h = ig_handle($a['socials']['instagram']))) $igHandles[$a['user_id']] = $h;
  if (!empty($a['socials']['facebook'])  && ($u = fb_url($a['socials']['facebook'])))     $fbUrls[$a['user_id']]  = $u;
}
$igMap = []; $igPhotos = []; $fbItems = [];
if ($APIFY && $igHandles) {
  foreach (apify_run('apify~instagram-profile-scraper', ['usernames' => array_values(array_unique($igHandles))], $APIFY) as $it) {
    $u = strtolower((string)($it['username'] ?? '')); $f = $it['followersCount'] ?? $it['followers'] ?? null;
    if ($u === '') continue;
    if (is_numeric($f)) $igMap[$u] = (int) $f;
    $photos = [];
    foreach (array_slice($it['latestPosts'] ?? [], 0, 3) as $p) { if (!empty($p['displayUrl'])) $photos[] = $p['displayUrl']; }
    if ($photos) $igPhotos[$u] = $photos;
  }
  info("Apify IG: " . count($igMap));
}
if ($APIFY && $fbUrls) {
  $fbItems = apify_run('apify~facebook-pages-scraper', ['startUrls' => array_map(fn($u) => ['url' => $u], array_values(array_unique($fbUrls)))], $APIFY);
  info("Apify FB: " . count($fbItems));
}

$out = [];
foreach ($artists as $a) {
  $s = []; $soc = $a['socials'];
  if (!empty($soc['spotify']))  { $v = spotify_listeners($soc['spotify']); if ($v !== null) $s['spotify_listeners'] = $v; }
  if (!empty($soc['tiktok']))   { $v = tiktok_followers($soc['tiktok']);   if ($v !== null) $s['tiktok_followers'] = $v;
    $vids = tiktok_latest_videos($soc['tiktok']); if ($vids) $s['tiktok_videos'] = $vids; }
  if (!empty($soc['twitch']))   { $v = twitch_followers($soc['twitch']);   if ($v !== null) $s['twitch_followers'] = $v; }
  if (!empty($soc['youtube']))  { $ucid = yt_channel_id($soc['youtube']);
    if ($ucid) { $v = yt_subs($ucid, $YT_KEY); if ($v !== null) $s['youtube_subs'] = $v;
                 $vid = yt_last_video($ucid);  if ($vid) $s['youtube_video'] = $vid; } }
  if (isset($igHandles[$a['user_id']], $igMap[$igHandles[$a['user_id']]])) $s['instagram_followers'] = $igMap[$igHandles[$a['user_id']]];
  if (isset($igHandles[$a['user_id']], $igPhotos[$igHandles[$a['user_id']]])) $s['instagram_photos'] = $igPhotos[$igHandles[$a['user_id']]];
  if (isset($fbUrls[$a['user_id']]) && ($v = fb_follow_for($fbUrls[$a['user_id']], $fbItems)) !== null) $s['facebook_followers'] = $v;
  $out[] = ['user_id' => $a['user_id'], 'stats' => $s];
  info(sprintf(" • %-20s %s", $a['name'] ?? $a['user_id'], json_encode($s)));
  usleep(300000);
}

$ch = curl_init("$ROSTER_URL/api/stats-ingest.php");
curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true,
  CURLOPT_POSTFIELDS => json_encode(['token' => $TOKEN, 'items' => $out]),
  CURLOPT_HTTPHEADER => ['Content-Type: application/json'], CURLOPT_TIMEOUT => 30]);
$resp = curl_exec($ch); $rc = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
info("Ingest HTTP $rc: $resp");
echo "Aggiornati: " . count($out) . "\n";
exit($rc === 200 ? 0 : 1);
