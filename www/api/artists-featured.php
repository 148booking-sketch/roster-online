<?php
/**
 * GET /api/artists-featured.php — artisti in evidenza: i TOP8 fissi (anche se non verificati)
 * che soddisfano anche i filtri di ricerca correnti (stessi parametri di artists-search.php),
 * così il box resta coerente con i filtri applicati altrove nella pagina.
 */
require_once __DIR__ . '/_http.php';
require_once __DIR__ . '/_gear.php';

$q       = trim($_GET['q'] ?? '');
$genres  = (array)($_GET['genre'] ?? []);
$cachMax = ($_GET['cachet_max'] ?? '') !== '' ? (int)$_GET['cachet_max'] : null;
$cachMin = ($_GET['cachet_min'] ?? '') !== '' ? (int)$_GET['cachet_min'] : null;
$form    = in_array($_GET['formazione'] ?? '', show_types(), true) ? $_GET['formazione'] : null;

$where  = ['ap.published = 1', 'ap.top8 = 1'];
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

$whereSql = 'WHERE ' . implode(' AND ', $where);
$sql = "SELECT ap.user_id, ap.stage_name, ap.slug, ap.formazione, ap.comune, ap.provincia, ap.photo_url, ap.verified
        FROM artist_profiles ap
        $whereSql
        ORDER BY RAND() LIMIT 8";

$st = db()->prepare($sql);
$st->execute($params);
$rows = $st->fetchAll();

$gs = db()->prepare('SELECT g.name FROM artist_genres ag JOIN genres g ON g.id = ag.genre_id WHERE ag.artist_user_id = ? LIMIT 1');
foreach ($rows as &$r) { $gs->execute([$r['user_id']]); $r['genre'] = $gs->fetchColumn() ?: null; }
unset($r);

ok(['artists' => $rows]);
