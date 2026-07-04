<?php
/**
 * GET /api/admin-list-admins.php   (solo super admin)
 * Elenco degli account admin (super e ridotti).
 */
require_once __DIR__ . '/_admin.php';
require_super_admin();

$rows = db()->query(
  'SELECT id, email, display_name, status, admin_super, created_at, last_login
     FROM users WHERE role = "admin" ORDER BY admin_super DESC, id ASC'
)->fetchAll();

ok(['admins' => $rows]);
