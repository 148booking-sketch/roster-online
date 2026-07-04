<?php
/**
 * POST /api/admin-delete-user.php   (solo admin)
 * Body: { id }
 * Elimina un utente (artista o promoter) e, per cascata FK, profilo/generi/venue/richieste.
 * Non permette di eliminare se stessi né altri admin.
 */
require_once __DIR__ . '/_admin.php';
only('POST');
$me = require_admin();

$in = body();
$id = (int)($in['id'] ?? 0);
if ($id <= 0) fail('id_required');
if ($id === (int)$me['id']) fail('cannot_delete_self', 400);

$st = db()->prepare('SELECT role FROM users WHERE id = ?');
$st->execute([$id]);
$role = $st->fetchColumn();
if ($role === false) fail('not_found', 404);
if ($role === 'admin') fail('cannot_delete_admin', 403);

db()->prepare('DELETE FROM users WHERE id = ?')->execute([$id]);
ok(['deleted' => $id]);
