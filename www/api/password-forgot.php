<?php
/**
 * POST /api/password-forgot.php   Body: { email }
 * Genera un token di reset (validità 2h) e invia l'email col link.
 * Risponde SEMPRE ok (non rivela se l'email esiste).
 */
require_once __DIR__ . '/_http.php';
require_once __DIR__ . '/_mail.php';
require_once __DIR__ . '/_ratelimit.php';
only('POST');
rate_limit('pwforgot', 6, 600);

$in    = body();
$email = strtolower(trim($in['email'] ?? ''));
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) fail('email_invalid');

$st = db()->prepare('SELECT id, display_name FROM users WHERE email = ?');
$st->execute([$email]);
$u = $st->fetch();

if ($u) {
  $token   = bin2hex(random_bytes(32));
  $expires = date('Y-m-d H:i:s', time() + 2 * 3600);
  db()->prepare('UPDATE users SET reset_token = ?, reset_expires = ? WHERE id = ?')
      ->execute([$token, $expires, $u['id']]);

  $c    = config();
  $link = rtrim($c['app_url'] ?? 'https://bookingroster.it', '/') . '/reset-password.html?token=' . $token;
  $name = trim($u['display_name'] ?? '') ?: 'ciao';

  $body = mail_layout('Reimposta la tua password',
      '<p style="margin:0">Ciao ' . htmlspecialchars($name) . ',<br>hai richiesto di reimpostare la password del tuo account Booking Roster.</p>'
    . mail_cta($link, 'Reimposta password')
    . '<p style="font-size:13px;line-height:1.6;color:#9a9aa2;margin:0">Il link scade tra 2 ore. Se non sei stato tu, ignora questa email.</p>'
    . '<p style="font-size:12px;color:#c9c9c9;word-break:break-all;margin:10px 0 0">' . htmlspecialchars($link) . '</p>');

  @send_mail($email, 'Reimposta la password · Booking Roster', $body);
}

ok(['sent' => true]);
