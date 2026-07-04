<?php
/**
 * Geocoding comune → lat/lng (via Nominatim/OpenStreetMap) + cache su file.
 * Nominatim richiede User-Agent identificativo e max ~1 req/sec: qui usiamo cache
 * su disco così ogni comune si geocodifica una volta sola.
 * Con provincia (sigla italiana, dall'autocomplete comuni.json) → ricerca ristretta all'Italia,
 * come sempre. Senza provincia (comune digitato a mano, o città europea da europe-cities.json,
 * che non ha sigle) → ricerca libera nel mondo, così funzionano anche le sedi fuori Italia.
 */

function geo_cache_path(): string {
  $dir = __DIR__ . '/cache';
  if (!is_dir($dir)) @mkdir($dir, 0775, true);
  return $dir . '/geocode.json';
}

function geocode_comune(string $comune, ?string $provincia = null): ?array {
  $comune = trim($comune);
  if ($comune === '') return null;
  $key = mb_strtolower($comune . '|' . ($provincia ?? ''));

  $path = geo_cache_path();
  $cache = is_file($path) ? (json_decode(@file_get_contents($path), true) ?: []) : [];
  if (isset($cache[$key])) return $cache[$key] ?: null;

  if ($provincia) {
    $q = "$comune, $provincia, Italia";
    $url = 'https://nominatim.openstreetmap.org/search?format=json&limit=1&countrycodes=it&q=' . rawurlencode($q);
  } else {
    $url = 'https://nominatim.openstreetmap.org/search?format=json&limit=1&q=' . rawurlencode($comune);
  }

  $ch = curl_init($url);
  curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => 8,
    CURLOPT_USERAGENT      => '148Roster/1.0 (artisti.148booking.it)',
  ]);
  $res = curl_exec($ch);
  curl_close($ch);

  $coords = null;
  if ($res) {
    $j = json_decode($res, true);
    if (!empty($j[0]['lat']) && !empty($j[0]['lon'])) {
      $coords = ['lat' => round((float)$j[0]['lat'], 6), 'lng' => round((float)$j[0]['lon'], 6)];
    }
  }
  $cache[$key] = $coords ?: false;   // memorizza anche i fallimenti per non ritentare all'infinito
  @file_put_contents($path, json_encode($cache, JSON_UNESCAPED_UNICODE));
  return $coords;
}

/** Distanza in km tra due punti (Haversine). */
function haversine_km(float $lat1, float $lng1, float $lat2, float $lng2): float {
  $R = 6371.0;
  $dLat = deg2rad($lat2 - $lat1);
  $dLng = deg2rad($lng2 - $lng1);
  $a = sin($dLat/2)**2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng/2)**2;
  return $R * 2 * atan2(sqrt($a), sqrt(1 - $a));
}
