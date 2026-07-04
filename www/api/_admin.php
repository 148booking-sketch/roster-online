<?php
/**
 * Helper condivisi per l'area admin.
 * require_admin() → utente admin loggato, altrimenti 401/403.
 */
require_once __DIR__ . '/_http.php';

function require_admin(): array {
  $u = current_user();
  if (!$u) fail('not_authenticated', 401);
  if ($u['status'] === 'blocked') fail('account_blocked', 403);
  if ($u['role'] !== 'admin') fail('forbidden_admin', 403);
  return $u;
}

/** slug "nome-arte-<id>" coerente con artist-save.php */
function make_slug(string $name, int $id): ?string {
  if ($name === '') return null;
  $s = preg_replace('/[^a-z0-9]+/', '-', mb_strtolower($name)) . '-' . $id;
  return trim($s, '-') ?: null;
}

/** Email placeholder per un profilo "Verificato" gestito direttamente da 148 Booking
 *  (l'artista non ha bisogno di credenziali proprie). Usa l'alias +tag di Gmail: i messaggi
 *  arrivano comunque su 148booking@gmail.com, ma ogni artista ha un indirizzo univoco nel DB. */
function managed_email(string $stageName): string {
  $slug = trim(preg_replace('/[^a-z0-9]+/', '-', mb_strtolower($stageName)), '-') ?: 'artista';
  $suffix = substr(bin2hex(random_bytes(3)), 0, 5);
  return "148booking+{$slug}-{$suffix}@gmail.com";
}
/** Password casuale per un profilo gestito: non viene mai comunicata (l'artista non accede). */
function managed_password(): string {
  return bin2hex(random_bytes(16));
}

/**
 * Campi obbligatori per rendere pubblico (published=1) un profilo artista: senza questi
 * dati la scheda pubblica risulterebbe incompleta per un promoter. Ritorna le etichette
 * dei campi ancora mancanti (in italiano, ordine del form), o [] se il profilo è completo.
 * $row = riga di artist_profiles (eventualmente con 'socials' già decodificato) + 'genre_count'.
 */
function artist_publish_missing_fields(array $row): array {
  $socials = $row['socials'] ?? [];
  if (is_string($socials)) $socials = json_decode($socials, true) ?: [];
  $genreCount = $row['genre_count'] ?? (is_array($row['genres'] ?? null) ? count($row['genres']) : 0);
  $filled = fn($v) => trim((string)($v ?? '')) !== '';

  $checks = [
    'Bio'              => $filled($row['bio'] ?? null),
    'Calendario'       => $filled($row['calendar_url'] ?? null),
    'Comune'           => $filled($row['comune'] ?? null),
    'Provincia'        => $filled($row['provincia'] ?? null),
    'Telefono'         => $filled($row['phone'] ?? null),
    'Tipo di show'     => $filled($row['formazione'] ?? null),
    'On stage'         => $filled($row['componenti'] ?? null),
    'Generi'           => $genreCount > 0,
    'Spotify'          => $filled($socials['spotify'] ?? null),
    'Apple Music'      => $filled($socials['applemusic'] ?? null),
    'Instagram'        => $filled($socials['instagram'] ?? null),
    'Cachet a serata'  => $row['cachet_min'] !== null || $row['cachet_max'] !== null,
    'Cachet'           => $filled($row['cachet_trattabile'] ?? null),
    'Viaggi'           => $filled($row['rimborso_tipo'] ?? null),
    'Durata set'       => $filled($row['durata_set_min'] ?? null),
    'Scheda tecnica'   => $filled($row['tech_sheet_url'] ?? null),
  ];
  return array_keys(array_filter($checks, fn($ok) => !$ok));
}
