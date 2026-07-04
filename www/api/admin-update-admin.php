<?php
/**
 * POST /api/admin-update-admin.php   (solo super admin)
 * Aggiorna email/nome/stato/livello di un admin ESISTENTE. NON la password.
 * Non permette di modificare il proprio account (evita l'auto-blocco/auto-declassamento).
 * Body: { id, email, display_name, status, admin_super }
 */
require_once __DIR__ . '/_admin.php';
only('POST');
$me = require_super_admin();

$in = body();
$id = (int)($in['id'] ?? 0);
if ($id <= 0) fail('id_required');
if ($id === (int)$me['id']) fail('cannot_edit_self', 400);

$st = db()->prepare('SELECT role FROM users WHERE id = ?');
$st->execute([$id]);
if ($st->fetchColumn() !== 'admin') fail('not_an_admin', 404);

$email = strtolower(trim($in['email'] ?? ''));
$name  = trim($in['display_name'] ?? '');
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) fail('email_invalid');
if ($name === '') fail('name_required');

$st = db()->prepare('SELECT id FROM users WHERE email = ? AND id <> ?');
$st->execute([$email, $id]);
if ($st->fetch()) fail('email_taken', 409);

$status = in_array($in['status'] ?? '', ['active', 'blocked'], true) ? $in['status'] : 'active';
$super  = (int)!!($in['admin_super'] ?? 0);

db()->prepare('UPDATE users SET email = ?, display_name = ?, status = ?, admin_super = ? WHERE id = ?')
    ->execute([$email, $name, $status, $super, $id]);

ok(['id' => $id]);
