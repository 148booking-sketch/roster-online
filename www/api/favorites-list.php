<?php
/**
 * GET /api/favorites-list.php  — artisti salvati nei preferiti dal promoter/management corrente.
 * Restituisce le info per il monitoraggio: disponibilità (calendar_busy) e prezzo scontato
 * (cachet_promo/promo_until). I prezzi seguono la stessa regola di accesso della ricerca:
 * visibili solo ad admin o promoter/management verificato dall'admin.
 * Alimenta sia /preferiti.html sia il calendario aggregato /preferiti-calendario.html.
 */
require_once __DIR__ . '/_http.php';
require_once __DIR__ . '/_access.php';

$viewer = current_user();
if (!$viewer) fail('not_authenticated', 401);
if (!viewer_can_favorite($viewer)) fail('forbidden_role', 403);
ensure_favorites_table();

$uid    = (int)$viewer['id'];
$locked = !viewer_can_see_prices($viewer);
$pendingVerification = (bool)(in_array($viewer['role'], ['promoter', 'management'], true) && !promoter_is_verified($uid));

$st = db()->prepare(
  "SELECT ap.user_id, ap.stage_name, ap.slug, ap.formazione, ap.comune, ap.provincia,
          ap.cachet_min, ap.cachet_max, ap.cachet_trattabile, ap.trattativa_riservata, ap.cachet_promo, ap.promo_until,
          ap.rimborso_tipo, ap.photo_url, ap.verified, ap.calendar_busy, ap.calendar_url,
          ap.stats, f.created_at AS favorited_at
   FROM favorites f
   JOIN artist_profiles ap ON ap.user_id = f.artist_user_id
   WHERE f.user_id = ? AND ap.published = 1
   ORDER BY f.created_at DESC"
);
$st->execute([$uid]);
$rows = $st->fetchAll();

$today = date('Y-m-d');
$artists = [];
foreach ($rows as $r) {
  $busy = $r['calendar_busy'] ? (json_decode($r['calendar_busy'], true) ?: []) : [];
  // solo date da oggi in poi, ordinate
  $busy = array_values(array_filter($busy, fn($d) => $d >= $today));
  sort($busy);

  if ((int)($r['trattativa_riservata'] ?? 0) === 1) {
    $r['cachet_min'] = $r['cachet_max'] = $r['cachet_promo'] = $r['promo_until'] = null; $r['cachet_trattabile'] = null;
  }
  $hasPromo = ($r['cachet_promo'] !== null && (int)$r['cachet_promo'] > 0
               && ($r['promo_until'] === null || $r['promo_until'] >= $today));

  $a = [
    'user_id'      => (int)$r['user_id'],
    'stage_name'   => $r['stage_name'],
    'slug'         => $r['slug'],
    'formazione'   => $r['formazione'],
    'comune'       => $r['comune'],
    'provincia'    => $r['provincia'],
    'photo_url'    => $r['photo_url'],
    'verified'     => (bool)$r['verified'],
    'has_calendar' => !empty($r['calendar_url']),
    'busy'         => $busy,
    'has_promo'    => $hasPromo,
    'rimborso_tipo'=> $r['rimborso_tipo'],
    'stats'        => $r['stats'] ? (json_decode($r['stats'], true) ?: []) : [],
    'favorited_at' => $r['favorited_at'],
  ];
  if ($locked) {
    $a['cachet_min'] = $a['cachet_max'] = $a['cachet_promo'] = $a['promo_until'] = null;
    $a['cachet_trattabile'] = null;
    // has_promo resta come flag pubblico (mostra il badge), ma senza importo
  } else {
    $a['cachet_min']        = $r['cachet_min'] !== null ? (int)$r['cachet_min'] : null;
    $a['cachet_max']        = $r['cachet_max'] !== null ? (int)$r['cachet_max'] : null;
    $a['cachet_promo']      = $r['cachet_promo'] !== null ? (int)$r['cachet_promo'] : null;
    $a['cachet_trattabile'] = (int)$r['cachet_trattabile'];
    $a['promo_until']       = $r['promo_until'];
  }
  $artists[] = $a;
}

ok([
  'artists' => $artists,
  'locked'  => $locked,
  'pending_verification' => $pendingVerification,
]);
