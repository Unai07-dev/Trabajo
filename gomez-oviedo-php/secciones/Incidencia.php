<?php
/* ============================================================
   INCIDENCIA.PHP 
   ------------------------------------------------------------
   URL:   secciones/incidencia.php?id=123
   Acceso por rol:
     - admin    → cualquier incidencia
     - sede     → solo las de su sede
     - operario → SIN ACCESO (no ve historial ni detalles)
   ============================================================ */

require_once __DIR__ . '/../utilidades/funciones.php';
requiereLogin();

$u   = usuarioActual();

// ============================================================
// BLOQUEO PARA OPERARIOS — no pueden ver el detalle ni el histórico
// (solo ven los contadores en el dashboard de usuarios.php)
// ============================================================
if ($u['rol'] === 'operario') {
    flash('error', 'No tienes acceso al detalle de incidencias.');
    header('Location: usuarios.php'); exit;
}

$id = (int) ($_GET['id'] ?? 0);

if ($id <= 0) {
    flash('error', 'ID de incidencia no válido.');
    header('Location: usuarios.php'); exit;
}

$datos = cargarIncidenciaConAcceso($id);
if (!$datos) {
    flash('error', 'No tienes acceso a esa incidencia o no existe.');
    header('Location: usuarios.php'); exit;
}

$inc   = $datos['inc'];
$hija  = $datos['hija'];
$fotos = $datos['fotos'];

// Configuración por tipo
$config = match ($inc['tipo']) {
    'averia'     => ['titulo' => 'Avería',     'icono' => 'fa-triangle-exclamation', 'volver' => 'averias.php',     'color' => '#dc2626'],
    'pinchazo'   => ['titulo' => 'Pinchazo',   'icono' => 'fa-screwdriver-wrench',  'volver' => 'pinchazos.php',   'color' => '#0052a5'],
    'devolucion' => ['titulo' => 'Devolución', 'icono' => 'fa-rotate-left',          'volver' => 'devoluciones.php','color' => '#10b981'],
    default      => ['titulo' => 'Incidencia', 'icono' => 'fa-file',                 'volver' => 'usuarios.php',   'color' => '#64748b'],
};

renderHeader('Detalle ' . $config['titulo'], $config['icono']);
?>

<style>
.detail-header {
    background: linear-gradient(135deg, <?= $config['color'] ?>22, <?= $config['color'] ?>08);
    border-left: 5px solid <?= $config['color'] ?>;
    border-radius: 12px;
    padding: 24px;
    margin-bottom: 24px;
}
.detail-header h1 { color: <?= $config['color'] ?>; margin-bottom: 8px; }
.detail-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
    gap: 16px;
    margin-bottom: 24px;
}
.detail-card {
    background: #fff;
    border-radius: 12px;
    padding: 20px;
    box-shadow: 0 4px 12px rgba(0,0,0,.05);
    border: 1px solid #e2e8f0;
}
.detail-card h4 {
    font-size: 13px;
    text-transform: uppercase;
    color: #64748b;
    letter-spacing: 1px;
    margin-bottom: 12px;
    padding-bottom: 8px;
    border-bottom: 1px solid #f1f5f9;
}
.detail-row { display: flex; padding: 6px 0; font-size: 14px; }
.detail-row .label { width: 130px; color: #64748b; flex-shrink: 0; }
.detail-row .value { color: #0f172a; font-weight: 500; word-break: break-word; }
.detail-row .value.empty { color: #cbd5e1; font-style: italic; font-weight: 400; }
.detail-fotos {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(180px, 1fr));
    gap: 12px;
    margin-top: 12px;
}
.detail-fotos a {
    display: block;
    aspect-ratio: 1;
    overflow: hidden;
    border-radius: 8px;
    border: 1px solid #e2e8f0;
}
.detail-fotos img {
    width: 100%; height: 100%; object-fit: cover;
    transition: transform .2s;
}
.detail-fotos a:hover img { transform: scale(1.05); }
.estado-pill {
    display: inline-block;
    padding: 4px 12px;
    border-radius: 999px;
    font-size: 12px;
    font-weight: 600;
    text-transform: uppercase;
}
.estado-borrador  { background: #fef3c7; color: #92400e; }
.estado-enviada   { background: #dcfce7; color: #166534; }
.estado-procesada { background: #dbeafe; color: #1e40af; }
.estado-cerrada   { background: #e2e8f0; color: #475569; }
</style>

<!-- HEADER -->
<section class="detail-header">
    <div class="d-flex justify-content-between align-items-start flex-wrap gap-3">
        <div>
            <h1><i class="fa-solid <?= $config['icono'] ?>"></i> <?= $config['titulo'] ?></h1>
            <div class="text-muted">
                ID #<?= (int) $inc['id_incidencia'] ?>
                <?php if ($inc['ticket']): ?> · Ticket <strong><?= escapar($inc['ticket']) ?></strong><?php endif; ?>
                · Creada <?= date('d/m/Y · H:i', strtotime($inc['fecha_creacion'])) ?>
            </div>
        </div>
        <div class="d-flex flex-column align-items-end gap-2">
            <span class="estado-pill estado-<?= escapar($inc['estado']) ?>"><?= escapar($inc['estado']) ?></span>
            <?php if ($inc['email_enviado']): ?>
                <span style="font-size:12px;color:#10b981;">
                    <i class="fa-solid fa-envelope-circle-check"></i>
                    Email enviado a <?= escapar($inc['email_destinatario']) ?>
                </span>
            <?php elseif ($inc['email_error']): ?>
                <span style="font-size:12px;color:#dc2626;" title="<?= escapar($inc['email_error']) ?>">
                    <i class="fa-solid fa-envelope-circle-xmark"></i> Email no enviado
                </span>
            <?php endif; ?>
        </div>
    </div>
</section>


<!-- TARJETAS DE DATOS -->
<div class="detail-grid">

    <!-- CONTRATO -->
    <div class="detail-card">
        <h4><i class="fa-solid fa-file-contract"></i> Contrato</h4>
        <div class="detail-row">
            <span class="label">ID Contrato</span>
            <span class="value <?= $inc['contrato_codigo'] ? '' : 'empty' ?>">
                <?= escapar($inc['contrato_codigo'] ?: '—') ?>
            </span>
        </div>
        <div class="detail-row">
            <span class="label">Cliente</span>
            <span class="value <?= $inc['cliente_nombre'] ? '' : 'empty' ?>">
                <?= escapar($inc['cliente_nombre'] ?: '—') ?>
            </span>
        </div>
        <div class="detail-row">
            <span class="label">Obra</span>
            <span class="value <?= $inc['obra_nombre'] ? '' : 'empty' ?>">
                <?= escapar($inc['obra_nombre'] ?: '—') ?>
            </span>
        </div>
        <div class="detail-row">
            <span class="label">Sede</span>
            <span class="value"><?= escapar($inc['sede_nombre'] ?: '—') ?></span>
        </div>
    </div>

    <!-- MÁQUINA -->
    <div class="detail-card">
        <h4><i class="fa-solid fa-truck-monster"></i> Máquina</h4>
        <div class="detail-row">
            <span class="label">Modelo</span>
            <span class="value <?= $inc['maquina_modelo'] ? '' : 'empty' ?>">
                <?= escapar($inc['maquina_modelo'] ?: '—') ?>
            </span>
        </div>
        <div class="detail-row">
            <span class="label">Nº máquina</span>
            <span class="value <?= $inc['numero_maquina'] ? '' : 'empty' ?>">
                <?= escapar($inc['numero_maquina'] ?: '—') ?>
            </span>
        </div>

        <?php if ($inc['tipo'] === 'averia'): ?>
            <div class="detail-row">
                <span class="label">Tipo de fallo</span>
                <span class="value">
                    <?= escapar($hija['tipo_fallo_nombre'] ?? $hija['tipo_fallo_texto'] ?? '—') ?>
                </span>
            </div>
            <div class="detail-row">
                <span class="label">Urgencia</span>
                <span class="value">
                    <?php
                    $u_cod = $hija['urgencia_codigo'] ?? '';
                    $u_nom = $hija['urgencia_nombre'] ?? '—';
                    $bgU = match($u_cod) {
                        'critica' => '#dc2626', 'alta' => '#ea580c',
                        'media'   => '#f59e0b', 'baja' => '#64748b',
                        default => '#cbd5e1',
                    };
                    ?>
                    <span style="background:<?= $bgU ?>;color:#fff;padding:2px 10px;border-radius:6px;font-size:12px;">
                        <?= escapar($u_nom) ?>
                    </span>
                </span>
            </div>
            <div class="detail-row">
                <span class="label">Estado máquina</span>
                <span class="value"><?= escapar($hija['impacto_operativo'] ?? '—') ?></span>
            </div>
        <?php endif; ?>

        <?php if ($inc['tipo'] === 'pinchazo'): ?>
            <div class="detail-row">
                <span class="label">Posición rueda</span>
                <span class="value"><?= escapar($hija['posicion_rueda'] ?? '—') ?></span>
            </div>
        <?php endif; ?>

        <?php if ($inc['tipo'] === 'devolucion'): ?>
            <div class="detail-row">
                <span class="label">Horas finales</span>
                <span class="value"><?= $hija['horas_finales'] !== null ? (int) $hija['horas_finales'] . ' h' : '—' ?></span>
            </div>
        <?php endif; ?>
    </div>

    <!-- CONTACTO -->
    <div class="detail-card">
        <h4><i class="fa-solid fa-user"></i> Persona de contacto</h4>
        <div class="detail-row">
            <span class="label">Nombre</span>
            <span class="value <?= $inc['contacto_nombre'] ? '' : 'empty' ?>">
                <?= escapar(trim(($inc['contacto_nombre'] ?? '') . ' ' . ($inc['contacto_apellidos'] ?? '')) ?: '—') ?>
            </span>
        </div>
        <div class="detail-row">
            <span class="label">Teléfono</span>
            <span class="value <?= $inc['contacto_telefono'] ? '' : 'empty' ?>">
                <?php if ($inc['contacto_telefono']): ?>
                    <a href="tel:<?= escapar($inc['contacto_telefono']) ?>"><?= escapar($inc['contacto_telefono']) ?></a>
                <?php else: ?>—<?php endif; ?>
            </span>
        </div>
        <div class="detail-row">
            <span class="label">Email</span>
            <span class="value <?= $inc['contacto_email'] ? '' : 'empty' ?>">
                <?php if ($inc['contacto_email']): ?>
                    <a href="mailto:<?= escapar($inc['contacto_email']) ?>"><?= escapar($inc['contacto_email']) ?></a>
                <?php else: ?>—<?php endif; ?>
            </span>
        </div>
    </div>

    <!-- UBICACIÓN -->
    <div class="detail-card">
        <h4><i class="fa-solid fa-location-dot"></i> Ubicación</h4>
        <div class="detail-row">
            <span class="label">Coordenadas</span>
            <span class="value <?= $inc['latitud'] ? '' : 'empty' ?>">
                <?php if ($inc['latitud'] && $inc['longitud']): ?>
                    <a href="https://maps.google.com/?q=<?= $inc['latitud'] ?>,<?= $inc['longitud'] ?>"
                       target="_blank" rel="noopener">
                        <?= number_format((float) $inc['latitud'], 5) ?>, <?= number_format((float) $inc['longitud'], 5) ?>
                        <i class="fa-solid fa-up-right-from-square" style="font-size:11px;"></i>
                    </a>
                <?php else: ?>—<?php endif; ?>
            </span>
        </div>
        <?php if (!empty($inc['ubicacion_aclaracion'])): ?>
        <div class="detail-row">
            <span class="label">Detalle</span>
            <span class="value"><?= nl2br(escapar($inc['ubicacion_aclaracion'])) ?></span>
        </div>
        <?php endif; ?>
    </div>

    <!-- ENVÍO -->
    <div class="detail-card">
        <h4><i class="fa-solid fa-clipboard-check"></i> Envío y trazabilidad</h4>
        <div class="detail-row">
            <span class="label">Creado por</span>
            <span class="value">
                <?php
                $creador = trim(($inc['creador_nombre'] ?? '') . ' ' . ($inc['creador_apellidos'] ?? ''));
                if ($creador && !empty($inc['creador_username'])):
                ?>
                    <?= escapar($creador) ?> <small class="text-muted">(<?= escapar($inc['creador_username']) ?>)</small>
                <?php else: ?>
                    —
                <?php endif; ?>
            </span>
        </div>
        <div class="detail-row">
            <span class="label">Email enviado</span>
            <span class="value">
                <?= $inc['email_enviado'] ? '✅ Sí' : '❌ No' ?>
            </span>
        </div>
        <?php if ($inc['email_destinatario']): ?>
        <div class="detail-row">
            <span class="label">Destinatario</span>
            <span class="value"><?= escapar($inc['email_destinatario']) ?></span>
        </div>
        <?php endif; ?>
        <?php if ($inc['email_error']): ?>
        <div class="detail-row">
            <span class="label">Error email</span>
            <span class="value" style="color:#dc2626;font-size:13px;">
                <?= escapar($inc['email_error']) ?>
            </span>
        </div>
        <?php endif; ?>
    </div>

</div>


<!-- DESCRIPCIÓN / OBSERVACIONES (a ancho completo) -->
<?php
$descripcionLarga = '';
if ($inc['tipo'] === 'averia' && !empty($hija['descripcion_tecnica'])) {
    $descripcionLarga = $hija['descripcion_tecnica'];
} elseif ($inc['tipo'] === 'devolucion' && !empty($hija['comentarios'])) {
    $descripcionLarga = $hija['comentarios'];
} elseif (!empty($inc['observaciones'])) {
    $descripcionLarga = $inc['observaciones'];
}
?>
<?php if ($descripcionLarga): ?>
<div class="detail-card mb-4">
    <h4><i class="fa-solid fa-comment-dots"></i>
        <?= $inc['tipo'] === 'averia'     ? 'Descripción técnica'
          : ($inc['tipo'] === 'devolucion'? 'Comentarios'
                                          : 'Observaciones') ?>
    </h4>
    <p class="mb-0" style="white-space:pre-wrap;"><?= escapar($descripcionLarga) ?></p>
</div>
<?php endif; ?>


<!-- FOTOS -->
<?php if ($fotos): ?>
<div class="detail-card mb-4">
    <h4><i class="fa-solid fa-camera"></i> Fotos adjuntas (<?= count($fotos) ?>)</h4>
    <div class="detail-fotos">
        <?php foreach ($fotos as $f): ?>
            <a href="../<?= escapar($f['ruta']) ?>" target="_blank" rel="noopener">
                <img src="../<?= escapar($f['ruta']) ?>" alt="Foto" loading="lazy">
            </a>
        <?php endforeach; ?>
    </div>
</div>
<?php endif; ?>


<!-- BOTÓN VOLVER -->
<div class="mt-4 d-flex gap-2">
    <a href="<?= $config['volver'] ?>" class="btn btn-secondary">
        <i class="fa-solid fa-arrow-left"></i> Volver al listado
    </a>
    <a href="usuarios.php" class="btn btn-outline-secondary">
        <i class="fa-solid fa-house"></i> Inicio
    </a>
</div>


<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<?php renderFooter(); ?>