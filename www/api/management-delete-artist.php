<?php
/**
 * POST /api/management-delete-artist.php   (solo booking/management ATTIVI)
 * Body: { id }
 * Elimina un artista GESTITO dal booking corrente. Per cascata FK vengono rimossi
 * profilo/generi/richieste. Un booking può eliminare solo i propri artisti.
 */
require_once __DIR__ . '/_management.php';
only('POST');
$me = require_management();

$in = body();
$id = (int)($in['id'] ?? 0);
require_managed_artist($id, (int) $me['id']);   // esiste ed è mio, altrimenti fail()

db()->prepare('DELETE FROM users WHERE id = ?')->execute([$id]);
ok(['deleted' => $id]);
