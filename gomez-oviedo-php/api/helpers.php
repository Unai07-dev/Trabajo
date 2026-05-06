<?php
/* ============================================================
   API/HELPERS.PHP  —  Funciones de utilidad para la API
   ------------------------------------------------------------
   - jsonOk / jsonError: respuestas JSON estandarizadas
   - bodyJson: lee el body POST como JSON
   - autenticarPeticion: extrae y valida el JWT de la cabecera
   ============================================================ */

declare(strict_types=1);

/** Responde con JSON 200 y termina la ejecución. */
function jsonOk(array $datos = [], int $codigo = 200): void {
    http_response_code($codigo);
    echo json_encode([
        'ok'    => true,
        'datos' => $datos,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

/** Responde con un error JSON y termina la ejecución. */
function jsonError(string $mensaje, int $codigo = 400, array $extra = []): void {
    http_response_code($codigo);
    echo json_encode(array_merge([
        'ok'      => false,
        'mensaje' => $mensaje,
        'codigo'  => $codigo,
    ], $extra), JSON_UNESCAPED_UNICODE);
    exit;
}

/** Devuelve el body POST decodificado como array, o []. */
function bodyJson(): array {
    $raw = file_get_contents('php://input');
    if (!$raw) return [];
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}

/**
 * Lee el header 'Authorization: Bearer xxx', valida el JWT y
 * devuelve el payload con los datos del usuario. Si falla, responde
 * 401 y termina.
 */
function autenticarPeticion(): array {
    $headers = function_exists('getallheaders') ? getallheaders() : [];
    $auth    = $headers['Authorization'] ?? $headers['authorization'] ?? '';

    if (!$auth || !preg_match('/Bearer\s+(.+)/i', $auth, $m)) {
        jsonError('Token de autenticación requerido', 401);
    }

    try {
        return jwtDecodificar($m[1]);
    } catch (\Throwable $e) {
        jsonError('Token inválido o expirado: ' . $e->getMessage(), 401);
    }

    return [];
}

/** Comprueba que el rol del usuario está en la lista. Responde 403 si no. */
function exigirRol(array $usuario, array $rolesPermitidos): void {
    if (!in_array($usuario['rol'] ?? '', $rolesPermitidos, true)) {
        jsonError('No tienes permisos para esta acción', 403);
    }
}
