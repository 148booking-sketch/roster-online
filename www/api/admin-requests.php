<?php
/**
 * GET /api/admin-requests.php   (solo admin)
 * Storico completo delle richieste promoter → artista + report aggregato.
 * Query opzionali: ?status=... ?q=testo (nome artista/promoter) ?limit=500
 */
require_once __DIR__ . '/_admin.php';
require_admin();

$status = in_array($_GET['status'] ?? '', ['inviata','vista','accettata','rifiutata','ritirata'], true) ? $_GET['status'] : '';
$q      = trim($_GET['q'] ?? '');
$limit  = min(1000, max(1, (int)($_GET['limit'] ?? 500)));

$where  = [];
$params = [];
if ($status !== '') { $where[] = 'br.status = ?'; $params[] = $status; }
if ($q !== '') {
  $where[] = '(pu.display_name LIKE ? OR au.display_name LIKE ? OR ap.stage_name LIKE ?)';
  $like = '%' . $q . '%';
  array_push($params, $like, $like, $like);
}
$whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

$sql = "SELECT br.id, br.event_date, br.message, br.proposed_fee, br.status,
               br.created_at, br.responded_at,
               pu.id AS promoter_id, pu.display_name AS promoter_name,
               au.id AS artist_id, COALESCE(ap.stage_name, au.display_name) AS artist_name, ap.slug AS artist_slug,
               v.name AS venue_name
        FROM booking_requests br
        JOIN users pu ON pu.id = br.promoter_user_id
        JOIN users au ON au.id = br.artist_user_id
        LEFT JOIN artist_profiles ap ON ap.user_id = br.artist_user_id
        LEFT JOIN venues v ON v.id = br.venue_id
        $whereSql
        ORDER BY br.created_at DESC
        LIMIT $limit";
$st = db()->prepare($sql);
$st->execute($params);
$rows = $st->fetchAll();

// Report aggregato su TUTTE le richieste (non solo quelle mostrate).
$agg = db()->query(
  "SELECT status, COUNT(*) AS n, COALESCE(SUM(proposed_fee),0) AS fee
   FROM booking_requests GROUP BY status"
)->fetchAll();

$byStatus = ['inviata'=>0,'vista'=>0,'accettata'=>0,'rifiutata'=>0,'ritirata'=>0];
$total = 0; $proposedTotal = 0; $acceptedValue = 0;
foreach ($agg as $a) {
  $byStatus[$a['status']] = (int)$a['n'];
  $total += (int)$a['n'];
  $proposedTotal += (int)$a['fee'];
  if ($a['status'] === 'accettata') $acceptedValue = (int)$a['fee'];
}
$pending = $byStatus['inviata'] + $byStatus['vista'];
$responded = $byStatus['accettata'] + $byStatus['rifiutata'];
$acceptRate = $responded > 0 ? round($byStatus['accettata'] * 100 / $responded) : 0;

ok([
  'requests' => $rows,
  'report' => [
    'total'          => $total,
    'by_status'      => $byStatus,
    'pending'        => $pending,
    'accept_rate'    => $acceptRate,      // % accettate sulle richieste con risposta
    'proposed_total' => $proposedTotal,   // somma compensi proposti (tutte)
    'accepted_value' => $acceptedValue,   // somma compensi delle accettate
  ],
]);
