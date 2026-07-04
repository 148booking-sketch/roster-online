<?php
/**
 * GET /api/management-get-artist.php?id=123   (solo booking/management ATTIVI)
 * Profilo completo + generi di un artista GESTITO dal booking corrente (per il form di modifica).
 * Stessa forma di admin-get-user.php, così il form condiviso (artist-form.js) legge lo stesso formato.
 */
require_once __DIR__ . '/_management.php';
$me = require_management();

$id = (int)($_GET['id'] ?? 0);
require_managed_artist($id, (int) $me['id']);   // esiste ed è mio, altrimenti fail()

$st = db()->prepare('SELECT id, email, role, display_name, status FROM users WHERE id = ?');
$st->execute([$id]);
$user = $st->fetch();
if (!$user) fail('not_found', 404);

$p = db()->prepare('SELECT * FROM artist_profiles WHERE user_id = ?');
$p->execute([$id]);
$profile = $p->fetch() ?: null;
if ($profile) {
  $g = db()->prepare(
    'SELECT g.id, g.slug, g.name FROM artist_genres ag
     JOIN genres g ON g.id = ag.genre_id WHERE ag.artist_user_id = ?'
  );
  $g->execute([$id]);
  $profile['genres'] = $g->fetchAll();
  if (is_string($profile['socials'] ?? null)) {
    $profile['socials'] = json_decode($profile['socials'], true) ?: [];
  }
  foreach (['gear_bring', 'gear_need', 'custom_links'] as $gk) {
    if (is_string($profile[$gk] ?? null)) $profile[$gk] = json_decode($profile[$gk], true) ?: [];
  }
}

ok(['user' => $user, 'profile' => $profile]);
