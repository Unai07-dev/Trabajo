<?php
/* ============================================================
   API/ENDPOINTS/SEDES.PHP  —  Listar sedes
   ------------------------------------------------------------
   GET /api/sedes  → lista de sedes activas
   ============================================================ */

declare(strict_types=1);

function manejarSedes(): void {
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        jsonError('Método no permitido', 405);
    }
    autenticarPeticion(); // login requerido

    $lista = array_map(function ($s) {
        return [
            'id'     => (int) $s['id_sede'],
            'nombre' => $s['nombre'],
            'email'  => $s['email'],
        ];
    }, obtenerSedes());

    jsonOk(['sedes' => $lista]);
}
