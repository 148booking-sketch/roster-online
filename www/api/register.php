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
only('POST');

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
  $tipo    = in_array($in['tipo'] ?? '', ['locale','festival','associazione','agenzia','privato','altro'], true) ? $in['tipo'] : null;
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
  // Artista: telefono raccolto nello step 2 del wizard. Il link Apple Music verificato nello
  // step 1 viene salvato tra i social del profilo, per non perdere il dato.
  $phone      = trim($in['phone'] ?? '');
  $appleMusic = trim($in['applemusic'] ?? '');
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
    // stage_name vuoto: $name qui è il nome anagrafico (Nome Cognome), non il nome d'arte —
    // lo sceglierà l'artista stesso completando il profilo.
    $socials = $appleMusic !== '' ? json_encode(['applemusic' => $appleMusic], JSON_UNESCAPED_UNICODE) : null;
    $pdo->prepare('INSERT INTO artist_profiles (user_id, stage_name, phone, socials, published) VALUES (?, ?, ?, ?, 0)')
        ->execute([$uid, '', ($phone ?: null), $socials]);
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

@send_verification_email($email, $name, $token);

// Niente login: l'utente deve verificare l'email.
ok(['needs_verification' => true, 'email' => $email]);
