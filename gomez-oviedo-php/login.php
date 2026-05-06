<?php
/* ============================================================
   LOGIN.PHP  —  Inicio de sesión + Registro
   ============================================================ */

require_once __DIR__ . '/utilidades/funciones.php';

iniciarSesion();

// Si ya está autenticado, redirige al dashboard
if (usuarioActual()) {
    header('Location: secciones/usuarios.php');
    exit;
}

// Verifica que las tablas existen — si no, redirige a install.php
try {
    $pdo = conectarDB();
    $pdo->query('SELECT 1 FROM usuarios LIMIT 1');
} catch (\Throwable $e) {
    header('Location: install.php');
    exit;
}

$errores  = [];
$tabActiva = 'login';

// ============================================================
// PROCESAR POST
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if (!verificarCsrf($_POST['csrf'] ?? null)) {
        $errores[] = 'Token de seguridad inválido. Recarga la página.';
    } else {

        $accion = $_POST['accion'] ?? '';

        // ---------- LOGIN ----------
        if ($accion === 'login') {
            $tabActiva = 'login';

            // ⚠️ ARREGLO: rate limiting por IP+usuario
            $usrIntento = strtolower(trim($_POST['username'] ?? ''));
            $claveRL    = ipCliente() . '|' . $usrIntento;

            if (!rateLimitPermitido($claveRL)) {
                $errores[] = 'Demasiados intentos fallidos. Espera 15 minutos antes de volver a intentarlo.';
            } else {
                $user = autenticar($usrIntento, $_POST['password'] ?? '');

                if ($user) {
                    rateLimitLimpiar($claveRL);
                    loginUsuario($user);
                    flash('success', 'Bienvenido, ' . $user['nombre']);
                    header('Location: secciones/usuarios.php');
                    exit;
                }
                $segundos  = rateLimitRegistrarFallo($claveRL);
                $errores[] = 'Usuario o contraseña incorrectos.';
            }
        }

        // ---------- REGISTRO ----------
        elseif ($accion === 'registro') {
            $tabActiva = 'registro';

            $u   = limpiar($_POST['reg_user']      ?? '');
            $n   = limpiar($_POST['reg_nombre']    ?? '');
            $ap  = limpiar($_POST['reg_apellidos'] ?? '');
            $em  = limpiar($_POST['reg_email']     ?? '');
            $sd  = limpiar($_POST['reg_sede']      ?? '');
            $p1  = $_POST['reg_pass']  ?? '';
            $p2  = $_POST['reg_pass2'] ?? '';

            if ($p1 !== $p2) {
                $errores[] = 'Las contraseñas no coinciden.';
            } else {
                $r = registrarUsuario($u, $p1, $n, $ap, $em, $sd ?: null);
                if ($r['ok']) {
                    // Auto-login
                    $user = autenticar($u, $p1);
                    if ($user) {
                        loginUsuario($user);
                        flash('success', '¡Cuenta creada! Bienvenido, ' . $user['nombre']);
                        header('Location: secciones/usuarios.php');
                        exit;
                    }
                } else {
                    $errores = $r['errores'];
                }
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#0052a5">
    <title>Acceso · <?= APP_NAME ?></title>

    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="css/bootstrap.min.css">
    <link rel="stylesheet" href="css/style.css">
</head>
<body>

<div class="login-overlay">

    <div class="login-box">

        <!-- Marca -->
        <div class="login-brand">
            <div class="brand-logo">GO</div>
            <div class="brand-text">
                <strong><?= APP_NAME ?></strong>
                <span><?= APP_TAGLINE ?></span>
            </div>
        </div>

        <!-- Tabs Login / Registro -->
        <ul class="nav nav-pills nav-fill auth-tabs-bs mb-4" role="tablist">
            <li class="nav-item">
                <button class="nav-link <?= $tabActiva === 'login' ? 'active' : '' ?>"
                        data-bs-toggle="pill" data-bs-target="#paneLogin" type="button">
                    Iniciar sesión
                </button>
            </li>
            <li class="nav-item">
                <button class="nav-link <?= $tabActiva === 'registro' ? 'active' : '' ?>"
                        data-bs-toggle="pill" data-bs-target="#paneRegistro" type="button">
                    Registrarse
                </button>
            </li>
        </ul>

        <?php if ($errores): ?>
            <div class="alert alert-danger" role="alert">
                <?php foreach ($errores as $e): ?>
                    <div>❌ <?= escapar($e) ?></div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <div class="tab-content">

            <!-- ========== PANEL LOGIN ========== -->
            <div class="tab-pane fade <?= $tabActiva === 'login' ? 'show active' : '' ?>" id="paneLogin">

                <h1 class="login-title">Bienvenido de vuelta</h1>
                <p class="login-subtitle">Introduce tus credenciales para acceder al portal</p>

                <form method="post" autocomplete="on" novalidate>
                    <?= inputCsrf() ?>
                    <input type="hidden" name="accion" value="login">

                    <div class="input-icon mb-3">
                        <i class="fa-solid fa-user"></i>
                        <input class="form-control input" type="text" name="username"
                               placeholder="Usuario" autocomplete="username" required>
                    </div>

                    <div class="input-icon mb-3">
                        <i class="fa-solid fa-lock"></i>
                        <input class="form-control input" type="password" name="password"
                               placeholder="Contraseña" autocomplete="current-password" required>
                    </div>

                    <button type="submit" class="btn-primary w-100">
                        <i class="fa-solid fa-arrow-right-to-bracket"></i>
                        Acceder al portal
                    </button>
                </form>
            </div>

            <!-- ========== PANEL REGISTRO ========== -->
            <div class="tab-pane fade <?= $tabActiva === 'registro' ? 'show active' : '' ?>" id="paneRegistro">

                <h1 class="login-title">Crear cuenta</h1>
                <p class="login-subtitle">Únete al portal de gestión <?= APP_NAME ?></p>

                <form method="post" autocomplete="off" novalidate>
                    <?= inputCsrf() ?>
                    <input type="hidden" name="accion" value="registro">

                    <div class="input-icon mb-2">
                        <i class="fa-solid fa-user-tag"></i>
                        <input class="form-control input" type="text" name="reg_user"
                               placeholder="Nombre de usuario" required
                               value="<?= escapar($_POST['reg_user'] ?? '') ?>">
                    </div>

                    <div class="row g-2">
                        <div class="col-md-6">
                            <div class="input-icon mb-2">
                                <i class="fa-solid fa-id-card"></i>
                                <input class="form-control input" type="text" name="reg_nombre"
                                       placeholder="Nombre" required
                                       value="<?= escapar($_POST['reg_nombre'] ?? '') ?>">
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="input-icon mb-2">
                                <i class="fa-solid fa-id-card-clip"></i>
                                <input class="form-control input" type="text" name="reg_apellidos"
                                       placeholder="Apellidos"
                                       value="<?= escapar($_POST['reg_apellidos'] ?? '') ?>">
                            </div>
                        </div>
                    </div>

                    <div class="input-icon mb-2">
                        <i class="fa-solid fa-envelope"></i>
                        <input class="form-control input" type="email" name="reg_email"
                               placeholder="Email" required
                               value="<?= escapar($_POST['reg_email'] ?? '') ?>">
                    </div>

                    <div class="input-icon mb-2">
                        <i class="fa-solid fa-building"></i>
                        <select class="form-select input" name="reg_sede">
                            <option value="">— Sede (opcional) —</option>
                            <?php foreach (obtenerSedes() as $s): ?>
                                <option value="<?= escapar($s['nombre']) ?>"
                                    <?= ($_POST['reg_sede'] ?? '') === $s['nombre'] ? 'selected' : '' ?>>
                                    <?= escapar($s['nombre']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="row g-2 mb-3">
                        <div class="col-6">
                            <div class="input-icon mb-0">
                                <i class="fa-solid fa-lock"></i>
                                <input class="form-control input" type="password" name="reg_pass"
                                       placeholder="Contraseña (mín. 6)" required>
                            </div>
                        </div>
                        <div class="col-6">
                            <div class="input-icon mb-0">
                                <i class="fa-solid fa-lock"></i>
                                <input class="form-control input" type="password" name="reg_pass2"
                                       placeholder="Repetir" required>
                            </div>
                        </div>
                    </div>

                    <button type="submit" class="btn-primary w-100">
                        <i class="fa-solid fa-user-plus"></i>
                        Crear mi cuenta
                    </button>
                </form>
            </div>

        </div>

    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
