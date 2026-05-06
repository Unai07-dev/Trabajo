<?php
/* ============================================================
   API/INDEX.PHP  —  Router REST principal
   ------------------------------------------------------------
   Todas las peticiones que vayan a /api/... pasan por aquí
   gracias al .htaccess. Lee la URL, dispara el endpoint y
   devuelve siempre JSON.
   ============================================================ */

declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/jwt.php';

// CORS — permite que la app móvil llame a la API
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Content-Type: application/json; charset=utf-8');

// Pre-flight CORS
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// ----- Parsear ruta tipo /api/auth/login → ['auth','login'] -----
$uri  = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?? '';
$uri  = preg_replace('#^.*/api/?#', '', $uri);
$uri  = trim($uri ?? '', '/');
$path = $uri === '' ? [] : explode('/', $uri);

$recurso = $path[0] ?? '';
$accion  = $path[1] ?? '';
$id      = isset($path[2]) ? (int) $path[2] : null;

// ----- Dispatcher -----
try {
    switch ($recurso) {
        case 'auth':
            require __DIR__ . '/endpoints/auth.php';
            manejarAuth($accion);
            break;

        case 'sedes':
            require __DIR__ . '/endpoints/sedes.php';
            manejarSedes();
            break;

        case 'catalogos':
            require __DIR__ . '/endpoints/catalogos.php';
            manejarCatalogos($accion);
            break;

        case 'incidencias':
            require __DIR__ . '/endpoints/incidencias.php';
            manejarIncidencias($accion, $id);
            break;

        case 'fotos':
            require __DIR__ . '/endpoints/fotos.php';
            manejarFotos($accion);
            break;

        case 'health':
            jsonOk(['status' => 'ok', 'time' => date('c')]);
            break;

        default:
            jsonError('Recurso no encontrado', 404);
    }
} catch (\Throwable $e) {
    error_log('API ERROR: ' . $e->getMessage());
    jsonError('Error interno del servidor', 500, [
        'detail' => $e->getMessage(), // quitar en producción
    ]);
}
