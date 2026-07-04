<?php
/**
 * POST /api/admin-refresh-stats.php   (solo admin)
 * Body: { id }
 * Ricalcola statistiche social (incl. Instagram/Facebook via Apify) E calendario disponibilità in un colpo solo.
 */
require_once __DIR__ . '/_admin.php';
require_once __DIR__ . '/_stats.php';
require_once __DIR__ . '/_calendar.php';
only('POST');
require_admin();

$in = body();
$id = (int)($in['id'] ?? 0);
if ($id <= 0) fail('id_required');

$st = db()->prepare('SELECT socials, calendar_url FROM artist_profiles WHERE user_id = ?');
$st->execute([$id]);
$row = $st->fetch();
if ($row === false) fail('not_found', 404);

// Rilascia il lock di sessione PRIMA della chiamata lenta ad Apify: altrimenti, finché questa
// richiesta è in corso, ogni altra richiesta con lo stesso cookie di sessione (altre tab, la nav
// della pagina stessa) resta bloccata in attesa dello stesso file di sessione ⇒ "sito non navigabile".
if (session_status() === PHP_SESSION_ACTIVE) session_write_close();

@set_time_limit(0);
$soc = json_decode($row['socials'] ?: '', true) ?: [];
$stats = $soc ? refresh_artist_stats($id, $soc, true) : [];   // withApify = true (refresh completo)
$busy  = refresh_artist_calendar($id, $row['calendar_url']);

ok(['id' => $id, 'stats' => $stats, 'calendar_busy' => $busy]);
