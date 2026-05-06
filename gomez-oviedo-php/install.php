<?php
/* ============================================================
   INSTALL.PHP  —  Instalador idempotente y auto-bloqueante
   ------------------------------------------------------------
   ============================================================ */

require_once __DIR__ . '/utilidades/define.php';
require_once __DIR__ . '/utilidades/db.php';

$LOCK_FILE = __DIR__ . '/install.lock';
$logs      = [];
$errores   = [];
$instalado = false;

function log_paso(string $msg, bool $ok = true): void {
    global $logs;
    $logs[] = ['ok' => $ok, 'msg' => $msg];
}


// ============================================================
// 0. AUTO-BLOQUEO
// ============================================================
if (file_exists($LOCK_FILE)) {
    $lockTime = date('d/m/Y H:i:s', (int) file_get_contents($LOCK_FILE));
    ?>
    <!DOCTYPE html><html lang="es"><head>
        <meta charset="UTF-8"><title>Sistema bloqueado</title>
        <link rel="stylesheet" href="css/bootstrap.min.css">
        <style>body{background:#f4f7fb}.box{max-width:700px;margin:40px auto;background:#fff;border-radius:16px;padding:32px;box-shadow:0 30px 70px -12px rgba(15,30,60,.22)}</style>
    </head><body>
    <div class="box">
        <h1>🔒 Sistema ya instalado</h1>
        <p>El portal fue instalado el <strong><?= htmlspecialchars($lockTime) ?></strong>.</p>
        <p>Por seguridad, el instalador está bloqueado para evitar
           regeneración accidental de la base de datos o de las contraseñas.</p>
        <h3>¿Necesitas reinstalar?</h3>
        <ol>
            <li>Edita las contraseñas en <code>utilidades/define.php</code></li>
            <li>Borra el archivo <code>install.lock</code> de la carpeta del proyecto</li>
            <li>Vuelve a abrir <code>install.php</code></li>
        </ol>
        <div class="alert alert-warning">
            ⚠️ <strong>Recomendación:</strong> en producción borra <code>install.php</code> de tu servidor.
            Cualquiera con acceso a su URL podría reinstalar el sistema y resetear contraseñas.
        </div>
        <a href="login.php" class="btn btn-primary">Ir al login →</a>
    </div></body></html>
    <?php
    exit;
}


// ============================================================
// 1. VALIDAR CONTRASEÑAS DE define.php
// ============================================================
$pwdMap = [
    'admin'       => defined('PASSWORD_ADMIN')     ? PASSWORD_ADMIN     : '',
    'oviedo'      => defined('PASSWORD_OVIEDO')    ? PASSWORD_OVIEDO    : '',
    'gijon'       => defined('PASSWORD_GIJON')     ? PASSWORD_GIJON     : '',
    'cantabria'   => defined('PASSWORD_CANTABRIA') ? PASSWORD_CANTABRIA : '',
    'sanfernando' => defined('PASSWORD_SF')        ? PASSWORD_SF        : '',
    'leganes'     => defined('PASSWORD_LEGANES')   ? PASSWORD_LEGANES   : '',
    'malaga'      => defined('PASSWORD_MALAGA')    ? PASSWORD_MALAGA    : '',
];
$sinCambiar = [];
foreach ($pwdMap as $user => $pwd) {
    if (str_starts_with($pwd, 'CAMBIAR_') || strlen($pwd) < 8) {
        $sinCambiar[] = $user;
    }
}
if ($sinCambiar) {
    $errores[] = 'Tienes contraseñas sin cambiar (mínimo 8 caracteres y sin el prefijo "CAMBIAR_") '
               . 'en utilidades/define.php para los usuarios: '
               . implode(', ', $sinCambiar)
               . '. Edita ese archivo antes de continuar.';
}


// ============================================================
// 2. CONECTAR Y CREAR BD
// ============================================================
if (!$errores) {
    try {
        $srv = conectarServidorMySQL();
        log_paso('Conexión con MySQL OK (' . DB_HOST . ':' . DB_PORT . ')');
        $srv->exec('CREATE DATABASE IF NOT EXISTS `' . DB_NAME . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
        log_paso('Base de datos `' . DB_NAME . '` lista');
    } catch (\Throwable $e) {
        $errores[] = 'Error conectando a MySQL: ' . $e->getMessage();
    }
}


// ============================================================
// 3. CREAR TABLAS
// ============================================================
if (!$errores) {
    try {
        $pdo = conectarDB();
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');

        $pdo->exec("CREATE TABLE IF NOT EXISTS sedes (
            id_sede INT AUTO_INCREMENT PRIMARY KEY, nombre VARCHAR(100) NOT NULL UNIQUE,
            email VARCHAR(150) NOT NULL, activo TINYINT(1) NOT NULL DEFAULT 1,
            fecha_creacion DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $pdo->exec("CREATE TABLE IF NOT EXISTS usuarios (
            id_usuario INT AUTO_INCREMENT PRIMARY KEY, username VARCHAR(50) NOT NULL UNIQUE,
            nombre VARCHAR(100) NOT NULL, apellidos VARCHAR(150) NULL,
            email VARCHAR(150) NOT NULL UNIQUE, pass VARCHAR(255) NOT NULL,
            tipo_usuario ENUM('admin','sede','operario') NOT NULL DEFAULT 'operario',
            id_sede INT NULL, telefono VARCHAR(30) NULL,
            activo TINYINT(1) NOT NULL DEFAULT 1, ultimo_acceso DATETIME NULL,
            fecha_creacion DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            fecha_modificacion DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            CONSTRAINT fk_usuarios_sede FOREIGN KEY (id_sede) REFERENCES sedes(id_sede) ON DELETE SET NULL,
            INDEX idx_usuarios_email (email), INDEX idx_usuarios_username (username), INDEX idx_usuarios_activo (activo)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $pdo->exec("CREATE TABLE IF NOT EXISTS tipos_fallo (
            id_tipo_fallo INT AUTO_INCREMENT PRIMARY KEY, nombre VARCHAR(150) NOT NULL UNIQUE,
            activo TINYINT(1) NOT NULL DEFAULT 1, orden INT NOT NULL DEFAULT 0
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $pdo->exec("CREATE TABLE IF NOT EXISTS urgencias (
            id_urgencia INT AUTO_INCREMENT PRIMARY KEY, codigo VARCHAR(20) NOT NULL UNIQUE,
            nombre VARCHAR(50) NOT NULL, nivel INT NOT NULL DEFAULT 0
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $pdo->exec("CREATE TABLE IF NOT EXISTS incidencias (
            id_incidencia INT AUTO_INCREMENT PRIMARY KEY,
            tipo ENUM('pinchazo','averia','devolucion') NOT NULL, ticket VARCHAR(30) NULL UNIQUE,
            id_sede INT NULL, id_usuario INT NULL,
            contrato_codigo VARCHAR(50) NULL, cliente_nombre VARCHAR(200) NULL,
            obra_nombre VARCHAR(200) NULL, maquina_modelo VARCHAR(200) NULL,
            numero_maquina VARCHAR(50) NULL, sede_nombre VARCHAR(100) NULL,
            contacto_nombre VARCHAR(100) NULL, contacto_apellidos VARCHAR(150) NULL,
            contacto_email VARCHAR(150) NULL, contacto_telefono VARCHAR(30) NULL,
            latitud DECIMAL(10,7) NULL, longitud DECIMAL(10,7) NULL,
            ubicacion_aclaracion TEXT NULL, observaciones TEXT NULL,
            estado ENUM('borrador','enviada','procesada','cerrada') NOT NULL DEFAULT 'enviada',
            email_enviado TINYINT(1) NOT NULL DEFAULT 0, email_destinatario VARCHAR(150) NULL,
            email_error TEXT NULL, fecha_creacion DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            CONSTRAINT fk_inc_sede FOREIGN KEY (id_sede) REFERENCES sedes(id_sede) ON DELETE SET NULL,
            CONSTRAINT fk_inc_usuario FOREIGN KEY (id_usuario) REFERENCES usuarios(id_usuario) ON DELETE SET NULL,
            INDEX idx_inc_tipo (tipo), INDEX idx_inc_sede (id_sede), INDEX idx_inc_fecha (fecha_creacion)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $pdo->exec("CREATE TABLE IF NOT EXISTS incidencias_pinchazo (
            id_incidencia INT PRIMARY KEY,
            posicion_rueda ENUM('Delantera Izq.','Delantera Der.','Trasera Izq.','Trasera Der.') NULL,
            CONSTRAINT fk_pinch_inc FOREIGN KEY (id_incidencia) REFERENCES incidencias(id_incidencia) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $pdo->exec("CREATE TABLE IF NOT EXISTS incidencias_averia (
            id_incidencia INT PRIMARY KEY, id_tipo_fallo INT NULL, tipo_fallo_texto VARCHAR(150) NULL,
            id_urgencia INT NULL, impacto_operativo ENUM('operativa','parada') NULL,
            descripcion_tecnica TEXT NULL,
            CONSTRAINT fk_av_inc FOREIGN KEY (id_incidencia) REFERENCES incidencias(id_incidencia) ON DELETE CASCADE,
            CONSTRAINT fk_av_tipo FOREIGN KEY (id_tipo_fallo) REFERENCES tipos_fallo(id_tipo_fallo) ON DELETE SET NULL,
            CONSTRAINT fk_av_urgencia FOREIGN KEY (id_urgencia) REFERENCES urgencias(id_urgencia) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $pdo->exec("CREATE TABLE IF NOT EXISTS incidencias_devolucion (
            id_incidencia INT PRIMARY KEY, horas_finales INT NULL, comentarios TEXT NULL,
            CONSTRAINT fk_dev_inc FOREIGN KEY (id_incidencia) REFERENCES incidencias(id_incidencia) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $pdo->exec("CREATE TABLE IF NOT EXISTS incidencia_fotos (
            id_foto INT AUTO_INCREMENT PRIMARY KEY, id_incidencia INT NOT NULL,
            ruta VARCHAR(500) NOT NULL, orden INT NOT NULL DEFAULT 0,
            fecha_creacion DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            CONSTRAINT fk_fotos_inc FOREIGN KEY (id_incidencia) REFERENCES incidencias(id_incidencia) ON DELETE CASCADE,
            INDEX idx_fotos_inc (id_incidencia)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
        log_paso('Tablas creadas o ya existentes (9 tablas)');
    } catch (\Throwable $e) {
        $errores[] = 'Error creando tablas: ' . $e->getMessage();
    }
}


// ============================================================
// 4. SEMBRAR DATOS
// ============================================================
if (!$errores) {
    try {
        $pdo = conectarDB();

        // SEDES
        $sedes = [
            ['Oviedo',                  'otaborda@gomezoviedo.com'],
            ['Gijón',                   'gijon@gomezoviedo.com'],
            ['Cantabria',               'cantabria@gomezoviedo.com'],
            ['San Fernando de Henares', 'practicasgomezpracticas@gmail.com'],
            ['Leganés',                 'mblanco@gomezoviedo.com'],
            ['Málaga',                  'malaga@gomezoviedo.com'],
        ];
        $stmt = $pdo->prepare('INSERT IGNORE INTO sedes (nombre, email) VALUES (?, ?)');
        foreach ($sedes as $s) $stmt->execute($s);
        log_paso('Sedes sembradas (' . count($sedes) . ')');

        // TIPOS FALLO
        $fallos = [
            'Le cuesta arrancar pero arranca','No arranca','Sale mucho humo gris',
            'Sale mucho humo negro','No hay potencia','Arranca y se para',
            'Pérdida de combustible','Pérdida de aceite','Bajo nivel aceite',
            'Pérdida refrigerante','Bajo nivel refrigerante',
            'Batería sin carga o no carga correctamente','Roto joystick/interruptor',
            'No funcionan luces/rotativo','Rotura o mal estado de cables',
            'Fallo eléctrico indeterminado','No se mueve','No funciona algún freno',
            'No funciona la dirección','Rotura latiguillo','Rotura correa',
            'Rotura cuerda de arranque','Se ha salido una rueda/oruga',
            'Pinchazo o tajo en rueda/oruga','Avería general/Mal funcionamiento (DESCRIBIR)',
            'Revisión/mantenimiento preventivo','Conexión de grupo a depósito externo',
            'Pérdida/rotura de llaves (indicar cuál)','Cambio/sustitución retrovisor',
            'La manguera pierde aire o agua','Caída o vuelco de la maquinaria',
            'La dejó sin combustible. Requiere purgar',
        ];
        $stmt = $pdo->prepare('INSERT IGNORE INTO tipos_fallo (nombre, orden) VALUES (?, ?)');
        foreach ($fallos as $i => $nombre) $stmt->execute([$nombre, $i + 1]);
        log_paso('Tipos de fallo sembrados (' . count($fallos) . ')');

        // URGENCIAS
        $urgencias = [
            ['critica', 'CRÍTICA', 4],
            ['alta',    'ALTA',    3],
            ['media',   'MEDIA',   2],
            ['baja',    'BAJA',    1],
        ];
        $stmt = $pdo->prepare('INSERT IGNORE INTO urgencias (codigo, nombre, nivel) VALUES (?, ?, ?)');
        foreach ($urgencias as $u) $stmt->execute($u);
        log_paso('Niveles de urgencia sembrados (4)');

        // ----------- USUARIOS PREDEFINIDOS -----------
        $sedesMap = [];
        foreach ($pdo->query('SELECT id_sede, nombre FROM sedes')->fetchAll() as $s) {
            $sedesMap[$s['nombre']] = (int) $s['id_sede'];
        }
        $semilla = [
            // [username, nombre, email, sede(name|null), rol]
            ['admin',       'Administrador',     'admin@gomezoviedo.com',     null,                       'admin'],
            ['oviedo',      'Sede Oviedo',       'oviedo@gomezoviedo.com',    'Oviedo',                   'sede'],
            ['gijon',       'Sede Gijón',        'gijon@gomezoviedo.com',     'Gijón',                    'sede'],
            ['cantabria',   'Sede Cantabria',    'cantabria@gomezoviedo.com', 'Cantabria',                'sede'],
            ['sanfernando', 'Sede San Fernando', 'sf@gomezoviedo.com',        'San Fernando de Henares',  'sede'],
            ['leganes',     'Sede Leganés',      'leganes@gomezoviedo.com',   'Leganés',                  'sede'],
            ['malaga',      'Sede Málaga',       'malaga@gomezoviedo.com',    'Málaga',                   'sede'],
        ];

        $stmtBuscar = $pdo->prepare('SELECT id_usuario FROM usuarios WHERE username = ? LIMIT 1');
        $stmtIns    = $pdo->prepare('
            INSERT INTO usuarios (username, nombre, email, pass, tipo_usuario, id_sede)
            VALUES (?, ?, ?, ?, ?, ?)
        ');
        $stmtUpd    = $pdo->prepare('
            UPDATE usuarios
            SET pass = ?, nombre = ?, email = ?, tipo_usuario = ?, id_sede = ?, activo = 1
            WHERE username = ?
        ');

        $insertados = 0;
        $actualizados = 0;
        foreach ($semilla as $u) {
            [$username, $nombre, $email, $sedeNombre, $rol] = $u;
            $idSede   = $sedeNombre ? ($sedesMap[$sedeNombre] ?? null) : null;
            $passwd   = $pwdMap[$username] ?? null;
            if (!$passwd) continue;
            $hash     = password_hash($passwd, PASSWORD_DEFAULT);

            $stmtBuscar->execute([$username]);
            if ($stmtBuscar->fetch()) {
                $stmtUpd->execute([$hash, $nombre, $email, $rol, $idSede, $username]);
                $actualizados++;
            } else {
                $stmtIns->execute([$username, $nombre, $email, $hash, $rol, $idSede]);
                $insertados++;
            }
        }
        log_paso("Usuarios predefinidos: $insertados creados, $actualizados actualizados con las contraseñas de define.php");

        // Crear lock file
        @file_put_contents($LOCK_FILE, (string) time());
        $instalado = true;
        log_paso('Instalador bloqueado (install.lock creado)');

    } catch (\Throwable $e) {
        $errores[] = 'Error sembrando datos: ' . $e->getMessage();
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Instalación · <?= APP_NAME ?></title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="css/bootstrap.min.css">
    <link rel="stylesheet" href="css/style.css">
    <style>
        body { background:#f4f7fb; }
        .install-box { max-width:760px; margin:40px auto; background:#fff;
                       border-radius:16px; padding:32px;
                       box-shadow:0 30px 70px -12px rgba(15,30,60,.22); }
        .step { padding:10px 14px; margin:8px 0; border-radius:10px;
                background:#f0fdf4; border-left:4px solid #10b981; font-size:14px; }
        .step.bad { background:#fef2f2; border-color:#dc2626; }
        .danger-banner { background:#fef2f2;border:2px solid #dc2626;border-radius:12px;
                         padding:18px;margin-top:24px; }
        .danger-banner h3 { color:#7f1d1d; }
    </style>
</head>
<body>

<div class="install-box">
    <h1>🛠️ Instalación de <?= APP_NAME ?></h1>

    <?php foreach ($logs as $l): ?>
        <div class="step <?= $l['ok'] ? '' : 'bad' ?>">
            <?= $l['ok'] ? '✅' : '❌' ?> <?= htmlspecialchars($l['msg']) ?>
        </div>
    <?php endforeach; ?>

    <?php if ($errores): ?>
        <div class="alert alert-danger mt-3">
            <strong>⚠️ Instalación NO realizada:</strong>
            <?php foreach ($errores as $e): ?>
                <div class="mt-2">❌ <?= htmlspecialchars($e) ?></div>
            <?php endforeach; ?>
        </div>
        <?php if ($sinCambiar): ?>
        <div class="alert alert-info">
            <strong>Cómo arreglarlo:</strong> abre <code>utilidades/define.php</code>
            con un editor de texto y cambia las constantes
            <code>PASSWORD_*</code> a contraseñas reales (mínimo 8 caracteres,
            sin el prefijo "CAMBIAR_"). Luego recarga esta página.
        </div>
        <?php endif; ?>
    <?php elseif ($instalado): ?>
        <div class="alert alert-success mt-3">
            🎉 Instalación completada. Ya puedes acceder al portal con los usuarios
            que has configurado en <code>define.php</code>.
        </div>

        <h3 class="mt-4">👥 Usuarios creados / actualizados</h3>
        <p>Las contraseñas son las que TÚ pusiste en <code>utilidades/define.php</code>.
           Apúntalas en un sitio seguro:</p>
        <table class="table table-sm">
            <thead><tr><th>Usuario</th><th>Rol</th><th>Sede</th></tr></thead>
            <tbody>
                <tr><td><strong>admin</strong></td><td><span class="badge bg-danger">admin</span></td><td>—</td></tr>
                <tr><td>oviedo</td><td><span class="badge bg-primary">sede</span></td><td>Oviedo</td></tr>
                <tr><td>gijon</td><td><span class="badge bg-primary">sede</span></td><td>Gijón</td></tr>
                <tr><td>cantabria</td><td><span class="badge bg-primary">sede</span></td><td>Cantabria</td></tr>
                <tr><td>sanfernando</td><td><span class="badge bg-primary">sede</span></td><td>San Fernando de Henares</td></tr>
                <tr><td>leganes</td><td><span class="badge bg-primary">sede</span></td><td>Leganés</td></tr>
                <tr><td>malaga</td><td><span class="badge bg-primary">sede</span></td><td>Málaga</td></tr>
            </tbody>
        </table>

        <div class="danger-banner">
            <h3>🔒 Pasos finales de seguridad</h3>
            <ol class="mb-0">
                <li>El instalador se ha <strong>auto-bloqueado</strong> (creó <code>install.lock</code>).
                    No se podrá ejecutar de nuevo a menos que borres ese archivo.</li>
                <li>Para máxima seguridad en producción, <strong>borra <code>install.php</code></strong>
                    de tu servidor.</li>
                <li>Cuando quieras desplegar el portal en internet (no solo en localhost),
                    asegúrate de tener HTTPS activo.</li>
            </ol>
        </div>

        <a href="login.php" class="btn btn-primary mt-4 w-100" style="padding:14px;">
            Ir a iniciar sesión →
        </a>
    <?php endif; ?>
</div>

</body>
</html>