<?php
/**
 * POST /api/lineup-suggest.php   (solo promoter verificati/admin: serve vedere i cachet)
 * Body: { budget, mode: "uno"|"piu", genres?: [slug,...] }
 *
 * Consiglia artisti pubblicati con cachet noto (esclusi quelli in trattativa riservata, il
 * cui prezzo non è mai in chiaro). In entrambe le modalità la priorità è SEMPRE la stessa
 * regola del resto del sito: artisti in evidenza (top8) prima, poi verificati, poi gli altri
 * (vedi memoria "Ordine liste artisti") — qui applicata per fasce, mescolando SOLO all'interno
 * di ciascuna fascia (per avere varietà a ogni click senza perdere la priorità).
 *
 * mode "uno": TUTTI gli artisti singoli con cachet ENTRO IL 20% dal budget indicato (es. budget
 *   5.000 → mostra artisti da 4.000 a 6.000), fino a un tetto ampio di 30 risultati per non
 *   sommergere la pagina. Se nessuno rientra nella finestra (budget molto alto o molto basso
 *   rispetto al roster), propone gli artisti dal cachet più alto disponibile, stessa priorità
 *   a fasce. Propone anche fino a 2 artisti "senza impegno" (cachet 0) come apertura.
 *
 * mode "piu": SEMPRE 5 righe con lo schema fisso (paganti + aperture, sempre 6 posti totali):
 *   1 artista + 5 aperture · 2+4 · 3+3 · 4+2 · 5+1.
 *   Gli artisti paganti di ogni riga sommano tra il 10% e il 110% del budget (tolleranza +10%),
 *   cercando di avvicinarsi il più possibile al tetto (usare tutto il budget indicato); a parità
 *   di uso del budget (entro l'1%) vince la combinazione con più artisti top8/verificati. Pool e
 *   aperture rimescolati (per fascia) a ogni chiamata: anche a parità di budget/generi le righe
 *   cambiano a ogni click. Una riga per cui non si trova una combinazione valida viene omessa.
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

// mode "uno": finestra stretta ±20% intorno al budget indicato.
// mode "piu": tolleranza standard, 10%-110% del budget per la somma dei paganti di ogni riga.
if ($mode === 'uno') { $loBound = $budget * 0.8; $hiBound = $budget * 1.2; }
else                 { $loBound = $budget * 0.1; $hiBound = $budget * 1.1; }

$where  = ["ap.published = 1", "ap.trattativa_riservata = 0", "ap.cachet_min IS NOT NULL", "ap.cachet_min <= ?"];
$params = [$hiBound];

if ($genres) {
  $ph = implode(',', array_fill(0, count($genres), '?'));
  $where[] = "ap.user_id IN (
      SELECT ag.artist_user_id FROM artist_genres ag JOIN genres g ON g.id = ag.genre_id
      WHERE g.slug IN ($ph)
    )";
  foreach ($genres as $g) $params[] = $g;
}

// Pool ampio (200, non più 60): "mostra più artisti possibili" richiede materiale sufficiente,
// indipendente da quanti artisti totali ci siano nel roster. L'ordine (top8 → verificati →
// cachet) è la fascia di priorità che il resto della funzione preserva.
$sql = "SELECT ap.user_id, ap.stage_name, ap.slug, ap.formazione, ap.comune, ap.photo_url,
               ap.verified, ap.top8, ap.cachet_min, ap.cachet_promo, ap.promo_until
        FROM artist_profiles ap
        WHERE " . implode(' AND ', $where) . "
        ORDER BY ap.top8 DESC, ap.verified DESC, ap.cachet_min DESC
        LIMIT 200";
$st = db()->prepare($sql);
$st->execute($params);
$rows = $st->fetchAll();

$today = date('Y-m-d');
$pool = [];
foreach ($rows as $r) {
  $promoOk = $r['cachet_promo'] !== null && (int) $r['cachet_promo'] > 0
    && ($r['promo_until'] === null || $r['promo_until'] >= $today);
  $price = $promoOk ? (int) $r['cachet_promo'] : (int) $r['cachet_min'];
  if ($price <= 0 || $price > $hiBound) continue;   // "senza impegno"/cachet 0: gestiti a parte come aperture
  $pool[] = [
    'user_id' => (int) $r['user_id'], 'stage_name' => $r['stage_name'], 'slug' => $r['slug'],
    'formazione' => $r['formazione'], 'comune' => $r['comune'], 'photo_url' => $r['photo_url'],
    'verified' => (bool) $r['verified'], 'top8' => (bool) $r['top8'], 'price' => $price,
  ];
}

/** Fascia di priorità di un artista: 0 = top8, 1 = verificato (non top8), 2 = altri. */
function priority_tier(array $a): int { return $a['top8'] ? 0 : ($a['verified'] ? 1 : 2); }

/**
 * Mescola SOLO all'interno di ogni fascia di priorità (top8 → verificati → altri), preservando
 * l'ordine delle fasce: dà varietà a ogni click senza mai far scavalcare un artista "altro" a
 * uno top8/verificato, come richiesto ("priorità ai top poi verificati poi altri").
 */
function shuffle_within_tiers(array $items): array {
  $tiers = [[], [], []];
  foreach ($items as $a) $tiers[priority_tier($a)][] = $a;
  foreach ($tiers as &$t) shuffle($t);
  unset($t);
  return array_merge($tiers[0], $tiers[1], $tiers[2]);
}

$pool = shuffle_within_tiers($pool);

/**
 * Artisti "senza impegno" (cachet 0), da proporre come apertura. $exclude evita di ripetere lo
 * stesso artista tra più righe della stessa risposta. Prima prova a rispettare i generi scelti;
 * se non bastano per raggiungere $limit, ripiega su tutti i generi.
 */
function fetch_openers(array $genres, array $exclude, int $limit): array {
  if ($limit <= 0) return [];
  $build = function (array $genres, array $exclude) use ($limit) {
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
            FROM artist_profiles ap WHERE " . implode(' AND ', $where) . " ORDER BY RAND() LIMIT $limit";
    $st = db()->prepare($sql);
    $st->execute($params);
    return array_map(fn($r) => [
      'user_id' => (int) $r['user_id'], 'stage_name' => $r['stage_name'], 'slug' => $r['slug'],
      'formazione' => $r['formazione'], 'comune' => $r['comune'], 'photo_url' => $r['photo_url'],
      'verified' => (bool) $r['verified'], 'price' => 0,
    ], $st->fetchAll());
  };
  $found = $build($genres, $exclude);
  if (count($found) < $limit && $genres) {
    $more = $build([], array_merge($exclude, array_column($found, 'user_id')));
    $found = array_slice(array_merge($found, $more), 0, $limit);
  }
  return $found;
}

/**
 * Cerca una combinazione di ESATTAMENTE $n artisti paganti la cui somma rientri in [$lo,$hi],
 * cercando di avvicinarsi il più possibile a $hi (usare tutto il budget indicato). A parità di
 * uso del budget (entro l'1% del tetto) vince la combinazione con più artisti top8/verificati.
 * N=1: caso banale, risolto in modo esatto (niente casuale): il prezzo più alto ammesso nella
 * fascia di priorità più alta disponibile.
 * N>1: prova molte combinazioni casuali su TUTTO il pool (non solo i più economici — pescare
 * solo tra quelli avrebbe sistematicamente sotto-usato budget alti); nessuna ricerca esaustiva
 * (il pool è già ampio, sufficiente per un roster reale).
 */
function combo_priority_score(array $picked): int {
  $score = 0;
  foreach ($picked as $a) $score += $a['top8'] ? 2 : ($a['verified'] ? 1 : 0);
  return $score;
}
function pick_n_artists(array $pool, int $n, float $lo, float $hi, int $tries = 250): ?array {
  if ($n <= 0 || count($pool) < $n) return null;
  if ($n === 1) {
    $valid = array_values(array_filter($pool, fn($a) => $a['price'] >= $lo && $a['price'] <= $hi));
    if (!$valid) return null;
    // il pool è già in ordine di fascia (top8 → verificati → altri); dentro la prima fascia utile
    // che ha una fascia con match, preferisce il prezzo più alto (miglior uso del budget)
    $bestTier = min(array_map('priority_tier', $valid));
    $inTier = array_values(array_filter($valid, fn($a) => priority_tier($a) === $bestTier));
    usort($inTier, fn($a, $b) => $b['price'] <=> $a['price']);
    return ['artists' => [$inTier[0]], 'total' => $inTier[0]['price']];
  }
  $best = null;
  for ($t = 0; $t < $tries; $t++) {
    $sample = $pool;
    shuffle($sample);
    $picked = array_slice($sample, 0, $n);
    if (count($picked) < $n) continue;
    $total = array_sum(array_column($picked, 'price'));
    if ($total < $lo || $total > $hi) continue;
    $score = combo_priority_score($picked);
    if ($best === null) { $best = ['artists' => $picked, 'total' => $total, 'score' => $score]; continue; }
    $closeEnough = abs($total - $best['total']) <= $hi * 0.01;
    $better = $closeEnough ? ($score > $best['score']) : ($total > $best['total']);
    if ($better) $best = ['artists' => $picked, 'total' => $total, 'score' => $score];
    if ($best['total'] >= $hi * 0.98) break;   // già vicinissimo al tetto: inutile continuare a provare
  }
  return $best;
}

/**
 * Fallback per budget molto alto (o molto basso): nessun artista rientra nella finestra ±20%
 * intorno al valore indicato. Invece di non mostrare nulla, propone gli artisti col cachet
 * (promo incluso, se più alto) più alto disponibile nel roster, stessa priorità a fasce.
 */
function fetch_top_cachet(array $genres, int $limit): array {
  $where  = ["ap.published = 1", "ap.trattativa_riservata = 0", "ap.cachet_min IS NOT NULL", "ap.cachet_min > 0"];
  $params = [];
  if ($genres) {
    $ph = implode(',', array_fill(0, count($genres), '?'));
    $where[] = "ap.user_id IN (
        SELECT ag.artist_user_id FROM artist_genres ag JOIN genres g ON g.id = ag.genre_id
        WHERE g.slug IN ($ph)
      )";
    foreach ($genres as $g) $params[] = $g;
  }
  $today = date('Y-m-d');
  $sql = "SELECT ap.user_id, ap.stage_name, ap.slug, ap.formazione, ap.comune, ap.photo_url,
                 ap.verified, ap.top8, ap.cachet_min, ap.cachet_promo, ap.promo_until
          FROM artist_profiles ap WHERE " . implode(' AND ', $where) . "
          ORDER BY ap.top8 DESC, ap.verified DESC, GREATEST(ap.cachet_min, COALESCE(ap.cachet_promo, 0)) DESC
          LIMIT $limit";
  $st = db()->prepare($sql);
  $st->execute($params);
  return array_map(function ($r) use ($today) {
    $promoOk = $r['cachet_promo'] !== null && (int) $r['cachet_promo'] > 0
      && ($r['promo_until'] === null || $r['promo_until'] >= $today);
    $price = $promoOk ? (int) $r['cachet_promo'] : (int) $r['cachet_min'];
    return [
      'user_id' => (int) $r['user_id'], 'stage_name' => $r['stage_name'], 'slug' => $r['slug'],
      'formazione' => $r['formazione'], 'comune' => $r['comune'], 'photo_url' => $r['photo_url'],
      'verified' => (bool) $r['verified'], 'top8' => (bool) $r['top8'], 'price' => $price,
    ];
  }, $st->fetchAll());
}

if ($mode === 'uno') {
  $matches = array_values(array_filter($pool, fn($a) => $a['price'] >= $loBound && $a['price'] <= $hiBound));
  // pool già in ordine di fascia (e mescolato dentro ciascuna): filtrare mantiene l'ordine di
  // priorità. Tetto ampio (30) invece dei 6 di prima: "mostra più artisti possibili".
  $chosen = $matches ? array_slice($matches, 0, 30) : fetch_top_cachet($genres, 30);
  ok([
    'suggestions' => array_map(fn($a) => ['artists' => [$a], 'total' => $a['price']], $chosen),
    'openers' => fetch_openers($genres, [], 2),
  ]);
}

// mode "piu": schema fisso a 5 righe, sempre 6 posti totali (paganti + aperture).
// Ogni riga pesca le proprie aperture in modo indipendente (senza escludere quelle già usate
// nelle righe precedenti): con pochi artisti "senza impegno" nel roster, la riga 1 (che ne
// chiede fino a 5) esaurirebbe il pool ed escluderebbe le aperture da tutte le righe successive.
// Qualche ripetizione tra righe è accettabile: sono proposte alternative, non un'unica lista.
$scheme = [[1, 5], [2, 4], [3, 3], [4, 2], [5, 1]];
$out = [];
foreach ($scheme as [$paidN, $openN]) {
  $combo = pick_n_artists($pool, $paidN, $loBound, $hiBound);
  if (!$combo) continue;   // nessuna combinazione da $paidN artisti in budget: riga omessa
  $openers = fetch_openers($genres, [], $openN);
  $out[] = [
    'paidCount' => $paidN, 'openersCount' => count($openers),
    'artists' => $combo['artists'], 'openers' => $openers, 'total' => $combo['total'],
  ];
}
ok(['rows' => $out]);
