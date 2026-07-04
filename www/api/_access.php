<?php
/**
 * Chi può vedere i cachet e chi può inviare richieste di booking — logica CONDIVISA da
 * artist-get.php, artists-search.php, artists-map.php e artists-featured.php (stessa regola ovunque).
 *   - Cachet/prezzi: admin, l'artista stesso, o un promoter con account in stato "active"
 *     (approvato dall'admin — chi si registra da solo parte "pending").
 *   - Richieste di booking: qualunque promoter loggato (pending o active) o admin — lo stato
 *     va promosso ad "active" solo per vedere i prezzi, non per candidarsi/scrivere.
 */

/** True se il promoter (user_id) è stato approvato dall'admin (status = active). */
function promoter_is_verified(int $userId): bool {
  static $cache = [];
  if (!array_key_exists($userId, $cache)) {
    $st = db()->prepare('SELECT status FROM users WHERE id = ?');
    $st->execute([$userId]);
    $cache[$userId] = $st->fetchColumn() === 'active';
  }
  return $cache[$userId];
}

/** Il $viewer corrente può vedere il cachet di un artista (user_id $artistUserId)? */
function viewer_can_see_prices(?array $viewer, int $artistUserId = 0): bool {
  if (!$viewer) return false;
  if ($viewer['role'] === 'admin') return true;
  if ($artistUserId > 0 && (int) $viewer['id'] === $artistUserId) return true;
  if (in_array($viewer['role'], ['promoter', 'management'], true)) return promoter_is_verified((int) $viewer['id']);
  return false;
}

/** Il $viewer corrente può inviare una richiesta di booking? (promoter/booking anche non verificato) */
function viewer_can_contact(?array $viewer): bool {
  return (bool) ($viewer && in_array($viewer['role'], ['promoter', 'management', 'admin'], true));
}
