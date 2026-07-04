<?php
/**
 * POST /api/admin-bootstrap.php
 * Crea il PRIMO account admin. Utilizzabile una sola volta:
 *  - richiede il token segreto 'admin_setup_token' definito in config.php
 *  - si disabilita automaticamente non appena esiste già un admin
 * Body: { token, email, password }
 */
require_once __DIR__ . '/_http.php';
only('POST');

$cfg   = config();
$token = (string)($cfg['admin_setup_token'] ?? '');
if ($token === '') fail('setup_disabled', 403);

$in = body();
if (!hash_equals($token, (string)($in['token'] ?? ''))) fail('token_invalid', 403);

$pdo = db();
if ($pdo->query("SELECT id FROM users WHERE role = 'admin' LIMIT 1")->fetch()) {
  fail('admin_exists', 409);   // esiste già un admin: bootstrap chiuso
}

$email = strtolower(trim($in['email'] ?? ''));
$pass  = (string)($in['password'] ?? '');
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) fail('email_invalid');
if (strlen($pass) < 8) fail('password_too_short');

$st = $pdo->prepare('SELECT id FROM users WHERE email = ?');
$st->execute([$email]);
if ($st->fetch()) fail('email_taken', 409);

$pdo->prepare(
  'INSERT INTO users (email, password_hash, role, display_name, status, email_verified)
   VALUES (?, ?, "admin", "Admin", "active", 1)'
)->execute([$email, password_hash($pass, PASSWORD_DEFAULT)]);
$uid = (int)$pdo->lastInsertId();

login_user($uid);
ok(['id' => $uid, 'email' => $email]);
