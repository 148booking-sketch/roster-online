<?php
/**
 * Disponibilità artista da un calendario Google pubblico in formato iCal (.ics).
 * L'artista incolla il "Public/Secret address in iCal format" del suo calendario.
 * Parsiamo i VEVENT ed estraiamo le DATE OCCUPATE nei prossimi ~120 giorni.
 */
require_once __DIR__ . '/_social.php';   // http_get()

/** Normalizza una DTSTART/DTEND iCal in 'YYYY-MM-DD'. */
function ics_date(string $val): ?string {
  // formati: 20260715  |  20260715T190000Z  |  20260715T190000
  if (preg_match('/(\d{4})(\d{2})(\d{2})/', $val, $m)) return "$m[1]-$m[2]-$m[3]";
  return null;
}

/**
 * Ritorna array di date occupate ['YYYY-MM-DD', ...] dai prossimi $days giorni.
 * Gestisce eventi singoli (anche multi-giorno via DTEND) e RRULE FREQ=WEEKLY/DAILY semplici.
 */
function calendar_busy_dates(string $icsUrl, int $days = 120): array {
  if (!preg_match('#^https?://#i', $icsUrl)) return [];
  // Google accetta anche webcal:// → forziamo https
  $icsUrl = preg_replace('#^webcal://#i', 'https://', $icsUrl);
  $r = http_get($icsUrl, 12);
  if ($r['code'] !== 200 || stripos($r['body'], 'BEGIN:VCALENDAR') === false) return [];

  // unfolding: le righe iCal continuano se la successiva inizia con spazio/tab
  $raw = preg_replace("/\r\n[ \t]/", '', $r['body']);
  $lines = preg_split('/\r\n|\n|\r/', $raw);

  $today = strtotime('today');
  $limit = strtotime("+$days days", $today);
  $busy = [];

  $in = false; $dtstart = null; $dtend = null; $rrule = null;
  foreach ($lines as $ln) {
    if (strpos($ln, 'BEGIN:VEVENT') === 0) { $in = true; $dtstart = $dtend = $rrule = null; continue; }
    if (strpos($ln, 'END:VEVENT') === 0) {
      if ($dtstart) {
        $s = strtotime($dtstart);
        $e = $dtend ? strtotime($dtend) : $s;
        if ($e < $s) $e = $s;
        // eventi singoli / multi-giorno
        for ($d = $s; $d <= $e && $d <= $limit; $d += 86400) {
          if ($d >= $today) $busy[date('Y-m-d', $d)] = true;
        }
        // RRULE settimanale/giornaliero (approssimazione: espande fino al limite)
        if ($rrule && preg_match('/FREQ=(WEEKLY|DAILY)/', $rrule, $fm)) {
          $step = $fm[1] === 'DAILY' ? 86400 : 7 * 86400;
          $intv = (preg_match('/INTERVAL=(\d+)/', $rrule, $im) ? (int)$im[1] : 1) * $step;
          $until = (preg_match('/UNTIL=(\d{8})/', $rrule, $um)) ? strtotime($um[1]) : $limit;
          for ($d = $s; $d <= min($until, $limit); $d += $intv) {
            if ($d >= $today) $busy[date('Y-m-d', $d)] = true;
          }
        }
      }
      $in = false; continue;
    }
    if (!$in) continue;
    if (strpos($ln, 'DTSTART') === 0) { $dtstart = ics_date(substr($ln, strpos($ln, ':') + 1)); }
    elseif (strpos($ln, 'DTEND') === 0) {
      $d = ics_date(substr($ln, strpos($ln, ':') + 1));
      // DTEND all-day è esclusivo → togli un giorno
      if ($d && strpos($ln, 'VALUE=DATE') !== false) $d = date('Y-m-d', strtotime($d) - 86400);
      $dtend = $d;
    }
    elseif (strpos($ln, 'RRULE') === 0) { $rrule = substr($ln, strpos($ln, ':') + 1); }
  }

  $dates = array_keys($busy);
  sort($dates);
  return $dates;
}

/** Aggiorna e salva le date occupate di un artista. */
function refresh_artist_calendar(int $userId, ?string $icsUrl): array {
  $busy = ($icsUrl && trim($icsUrl) !== '') ? calendar_busy_dates(trim($icsUrl)) : [];
  db()->prepare('UPDATE artist_profiles SET calendar_busy = ?, calendar_updated_at = NOW() WHERE user_id = ?')
      ->execute([$busy ? json_encode($busy) : null, $userId]);
  return $busy;
}
