<?php
/**
 * Deriva l'URL dell'immagine profilo di un artista dai suoi link social.
 * NON scarica nulla sul server: si salva direttamente l'URL remoto (hotlink).
 * Ordine di preferenza:
 *   1. Spotify  → oEmbed pubblico (thumbnail_url), affidabile, senza auth
 *   2. Instagram / sito → meta tag og:image della pagina
 *
 * Ritorna l'URL immagine remoto, oppure la foto attuale se non trovata.
 */

const UA_BROWSER = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124 Safari/537.36';
// UA da crawler social: Spotify serve l'HTML completo con og:description (ascoltatori
// mensili) SOLO ai bot dei social. Con una UA da browser restituisce la shell JS vuota,
// sia dall'hosting che dal cloud. Questo è ciò che rende lo scrape possibile senza app.
const UA_CRAWLER = 'facebookexternalhit/1.1 (+http://www.facebook.com/externalhit_uatext.php)';

/**
 * Guardia anti-SSRF: accetta solo http/https verso host che risolvono a IP PUBBLICI.
 * Blocca loopback, range privati (RFC1918), link-local (169.254.0.0/16 → metadata cloud)
 * e riservati, sia IPv4 che IPv6. Da usare PRIMA di ogni fetch di URL forniti dall'utente
 * (calendario iCal, social, sito). Ritorna true se l'URL è sicuro da contattare.
 */
function ssrf_url_ok(string $url): bool {
  $p = parse_url($url);
  if (!$p || empty($p['scheme']) || empty($p['host'])) return false;
  if (!in_array(strtolower($p['scheme']), ['http', 'https'], true)) return false;
  $host = $p['host'];

  $ips = [];
  if (filter_var($host, FILTER_VALIDATE_IP)) {
    $ips = [$host];
  } else {
    foreach (@dns_get_record($host, DNS_A | DNS_AAAA) ?: [] as $r) {
      if (!empty($r['ip']))   $ips[] = $r['ip'];
      if (!empty($r['ipv6'])) $ips[] = $r['ipv6'];
    }
    if (!$ips) { $l = @gethostbynamel($host); if ($l) $ips = $l; }
  }
  if (!$ips) return false;

  foreach ($ips as $ip) {
    // NO_PRIV_RANGE + NO_RES_RANGE scartano privati/loopback/link-local/riservati
    if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) return false;
  }
  return true;
}

function http_get(string $url, int $timeout = 12, ?string $ua = null): array {
  // Segue i redirect MANUALMENTE, rivalidando ogni hop contro la guardia SSRF: così un
  // 302 verso un IP interno (169.254.169.254, localhost, RFC1918) viene bloccato invece
  // di essere seguito ciecamente come farebbe CURLOPT_FOLLOWLOCATION.
  $body = ''; $code = 0; $ct = '';
  for ($hop = 0; $hop < 5; $hop++) {
    if (!ssrf_url_ok($url)) return ['body' => '', 'code' => 0, 'ct' => '', 'blocked' => true];
    $ch = curl_init($url);
    curl_setopt_array($ch, [
      CURLOPT_RETURNTRANSFER => true,
      CURLOPT_FOLLOWLOCATION => false,
      CURLOPT_TIMEOUT        => $timeout,
      CURLOPT_PROTOCOLS      => CURLPROTO_HTTP | CURLPROTO_HTTPS,
      CURLOPT_USERAGENT      => $ua ?: UA_BROWSER,
      CURLOPT_HTTPHEADER     => ['Accept-Language: it,en;q=0.8'],
      CURLOPT_COOKIE         => 'CONSENT=YES+1',   // bypassa il consent-wall YouTube dagli IP datacenter
    ]);
    $body = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $ct   = (string)curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
    $loc  = (string)curl_getinfo($ch, CURLINFO_REDIRECT_URL);
    curl_close($ch);
    if ($code >= 300 && $code < 400 && $loc !== '') { $url = $loc; continue; }
    break;
  }
  return ['body' => $body ?: '', 'code' => $code, 'ct' => $ct];
}

/** Estrae l'URL di og:image (o twitter:image) dall'HTML di una pagina. */
function extract_og_image(string $html): ?string {
  if ($html === '') return null;
  $patterns = [
    '/<meta[^>]+(?:property|name)=["\'](?:og:image|og:image:secure_url|twitter:image)["\'][^>]+content=["\']([^"\']+)["\']/i',
    '/<meta[^>]+content=["\']([^"\']+)["\'][^>]+(?:property|name)=["\'](?:og:image|twitter:image)["\']/i',
  ];
  foreach ($patterns as $re) {
    if (preg_match($re, $html, $m)) {
      $u = html_entity_decode(trim($m[1]), ENT_QUOTES);
      if (preg_match('#^https?://#i', $u)) return $u;
    }
  }
  return null;
}

/** URL immagine da Spotify via oEmbed pubblico. */
function spotify_image(string $url): ?string {
  if (!preg_match('#open\.spotify\.com/#i', $url)) return null;
  $r = http_get('https://open.spotify.com/oembed?url=' . rawurlencode($url), 10);
  if ($r['code'] === 200) {
    $j = json_decode($r['body'], true);
    if (!empty($j['thumbnail_url'])) return $j['thumbnail_url'];
  }
  return null;
}

/** Normalizza un handle/URL Instagram in URL profilo. */
function instagram_url(string $v): ?string {
  $v = trim($v);
  if ($v === '') return null;
  if (preg_match('#instagram\.com/#i', $v)) return $v;
  $h = ltrim($v, '@');
  if (preg_match('/^[A-Za-z0-9._]+$/', $h)) return "https://www.instagram.com/$h/";
  return null;
}

/** URL immagine sorgente dai social. SOLO Spotify (via oEmbed pubblico). */
function social_image_source(array $socials): ?string {
  if (!empty($socials['spotify'])) {
    if ($img = spotify_image($socials['spotify'])) return $img;
  }
  return null;
}

/**
 * Foto profilo artista — logica CONDIVISA da artist-save.php, admin-create-artist.php,
 * admin-update-artist.php e refresh_artist_stats()/refresh_instagram_now() in _stats.php
 * (stessa priorità ovunque, che l'artista si modifichi da solo, lo faccia un admin, o scatti
 * il refresh periodico). Nessuna scelta manuale: è tutto automatico.
 *   1. Spotify (hotlink diretto, nessun download)
 *   2. Instagram (l'avatar già salvato in stats.instagram_avatar dall'ultimo refresh statistiche,
 *      per chi non ha Spotify collegato) — servito via /api/ig-avatar.php, NON in hotlink diretto:
 *      il CDN di Instagram manda "Cross-Origin-Resource-Policy: same-origin" e i browser
 *      bloccherebbero l'<img> da un altro dominio (stesso motivo di /api/ig-photo.php).
 *   3. Icona automatica a tema in base al PRIMO genere musicale scelto
 *   4. Foto attuale (nessuna modifica)
 * Ritorna [url_o_null, sorgente] dove sorgente è 'social'|'auto'|'current'.
 */
function resolve_photo_url(?string $current, array $socials, ?string $firstGenreSlug, ?string $instagramAvatar = null, int $userId = 0): array {
  $socialImg = social_image_source($socials);
  if ($socialImg) return [$socialImg, 'social'];
  if ($instagramAvatar && $userId > 0) return ['/api/ig-avatar.php?u=' . $userId, 'social'];
  if ($firstGenreSlug && file_exists(__DIR__ . '/../assets/avatars/genre-' . $firstGenreSlug . '.svg')) {
    return ['/assets/avatars/genre-' . $firstGenreSlug . '.svg', 'auto'];
  }
  return [$current, 'current'];
}

/**
 * Slug del PRIMO genere musicale di un artista, per l'icona automatica. Usa i generi appena
 * inviati (ordine di selezione) se presenti, altrimenti quelli già salvati in DB.
 */
function resolve_first_genre_slug(int $userId, ?array $submittedGenreIds): ?string {
  $gid = null;
  if ($submittedGenreIds !== null && count($submittedGenreIds) > 0) {
    $gid = (int)$submittedGenreIds[0];
  } else {
    $st = db()->prepare('SELECT genre_id FROM artist_genres WHERE artist_user_id=? ORDER BY genre_id LIMIT 1');
    $st->execute([$userId]);
    $gid = $st->fetchColumn() ?: null;
  }
  if (!$gid) return null;
  $st = db()->prepare('SELECT slug FROM genres WHERE id=?');
  $st->execute([(int)$gid]);
  return $st->fetchColumn() ?: null;
}

/** Normalizza fino a 3 link manuali {title,url} inviati dal form artista (uguale ovunque:
 *  profilo.html self-edit e admin.html condividono lo stesso artist-form.js). */
function custom_links_sanitize($raw): array {
  $out = [];
  foreach (array_slice(is_array($raw) ? $raw : [], 0, 3) as $l) {
    $title = trim((string) (is_array($l) ? ($l['title'] ?? '') : ''));
    $url   = trim((string) (is_array($l) ? ($l['url'] ?? '') : ''));
    if ($title === '' || $url === '') continue;
    if (!preg_match('#^https?://#i', $url)) $url = 'https://' . $url;
    $out[] = ['title' => mb_substr($title, 0, 60), 'url' => $url];
  }
  return $out;
}
