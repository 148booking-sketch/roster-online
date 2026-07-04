<?php
/**
 * POST /api/login.php   Body: { email, password }
 */
require_once __DIR__ . '/_http.php';
only('POST');

$in    = body();
$email = strtolower(trim($in['email'] ?? ''));
$pass  = (string)($in['password'] ?? '');

$st = db()->prepare('SELECT id, password_hash, role, display_name, status, email_verified FROM users WHERE email = ?');
$st->execute([$email]);
$u = $st->fetch();

if (!$u || !password_verify($pass, $u['password_hash'])) fail('credentials_invalid', 401);
if ($u['status'] === 'blocked') fail('account_blocked', 403);
if ((int)$u['email_verified'] === 0) fail('email_not_verified', 403, ['email' => $email]);

db()->prepare('UPDATE users SET last_login = NOW() WHERE id = ?')->execute([$u['id']]);
login_user((int)$u['id']);
ok(['user' => ['id' => (int)$u['id'], 'role' => $u['role'], 'display_name' => $u['display_name']]]);
