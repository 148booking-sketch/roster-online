<?php
/**
 * POST /api/management-custom-links-save.php   (solo booking/management ATTIVI)
 * Body: { id, custom_links }
 * Salva fino a 3 link manuali {title,url} per la pagina "multi link" di un artista
 * GESTITO dall'agenzia corrente. Equivalente management di admin-custom-links-save.php.
 */
require_once __DIR__ . '/_management.php';
require_once __DIR__ . '/_social.php';
only('POST');
$me = require_management();

$in = body();
$id = (int) ($in['id'] ?? 0);
require_managed_artist($id, (int) $me['id']);   // esiste ed è mio, altrimenti fail()

$links = custom_links_sanitize($in['custom_links'] ?? []);
$json  = $links ? json_encode($links, JSON_UNESCAPED_UNICODE) : null;

db()->prepare('UPDATE artist_profiles SET custom_links = ? WHERE user_id = ?')->execute([$json, $id]);

ok(['saved' => true, 'custom_links' => $links]);
