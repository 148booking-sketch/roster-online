<?php
/**
 * Vocabolario attrezzatura (backline). Due liste: "cosa porta l'artista" e
 * "cosa serve sul posto", con una base condivisa. Replicate nel frontend
 * (profilo.html / admin.html / artista.html).
 */
function gear_options_base(): array {
  return [
    'Impianto audio (PA)', 'Mixer', 'Casse spia / Monitor', 'Microfoni con aste',
    'Batteria', 'Ampli basso', 'Ampli chitarra', 'Tastiera / Piano', 'Consolle DJ',
    'Luci',
  ];
}
/** Default pre-selezionati per "cosa serve sul posto" (nuovo artista). */
function gear_need_default(): array {
  return ['Palco', 'Luci', 'Impianto audio (PA)', 'Mixer', 'Casse spia / Monitor', 'Microfoni con aste'];
}
/** Tipi di show ammessi (slug). Le etichette sono nel frontend (api.js SHOW_LABEL). */
function show_types(): array {
  return ['live_dj', 'dj_set', 'acustico', 'live_band', 'meet_greet'];
}

/** Cosa porta l'artista. */
function gear_options_bring(): array {
  return array_merge(gear_options_base(), ['Backline completa', 'Auto Tune']);
}
/** Cosa deve esserci sul posto. */
function gear_options_need(): array {
  return array_merge(gear_options_base(), ['Palco', 'Video wall', 'In Ear Monitor']);
}

/**
 * Filtra un input (array di stringhe) tenendo solo le voci ammesse, senza duplicati.
 * $which = 'bring' | 'need' | 'both' (default: unione delle due liste).
 */
function gear_whitelist($input, string $which = 'both'): array {
  if (!is_array($input)) return [];
  $allowed = $which === 'bring' ? gear_options_bring()
           : ($which === 'need' ? gear_options_need()
           : array_values(array_unique(array_merge(gear_options_bring(), gear_options_need()))));
  $out = [];
  foreach ($input as $v) {
    $v = is_string($v) ? trim($v) : '';
    if ($v !== '' && in_array($v, $allowed, true) && !in_array($v, $out, true)) $out[] = $v;
  }
  return $out;
}
