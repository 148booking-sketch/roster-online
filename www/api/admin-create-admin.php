<?php
/**
 * POST /api/admin-create-admin.php   (solo super admin)
 * Crea un nuovo account admin (super o ridotto).
 * Body: { email, password, display_name, admin_super: 0|1 }
 */
require_once __DIR__ . '/_admin.php';
only('POST');
require_super_admin();

$in    = body();
$email = strtolower(trim($in['email'] ?? ''));
$pass  = (string)($in['password'] ?? '');
$name  = trim($in['display_name'] ?? '');
$super = (int)!!($in['admin_super'] ?? 0);

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) fail('email_invalid');
if (strlen($pass) < 8) fail('password_too_short');
if ($name === '') fail('name_required');

$st = db()->prepare('SELECT id FROM users WHERE email = ?');
$st->execute([$email]);
if ($st->fetch()) fail('email_taken', 409);

db()->prepare(
  'INSERT INTO users (email, password_hash, role, display_name, status, email_verified, admin_super)
   VALUES (?, ?, "admin", ?, "active", 1, ?)'
)->execute([$email, password_hash($pass, PASSWORD_DEFAULT), $name, $super]);

ok(['id' => (int) db()->lastInsertId()]);
