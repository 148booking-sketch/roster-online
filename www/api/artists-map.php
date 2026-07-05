<?php
/**
 * GET /api/artists-map.php — artisti pubblicati con coordinate, per la mappa.
 * Accetta gli stessi filtri opzionali di artists-search.php (q, genre[], cachet_max/min,
 * formazione, trattabile, no_price, promo, lat/lng/max_km), così i pin restano coerenti
 * con i risultati della ricerca.
 */
require_once __DIR__ . '/_http.php';
require_once __DIR__ . '/_gear.php';
require_once __DIR__ . '/_access.php';

$q       = trim($_GET['q'] ?? '');
$genres  = (array)($_GET['genre'] ?? []);
$cachMax = ($_GET['cachet_max'] ?? '') !== '' ? (int)$_GET['cachet_max'] : null;
$cachMin = ($_GET['cachet_min'] ?? '') !== '' ? (int)$_GET['cachet_min'] : null;
$form    = in_array($_GET['formazione'] ?? '', show_types(), true) ? $_GET['formazione'] : null;
$maxKm   = ($_GET['max_km'] ?? '') !== '' ? max(1, (int)$_GET['max_km']) : null;

$lat = ($_GET['lat'] ?? '') !== '' ? (float)$_GET['lat'] : null;
$lng = ($_GET['lng'] ?? '') !== '' ? (float)$_GET['lng'] : null;
$hasOrigin = ($lat !== null && $lng !== null);

$where  = ['ap.published = 1', 'ap.lat IS NOT NULL', 'ap.lng IS NOT NULL'];
$params = [];

if ($q !== '') { $where[] = '(ap.stage_name LIKE ? OR ap.bio LIKE ?)'; $params[] = "%$q%"; $params[] = "%$q%"; }
if ($cachMax !== null) {
  $where[] = '(ap.cachet_min IS NULL OR ap.cachet_min <= ? OR (ap.cachet_promo IS NOT NULL AND ap.cachet_promo <= ? AND (ap.promo_until IS NULL OR ap.promo_until >= CURDATE())))';
  $params[] = $cachMax; $params[] = $cachMax;
}
if ($cachMin !== null) { $where[] = '(ap.cachet_max IS NULL OR ap.cachet_max >= ?)'; $params[] = $cachMin; }
if ($form)             { $where[] = 'ap.formazione = ?'; $params[] = $form; }
if (($_GET['trattabile'] ?? '') === '1') { $where[] = 'ap.cachet_trattabile = 1'; }
if (($_GET['no_price'] ?? '') === '1')   { $where[] = 'ap.cachet_min IS NULL AND ap.cachet_max IS NULL'; }
if (($_GET['promo'] ?? '') === '1')      { $where[] = "ap.cachet_promo IS NOT NULL AND ap.cachet_promo > 0 AND (ap.promo_until IS NULL OR ap.promo_until >= CURDATE())"; }

if ($genres) {
  $slugs = array_filter(array_map('strval', $genres));
  if ($slugs) {
    $ph = implode(',', array_fill(0, count($slugs), '?'));
    $where[] = "ap.user_id IN (
        SELECT ag.artist_user_id FROM artist_genres ag
        JOIN genres g ON g.id = ag.genre_id
        WHERE g.slug IN ($ph) OR g.id IN ($ph)
      )";
    foreach ($slugs as $s) $params[] = $s;   // per g.slug
    foreach ($slugs as $s) $params[] = $s;   // per g.id
  }
}

$distSelect = '';
if ($hasOrigin) {
  $distSelect = ', (6371 * 2 * ASIN(SQRT(
      POWER(SIN(RADIANS(ap.lat - ?) / 2), 2) +
      COS(RADIANS(?)) * COS(RADIANS(ap.lat)) *
      POWER(SIN(RADIANS(ap.lng - ?) / 2), 2)
    ))) AS distance_km';
}

$whereSql  = 'WHERE ' . implode(' AND ', $where);
$havingSql = ($hasOrigin && $maxKm !== null) ? 'HAVING distance_km IS NOT NULL AND distance_km <= ' . (int)$maxKm : '';

$sql = "SELECT ap.user_id, ap.stage_name, ap.slug, ap.formazione, ap.comune, ap.provincia, ap.lat, ap.lng,
               ap.cachet_min, ap.cachet_max, ap.trattativa_riservata, ap.photo_url, ap.verified
               $distSelect
        FROM artist_profiles ap
        $whereSql
        $havingSql
        ORDER BY ap.top8 DESC, ap.verified DESC, ap.stage_name ASC";

$bind = [];
if ($hasOrigin) { $bind[] = $lat; $bind[] = $lat; $bind[] = $lng; }
$bind = array_merge($bind, $params);

$st = db()->prepare($sql);
$st->execute($bind);
$rows = $st->fetchAll();

// coordinate esatte del comune: gli artisti nella stessa città vengono raggruppati
// lato client (renderArtistPins) in un solo pin con badge "+n".
// LIVELLO DI ACCESSO: i prezzi si vedono solo ad admin o promoter verificati dall'admin.
$viewer = current_user();
$locked = !viewer_can_see_prices($viewer);
$pendingVerification = (bool) ($viewer && in_array($viewer['role'], ['promoter', 'management'], true) && !promoter_is_verified((int) $viewer['id']));
foreach ($rows as &$r) {
  if ((int)($r['trattativa_riservata'] ?? 0) === 1) { $r['cachet_min'] = $r['cachet_max'] = null; }
  $r['lat'] = (float)$r['lat']; $r['lng'] = (float)$r['lng'];
  if (isset($r['distance_km']) && $r['distance_km'] !== null) $r['distance_km'] = round((float)$r['distance_km'], 1);
  if ($locked) { $r['cachet_min'] = null; $r['cachet_max'] = null; }
}
unset($r);

ok(['artists' => $rows, 'locked' => $locked, 'pending_verification' => $pendingVerification]);
