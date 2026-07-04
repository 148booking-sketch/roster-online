<?php
/**
 * POST /api/admin-publish.php   (solo admin)
 * Body: { id, published: 0|1 }
 * Approva/nasconde un artista (imposta artist_profiles.published). "Approvare" = renderlo visibile.
 * Per approvare servono: email verificata + tutti i campi di artist_publish_missing_fields()
 * (bio, calendario, comune/provincia, telefono, tipo di show, on stage, generi, spotify,
 * apple music, instagram, cachet a serata, cachet, viaggi, durata set, scheda tecnica),
 * altrimenti la scheda pubblica risulterebbe incompleta.
 */
require_once __DIR__ . '/_admin.php';
only('POST');
require_admin();

$in = body();
$id  = (int)($in['id'] ?? 0);
$pub = (int)!!($in['published'] ?? 0);
if ($id <= 0) fail('id_required');

$st = db()->prepare(
  'SELECT u.role, u.email_verified, p.*
     FROM users u JOIN artist_profiles p ON p.user_id = u.id
    WHERE u.id = ?'
);
$st->execute([$id]);
$row = $st->fetch();
if (!$row || $row['role'] !== 'artist') fail('not_an_artist', 404);

if ($pub === 1) {
  if ((int)$row['email_verified'] !== 1) fail('email_not_verified_yet');
  $g = db()->prepare('SELECT COUNT(*) FROM artist_genres WHERE artist_user_id = ?');
  $g->execute([$id]);
  $row['genre_count'] = (int) $g->fetchColumn();
  $missing = artist_publish_missing_fields($row);
  if ($missing) fail('missing_fields_for_publish', 400, ['fields' => $missing]);
}

// published_at si imposta solo alla prima transizione 0→1: segna il momento in cui l'artista
// diventa visibile ed è l'ancora usata dal digest email per capire chi è "nuovo".
if ($pub === 1 && (int)$row['published'] === 0 && $row['published_at'] === null) {
  db()->prepare('UPDATE artist_profiles SET published = 1, published_at = NOW() WHERE user_id = ?')->execute([$id]);
} else {
  db()->prepare('UPDATE artist_profiles SET published = ? WHERE user_id = ?')->execute([$pub, $id]);
}
ok(['id' => $id, 'published' => $pub]);
