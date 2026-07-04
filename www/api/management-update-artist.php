<?php
/**
 * POST /api/management-update-artist.php   (solo booking/management ATTIVI)
 * Aggiorna un artista GESTITO dal booking corrente (profilo + generi). Come admin-update-artist.php
 * ma scoped alla proprietà: NON tocca email/password/stato (l'artista resta gestito e attivo),
 * NON cambia verificato/featured/pubblicazione (verified resta 1, publish/top8 li gestisce l'admin).
 * Body: { id, ...campi del form condiviso (artist-form.js), genres:[id] }
 */
require_once __DIR__ . '/_management.php';
require_once __DIR__ . '/_geo.php';
require_once __DIR__ . '/_calendar.php';
require_once __DIR__ . '/_gear.php';
require_once __DIR__ . '/_social.php';
require_once __DIR__ . '/_stats.php';
only('POST');
$me = require_management();

$in = body();
$id = (int)($in['id'] ?? 0);
require_managed_artist($id, (int) $me['id']);   // esiste ed è mio, altrimenti fail()

$stage = trim($in['stage_name'] ?? '');
if ($stage === '') fail('stage_name_required');

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

$socialsArr = isset($in['socials']) && is_array($in['socials']) ? array_filter($in['socials'], fn($v) => trim((string)$v) !== '') : [];
$socials    = $socialsArr ? json_encode($socialsArr, JSON_UNESCAPED_UNICODE) : null;

// Foto profilo: Spotify → Instagram → icona dal 1° genere → foto attuale (come artist-save.php).
$currentPhotoSt = db()->prepare('SELECT photo_url, stats FROM artist_profiles WHERE user_id=?');
$currentPhotoSt->execute([$id]);
$row0 = $currentPhotoSt->fetch();
$currentPhoto = $row0['photo_url'] ?: null;
$instagramAvatar = (json_decode($row0['stats'] ?? '', true) ?: [])['instagram_avatar'] ?? null;
$submittedGenres = isset($in['genres']) && is_array($in['genres']) ? array_map('intval', $in['genres']) : null;
$firstGenreSlug = resolve_first_genre_slug($id, $submittedGenres);
[$photo, $photoSource] = resolve_photo_url($currentPhoto, $socialsArr, $firstGenreSlug, $instagramAvatar, $id);

$techUrl = trim($in['tech_sheet_url'] ?? '');
$techUrl = preg_match('#^https?://#i', $techUrl) ? $techUrl : '';
$gearBring = gear_whitelist($in['gear_bring'] ?? [], 'bring');
$gearNeed  = gear_whitelist($in['gear_need'] ?? [], 'need');
$gearBringJson = $gearBring ? json_encode($gearBring, JSON_UNESCAPED_UNICODE) : null;
$gearNeedJson  = $gearNeed  ? json_encode($gearNeed,  JSON_UNESCAPED_UNICODE) : null;

$trattabile = (isset($in['cachet_trattabile']) && (string)$in['cachet_trattabile'] === '0') ? 0 : 1;
$bioFromSpotify = (isset($in['bio_from_spotify']) && (string)$in['bio_from_spotify'] === '1') ? 1 : 0;

// Valori "prima" del salvataggio: per capire se bio-Spotify/Instagram vanno risincronizzati.
$wasSt = db()->prepare('SELECT bio_from_spotify, socials FROM artist_profiles WHERE user_id=?');
$wasSt->execute([$id]);
$wasRow = $wasSt->fetch();
$wasBioFromSpotify = (int) ($wasRow['bio_from_spotify'] ?? 0);
$wasSocials = json_decode($wasRow['socials'] ?? '', true) ?: [];

$calUrl = trim($in['calendar_url'] ?? '');
$calUrl = preg_match('#^https?://#i', $calUrl) ? $calUrl : '';

// Geocoding solo se il comune è indicato
$lat = $lng = null;
if ($comune !== '') {
  $geo = geocode_comune($comune, $prov);
  if ($geo) { $lat = $geo['lat']; $lng = $geo['lng']; }
}
$slug = make_slug($stage, $id);

$pdo = db();
$pdo->beginTransaction();
try {
  // Solo il display_name segue il nome d'arte; email/password/stato restano invariati (gestito).
  $pdo->prepare('UPDATE users SET display_name = ? WHERE id = ?')->execute([$stage, $id]);

  // NB: verified/top8/published NON toccati dal booking → mantenuti col valore attuale.
  $pdo->prepare(
    'UPDATE artist_profiles SET
       stage_name=?, slug=?, formazione=?, componenti=?, bio=?, bio_from_spotify=?, phone=?, comune=?, provincia=?,
       lat=?, lng=?, cachet_min=?, cachet_max=?, cachet_trattabile=?, cachet_promo=?, promo_until=?, rimborso_tipo=?, rimborso_forfait=?,
       travel_max_km=?, durata_set_min=?, website=?, socials=?, photo_url=?, calendar_url=?,
       label=?, management=?,
       tech_sheet_url=?, gear_bring=?, gear_need=?
     WHERE user_id=? AND manager_user_id=?'
  )->execute([
    $stage, $slug, $form, $componenti, $bio, $bioFromSpotify, $phone, ($comune ?: null), $prov,
    $lat, $lng, $cachetMin, $cachetMax, $trattabile, $cachetPromo, $promoUntil, $rimb, $rimbForf,
    $travelKm, $durata, $website, $socials, $photo, ($calUrl ?: null),
    ($label ?: null), ($management ?: null),
    ($techUrl ?: null), $gearBringJson, $gearNeedJson,
    $id, (int) $me['id'],
  ]);

  // Generi: artista gestito = verificato → fino a 3.
  $pdo->prepare('DELETE FROM artist_genres WHERE artist_user_id = ?')->execute([$id]);
  if (isset($in['genres']) && is_array($in['genres'])) {
    $ins = $pdo->prepare('INSERT IGNORE INTO artist_genres (artist_user_id, genre_id) VALUES (?, ?)');
    foreach (array_slice($in['genres'], 0, 3) as $gid) {
      $gid = (int)$gid;
      if ($gid > 0) $ins->execute([$id, $gid]);
    }
  }
  $pdo->commit();
} catch (Throwable $e) {
  $pdo->rollBack();
  fail('update_failed', 500);
}

// Rilascia il lock di sessione prima delle chiamate lente.
if (session_status() === PHP_SESSION_ACTIVE) session_write_close();

// Calendario: ricalcola le date occupate (best-effort, fuori transazione).
try { refresh_artist_calendar($id, $calUrl ?: null); } catch (Throwable $e) { /* ignora */ }

// Sync immediata se "bio da Spotify" appena attivata o Instagram appena cambiato.
$apifyTok = stats_cred('apify_token');
if ($apifyTok !== '') {
  if ($bioFromSpotify && !$wasBioFromSpotify && !empty($socialsArr['spotify'])) {
    $freshBio = apify_spotify_biography($socialsArr['spotify'], $apifyTok);
    if ($freshBio !== null) db()->prepare('UPDATE artist_profiles SET bio=? WHERE user_id=?')->execute([$freshBio, $id]);
  }
  if (!empty($socialsArr['instagram']) && trim($socialsArr['instagram']) !== trim($wasSocials['instagram'] ?? '')) {
    refresh_instagram_now($id, $socialsArr['instagram'], $apifyTok);
  }
}

ok(['id' => $id, 'slug' => $slug, 'geocoded' => $lat !== null, 'photo_url' => $photo, 'photo_from' => $photoSource]);
