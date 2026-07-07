<?php
/**
 * GET /api/agencies-list.php — elenco pubblico delle agenzie (role=management) con foto profilo,
 * usato per il carosello "agenzie" in home. Nessun dato sensibile.
 */
require_once __DIR__ . '/_http.php';
ensure_promoter_ig_cols();

$st = db()->query(
  "SELECT u.id, COALESCE(p.org_name, u.display_name) AS org_name, p.photo_url
     FROM users u JOIN promoter_profiles p ON p.user_id = u.id
    WHERE u.role = 'management' AND u.status = 'active' AND p.photo_url IS NOT NULL
      AND EXISTS (SELECT 1 FROM artist_profiles a WHERE a.manager_user_id = u.id AND a.published = 1)
    ORDER BY u.id DESC"
);
ok(['agencies' => $st->fetchAll()]);
