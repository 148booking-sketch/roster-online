<?php
/** GET /api/genres.php → generi, ordinati per numero di artisti pubblicati (i più usati prima) */
require_once __DIR__ . '/_http.php';
$rows = db()->query(
  "SELECT g.id, g.slug, g.name,
          (SELECT COUNT(*) FROM artist_genres ag
             JOIN artist_profiles ap ON ap.user_id = ag.artist_user_id
            WHERE ag.genre_id = g.id AND ap.published = 1) AS cnt
   FROM genres g
   ORDER BY cnt DESC, g.name ASC"
)->fetchAll();
foreach ($rows as &$r) $r['cnt'] = (int) $r['cnt'];
ok(['genres' => $rows]);
