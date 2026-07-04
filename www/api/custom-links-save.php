<?php
/**
 * POST /api/custom-links-save.php  (solo artisti loggati)
 * Salva fino a 3 link manuali {title,url} per la pagina "multi link" (link in bio).
 * Endpoint dedicato e separato da artist-save.php: gestito dalla scheda "Multi link"
 * in account.html, non dal form profilo.
 */
require_once __DIR__ . '/_http.php';
require_once __DIR__ . '/_social.php';
only('POST');

$u  = require_user('artist');
$in = body();

$links = custom_links_sanitize($in['custom_links'] ?? []);
$json  = $links ? json_encode($links, JSON_UNESCAPED_UNICODE) : null;

db()->prepare('UPDATE artist_profiles SET custom_links = ? WHERE user_id = ?')->execute([$json, $u['id']]);

ok(['saved' => true, 'custom_links' => $links]);
