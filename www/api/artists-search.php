<?php
/**
 * GET /api/artists-search.php  — ricerca artisti (pubblica).
 * Filtri (tutti opzionali):
 *   q          testo su stage_name/bio
 *   genre      slug o id genere (ripetibile: genre[]=pop&genre[]=rock)
 *   cachet_max budget massimo del promoter (€) → mostra artisti con cachet_min <= budget
 *   cachet_min soglia minima
 *   lat,lng    coordinate del locale (o via comune=)
 *   comune     comune del locale (geocodificato se lat/lng assenti)
 *   max_km     raggio massimo dal locale
 *   formazione solista|duo|trio|band|dj|altro
 *   sort       distance|cachet|recent   (default: distance se lat/lng, altrimenti recent)
 *   page,limit paginazione (limit max 50)
 */
require_once __DIR__ . '/_http.php';
require_once __DIR__ . '/_geo.php';
require_once __DIR__ . '/_gear.php';
require_once __DIR__ . '/_access.php';

ensure_trattativa_col();
$q       = trim($_GET['q'] ?? '');
$genres  = (array)($_GET['genre'] ?? []);
$cachMax = ($_GET['cachet_max'] ?? '') !== '' ? (int)$_GET['cachet_max'] : null;
$cachMin = ($_GET['cachet_min'] ?? '') !== '' ? (int)$_GET['cachet_min'] : null;
$form    = in_array($_GET['formazione'] ?? '', show_types(), true) ? $_GET['formazione'] : null;
$rimb    = in_array($_GET['rimborso'] ?? '', ['incluso','forfait','da_concordare'], true) ? $_GET['rimborso'] : null;
$maxKm   = ($_GET['max_km'] ?? '') !== '' ? max(1, (int)$_GET['max_km']) : null;
$sort    = $_GET['sort'] ?? '';
$page    = max(1, (int)($_GET['page'] ?? 1));
$limit   = min(50, max(1, (int)($_GET['limit'] ?? 20)));
$offset  = ($page - 1) * $limit;

// Origine per la distanza
$lat = ($_GET['lat'] ?? '') !== '' ? (float)$_GET['lat'] : null;
$lng = ($_GET['lng'] ?? '') !== '' ? (float)$_GET['lng'] : null;
if (($lat === null || $lng === null) && !empty($_GET['comune'])) {
  $geo = geocode_comune($_GET['comune'], $_GET['provincia'] ?? null);
  if ($geo) { $lat = $geo['lat']; $lng = $geo['lng']; }
}
$hasOrigin = ($lat !== null && $lng !== null);

$where  = ["ap.published = 1"];
$params = [];

if ($q !== '') {
  $where[] = '(ap.stage_name LIKE ? OR ap.bio LIKE ?)';
  $params[] = "%$q%"; $params[] = "%$q%";
}
if ($cachMax !== null) {
  // Match se il cachet base rientra nel budget, OPPURE se c'è una promo attiva sotto budget
  // (un cachet pieno alto non deve escludere un artista che in questo momento costa meno).
  $where[] = '(ap.cachet_min IS NULL OR ap.cachet_min <= ? OR (ap.cachet_promo IS NOT NULL AND ap.cachet_promo <= ? AND (ap.promo_until IS NULL OR ap.promo_until >= CURDATE())))';
  $params[] = $cachMax; $params[] = $cachMax;
}
if ($cachMin !== null) { $where[] = '(ap.cachet_max IS NULL OR ap.cachet_max >= ?)'; $params[] = $cachMin; }
if ($form)            { $where[] = 'ap.formazione = ?'; $params[] = $form; }
if ($rimb)            { $where[] = 'ap.rimborso_tipo = ?'; $params[] = $rimb; }
if (($_GET['trattabile'] ?? '') === '1') { $where[] = 'ap.cachet_trattabile = 1'; }
// "Senza impegno" = cachet ESPLICITAMENTE 0 (suona gratis/a rimborso spese): NULL non è
// equivalente (significa prezzo non impostato, non "gratis"). Esclude sempre la trattativa
// riservata: è una scelta di privacy del prezzo, concettualmente diversa da suonare gratis.
if (($_GET['no_price'] ?? '') === '1') { $where[] = "ap.trattativa_riservata = 0 AND ap.cachet_min = 0 AND ap.cachet_max = 0"; }
if (($_GET['promo'] ?? '') === '1') { $where[] = "ap.cachet_promo IS NOT NULL AND ap.cachet_promo > 0 AND (ap.promo_until IS NULL OR ap.promo_until >= CURDATE())"; }
if (($_GET['trv'] ?? '') === '1') { $where[] = 'ap.trattativa_riservata = 1'; }

// Filtro genere: match se l'artista ha ALMENO uno dei generi richiesti
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

// Distanza calcolata in SQL (Haversine) se abbiamo origine e l'artista ha coordinate
$distSelect = '';
$favSelect  = '';
if ($hasOrigin) {
  $distSelect = ', (6371 * 2 * ASIN(SQRT(
      POWER(SIN(RADIANS(ap.lat - ?) / 2), 2) +
      COS(RADIANS(?)) * COS(RADIANS(ap.lat)) *
      POWER(SIN(RADIANS(ap.lng - ?) / 2), 2)
    ))) AS distance_km';
  // questi 3 param vanno PRIMA di quelli del WHERE nella query finale
}

$whereSql = 'WHERE ' . implode(' AND ', $where);

// Ordinamento — in evidenza (top8) SEMPRE in cima, poi i verificati, poi il criterio scelto
if ($sort === 'az') {
  $orderSql = 'ORDER BY ap.top8 DESC, ap.verified DESC, ap.stage_name ASC';
} elseif ($sort === 'za') {
  $orderSql = 'ORDER BY ap.top8 DESC, ap.verified DESC, ap.stage_name DESC';
} elseif ($sort === 'cachet') {
  $dir = ($_GET['dir'] ?? '') === 'desc' ? 'DESC' : 'ASC';
  $orderSql = "ORDER BY ap.top8 DESC, ap.verified DESC, ap.cachet_min IS NULL, ap.cachet_min $dir";
} elseif ($sort === 'favs') {
  // Più aggiunti ai preferiti; a parità di salvataggi, nome A→Z.
  // Tollerante se la tabella favorites non esiste ancora (regola hot-endpoint).
  $hasFav = false;
  try { $hasFav = (bool) db()->query("SHOW TABLES LIKE 'favorites'")->fetch(); } catch (Throwable $e) {}
  if ($hasFav) {
    $favSelect = ', (SELECT COUNT(*) FROM favorites fv WHERE fv.artist_user_id = ap.user_id) AS fav_count';
    $orderSql  = 'ORDER BY ap.top8 DESC, ap.verified DESC, fav_count DESC, ap.stage_name ASC';
  } else {
    $orderSql = 'ORDER BY ap.top8 DESC, ap.verified DESC, ap.stage_name ASC';
  }
} elseif ($sort === 'recent') {
  $orderSql = 'ORDER BY ap.top8 DESC, ap.verified DESC, ap.updated_at DESC';
} elseif ($sort === 'distance' && $hasOrigin) {
  $orderSql = 'ORDER BY ap.top8 DESC, ap.verified DESC, distance_km IS NULL, distance_km ASC';
} else {
  // default: in evidenza → verificati → alfabetico A-Z, ogni gruppo A-Z
  $orderSql = 'ORDER BY ap.top8 DESC, ap.verified DESC, ap.stage_name ASC';
}

// HAVING per il raggio massimo (usa l'alias distance_km)
// con un raggio attivo mostriamo SOLO gli artisti effettivamente entro quel raggio
// (quelli senza coordinate non possono essere "vicini" → esclusi)
$havingSql = ($hasOrigin && $maxKm !== null) ? 'HAVING distance_km IS NOT NULL AND distance_km <= ' . (int)$maxKm : '';

$sql = "SELECT ap.user_id, ap.stage_name, ap.slug, ap.formazione, ap.comune, ap.provincia,
               ap.cachet_min, ap.cachet_max, ap.cachet_trattabile, ap.trattativa_riservata, ap.cachet_promo, ap.promo_until, ap.rimborso_tipo, ap.travel_max_km,
               ap.photo_url, ap.verified, ap.lat, ap.lng, ap.stats
               $distSelect $favSelect
        FROM artist_profiles ap
        $whereSql
        $havingSql
        $orderSql
        LIMIT $limit OFFSET $offset";

// Ordine parametri: prima quelli del SELECT (distanza), poi quelli del WHERE
$bind = [];
if ($hasOrigin) { $bind[] = $lat; $bind[] = $lat; $bind[] = $lng; }
$bind = array_merge($bind, $params);

$st = db()->prepare($sql);
$st->execute($bind);
$rows = $st->fetchAll();

// Totale complessivo che soddisfa i filtri (indipendente da LIMIT/OFFSET), per mostrare subito il conteggio in home
$totalSql = "SELECT COUNT(*) FROM (SELECT ap.user_id $distSelect FROM artist_profiles ap $whereSql $havingSql) t";
$totalSt = db()->prepare($totalSql);
$totalSt->execute($bind);
$total = (int) $totalSt->fetchColumn();

// LIVELLO DI ACCESSO: i prezzi si vedono solo ad admin o promoter verificati dall'admin.
$viewer = current_user();
$locked = !viewer_can_see_prices($viewer);
$pendingVerification = (bool) ($viewer && in_array($viewer['role'], ['promoter', 'management'], true) && !promoter_is_verified((int) $viewer['id']));

// Preferiti: se il viewer può averne, insieme degli artist_user_id salvati (per il cuore nelle card).
$canFavorite = viewer_can_favorite($viewer);
$favIds      = $canFavorite ? array_flip(favorite_artist_ids($viewer)) : [];

// Cachet massimo di tutto il roster (per il max dello slider prezzo). Solo ai loggati.
$rosterMaxCachet = $locked ? 0 : (int) db()->query(
  "SELECT MAX(GREATEST(COALESCE(cachet_min,0), COALESCE(cachet_max,0)))
   FROM artist_profiles WHERE published = 1"
)->fetchColumn();

// Arrotonda distanza e allega i generi
foreach ($rows as &$r) {
  if (isset($r['distance_km']) && $r['distance_km'] !== null) $r['distance_km'] = round((float)$r['distance_km'], 1);
  $g = db()->prepare('SELECT g.slug, g.name FROM artist_genres ag JOIN genres g ON g.id=ag.genre_id WHERE ag.artist_user_id=?');
  $g->execute([$r['user_id']]);
  $r['genres'] = $g->fetchAll();
  // Trattativa riservata attiva: nessun prezzo in chiaro, per nessuno (le UI mostrano "Trattativa riservata")
  if ((int)($r['trattativa_riservata'] ?? 0) === 1) {
    $r['cachet_min'] = $r['cachet_max'] = $r['cachet_promo'] = $r['promo_until'] = null;
  }
  $r['has_promo'] = ($r['cachet_promo'] !== null && (int)$r['cachet_promo'] > 0
                     && ($r['promo_until'] === null || $r['promo_until'] >= date('Y-m-d')));
  if ($locked) { $r['cachet_min'] = null; $r['cachet_max'] = null; $r['cachet_promo'] = null; $r['promo_until'] = null; }
  $r['stats'] = $r['stats'] ? (json_decode($r['stats'], true) ?: []) : [];
  $r['is_favorite'] = isset($favIds[(int)$r['user_id']]);
}
unset($r);

// Risposta
header('Content-Type: application/json; charset=utf-8');
echo json_encode([
  'ok'      => true,
  'results' => $rows,
  'total'   => $total,
  'page'    => $page,
  'locked'  => $locked,
  'pending_verification' => $pendingVerification,
  'can_favorite' => $canFavorite,
  'roster_max_cachet' => $rosterMaxCachet,
  'origin'  => $hasOrigin ? ['lat' => $lat, 'lng' => $lng] : null,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

// Loop stat "leggero" guidato dal traffico: al massimo un piccolo batch al giorno,
// eseguito DOPO la risposta (non rallenta l'utente). Aggiorna gli artisti con stat
// più vecchie di 7 giorni. Per un aggiornamento garantito su tutto il roster usare
// un cron settimanale verso stats-cron.php.
if (function_exists('fastcgi_finish_request')) {
  fastcgi_finish_request();
  // Rilascia il lock di sessione prima dell'eventuale refresh Apify: su questo host
  // fastcgi_finish_request() non stacca davvero il lavoro in background, quindi senza
  // questa riga il visitatore (o le sue altre tab) resterebbe bloccato fino al termine.
  if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
  try {
    require_once __DIR__ . '/_stats.php';
    $last = meta_get('stats_tick_at');
    if (!$last || strtotime($last) < time() - 86400) {
      meta_set('stats_tick_at', gmdate('Y-m-d H:i:s'));
      refresh_stale_stats(5, 7);
      // Promemoria evento 3 giorni prima: guidato dal traffico come le stat, così parte
      // anche senza cron configurato (deduplicato in booking_reminders).
      require_once __DIR__ . '/_mail.php';
      send_event_reminders();
    }
  } catch (Throwable $e) { /* silenzioso */ }
}

