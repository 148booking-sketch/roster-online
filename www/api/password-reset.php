<?php
/**
 * POST /api/password-reset.php   Body: { token, password }
 * Verifica il token (non scaduto) e imposta la nuova password.
 */
require_once __DIR__ . '/_http.php';
only('POST');

$in    = body();
$token = trim($in['token'] ?? '');
$pass  = (string)($in['password'] ?? '');

if ($token === '') fail('token_invalid');
if (strlen($pass) < 8) fail('password_too_short');

$st = db()->prepare('SELECT id FROM users WHERE reset_token = ? AND reset_expires IS NOT NULL AND reset_expires > NOW()');
$st->execute([$token]);
$u = $st->fetch();
if (!$u) fail('token_invalid_or_expired', 400);

db()->prepare('UPDATE users SET password_hash = ?, reset_token = NULL, reset_expires = NULL WHERE id = ?')
    ->execute([password_hash($pass, PASSWORD_DEFAULT), $u['id']]);

ok(['reset' => true]);
