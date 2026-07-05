<?php
/**
 * GET  /api/me.php     → utente corrente + profilo
 * POST /api/me.php?logout=1  → logout
 */
require_once __DIR__ . '/_http.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && isset($_GET['logout'])) {
  logout_user();
  ok(['logged_out' => true]);
}

$u = current_user();
if (!$u) ok(['user' => null]);

$profile = null;
if ($u['role'] === 'artist') {
  $st = db()->prepare('SELECT * FROM artist_profiles WHERE user_id = ?');
  $st->execute([$u['id']]);
  $profile = $st->fetch() ?: null;
  if ($profile) {
    $g = db()->prepare(
      'SELECT g.id, g.slug, g.name FROM artist_genres ag
       JOIN genres g ON g.id = ag.genre_id WHERE ag.artist_user_id = ?'
    );
    $g->execute([$u['id']]);
    $profile['genres'] = $g->fetchAll();
    // Stessa forma di admin-get-user.php / management-get-artist.php: socials e gear
    // decodificati in array, non stringa JSON grezza (artist-form.js li legge identici).
    if (is_string($profile['socials'] ?? null)) {
      $profile['socials'] = json_decode($profile['socials'], true) ?: [];
    }
    foreach (['gear_bring', 'gear_need', 'custom_links'] as $gk) {
      if (is_string($profile[$gk] ?? null)) $profile[$gk] = json_decode($profile[$gk], true) ?: [];
    }
    // Nome dell'agenzia che lo gestisce (se assegnato): il campo "Agenzia" del form lo mostra
    // in automatico e non modificabile, invece di lasciarlo testo libero (vedi artist-form.js).
    if (!empty($profile['manager_user_id'])) {
      $mn = db()->prepare('SELECT org_name FROM promoter_profiles WHERE user_id = ?');
      $mn->execute([$profile['manager_user_id']]);
      $profile['manager_org_name'] = $mn->fetchColumn() ?: null;
    }
  }
} elseif (in_array($u['role'], ['promoter', 'management'], true)) {
  // I booking/management riusano promoter_profiles (stessa forma: org_name, tipo, contatti).
  $st = db()->prepare('SELECT * FROM promoter_profiles WHERE user_id = ?');
  $st->execute([$u['id']]);
  $profile = $st->fetch() ?: null;
}

ok(['user' => $u, 'profile' => $profile]);
