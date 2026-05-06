<?php
/* ============================================================
   USUARIOS.PHP  —  Dashboard + gestión de usuarios
   ------------------------------------------------------------
   admin    → ve estadísticas globales + lista de TODOS los usuarios
   sede     → ve estadísticas de su sede + lista de operarios de su sede
   operario → solo ve sus propias estadísticas (no ve lista de usuarios)
   ============================================================ */

require_once __DIR__ . '/../utilidades/funciones.php';
requiereLogin();

$u   = usuarioActual();
$pdo = conectarDB();


// ============================================================
// ACCIÓN: Eliminar usuario
//   admin → puede eliminar a cualquiera (excepto a sí mismo)
//   sede  → solo puede eliminar OPERARIOS DE SU SEDE
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['accion'] ?? '') === 'eliminar') {

    if (!verificarCsrf($_POST['csrf'] ?? null)) {
        flash('error', 'Token de seguridad inválido.');
        header('Location: usuarios.php'); exit;
    }
    if (!in_array($u['rol'], ['admin', 'sede'], true)) {
        flash('error', 'No tienes permisos para esta acción.');
        header('Location: usuarios.php'); exit;
    }

    $id = (int) ($_POST['id'] ?? 0);
    if (!$id) {
        flash('error', 'Usuario inválido.');
        header('Location: usuarios.php'); exit;
    }
    if ($id === (int) $u['id']) {
        flash('error', 'No puedes eliminarte a ti mismo.');
        header('Location: usuarios.php'); exit;
    }

    // Cargar datos del usuario a eliminar para validar permisos
    $stmt = $pdo->prepare('SELECT id_usuario, tipo_usuario, id_sede FROM usuarios WHERE id_usuario = ? LIMIT 1');
    $stmt->execute([$id]);
    $obj = $stmt->fetch();
    if (!$obj) {
        flash('error', 'El usuario no existe.');
        header('Location: usuarios.php'); exit;
    }

    
    if ($u['rol'] === 'sede') {
        if ($obj['tipo_usuario'] !== 'operario' || (int) $obj['id_sede'] !== (int) $u['id_sede']) {
            flash('error', 'Solo puedes eliminar operarios de tu propia sede.');
            header('Location: usuarios.php'); exit;
        }
    }

    $pdo->prepare('DELETE FROM usuarios WHERE id_usuario = ?')->execute([$id]);
    flash('success', 'Usuario eliminado.');
    header('Location: usuarios.php'); exit;
}


// ============================================================
// ESTADÍSTICAS  
// ============================================================
$alcance = alcanceIncidencias();
$stats   = ['averias' => 0, 'pinchazos' => 0, 'devoluciones' => 0];

$stmt = $pdo->prepare("
    SELECT i.tipo, COUNT(*) AS total
    FROM incidencias i
    WHERE {$alcance['where']}
    GROUP BY i.tipo
");
$stmt->execute($alcance['params']);
foreach ($stmt->fetchAll() as $r) {
    if ($r['tipo'] === 'averia')     $stats['averias']      = (int) $r['total'];
    if ($r['tipo'] === 'pinchazo')   $stats['pinchazos']    = (int) $r['total'];
    if ($r['tipo'] === 'devolucion') $stats['devoluciones'] = (int) $r['total'];
}


// ============================================================
// LISTA DE USUARIOS 
// ============================================================
$listaUsuarios = [];
if ($u['rol'] === 'admin') {
    $listaUsuarios = $pdo->query('
        SELECT u.id_usuario, u.username, u.nombre, u.apellidos, u.email,
               u.tipo_usuario, u.fecha_creacion, u.activo,
               s.nombre AS sede_nombre
        FROM   usuarios u
        LEFT JOIN sedes s ON s.id_sede = u.id_sede
        ORDER BY u.fecha_creacion DESC
    ')->fetchAll();
} elseif ($u['rol'] === 'sede') {
    $stmt = $pdo->prepare('
        SELECT u.id_usuario, u.username, u.nombre, u.apellidos, u.email,
               u.tipo_usuario, u.fecha_creacion, u.activo,
               s.nombre AS sede_nombre
        FROM   usuarios u
        LEFT JOIN sedes s ON s.id_sede = u.id_sede
        WHERE  u.id_sede = ?
        ORDER BY u.fecha_creacion DESC
    ');
    $stmt->execute([(int) $u['id_sede']]);
    $listaUsuarios = $stmt->fetchAll();
}

renderHeader('Inicio');
?>

<style>
.hero-welcome {
    background: linear-gradient(rgba(11,25,41,0.55), rgba(0,82,165,0.45)),
                url('https://tse2.mm.bing.net/th/id/OIP.ny9iAUqWStdfd44F_-FZIAHaCc?rs=1&amp;pid=ImgDetMain&amp;o=7&amp;rm=3') center/cover no-repeat !important;
    color: #fff;
}
.hero-welcome .hero-tag, .hero-welcome h1, .hero-welcome p {
    color:#fff; text-shadow:0 2px 8px rgba(0,0,0,0.4);
}
.hero-welcome-visual { display:none; }
</style>

<!-- HERO -->
<section class="hero-welcome">
    <div class="hero-welcome-text">
        <span class="hero-tag">
            Portal de Gestión · En línea ·
            <strong style="text-transform:uppercase;"><?= escapar($u['rol']) ?></strong>
            <?= $u['sede'] ? ' · ' . escapar($u['sede']) : '' ?>
        </span>
        <h1>Hola, <?= escapar($u['nombre']) ?></h1>
        <p>
            <?php if ($u['rol'] === 'admin'): ?>
                Vista global del portal: estás viendo incidencias y usuarios de TODAS las sedes.
            <?php elseif ($u['rol'] === 'sede'): ?>
                Vista de la sede de <strong><?= escapar($u['sede']) ?></strong>: incidencias y operarios de tu sede.
            <?php else: ?>
                Aquí ves tus propias incidencias. Reporta averías, pinchazos y devoluciones desde el menú.
            <?php endif; ?>
        </p>
    </div>
</section>

<!-- STATS -->
<section class="row g-3 mb-4">
    <div class="col-md-4">
        <div class="stat-card">
            <div class="stat-icon"><i class="fa-solid fa-rotate-left"></i></div>
            <div>
                <div class="stat-value"><?= $stats['devoluciones'] ?></div>
                <div class="stat-label">Devoluciones registradas</div>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="stat-card">
            <div class="stat-icon stat-icon-alert"><i class="fa-solid fa-triangle-exclamation"></i></div>
            <div>
                <div class="stat-value"><?= $stats['averias'] ?></div>
                <div class="stat-label">Averías reportadas</div>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="stat-card">
            <div class="stat-icon"><i class="fa-solid fa-screwdriver-wrench"></i></div>
            <div>
                <div class="stat-value"><?= $stats['pinchazos'] ?></div>
                <div class="stat-label">Pinchazos atendidos</div>
            </div>
        </div>
    </div>
</section>

<!-- ACCESOS RÁPIDOS -->
<section class="services-section">
    <h2 class="section-title">Accesos rápidos</h2>

    <div class="row g-4">
        <div class="col-md-4">
            <a href="devoluciones.php" class="service-card text-decoration-none">
                <div class="service-icon"><i class="fa-solid fa-rotate-left"></i></div>
                <h3>Devolución</h3>
                <p>Finaliza el alquiler de maquinaria y genera el acta de cierre.</p>
                <span class="service-cta">Generar acta <i class="fa-solid fa-arrow-right"></i></span>
            </a>
        </div>
        <div class="col-md-4">
            <a href="averias.php" class="service-card text-decoration-none">
                <div class="service-icon service-icon-alert"><i class="fa-solid fa-triangle-exclamation"></i></div>
                <h3>Avería</h3>
                <p>Reporta un fallo técnico urgente con toda la información del contrato.</p>
                <span class="service-cta">Abrir incidencia <i class="fa-solid fa-arrow-right"></i></span>
            </a>
        </div>
        <div class="col-md-4">
            <a href="pinchazos.php" class="service-card text-decoration-none">
                <div class="service-icon"><i class="fa-solid fa-screwdriver-wrench"></i></div>
                <h3>Pinchazo</h3>
                <p>Solicita asistencia neumática y taller móvil para tu máquina.</p>
                <span class="service-cta">Pedir asistencia <i class="fa-solid fa-arrow-right"></i></span>
            </a>
        </div>
    </div>
</section>


<?php if ($listaUsuarios): ?>
<!-- TABLA DE USUARIOS  (visible para admin y sede) -->
<section class="mt-5">
    <h2 class="section-title">
        <?= $u['rol'] === 'admin'
            ? 'Todos los usuarios del portal'
            : 'Usuarios de la sede de ' . escapar($u['sede']) ?>
    </h2>

    <div class="table-responsive bg-white rounded-3 shadow-sm p-3">
        <table class="table table-hover align-middle mb-0">
            <thead>
                <tr>
                    <th>Usuario</th>
                    <th>Nombre</th>
                    <th>Email</th>
                    <th>Sede</th>
                    <th>Rol</th>
                    <th>Alta</th>
                    <th>Estado</th>
                    <th class="text-end">Acción</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($listaUsuarios as $usuario): ?>
                    <?php
                    $bgRol = match($usuario['tipo_usuario']) {
                        'admin' => 'danger', 'sede'  => 'primary', default => 'secondary',
                    };
                    // ¿Puede el usuario actual eliminar a este? (jerarquía)
                    $puedeEliminar = false;
                    if ((int) $usuario['id_usuario'] !== (int) $u['id']) {
                        if ($u['rol'] === 'admin') {
                            $puedeEliminar = true;
                        } elseif ($u['rol'] === 'sede'
                                  && $usuario['tipo_usuario'] === 'operario') {
                            $puedeEliminar = true;
                        }
                    }
                    ?>
                <tr>
                    <td><strong><?= escapar($usuario['username']) ?></strong></td>
                    <td>
                        <?= escapar($usuario['nombre']) ?>
                        <?= $usuario['apellidos'] ? ' ' . escapar($usuario['apellidos']) : '' ?>
                    </td>
                    <td><?= escapar($usuario['email']) ?></td>
                    <td><?= escapar($usuario['sede_nombre'] ?? '—') ?></td>
                    <td>
                        <span class="badge bg-<?= $bgRol ?>">
                            <?= escapar($usuario['tipo_usuario']) ?>
                        </span>
                    </td>
                    <td><small class="text-muted"><?= date('d/m/Y', strtotime($usuario['fecha_creacion'])) ?></small></td>
                    <td>
                        <?php if ($usuario['activo']): ?>
                            <span class="badge bg-success-subtle text-success">Activo</span>
                        <?php else: ?>
                            <span class="badge bg-secondary">Inactivo</span>
                        <?php endif; ?>
                    </td>
                    <td class="text-end">
                        <?php if ($puedeEliminar): ?>
                            <form method="post" class="d-inline"
                                  onsubmit="return confirm('¿Eliminar al usuario <?= escapar($usuario['username']) ?>?');">
                                <?= inputCsrf() ?>
                                <input type="hidden" name="accion" value="eliminar">
                                <input type="hidden" name="id" value="<?= (int) $usuario['id_usuario'] ?>">
                                <button type="submit" class="btn btn-sm btn-outline-danger" title="Eliminar">
                                    <i class="fa-solid fa-trash"></i>
                                </button>
                            </form>
                        <?php elseif ((int) $usuario['id_usuario'] === (int) $u['id']): ?>
                            <small class="text-muted">— tú —</small>
                        <?php else: ?>
                            <small class="text-muted" title="No tienes permisos sobre este usuario">—</small>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>
<?php endif; ?>


<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<?php renderFooter(); ?>