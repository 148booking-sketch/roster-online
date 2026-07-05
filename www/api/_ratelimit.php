<?php
/**
 * Rate limiter leggero, file-based (nessuna dipendenza da DB/estensioni).
 * Serve a frenare brute-force login, email-bombing (forgot/resend) e l'uso ripetuto
 * di calendar-check come motore SSRF/scanner. Chiave = azione + IP client.
 *
 * rate_limit('login', 10, 300)  → max 10 tentativi ogni 300s per IP; oltre → fail(429).
 * Best-effort: se la dir temp non è scrivibile non blocca (fail-open) per non rompere il sito.
 */

function client_ip(): string {
  // Dietro proxy/CDN si può leggere X-Forwarded-For, ma solo se il proxy è fidato:
  // in assenza di tale garanzia usiamo REMOTE_ADDR (non falsificabile dal client).
  return (string)($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
}

function rate_limit(string $action, int $max, int $windowSec): void {
  $dir = sys_get_temp_dir() . '/br_rl';
  if (!is_dir($dir)) @mkdir($dir, 0700, true);
  $key  = $action . '|' . client_ip();
  $file = $dir . '/' . hash('sha256', $key);
  $now  = time();

  $hits = [];
  if (is_file($file)) {
    $raw = @file_get_contents($file);
    if ($raw !== false) {
      foreach (explode("\n", trim($raw)) as $ts) {
        if ($ts !== '' && (int)$ts > $now - $windowSec) $hits[] = (int)$ts;
      }
    }
  }

  if (count($hits) >= $max) {
    fail('too_many_requests', 429, ['retry_after' => $windowSec]);
  }

  $hits[] = $now;
  @file_put_contents($file, implode("\n", $hits), LOCK_EX);
}
