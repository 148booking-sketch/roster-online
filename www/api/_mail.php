<?php
/**
 * Invio email semplice via mail() nativa (hosting DirectAdmin).
 * HTML minimale con mittente da config. Ritorna true/false.
 */
require_once __DIR__ . '/_db.php';

function send_mail(string $to, string $subject, string $htmlBody): bool {
  $c = config();
  $from     = $c['mail_from']      ?? 'noreply@148booking.it';
  $fromName = $c['mail_from_name'] ?? 'Booking Roster';

  $headers = implode("\r\n", [
    'MIME-Version: 1.0',
    'Content-Type: text/html; charset=utf-8',
    'From: ' . mb_encode_mimeheader($fromName) . ' <' . $from . '>',
    'Reply-To: ' . $from,
    'X-Mailer: BookingRoster',
  ]);
  $subjectEnc = '=?UTF-8?B?' . base64_encode($subject) . '?=';

  return @mail($to, $subjectEnc, $htmlBody, $headers);
}

/** Invia l'email di verifica con il link che attiva l'account. */
function send_verification_email(string $email, string $name, string $token): bool {
  $c = config();
  $link = rtrim($c['app_url'] ?? 'https://bookingroster.it', '/') . '/api/verify-email.php?token=' . $token;
  $name = trim($name) ?: 'ciao';
  $body = mail_layout('Verifica la tua email',
      '<p>' . htmlspecialchars($name) . ', benvenuto in Booking Roster! Conferma la tua email per attivare l\'account.</p>'
    . '<p style="margin:20px 0"><a href="' . htmlspecialchars($link) . '" '
    . 'style="background:#1a1c22;color:#fff;padding:12px 20px;border-radius:10px;text-decoration:none;display:inline-block">Verifica email</a></p>'
    . '<p style="font-size:13px;color:#777">Se non hai creato tu questo account, ignora questa email.</p>'
    . '<p style="font-size:12px;color:#999;word-break:break-all">' . htmlspecialchars($link) . '</p>');
  return @send_mail($email, 'Verifica la tua email · Booking Roster', $body);
}

/** Layout email di base per Booking Roster. $footerHtml opzionale (es. link disiscrizione). */
function mail_layout(string $title, string $bodyHtml, string $footerHtml = ''): string {
  $c = config();
  $app = $c['app_name'] ?? 'Booking Roster';
  return '<div style="font-family:Inter,Arial,sans-serif;max-width:520px;margin:0 auto;color:#1a1c22">'
    . '<div style="font-size:20px;font-weight:700;margin-bottom:16px">' . htmlspecialchars($app) . '</div>'
    . '<h2 style="font-size:18px;margin:0 0 12px">' . htmlspecialchars($title) . '</h2>'
    . '<div style="font-size:15px;line-height:1.55;color:#333">' . $bodyHtml . '</div>'
    . '<hr style="border:none;border-top:1px solid #eee;margin:22px 0">'
    . '<div style="font-size:12px;color:#999">' . htmlspecialchars($app) . ' · bookingroster.it</div>'
    . ($footerHtml !== '' ? '<div style="font-size:12px;color:#999;margin-top:6px">' . $footerHtml . '</div>' : '')
    . '</div>';
}

/** Genera (se manca) e ritorna il token di disiscrizione one-click del promoter. */
function ensure_promoter_unsub_token(int $userId): string {
  $st = db()->prepare('SELECT email_unsub_token FROM promoter_profiles WHERE user_id = ?');
  $st->execute([$userId]);
  $tok = $st->fetchColumn();
  if ($tok) return $tok;
  $tok = bin2hex(random_bytes(32));
  db()->prepare('UPDATE promoter_profiles SET email_unsub_token = ? WHERE user_id = ?')->execute([$tok, $userId]);
  return $tok;
}

/**
 * Digest email per i promoter: nuovi artisti pubblicati, artisti in promo attivi,
 * richieste di booking a cui l'artista ha risposto. $data = [
 *   'new_artists' => [ {stage_name, slug, comune, genre}, ... ],
 *   'promo_artists' => [ {stage_name, slug, comune, cachet_promo, promo_until}, ... ],
 *   'responded_requests' => [ {stage_name, status, event_date}, ... ],
 * ]
 * Ritorna null se non c'è nulla da mandare (il chiamante decide di non inviare).
 */
function build_promoter_digest_html(array $data, string $freqLabel, string $name = '', bool $force = false): ?string {
  $hasContent = !empty($data['new_artists']) || !empty($data['promo_artists']) || !empty($data['responded_requests']);
  if (!$hasContent && !$force) return null;

  $appUrl = rtrim(config()['app_url'] ?? 'https://bookingroster.it', '/');
  $artistLink = fn($slug) => $appUrl . '/' . rawurlencode($slug);

  $sections = '';

  if (!empty($data['new_artists'])) {
    $items = '';
    foreach ($data['new_artists'] as $a) {
      $loc = trim(($a['comune'] ?? '') . ($a['provincia'] ? ' (' . $a['provincia'] . ')' : ''));
      $items .= '<li style="margin-bottom:6px"><a href="' . htmlspecialchars($artistLink($a['slug'])) . '" style="color:#d52454;text-decoration:none;font-weight:600">'
        . htmlspecialchars($a['stage_name']) . '</a>'
        . ($a['genre'] ? ' · ' . htmlspecialchars($a['genre']) : '')
        . ($loc !== '' ? ' · ' . htmlspecialchars($loc) : '') . '</li>';
    }
    $sections .= '<h3 style="font-size:15px;margin:18px 0 8px">🆕 Nuovi artisti in roster</h3><ul style="margin:0;padding-left:18px">' . $items . '</ul>';
  }

  if (!empty($data['promo_artists'])) {
    $items = '';
    foreach ($data['promo_artists'] as $a) {
      $until = $a['promo_until'] ? ' (fino al ' . date('d/m/Y', strtotime($a['promo_until'])) . ')' : '';
      $items .= '<li style="margin-bottom:6px"><a href="' . htmlspecialchars($artistLink($a['slug'])) . '" style="color:#d52454;text-decoration:none;font-weight:600">'
        . htmlspecialchars($a['stage_name']) . '</a> · cachet promo ' . (int)$a['cachet_promo'] . '€' . $until . '</li>';
    }
    $sections .= '<h3 style="font-size:15px;margin:18px 0 8px">🔥 Artisti in promo</h3><ul style="margin:0;padding-left:18px">' . $items . '</ul>';
  }

  if (!empty($data['responded_requests'])) {
    $items = '';
    foreach ($data['responded_requests'] as $r) {
      $label = $r['status'] === 'accettata' ? '✅ ha accettato' : '❌ ha rifiutato';
      $items .= '<li style="margin-bottom:6px"><b>' . htmlspecialchars($r['stage_name']) . '</b> ' . $label . ' la tua richiesta'
        . ($r['event_date'] ? ' per il ' . date('d/m/Y', strtotime($r['event_date'])) : '') . '</li>';
    }
    $sections .= '<h3 style="font-size:15px;margin:18px 0 8px">📩 Risposte alle tue richieste</h3><ul style="margin:0;padding-left:18px">' . $items . '</ul>';
  }

  if ($sections === '' && $force) {
    $sections = '<p style="color:#777;font-size:13px">(Nessuna novità reale in questo momento — email di test.)</p>';
  }

  $greet = $name !== '' ? htmlspecialchars($name) : 'Ciao';
  return '<p>' . $greet . ', ecco il riepilogo ' . htmlspecialchars($freqLabel) . ' pensato per te:</p>' . $sections;
}

/**
 * Invia il digest e ritorna true/false. $unsubToken → link di disiscrizione one-click
 * (se vuoto, il link viene omesso: caso email di test non legata a un account promoter).
 * $force = true → invia comunque anche se non c'è contenuto reale (per test manuali).
 */
function send_promoter_digest_email(string $email, string $name, array $data, string $freqLabel, string $unsubToken, bool $force = false): bool {
  $body = build_promoter_digest_html($data, $freqLabel, $name, $force);
  if ($body === null) return false;

  $appUrl = rtrim(config()['app_url'] ?? 'https://bookingroster.it', '/');
  $prefsLink = $appUrl . '/account.html';

  $footer = 'Ricevi questa email perché hai attivato gli alert ' . htmlspecialchars($freqLabel) . ' su Booking Roster. '
    . '<a href="' . htmlspecialchars($prefsLink) . '" style="color:#999">Gestisci preferenze</a>';
  if ($unsubToken !== '') {
    $unsubLink = $appUrl . '/api/promoter-unsubscribe.php?token=' . urlencode($unsubToken);
    $footer .= ' · <a href="' . htmlspecialchars($unsubLink) . '" style="color:#999">Disiscriviti</a>';
  }

  $html = mail_layout('Novità per te su Booking Roster', $body, $footer);
  return send_mail($email, 'Novità su Booking Roster · nuovi artisti e promo', $html);
}
