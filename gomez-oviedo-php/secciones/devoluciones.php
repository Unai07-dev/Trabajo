<?php
/* ============================================================
   DEVOLUCIONES.PHP  —  Cierre de contrato + listado
   ============================================================ */

require_once __DIR__ . '/../utilidades/funciones.php';
requiereLogin();

$u   = usuarioActual();
$pdo = conectarDB();

// ============================================================
// PROCESAR ENVÍO
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['accion'] ?? '') === 'crear') {

    if (!verificarCsrf($_POST['csrf'] ?? null)) {
        flash('error', 'Token de seguridad inválido.');
        header('Location: devoluciones.php'); exit;
    }

    // ---------- VALIDACIÓN: CAMPOS OBLIGATORIOS ----------
    // (comentarios, ubicación y fotos son OPCIONALES)
    $camposReq = [
        'id_contrato'   => 'ID Contrato',
        'cliente'       => 'Cliente / Empresa',
        'obra'          => 'Obra',
        'tipo_maquina'  => 'Tipo de máquina',
        'num_maquina'   => 'Nº de máquina',
        'sede'          => 'Sede',
        'nombre'        => 'Nombre',
        'apellidos'     => 'Apellidos',
        'telefono'      => 'Teléfono',
        'email'         => 'Email',
        'horas_finales' => 'Horas finales',
    ];
    $faltantes = [];
    foreach ($camposReq as $name => $label) {
        if (!isset($_POST[$name]) || trim((string) $_POST[$name]) === '') {
            $faltantes[] = $label;
        }
    }
    if ($faltantes) {
        flash('error', 'Faltan campos obligatorios: ' . implode(', ', $faltantes));
        header('Location: devoluciones.php'); exit;
    }

    $sedeNombre = limpiar($_POST['sede'] ?? '');
    if (!idSedePorNombre($sedeNombre)) {
        flash('error', 'La sede seleccionada no es válida.');
        header('Location: devoluciones.php'); exit;
    }
    if (!filter_var($_POST['email'] ?? '', FILTER_VALIDATE_EMAIL)) {
        flash('error', 'El email no tiene un formato válido.');
        header('Location: devoluciones.php'); exit;
    }
    if (!is_numeric($_POST['horas_finales'] ?? '') || (int) $_POST['horas_finales'] < 0) {
        flash('error', 'Las horas finales deben ser un número válido.');
        header('Location: devoluciones.php'); exit;
    }

        $datosIncidencia = [
            'sede_nombre'        => $sedeNombre,
            'contrato_codigo'    => limpiar($_POST['id_contrato']),
            'cliente_nombre'     => limpiar($_POST['cliente']),
            'obra_nombre'        => limpiar($_POST['obra']),
            'maquina_modelo'     => limpiar($_POST['tipo_maquina']),
            'numero_maquina'     => limpiar($_POST['num_maquina']),
            'contacto_nombre'    => limpiar($_POST['nombre']),
            'contacto_apellidos' => limpiar($_POST['apellidos']),
            'contacto_telefono'  => limpiar($_POST['telefono']),
            'contacto_email'     => limpiar($_POST['email']),
            'ubicacion'          => limpiar($_POST['ubicacion'] ?? '') ?: null,
            'ubicacion_aclar'    => limpiar($_POST['ubicacion_aclar'] ?? '') ?: null,
            'observaciones'      => limpiar($_POST['comentarios'] ?? '') ?: null,
        ];

        try {
            $pdo->beginTransaction();

            $idIncidencia = crearIncidencia('devolucion', $datosIncidencia);

            $horasFinales = (int) $_POST['horas_finales'];

            $stmt = $pdo->prepare('
                INSERT INTO incidencias_devolucion
                    (id_incidencia, horas_finales, comentarios)
                VALUES (?, ?, ?)
            ');
            $stmt->execute([
                $idIncidencia,
                $horasFinales,
                limpiar($_POST['comentarios'] ?? '') ?: null,
            ]);

            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            flash('error', 'Error al guardar la devolución: ' . $e->getMessage());
            header('Location: devoluciones.php'); exit;
        }

        $fotos = guardarFotosSubidas('devolucion', $idIncidencia, $_FILES['fotos'] ?? []);

        $datosEmail = $datosIncidencia + [
            'horas_finales' => $horasFinales,
            'comentarios'   => limpiar($_POST['comentarios'] ?? ''),
        ];
        $cuerpo  = construirCuerpoEmail('DEVOLUCIÓN', $datosEmail, $fotos);
        $asunto  = 'Acta de Devolución - Sede ' . $sedeNombre;
        $resMail = enviarEmailSede($sedeNombre, $asunto, $cuerpo);

        marcarIncidenciaEnviada($idIncidencia, $resMail);

        if ($resMail['ok']) {
            flash('success', '✅ Devolución generada y enviada a la sede ' . $sedeNombre);
        } else {
            flash('warning', '⚠️ Devolución registrada pero el email a la sede falló. '
                            . 'Causa: ' . ($resMail['error'] ?? 'desconocida'));
        }

        header('Location: devoluciones.php');
        exit;
}


// ============================================================
// LISTADO  —  solo se carga para admin y sede
// Los operarios no ven el histórico, solo los contadores en usuarios.php
// ============================================================
$listado = [];
$filtros = ['where' => '', 'params' => [], 'activos' => []];

if ($u['rol'] !== 'operario') {
    $alcance = alcanceIncidencias();
    $filtros = construirFiltrosListado($_GET);
    $sql = "
        SELECT i.id_incidencia, i.sede_nombre, i.cliente_nombre, i.obra_nombre,
               i.numero_maquina, i.fecha_creacion,
               d.horas_finales,
               usr.username AS creador_username
        FROM   incidencias i
        INNER JOIN incidencias_devolucion d ON d.id_incidencia = i.id_incidencia
        LEFT JOIN  usuarios usr             ON usr.id_usuario  = i.id_usuario
        WHERE  i.tipo = 'devolucion' AND {$alcance['where']} {$filtros['where']}
        ORDER BY i.fecha_creacion DESC
        LIMIT 50
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute(array_merge($alcance['params'], $filtros['params']));
    $listado = $stmt->fetchAll();
}


renderHeader('Devolución', 'fa-rotate-left');
?>

<a href="usuarios.php" class="btn btn-outline-secondary mb-3">
    <i class="fa-solid fa-arrow-left"></i> Volver al inicio
</a>

<section class="page-header mb-4">
    <div class="page-icon-badge bg-info-subtle text-info">📄</div>
    <div>
        <h1>Devolución</h1>
        <p class="text-muted">Cierre técnico y económico del contrato</p>
    </div>
</section>

<form method="post" enctype="multipart/form-data" class="form-card mb-5" novalidate>
    <?= inputCsrf() ?>
    <input type="hidden" name="accion" value="crear">

    <fieldset class="form-section">
        <legend>Datos del contrato</legend>
        <div class="row g-3">
            <div class="col-md-6"><input class="form-control" type="text" name="id_contrato" placeholder="ID Contrato #ALQ-0000" required></div>
            <div class="col-md-6"><input class="form-control" type="text" name="cliente" placeholder="Cliente / Empresa" required></div>
            <div class="col-md-6"><input class="form-control" type="text" name="obra" placeholder="Obra" required></div>
            <div class="col-md-6">
                <select class="form-select" name="tipo_maquina" required>
                    <option value="">— Tipo de máquina —</option>
                    <?php foreach (MAQUINAS as $m): ?>
                        <option><?= escapar($m) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-6"><input class="form-control" type="text" name="num_maquina" placeholder="Nº de máquina" required></div>
            <div class="col-md-6">
                <select class="form-select" name="sede" required>
                    <option value="">— Selecciona una sede —</option>
                    <?php foreach (obtenerSedes() as $s): ?>
                        <option <?= ($u['sede'] ?? '') === $s['nombre'] ? 'selected' : '' ?>>
                            <?= escapar($s['nombre']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
    </fieldset>

    <fieldset class="form-section">
        <legend>Datos personales</legend>
        <div class="row g-3">
            <div class="col-md-6"><input class="form-control" type="text" name="nombre" placeholder="Nombre" required></div>
            <div class="col-md-6"><input class="form-control" type="text" name="apellidos" placeholder="Apellidos" required></div>
            <div class="col-md-6"><input class="form-control" type="tel"  name="telefono" placeholder="Teléfono de contacto" required></div>
            <div class="col-md-6"><input class="form-control" type="email" name="email" placeholder="Email" required></div>
        </div>
    </fieldset>

    <fieldset class="form-section">
        <legend>Estado operativo</legend>
        <input class="form-control" type="number" name="horas_finales" placeholder="Horas finales" min="0" required>
    </fieldset>

    <fieldset class="form-section">
        <legend>Comentarios <small class="text-muted" style="font-size:10px;text-transform:none;letter-spacing:0;font-weight:500;">(opcional)</small></legend>
        <textarea class="form-control" name="comentarios" rows="3"
                  placeholder="Describe incidencias técnicas o el estado de devolución..."></textarea>
    </fieldset>

    <!-- ============================================================
         FOTOS — Cámara + Galería con miniaturas (opcional)
         ============================================================ -->
    <fieldset class="form-section">
        <legend>Fotos <small class="text-muted" style="font-size:10px;text-transform:none;letter-spacing:0;font-weight:500;">(opcional)</small></legend>

        <!-- Inputs ocultos: uno fuerza cámara trasera, otro abre galería -->
        <input type="file" id="fotosCamara" accept="image/*" capture="environment" class="d-none">
        <input type="file" id="fotosGaleria" accept="image/*" multiple class="d-none">

        <!-- Input REAL que viaja en el POST como fotos[] -->
        <input type="file" id="fotosFinal" name="fotos[]" multiple class="d-none">

        <div class="d-flex gap-2 mb-2 flex-wrap">
            <button type="button" class="btn btn-outline-primary"
                    onclick="document.getElementById('fotosCamara').click()">
                📷 Hacer foto
            </button>
            <button type="button" class="btn btn-outline-secondary"
                    onclick="document.getElementById('fotosGaleria').click()">
                🖼️ Subir desde galería
            </button>
        </div>

        <!-- Contenedor de miniaturas -->
        <div id="previewFotos" class="d-flex flex-wrap gap-2 mt-2"></div>

        <small class="text-muted d-block mt-2"></small>
    </fieldset>

    <fieldset class="form-section">
        <legend>Ubicación <small class="text-muted" style="font-size:10px;text-transform:none;letter-spacing:0;font-weight:500;">(opcional)</small></legend>
        <div class="d-flex gap-2 mb-2">
            <button type="button" class="btn btn-outline-primary" onclick="obtenerGPS('ubicacionDevolucion')">
                📍 Obtener ubicación GPS
            </button>
            <input type="text" class="form-control" id="ubicacionDevolucion" name="ubicacion"
                   placeholder="Pulsa el botón para obtener tu ubicación" readonly>
        </div>
        <textarea class="form-control" name="ubicacion_aclar" rows="2"
                  placeholder="Aclaraciones de la ubicación..."></textarea>
    </fieldset>



    <button type="submit" class="btn-primary w-100">
        <i class="fa-solid fa-file-invoice"></i>
        Generar devolución
    </button>
</form>


<!-- ============================================================
     LISTADO — solo visible para admin y sede
     Los operarios no ven el histórico, solo los contadores
     en el dashboard de usuarios.php
     ============================================================ -->
<?php if ($u['rol'] !== 'operario'): ?>
<section class="mt-5">
    <h2 class="section-title">
        <?php
        $tituloListado = match($u['rol']) {
            'admin' => 'Últimas devoluciones (todas las sedes)',
            'sede'  => 'Devoluciones de ' . $u['sede'],
            default => 'Mis últimas devoluciones',
        };
        ?>
        <?= escapar($tituloListado) ?>
    </h2>

    <!-- FILTROS -->
    <form method="get" class="bg-white rounded-3 shadow-sm p-3 mb-3 row g-2 align-items-end">
        <div class="col-md-4">
            <label class="form-label" style="font-size:12px;">Búsqueda</label>
            <input type="text" name="q" class="form-control form-control-sm"
                   value="<?= escapar($_GET['q'] ?? '') ?>"
                   placeholder="Cliente, obra, máquina…">
        </div>
        <div class="col-md-2">
            <label class="form-label" style="font-size:12px;">Desde</label>
            <input type="date" name="desde" class="form-control form-control-sm"
                   value="<?= escapar($_GET['desde'] ?? '') ?>">
        </div>
        <div class="col-md-2">
            <label class="form-label" style="font-size:12px;">Hasta</label>
            <input type="date" name="hasta" class="form-control form-control-sm"
                   value="<?= escapar($_GET['hasta'] ?? '') ?>">
        </div>
        <?php if ($u['rol'] === 'admin'): ?>
        <div class="col-md-3">
            <label class="form-label" style="font-size:12px;">Sede</label>
            <select name="sede" class="form-select form-select-sm">
                <option value="">Todas</option>
                <?php foreach (obtenerSedes() as $s): ?>
                    <option value="<?= (int) $s['id_sede'] ?>"
                        <?= ((int)($_GET['sede'] ?? 0) === (int) $s['id_sede']) ? 'selected' : '' ?>>
                        <?= escapar($s['nombre']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <?php endif; ?>
        <div class="col-md-1">
            <button type="submit" class="btn btn-primary btn-sm w-100">
                <i class="fa-solid fa-filter"></i>
            </button>
        </div>
        <?php if ($filtros['activos']): ?>
            <div class="col-12 mt-2">
                <small class="text-muted">
                    <i class="fa-solid fa-circle-info"></i>
                    Filtrando por: <?= escapar(implode(' · ', $filtros['activos'])) ?>
                    · <a href="devoluciones.php">Limpiar filtros</a>
                </small>
            </div>
        <?php endif; ?>
    </form>

    <?php if (empty($listado)): ?>
        <div class="alert alert-light text-center py-4">
            <i class="fa-regular fa-folder-open fa-2x mb-2 d-block text-muted"></i>
            <?= $filtros['activos'] ? 'Ninguna devolución coincide con los filtros aplicados.' : 'Aún no hay devoluciones registradas.' ?>
        </div>
    <?php else: ?>
        <div class="table-responsive bg-white rounded-3 shadow-sm p-3">
            <table class="table table-hover align-middle mb-0">
                <thead>
                    <tr>
                        <th>Sede</th>
                        <th>Cliente</th>
                        <th>Obra</th>
                        <th>Máquina</th>
                        <th class="text-end">Horas</th>
                        <th>Fecha</th>
                        <th class="text-end">Ver</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($listado as $d): ?>
                        <tr>
                            <td><?= escapar($d['sede_nombre']) ?></td>
                            <td><?= escapar($d['cliente_nombre']) ?></td>
                            <td><small><?= escapar($d['obra_nombre']) ?></small></td>
                            <td><small><?= escapar($d['numero_maquina']) ?></small></td>
                            <td class="text-end"><?= $d['horas_finales'] !== null ? (int) $d['horas_finales'] : '—' ?></td>
                            <td><small class="text-muted"><?= date('d/m/Y H:i', strtotime($d['fecha_creacion'])) ?></small></td>
                            <td class="text-end">
                                <a href="incidencia.php?id=<?= (int) $d['id_incidencia'] ?>"
                                   class="btn btn-sm btn-outline-primary" title="Ver detalle">
                                    <i class="fa-solid fa-eye"></i>
                                </a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>
<?php endif; ?>


<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
function obtenerGPS(targetId) {
    const txt = document.getElementById(targetId);
    if (!navigator.geolocation) { txt.value = 'Navegador no compatible'; return; }
    txt.value = 'Localizando...';
    navigator.geolocation.getCurrentPosition(
        pos => {
            txt.value = pos.coords.latitude.toFixed(4) + ', ' + pos.coords.longitude.toFixed(4);
            txt.dispatchEvent(new Event('input', { bubbles: true }));
        },
        ()  => txt.value = 'GPS desactivado o sin permiso'
    );
}

// ================================================================
// GESTIÓN DE FOTOS — Cámara + Galería con miniaturas y eliminar
// ================================================================
(function() {
    const dt           = new DataTransfer();
    const inputCamara  = document.getElementById('fotosCamara');
    const inputGaleria = document.getElementById('fotosGaleria');
    const inputFinal   = document.getElementById('fotosFinal');
    const preview      = document.getElementById('previewFotos');

    function reconstruirInputFinal() {
        const nuevo = new DataTransfer();
        Array.from(dt.files).forEach(f => nuevo.items.add(f));
        inputFinal.files = nuevo.files;
    }

    function renderPreview() {
        preview.innerHTML = '';
        Array.from(dt.files).forEach((file, idx) => {
            const url = URL.createObjectURL(file);
            const wrapper = document.createElement('div');
            wrapper.style.cssText = 'position:relative;width:96px;height:96px;border-radius:10px;overflow:hidden;border:1.5px solid var(--go-border, #dbe4ef);box-shadow:0 2px 6px rgba(0,0,0,.08);';
            wrapper.innerHTML = `
                <img src="${url}" alt="Foto ${idx+1}"
                     style="width:100%;height:100%;object-fit:cover;display:block;"
                     onload="URL.revokeObjectURL(this.src)">
                <button type="button" aria-label="Eliminar foto"
                        style="position:absolute;top:4px;right:4px;width:24px;height:24px;border-radius:50%;border:none;background:rgba(220,38,38,0.95);color:white;cursor:pointer;font-size:14px;line-height:1;display:flex;align-items:center;justify-content:center;font-weight:700;box-shadow:0 2px 4px rgba(0,0,0,.3);">
                    ×
                </button>
            `;
            wrapper.querySelector('button').addEventListener('click', () => eliminar(idx));
            preview.appendChild(wrapper);
        });
    }

    function añadirArchivos(fileList) {
        for (const f of fileList) {
            const yaEsta = Array.from(dt.files).some(x => x.name === f.name && x.size === f.size);
            if (!yaEsta) dt.items.add(f);
        }
        reconstruirInputFinal();
        renderPreview();
    }

    function eliminar(idx) {
        const archivos = Array.from(dt.files);
        archivos.splice(idx, 1);
        while (dt.items.length > 0) dt.items.remove(0);
        archivos.forEach(f => dt.items.add(f));
        reconstruirInputFinal();
        renderPreview();
    }

    inputCamara.addEventListener('change', e => {
        añadirArchivos(e.target.files);
        e.target.value = '';
    });
    inputGaleria.addEventListener('change', e => {
        añadirArchivos(e.target.files);
        e.target.value = '';
    });
})();

// ================================================================
// CAMPOS REQUERIDOS — comentarios, ubicación y fotos son OPCIONALES
// ================================================================
const CAMPOS_REQUERIDOS = [
    'id_contrato', 'cliente', 'obra', 'tipo_maquina', 'num_maquina', 'sede',
    'nombre', 'apellidos', 'telefono', 'email',
    'horas_finales'
];

// ================================================================
// Estado dinámico del contrato (banner verde / amarillo / gris)
// ================================================================
function actualizarEstadoContrato() {
    let rellenos = 0;
    const total = CAMPOS_REQUERIDOS.length;

    CAMPOS_REQUERIDOS.forEach(name => {
        const el = document.querySelector(`[name="${name}"]`);
        if (el && el.value && el.value.trim() !== '') rellenos++;
    });

    const banner = document.getElementById('estadoContrato');
    if (!banner) return;

    if (rellenos === 0) {
        banner.className = 'alert alert-secondary';
        banner.innerHTML = '<strong>Estado del contrato:</strong> Sin datos — completa el formulario para poder cerrarlo';
    } else if (rellenos < total) {
        const faltan = total - rellenos;
        banner.className = 'alert alert-warning';
        banner.innerHTML = `<strong>Estado del contrato:</strong> No listo para cierre — faltan ${faltan} campo(s) por rellenar`;
    } else {
        banner.className = 'alert alert-success';
        banner.innerHTML = '<strong>Estado del contrato:</strong> ✅ Listo para cierre';
    }
}

// ================================================================
// Bloquea el botón de envío hasta que TODOS los obligatorios estén llenos
// ================================================================
function actualizarBotonEnvio() {
    const form  = document.querySelector('form[method="post"]');
    const boton = form.querySelector('button[type="submit"]');
    if (!boton) return;

    let valido = true;
    for (const name of CAMPOS_REQUERIDOS) {
        const el = form.querySelector(`[name="${name}"]`);
        if (!el || !el.value || el.value.trim() === '') { valido = false; break; }
    }

    boton.disabled = !valido;
    boton.style.opacity      = valido ? '1' : '0.5';
    boton.style.cursor       = valido ? 'pointer' : 'not-allowed';
}

// Listeners en todos los inputs
document.querySelectorAll('form .form-control, form .form-select').forEach(el => {
    el.addEventListener('input',  () => { actualizarEstadoContrato(); actualizarBotonEnvio(); });
    el.addEventListener('change', () => { actualizarEstadoContrato(); actualizarBotonEnvio(); });
});

actualizarEstadoContrato();
actualizarBotonEnvio();
</script>

<?php renderFooter(); ?>