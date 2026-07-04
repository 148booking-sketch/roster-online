<?php
/** GET /api/calendars-busy.php — artisti pubblicati con giorni occupati (calendario aggregato). */
require_once __DIR__ . '/_http.php';

$rows = db()->query(
  "SELECT user_id, stage_name, slug, photo_url, verified, calendar_busy
   FROM artist_profiles
   WHERE published = 1 AND calendar_busy IS NOT NULL AND calendar_busy != '[]'"
)->fetchAll();

$artists = [];
foreach ($rows as $r) {
  $busy = json_decode($r['calendar_busy'], true) ?: [];
  if (!$busy) continue;
  $artists[] = [
    'user_id'    => (int)$r['user_id'],
    'stage_name' => $r['stage_name'],
    'slug'       => $r['slug'],
    'photo_url'  => $r['photo_url'],
    'verified'   => (bool)$r['verified'],
    'busy'       => $busy,
  ];
}

ok(['artists' => $artists]);
