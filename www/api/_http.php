<?php
/**
 * Helper HTTP/JSON + gestione sessione/auth condivisa.
 */
require_once __DIR__ . '/_db.php';

/**
 * Normalizza un URL inserito dall'utente (sito web, link personalizzati...):
 * se manca lo schema (es. "www.sito.it" o "sito.it") antepone "https://".
 * Ritorna null per stringa vuota.
 */
function normalize_url(string $url): ?string {
  $url = trim($url);
  if ($url === '') return null;
  if (!preg_match('#^https?://#i', $url)) $url = 'https://' . $url;
  return $url;
}

// Sessione stessa-origine (bookingroster.it). Cookie httpOnly.
function boot_session(): void {
  if (session_status() === PHP_SESSION_ACTIVE) return;
  $c = config();
  session_name($c['session_name'] ?? 'roster_sid');
  session_set_cookie_params([
    'lifetime' => 60 * 60 * 24 * 30,
    'path'     => '/',
    'httponly' => true,
    'samesite' => 'Lax',
    'secure'   => (($_SERVER['HTTPS'] ?? '') === 'on'),
  ]);
  session_start();
}

/** Legge il body JSON della richiesta come array. */
function body(): array {
  $raw = file_get_contents('php://input');
  $d = json_decode($raw ?: '[]', true);
  return is_array($d) ? $d : [];
}

/** Risposta JSON e stop. */
function out($data, int $code = 200): void {
  http_response_code($code);
  header('Content-Type: application/json; charset=utf-8');
  echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  exit;
}

function fail(string $error, int $code = 400, array $extra = []): void {
  out(array_merge(['ok' => false, 'error' => $error], $extra), $code);
}

function ok(array $data = []): void {
  out(array_merge(['ok' => true], $data));
}

/** Solo metodo consentito, altrimenti 405. */
function only(string $method): void {
  if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== $method) fail('method_not_allowed', 405);
}

/** Utente loggato (array da users) o null. */
function current_user(): ?array {
  boot_session();
  if (empty($_SESSION['uid'])) return null;
  static $u = null;
  if ($u === null) {
    $st = db()->prepare('SELECT id, email, role, display_name, status, email_verified, admin_super FROM users WHERE id = ?');
    $st->execute([$_SESSION['uid']]);
    $u = $st->fetch() ?: null;
  }
  return $u;
}

/** Richiede login (+ ruolo opzionale), altrimenti 401/403. */
function require_user(?string $role = null): array {
  $u = current_user();
  if (!$u) fail('not_authenticated', 401);
  if ($u['status'] === 'blocked') fail('account_blocked', 403);
  if ($role && $u['role'] !== $role) fail('forbidden_role', 403);
  return $u;
}

function login_user(int $uid): void {
  boot_session();
  session_regenerate_id(true);
  $_SESSION['uid'] = $uid;
}

function logout_user(): void {
  boot_session();
  $_SESSION = [];
  session_destroy();
}

/**
 * Colonna trattativa_riservata (migration-23): ADD COLUMN additivo e idempotente,
 * auto-applicato al primo uso (stesso pattern di ensure_request_extras).
 * Se attiva, i prezzi (cachet/promo/viaggi) spariscono dalle viste pubbliche.
 */
function ensure_trattativa_col(): void {
  static $done = false; if ($done) return; $done = true;
  try {
    $c = db()->query("SHOW COLUMNS FROM artist_profiles LIKE 'trattativa_riservata'")->fetch();
    if (!$c) db()->exec("ALTER TABLE artist_profiles ADD COLUMN trattativa_riservata TINYINT(1) NOT NULL DEFAULT 0 AFTER cachet_trattabile");
  } catch (Throwable $e) { error_log('ensure_trattativa_col: ' . $e->getMessage()); }
}
