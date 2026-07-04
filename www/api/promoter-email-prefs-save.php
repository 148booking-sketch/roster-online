<?php
/**
 * POST /api/promoter-email-prefs-save.php   (solo promoter loggato)
 * Body: { freq: "off"|"daily"|"weekly"|"monthly", consent: bool }
 * Attivare un digest richiede consenso esplicito (consent=true): senza consenso non si può
 * impostare una frequenza diversa da "off". Il consenso si registra con data/ora (audit).
 */
require_once __DIR__ . '/_http.php';
require_once __DIR__ . '/_mail.php';
only('POST');

$u  = require_user('promoter');
$in = body();

$freq    = $in['freq'] ?? '';
$consent = !empty($in['consent']);

if (!in_array($freq, ['off', 'daily', 'weekly', 'monthly'], true)) fail('freq_invalid');
if ($freq !== 'off' && !$consent) fail('consent_required');

ensure_promoter_unsub_token((int) $u['id']);

if ($freq === 'off') {
  db()->prepare('UPDATE promoter_profiles SET email_freq = ? WHERE user_id = ?')->execute(['off', $u['id']]);
} else {
  db()->prepare('UPDATE promoter_profiles SET email_freq = ?, email_consent_at = NOW() WHERE user_id = ?')
      ->execute([$freq, $u['id']]);
}

ok(['freq' => $freq]);
