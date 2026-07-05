<?php
/**
 * POST /api/resend-verification.php   Body: { email }
 * Rigenera il token e reinvia l'email di verifica. Risponde sempre ok (non rivela l'esistenza).
 */
require_once __DIR__ . '/_http.php';
require_once __DIR__ . '/_mail.php';
require_once __DIR__ . '/_ratelimit.php';
only('POST');
rate_limit('resend', 6, 600);

$in    = body();
$email = strtolower(trim($in['email'] ?? ''));
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) fail('email_invalid');

$st = db()->prepare('SELECT id, display_name FROM users WHERE email = ? AND email_verified = 0');
$st->execute([$email]);
$u = $st->fetch();
if ($u) {
  $token = bin2hex(random_bytes(32));
  db()->prepare('UPDATE users SET verify_token = ? WHERE id = ?')->execute([$token, $u['id']]);
  @send_verification_email($email, $u['display_name'] ?? '', $token);
}
ok(['sent' => true]);
