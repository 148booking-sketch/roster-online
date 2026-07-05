<?php
/** GET /api/genres.php → generi, ordinati per numero di artisti pubblicati (i più usati prima) */
require_once __DIR__ . '/_http.php';

// Macro-categorie aggiunte 2026-07 (migration-22): auto-seed idempotente al primo uso,
// come le tabelle nuove. Un SELECT di guardia evita l'INSERT a ogni richiesta.
try {
  $has = db()->query("SELECT 1 FROM genres WHERE slug='latin' LIMIT 1")->fetch();
  if (!$has) {
    db()->exec("INSERT IGNORE INTO genres (slug, name) VALUES
      ('latin','Latin / Reggaeton'), ('rnb','R&B'), ('country','Country'),
      ('gospel','Gospel / Spiritual'), ('ambient','Ambient / Chill'), ('ska','Ska')");
  }
  $has2 = db()->query("SELECT 1 FROM genres WHERE slug='revival' LIMIT 1")->fetch();
  if (!$has2) { db()->exec("INSERT IGNORE INTO genres (slug, name) VALUES ('revival','Revival')"); }
} catch (Throwable $e) { error_log('genres seed 22/24: ' . $e->getMessage()); }

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
