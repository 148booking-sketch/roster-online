<?php
/**
 * Invio digest email ai promoter — motore per lo scheduler.
 *   GET /api/email-digest-cron.php?token=SEGRETO&freq=daily|weekly|monthly
 * Chiamabile da: cron dell'hosting (DirectAdmin), una chiamata per frequenza
 * (es. ogni giorno per "daily", ogni lunedì per "weekly", il giorno 1 per "monthly").
 * Token in config: 'email_digest_token'.
 *
 * Manda solo ai promoter con email_freq = $freq e consenso dato (email_consent_at IS NOT NULL).
 * Se per un promoter non c'è nulla di nuovo dall'ultimo invio, salta senza aggiornare
 * email_last_sent_at (così la finestra si allarga finché non c'è davvero qualcosa da dire).
 */
require_once __DIR__ . '/_http.php';
require_once __DIR__ . '/_mail.php';
header('Content-Type: application/json; charset=utf-8');

$token = config()['email_digest_token'] ?? '';
if ($token === '' || ($_GET['token'] ?? '') !== $token) { http_response_code(403); exit('{"ok":false,"error":"forbidden"}'); }

$freq = $_GET['freq'] ?? '';
if (!in_array($freq, ['daily', 'weekly', 'monthly'], true)) { http_response_code(400); exit('{"ok":false,"error":"freq_invalid"}'); }

@set_time_limit(0);

$freqLabels = ['daily' => 'giornaliero', 'weekly' => 'settimanale', 'monthly' => 'mensile'];
$fallbackDays = ['daily' => 1, 'weekly' => 7, 'monthly' => 30];

$pdo = db();

// Modalità test: ?test_email=... → invia UN digest a quell'indirizzo (contenuto reale corrente,
// forzando l'invio anche se vuoto) senza toccare email_last_sent_at di nessun promoter.
$testEmail = trim($_GET['test_email'] ?? '');
if ($testEmail !== '') {
  if (!filter_var($testEmail, FILTER_VALIDATE_EMAIL)) { http_response_code(400); exit('{"ok":false,"error":"test_email_invalid"}'); }

  $since = gmdate('Y-m-d H:i:s', strtotime('-' . $fallbackDays[$freq] . ' days'));

  $genreStmt = $pdo->prepare('SELECT g.name FROM artist_genres ag JOIN genres g ON g.id = ag.genre_id WHERE ag.artist_user_id = ? LIMIT 1');
  $newArtists = $pdo->prepare(
    'SELECT user_id, stage_name, slug, comune, provincia FROM artist_profiles
      WHERE published = 1 AND published_at >= ? ORDER BY published_at DESC LIMIT 12'
  );
  $newArtists->execute([$since]);
  $newArtists = $newArtists->fetchAll();
  foreach ($newArtists as &$a) { $genreStmt->execute([$a['user_id']]); $a['genre'] = $genreStmt->fetchColumn() ?: null; }
  unset($a);

  $promoArtists = $pdo->query(
    "SELECT stage_name, slug, comune, provincia, cachet_promo, promo_until FROM artist_profiles
      WHERE published = 1 AND cachet_promo IS NOT NULL AND cachet_promo > 0
        AND (promo_until IS NULL OR promo_until >= CURDATE())
      ORDER BY (promo_until IS NULL), promo_until ASC LIMIT 8"
  )->fetchAll();

  // Se l'indirizzo di test corrisponde a un promoter reale, aggiungiamo anche le sue richieste
  // evase e il vero link di disiscrizione (comodo per verificare anche quella parte).
  $promoterMatch = $pdo->prepare(
    "SELECT u.id, u.display_name, pp.email_unsub_token FROM users u
       JOIN promoter_profiles pp ON pp.user_id = u.id
      WHERE u.email = ? AND u.role = 'promoter'"
  );
  $promoterMatch->execute([$testEmail]);
  $match = $promoterMatch->fetch();

  $responded = [];
  $name = '';
  $unsubToken = '';
  if ($match) {
    $name = $match['display_name'] ?? '';
    $unsubToken = $match['email_unsub_token'] ?: ensure_promoter_unsub_token((int) $match['id']);
    $respondedStmt = $pdo->prepare(
      "SELECT ap.stage_name, br.status, br.event_date FROM booking_requests br
         JOIN artist_profiles ap ON ap.user_id = br.artist_user_id
        WHERE br.promoter_user_id = ? AND br.responded_at >= ? AND br.status IN ('accettata','rifiutata')
        ORDER BY br.responded_at DESC LIMIT 20"
    );
    $respondedStmt->execute([$match['id'], $since]);
    $responded = $respondedStmt->fetchAll();
  }

  $data = ['new_artists' => $newArtists, 'promo_artists' => $promoArtists, 'responded_requests' => $responded];
  $ok = send_promoter_digest_email($testEmail, $name, $data, $freqLabels[$freq], $unsubToken, true);

  echo json_encode(['ok' => $ok, 'test' => true, 'freq' => $freq, 'sent_to' => $testEmail, 'matched_promoter' => (bool) $match]);
  exit;
}

$promoters = $pdo->prepare(
  "SELECT u.id, u.email, u.display_name, pp.email_last_sent_at, pp.email_unsub_token
     FROM users u JOIN promoter_profiles pp ON pp.user_id = u.id
    WHERE u.role = 'promoter' AND u.status <> 'blocked'
      AND pp.email_freq = ? AND pp.email_consent_at IS NOT NULL"
);
$promoters->execute([$freq]);
$rows = $promoters->fetchAll();

$genreStmt = $pdo->prepare('SELECT g.name FROM artist_genres ag JOIN genres g ON g.id = ag.genre_id WHERE ag.artist_user_id = ? LIMIT 1');
$newArtistsStmt = $pdo->prepare(
  'SELECT user_id, stage_name, slug, comune, provincia FROM artist_profiles
    WHERE published = 1 AND published_at >= ? ORDER BY published_at DESC LIMIT 12'
);
$promoArtistsStmt = $pdo->prepare(
  "SELECT stage_name, slug, comune, provincia, cachet_promo, promo_until FROM artist_profiles
    WHERE published = 1 AND cachet_promo IS NOT NULL AND cachet_promo > 0
      AND (promo_until IS NULL OR promo_until >= CURDATE())
    ORDER BY (promo_until IS NULL), promo_until ASC LIMIT 8"
);
$respondedStmt = $pdo->prepare(
  "SELECT ap.stage_name, br.status, br.event_date FROM booking_requests br
     JOIN artist_profiles ap ON ap.user_id = br.artist_user_id
    WHERE br.promoter_user_id = ? AND br.responded_at >= ? AND br.status IN ('accettata','rifiutata')
    ORDER BY br.responded_at DESC LIMIT 20"
);

$sent = 0; $skipped = 0;

foreach ($rows as $p) {
  $since = $p['email_last_sent_at'] ?: gmdate('Y-m-d H:i:s', strtotime('-' . $fallbackDays[$freq] . ' days'));

  $newArtistsStmt->execute([$since]);
  $newArtists = $newArtistsStmt->fetchAll();
  foreach ($newArtists as &$a) { $genreStmt->execute([$a['user_id']]); $a['genre'] = $genreStmt->fetchColumn() ?: null; }
  unset($a);

  $promoArtistsStmt->execute();
  $promoArtists = $promoArtistsStmt->fetchAll();

  $respondedStmt->execute([$p['id'], $since]);
  $responded = $respondedStmt->fetchAll();

  $data = ['new_artists' => $newArtists, 'promo_artists' => $promoArtists, 'responded_requests' => $responded];
  $tok = $p['email_unsub_token'] ?: ensure_promoter_unsub_token((int) $p['id']);

  $ok = send_promoter_digest_email($p['email'], $p['display_name'] ?? '', $data, $freqLabels[$freq], $tok);
  if ($ok) {
    $pdo->prepare('UPDATE promoter_profiles SET email_last_sent_at = NOW() WHERE user_id = ?')->execute([$p['id']]);
    $sent++;
  } else {
    $skipped++;
  }
}

echo json_encode(['ok' => true, 'freq' => $freq, 'candidates' => count($rows), 'sent' => $sent, 'skipped_empty' => $skipped]);
