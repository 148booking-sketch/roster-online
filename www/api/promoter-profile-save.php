<?php
/**
 * POST /api/promoter-profile-save.php   (promoter o booking/management loggato)
 * Salva/aggiorna i dati del profilo (non email/password/verifica). I booking/management
 * riusano promoter_profiles, quindi lo stesso endpoint aggiorna il loro profilo agenzia.
 * Body: { org_name, tipo, comune, provincia, phone, website }
 */
require_once __DIR__ . '/_http.php';
require_once __DIR__ . '/_geo.php';
only('POST');

$u  = require_user();
if (!in_array($u['role'], ['promoter', 'management'], true)) fail('forbidden_role', 403);
$in = body();

$org    = trim($in['org_name'] ?? '');
$tipo   = in_array($in['tipo'] ?? '', ['locale','festival','associazione','agenzia','privato','altro'], true) ? $in['tipo'] : 'locale';
$phone  = trim($in['phone'] ?? '');
$comune = trim($in['comune'] ?? '');
$prov   = strtoupper(trim($in['provincia'] ?? '')) ?: null;
$website = normalize_url($in['website'] ?? '');

if ($org === '') fail('org_name_required');

$lat = $lng = null;
if ($comune !== '') {
  $geo = geocode_comune($comune, $prov);
  if ($geo) { $lat = $geo['lat']; $lng = $geo['lng']; }
}

$pdo = db();
$pdo->beginTransaction();
try {
  $pdo->prepare('UPDATE users SET display_name = ? WHERE id = ?')->execute([$org, $u['id']]);
  $pdo->prepare(
    'UPDATE promoter_profiles SET org_name=?, tipo=?, phone=?, comune=?, provincia=?, lat=?, lng=?, website=?
     WHERE user_id=?'
  )->execute([$org, $tipo, $phone, ($comune ?: null), $prov, $lat, $lng, $website, $u['id']]);
  $pdo->commit();
} catch (Throwable $e) {
  $pdo->rollBack();
  fail('save_failed', 500);
}

ok(['saved' => true, 'geocoded' => $lat !== null]);
