<?php
/**
 * Connessione PDO condivisa. Include questo file negli endpoint: `$pdo = db();`
 */
function config(): array {
  static $cfg = null;
  if ($cfg === null) {
    $path = __DIR__ . '/config.php';
    if (!is_file($path)) { http_response_code(500); exit('config.php mancante'); }
    $cfg = require $path;
  }
  return $cfg;
}

function db(): PDO {
  static $pdo = null;
  if ($pdo === null) {
    $c = config();
    $dsn = "mysql:host={$c['db_host']};dbname={$c['db_name']};charset={$c['db_charset']}";
    try {
      $pdo = new PDO($dsn, $c['db_user'], $c['db_pass'], [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
      ]);
    } catch (Throwable $e) {
      http_response_code(500);
      header('Content-Type: application/json; charset=utf-8');
      exit(json_encode(['ok' => false, 'error' => 'db_connection_failed']));
    }
  }
  return $pdo;
}
