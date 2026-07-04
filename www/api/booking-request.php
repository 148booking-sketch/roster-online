<?php
/**
 * POST /api/booking-request.php  (solo promoter loggati)
 *   Body: { artist_user_id, venue_id?, event_date?, message, proposed_fee? }
 * GET  /api/booking-request.php  (loggato)
 *   ?box=received (artista) | sent (promoter)  → lista richieste
 */
require_once __DIR__ . '/_http.php';

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($method === 'GET') {
  $u = require_user();
  $box = $_GET['box'] ?? ($u['role'] === 'artist' ? 'received' : 'sent');
  if ($box === 'received') {
    $st = db()->prepare(
      'SELECT br.*, up.display_name AS promoter_name, pp.org_name
       FROM booking_requests br
       JOIN users up ON up.id = br.promoter_user_id
       LEFT JOIN promoter_profiles pp ON pp.user_id = br.promoter_user_id
       WHERE br.artist_user_id = ? ORDER BY br.created_at DESC'
    );
    $st->execute([$u['id']]);
  } else {
    $st = db()->prepare(
      'SELECT br.*, ap.stage_name
       FROM booking_requests br
       JOIN artist_profiles ap ON ap.user_id = br.artist_user_id
       WHERE br.promoter_user_id = ? ORDER BY br.created_at DESC'
    );
    $st->execute([$u['id']]);
  }
  ok(['requests' => $st->fetchAll()]);
}

only('POST');
$u  = require_user();
if (!in_array($u['role'], ['promoter', 'management'], true)) fail('forbidden_role', 403);
$in = body();

$artistId = (int)($in['artist_user_id'] ?? 0);
$venueId  = ($in['venue_id'] ?? '') !== '' ? (int)$in['venue_id'] : null;
$date     = ($in['event_date'] ?? '') !== '' ? substr((string)$in['event_date'], 0, 10) : null;
$msg      = trim($in['message'] ?? '');
$fee      = ($in['proposed_fee'] ?? '') !== '' ? max(0, (int)$in['proposed_fee']) : null;

if ($artistId <= 0) fail('artist_required');
if ($msg === '')    fail('message_required');

// verifica che l'artista esista
$chk = db()->prepare("SELECT id FROM users WHERE id=? AND role='artist'");
$chk->execute([$artistId]);
if (!$chk->fetch()) fail('artist_not_found', 404);

// il venue (se indicato) deve appartenere al promoter
if ($venueId !== null) {
  $v = db()->prepare('SELECT id FROM venues WHERE id=? AND promoter_user_id=?');
  $v->execute([$venueId, $u['id']]);
  if (!$v->fetch()) fail('venue_not_owned', 403);
}

db()->prepare(
  'INSERT INTO booking_requests (promoter_user_id, artist_user_id, venue_id, event_date, message, proposed_fee)
   VALUES (?, ?, ?, ?, ?, ?)'
)->execute([$u['id'], $artistId, $venueId, $date, $msg, $fee]);

// TODO: notifica email all'artista
ok(['request_id' => (int)db()->lastInsertId()]);
