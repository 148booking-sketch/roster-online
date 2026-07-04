<?php
/**
 * GET /api/management-artists.php   (solo booking/management ATTIVI)
 * Elenco degli artisti gestiti dal booking corrente (manager_user_id = me).
 */
require_once __DIR__ . '/_management.php';
$me = require_management();

$st = db()->prepare(
  "SELECT p.user_id AS id, p.stage_name, p.slug, p.comune, p.provincia, p.photo_url,
          p.formazione, p.cachet_min, p.cachet_max, p.published, p.verified, p.updated_at
     FROM artist_profiles p
    WHERE p.manager_user_id = ?
    ORDER BY p.stage_name ASC, p.user_id DESC"
);
$st->execute([(int) $me['id']]);
ok(['artists' => $st->fetchAll()]);
