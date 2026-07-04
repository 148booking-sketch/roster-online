<?php
/**
 * POST /api/artist-eligibility-check.php   (pubblico, usato dal wizard di registrazione artista
 * e dalla dashboard booking per aggiungere un artista)
 * Body: { itunes }
 * Requisito minimo per candidarsi come artista su 148 Roster: almeno 4 brani pubblicati
 * (anche in feat./collaborazione) negli ultimi 2 anni, secondo il catalogo Apple Music/iTunes.
 * Usa la iTunes Search API pubblica (nessuna chiave/credenziale richiesta).
 */
require_once __DIR__ . '/_itunes.php';
only('POST');

$in  = body();
$url = trim($in['itunes'] ?? '');
ok(itunes_eligibility($url));
