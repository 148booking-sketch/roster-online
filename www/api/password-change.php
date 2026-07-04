<?php
/**
 * POST /api/password-change.php   (utente loggato)
 * Body: { new_password }
 * Cambia la password dell'utente loggato (nessuna verifica della password attuale:
 * basta una sessione valida). Vedi memoria "keep-everything-aligned" per il trade-off.
 */
require_once __DIR__ . '/_http.php';
only('POST');

$u  = require_user();
$in = body();
$new = (string)($in['new_password'] ?? '');

if (strlen($new) < 8) fail('password_too_short');

db()->prepare('UPDATE users SET password_hash = ? WHERE id = ?')
    ->execute([password_hash($new, PASSWORD_DEFAULT), $u['id']]);

ok(['changed' => true]);
