<?php
/**
 * POST /api/admin-delete-admin.php   (solo super admin)
 * Elimina un account admin. Non permette di eliminare se stessi.
 * Body: { id }
 */
require_once __DIR__ . '/_admin.php';
only('POST');
$me = require_super_admin();

$in = body();
$id = (int)($in['id'] ?? 0);
if ($id <= 0) fail('id_required');
if ($id === (int)$me['id']) fail('cannot_delete_self', 400);

$st = db()->prepare('SELECT role FROM users WHERE id = ?');
$st->execute([$id]);
if ($st->fetchColumn() !== 'admin') fail('not_an_admin', 404);

db()->prepare('DELETE FROM users WHERE id = ?')->execute([$id]);
ok(['deleted' => $id]);
