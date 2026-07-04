<?php
/**
 * POST /api/admin-update-promoter.php   (solo admin)
 * Aggiorna TUTTI i dati di un promoter (utente + profilo). NON la password.
 * Body: { id, email, status, org_name, tipo, phone, comune, provincia, website }
 */
require_once __DIR__ . '/_admin.php';
require_once __DIR__ . '/_geo.php';
only('POST');
require_admin();

$in = body();
$id = (int)($in['id'] ?? 0);
if ($id <= 0) fail('id_required');

$st = db()->prepare('SELECT role FROM users WHERE id = ?');
$st->execute([$id]);
$role = $st->fetchColumn();
if (!in_array($role, ['promoter', 'management'], true)) fail('not_a_promoter', 404);

$email = strtolower(trim($in['email'] ?? ''));
$org   = trim($in['org_name'] ?? '');
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) fail('email_invalid');
if ($org === '') fail('org_name_required');

$st = db()->prepare('SELECT id FROM users WHERE email = ? AND id <> ?');
$st->execute([$email, $id]);
if ($st->fetch()) fail('email_taken', 409);

$status  = in_array($in['status'] ?? '', ['active','pending','blocked'], true) ? $in['status'] : 'active';
$tipo    = in_array($in['tipo'] ?? '', ['locale','festival','associazione','agenzia','privato','altro'], true) ? $in['tipo'] : 'locale';
$phone   = trim($in['phone'] ?? '');
$comune  = trim($in['comune'] ?? '');
$prov    = strtoupper(trim($in['provincia'] ?? '')) ?: null;
$website = normalize_url($in['website'] ?? '');

$lat = $lng = null;
if ($comune !== '') {
  $geo = geocode_comune($comune, $prov);
  if ($geo) { $lat = $geo['lat']; $lng = $geo['lng']; }
}

$pdo = db();
$pdo->beginTransaction();
try {
  $pdo->prepare('UPDATE users SET email = ?, display_name = ?, status = ? WHERE id = ?')
      ->execute([$email, $org, $status, $id]);

  $pdo->prepare(
    'UPDATE promoter_profiles SET org_name=?, tipo=?, phone=?, comune=?, provincia=?, lat=?, lng=?, website=?
     WHERE user_id=?'
  )->execute([$org, $tipo, $phone, ($comune ?: null), $prov, $lat, $lng, $website, $id]);

  $pdo->commit();
} catch (Throwable $e) {
  $pdo->rollBack();
  fail('update_failed', 500);
}

ok(['id' => $id, 'geocoded' => $lat !== null]);
