<?php
/**
 * POST /api/register.php
 * Body: { email, password, role: "artist"|"promoter", display_name }
 * Crea l'utente (email NON verificata) + profilo, invia l'email di verifica.
 * NON effettua il login: l'account si attiva cliccando il link ricevuto via email.
 * Gli artisti nascono con profilo published=0 (non visibile finché un admin approva).
 */
require_once __DIR__ . '/_http.php';
require_once __DIR__ . '/_mail.php';
require_once __DIR__ . '/_geo.php';
require_once __DIR__ . '/_itunes.php';
require_once __DIR__ . '/_calendar.php';
require_once __DIR__ . '/_ratelimit.php';
only('POST');
rate_limit('register', 8, 600);

$in    = body();
$email = strtolower(trim($in['email'] ?? ''));
$pass  = (string)($in['password'] ?? '');
$role  = $in['role'] ?? '';
$name  = trim($in['display_name'] ?? '');

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) fail('email_invalid');
if (strlen($pass) < 8) fail('password_too_short');
if (!in_array($role, ['artist','promoter','management'], true)) fail('role_invalid');

$tipo = $phone = $comune = $prov = $website = null;
$emailFreq = 'off'; $emailConsent = false;
if (in_array($role, ['promoter','management'], true)) {
  $tipo    = in_array($in['tipo'] ?? '', ['locale','festival','associazione','agenzia','booking','management','privato','altro'], true) ? $in['tipo'] : null;
  $phone   = trim($in['phone'] ?? '');
  $comune  = trim($in['comune'] ?? '');
  $prov    = strtoupper(trim($in['provincia'] ?? '')) ?: null;
  $website = trim($in['website'] ?? '');
  if (!$tipo) fail('tipo_required');
  if ($comune === '') fail('comune_required');
  if ($phone === '') fail('phone_required');
  if ($website === '') fail('website_required');
  $website = normalize_url($website);
  // Consenso esplicito agli alert email (nuovi artisti/promo): opt-in, default settimanale se accettato.
  $emailConsent = !empty($in['email_alert_consent']);
  $emailFreq = $emailConsent ? 'weekly' : 'off';
} else {
  // Artista: nome d'arte, telefono e comune raccolti nel wizard; Apple Music e Google Calendar
  // già verificati negli step 1-2, ma li RI-verifichiamo qui lato server (non ci si fida del
  // client) per impedire di aggirare i requisiti. Il display_name resta il nome anagrafico
  // (Nome Cognome, per SIAE/contratti); il nome d'arte va in artist_profiles.stage_name.
  $stage      = trim($in['stage_name'] ?? '');
  $phone      = trim($in['phone'] ?? '');
  $comune     = trim($in['comune'] ?? '');
  $prov       = strtoupper(trim($in['provincia'] ?? '')) ?: null;
  $calUrl     = trim($in['calendar_url'] ?? '');
  $appleMusic = trim($in['applemusic'] ?? '');

  if ($stage === '')      fail('stage_name_required');
  if ($phone === '')      fail('phone_required');
  if ($comune === '')     fail('comune_required');
  if ($appleMusic === '') fail('applemusic_required');

  // Nome d'arte univoco nel roster (case-insensitive).
  $dupSt = db()->prepare('SELECT 1 FROM artist_profiles WHERE LOWER(stage_name) = LOWER(?) LIMIT 1');
  $dupSt->execute([$stage]);
  if ($dupSt->fetchColumn()) fail('stage_name_taken', 409);

  // Idoneità Apple Music/iTunes (≥2 brani/12 mesi e ≥6 totali) — ri-verificata server-side.
  $elig = itunes_eligibility($appleMusic);
  if (!$elig['eligible']) fail('not_eligible', 422, ['track_count' => $elig['track_count'] ?? 0]);

  // Google Calendar: iCal valido e raggiungibile.
  if ($calUrl === '' || !calendar_is_valid($calUrl)) fail('calendar_invalid', 422);
}

$pdo = db();
$st = $pdo->prepare('SELECT id FROM users WHERE email = ?');
$st->execute([$email]);
if ($st->fetch()) fail('email_taken', 409);

$token = bin2hex(random_bytes(32));

$pdo->beginTransaction();
try {
  // email_verified = 0 (default): l'account resta bloccato finché non si clicca il link.
  // I promoter e i booking/management partono in stato "pending": vedono i prezzi (e, per i
  // booking, possono aggiungere artisti) solo dopo l'approvazione manuale dell'admin (→ "active").
  $status = in_array($role, ['promoter','management'], true) ? 'pending' : 'active';
  $pdo->prepare(
    'INSERT INTO users (email, password_hash, role, display_name, verify_token, email_verified, status)
     VALUES (?, ?, ?, ?, ?, 0, ?)'
  )->execute([$email, password_hash($pass, PASSWORD_DEFAULT), $role, $name, $token, $status]);
  $uid = (int)$pdo->lastInsertId();

  if ($role === 'artist') {
    // published = 0: non visibile nella ricerca finché un admin non approva.
    // Il nome d'arte è scelto nel wizard (stage_name); $name resta il nome anagrafico.
    $socials = $appleMusic !== '' ? json_encode(['applemusic' => $appleMusic], JSON_UNESCAPED_UNICODE) : null;
    $slug = trim(preg_replace('/[^a-z0-9]+/', '-', mb_strtolower($stage)), '-');
    $slug = ($slug !== '' ? $slug : 'artista') . '-' . $uid;
    $lat = $lng = null;
    if ($comune !== '') {
      $geo = geocode_comune($comune, $prov);
      if ($geo) { $lat = $geo['lat']; $lng = $geo['lng']; }
    }
    $pdo->prepare(
      'INSERT INTO artist_profiles (user_id, stage_name, slug, phone, comune, provincia, lat, lng, calendar_url, socials, published)
       VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0)'
    )->execute([$uid, $stage, $slug, ($phone ?: null), ($comune ?: null), $prov, $lat, $lng, ($calUrl ?: null), $socials]);
  } else {
    $lat = $lng = null;
    $geo = geocode_comune($comune, $prov);
    if ($geo) { $lat = $geo['lat']; $lng = $geo['lng']; }
    $unsubToken = bin2hex(random_bytes(32));
    $consentAt = $emailConsent ? gmdate('Y-m-d H:i:s') : null;
    $pdo->prepare(
      'INSERT INTO promoter_profiles
         (user_id, org_name, tipo, phone, comune, provincia, lat, lng, website, email_freq, email_consent_at, email_unsub_token)
       VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    )->execute([$uid, $name, $tipo, $phone, $comune, $prov, $lat, $lng, $website, $emailFreq, $consentAt, $unsubToken]);
  }
  $pdo->commit();
} catch (Throwable $e) {
  $pdo->rollBack();
  fail('register_failed', 500);
}

// Calendario artista: precalcola le date occupate dal link appena validato (best-effort).
if ($role === 'artist' && $calUrl !== '') {
  if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
  try { refresh_artist_calendar($uid, $calUrl); } catch (Throwable $e) { /* ignora */ }
}

@send_verification_email($email, $name, $token);

// Niente login: l'utente deve verificare l'email.
ok(['needs_verification' => true, 'email' => $email]);
