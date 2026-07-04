<?php
/**
 * GET /api/platform-stats.php — conteggi pubblici per la home (nessun dato sensibile).
 */
require_once __DIR__ . '/_http.php';

$artists   = (int) db()->query("SELECT COUNT(*) FROM artist_profiles WHERE published = 1")->fetchColumn();
$promoters = (int) db()->query("SELECT COUNT(*) FROM users WHERE role = 'promoter' AND status <> 'blocked'")->fetchColumn();

ok(['artists' => $artists, 'promoters' => $promoters]);
