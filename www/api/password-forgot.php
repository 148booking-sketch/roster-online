<?php
/**
 * POST /api/password-forgot.php   Body: { email }
 * Genera un token di reset (validità 2h) e invia l'email col link.
 * Risponde SEMPRE ok (non rivela se l'email esiste).
 */
require_once __DIR__ . '/_http.php';
require_once __DIR__ . '/_mail.php';
only('POST');

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
  $link = rtrim($c['app_url'] ?? 'https://artisti.148booking.it', '/') . '/reset-password.html?token=' . $token;
  $name = trim($u['display_name'] ?? '') ?: 'ciao';

  $body = mail_layout('Reimposta la tua password',
      '<p>' . htmlspecialchars($name) . ', hai richiesto di reimpostare la password del tuo account 148 Roster.</p>'
    . '<p style="margin:20px 0"><a href="' . htmlspecialchars($link) . '" '
    . 'style="background:#1a1c22;color:#fff;padding:12px 20px;border-radius:10px;text-decoration:none;display:inline-block">Reimposta password</a></p>'
    . '<p style="font-size:13px;color:#777">Il link scade tra 2 ore. Se non sei stato tu, ignora questa email.</p>'
    . '<p style="font-size:12px;color:#999;word-break:break-all">' . htmlspecialchars($link) . '</p>');

  @send_mail($email, 'Reimposta la password · 148 Roster', $body);
}

ok(['sent' => true]);
