<?php
/* ============================================================
   FUNCIONES.PHP  —  Helpers reutilizables del Portal
   ------------------------------------------------------------

   ============================================================ */

require_once __DIR__ . '/define.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/../lib/SmtpMailer.php';


/* ============================================================
   SESIÓN
   ============================================================ */
function iniciarSesion(): void {
    if (session_status() === PHP_SESSION_NONE) {
        session_name(SESSION_NAME);
        session_set_cookie_params([
            'lifetime' => 0,
            'path'     => '/',
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        session_start();
    }
}

function usuarioActual(): ?array {
    iniciarSesion();
    return $_SESSION['usuario'] ?? null;
}

function requiereLogin(): void {
    if (!usuarioActual()) {
        $base = (basename(dirname($_SERVER['PHP_SELF'])) === 'secciones') ? '../' : '';
        header('Location: ' . $base . 'login.php');
        exit;
    }
}

/** Exige que el usuario actual sea de uno de los roles dados. */
function requiereRol(array $roles): void {
    requiereLogin();
    $u = usuarioActual();
    if (!in_array($u['rol'], $roles, true)) {
        flash('error', 'No tienes permisos para acceder a esa sección.');
        $base = (basename(dirname($_SERVER['PHP_SELF'])) === 'secciones') ? '' : 'secciones/';
        header('Location: ' . $base . 'usuarios.php');
        exit;
    }
}

function cerrarSesion(): void {
    iniciarSesion();
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000,
            $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    }
    session_destroy();
}


/* ============================================================
   CSRF
   ============================================================ */
function csrfToken(): string {
    iniciarSesion();
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function verificarCsrf(?string $token): bool {
    iniciarSesion();
    return !empty($_SESSION['csrf_token'])
        && is_string($token)
        && hash_equals($_SESSION['csrf_token'], $token);
}

function inputCsrf(): string {
    return '<input type="hidden" name="csrf" value="' . escapar(csrfToken()) . '">';
}


/* ============================================================
   RATE LIMITING  (Anti fuerza bruta en login)
   ------------------------------------------------------------
   Implementación simple basada en archivos: por cada
   IP/usuario guardamos cuántos intentos fallidos lleva y
   cuándo se reinicia la ventana. No requiere Redis ni BD.
   ============================================================ */

/** Devuelve la IP del cliente lo más fiable posible. */
function ipCliente(): string {
    foreach (['HTTP_X_FORWARDED_FOR', 'HTTP_CLIENT_IP', 'REMOTE_ADDR'] as $k) {
        if (!empty($_SERVER[$k])) {
            $ip = explode(',', $_SERVER[$k])[0];
            return trim($ip);
        }
    }
    return '0.0.0.0';
}

/** Comprueba si una IP/usuario tiene permitido seguir intentando. */
function rateLimitPermitido(string $clave): bool {
    $dir = RATE_LIMIT_DIR;
    if (!is_dir($dir)) @mkdir($dir, 0755, true);

    $archivo = $dir . sha1($clave) . '.json';
    if (!is_file($archivo)) return true;

    $datos = json_decode(@file_get_contents($archivo), true) ?: [];
    $hasta = (int) ($datos['hasta'] ?? 0);

    // Si ya pasó la ventana, se reinicia
    if (time() > $hasta) {
        @unlink($archivo);
        return true;
    }

    return ((int) ($datos['fallos'] ?? 0)) < RATE_LIMIT_LOGIN_INTENTOS;
}

/** Registra un fallo de login. Devuelve los segundos que faltan para desbloquear. */
function rateLimitRegistrarFallo(string $clave): int {
    $dir = RATE_LIMIT_DIR;
    if (!is_dir($dir)) @mkdir($dir, 0755, true);

    $archivo = $dir . sha1($clave) . '.json';
    $datos   = is_file($archivo)
        ? (json_decode(@file_get_contents($archivo), true) ?: [])
        : [];

    $hasta = (int) ($datos['hasta'] ?? 0);
    if (time() > $hasta) {
        // Nueva ventana
        $datos = [
            'fallos' => 1,
            'hasta'  => time() + RATE_LIMIT_LOGIN_VENTANA,
        ];
    } else {
        $datos['fallos'] = ((int) ($datos['fallos'] ?? 0)) + 1;
    }

    @file_put_contents($archivo, json_encode($datos));
    return max(0, ((int) $datos['hasta']) - time());
}

/** Limpia el contador después de un login OK. */
function rateLimitLimpiar(string $clave): void {
    $archivo = RATE_LIMIT_DIR . sha1($clave) . '.json';
    if (is_file($archivo)) @unlink($archivo);
}


/* ============================================================
   ESCAPE / SANITIZACIÓN
   ============================================================ */
function escapar($v): string {
    return htmlspecialchars((string) ($v ?? ''), ENT_QUOTES, 'UTF-8');
}

function limpiar(?string $v): string {
    return trim((string) $v);
}

function flash(string $tipo, string $mensaje): void {
    iniciarSesion();
    $_SESSION['flash'][] = ['tipo' => $tipo, 'mensaje' => $mensaje];
}

function obtenerFlashes(): array {
    iniciarSesion();
    $f = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return $f;
}


/* ============================================================
   ALCANCE POR ROL  —  Filtro WHERE para listados de incidencias
   ------------------------------------------------------------
   admin   → ve todas         → "1=1"
   sede    → ve las de su sede → "i.id_sede = ?"
   operario → ve solo las suyas → "i.id_usuario = ?"
   ============================================================ */
function alcanceIncidencias(): array {
    $u = usuarioActual();
    if (!$u) return ['where' => '0=0', 'params' => []];

    if ($u['rol'] === 'admin') {
        return ['where' => '1=1', 'params' => []];
    }
    if ($u['rol'] === 'sede') {
        return ['where' => 'i.id_sede = ?', 'params' => [(int) $u['id_sede']]];
    }
    return ['where' => 'i.id_usuario = ?', 'params' => [(int) $u['id']]];
}


/* ============================================================
   PAGINACIÓN  —  helper para listados
   ------------------------------------------------------------
   Lee ?pagina=N y ?por_pagina=M de $_GET y devuelve [offset, limit, pagina].
   ============================================================ */
function paginarDesdeGet(array $get): array {
    $pagina    = max(1, (int) ($get['pagina'] ?? 1));
    $porPagina = (int) ($get['por_pagina'] ?? LISTADO_POR_PAGINA);
    $porPagina = max(1, min($porPagina, LISTADO_PAGINA_MAX));
    $offset    = ($pagina - 1) * $porPagina;

    return ['pagina' => $pagina, 'por_pagina' => $porPagina, 'offset' => $offset];
}

/** Cuenta el total de filas que devolvería un SELECT (sin LIMIT). */
function contarFilas(PDO $pdo, string $sqlConteo, array $params): int {
    $stmt = $pdo->prepare($sqlConteo);
    $stmt->execute($params);
    return (int) $stmt->fetchColumn();
}


/* ============================================================
   FILTROS DE LISTADO  —  construye un WHERE/params extra a partir
   de los parámetros GET (cliente, máquina, fecha desde/hasta, sede,
   urgencia, posición rueda).  Devuelve también un array 'activos'
   con los filtros que el usuario ha rellenado, útil para mostrar
   "filtrando por: ..." en la vista.
   ============================================================ */
function construirFiltrosListado(array $get, array $opcionesExtra = []): array {
    $where    = [];
    $params   = [];
    $activos  = [];

    // 1) Búsqueda libre en cliente / máquina / contrato / ticket / obra
    $q = trim($get['q'] ?? '');
    if ($q !== '') {
        $where[]   = '(i.cliente_nombre LIKE ? OR i.maquina_modelo LIKE ?
                       OR i.contrato_codigo LIKE ? OR i.ticket LIKE ?
                       OR i.numero_maquina LIKE ? OR i.obra_nombre LIKE ?)';
        $like      = '%' . $q . '%';
        $params    = array_merge($params, [$like, $like, $like, $like, $like, $like]);
        $activos[] = "Búsqueda: \"$q\"";
    }

    // 2) Rango de fechas
    $desde = trim($get['desde'] ?? '');
    if ($desde !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $desde)) {
        $where[]   = 'i.fecha_creacion >= ?';
        $params[]  = $desde . ' 00:00:00';
        $activos[] = "Desde $desde";
    }
    $hasta = trim($get['hasta'] ?? '');
    if ($hasta !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $hasta)) {
        $where[]   = 'i.fecha_creacion <= ?';
        $params[]  = $hasta . ' 23:59:59';
        $activos[] = "Hasta $hasta";
    }

    // 3) Sede (solo la usa admin — sede ya está limitado por alcanceIncidencias)
    $sedeId = (int) ($get['sede'] ?? 0);
    if ($sedeId > 0 && (usuarioActual()['rol'] ?? '') === 'admin') {
        $where[]   = 'i.id_sede = ?';
        $params[]  = $sedeId;
        foreach (obtenerSedes() as $s) {
            if ((int) $s['id_sede'] === $sedeId) {
                $activos[] = 'Sede: ' . $s['nombre'];
                break;
            }
        }
    }

    // 4) Filtros específicos según opcionesExtra (urgencia, posición)
    if (!empty($opcionesExtra['urgencia'])) {
        $cod = trim($get['urgencia'] ?? '');
        if ($cod !== '') {
            $where[]   = 'a.id_urgencia = (SELECT id_urgencia FROM urgencias WHERE codigo = ?)';
            $params[]  = $cod;
            $activos[] = 'Urgencia: ' . strtoupper($cod);
        }
    }
    if (!empty($opcionesExtra['posicion'])) {
        $pos = trim($get['posicion'] ?? '');
        if ($pos !== '' && array_key_exists($pos, POSICIONES_RUEDA)) {
            $where[]   = 'p.posicion_rueda = ?';
            $params[]  = $pos;
            $activos[] = 'Posición: ' . $pos;
        }
    }

    return [
        'where'   => $where ? ' AND ' . implode(' AND ', $where) : '',
        'params'  => $params,
        'activos' => $activos,
    ];
}


/* ============================================================
   CARGAR INCIDENCIA POR ID  —  con verificación de acceso por rol.
   ============================================================ */
function cargarIncidenciaConAcceso(int $id): ?array {
    $u = usuarioActual();
    if (!$u) return null;

    $pdo = conectarDB();

    $stmt = $pdo->prepare('
        SELECT i.*,
               s.nombre  AS sede_nombre_real,
               s.email   AS sede_email,
               usr.username AS creador_username,
               usr.nombre   AS creador_nombre,
               usr.apellidos AS creador_apellidos
        FROM   incidencias i
        LEFT JOIN sedes    s   ON s.id_sede   = i.id_sede
        LEFT JOIN usuarios usr ON usr.id_usuario = i.id_usuario
        WHERE  i.id_incidencia = ?
        LIMIT 1
    ');
    $stmt->execute([$id]);
    $inc = $stmt->fetch();
    if (!$inc) return null;

    // Verificar acceso según rol
    if ($u['rol'] === 'sede' && (int) $inc['id_sede'] !== (int) $u['id_sede']) return null;
    if ($u['rol'] === 'operario' && (int) $inc['id_usuario'] !== (int) $u['id']) return null;

    // Cargar tabla hija
    $hija = null;
    if ($inc['tipo'] === 'pinchazo') {
        $stmt = $pdo->prepare('SELECT * FROM incidencias_pinchazo WHERE id_incidencia = ?');
        $stmt->execute([$id]);
        $hija = $stmt->fetch() ?: [];
    } elseif ($inc['tipo'] === 'averia') {
        $stmt = $pdo->prepare('
            SELECT a.*, ur.codigo AS urgencia_codigo, ur.nombre AS urgencia_nombre,
                   tf.nombre AS tipo_fallo_nombre
            FROM   incidencias_averia a
            LEFT JOIN urgencias    ur ON ur.id_urgencia    = a.id_urgencia
            LEFT JOIN tipos_fallo  tf ON tf.id_tipo_fallo  = a.id_tipo_fallo
            WHERE  a.id_incidencia = ?
        ');
        $stmt->execute([$id]);
        $hija = $stmt->fetch() ?: [];
    } elseif ($inc['tipo'] === 'devolucion') {
        $stmt = $pdo->prepare('SELECT * FROM incidencias_devolucion WHERE id_incidencia = ?');
        $stmt->execute([$id]);
        $hija = $stmt->fetch() ?: [];
    }

    // Fotos
    $stmt = $pdo->prepare('SELECT id_foto, ruta FROM incidencia_fotos WHERE id_incidencia = ? ORDER BY orden, id_foto');
    $stmt->execute([$id]);
    $fotos = $stmt->fetchAll();

    return ['inc' => $inc, 'hija' => $hija, 'fotos' => $fotos];
}


/* ============================================================
   SEDES
   ============================================================ */
function obtenerSedes(): array {
    static $sedes = null;
    if ($sedes === null) {
        $pdo = conectarDB();
        $sedes = $pdo->query('
            SELECT id_sede, nombre, email
            FROM sedes
            WHERE activo = 1
            ORDER BY id_sede
        ')->fetchAll();
    }
    return $sedes;
}

function idSedePorNombre(?string $nombre): ?int {
    if (!$nombre) return null;
    foreach (obtenerSedes() as $s) {
        if ($s['nombre'] === $nombre) return (int) $s['id_sede'];
    }
    return null;
}

function emailDeSede(?string $nombre): ?string {
    if (!$nombre) return null;
    foreach (obtenerSedes() as $s) {
        if ($s['nombre'] === $nombre) return $s['email'];
    }
    return null;
}


/* ============================================================
   AUTENTICACIÓN
   ============================================================ */
function autenticar(string $username, string $password): array|false {
    $pdo = conectarDB();
    $stmt = $pdo->prepare('
        SELECT  u.*,
                s.nombre AS sede_nombre
        FROM    usuarios u
        LEFT JOIN sedes s ON s.id_sede = u.id_sede
        WHERE   u.username = ?
          AND   u.activo = 1
        LIMIT 1
    ');
    $stmt->execute([strtolower(limpiar($username))]);
    $user = $stmt->fetch();

    if (!$user)                                          return false;
    if (!password_verify($password, $user['pass']))      return false;

    try {
        $pdo->prepare('UPDATE usuarios SET ultimo_acceso = NOW() WHERE id_usuario = ?')
            ->execute([(int) $user['id_usuario']]);
    } catch (\Throwable $e) { /* ignorado */ }

    return $user;
}


function registrarUsuario(
    string $username,
    string $password,
    string $nombre,
    string $apellidos,
    string $email,
    ?string $sedeNombre
): array {
    $username = strtolower(limpiar($username));
    $errores  = [];

    if (strlen($username) < 3) $errores[] = 'El usuario debe tener al menos 3 caracteres.';
    if (!preg_match('/^[a-z0-9_]+$/', $username)) {
        $errores[] = 'El usuario solo puede contener letras, números y guion bajo.';
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL))   $errores[] = 'Email no válido.';
    if (strlen($password) < 6)                        $errores[] = 'La contraseña debe tener al menos 6 caracteres.';
    if (empty($nombre))                               $errores[] = 'El nombre es obligatorio.';

    if ($errores) return ['ok' => false, 'errores' => $errores];

    $pdo = conectarDB();
    $stmt = $pdo->prepare('SELECT id_usuario FROM usuarios WHERE username = ? LIMIT 1');
    $stmt->execute([$username]);
    if ($stmt->fetch()) {
        return ['ok' => false, 'errores' => ['Ya existe un usuario con ese nombre.']];
    }
    $stmt = $pdo->prepare('SELECT id_usuario FROM usuarios WHERE email = ? LIMIT 1');
    $stmt->execute([limpiar($email)]);
    if ($stmt->fetch()) {
        return ['ok' => false, 'errores' => ['Ya existe una cuenta con ese email.']];
    }

    $idSede = idSedePorNombre($sedeNombre);

    $stmt = $pdo->prepare('
        INSERT INTO usuarios
            (username, nombre, apellidos, email, pass, tipo_usuario, id_sede)
        VALUES
            (?, ?, ?, ?, ?, ?, ?)
    ');
    $stmt->execute([
        $username,
        limpiar($nombre),
        limpiar($apellidos) ?: null,
        limpiar($email),
        password_hash($password, PASSWORD_DEFAULT),
        'operario',
        $idSede,
    ]);

    return ['ok' => true, 'id' => (int) $pdo->lastInsertId()];
}


function loginUsuario(array $user): void {
    iniciarSesion();
    session_regenerate_id(true);
    $_SESSION['usuario'] = [
        'id'        => (int) $user['id_usuario'],
        'username'  => $user['username'],
        'nombre'    => $user['nombre'],
        'apellidos' => $user['apellidos'] ?? null,
        'email'     => $user['email'],
        'sede'      => $user['sede_nombre'] ?? null,
        'id_sede'   => $user['id_sede'] ? (int) $user['id_sede'] : null,
        'rol'       => $user['tipo_usuario'] ?? 'operario',
    ];
}


/* ============================================================
   FOTOS
   ============================================================ */
function guardarFotosSubidas(string $modulo, int $idIncidencia, array $files): array {
    if (empty($files['name'][0])) return [];

    if (!is_dir(UPLOAD_DIR)) {
        @mkdir(UPLOAD_DIR, 0755, true);
    }

    $rutas = [];
    $pdo = conectarDB();
    $stmt = $pdo->prepare('
        INSERT INTO incidencia_fotos (id_incidencia, ruta, orden)
        VALUES (?, ?, ?)
    ');

    $orden = 0;
    foreach ($files['name'] as $i => $nombreOriginal) {
        if ($files['error'][$i] !== UPLOAD_ERR_OK)            continue;
        if ($files['size'][$i]  > UPLOAD_MAX_SIZE)            continue;

        $ext = strtolower(pathinfo($nombreOriginal, PATHINFO_EXTENSION));
        if (!in_array($ext, UPLOAD_EXT_PERMITIDAS, true))     continue;

        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime  = finfo_file($finfo, $files['tmp_name'][$i]);
        finfo_close($finfo);
        if (!str_starts_with((string) $mime, 'image/'))       continue;

        $nombreNuevo = sprintf('%s_%d_%s.%s',
            $modulo, $idIncidencia, bin2hex(random_bytes(6)), $ext
        );
        $rutaFs  = UPLOAD_DIR . $nombreNuevo;
        $rutaWeb = UPLOAD_URL . $nombreNuevo;

        if (move_uploaded_file($files['tmp_name'][$i], $rutaFs)) {
            $stmt->execute([$idIncidencia, $rutaWeb, $orden++]);
            $rutas[] = $rutaWeb;
        }
    }
    return $rutas;
}


/* ============================================================
   ENVÍO DE EMAIL  —  vía SmtpMailer (PHP)
   ============================================================ */
function enviarEmailSede(string $sedeNombre, string $asunto, string $cuerpo): array {
    $destino = emailDeSede($sedeNombre);
    if (!$destino) {
        return ['ok' => false, 'destino' => null, 'error' => 'La sede no tiene email configurado.'];
    }

    // ---------- MODO DEBUG: guardar como .txt en disco ----------
    if (defined('MAIL_DEBUG') && MAIL_DEBUG === true) {
        $dir = UPLOAD_DIR . '_mail_debug/';
        if (!is_dir($dir)) @mkdir($dir, 0755, true);

        $archivo = sprintf('%s%s_%s.txt',
            $dir, date('Ymd_His'),
            preg_replace('/[^a-z0-9]+/i', '_', $asunto)
        );
        $contenido = "PARA: $destino\n"
                   . "DE:   " . MAIL_FROM_NAME . " <" . MAIL_FROM . ">\n"
                   . "ASUNTO: $asunto\n"
                   . "FECHA: " . date('Y-m-d H:i:s') . "\n"
                   . str_repeat('=', 60) . "\n\n"
                   . $cuerpo;
        @file_put_contents($archivo, $contenido);
        return ['ok' => true, 'destino' => $destino, 'error' => null, 'modo' => 'debug'];
    }

    // ---------- ENVÍO REAL POR SMTP ----------
    if (SMTP_PASSWORD === 'PON_AQUI_TU_APP_PASSWORD' || SMTP_PASSWORD === '') {
        return [
            'ok' => false, 'destino' => $destino,
            'error' => 'SMTP no configurado: edita utilidades/define.php y rellena SMTP_PASSWORD.',
        ];
    }

    $mail = new SmtpMailer();
    $mail->host       = SMTP_HOST;
    $mail->port       = SMTP_PORT;
    $mail->encryption = SMTP_ENCRYPTION;
    $mail->username   = SMTP_USERNAME;
    $mail->password   = SMTP_PASSWORD;
    $mail->hostname   = $_SERVER['HTTP_HOST'] ?? 'localhost';

    $ok = $mail->send(MAIL_FROM, MAIL_FROM_NAME, $destino, $asunto, $cuerpo);

    return [
        'ok'      => $ok,
        'destino' => $destino,
        'error'   => $ok ? null : $mail->lastError,
        'modo'    => 'smtp',
    ];
}


function construirCuerpoEmail(string $tipo, array $datos, array $fotos = []): string {
    $u = usuarioActual();
    $lineas = [];
    $lineas[] = '════════════════════════════════════════';
    $lineas[] = "  REPORTE DE: $tipo";
    $lineas[] = '  Portal Gómez Oviedo';
    $lineas[] = '════════════════════════════════════════';
    $lineas[] = '';

    if ($u) {
        $nombre = $u['nombre'] . ($u['apellidos'] ? ' ' . $u['apellidos'] : '');
        $lineas[] = "Enviado por: $nombre ({$u['username']})";
        $lineas[] = '';
    }

    $etiquetas = [
        'ticket'             => 'Ticket',
        'contrato_codigo'    => 'ID Contrato',
        'cliente_nombre'     => 'Cliente / Empresa',
        'obra_nombre'        => 'Obra',
        'maquina_modelo'     => 'Máquina',
        'numero_maquina'     => 'Nº de máquina',
        'sede_nombre'        => 'Sede',
        'contacto_nombre'    => 'Nombre',
        'contacto_apellidos' => 'Apellidos',
        'contacto_telefono'  => 'Teléfono',
        'contacto_email'     => 'Email',
        'tipo_fallo'         => 'Tipo de fallo',
        'urgencia'           => 'Urgencia',
        'impacto_operativo'  => 'Estado de la máquina',
        'posicion_rueda'     => 'Posición rueda',
        'horas_finales'      => 'Horas finales',
        'descripcion'        => 'Descripción',
        'comentarios'        => 'Comentarios',
        'observaciones'      => 'Observaciones',
        'ubicacion'          => 'Ubicación GPS',
        'ubicacion_aclar'    => 'Aclaración ubicación',
    ];

    foreach ($datos as $clave => $valor) {
        if ($valor === null || $valor === '') continue;
        $eti = $etiquetas[$clave] ?? ucfirst(str_replace('_', ' ', $clave));
        $lineas[] = "• $eti: $valor";
    }

    if ($fotos) {
        $lineas[] = '';
        $lineas[] = 'Fotos adjuntas: ' . count($fotos);
        foreach ($fotos as $f) {
            $lineas[] = '   - ' . $f;
        }
    }

    $lineas[] = '';
    $lineas[] = '────────────────────────────────────────';
    $lineas[] = 'Enviado el ' . date('d/m/Y H:i:s');

    return implode("\n", $lineas);
}


/* ============================================================
   INCIDENCIAS  —  Inserción común (padre)
   ============================================================ */
function crearIncidencia(string $tipo, array $datos): int {
    $pdo = conectarDB();
    $u   = usuarioActual();

    $sql = '
        INSERT INTO incidencias
            (tipo, ticket,
             id_sede, id_usuario,
             contrato_codigo, cliente_nombre, obra_nombre,
             maquina_modelo, numero_maquina, sede_nombre,
             contacto_nombre, contacto_apellidos,
             contacto_email, contacto_telefono,
             latitud, longitud, ubicacion_aclaracion,
             observaciones, estado)
        VALUES
            (:tipo, :ticket,
             :id_sede, :id_usuario,
             :contrato_codigo, :cliente_nombre, :obra_nombre,
             :maquina_modelo, :numero_maquina, :sede_nombre,
             :contacto_nombre, :contacto_apellidos,
             :contacto_email, :contacto_telefono,
             :latitud, :longitud, :ubicacion_aclaracion,
             :observaciones, "borrador")
    ';
    $stmt = $pdo->prepare($sql);

    [$lat, $lng] = parsearGPS($datos['ubicacion'] ?? '');

    $stmt->execute([
        'tipo'                 => $tipo,
        'ticket'               => $datos['ticket']            ?? null,
        'id_sede'              => idSedePorNombre($datos['sede_nombre'] ?? null),
        'id_usuario'           => $u ? (int) $u['id'] : null,
        'contrato_codigo'      => $datos['contrato_codigo']    ?? null,
        'cliente_nombre'       => $datos['cliente_nombre']     ?? null,
        'obra_nombre'          => $datos['obra_nombre']        ?? null,
        'maquina_modelo'       => $datos['maquina_modelo']     ?? null,
        'numero_maquina'       => $datos['numero_maquina']     ?? null,
        'sede_nombre'          => $datos['sede_nombre']        ?? null,
        'contacto_nombre'      => $datos['contacto_nombre']    ?? null,
        'contacto_apellidos'   => $datos['contacto_apellidos'] ?? null,
        'contacto_email'       => $datos['contacto_email']     ?? null,
        'contacto_telefono'    => $datos['contacto_telefono']  ?? null,
        'latitud'              => $lat,
        'longitud'             => $lng,
        'ubicacion_aclaracion' => $datos['ubicacion_aclar']    ?? null,
        'observaciones'        => $datos['observaciones']      ?? null,
    ]);

    return (int) $pdo->lastInsertId();
}


function marcarIncidenciaEnviada(int $idIncidencia, array $resultadoEmail): void {
    $pdo = conectarDB();
    $stmt = $pdo->prepare('
        UPDATE incidencias
        SET estado            = ?,
            email_enviado     = ?,
            email_destinatario= ?,
            email_error       = ?
        WHERE id_incidencia = ?
    ');
    $stmt->execute([
        $resultadoEmail['ok'] ? 'enviada' : 'borrador',
        $resultadoEmail['ok'] ? 1 : 0,
        $resultadoEmail['destino'] ?? null,
        $resultadoEmail['error']   ?? null,
        $idIncidencia,
    ]);
}


/* ============================================================
   UTILIDADES
   ============================================================ */
function generarTicket(): string {
    return 'WAV-' . str_pad((string) random_int(1000, 9999), 4, '0', STR_PAD_LEFT);
}

function urlActiva(string $seccion): string {
    $actual = basename($_SERVER['PHP_SELF'], '.php');
    return $actual === $seccion ? 'active' : '';
}

function parsearGPS(?string $texto): array {
    if (!$texto) return [null, null];
    if (preg_match('/^\s*(-?\d+(?:\.\d+)?)\s*,\s*(-?\d+(?:\.\d+)?)\s*$/', $texto, $m)) {
        return [(float) $m[1], (float) $m[2]];
    }
    return [null, null];
}


/* ============================================================
   CARGAS PARA FORMULARIOS
   ============================================================ */
function obtenerTiposFallo(): array {
    static $cache = null;
    if ($cache === null) {
        $pdo = conectarDB();
        $cache = $pdo->query('
            SELECT id_tipo_fallo, nombre
            FROM tipos_fallo
            WHERE activo = 1
            ORDER BY orden, nombre
        ')->fetchAll();
    }
    return $cache;
}

function obtenerUrgencias(): array {
    static $cache = null;
    if ($cache === null) {
        $pdo = conectarDB();
        $cache = $pdo->query('
            SELECT id_urgencia, codigo, nombre, nivel
            FROM urgencias
            ORDER BY nivel DESC
        ')->fetchAll();
    }
    return $cache;
}

function idUrgenciaPorCodigo(?string $codigo): ?int {
    if (!$codigo) return null;
    foreach (obtenerUrgencias() as $u) {
        if ($u['codigo'] === $codigo) return (int) $u['id_urgencia'];
    }
    return null;
}

function idTipoFalloPorNombre(?string $nombre): ?int {
    if (!$nombre) return null;
    foreach (obtenerTiposFallo() as $t) {
        if ($t['nombre'] === $nombre) return (int) $t['id_tipo_fallo'];
    }
    return null;
}


/* ============================================================
   LAYOUT
   ============================================================ */
function renderHeader(string $titulo, string $iconoFA = 'fa-house'): void {
    $u = usuarioActual();
    $esSeccion = basename(dirname($_SERVER['PHP_SELF'])) === 'secciones';
    $base = $esSeccion ? '../' : '';
    ?>
    <!DOCTYPE html>
    <html lang="es">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <meta name="theme-color" content="#0052a5">
        <title><?= escapar($titulo) ?> · <?= APP_NAME ?></title>

        <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
        <link rel="stylesheet" href="<?= $base ?>css/bootstrap.min.css">
        <link rel="stylesheet" href="<?= $base ?>css/style.css">
    </head>
    <body>

    <?php if ($u): ?>
    <div class="layout">
        <aside class="sidebar" id="sidebar">

            <div class="sidebar-brand">
                <div class="brand-logo">GO</div>
                <div class="brand-text">
                    <strong><?= APP_NAME ?></strong>
                    <span><?= APP_TAGLINE ?></span>
                </div>
            </div>

            <div class="user-chip">
                <i class="fa-solid fa-user-circle"></i>
                <span>
                    <?= escapar($u['nombre']) ?>
                    <small style="opacity:.7">(<?= escapar($u['rol']) ?>)</small>
                </span>
            </div>

            <nav class="sidebar-nav">
                <a class="nav-item <?= urlActiva('usuarios') ?>"     href="<?= $base ?>secciones/usuarios.php">
                    <i class="fa-solid fa-house"></i><span>Inicio</span>
                </a>
                <a class="nav-item <?= urlActiva('devoluciones') ?>" href="<?= $base ?>secciones/devoluciones.php">
                    <i class="fa-solid fa-rotate-left"></i><span>Devolución</span>
                </a>
                <a class="nav-item <?= urlActiva('averias') ?>"      href="<?= $base ?>secciones/averias.php">
                    <i class="fa-solid fa-triangle-exclamation"></i><span>Avería</span>
                </a>
                <a class="nav-item <?= urlActiva('pinchazos') ?>"    href="<?= $base ?>secciones/pinchazos.php">
                    <i class="fa-solid fa-screwdriver-wrench"></i><span>Pinchazo</span>
                </a>
            </nav>

            <div class="sidebar-footer">
                <a href="mailto:gomezoviedo@gomezoviedo.com" class="btn-contact">
                    <i class="fa-solid fa-envelope"></i> Contactar
                </a>
                <a href="<?= $base ?>logout.php" class="btn-logout">
                    <i class="fa-solid fa-right-from-bracket"></i> Cerrar sesión
                </a>
            </div>
        </aside>

        <div class="sidebar-backdrop" id="sidebarBackdrop"
             onclick="document.getElementById('sidebar').classList.remove('open');this.classList.remove('open')"></div>

        <main class="content">

            <header class="content-topbar">
                <button class="menu-toggle" type="button"
                        onclick="document.getElementById('sidebar').classList.toggle('open');document.getElementById('sidebarBackdrop').classList.toggle('open')"
                        aria-label="Abrir menú">
                    <i class="fa-solid fa-bars"></i>
                </button>
                <div class="topbar-brand">
                    <div class="brand-logo brand-logo-sm">GO</div>
                    <strong><?= APP_NAME ?></strong>
                </div>
            </header>

            <?php renderFlashes(); ?>
    <?php endif;
}

function renderFooter(): void {
    $u = usuarioActual();
    if ($u): ?>
        </main>
    </div>
    <?php endif; ?>
    </body>
    </html>
    <?php
}

function renderFlashes(): void {
    foreach (obtenerFlashes() as $f) {
        $clase = match ($f['tipo']) {
            'success' => 'alert-success',
            'error'   => 'alert-danger',
            'warning' => 'alert-warning',
            default   => 'alert-info',
        };
        echo '<div class="alert ' . $clase . ' alert-dismissible fade show" role="alert">'
           . escapar($f['mensaje'])
           . '<button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Cerrar"></button>'
           . '</div>';
    }
}
