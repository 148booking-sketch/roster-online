<?php
/**
 * GET /api/artist-get.php?slug=<slug>   — profilo pubblico completo di un artista.
 * Auto-aggiorna le statistiche: sincrono la prima volta (stats mancanti),
 * in background (dopo la risposta) quando sono più vecchie di 7 giorni.
 */
require_once __DIR__ . '/_http.php';
require_once __DIR__ . '/_stats.php';
require_once __DIR__ . '/_calendar.php';
require_once __DIR__ . '/_access.php';

$slug = trim($_GET['slug'] ?? '');
if ($slug === '') fail('slug_required');

$st = db()->prepare(
  'SELECT user_id, stage_name, slug, formazione, componenti, bio, comune, provincia,
          lat, lng, cachet_min, cachet_max, cachet_trattabile, cachet_promo, promo_until, rimborso_tipo, rimborso_km, rimborso_forfait,
          travel_max_km, durata_set_min, website, socials, custom_links, photo_url, verified,
          label, management,
          tech_sheet_url, gear_bring, gear_need,
          stats, stats_updated_at, calendar_url, calendar_busy, calendar_updated_at
   FROM artist_profiles WHERE slug = ? AND published = 1'
);
$st->execute([$slug]);
$a = $st->fetch();
if (!$a) fail('not_found', 404);

$uid     = (int) $a['user_id'];
$socials = json_decode($a['socials'] ?? '', true) ?: [];

// generi
$g = db()->prepare('SELECT gr.slug, gr.name FROM artist_genres ag JOIN genres gr ON gr.id = ag.genre_id WHERE ag.artist_user_id = ?');
$g->execute([$uid]);
$a['genres'] = $g->fetchAll();

// serve un refresh? Con stat presenti → settimanale; senza stat → ritenta ogni giorno.
$updated  = $a['stats_updated_at'] ? strtotime($a['stats_updated_at']) : 0;
$hasStats = !empty($a['stats']) && $a['stats'] !== '[]';
$ttl      = $hasStats ? 7 * 86400 : 86400;
$stale    = ($socials || trim($a['stage_name'] ?? '') !== '') && ($updated === 0 || $updated < time() - $ttl);
$inline   = $stale && !$hasStats;   // se non ci sono ancora stat, calcola subito

$stats = $a['stats'] ? (json_decode($a['stats'], true) ?: []) : [];
if ($inline) { $stats = refresh_artist_stats($uid, $socials); }

// Calendario: refresh se URL presente e cache mancante/vecchia (>1 giorno)
$calUrl = $a['calendar_url'] ?? null;
$busy = $a['calendar_busy'] ? (json_decode($a['calendar_busy'], true) ?: []) : [];
$bgCalendar = false;
if (!empty($calUrl)) {
  $cUpd = $a['calendar_updated_at'] ? strtotime($a['calendar_updated_at']) : 0;
  $calStale = $cUpd === 0 || $cUpd < time() - 86400;
  if ($calStale && empty($busy)) { try { $busy = refresh_artist_calendar($uid, $calUrl); } catch (Throwable $e) {} }
  elseif ($calStale) { $bgCalendar = true; }
}

$a['stats']        = $stats;
$a['socials']      = $socials;
$a['custom_links'] = $a['custom_links'] ? (json_decode($a['custom_links'], true) ?: []) : [];
$a['gear_bring']   = $a['gear_bring'] ? (json_decode($a['gear_bring'], true) ?: []) : [];
$a['gear_need']    = $a['gear_need']  ? (json_decode($a['gear_need'], true)  ?: []) : [];
$a['calendar_busy']= array_values($busy);
$a['has_calendar'] = !empty($calUrl);
unset($a['calendar_url']);   // non esporre l'URL iCal (può essere un indirizzo segreto)

// Promo attiva? (flag pubblico, mostra il pin PROMO anche ai non loggati)
$a['has_promo'] = ($a['cachet_promo'] !== null && (int)$a['cachet_promo'] > 0
                   && ($a['promo_until'] === null || $a['promo_until'] >= date('Y-m-d')));

// LIVELLO DI ACCESSO:
//  - cachet/scheda tecnica: admin, l'artista stesso, o un promoter VERIFICATO dall'admin.
//  - richiesta di booking: qualunque promoter loggato (verificato o no) può comunque scrivere.
$viewer = current_user();
$a['locked']       = !viewer_can_see_prices($viewer, $uid);
$a['can_contact']  = viewer_can_contact($viewer) || ($viewer && (int) $viewer['id'] === $uid);
$a['pending_verification'] = (bool) ($viewer && in_array($viewer['role'], ['promoter', 'management'], true) && !promoter_is_verified((int) $viewer['id']));
if ($a['locked']) {
  foreach (['cachet_min','cachet_max','cachet_trattabile','cachet_promo','promo_until','rimborso_tipo','rimborso_km','rimborso_forfait','tech_sheet_url'] as $k) {
    $a[$k] = null;
  }
}

$out = ['ok' => true, 'artist' => $a];

header('Content-Type: application/json; charset=utf-8');
echo json_encode($out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

// Refresh in background (una sola chiusura risposta) per stat/calendario vecchi ma presenti.
$bgStats = $stale && !$inline;
if ($bgStats || $bgCalendar) {
  // Rilascia il lock di sessione PRIMA della chiamata lenta ad Apify: su questo host
  // fastcgi_finish_request() non stacca davvero il lavoro in background, quindi senza
  // questa riga il visitatore (o le sue altre tab) resterebbe bloccato fino al termine.
  if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
  run_after_response(function () use ($uid, $socials, $calUrl, $bgStats, $bgCalendar) {
    if ($bgStats)    { try { refresh_artist_stats($uid, $socials); } catch (Throwable $e) {} }
    if ($bgCalendar) { try { refresh_artist_calendar($uid, $calUrl); } catch (Throwable $e) {} }
  });
}
