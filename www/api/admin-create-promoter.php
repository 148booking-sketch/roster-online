<?php
/**
 * POST /api/admin-create-promoter.php   (solo admin)
 * Crea a mano un utente promoter (o booking/management, vedi `role`) + profilo.
 * Geocodifica il comune.
 * Body: { email, password, org_name, tipo, phone, comune, provincia, website, role? }
 */
require_once __DIR__ . '/_admin.php';
require_once __DIR__ . '/_geo.php';
require_once __DIR__ . '/_stats.php';
only('POST');
require_admin();
ensure_promoter_ig_cols();

$in    = body();
$email = strtolower(trim($in['email'] ?? ''));
$pass  = (string)($in['password'] ?? '');
$org   = trim($in['org_name'] ?? '');
$role  = in_array($in['role'] ?? '', ['promoter', 'management'], true) ? $in['role'] : 'promoter';

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) fail('email_invalid');
if (strlen($pass) < 8) fail('password_too_short');
if ($org === '') fail('org_name_required');

$tipo      = in_array($in['tipo'] ?? '', ['locale','festival','associazione','agenzia','privato','altro'], true) ? $in['tipo'] : 'locale';
$phone     = trim($in['phone'] ?? '');
$comune    = trim($in['comune'] ?? '');
$prov      = strtoupper(trim($in['provincia'] ?? '')) ?: null;
$website   = normalize_url($in['website'] ?? '');
$instagram = trim($in['instagram'] ?? '');
$status    = in_array($in['status'] ?? '', ['active','pending','blocked'], true) ? $in['status'] : 'pending';

$pdo = db();
$st = $pdo->prepare('SELECT id FROM users WHERE email = ?');
$st->execute([$email]);
if ($st->fetch()) fail('email_taken', 409);

$lat = $lng = null;
if ($comune !== '') {
  $geo = geocode_comune($comune, $prov);
  if ($geo) { $lat = $geo['lat']; $lng = $geo['lng']; }
}

// Foto profilo agenzia derivata da Instagram (stesso meccanismo Apify degli artisti): se non
// disponibile (nessun token configurato o profilo non trovato) resta senza foto, va bene.
$igAvatar = $instagram !== '' ? fetch_promoter_ig_avatar($instagram) : null;
$photoUrl = null; // impostata sotto una volta noto $uid, serve per l'URL del relay

$pdo->beginTransaction();
try {
  $pdo->prepare(
    'INSERT INTO users (email, password_hash, role, display_name, status, email_verified)
     VALUES (?, ?, ?, ?, ?, 1)'
  )->execute([$email, password_hash($pass, PASSWORD_DEFAULT), $role, $org, $status]);
  $uid = (int)$pdo->lastInsertId();
  if ($igAvatar) $photoUrl = '/api/ig-avatar.php?u=' . $uid . '&role=promoter';

  $pdo->prepare(
    'INSERT INTO promoter_profiles
       (user_id, org_name, tipo, phone, comune, provincia, lat, lng, website, instagram, instagram_avatar, photo_url)
     VALUES (?,?,?,?,?,?,?,?,?,?,?,?)'
  )->execute([$uid, $org, $tipo, $phone, ($comune ?: null), $prov, $lat, $lng, $website, ($instagram ?: null), $igAvatar, $photoUrl]);

  $pdo->commit();
} catch (Throwable $e) {
  $pdo->rollBack();
  fail('create_failed', 500);
}

ok(['id' => $uid, 'geocoded' => $lat !== null, 'role' => $role]);
