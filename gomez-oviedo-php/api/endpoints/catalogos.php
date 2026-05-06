<?php
/* ============================================================
   API/ENDPOINTS/CATALOGOS.PHP  —  Listas para los formularios
   ------------------------------------------------------------
   GET /api/catalogos/tipos-fallo   → lista de tipos de fallo
   GET /api/catalogos/urgencias     → lista de niveles de urgencia
   GET /api/catalogos/maquinas      → catálogo de máquinas
   GET /api/catalogos/posiciones    → posiciones de rueda (pinchazo)
   ============================================================ */

declare(strict_types=1);

function manejarCatalogos(string $accion): void {
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        jsonError('Método no permitido', 405);
    }
    autenticarPeticion();

    switch ($accion) {
        case 'tipos-fallo':
            $tipos = array_map(fn($t) => [
                'id'     => (int) $t['id_tipo_fallo'],
                'nombre' => $t['nombre'],
            ], obtenerTiposFallo());
            jsonOk(['tipos_fallo' => $tipos]);
            break;

        case 'urgencias':
            $urgs = array_map(fn($u) => [
                'id'     => (int) $u['id_urgencia'],
                'codigo' => $u['codigo'],
                'nombre' => $u['nombre'],
                'nivel'  => (int) $u['nivel'],
            ], obtenerUrgencias());
            jsonOk(['urgencias' => $urgs]);
            break;

        case 'maquinas':
            jsonOk(['maquinas' => array_values(MAQUINAS)]);
            break;

        case 'posiciones':
            $pos = [];
            foreach (POSICIONES_RUEDA as $clave => $etiqueta) {
                $pos[] = ['codigo' => $clave, 'etiqueta' => $etiqueta];
            }
            jsonOk(['posiciones' => $pos]);
            break;

        default:
            jsonError('Catálogo no encontrado', 404);
    }
}
