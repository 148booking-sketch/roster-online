<?php
/**
 * GET /api/admin-list.php   (solo admin)
 * Ultimi artisti e promoter inseriti, per un colpo d'occhio.
 * Query opzionale: ?q=testo (cerca su nome/email), ?limit=50
 */
require_once __DIR__ . '/_admin.php';
require_admin();

$q     = trim($_GET['q'] ?? '');
// L'admin panel non ha una vera paginazione: chiede sempre "tutto" per filtrare/contare lato
// client (dashboard + elenco). Il tetto serve solo come rete di sicurezza, non come pagina.
$limit = min(5000, max(1, (int)($_GET['limit'] ?? 50)));
$like  = '%' . $q . '%';
$argsA = $q !== '' ? [$like, $like] : [];

$sqlA = "SELECT u.id, u.email, u.display_name, u.status, u.created_at, u.email_verified,
                p.stage_name, p.comune, p.provincia, p.published, p.verified, p.top8, p.formazione, p.updated_at
           FROM users u JOIN artist_profiles p ON p.user_id = u.id
          WHERE u.role = 'artist'"
      . ($q !== '' ? ' AND (u.email LIKE ? OR u.display_name LIKE ?)' : '')
      . " ORDER BY u.id DESC LIMIT $limit";
$stA = db()->prepare($sqlA);
$stA->execute($argsA);
$artistsRows = $stA->fetchAll();

$sqlP = "SELECT u.id, u.email, u.display_name, u.status, u.created_at, u.email_verified, u.role,
                p.org_name, p.tipo, p.comune, p.provincia, p.updated_at
           FROM users u JOIN promoter_profiles p ON p.user_id = u.id
          WHERE u.role IN ('promoter','management')"
      . ($q !== '' ? ' AND (u.email LIKE ? OR u.display_name LIKE ?)' : '')
      . " ORDER BY u.id DESC LIMIT $limit";
$stP = db()->prepare($sqlP);
$stP->execute($argsA);
$promotersRows = $stP->fetchAll();

ok(['artists' => $artistsRows, 'promoters' => $promotersRows]);
