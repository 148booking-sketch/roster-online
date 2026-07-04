<?php
/**
 * POST /api/management-create-artist.php   (solo booking/management ATTIVI)
 * Crea un artista GESTITO dal booking corrente. Come admin-create-artist.php ma:
 *   - il proprietario è il booking loggato (manager_user_id);
 *   - l'artista è sempre "gestito" (email/password auto-generate, nessun login proprio);
 *   - deve superare l'idoneità iTunes (≥4 brani in 2 anni) → nasce verified=1, published=1;
 *   - il booking non decide stato/featured: status=active, top8=0.
 * Body: stessi campi del form condiviso (artist-form.js), + socials.applemusic obbligatorio.
 */
require_once __DIR__ . '/_management.php';
require_once __DIR__ . '/_itunes.php';
require_once __DIR__ . '/_geo.php';
require_once __DIR__ . '/_social.php';
require_once __DIR__ . '/_stats.php';
require_once __DIR__ . '/_calendar.php';
require_once __DIR__ . '/_gear.php';
only('POST');
$me = require_management();

$in    = body();
$stage = trim($in['stage_name'] ?? '');
if ($stage === '') fail('stage_name_required');

// Idoneità iTunes — ri-verificata lato server (non ci si fida del client). Il link Apple Music
// arriva tra i social (campo s_am del form). Deve esserci ed essere idoneo.
$socialsArr = isset($in['socials']) && is_array($in['socials']) ? array_filter($in['socials'], fn($v) => trim((string)$v) !== '') : [];
$appleMusic = trim($socialsArr['applemusic'] ?? '');
if ($appleMusic === '') fail('applemusic_required');
$elig = itunes_eligibility($appleMusic);
if (!$elig['eligible']) fail('not_eligible', 422, ['track_count' => $elig['track_count']]);

// Profilo gestito: credenziali generate automaticamente (l'artista non accede).
$email = managed_email($stage);
$pass  = managed_password();

$form = in_array($in['formazione'] ?? '', show_types(), true) ? $in['formazione'] : 'live_band';
$rimb = in_array($in['rimborso_tipo'] ?? '', ['incluso','forfait','da_concordare'], true) ? $in['rimborso_tipo'] : 'da_concordare';
$bio     = trim($in['bio'] ?? '');
$phone   = trim($in['phone'] ?? '');
$comune  = trim($in['comune'] ?? '');
$prov    = strtoupper(trim($in['provincia'] ?? '')) ?: null;
$website = normalize_url($in['website'] ?? '');
$label      = trim($in['label'] ?? '');
$management = trim($in['management'] ?? '');

$intOrNull = fn($v) => ($v === '' || $v === null) ? null : max(0, (int)$v);
$cachetMin  = $intOrNull($in['cachet_min'] ?? null);
$cachetMax  = $intOrNull($in['cachet_max'] ?? null);
$cachetPromo= $intOrNull($in['cachet_promo'] ?? null);
$promoUntil = preg_match('/^\d{4}-\d{2}-\d{2}$/', $in['promo_until'] ?? '') ? $in['promo_until'] : null;
if ($cachetPromo === null) $promoUntil = null;
$rimbForf   = $intOrNull($in['rimborso_forfait'] ?? null);
$travelKm   = $intOrNull($in['travel_max_km'] ?? null);
$durata     = $intOrNull($in['durata_set_min'] ?? null);
$componenti = $intOrNull($in['componenti'] ?? null);

$socials = $socialsArr ? json_encode($socialsArr, JSON_UNESCAPED_UNICODE) : null;

$techUrl = trim($in['tech_sheet_url'] ?? '');
$techUrl = preg_match('#^https?://#i', $techUrl) ? $techUrl : '';
$gearBring = gear_whitelist($in['gear_bring'] ?? [], 'bring');
$gearNeed  = gear_whitelist($in['gear_need'] ?? [], 'need');
$gearBringJson = $gearBring ? json_encode($gearBring, JSON_UNESCAPED_UNICODE) : null;
$gearNeedJson  = $gearNeed  ? json_encode($gearNeed,  JSON_UNESCAPED_UNICODE) : null;

$trattabile = (isset($in['cachet_trattabile']) && (string)$in['cachet_trattabile'] === '0') ? 0 : 1;
$bioFromSpotify = (isset($in['bio_from_spotify']) && (string)$in['bio_from_spotify'] === '1') ? 1 : 0;

// Fisso: artista gestito da un booking → verificato e pubblicato subito, non featured.
$verified = 1; $published = 1; $top8 = 0;

$pdo = db();

// Geocoding comune (best-effort)
$lat = $lng = null;
if ($comune !== '') {
  $geo = geocode_comune($comune, $prov);
  if ($geo) { $lat = $geo['lat']; $lng = $geo['lng']; }
}

$pdo->beginTransaction();
try {
  $pdo->prepare(
    'INSERT INTO users (email, password_hash, role, display_name, status, email_verified)
     VALUES (?, ?, "artist", ?, "active", 1)'
  )->execute([$email, password_hash($pass, PASSWORD_DEFAULT), $stage]);
  $uid = (int)$pdo->lastInsertId();
  $slug = make_slug($stage, $uid);

  $pdo->prepare(
    'INSERT INTO artist_profiles
       (user_id, manager_user_id, stage_name, slug, formazione, componenti, bio, bio_from_spotify, phone, comune, provincia,
        lat, lng, cachet_min, cachet_max, cachet_trattabile, cachet_promo, promo_until, rimborso_tipo, rimborso_forfait, travel_max_km,
        durata_set_min, website, socials, label, management,
        tech_sheet_url, gear_bring, gear_need, verified, top8, published)
     VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)'
  )->execute([
    $uid, $me['id'], $stage, $slug, $form, $componenti, $bio, $bioFromSpotify, $phone, ($comune ?: null), $prov,
    $lat, $lng, $cachetMin, $cachetMax, $trattabile, $cachetPromo, $promoUntil, $rimb, $rimbForf, $travelKm,
    $durata, $website, $socials, ($label ?: null), ($management ?: null),
    ($techUrl ?: null), $gearBringJson, $gearNeedJson,
    $verified, $top8, $published,
  ]);

  // Artista verificato → fino a 3 generi.
  if (isset($in['genres']) && is_array($in['genres'])) {
    $ins = $pdo->prepare('INSERT IGNORE INTO artist_genres (artist_user_id, genre_id) VALUES (?, ?)');
    foreach (array_slice($in['genres'], 0, 3) as $gid) {
      $gid = (int)$gid;
      if ($gid > 0) $ins->execute([$uid, $gid]);
    }
  }

  $pdo->commit();
} catch (Throwable $e) {
  $pdo->rollBack();
  fail('create_failed', 500);
}

// Rilascia il lock di sessione prima delle chiamate lente (Apify/calendario/stats).
if (session_status() === PHP_SESSION_ACTIVE) session_write_close();

// Foto profilo: Spotify/social → icona automatica dal 1° genere → nessuna. Best-effort.
$submittedGenres = isset($in['genres']) && is_array($in['genres']) ? array_map('intval', $in['genres']) : null;
$firstGenreSlug = resolve_first_genre_slug($uid, $submittedGenres);
[$photo, $photoSource] = resolve_photo_url(null, $socialsArr, $firstGenreSlug, null, $uid);
if ($photo) {
  try { $pdo->prepare('UPDATE artist_profiles SET photo_url=? WHERE user_id=?')->execute([$photo, $uid]); }
  catch (Throwable $e) { /* ignora */ }
}

// Calendario Google (iCal): salva URL e ricalcola le date occupate (best-effort).
$calUrl = trim($in['calendar_url'] ?? '');
$calUrl = preg_match('#^https?://#i', $calUrl) ? $calUrl : '';
if ($calUrl) {
  $pdo->prepare('UPDATE artist_profiles SET calendar_url=? WHERE user_id=?')->execute([$calUrl, $uid]);
  try { refresh_artist_calendar($uid, $calUrl); } catch (Throwable $e) { /* ignora */ }
}

// Statistiche iniziali (Spotify/YouTube/TikTok/Twitch). IG/FB via il pulsante "aggiorna".
$stats = [];
if ($socialsArr) {
  try { $stats = refresh_artist_stats($uid, $socialsArr, false); } catch (Throwable $e) { /* ignora */ }
}

// Bio da Spotify (se il flag è attivo) + Instagram (se già inserito): prendi subito i dati.
$apifyTok = stats_cred('apify_token');
if ($apifyTok !== '') {
  if ($bioFromSpotify && !empty($socialsArr['spotify'])) {
    $freshBio = apify_spotify_biography($socialsArr['spotify'], $apifyTok);
    if ($freshBio !== null) $pdo->prepare('UPDATE artist_profiles SET bio=? WHERE user_id=?')->execute([$freshBio, $uid]);
  }
  if (!empty($socialsArr['instagram'])) {
    try { refresh_instagram_now($uid, $socialsArr['instagram'], $apifyTok); } catch (Throwable $e) { /* ignora */ }
  }
}

ok(['id' => $uid, 'slug' => $slug, 'geocoded' => $lat !== null, 'photo_url' => $photo, 'photo_from' => $photo ? $photoSource : null, 'stats' => $stats]);
