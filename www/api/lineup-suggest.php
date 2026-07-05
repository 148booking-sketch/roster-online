<?php
/**
 * POST /api/lineup-suggest.php   (solo promoter verificati/admin: serve vedere i cachet)
 * Body: { budget, mode: "uno"|"piu", genres?: [slug,...] }
 *
 * Consiglia artisti (pubblicati, con cachet noto — esclusi quelli in trattativa riservata,
 * il cui prezzo non è mai in chiaro) il cui compenso, singolo o sommato, rientra nel budget
 * indicato usandone almeno il 10% (evita di suggerire cachet trascurabili) e fino al 10% IN PIÙ
 * del budget (es. budget 10.000 → fino a 11.000), per non scartare combinazioni quasi perfette.
 *
 * mode "uno": lista di artisti singoli, ordine casuale tra i compatibili (variano a ogni click).
 * mode "piu": 2-3 combinazioni di line-up (2-4 artisti) che assieme si avvicinano al budget —
 *   costruite con un greedy "riempi il budget" a partire da punti di ingresso diversi in un pool
 *   RIMESCOLATO a ogni chiamata (niente subset-sum esaustivo: il pool è già ristretto, e mescolarlo
 *   è ciò che fa apparire combinazioni diverse cliccando più volte con lo stesso budget/generi).
 *
 * Oltre alla lista principale, propone SEMPRE (indipendentemente da budget/modalità) fino a 2
 * artisti "senza impegno" (cachet 0, disponibili per aperture), utile per completare la serata.
 */
require_once __DIR__ . '/_http.php';
require_once __DIR__ . '/_access.php';
only('POST');

$u = require_user();
if (!viewer_can_see_prices($u)) fail('prices_locked', 403);

$in     = body();
$budget = (float) ($in['budget'] ?? 0);
$mode   = ($in['mode'] ?? 'uno') === 'piu' ? 'piu' : 'uno';
$genres = array_filter(array_map('strval', (array) ($in['genres'] ?? [])));

if ($budget <= 0) fail('budget_required');
$minTotal   = $budget * 0.10;
$budgetMax  = $budget * 1.10;   // tolleranza 10% in più, per non scartare combinazioni quasi perfette

$where  = ["ap.published = 1", "ap.trattativa_riservata = 0", "ap.cachet_min IS NOT NULL", "ap.cachet_min <= ?"];
$params = [$budgetMax];

if ($genres) {
  $ph = implode(',', array_fill(0, count($genres), '?'));
  $where[] = "ap.user_id IN (
      SELECT ag.artist_user_id FROM artist_genres ag JOIN genres g ON g.id = ag.genre_id
      WHERE g.slug IN ($ph)
    )";
  foreach ($genres as $g) $params[] = $g;
}

// Pool ristretto: i migliori 60 candidati per priorità (evidenza/verificati) e cachet decrescente,
// indipendente da quanti artisti totali ci siano nel roster.
$sql = "SELECT ap.user_id, ap.stage_name, ap.slug, ap.formazione, ap.comune, ap.photo_url, ap.verified,
               ap.cachet_min, ap.cachet_promo, ap.promo_until
        FROM artist_profiles ap
        WHERE " . implode(' AND ', $where) . "
        ORDER BY ap.top8 DESC, ap.verified DESC, ap.cachet_min DESC
        LIMIT 60";
$st = db()->prepare($sql);
$st->execute($params);
$rows = $st->fetchAll();

$today = date('Y-m-d');
$pool = [];
foreach ($rows as $r) {
  $promoOk = $r['cachet_promo'] !== null && (int) $r['cachet_promo'] > 0
    && ($r['promo_until'] === null || $r['promo_until'] >= $today);
  $price = $promoOk ? (int) $r['cachet_promo'] : (int) $r['cachet_min'];
  if ($price <= 0 || $price > $budgetMax) continue;   // "senza impegno"/cachet 0: non componibile in un budget
  $pool[] = [
    'user_id' => (int) $r['user_id'], 'stage_name' => $r['stage_name'], 'slug' => $r['slug'],
    'formazione' => $r['formazione'], 'comune' => $r['comune'], 'photo_url' => $r['photo_url'],
    'verified' => (bool) $r['verified'], 'price' => $price,
  ];
}
shuffle($pool);   // rimescolato a ogni richiesta: stesso budget/generi → combinazioni diverse a ogni click

// Artisti "senza impegno" (cachet 0) proposti come apertura, sempre, indipendentemente da budget/modalità.
// Prima prova rispettando i generi scelti; se non bastano per arrivare a 2, ripiega su tutti i generi.
function fetch_openers(array $genres, array $exclude): array {
  $where  = ["ap.published = 1", "ap.trattativa_riservata = 0",
             "((ap.cachet_min IS NOT NULL AND ap.cachet_min = 0) OR (ap.cachet_max IS NOT NULL AND ap.cachet_max = 0))"];
  $params = [];
  if ($genres) {
    $ph = implode(',', array_fill(0, count($genres), '?'));
    $where[] = "ap.user_id IN (
        SELECT ag.artist_user_id FROM artist_genres ag JOIN genres g ON g.id = ag.genre_id
        WHERE g.slug IN ($ph)
      )";
    foreach ($genres as $g) $params[] = $g;
  }
  if ($exclude) {
    $ph = implode(',', array_fill(0, count($exclude), '?'));
    $where[] = "ap.user_id NOT IN ($ph)";
    foreach ($exclude as $id) $params[] = $id;
  }
  $sql = "SELECT ap.user_id, ap.stage_name, ap.slug, ap.formazione, ap.comune, ap.photo_url, ap.verified
          FROM artist_profiles ap WHERE " . implode(' AND ', $where) . " ORDER BY RAND() LIMIT 2";
  $st = db()->prepare($sql);
  $st->execute($params);
  return array_map(fn($r) => [
    'user_id' => (int) $r['user_id'], 'stage_name' => $r['stage_name'], 'slug' => $r['slug'],
    'formazione' => $r['formazione'], 'comune' => $r['comune'], 'photo_url' => $r['photo_url'],
    'verified' => (bool) $r['verified'], 'price' => 0,
  ], $st->fetchAll());
}
$openers = fetch_openers($genres, []);
if (count($openers) < 2 && $genres) {
  $more = fetch_openers([], array_column($openers, 'user_id'));
  $openers = array_slice(array_merge($openers, $more), 0, 2);
}

if ($mode === 'uno') {
  $matches = array_values(array_filter($pool, fn($a) => $a['price'] >= $minTotal));
  // pool già mescolato: i primi 6 sono una selezione casuale tra i compatibili, diversa a ogni click
  ok([
    'suggestions' => array_map(fn($a) => ['artists' => [$a], 'total' => $a['price']], array_slice($matches, 0, 6)),
    'openers' => $openers,
  ]);
}

// mode "piu": alcuni tentativi greedy da punti di partenza diversi (nel pool mescolato), poi dedup.
$lineups = [];
$tryStartFrom = array_slice(array_keys($pool), 0, min(6, count($pool)));
foreach ($tryStartFrom as $startIdx) {
  $used = []; $total = 0; $picked = [];
  $order = array_merge([$startIdx], array_diff(array_keys($pool), [$startIdx]));
  foreach ($order as $i) {
    if (count($picked) >= 4) break;
    $a = $pool[$i];
    if (isset($used[$a['user_id']])) continue;
    if ($total + $a['price'] <= $budgetMax) { $picked[] = $a; $total += $a['price']; $used[$a['user_id']] = true; }
  }
  if (count($picked) >= 2 && $total >= $minTotal) {
    $ids = array_column($picked, 'user_id'); sort($ids);
    $dedupKey = implode(',', $ids);
    if (!isset($lineups[$dedupKey])) $lineups[$dedupKey] = ['artists' => $picked, 'total' => $total];
  }
}
usort($lineups, fn($a, $b) => $b['total'] <=> $a['total']);
ok(['suggestions' => array_slice(array_values($lineups), 0, 3), 'openers' => $openers]);
