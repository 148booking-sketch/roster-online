<?php
/**
 * Statistiche social artisti — fonti pubbliche gratuite (nessuna credenziale):
 *   - Spotify  → ascoltatori mensili (HTML pubblico)
 *   - TikTok   → follower via tikwm.com (proxy pubblico, regge gli IP datacenter)
 *   - Twitch   → follower via decapi.me
 *   - YouTube  → iscritti via Data API v3 SOLO se config['youtube_api_key'] è impostata
 * Instagram/Facebook richiedono scraper a pagamento (Apify) → non inclusi.
 *
 * Le stat vengono salvate in artist_profiles.stats (JSON) + stats_updated_at.
 */
require_once __DIR__ . '/_db.php';
require_once __DIR__ . '/_social.php';   // http_get()

/** Credenziale statistiche: prima da config.php, altrimenti da app_meta (impostabile da admin). */
function stats_cred(string $k): string {
  $c = config();
  $v = trim((string)($c[$k] ?? ''));
  if ($v !== '') return $v;
  $m = meta_get($k);
  return $m !== null ? trim($m) : '';
}

/** "25,9K" / "1.2M" → intero. */
function km_to_int(string $num, string $suf): int {
  $n = (float) str_replace(',', '.', preg_replace('/\.(?=\d{3}\b)/', '', $num));
  $suf = strtoupper($suf);
  if ($suf === 'K') $n *= 1000; elseif ($suf === 'M') $n *= 1000000;
  return (int) round($n);
}

/** Token Spotify (client credentials). Cache in memoria per la richiesta. */
function spotify_token(): ?string {
  static $tok = false;
  if ($tok !== false) return $tok;
  $id = stats_cred('spotify_client_id'); $sec = stats_cred('spotify_client_secret');
  if ($id === '' || $sec === '') return $tok = null;
  $ch = curl_init('https://accounts.spotify.com/api/token');
  curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10, CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => 'grant_type=client_credentials',
    CURLOPT_HTTPHEADER => ['Authorization: Basic ' . base64_encode("$id:$sec"),
                           'Content-Type: application/x-www-form-urlencoded'],
  ]);
  $res = curl_exec($ch); curl_close($ch);
  $j = json_decode($res ?: '', true);
  return $tok = ($j['access_token'] ?? null);
}

/** Follower Spotify via Web API ufficiale (serve spotify_client_id/secret in config). */
function spotify_followers(string $url): ?int {
  if (!preg_match('#/artist/([A-Za-z0-9]+)#', $url, $m)) return null;
  $tok = spotify_token();
  if (!$tok) return null;
  $ch = curl_init('https://api.spotify.com/v1/artists/' . $m[1]);
  curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10,
    CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $tok]]);
  $res = curl_exec($ch); $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
  if ($code !== 200) return null;
  $j = json_decode($res ?: '', true);
  return isset($j['followers']['total']) ? (int) $j['followers']['total'] : null;
}

/** Ascoltatori mensili Spotify dall'HTML pubblico dell'artista (best effort). */
function spotify_listeners(string $url): ?int {
  if (!preg_match('#/artist/([A-Za-z0-9]+)#', $url, $m)) return null;
  // UA da crawler social: obbligatoria, altrimenti Spotify serve la shell JS senza dati.
  $r = http_get("https://open.spotify.com/artist/{$m[1]}", 12, UA_CRAWLER);
  if ($r['code'] !== 200 || $r['body'] === '') return null;
  $h = $r['body'];
  // valore esatto ("255,811 monthly listeners")
  if (preg_match('/([\d.,]{3,})\s*(?:monthly listeners|ascoltatori mensili)/i', $h, $mm)) {
    $n = (int) preg_replace('/[.,]/', '', $mm[1]);
    if ($n > 0) return $n;
  }
  // fallback og:description arrotondato ("· 25.9M monthly listeners")
  if (preg_match('/og:description"\s+content="[^"]*?·\s*([\d.,]+)\s*([KM])?\s*monthly/i', $h, $mm)) {
    $n = km_to_int($mm[1], $mm[2] ?? '');
    if ($n > 0) return $n;
  }
  return null;
}

/** Follower TikTok via tikwm.com (valore esatto, no login). */
function tiktok_followers(string $url): ?int {
  if (!preg_match('#tiktok\.com/@?([\w.]+)#', $url, $m)) {
    // consenti anche il solo handle "@nome" o "nome"
    $h = ltrim(trim($url), '@');
    if (!preg_match('/^[\w.]+$/', $h)) return null;
  } else { $h = ltrim($m[1], '@'); }
  if ($h === '') return null;
  $r = http_get('https://www.tikwm.com/api/user/info?unique_id=' . rawurlencode($h));
  if ($r['code'] !== 200 || $r['body'] === '') return null;
  $d = json_decode($r['body'], true);
  if (($d['code'] ?? -1) !== 0) return null;
  $fc = $d['data']['stats']['followerCount'] ?? null;
  return is_numeric($fc) ? (int) $fc : null;
}

/** Handle TikTok da @nome o URL. */
function tiktok_handle(string $v): ?string {
  if (preg_match('#tiktok\.com/@?([\w.]+)#', $v, $m)) $h = ltrim($m[1], '@');
  else { $h = ltrim(trim($v), '@'); }
  return ($h !== '' && preg_match('/^[\w.]+$/', $h)) ? $h : null;
}
/** Ultimi video TikTok (id) via tikwm.com — nessuna API key. Solo gli id vengono salvati:
 *  cover/play di tikwm sono URL firmati che scadono in 1-2 giorni, troppo poco per una
 *  cache settimanale (vedi tiktok-photo.php, che rifà il fetch ad ogni visualizzazione). */
function tiktok_latest_videos(string $url, int $count = 4): array {
  $h = tiktok_handle($url);
  if (!$h) return [];
  $r = http_get('https://www.tikwm.com/api/user/posts?unique_id=' . rawurlencode($h) . '&count=' . $count . '&cursor=0', 10);
  if ($r['code'] !== 200 || $r['body'] === '') return [];
  $d = json_decode($r['body'], true);
  if (($d['code'] ?? -1) !== 0) return [];
  $ids = [];
  foreach ($d['data']['videos'] ?? [] as $v) { if (!empty($v['video_id'])) $ids[] = (string) $v['video_id']; }
  return $ids;
}

/** Follower Twitch via decapi.me. */
function twitch_followers(string $url): ?int {
  if (!preg_match('#twitch\.tv/([A-Za-z0-9_]{2,})#', $url, $m)) return null;
  $r = http_get('https://decapi.me/twitch/followcount/' . rawurlencode(strtolower($m[1])));
  if ($r['code'] !== 200) return null;
  $raw = trim($r['body']);
  return preg_match('/^\d+$/', $raw) ? (int) $raw : null;
}

/** Ultimo VOD Twitch (id) via decapi.me — nessuna API key. */
function twitch_last_video(string $url): ?string {
  if (!preg_match('#twitch\.tv/([A-Za-z0-9_]{2,})#', $url, $m)) return null;
  $u = 'https://decapi.me/twitch/videos/' . rawurlencode(strtolower($m[1])) . '?' .
       http_build_query(['video_format' => '${url}', 'limit' => 1]);
  $r = http_get($u, 10);
  if ($r['code'] !== 200) return null;
  return preg_match('#twitch\.tv/videos/(\d+)#', trim($r['body']), $mm) ? $mm[1] : null;
}

/** Iscritti YouTube via Data API v3 (serve api key). Risolve l'ID canale da qualunque URL. */
function youtube_subs(string $url, string $key): ?int {
  if ($key === '') return null;
  $ucid = null;
  if (preg_match('#/channel/(UC[\w-]{20,})#', $url, $m)) $ucid = $m[1];
  else {
    $r = http_get($url);
    if ($r['code'] === 200 && preg_match('/"(?:externalId|channelId)":"(UC[\w-]{20,})"/', $r['body'], $m)) $ucid = $m[1];
  }
  if (!$ucid) return null;
  $r = http_get("https://www.googleapis.com/youtube/v3/channels?part=statistics&id=$ucid&key=$key");
  if ($r['code'] !== 200) return null;
  $d = json_decode($r['body'], true);
  $st = $d['items'][0]['statistics'] ?? null;
  if (!$st || ($st['hiddenSubscriberCount'] ?? false)) return null;
  return isset($st['subscriberCount']) ? (int) $st['subscriberCount'] : null;
}

// ── Instagram + Facebook via Apify (serve config['apify_token']; consuma credito) ──
/** Esegue un Actor Apify in modo sincrono e ritorna gli item (array vuoto su errore). */
function apify_run(string $actor, array $input, string $token, int $timeout = 45): array {
  $url = "https://api.apify.com/v2/acts/$actor/run-sync-get-dataset-items?token=" . rawurlencode($token) . "&timeout=$timeout";
  $ch = curl_init($url);
  curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => json_encode($input), CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
    CURLOPT_TIMEOUT => $timeout + 30,
  ]);
  $raw = curl_exec($ch); curl_close($ch);
  $j = json_decode($raw ?: '', true);
  return (is_array($j) && !isset($j['error'])) ? $j : [];
}
/** Handle Instagram da @nome o URL. */
function ig_handle_norm(string $v): ?string {
  $v = trim($v);
  if (preg_match('~instagram\.com/([^/?#]+)~i', $v, $m)) $v = $m[1];
  $h = ltrim(strtolower($v), '@');
  return preg_match('/^[a-z0-9._]+$/', $h) && !in_array($h, ['p','reel','reels','explore','stories','tv'], true) ? $h : null;
}
/** URL Pagina Facebook da @nome o URL. */
function fb_url_norm(string $v): ?string {
  $v = trim($v); if ($v === '') return null;
  return preg_match('#^https?://#i', $v) ? $v : 'https://www.facebook.com/' . ltrim($v, '@');
}
/** Follower + URL hotlink delle ultime 3 foto pubblicate, in un'unica chiamata Apify
 *  (l'actor restituisce già "latestPosts" insieme ai follower, nessun costo extra). */
function apify_instagram_data(string $handle, string $token): array {
  $items = apify_run('apify~instagram-profile-scraper', ['usernames' => [$handle]], $token);
  $out = ['followers' => null, 'photos' => [], 'avatar' => null];
  foreach ($items as $it) {
    $f = $it['followersCount'] ?? $it['followers'] ?? null;
    if (is_numeric($f)) $out['followers'] = (int) $f;
    foreach (array_slice($it['latestPosts'] ?? [], 0, 4) as $p) {
      $img = $p['displayUrl'] ?? null;
      if ($img) $out['photos'][] = $img;
    }
    $out['avatar'] = $it['profilePicUrlHD'] ?? $it['profilePicUrl'] ?? null;
    break; // un solo username richiesto
  }
  return $out;
}

/**
 * Avatar Instagram per il profilo agenzia/promoter: stesso meccanismo Apify delle statistiche
 * artista, ma qui serve solo la foto profilo (non follower/post). Usata da admin-create-promoter
 * e admin-update-promoter quando viene indicato un link/handle Instagram. Ritorna l'URL scrappato
 * (da salvare in promoter_profiles.instagram_avatar) oppure null se non disponibile/nessun
 * token Apify configurato — in quel caso il profilo resta senza foto (fallback iniziali colorate).
 */
function fetch_promoter_ig_avatar(string $instagramInput): ?string {
  $h = ig_handle_norm($instagramInput);
  $token = stats_cred('apify_token');
  if (!$h || $token === '') return null;
  return apify_instagram_data($h, $token)['avatar'] ?: null;
}

/** Rifà subito follower+foto Instagram e li fonde nelle stat salvate (senza toccare gli altri
 *  valori). Da chiamare solo quando il link Instagram è appena cambiato, non a ogni salvataggio.
 *  Ricalcola anche photo_url (se l'artista non ha Spotify, l'avatar IG appena arrivato deve
 *  riflettersi subito sulla foto profilo, non restare "intrappolato" solo in stats). */
function refresh_instagram_now(int $userId, string $igUrl, string $token): void {
  $h = ig_handle_norm($igUrl);
  if (!$h || $token === '') return;
  $ig = apify_instagram_data($h, $token);
  if ($ig['followers'] === null && !$ig['photos'] && !$ig['avatar']) return;
  $st = db()->prepare('SELECT stats, photo_url, socials FROM artist_profiles WHERE user_id=?');
  $st->execute([$userId]);
  $row = $st->fetch() ?: [];
  $cur = json_decode($row['stats'] ?? '', true) ?: [];
  if ($ig['followers'] !== null) $cur['instagram_followers'] = $ig['followers'];
  if ($ig['photos']) $cur['instagram_photos'] = $ig['photos'];
  if ($ig['avatar']) $cur['instagram_avatar'] = $ig['avatar'];
  $socials = json_decode($row['socials'] ?? '', true) ?: [];
  $firstGenreSlug = resolve_first_genre_slug($userId, null);
  [$photo, ] = resolve_photo_url($row['photo_url'] ?? null, $socials, $firstGenreSlug, $cur['instagram_avatar'] ?? null, $userId);
  db()->prepare('UPDATE artist_profiles SET stats=?, stats_updated_at=NOW(), photo_url=? WHERE user_id=?')
      ->execute([json_encode($cur, JSON_UNESCAPED_UNICODE), $photo, $userId]);
}
function apify_facebook_followers(string $url, string $token): ?int {
  $items = apify_run('apify~facebook-pages-scraper', ['startUrls' => [['url' => $url]]], $token);
  foreach ($items as $it) { $f = $it['followers'] ?? $it['followersCount'] ?? $it['likes'] ?? null; if (is_numeric($f)) return (int) $f; }
  return null;
}
/** Testo "biography" del profilo Spotify via Apify (Artist's Pick breve, o bio editoriale
 *  più lunga quando presente — dipende dall'artista). */
function apify_spotify_biography(string $url, string $token): ?string {
  // Normalizza l'URL: gli URL Spotify moderni hanno spesso un prefisso di lingua
  // (es. "/intl-it/artist/...") che questo actor Apify non riconosce e per cui
  // restituisce un dataset vuoto. Con "/artist/<id>" pulito funziona correttamente.
  if (!preg_match('#/artist/([A-Za-z0-9]+)#', $url, $m)) return null;
  $cleanUrl = 'https://open.spotify.com/artist/' . $m[1];
  $items = apify_run('automation-lab~spotify-scraper', ['mode' => 'urls', 'urls' => [$cleanUrl], 'maxResults' => 1], $token);
  foreach ($items as $it) {
    $b = trim(html_entity_decode((string)($it['biography'] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    if ($b !== '') return $b;
  }
  return null;
}

/** Ultima uscita discografica + preview audio 30s (iTunes API, nessuna credenziale).
 *  1) risolve l'ID artista dal nome (miglior corrispondenza iTunes);
 *  2) legge il suo intero catalogo via lookup (più completo del semplice /search) e lo
 *     ordina per data noi stessi (il parametro "sort" di iTunes non è affidabile);
 *  3) preferisce i brani a proprio nome (esclude le semplici partecipazioni), con fallback
 *     alle feature se l'artista non ha ancora uscite a proprio nome nel catalogo. */
function itunes_latest_release(string $stageName): ?array {
  $stageName = trim($stageName);
  if ($stageName === '') return null;

  $ru = 'https://itunes.apple.com/search?' . http_build_query([
    'term' => $stageName, 'entity' => 'musicArtist', 'limit' => 1, 'country' => 'IT',
  ]);
  $r = http_get($ru, 10);
  if ($r['code'] !== 200 || $r['body'] === '') return null;
  $artistId = json_decode($r['body'], true)['results'][0]['artistId'] ?? null;
  if (!$artistId) return null;

  $lu = 'https://itunes.apple.com/lookup?' . http_build_query([
    'id' => $artistId, 'entity' => 'song', 'limit' => 200, 'country' => 'IT',
  ]);
  $r = http_get($lu, 10);
  if ($r['code'] !== 200 || $r['body'] === '') return null;
  $tracks = array_values(array_filter(json_decode($r['body'], true)['results'] ?? [],
    fn($x) => ($x['wrapperType'] ?? '') === 'track'));
  if (!$tracks) return null;

  $norm = fn($s) => mb_strtolower(preg_replace('/\s+/', ' ', trim((string) $s)));
  $target = $norm($stageName);
  $own  = array_values(array_filter($tracks, fn($x) => $norm($x['artistName'] ?? '') === $target));
  $pool = $own ?: $tracks;

  usort($pool, fn($a, $b) => strtotime($b['releaseDate'] ?? '1970-01-01') <=> strtotime($a['releaseDate'] ?? '1970-01-01'));
  $t = $pool[0];

  $art = $t['artworkUrl100'] ?? $t['artworkUrl60'] ?? null;
  if ($art) $art = preg_replace('/\d+x\d+bb(?=\.\w+$)/', '600x600bb', $art);   // artwork ad alta risoluzione
  $https = fn(?string $u) => $u ? preg_replace('#^http://#', 'https://', $u) : null;

  return [
    'title'        => $t['trackName'] ?? null,
    'collection'   => $t['collectionName'] ?? null,
    'artwork'      => $https($art),
    'release_date' => substr($t['releaseDate'] ?? '', 0, 10),
    'preview_url'  => $https($t['previewUrl'] ?? null),   // clip audio 30s ufficiale, pronto per <audio>
    'itunes_url'   => $https($t['trackViewUrl'] ?? $t['collectionViewUrl'] ?? null),
  ];
}

/** Ultimo video YouTube (id) via feed RSS del canale — nessuna API key. */
/** Se il link è un video/short specifico (non il canale), ne estrae l'ID direttamente:
 *  in quel caso va mostrato QUEL video, non l'ultimo caricato sul canale. */
function youtube_video_id_from_url(string $url): ?string {
  if (preg_match('#(?:youtube\.com/(?:watch\?(?:.*&)?v=|shorts/|embed/|live/)|youtu\.be/)([A-Za-z0-9_-]{11})#', $url, $m)) return $m[1];
  return null;
}
function youtube_last_video(string $url): ?string {
  $ucid = null;
  if (preg_match('#/channel/(UC[\w-]{20,})#', $url, $m)) $ucid = $m[1];
  else { $r = http_get($url); if ($r['code'] === 200 && preg_match('/"(?:externalId|channelId)":"(UC[\w-]{20,})"/', $r['body'], $m)) $ucid = $m[1]; }
  if (!$ucid) return null;
  $r = http_get("https://www.youtube.com/feeds/videos.xml?channel_id=$ucid", 10);
  if ($r['code'] !== 200) return null;
  return preg_match('#<yt:videoId>([\w-]{11})</yt:videoId>#', $r['body'], $m) ? $m[1] : null;
}

/** Ricalcola e salva le stat di un artista. Ritorna l'array stats salvato.
 *  $withApify=true → aggiorna anche Instagram/Facebook via Apify (consuma credito): usarlo
 *  solo nel job settimanale, non ad ogni visita. */
function refresh_artist_stats(int $userId, array $socials, bool $withApify = false): array {
  $row = db()->prepare('SELECT stage_name, stats, bio_from_spotify, photo_url FROM artist_profiles WHERE user_id = ?');
  $row->execute([$userId]);
  $row = $row->fetch() ?: [];
  $stageName = trim($row['stage_name'] ?? '');
  $cur = json_decode($row['stats'] ?? '', true) ?: [];

  $s = [];
  if (!empty($socials['spotify'])) {
    // preferisci i follower via API ufficiale; fallback agli ascoltatori mensili (scrape)
    $v = spotify_followers($socials['spotify']);
    if ($v !== null) { $s['spotify_followers'] = $v; }
    else { $v = spotify_listeners($socials['spotify']); if ($v !== null) $s['spotify_listeners'] = $v; }
  }
  if (!empty($socials['tiktok']))   { $v = tiktok_followers($socials['tiktok']);   if ($v !== null) $s['tiktok_followers'] = $v;
    $vids = tiktok_latest_videos($socials['tiktok']); if ($vids) $s['tiktok_videos'] = $vids; }
  if (!empty($socials['twitch']))   {
    $v = twitch_followers($socials['twitch']);   if ($v !== null) $s['twitch_followers'] = $v;
    $vid = twitch_last_video($socials['twitch']); if ($vid) $s['twitch_video'] = $vid;
  }
  $key = stats_cred('youtube_api_key');
  if (!empty($socials['youtube']))  {
    $v = youtube_subs($socials['youtube'], $key); if ($v !== null) $s['youtube_subs'] = $v;
    // link a un video/short specifico → mostra quello; link al canale → ultimo caricato (RSS)
    $vid = youtube_video_id_from_url($socials['youtube']) ?? youtube_last_video($socials['youtube']);
    if ($vid) $s['youtube_video'] = $vid;
  }

  // Ultima uscita discografica + preview audio (iTunes, nessuna credenziale).
  if ($stageName !== '') {
    $rel = itunes_latest_release($stageName);
    if ($rel) $s['itunes'] = $rel;
  }

  // valori forniti dal worker cloud o da un refresh Apify precedente (Spotify ascoltatori, IG,
  // FB): non ricalcolabili qui se non withApify → li conserviamo tra un refresh e l'altro.
  foreach (['instagram_followers','facebook_followers','spotify_listeners','instagram_photos','instagram_avatar'] as $k) if (isset($cur[$k])) $s[$k] = $cur[$k];

  // Salva SUBITO le stat veloci (Spotify/TikTok/Twitch/YouTube/iTunes): Apify sotto può
  // metterci decine di secondi o bloccarsi, e se il server uccide la richiesta per timeout
  // NON deve andare perso quello che abbiamo già scaricato (prima c'era un'unica UPDATE
  // finale, insieme ad Apify — bastava che Apify si impallasse per perdere anche il resto).
  $firstGenreSlug = resolve_first_genre_slug($userId, null);
  [$photo, ] = resolve_photo_url($row['photo_url'] ?? null, $socials, $firstGenreSlug, $s['instagram_avatar'] ?? null, $userId);
  db()->prepare('UPDATE artist_profiles SET stats = ?, stats_updated_at = NOW(), photo_url = ? WHERE user_id = ?')
      ->execute([$s ? json_encode($s, JSON_UNESCAPED_UNICODE) : null, $photo, $userId]);

  // Instagram + Facebook: solo nel job settimanale o su richiesta admin (Apify costa credito
  // ed è lento). Se qualcosa cambia, seconda UPDATE mirata; altrimenti restano i valori
  // già salvati sopra (da $cur).
  $apifyTok = stats_cred('apify_token');
  if ($withApify && $apifyTok !== '') {
    $changed = false;
    if (!empty($socials['instagram']) && ($h = ig_handle_norm($socials['instagram']))) {
      $ig = apify_instagram_data($h, $apifyTok);
      if ($ig['followers'] !== null) { $s['instagram_followers'] = $ig['followers']; $changed = true; }
      if ($ig['photos'])             { $s['instagram_photos']    = $ig['photos'];    $changed = true; }
      if ($ig['avatar'])             { $s['instagram_avatar']    = $ig['avatar'];    $changed = true; }
    }
    if (!empty($socials['facebook']) && ($u = fb_url_norm($socials['facebook']))) {
      $v = apify_facebook_followers($u, $apifyTok); if ($v !== null) { $s['facebook_followers'] = $v; $changed = true; }
    }
    if (!empty($row['bio_from_spotify']) && !empty($socials['spotify'])) {
      $bio = apify_spotify_biography($socials['spotify'], $apifyTok);
      if ($bio !== null) db()->prepare('UPDATE artist_profiles SET bio = ? WHERE user_id = ?')->execute([$bio, $userId]);
    }
    if ($changed) {
      [$photo, ] = resolve_photo_url($row['photo_url'] ?? null, $socials, $firstGenreSlug, $s['instagram_avatar'] ?? null, $userId);
      db()->prepare('UPDATE artist_profiles SET stats = ?, stats_updated_at = NOW(), photo_url = ? WHERE user_id = ?')
          ->execute([json_encode($s, JSON_UNESCAPED_UNICODE), $photo, $userId]);
    }
  }

  return $s;
}

/** Aggiorna in blocco fino a $limit artisti con stat mancanti o più vecchie di $days giorni. */
function refresh_stale_stats(int $limit = 10, int $days = 7, bool $withApify = false): int {
  $limit = max(1, min(200, $limit));
  // Ritenta: mai calcolate, oppure più vecchie di $days giorni, oppure VUOTE e più
  // vecchie di 1 giorno (auto-recupero da errori transitori di tikwm/YouTube/ecc.).
  $sql = "SELECT user_id, socials FROM artist_profiles
          WHERE (socials IS NOT NULL OR stage_name <> '')
            AND (stats_updated_at IS NULL
                 OR stats_updated_at < (NOW() - INTERVAL $days DAY)
                 OR ((stats IS NULL OR JSON_LENGTH(stats) = 0) AND stats_updated_at < (NOW() - INTERVAL 1 DAY)))
          ORDER BY stats_updated_at IS NOT NULL, stats_updated_at ASC
          LIMIT $limit";
  $rows = db()->query($sql)->fetchAll();
  $n = 0;
  foreach ($rows as $r) {
    $soc = json_decode($r['socials'] ?? '', true) ?: [];
    refresh_artist_stats((int) $r['user_id'], $soc, $withApify);
    $n++;
  }
  return $n;
}

/** Esegue $fn DOPO aver chiuso la risposta HTTP (non blocca l'utente), se possibile. */
function run_after_response(callable $fn): void {
  if (function_exists('fastcgi_finish_request')) {
    fastcgi_finish_request();
    $fn();
  }
  // se fastcgi_finish_request non c'è, non eseguiamo nulla in-linea per non rallentare la pagina.
}

/** Legge/scrive un valore in app_meta. */
function meta_get(string $k): ?string {
  $st = db()->prepare('SELECT v FROM app_meta WHERE k = ?');
  $st->execute([$k]);
  $v = $st->fetchColumn();
  return $v === false ? null : $v;
}
function meta_set(string $k, string $v): void {
  db()->prepare('INSERT INTO app_meta (k, v) VALUES (?, ?) ON DUPLICATE KEY UPDATE v = VALUES(v)')
      ->execute([$k, $v]);
}
