<?php
/* ============================================================
   API/ENDPOINTS/AUTH.PHP  —  Login, registro y perfil
   ------------------------------------------------------------
   POST /api/auth/login      → { username, password }   → { token, usuario }
   POST /api/auth/registro   → { username, ... }        → { token, usuario }
   GET  /api/auth/me         → (con Bearer)             → { usuario }
   ============================================================ */

declare(strict_types=1);

function manejarAuth(string $accion): void {
    switch ($accion) {
        case 'login':    authLogin();    return;
        case 'registro': authRegistro(); return;
        case 'me':       authMe();       return;
        default:         jsonError('Acción de auth no válida', 404);
    }
}

function authLogin(): void {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        jsonError('Método no permitido', 405);
    }

    $body     = bodyJson();
    $username = trim($body['username'] ?? '');
    $password = (string) ($body['password'] ?? '');

    if ($username === '' || $password === '') {
        jsonError('Usuario y contraseña son obligatorios');
    }

    // Reutiliza la función ya existente del portal web
    $user = autenticar($username, $password);
    if (!$user) {
        jsonError('Usuario o contraseña incorrectos', 401);
    }

    $datosUsuario = [
        'id'        => (int) $user['id_usuario'],
        'username'  => $user['username'],
        'nombre'    => $user['nombre'],
        'apellidos' => $user['apellidos'] ?? null,
        'email'     => $user['email'],
        'rol'       => $user['tipo_usuario'] ?? 'operario',
        'sede'      => $user['sede_nombre']  ?? null,
        'id_sede'   => $user['id_sede'] ? (int) $user['id_sede'] : null,
    ];

    $token = jwtCrear($datosUsuario);

    jsonOk([
        'token'   => $token,
        'usuario' => $datosUsuario,
    ]);
}

function authRegistro(): void {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        jsonError('Método no permitido', 405);
    }

    $b = bodyJson();
    $r = registrarUsuario(
        $b['username']  ?? '',
        $b['password']  ?? '',
        $b['nombre']    ?? '',
        $b['apellidos'] ?? '',
        $b['email']     ?? '',
        $b['sede']      ?? null
    );

    if (!$r['ok']) {
        jsonError(implode(' / ', $r['errores']));
    }

    // Auto-login después del registro
    $user = autenticar($b['username'], $b['password']);
    if (!$user) {
        jsonError('Cuenta creada pero el auto-login falló', 500);
    }

    $datosUsuario = [
        'id'        => (int) $user['id_usuario'],
        'username'  => $user['username'],
        'nombre'    => $user['nombre'],
        'apellidos' => $user['apellidos'] ?? null,
        'email'     => $user['email'],
        'rol'       => $user['tipo_usuario'] ?? 'operario',
        'sede'      => $user['sede_nombre']  ?? null,
        'id_sede'   => $user['id_sede'] ? (int) $user['id_sede'] : null,
    ];

    jsonOk([
        'token'   => jwtCrear($datosUsuario),
        'usuario' => $datosUsuario,
    ], 201);
}

function authMe(): void {
    $u = autenticarPeticion();
    jsonOk(['usuario' => $u]);
}
