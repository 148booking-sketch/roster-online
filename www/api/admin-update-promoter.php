<?php
/**
 * POST /api/admin-update-promoter.php   (solo admin)
 * Aggiorna TUTTI i dati di un promoter (utente + profilo). NON la password.
 * Body: { id, email, status, org_name, tipo, phone, comune, provincia, website, role? }
 * `role` (opzionale, 'promoter'|'management') converte l'account tra i due ruoli: la tabella
 * promoter_profiles ha la stessa forma per entrambi, quindi non serve migrare dati — passando
 * a 'management' l'utente ottiene subito accesso a management.html (gestione roster artisti).
 */
require_once __DIR__ . '/_admin.php';
require_once __DIR__ . '/_geo.php';
require_once __DIR__ . '/_stats.php';
only('POST');
require_admin();
ensure_promoter_ig_cols();

$in = body();
$id = (int)($in['id'] ?? 0);
if ($id <= 0) fail('id_required');

$st = db()->prepare('SELECT role FROM users WHERE id = ?');
$st->execute([$id]);
$role = $st->fetchColumn();
if (!in_array($role, ['promoter', 'management'], true)) fail('not_a_promoter', 404);

$newRole = in_array($in['role'] ?? '', ['promoter', 'management'], true) ? $in['role'] : $role;

$email = strtolower(trim($in['email'] ?? ''));
$org   = trim($in['org_name'] ?? '');
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) fail('email_invalid');
if ($org === '') fail('org_name_required');

$st = db()->prepare('SELECT id FROM users WHERE email = ? AND id <> ?');
$st->execute([$email, $id]);
if ($st->fetch()) fail('email_taken', 409);

$status    = in_array($in['status'] ?? '', ['active','pending','blocked'], true) ? $in['status'] : 'active';
$tipo      = in_array($in['tipo'] ?? '', ['locale','festival','associazione','agenzia','booking','management','privato','altro'], true) ? $in['tipo'] : 'locale';
$phone     = trim($in['phone'] ?? '');
$comune    = trim($in['comune'] ?? '');
$prov      = strtoupper(trim($in['provincia'] ?? '')) ?: null;
$website   = normalize_url($in['website'] ?? '');
$instagram = trim($in['instagram'] ?? '');

$lat = $lng = null;
if ($comune !== '') {
  $geo = geocode_comune($comune, $prov);
  if ($geo) { $lat = $geo['lat']; $lng = $geo['lng']; }
}

// Foto profilo agenzia da Instagram: rifà lo scrape solo se il link è cambiato (o se manca
// ancora una foto pur avendo un link) — come per gli artisti, non ad ogni salvataggio.
$was = db()->prepare('SELECT instagram, photo_url, instagram_avatar FROM promoter_profiles WHERE user_id = ?');
$was->execute([$id]);
$wasRow = $was->fetch() ?: [];
$igAvatar = $wasRow['instagram_avatar'] ?? null;
$photoUrl = $wasRow['photo_url'] ?? null;
if ($instagram === '') {
  $igAvatar = null; $photoUrl = null;   // link rimosso: niente più foto derivata
} elseif ($instagram !== ($wasRow['instagram'] ?? '') || !$photoUrl) {
  $igAvatar = fetch_promoter_ig_avatar($instagram);
  $photoUrl = $igAvatar ? '/api/ig-avatar.php?u=' . $id . '&role=promoter' : null;
}

$pdo = db();
$pdo->beginTransaction();
try {
  $pdo->prepare('UPDATE users SET email = ?, display_name = ?, status = ?, role = ? WHERE id = ?')
      ->execute([$email, $org, $status, $newRole, $id]);

  $pdo->prepare(
    'UPDATE promoter_profiles SET org_name=?, tipo=?, phone=?, comune=?, provincia=?, lat=?, lng=?, website=?,
       instagram=?, instagram_avatar=?, photo_url=?
     WHERE user_id=?'
  )->execute([$org, $tipo, $phone, ($comune ?: null), $prov, $lat, $lng, $website,
              ($instagram ?: null), $igAvatar, $photoUrl, $id]);

  $pdo->commit();
} catch (Throwable $e) {
  $pdo->rollBack();
  fail('update_failed', 500);
}

ok(['id' => $id, 'geocoded' => $lat !== null, 'role' => $newRole]);
