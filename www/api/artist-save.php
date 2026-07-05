<?php
/**
 * POST /api/artist-save.php  (solo artisti loggati)
 * Salva/aggiorna il profilo artista. Geocodifica il comune se cambiato.
 * Body accetta i campi di artist_profiles + genres:[id,...]
 */
require_once __DIR__ . '/_http.php';
require_once __DIR__ . '/_geo.php';
require_once __DIR__ . '/_social.php';
require_once __DIR__ . '/_calendar.php';
require_once __DIR__ . '/_gear.php';
require_once __DIR__ . '/_stats.php';
only('POST');

$u  = require_user('artist');
$in = body();

// Campi testo/enum ammessi
$stage    = trim($in['stage_name'] ?? '');
$form     = in_array($in['formazione'] ?? '', show_types(), true) ? $in['formazione'] : 'live_band';
$rimb     = in_array($in['rimborso_tipo'] ?? '', ['incluso','forfait','da_concordare'], true) ? $in['rimborso_tipo'] : 'da_concordare';
$bio      = trim($in['bio'] ?? '');
$phone    = trim($in['phone'] ?? '');
$comune   = trim($in['comune'] ?? '');
$prov     = strtoupper(trim($in['provincia'] ?? '')) ?: null;
$website  = normalize_url($in['website'] ?? '');
$label      = trim($in['label'] ?? '');
$management = trim($in['management'] ?? '');

$intOrNull = fn($v) => ($v === '' || $v === null) ? null : max(0, (int)$v);
$cachetMin = $intOrNull($in['cachet_min'] ?? null);
$cachetMax = $intOrNull($in['cachet_max'] ?? null);
$cachetPromo = $intOrNull($in['cachet_promo'] ?? null);
$promoUntil  = preg_match('/^\d{4}-\d{2}-\d{2}$/', $in['promo_until'] ?? '') ? $in['promo_until'] : null;
if ($cachetPromo === null) $promoUntil = null;   // niente promo → nessuna data
$rimbForf  = $intOrNull($in['rimborso_forfait'] ?? null);
$travelKm  = $intOrNull($in['travel_max_km'] ?? null);
$durata    = $intOrNull($in['durata_set_min'] ?? null);
$componenti= $intOrNull($in['componenti'] ?? null);
$rimbKm    = ($in['rimborso_km'] ?? '') === '' ? null : round((float)$in['rimborso_km'], 2);
$trattabile = (isset($in['cachet_trattabile']) && (string)$in['cachet_trattabile'] === '0') ? 0 : 1;
$bioFromSpotify = (isset($in['bio_from_spotify']) && (string)$in['bio_from_spotify'] === '1') ? 1 : 0;
// array_filter come negli altri write-path (admin/management): un social svuotato dall'artista
// deve sparire dal JSON, non restare come stringa vuota (altrimenti !empty($socials['x']) altrove
// si comporta diversamente a seconda di chi ha fatto l'ultima modifica).
$socialsArr = isset($in['socials']) && is_array($in['socials']) ? array_filter($in['socials'], fn($v) => trim((string)$v) !== '') : [];
$socials    = $socialsArr ? json_encode($socialsArr, JSON_UNESCAPED_UNICODE) : null;

// Valori "prima" del salvataggio: servono dopo, in background, per capire se bio-Spotify/
// Instagram vanno risincronizzati subito (sync fatta DOPO la risposta: non deve rallentarla).
$wasSt = db()->prepare('SELECT bio_from_spotify, socials, verified FROM artist_profiles WHERE user_id=?');
$wasSt->execute([$u['id']]);
$wasRow = $wasSt->fetch();
$wasBioFromSpotify = (int) ($wasRow['bio_from_spotify'] ?? 0);
$wasSocials = json_decode($wasRow['socials'] ?? '', true) ?: [];
// Il limite generi (3 per i non verificati, illimitati per i verificati) segue il flag "verified" già in
// DB: l'artista non può cambiarlo da sé, quindi qui vale solo lo stato attuale, non il payload.
$maxGenres = (int) ($wasRow['verified'] ?? 0) === 1 ? PHP_INT_MAX : 3;

// Trattativa riservata: solo gli artisti VERIFICATI possono attivarla (per gli altri resta 0).
ensure_trattativa_col();
$trvRis = ((int) ($wasRow['verified'] ?? 0) === 1 && !empty($in['trattativa_riservata'])) ? 1 : 0;

// Backline & scheda tecnica
require_once __DIR__ . '/_gear.php';
$techUrl  = trim($in['tech_sheet_url'] ?? '');
$techUrl  = preg_match('#^https?://#i', $techUrl) ? $techUrl : '';
$gearBring = gear_whitelist($in['gear_bring'] ?? [], 'bring');
$gearNeed  = gear_whitelist($in['gear_need'] ?? [], 'need');
$gearBringJson = $gearBring ? json_encode($gearBring, JSON_UNESCAPED_UNICODE) : null;
$gearNeedJson  = $gearNeed  ? json_encode($gearNeed,  JSON_UNESCAPED_UNICODE) : null;

// Geocoding: solo se è stato indicato un comune. Cache interna evita chiamate ripetute.
$lat = $lng = null;
if ($comune !== '') {
  $geo = geocode_comune($comune, $prov);
  if ($geo) { $lat = $geo['lat']; $lng = $geo['lng']; }
}

$pdo = db();
$slug = $stage !== '' ? preg_replace('/[^a-z0-9]+/', '-', mb_strtolower($stage)) . '-' . $u['id'] : null;
$slug = $slug ? trim($slug, '-') : null;

$sql = 'UPDATE artist_profiles SET
          stage_name=?, slug=?, formazione=?, componenti=?, bio=?, bio_from_spotify=?, phone=?,
          comune=?, provincia=?, lat=?, lng=?,
          cachet_min=?, cachet_max=?, cachet_trattabile=?, trattativa_riservata=?, cachet_promo=?, promo_until=?, rimborso_tipo=?, rimborso_km=?, rimborso_forfait=?,
          travel_max_km=?, durata_set_min=?, website=?, socials=?,
          label=?, management=?,
          tech_sheet_url=?, gear_bring=?, gear_need=?
        WHERE user_id=?';
db()->prepare($sql)->execute([
  $stage, $slug, $form, $componenti, $bio, $bioFromSpotify, $phone,
  ($comune ?: null), $prov, $lat, $lng,
  $cachetMin, $cachetMax, $trattabile, $trvRis, $cachetPromo, $promoUntil, $rimb, $rimbKm, $rimbForf,
  $travelKm, $durata, $website, $socials,
  ($label ?: null), ($management ?: null),
  ($techUrl ?: null), $gearBringJson, $gearNeedJson,
  $u['id'],
]);

// Generi (sostituzione completa)
if (isset($in['genres']) && is_array($in['genres'])) {
  $pdo->prepare('DELETE FROM artist_genres WHERE artist_user_id=?')->execute([$u['id']]);
  $ins = $pdo->prepare('INSERT IGNORE INTO artist_genres (artist_user_id, genre_id) VALUES (?, ?)');
  foreach (array_slice($in['genres'], 0, $maxGenres) as $gid) {
    $gid = (int)$gid;
    if ($gid > 0) $ins->execute([$u['id'], $gid]);
  }
}

// Foto profilo: Spotify → Instagram → icona automatica dal 1° genere → foto attuale. Nessun download (hotlink).
$st = db()->prepare('SELECT photo_url, stats FROM artist_profiles WHERE user_id=?');
$st->execute([$u['id']]);
$row0 = $st->fetch();
$current = $row0['photo_url'] ?: null;
$instagramAvatar = (json_decode($row0['stats'] ?? '', true) ?: [])['instagram_avatar'] ?? null;
$submittedGenres = isset($in['genres']) && is_array($in['genres']) ? array_map('intval', $in['genres']) : null;
$firstGenreSlug = resolve_first_genre_slug($u['id'], $submittedGenres);
[$photo, $photoSource] = resolve_photo_url($current, $socialsArr, $firstGenreSlug, $instagramAvatar, $u['id']);

if ($photo !== $current) {
  db()->prepare('UPDATE artist_profiles SET photo_url=? WHERE user_id=?')->execute([$photo, $u['id']]);
}

// Calendario Google (iCal): salva l'URL e ricalcola le date occupate.
$calUrl = trim($in['calendar_url'] ?? '');
db()->prepare('UPDATE artist_profiles SET calendar_url = ? WHERE user_id = ?')->execute([$calUrl ?: null, $u['id']]);

// Rilascia il lock di sessione PRIMA delle chiamate lente (Apify/calendario): altrimenti, finché
// questa richiesta è in corso, ogni altra richiesta con lo stesso cookie di sessione (altre tab,
// la nav della pagina stessa) resta bloccata in attesa dello stesso file di sessione.
if (session_status() === PHP_SESSION_ACTIVE) session_write_close();

try { refresh_artist_calendar($u['id'], $calUrl ?: null); } catch (Throwable $e) { /* calendario non valido/irraggiungibile: ignora */ }
try { refresh_artist_stats($u['id'], $socialsArr, false); } catch (Throwable $e) { /* ignora */ }

// Se il flag "bio da Spotify" viene appena attivato, o il link Instagram è appena cambiato,
// sincronizza subito (invece di aspettare il refresh settimanale/manuale).
$apifyTok = stats_cred('apify_token');
if ($apifyTok !== '') {
  if ($bioFromSpotify && !$wasBioFromSpotify && !empty($socialsArr['spotify'])) {
    $freshBio = apify_spotify_biography($socialsArr['spotify'], $apifyTok);
    if ($freshBio !== null) db()->prepare('UPDATE artist_profiles SET bio=? WHERE user_id=?')->execute([$freshBio, $u['id']]);
  }
  if (!empty($socialsArr['instagram']) && trim($socialsArr['instagram']) !== trim($wasSocials['instagram'] ?? '')) {
    refresh_instagram_now($u['id'], $socialsArr['instagram'], $apifyTok);
  }
}

ok([
  'saved'    => true,
  'geocoded' => $lat !== null,
  'slug'     => $slug,
  'photo_url'=> $photo,
  'photo_from' => $photoSource,
]);
