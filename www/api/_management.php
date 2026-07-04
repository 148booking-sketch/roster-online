<?php
/**
 * Helper condivisi per l'area booking/management.
 * require_management() → utente management loggato e ATTIVO (approvato dall'admin),
 * altrimenti 401/403. Un booking "pending" può accedere ma non gestire artisti finché
 * l'admin non lo porta ad "active" (stessa logica dei prezzi per i promoter).
 */
require_once __DIR__ . '/_admin.php';   // porta con sé _http.php + make_slug/managed_email/managed_password

function require_management(bool $mustBeActive = true): array {
  $u = current_user();
  if (!$u) fail('not_authenticated', 401);
  if ($u['status'] === 'blocked') fail('account_blocked', 403);
  if ($u['role'] !== 'management') fail('forbidden_management', 403);
  if ($mustBeActive && $u['status'] !== 'active') fail('account_pending', 403);
  return $u;
}

/** Verifica che l'artista $artistId esista e sia gestito dal booking $managerId. Altrimenti fail(). */
function require_managed_artist(int $artistId, int $managerId): void {
  if ($artistId <= 0) fail('id_required');
  $st = db()->prepare('SELECT manager_user_id FROM artist_profiles WHERE user_id = ?');
  $st->execute([$artistId]);
  $mgr = $st->fetchColumn();
  if ($mgr === false) fail('not_found', 404);
  if ((int) $mgr !== $managerId) fail('forbidden_not_owner', 403);
}
