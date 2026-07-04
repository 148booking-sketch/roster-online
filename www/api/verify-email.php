<?php
/**
 * GET /api/verify-email.php?token=...
 * Attiva l'account (email_verified=1), effettua il login e reindirizza:
 *   artista  → /profilo.html (completa il profilo)
 *   promoter → / (cerca artisti)
 * Link cliccabile dall'email di verifica.
 */
require_once __DIR__ . '/_http.php';

$app   = rtrim(config()['app_url'] ?? 'https://artisti.148booking.it', '/');
$token = trim($_GET['token'] ?? '');
$redir = function (string $path) use ($app) { header('Location: ' . $app . $path); exit; };

if ($token === '') $redir('/accedi.html?verify=fail');

$st = db()->prepare('SELECT id, role FROM users WHERE verify_token = ?');
$st->execute([$token]);
$u = $st->fetch();
if (!$u) $redir('/accedi.html?verify=fail');

db()->prepare('UPDATE users SET email_verified = 1, verify_token = NULL WHERE id = ?')->execute([$u['id']]);
login_user((int)$u['id']);

$redir($u['role'] === 'artist' ? '/profilo.html?welcome=1' : '/?welcome=1');
