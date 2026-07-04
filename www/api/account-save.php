<?php
/**
 * POST /api/account-save.php   (utente loggato)
 * Body: { email, display_name }
 * Aggiorna i dati dell'account (NON la password: usa password-change.php).
 */
require_once __DIR__ . '/_http.php';
only('POST');

$u   = require_user();
$in  = body();
$name  = trim($in['display_name'] ?? '');
$email = strtolower(trim($in['email'] ?? ''));

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) fail('email_invalid');
if ($name === '') fail('name_required');

// Email presa da un altro utente?
if ($email !== strtolower($u['email'])) {
  $st = db()->prepare('SELECT id FROM users WHERE email = ? AND id <> ?');
  $st->execute([$email, $u['id']]);
  if ($st->fetch()) fail('email_taken', 409);
}

db()->prepare('UPDATE users SET email = ?, display_name = ? WHERE id = ?')
    ->execute([$email, $name, $u['id']]);

ok(['user' => ['id' => (int)$u['id'], 'email' => $email, 'display_name' => $name, 'role' => $u['role']]]);
