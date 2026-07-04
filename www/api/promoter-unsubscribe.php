<?php
/**
 * GET /api/promoter-unsubscribe.php?token=...
 * Disiscrizione one-click dagli alert email (link cliccabile dall'email, nessun login richiesto).
 * Imposta email_freq='off' e reindirizza a /account.html con l'esito.
 */
require_once __DIR__ . '/_http.php';

$app   = rtrim(config()['app_url'] ?? 'https://artisti.148booking.it', '/');
$token = trim($_GET['token'] ?? '');
$redir = function (string $qs) use ($app) { header('Location: ' . $app . '/account.html?' . $qs); exit; };

if ($token === '') $redir('unsub=fail');

$st = db()->prepare('SELECT user_id FROM promoter_profiles WHERE email_unsub_token = ?');
$st->execute([$token]);
$uid = $st->fetchColumn();
if (!$uid) $redir('unsub=fail');

db()->prepare("UPDATE promoter_profiles SET email_freq = 'off' WHERE user_id = ?")->execute([$uid]);
$redir('unsub=ok');
