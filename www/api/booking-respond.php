<?php
/**
 * POST /api/booking-respond.php  Body: { request_id, action: "accetta"|"rifiuta"|"vista" }
 *   artista  → accetta/rifiuta/vista una richiesta ricevuta
 *   promoter → può "ritira"re una richiesta inviata (action: "ritira")
 */
require_once __DIR__ . '/_http.php';
only('POST');

$u  = require_user();
$in = body();
$id = (int)($in['request_id'] ?? 0);
$action = $in['action'] ?? '';
if ($id <= 0) fail('request_required');

$map = [
  'artist'     => ['accetta' => 'accettata', 'rifiuta' => 'rifiutata', 'vista' => 'vista'],
  'promoter'   => ['ritira'  => 'ritirata'],
  'management' => ['ritira'  => 'ritirata'],
];
$roleMap = $map[$u['role']] ?? [];
if (!isset($roleMap[$action])) fail('action_invalid');
$newStatus = $roleMap[$action];

$col = $u['role'] === 'artist' ? 'artist_user_id' : 'promoter_user_id';
$st = db()->prepare("SELECT id FROM booking_requests WHERE id=? AND $col=?");
$st->execute([$id, $u['id']]);
if (!$st->fetch()) fail('not_found', 404);

db()->prepare('UPDATE booking_requests SET status=?, responded_at=NOW() WHERE id=?')
    ->execute([$newStatus, $id]);
ok(['status' => $newStatus]);
