<?php
/**
 * POST /api/password-change.php   (utente loggato)
 * Body: { current_password, new_password }
 * Cambia la password: richiede la password ATTUALE (difesa contro il takeover da
 * sessione rubata/fissata). Rate-limited per frenare il brute-force sulla vecchia.
 */
require_once __DIR__ . '/_http.php';
require_once __DIR__ . '/_ratelimit.php';
only('POST');

$u  = require_user();
rate_limit('pwchange', 8, 300);
$in = body();
$cur = (string)($in['current_password'] ?? '');
$new = (string)($in['new_password'] ?? '');

if (strlen($new) < 8) fail('password_too_short');

// Verifica la password attuale dal DB (la sessione non basta).
$row = db()->prepare('SELECT password_hash FROM users WHERE id = ?');
$row->execute([$u['id']]);
$hash = (string)$row->fetchColumn();
if (!password_verify($cur, $hash)) fail('current_password_invalid', 403);

db()->prepare('UPDATE users SET password_hash = ? WHERE id = ?')
    ->execute([password_hash($new, PASSWORD_DEFAULT), $u['id']]);

ok(['changed' => true]);
