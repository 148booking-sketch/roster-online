<?php
/**
 * Invio email semplice via mail() nativa (hosting DirectAdmin).
 * HTML minimale con mittente da config. Ritorna true/false.
 */
require_once __DIR__ . '/_db.php';

function send_mail(string $to, string $subject, string $htmlBody): bool {
  $c = config();
  $from     = $c['mail_from']      ?? 'noreply@bookingroster.it';
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

/** Invia l'email di verifica con il link che attiva l'account (design 13a). */
function send_verification_email(string $email, string $name, string $token): bool {
  $c = config();
  $link = rtrim($c['app_url'] ?? 'https://bookingroster.it', '/') . '/api/verify-email.php?token=' . $token;
  $name = trim($name) ?: 'Ciao';
  $body = mail_layout('Conferma il tuo indirizzo email',
      '<p style="margin:0">Ciao ' . htmlspecialchars($name) . ',<br>grazie per esserti registrato su Booking Roster. Conferma la tua email per attivare l\'account.</p>'
    . mail_cta($link, 'Conferma email')
    . '<p style="font-size:13px;line-height:1.6;color:#9a9aa2;margin:0">Se non hai creato tu questo account, ignora questa email.</p>'
    . '<p style="font-size:12px;color:#c9c9c9;word-break:break-all;margin:10px 0 0">' . htmlspecialchars($link) . '</p>');
  return @send_mail($email, 'Conferma il tuo indirizzo email · Booking Roster', $body);
}

/**
 * Layout email ufficiale (design system "Email di sistema"): barra brand 4px in alto,
 * logo centrato, titolo display, contenuto, footer grigio con dati societari e link.
 * $footerHtml opzionale si aggiunge nel footer (es. link disiscrizione digest).
 */
function mail_layout(string $title, string $bodyHtml, string $footerHtml = ''): string {
  $appUrl = rtrim(config()['app_url'] ?? 'https://bookingroster.it', '/');
  $fDisplay = "'Space Grotesk',Arial,sans-serif";
  $fBody    = "'Inter',Arial,sans-serif";
  return '<div style="background:#f2f2f2;padding:24px 12px">'
    . '<div style="max-width:560px;margin:0 auto;background:#ffffff;border-radius:16px;overflow:hidden;border:1px solid #ebebeb">'
    .   '<div style="height:4px;background:#d52454"></div>'
    .   '<div style="padding:34px 44px 0;text-align:center"><div style="font:700 22px ' . $fDisplay . ';color:#d52454;letter-spacing:-.5px">Booking<span style="color:#222222"> Roster</span></div></div>'
    .   '<div style="padding:28px 44px 36px">'
    .     '<div style="font:700 22px ' . $fDisplay . ';letter-spacing:-.4px;color:#1a1c22">' . htmlspecialchars($title) . '</div>'
    .     '<div style="font-family:' . $fBody . ';font-size:15px;line-height:1.6;color:#444444;margin-top:14px">' . $bodyHtml . '</div>'
    .   '</div>'
    .   '<div style="padding:20px 44px;border-top:1px solid #ebebeb;background:#fafafa;font-family:' . $fBody . ';font-size:12px;color:#9a9aa2;line-height:1.6">'
    .     'Booking Roster · SHADE-OFF S.R.L.S. · Latina, Lazio<br>'
    .     '<a href="' . $appUrl . '/contatti.html" style="color:#b81e47;text-decoration:none">Centro assistenza</a> · '
    .     '<a href="' . $appUrl . '/account.html" style="color:#b81e47;text-decoration:none">Preferenze email</a>'
    .     ($footerHtml !== '' ? '<br>' . $footerHtml : '')
    .   '</div>'
    . '</div></div>';
}

/* ============================================================
   EMAIL TRANSAZIONALI RICHIESTE DI BOOKING (design "Email di sistema")
   Tutte best-effort: mai bloccare la risposta HTTP se l'invio fallisce.
   ============================================================ */

/** Bottone CTA standard per le email (rosa brand, centrato — design 13). */
function mail_cta(string $href, string $label): string {
  return '<div style="text-align:center;margin:26px 0"><a href="' . htmlspecialchars($href) . '" '
    . 'style="display:inline-block;font:600 15px \'Inter\',Arial,sans-serif;color:#ffffff;background:#d52454;border-radius:11px;padding:14px 34px;text-decoration:none">'
    . htmlspecialchars($label) . '</a></div>';
}

/** Nuova richiesta di booking → email all'artista. */
function notify_new_booking_request(int $artistUserId, array $req): void {
  try {
    $st = db()->prepare('SELECT u.email, ap.stage_name FROM users u JOIN artist_profiles ap ON ap.user_id = u.id WHERE u.id = ?');
    $st->execute([$artistUserId]);
    $a = $st->fetch(); if (!$a) return;
    $appUrl = rtrim(config()['app_url'] ?? 'https://bookingroster.it', '/');
    $when = !empty($req['event_date']) ? date('d/m/Y', strtotime($req['event_date'])) : 'da concordare';
    $fee  = isset($req['proposed_fee']) && $req['proposed_fee'] !== null ? '€' . number_format((int)$req['proposed_fee'], 0, ',', '.') : 'da concordare';
    $body = mail_layout('Nuova richiesta di booking',
        '<p>' . htmlspecialchars($a['stage_name'] ?: 'Ciao') . ', <b>' . htmlspecialchars($req['promoter_name'] ?? 'un promoter') . '</b> vuole scritturarti!</p>'
      . '<p style="font-size:14px;color:#444">Data: <b>' . htmlspecialchars($when) . '</b><br>Offerta: <b>' . htmlspecialchars($fee) . '</b></p>'
      . (!empty($req['message']) ? '<p style="font-size:14px;color:#555;background:#f7f7f7;border-radius:10px;padding:12px 14px">&ldquo;' . nl2br(htmlspecialchars(mb_substr($req['message'], 0, 500))) . '&rdquo;</p>' : '')
      . mail_cta($appUrl . '/richieste.html', 'Rispondi alla richiesta'));
    @send_mail($a['email'], 'Nuova richiesta di booking · Booking Roster', $body);
  } catch (Throwable $e) { /* best-effort */ }
}

/** L'artista ha risposto (accettata/rifiutata) → email al promoter. */
function notify_booking_response(int $requestId, string $status): void {
  if (!in_array($status, ['accettata', 'rifiutata'], true)) return;
  try {
    $st = db()->prepare(
      'SELECT br.event_date, up.email AS promoter_email, ap.stage_name
       FROM booking_requests br
       JOIN users up ON up.id = br.promoter_user_id
       JOIN artist_profiles ap ON ap.user_id = br.artist_user_id
       WHERE br.id = ?');
    $st->execute([$requestId]);
    $r = $st->fetch(); if (!$r) return;
    $appUrl = rtrim(config()['app_url'] ?? 'https://bookingroster.it', '/');
    $when = $r['event_date'] ? ' per il ' . date('d/m/Y', strtotime($r['event_date'])) : '';
    if ($status === 'accettata') {
      $subject = $r['stage_name'] . ' ha accettato la tua richiesta';
      $intro = '<p><b>' . htmlspecialchars($r['stage_name']) . '</b> ha <b style="color:#0a7d38">accettato</b> la tua richiesta' . $when . '! Ora potete accordarvi sui dettagli.</p>';
    } else {
      $subject = 'La tua richiesta non è andata a buon fine';
      $intro = '<p><b>' . htmlspecialchars($r['stage_name']) . '</b> non ha potuto accettare la tua richiesta' . $when . '. Nel roster ci sono tanti altri artisti disponibili!</p>';
    }
    $body = mail_layout($status === 'accettata' ? 'Richiesta accettata' : 'Richiesta non accolta',
      $intro . mail_cta($appUrl . ($status === 'accettata' ? '/richieste.html' : '/'), $status === 'accettata' ? 'Vedi la richiesta' : 'Cerca altri artisti'));
    @send_mail($r['promoter_email'], $subject . ' · Booking Roster', $body);
  } catch (Throwable $e) { /* best-effort */ }
}

/** Profilo approvato/pubblicato per la prima volta → email all'artista. */
function notify_artist_published(int $artistUserId): void {
  try {
    $st = db()->prepare('SELECT u.email, ap.stage_name, ap.slug FROM users u JOIN artist_profiles ap ON ap.user_id = u.id WHERE u.id = ?');
    $st->execute([$artistUserId]);
    $a = $st->fetch(); if (!$a) return;
    $appUrl = rtrim(config()['app_url'] ?? 'https://bookingroster.it', '/');
    $link = $appUrl . '/' . rawurlencode($a['slug'] ?: '');
    $body = mail_layout('Il tuo profilo è online!',
        '<p>' . htmlspecialchars($a['stage_name'] ?: 'Ciao') . ', il tuo profilo è stato approvato ed è ora <b>visibile ai promoter</b> nella ricerca di Booking Roster.</p>'
      . '<p style="font-size:14px;color:#555">Tieni aggiornati calendario e cachet: i profili completi ricevono più richieste.</p>'
      . mail_cta($link, 'Vedi il tuo profilo pubblico'));
    @send_mail($a['email'], 'Il tuo profilo è online · Booking Roster', $body);
  } catch (Throwable $e) { /* best-effort */ }
}

/** Benvenuto post-verifica per promoter/agenzie. */
function notify_promoter_welcome(string $email, string $name): void {
  try {
    $appUrl = rtrim(config()['app_url'] ?? 'https://bookingroster.it', '/');
    $body = mail_layout('Benvenuto su Booking Roster',
        '<p>' . htmlspecialchars($name ?: 'Ciao') . ', il tuo account è attivo! Ecco come funziona:</p>'
      . '<p style="font-size:14px;color:#444">1. <b>Cerca</b> l\'artista giusto per il tuo evento (filtri per genere, zona, budget).<br>'
      . '2. <b>Salva i preferiti</b> e tieni d\'occhio disponibilità e promo.<br>'
      . '3. <b>Invia la richiesta</b> con data e offerta: l\'artista ti risponde qui.</p>'
      . '<p style="font-size:13px;color:#777">I cachet diventano visibili dopo l\'approvazione del tuo account da parte dello staff.</p>'
      . mail_cta($appUrl . '/', 'Inizia a cercare artisti'));
    @send_mail($email, 'Benvenuto su Booking Roster', $body);
  } catch (Throwable $e) { /* best-effort */ }
}

/** Nuovo messaggio nel thread di una richiesta → email alla controparte. */
function notify_new_message(int $requestId, int $senderUserId): void {
  try {
    $st = db()->prepare(
      'SELECT br.promoter_user_id, br.artist_user_id, ap.stage_name,
              up.email AS promoter_email, up.display_name AS promoter_name, ua.email AS artist_email
       FROM booking_requests br
       JOIN users up ON up.id = br.promoter_user_id
       JOIN users ua ON ua.id = br.artist_user_id
       JOIN artist_profiles ap ON ap.user_id = br.artist_user_id
       WHERE br.id = ?');
    $st->execute([$requestId]);
    $r = $st->fetch(); if (!$r) return;
    $senderIsArtist = $senderUserId === (int)$r['artist_user_id'];
    $to   = $senderIsArtist ? $r['promoter_email'] : $r['artist_email'];
    $from = $senderIsArtist ? $r['stage_name'] : ($r['promoter_name'] ?: 'Il promoter');
    $appUrl = rtrim(config()['app_url'] ?? 'https://bookingroster.it', '/');
    $body = mail_layout('Nuovo messaggio',
        '<p><b>' . htmlspecialchars($from) . '</b> ti ha scritto un messaggio sulla richiesta di booking.</p>'
      . mail_cta($appUrl . '/richieste.html', 'Leggi e rispondi'));
    @send_mail($to, 'Nuovo messaggio da ' . $from . ' · Booking Roster', $body);
  } catch (Throwable $e) { /* best-effort */ }
}

/**
 * Promemoria evento: 3 giorni prima della data, email a artista e promoter per ogni
 * richiesta ACCETTATA. Deduplica con la tabella booking_reminders (auto-creata al primo
 * uso, stessa strategia di ensure_favorites_table). Sicuro da richiamare più volte al giorno.
 * Ritorna il numero di promemoria inviati.
 */
function send_event_reminders(): int {
  try {
    db()->exec("CREATE TABLE IF NOT EXISTS booking_reminders (
      request_id INT UNSIGNED NOT NULL,
      sent_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
      PRIMARY KEY (request_id),
      CONSTRAINT fk_rem_req FOREIGN KEY (request_id) REFERENCES booking_requests(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
  } catch (Throwable $e) { return 0; }

  $sent = 0;
  try {
    $st = db()->query(
      "SELECT br.id, br.event_date, br.proposed_fee,
              ua.email AS artist_email, ap.stage_name,
              up.email AS promoter_email, up.display_name AS promoter_name, pp.org_name
       FROM booking_requests br
       JOIN users ua ON ua.id = br.artist_user_id
       JOIN artist_profiles ap ON ap.user_id = br.artist_user_id
       JOIN users up ON up.id = br.promoter_user_id
       LEFT JOIN promoter_profiles pp ON pp.user_id = br.promoter_user_id
       LEFT JOIN booking_reminders rem ON rem.request_id = br.id
       WHERE br.status = 'accettata' AND rem.request_id IS NULL
         AND br.event_date = DATE_ADD(CURDATE(), INTERVAL 3 DAY)");
    $appUrl = rtrim(config()['app_url'] ?? 'https://bookingroster.it', '/');
    foreach ($st->fetchAll() as $r) {
      $when = date('d/m/Y', strtotime($r['event_date']));
      $org  = $r['org_name'] ?: $r['promoter_name'] ?: 'il promoter';
      $bodyA = mail_layout('Promemoria evento',
          '<p>' . htmlspecialchars($r['stage_name']) . ', tra 3 giorni (<b>' . $when . '</b>) hai l\'evento concordato con <b>' . htmlspecialchars($org) . '</b>.</p>'
        . mail_cta($appUrl . '/richieste.html', 'Rivedi i dettagli'));
      $bodyP = mail_layout('Promemoria evento',
          '<p>Tra 3 giorni (<b>' . $when . '</b>) c\'è l\'evento con <b>' . htmlspecialchars($r['stage_name']) . '</b> che hai concordato su Booking Roster.</p>'
        . mail_cta($appUrl . '/richieste.html', 'Rivedi i dettagli'));
      @send_mail($r['artist_email'],   'Promemoria: evento il ' . $when . ' · Booking Roster', $bodyA);
      @send_mail($r['promoter_email'], 'Promemoria: evento il ' . $when . ' · Booking Roster', $bodyP);
      db()->prepare('INSERT IGNORE INTO booking_reminders (request_id) VALUES (?)')->execute([$r['id']]);
      $sent++;
    }
  } catch (Throwable $e) { /* best-effort */ }
  return $sent;
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
 * Layout speciale del DIGEST (design 13e): header scuro con logo bianco + periodo,
 * KPI a 3 colonne, lista "Da non perdere", CTA rosa, footer con preferenze/disiscrizione.
 */
function digest_layout(string $periodLabel, array $kpi, string $itemsHtml, string $ctaHtml, string $footerHtml): string {
  $fDisplay = "'Space Grotesk',Arial,sans-serif";
  $fBody    = "'Inter',Arial,sans-serif";
  $kpiHtml = '';
  foreach ($kpi as [$n, $label, $color]) {
    $kpiHtml .= '<td style="padding:0 6px;width:33%"><div style="background:#fafafa;border-radius:12px;padding:14px;text-align:center">'
      . '<div style="font:700 24px ' . $fDisplay . ';color:' . $color . '">' . $n . '</div>'
      . '<div style="font-family:' . $fBody . ';font-size:11.5px;color:#717171">' . htmlspecialchars($label) . '</div></div></td>';
  }
  return '<div style="background:#f2f2f2;padding:24px 12px">'
    . '<div style="max-width:560px;margin:0 auto;background:#ffffff;border-radius:16px;overflow:hidden;border:1px solid #ebebeb">'
    .   '<div style="background:#17171b;padding:26px 44px">'
    .     '<div style="font:700 20px ' . $fDisplay . ';color:#ffffff;letter-spacing:-.5px">Booking<span style="color:#d52454"> Roster</span></div>'
    .     '<div style="font-family:' . $fBody . ';font-size:13px;color:#9a9aa2;margin-top:4px">Il tuo riepilogo · ' . htmlspecialchars($periodLabel) . '</div>'
    .   '</div>'
    .   '<div style="padding:26px 44px 32px;font-family:' . $fBody . '">'
    .     ($kpiHtml ? '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin-bottom:22px"><tr>' . $kpiHtml . '</tr></table>' : '')
    .     ($itemsHtml ? '<div style="font:700 12px ' . $fBody . ';text-transform:uppercase;letter-spacing:.05em;color:#9a9aa2;margin-bottom:10px">Da non perdere</div>' . $itemsHtml : '')
    .     $ctaHtml
    .   '</div>'
    .   '<div style="padding:20px 44px;border-top:1px solid #ebebeb;background:#fafafa;font-family:' . $fBody . ';font-size:12px;color:#9a9aa2;line-height:1.6">' . $footerHtml . '</div>'
    . '</div></div>';
}

/** Riga elemento del digest (avatar quadrato colorato + testo + valore a destra). */
function digest_item(string $mainHtml, string $rightHtml, string $grad = '#7c3aed,#d52454', bool $last = false): string {
  return '<div style="display:flex;align-items:center;gap:12px;padding:10px 0;' . ($last ? '' : 'border-bottom:1px solid #f2f2f2') . '">'
    . '<div style="width:38px;height:38px;border-radius:9px;background:linear-gradient(135deg,' . $grad . ');flex:none"></div>'
    . '<div style="flex:1;font-size:13.5px">' . $mainHtml . '</div>'
    . ($rightHtml !== '' ? '<span style="font-size:12.5px">' . $rightHtml . '</span>' : '')
    . '</div>';
}

/**
 * Invia il digest e ritorna true/false. $unsubToken → link di disiscrizione one-click
 * (se vuoto, il link viene omesso: caso email di test non legata a un account promoter).
 * $force = true → invia comunque anche se non c'è contenuto reale (per test manuali).
 */
function send_promoter_digest_email(string $email, string $name, array $data, string $freqLabel, string $unsubToken, bool $force = false): bool {
  $hasContent = !empty($data['new_artists']) || !empty($data['promo_artists']) || !empty($data['responded_requests']);
  if (!$hasContent && !$force) return false;

  $appUrl = rtrim(config()['app_url'] ?? 'https://bookingroster.it', '/');
  $grads = ['#7c3aed,#d52454', '#059669,#0d9488', '#f43f5e,#f59e0b', '#0ea5e9,#6366f1', '#8b5cf6,#ec4899'];
  $g = 0; $items = []; $esc = fn($s) => htmlspecialchars((string)$s);

  // Risposte alle richieste per primi (gli eventi più importanti)
  foreach (array_slice($data['responded_requests'] ?? [], 0, 4) as $r) {
    $okR = $r['status'] === 'accettata';
    $items[] = digest_item(
      '<b>' . $esc($r['stage_name']) . '</b> ha ' . ($okR ? 'accettato' : 'rifiutato') . ' la tua richiesta'
        . ($r['event_date'] ? ' per il ' . date('d/m/Y', strtotime($r['event_date'])) : ''),
      $okR ? '<span style="color:#0a7d38;font-weight:700">✓</span>' : '',
      $grads[$g++ % 5]);
  }
  foreach (array_slice($data['new_artists'] ?? [], 0, 5) as $a) {
    $loc = trim(($a['comune'] ?? '') . (!empty($a['provincia']) ? ' (' . $a['provincia'] . ')' : ''));
    $items[] = digest_item(
      '<a href="' . $esc($appUrl . '/' . rawurlencode($a['slug'])) . '" style="color:#1a1c22;text-decoration:none"><b>' . $esc($a['stage_name']) . '</b></a> è nuovo nel roster'
        . (!empty($a['genre']) ? ' · ' . $esc($a['genre']) : ''),
      $loc !== '' ? '<span style="color:#717171">' . $esc($loc) . '</span>' : '',
      $grads[$g++ % 5]);
  }
  foreach (array_slice($data['promo_artists'] ?? [], 0, 4) as $a) {
    $until = !empty($a['promo_until']) ? ' fino al ' . date('d/m/Y', strtotime($a['promo_until'])) : '';
    $items[] = digest_item(
      '<a href="' . $esc($appUrl . '/' . rawurlencode($a['slug'])) . '" style="color:#1a1c22;text-decoration:none"><b>' . $esc($a['stage_name']) . '</b></a> è in promo' . $until,
      '<span style="color:#c0395e;font-weight:700">€' . number_format((int)$a['cachet_promo'], 0, ',', '.') . '</span>',
      $grads[$g++ % 5]);
  }
  if (!$items && $force) $items[] = '<p style="color:#9a9aa2;font-size:13px">(Nessuna novità reale in questo momento — email di test.)</p>';
  if ($items) { $last = array_pop($items); $items[] = str_replace('border-bottom:1px solid #f2f2f2', '', $last); }

  $kpi = [
    [count($data['responded_requests'] ?? []), 'Nuove risposte', '#1a1c22'],
    [count($data['new_artists'] ?? []), 'Nuovi artisti', '#0a7d38'],
    [count($data['promo_artists'] ?? []), 'Artisti in promo', '#d52454'],
  ];
  $cta = '<div style="text-align:center;margin:24px 0 0"><a href="' . $appUrl . '/" style="display:inline-block;font:600 15px \'Inter\',Arial,sans-serif;color:#ffffff;background:#d52454;border-radius:11px;padding:13px 30px;text-decoration:none">Cerca artisti</a></div>';

  $footer = 'Ricevi questo riepilogo <b>' . htmlspecialchars($freqLabel) . '</b> perché hai attivato gli alert su Booking Roster.<br>'
    . '<a href="' . $esc($appUrl . '/account.html') . '" style="color:#b81e47;text-decoration:none">Preferenze email</a>';
  if ($unsubToken !== '') {
    $footer .= ' · <a href="' . $esc($appUrl . '/api/promoter-unsubscribe.php?token=' . urlencode($unsubToken)) . '" style="color:#b81e47;text-decoration:none">Disiscriviti</a>';
  }

  $html = digest_layout('riepilogo ' . $freqLabel, $kpi, implode('', $items), $cta, $footer);
  return send_mail($email, 'Novità su Booking Roster · il tuo riepilogo ' . $freqLabel, $html);
}
