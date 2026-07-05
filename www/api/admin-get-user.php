<?php
/**
 * GET /api/admin-get-user.php?id=123   (solo admin)
 * Restituisce utente + profilo completo (artista o promoter) + generi.
 */
require_once __DIR__ . '/_admin.php';
require_admin();

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) fail('id_required');

$st = db()->prepare('SELECT id, email, role, display_name, status, email_verified, created_at, last_login FROM users WHERE id = ?');
$st->execute([$id]);
$user = $st->fetch();
if (!$user) fail('not_found', 404);

$profile = null;
if ($user['role'] === 'artist') {
  $p = db()->prepare('SELECT * FROM artist_profiles WHERE user_id = ?');
  $p->execute([$id]);
  $profile = $p->fetch() ?: null;
  if ($profile) {
    // stessa forma di me.php: {id, slug, name} per genere (non solo l'id) — così i due
    // form (profilo artista / admin) leggono esattamente lo stesso formato di dati.
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
    if (!empty($profile['manager_user_id'])) {
      $mn = db()->prepare('SELECT org_name FROM promoter_profiles WHERE user_id = ?');
      $mn->execute([$profile['manager_user_id']]);
      $profile['manager_org_name'] = $mn->fetchColumn() ?: null;
    }
  }
} elseif (in_array($user['role'], ['promoter', 'management'], true)) {
  $p = db()->prepare('SELECT * FROM promoter_profiles WHERE user_id = ?');
  $p->execute([$id]);
  $profile = $p->fetch() ?: null;
}

ok(['user' => $user, 'profile' => $profile]);
