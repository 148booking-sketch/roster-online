<?php
/**
 * Aggiornamento statistiche social — motore per lo scheduler.
 *   GET /api/stats-cron.php?token=SEGRETO[&limit=50][&days=3][&user_id=ID]
 * Chiamabile da: cron dell'hosting, GitHub Action, o trigger interno del sito.
 * Token in config: 'stats_token'.
 * NB: $days è la soglia di "vecchiaia" oltre cui un artista viene ricalcolato in
 * questa chiamata, NON la frequenza del cron stesso (quella si imposta nel pannello
 * hosting). Con apify=1 va tenuto <= alla scadenza reale delle foto Instagram
 * (~4-5 giorni, sono URL CDN firmati e temporanei) altrimenti restano rotte per
 * qualche giorno tra un refresh e l'altro.
 */
require_once __DIR__ . '/_http.php';
require_once __DIR__ . '/_stats.php';
header('Content-Type: application/json; charset=utf-8');

$token = config()['stats_token'] ?? '';
if ($token === '' || ($_GET['token'] ?? '') !== $token) { http_response_code(403); exit('{"ok":false,"error":"forbidden"}'); }

@set_time_limit(0);

// apify=1 → aggiorna anche Instagram/Facebook (consuma credito Apify): usarlo nel cron settimanale.
$apify = !empty($_GET['apify']);

// refresh singolo (per test/manuale)
if (!empty($_GET['user_id'])) {
  $uid = (int) $_GET['user_id'];
  $st = db()->prepare('SELECT socials FROM artist_profiles WHERE user_id = ?');
  $st->execute([$uid]);
  $soc = json_decode($st->fetchColumn() ?: '', true) ?: [];
  $stats = refresh_artist_stats($uid, $soc, $apify);
  echo json_encode(['ok' => true, 'user_id' => $uid, 'stats' => $stats]); exit;
}

$limit = (int) ($_GET['limit'] ?? 50);
$days  = (int) ($_GET['days'] ?? 3);
$n = refresh_stale_stats($limit, $days, $apify);
meta_set('stats_batch_at', gmdate('Y-m-d H:i:s'));

echo json_encode(['ok' => true, 'refreshed' => $n, 'limit' => $limit, 'days' => $days]);
