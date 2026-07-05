<?php
/**
 * POST /api/admin-update-artist.php   (solo admin)
 * Aggiorna TUTTI i dati di un artista (utente + profilo + generi). NON la password.
 * Body: { id, email, status, ...campi artist_profiles..., genres:[id] }
 */
require_once __DIR__ . '/_admin.php';
require_once __DIR__ . '/_geo.php';
require_once __DIR__ . '/_calendar.php';
require_once __DIR__ . '/_gear.php';
require_once __DIR__ . '/_social.php';
require_once __DIR__ . '/_stats.php';
only('POST');
require_admin();

$in = body();
$id = (int)($in['id'] ?? 0);
if ($id <= 0) fail('id_required');

$st = db()->prepare('SELECT role FROM users WHERE id = ?');
$st->execute([$id]);
$role = $st->fetchColumn();
if ($role !== 'artist') fail('not_an_artist', 404);

$stage = trim($in['stage_name'] ?? '');
if ($stage === '') fail('stage_name_required');

// Profilo "Verificato" gestito direttamente da noi: se l'admin lo spunta ORA (non lo era prima) e
// non ha inserito un'email, gliene generiamo una e resettiamo la password (l'artista originale
// perde l'accesso, l'account passa sotto il nostro controllo). Se era già gestito e il campo
// email è rimasto vuoto (nascosto in form), manteniamo l'email attuale senza rigenerarla ad ogni salvataggio.
$wasVerifiedSt = db()->prepare('SELECT verified FROM artist_profiles WHERE user_id=?');
$wasVerifiedSt->execute([$id]);
$wasVerified = (int) $wasVerifiedSt->fetchColumn();
$verifiedIn  = isset($in['verified']) ? (int)!!$in['verified'] : 0;

$currentEmailSt = db()->prepare('SELECT email FROM users WHERE id=?');
$currentEmailSt->execute([$id]);
$currentEmail = (string) $currentEmailSt->fetchColumn();

$submittedEmail = strtolower(trim($in['email'] ?? ''));
$takingControl  = $verifiedIn && !$wasVerified && $submittedEmail === '';
$newPasswordHash = null;   // null = non tocca la password

if ($takingControl) {
  $email = managed_email($stage);
  $newPasswordHash = password_hash(managed_password(), PASSWORD_DEFAULT);
} elseif ($verifiedIn && $submittedEmail === '') {
  $email = $currentEmail;   // già gestito: campo nascosto, non toccare l'email esistente
} else {
  $email = $submittedEmail;
  if (!filter_var($email, FILTER_VALIDATE_EMAIL)) fail('email_invalid');
}

// Email duplicata su un altro utente?
$st = db()->prepare('SELECT id FROM users WHERE email = ? AND id <> ?');
$st->execute([$email, $id]);
if ($st->fetch()) fail('email_taken', 409);

$status = in_array($in['status'] ?? '', ['active','pending','blocked'], true) ? $in['status'] : 'active';
$form   = in_array($in['formazione'] ?? '', show_types(), true) ? $in['formazione'] : 'live_band';
$rimb   = in_array($in['rimborso_tipo'] ?? '', ['incluso','forfait','da_concordare'], true) ? $in['rimborso_tipo'] : 'da_concordare';
$bio     = trim($in['bio'] ?? '');
$phone   = trim($in['phone'] ?? '');
$comune  = trim($in['comune'] ?? '');
$prov    = strtoupper(trim($in['provincia'] ?? '')) ?: null;
$website = normalize_url($in['website'] ?? '');

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

// Foto profilo: Spotify → Instagram → icona automatica dal 1° genere → foto attuale (stessa priorità di artist-save.php).
$currentPhotoSt = db()->prepare('SELECT photo_url, stats FROM artist_profiles WHERE user_id=?');
$currentPhotoSt->execute([$id]);
$row0 = $currentPhotoSt->fetch();
$currentPhoto = $row0['photo_url'] ?: null;
$instagramAvatar = (json_decode($row0['stats'] ?? '', true) ?: [])['instagram_avatar'] ?? null;
$submittedGenres = isset($in['genres']) && is_array($in['genres']) ? array_map('intval', $in['genres']) : null;
$firstGenreSlug = resolve_first_genre_slug($id, $submittedGenres);
[$photo, $photoSource] = resolve_photo_url($currentPhoto, $socialsArr, $firstGenreSlug, $instagramAvatar, $id);

// Backline & scheda tecnica
require_once __DIR__ . '/_gear.php';
$techUrl = trim($in['tech_sheet_url'] ?? '');
$techUrl = preg_match('#^https?://#i', $techUrl) ? $techUrl : '';
$gearBring = gear_whitelist($in['gear_bring'] ?? [], 'bring');
$gearNeed  = gear_whitelist($in['gear_need'] ?? [], 'need');
$gearBringJson = $gearBring ? json_encode($gearBring, JSON_UNESCAPED_UNICODE) : null;
$gearNeedJson  = $gearNeed  ? json_encode($gearNeed,  JSON_UNESCAPED_UNICODE) : null;

// Trattativa riservata: attivabile solo per artisti verificati.
ensure_trattativa_col();
$trvRis = ($verifiedIn && !empty($in['trattativa_riservata'])) ? 1 : 0;

$trattabile = (isset($in['cachet_trattabile']) && (string)$in['cachet_trattabile'] === '0') ? 0 : 1;
$bioFromSpotify = (isset($in['bio_from_spotify']) && (string)$in['bio_from_spotify'] === '1') ? 1 : 0;

// Valori "prima" del salvataggio: servono dopo, in background, per capire se bio-Spotify/
// Instagram vanno risincronizzati subito (sync fatta DOPO la risposta: non deve rallentarla).
$wasSt = db()->prepare('SELECT bio_from_spotify, socials FROM artist_profiles WHERE user_id=?');
$wasSt->execute([$id]);
$wasRow = $wasSt->fetch();
$wasBioFromSpotify = (int) ($wasRow['bio_from_spotify'] ?? 0);
$wasSocials = json_decode($wasRow['socials'] ?? '', true) ?: [];

$calUrl = trim($in['calendar_url'] ?? '');
$calUrl = preg_match('#^https?://#i', $calUrl) ? $calUrl : '';

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

// "Pubblicato" non è più un campo del form (si gestisce col pulsante approva/nascondi
// nell'elenco, via admin-publish.php): se non arriva, mantieni il valore attuale invece
// di resettarlo, altrimenti ogni salvataggio ripubblicherebbe un artista nascosto.
$publishedSt = db()->prepare('SELECT published FROM artist_profiles WHERE user_id=?');
$publishedSt->execute([$id]);
$currentPublished = (int) $publishedSt->fetchColumn();
$published = isset($in['published']) ? (int)!!$in['published'] : $currentPublished;
$verified  = $verifiedIn;
$top8      = isset($in['top8'])      ? (int)!!$in['top8']      : 0;

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
  if ($newPasswordHash !== null) {
    $pdo->prepare('UPDATE users SET email = ?, display_name = ?, status = ?, password_hash = ? WHERE id = ?')
        ->execute([$email, $stage, $status, $newPasswordHash, $id]);
  } else {
    $pdo->prepare('UPDATE users SET email = ?, display_name = ?, status = ? WHERE id = ?')
        ->execute([$email, $stage, $status, $id]);
  }

  $pdo->prepare(
    'UPDATE artist_profiles SET
       manager_user_id=?, stage_name=?, slug=?, formazione=?, componenti=?, bio=?, bio_from_spotify=?, phone=?, comune=?, provincia=?,
       lat=?, lng=?, cachet_min=?, cachet_max=?, cachet_trattabile=?, trattativa_riservata=?, cachet_promo=?, promo_until=?, rimborso_tipo=?, rimborso_forfait=?,
       travel_max_km=?, durata_set_min=?, website=?, socials=?, photo_url=?, calendar_url=?,
       label=?, management=?,
       tech_sheet_url=?, gear_bring=?, gear_need=?, verified=?, top8=?, published=?
     WHERE user_id=?'
  )->execute([
    $managerVal, $stage, $slug, $form, $componenti, $bio, $bioFromSpotify, $phone, ($comune ?: null), $prov,
    $lat, $lng, $cachetMin, $cachetMax, $trattabile, $trvRis, $cachetPromo, $promoUntil, $rimb, $rimbForf,
    $travelKm, $durata, $website, $socials, $photo, ($calUrl ?: null),
    ($label ?: null), ($management ?: null),
    ($techUrl ?: null), $gearBringJson, $gearNeedJson, $verified, $top8, $published,
    $id,
  ]);

  $pdo->prepare('DELETE FROM artist_genres WHERE artist_user_id = ?')->execute([$id]);
  if (isset($in['genres']) && is_array($in['genres'])) {
    $ins = $pdo->prepare('INSERT IGNORE INTO artist_genres (artist_user_id, genre_id) VALUES (?, ?)');
    foreach (array_slice($in['genres'], 0, $verified ? PHP_INT_MAX : 3) as $gid) {
      $gid = (int)$gid;
      if ($gid > 0) $ins->execute([$id, $gid]);
    }
  }
  $pdo->commit();
} catch (Throwable $e) {
  $pdo->rollBack();
  error_log('admin-update-artist.php: ' . $e->getMessage());
  fail('update_failed', 500);
}

// Rilascia il lock di sessione PRIMA delle chiamate lente (Apify/calendario): altrimenti, finché
// questa richiesta è in corso, ogni altra richiesta con lo stesso cookie di sessione (altre tab,
// la nav della pagina stessa) resta bloccata in attesa dello stesso file di sessione.
if (session_status() === PHP_SESSION_ACTIVE) session_write_close();

// Calendario: ricalcola le date occupate (best-effort, fuori transazione).
try { refresh_artist_calendar($id, $calUrl ?: null); } catch (Throwable $e) { /* ignora */ }

// Se il flag "bio da Spotify" viene appena attivato, o il link Instagram è appena cambiato,
// sincronizza subito (invece di aspettare il refresh settimanale/manuale).
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
