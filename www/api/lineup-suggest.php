<?php
/**
 * POST /api/lineup-suggest.php   (solo promoter verificati/admin: serve vedere i cachet)
 * Body: { budget, mode: "uno"|"piu", genres?: [slug,...] }
 *
 * Consiglia artisti pubblicati con cachet noto (esclusi quelli in trattativa riservata, il
 * cui prezzo non è mai in chiaro).
 *
 * mode "uno": artisti singoli con cachet ENTRO IL 20% dal budget indicato (es. budget 5.000 →
 *   mostra artisti da 4.000 a 6.000). Ordine casuale tra i compatibili: variano a ogni click.
 *   Propone anche fino a 2 artisti "senza impegno" (cachet 0) come apertura.
 *
 * mode "piu": SEMPRE 5 righe con lo schema fisso (paganti + aperture, sempre 6 posti totali):
 *   1 artista + 5 aperture · 2+4 · 3+3 · 4+2 · 5+1.
 *   Gli artisti paganti di ogni riga sommano tra il 10% e il 110% del budget (tolleranza +10%),
 *   cercando di avvicinarsi il più possibile al tetto (usare tutto il budget indicato); pool e
 *   aperture rimescolati a ogni chiamata, quindi anche a parità di budget/generi le righe
 *   cambiano a ogni click. Una riga per cui non si trova una combinazione valida viene
 *   semplicemente omessa (mai un errore).
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
  if ($price <= 0 || $price > $hiBound) continue;   // "senza impegno"/cachet 0: gestiti a parte come aperture
  $pool[] = [
    'user_id' => (int) $r['user_id'], 'stage_name' => $r['stage_name'], 'slug' => $r['slug'],
    'formazione' => $r['formazione'], 'comune' => $r['comune'], 'photo_url' => $r['photo_url'],
    'verified' => (bool) $r['verified'], 'price' => $price,
  ];
}
shuffle($pool);   // rimescolato a ogni richiesta: stesso budget/generi → risultati diversi a ogni click

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
 * cercando di avvicinarsi il più possibile a $hi (usare tutto il budget indicato).
 * N=1: caso banale, risolto in modo esatto (il prezzo più alto ammesso, niente casuale).
 * N>1: prova molte combinazioni casuali su TUTTO il pool (non solo i più economici — pescare
 * solo tra quelli avrebbe sistematicamente sotto-usato budget alti) e tiene la migliore trovata;
 * nessuna ricerca esaustiva (il pool è già ristretto, sufficiente per un roster reale).
 */
function pick_n_artists(array $pool, int $n, float $lo, float $hi, int $tries = 250): ?array {
  if ($n <= 0 || count($pool) < $n) return null;
  if ($n === 1) {
    $valid = array_values(array_filter($pool, fn($a) => $a['price'] >= $lo && $a['price'] <= $hi));
    if (!$valid) return null;
    usort($valid, fn($a, $b) => $b['price'] <=> $a['price']);
    return ['artists' => [$valid[0]], 'total' => $valid[0]['price']];
  }
  $best = null;
  for ($t = 0; $t < $tries; $t++) {
    $sample = $pool;
    shuffle($sample);
    $picked = array_slice($sample, 0, $n);
    if (count($picked) < $n) continue;
    $total = array_sum(array_column($picked, 'price'));
    if ($total < $lo || $total > $hi) continue;
    if ($best === null || $total > $best['total']) $best = ['artists' => $picked, 'total' => $total];
    if ($best['total'] >= $hi * 0.98) break;   // già vicinissimo al tetto: inutile continuare a provare
  }
  return $best;
}

if ($mode === 'uno') {
  $matches = array_values(array_filter($pool, fn($a) => $a['price'] >= $loBound && $a['price'] <= $hiBound));
  usort($matches, fn($a, $b) => $b['price'] <=> $a['price']);   // usa più budget possibile = prezzo più alto prima
  // il migliore (uso massimo del budget) sempre primo; gli altri 5 variano a ogni click tra le opzioni successive
  $best = array_slice($matches, 0, 1);
  $rest = array_slice($matches, 1, 20);
  shuffle($rest);
  $chosen = array_merge($best, array_slice($rest, 0, 5));
  ok([
    'suggestions' => array_map(fn($a) => ['artists' => [$a], 'total' => $a['price']], $chosen),
    'openers' => fetch_openers($genres, [], 2),
  ]);
}

// mode "piu": schema fisso a 5 righe, sempre 6 posti totali (paganti + aperture).
$scheme = [[1, 5], [2, 4], [3, 3], [4, 2], [5, 1]];
$usedOpenerIds = [];
$out = [];
foreach ($scheme as [$paidN, $openN]) {
  $combo = pick_n_artists($pool, $paidN, $loBound, $hiBound);
  if (!$combo) continue;   // nessuna combinazione da $paidN artisti in budget: riga omessa
  $openers = fetch_openers($genres, $usedOpenerIds, $openN);
  foreach ($openers as $op) $usedOpenerIds[] = $op['user_id'];
  $out[] = [
    'paidCount' => $paidN, 'openersCount' => count($openers),
    'artists' => $combo['artists'], 'openers' => $openers, 'total' => $combo['total'],
  ];
}
ok(['rows' => $out]);
