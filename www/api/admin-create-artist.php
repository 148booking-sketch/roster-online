<?php
/**
 * POST /api/admin-create-artist.php   (solo admin)
 * Crea a mano un utente artista + profilo. Geocodifica il comune, associa i generi.
 * Body: { email, password, stage_name, formazione, bio, phone, comune, provincia,
 *         cachet_min, cachet_max, rimborso_tipo, rimborso_forfait, travel_max_km,
 *         durata_set_min, website, socials:{}, genres:[id], published, verified }
 */
require_once __DIR__ . '/_admin.php';
require_once __DIR__ . '/_geo.php';
require_once __DIR__ . '/_social.php';
require_once __DIR__ . '/_stats.php';
require_once __DIR__ . '/_calendar.php';
require_once __DIR__ . '/_gear.php';
only('POST');
require_admin();

$in    = body();
$stage = trim($in['stage_name'] ?? '');
if ($stage === '') fail('stage_name_required');

// Profilo "Verificato" gestito direttamente da noi: nessuna email/password richiesta all'admin,
// se ne genera una automaticamente (l'artista non ha bisogno di accedere).
$verifiedIn = isset($in['verified']) ? (int)!!$in['verified'] : 0;
$managed = $verifiedIn && trim($in['email'] ?? '') === '';
if ($managed) {
  $email = managed_email($stage);
  $pass  = managed_password();
} else {
  $email = strtolower(trim($in['email'] ?? ''));
  $pass  = (string)($in['password'] ?? '');
  if (!filter_var($email, FILTER_VALIDATE_EMAIL)) fail('email_invalid');
  if (strlen($pass) < 8) fail('password_too_short');
}

$form = in_array($in['formazione'] ?? '', show_types(), true) ? $in['formazione'] : 'live_band';
$rimb = in_array($in['rimborso_tipo'] ?? '', ['incluso','forfait','da_concordare'], true) ? $in['rimborso_tipo'] : 'da_concordare';
$bio     = trim($in['bio'] ?? '');
$phone   = trim($in['phone'] ?? '');
$comune  = trim($in['comune'] ?? '');
$prov    = strtoupper(trim($in['provincia'] ?? '')) ?: null;
$website = normalize_url($in['website'] ?? '');
$label      = trim($in['label'] ?? '');
$management = trim($in['management'] ?? '');

// Associazione a un'agenzia booking/management (opzionale): dev'essere un utente 'management'.
$managerId  = (int)($in['manager_user_id'] ?? 0);
$managerVal = null;
if ($managerId > 0) {
  $mchk = db()->prepare("SELECT id FROM users WHERE id=? AND role='management'");
  $mchk->execute([$managerId]);
  if ($mchk->fetch()) $managerVal = $managerId;
}

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

$socialsArr = isset($in['socials']) && is_array($in['socials']) ? array_filter($in['socials'], fn($v) => trim((string)$v) !== '') : [];
$socials    = $socialsArr ? json_encode($socialsArr, JSON_UNESCAPED_UNICODE) : null;

// Backline & scheda tecnica
require_once __DIR__ . '/_gear.php';
$techUrl = trim($in['tech_sheet_url'] ?? '');
$techUrl = preg_match('#^https?://#i', $techUrl) ? $techUrl : '';
$gearBring = gear_whitelist($in['gear_bring'] ?? [], 'bring');
$gearNeed  = gear_whitelist($in['gear_need'] ?? [], 'need');
$gearBringJson = $gearBring ? json_encode($gearBring, JSON_UNESCAPED_UNICODE) : null;
$gearNeedJson  = $gearNeed  ? json_encode($gearNeed,  JSON_UNESCAPED_UNICODE) : null;

$trattabile = (isset($in['cachet_trattabile']) && (string)$in['cachet_trattabile'] === '0') ? 0 : 1;
$bioFromSpotify = (isset($in['bio_from_spotify']) && (string)$in['bio_from_spotify'] === '1') ? 1 : 0;

$published = isset($in['published']) ? (int)!!$in['published'] : 1;
$verified  = $verifiedIn;
$top8      = isset($in['top8'])      ? (int)!!$in['top8']      : 0;

$pdo = db();
$st = $pdo->prepare('SELECT id FROM users WHERE email = ?');
$st->execute([$email]);
if ($st->fetch()) fail('email_taken', 409);

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
    $uid, $managerVal, $stage, $slug, $form, $componenti, $bio, $bioFromSpotify, $phone, ($comune ?: null), $prov,
    $lat, $lng, $cachetMin, $cachetMax, $trattabile, $cachetPromo, $promoUntil, $rimb, $rimbForf, $travelKm,
    $durata, $website, $socials, ($label ?: null), ($management ?: null),
    ($techUrl ?: null), $gearBringJson, $gearNeedJson,
    $verified, $top8, $published,
  ]);

  if (isset($in['genres']) && is_array($in['genres'])) {
    $ins = $pdo->prepare('INSERT IGNORE INTO artist_genres (artist_user_id, genre_id) VALUES (?, ?)');
    foreach (array_slice($in['genres'], 0, $verified ? 3 : 1) as $gid) {
      $gid = (int)$gid;
      if ($gid > 0) $ins->execute([$uid, $gid]);
    }
  }

  $pdo->commit();
} catch (Throwable $e) {
  $pdo->rollBack();
  fail('create_failed', 500);
}

// Rilascia il lock di sessione PRIMA delle chiamate lente (Apify/calendario): altrimenti, finché
// questa richiesta è in corso, ogni altra richiesta con lo stesso cookie di sessione (altre tab,
// la nav della pagina stessa) resta bloccata in attesa dello stesso file di sessione.
if (session_status() === PHP_SESSION_ACTIVE) session_write_close();

// Foto profilo: Spotify/social → icona automatica dal 1° genere → nessuna. Best-effort, fuori transazione.
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

// Statistiche iniziali (leggere: Spotify/YouTube/TikTok/Twitch). IG/FB via il pulsante "aggiorna".
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
