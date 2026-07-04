<?php
/**
 * POST /api/admin-custom-links-save.php   (solo admin)
 * Body: { id, custom_links }
 * Salva fino a 3 link manuali {title,url} per la pagina "multi link" (link in bio) di un
 * artista, per conto dell'admin. Equivalente admin di custom-links-save.php (self-service).
 */
require_once __DIR__ . '/_admin.php';
require_once __DIR__ . '/_social.php';
only('POST');
require_admin();

$in = body();
$id = (int) ($in['id'] ?? 0);
if ($id <= 0) fail('id_required');

$st = db()->prepare('SELECT role FROM users WHERE id = ?');
$st->execute([$id]);
if ($st->fetchColumn() !== 'artist') fail('not_an_artist', 404);

$links = custom_links_sanitize($in['custom_links'] ?? []);
$json  = $links ? json_encode($links, JSON_UNESCAPED_UNICODE) : null;

db()->prepare('UPDATE artist_profiles SET custom_links = ? WHERE user_id = ?')->execute([$json, $id]);

ok(['saved' => true, 'custom_links' => $links]);
