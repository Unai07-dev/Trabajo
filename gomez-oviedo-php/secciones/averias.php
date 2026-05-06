<?php
/* ============================================================
   AVERIAS.PHP  —  Reportar avería + listado
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
        header('Location: averias.php'); exit;
    }

    // ---------- VALIDACIÓN: CAMPOS OBLIGATORIOS ----------
    // (descripción técnica, ubicación y fotos son OPCIONALES)
    $camposReq = [
        'id_contrato'    => 'ID Contrato',
        'cliente'        => 'Cliente / Empresa',
        'obra'           => 'Obra',
        'tipo_maquina'   => 'Tipo de máquina',
        'num_maquina'    => 'Nº de máquina',
        'sede'           => 'Sede',
        'nombre'         => 'Nombre',
        'apellidos'      => 'Apellidos',
        'telefono'       => 'Teléfono',
        'email'          => 'Email',
        'tipo_fallo'     => 'Tipo de fallo',
        'estado_maquina' => 'Estado de la máquina',
        'urgencia'       => 'Urgencia',
    ];
    $faltantes = [];
    foreach ($camposReq as $name => $label) {
        if (!isset($_POST[$name]) || trim((string) $_POST[$name]) === '') {
            $faltantes[] = $label;
        }
    }
    if ($faltantes) {
        flash('error', 'Faltan campos obligatorios: ' . implode(', ', $faltantes));
        header('Location: averias.php'); exit;
    }

    $sedeNombre = limpiar($_POST['sede']);
    if (!idSedePorNombre($sedeNombre)) {
        flash('error', 'La sede seleccionada no es válida.');
        header('Location: averias.php'); exit;
    }
    if (!filter_var($_POST['email'] ?? '', FILTER_VALIDATE_EMAIL)) {
        flash('error', 'El email no tiene un formato válido.');
        header('Location: averias.php'); exit;
    }
    if (!in_array($_POST['estado_maquina'], ['operativa', 'parada'], true)) {
        flash('error', 'Selecciona un estado de máquina válido.');
        header('Location: averias.php'); exit;
    }
    if (!in_array($_POST['urgencia'], ['critica', 'alta', 'media', 'baja'], true)) {
        flash('error', 'Selecciona un nivel de urgencia válido.');
        header('Location: averias.php'); exit;
    }

        $ticket = generarTicket();

        $datosIncidencia = [
            'ticket'             => $ticket,
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
            'observaciones'      => limpiar($_POST['descripcion'] ?? '') ?: null,
        ];

        try {
            $pdo->beginTransaction();

            // 1) Padre: incidencias
            $idIncidencia = crearIncidencia('averia', $datosIncidencia);

            // 2) Detalle: incidencias_averia
            $tipoFalloTxt = limpiar($_POST['tipo_fallo'] ?? '');
            $urgencia     = in_array($_POST['urgencia'] ?? '', ['critica', 'alta', 'media', 'baja'], true)
                            ? $_POST['urgencia'] : null;
            $impacto      = in_array($_POST['estado_maquina'] ?? '', ['operativa', 'parada'], true)
                            ? $_POST['estado_maquina'] : null;

            $stmt = $pdo->prepare('
                INSERT INTO incidencias_averia
                    (id_incidencia, id_tipo_fallo, tipo_fallo_texto, id_urgencia, impacto_operativo, descripcion_tecnica)
                VALUES
                    (?, ?, ?, ?, ?, ?)
            ');
            $stmt->execute([
                $idIncidencia,
                idTipoFalloPorNombre($tipoFalloTxt),
                $tipoFalloTxt ?: null,
                idUrgenciaPorCodigo($urgencia),
                $impacto,
                limpiar($_POST['descripcion'] ?? '') ?: null,
            ]);

            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            flash('error', 'Error al guardar la avería: ' . $e->getMessage());
            header('Location: averias.php'); exit;
        }

        // 3) Subida de fotos
        $fotos = guardarFotosSubidas('averia', $idIncidencia, $_FILES['fotos'] ?? []);

        // 4) Email a la sede
        $datosEmail = $datosIncidencia + [
            'tipo_fallo'        => $tipoFalloTxt,
            'urgencia'          => $urgencia ? strtoupper($urgencia) : null,
            'impacto_operativo' => $impacto,
            'descripcion'       => limpiar($_POST['descripcion'] ?? ''),
        ];
        $cuerpo  = construirCuerpoEmail('AVERÍA — Ticket ' . $ticket, $datosEmail, $fotos);
        $asunto  = 'Reporte de Avería ' . $ticket . ' - Sede ' . $sedeNombre;
        $resMail = enviarEmailSede($sedeNombre, $asunto, $cuerpo);

        marcarIncidenciaEnviada($idIncidencia, $resMail);

        if ($resMail['ok']) {
            flash('success', '✅ Avería registrada y notificada a la sede ' . $sedeNombre
                            . ' (Ticket: ' . $ticket . ')');
        } else {
            flash('warning', '⚠️ Avería registrada (Ticket: ' . $ticket . ') pero el email a la sede falló. '
                            . 'Causa: ' . ($resMail['error'] ?? 'desconocida'));
        }

        header('Location: averias.php');
        exit;
}


// ============================================================
// CARGA DE LISTADO  (filtrado por rol + filtros del formulario GET)
// Solo se carga para admin y sede; los operarios no ven el histórico
// ============================================================
$listado = [];
$filtros = ['where' => '', 'params' => [], 'activos' => []];

if ($u['rol'] !== 'operario') {
    $alcance  = alcanceIncidencias();
    $filtros  = construirFiltrosListado($_GET, ['urgencia' => true]);

    $sql = "
        SELECT i.id_incidencia, i.ticket, i.sede_nombre, i.cliente_nombre,
               i.maquina_modelo, i.fecha_creacion,
               a.impacto_operativo,
               ur.codigo AS urgencia_codigo, ur.nombre AS urgencia_nombre,
               usr.username AS creador_username
        FROM   incidencias i
        INNER JOIN incidencias_averia a ON a.id_incidencia = i.id_incidencia
        LEFT JOIN  urgencias ur          ON ur.id_urgencia  = a.id_urgencia
        LEFT JOIN  usuarios  usr         ON usr.id_usuario  = i.id_usuario
        WHERE  i.tipo = 'averia' AND {$alcance['where']} {$filtros['where']}
        ORDER BY i.fecha_creacion DESC
        LIMIT 50
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute(array_merge($alcance['params'], $filtros['params']));
    $listado = $stmt->fetchAll();
}


renderHeader('Avería', 'fa-triangle-exclamation');
?>

<a href="usuarios.php" class="btn btn-outline-secondary mb-3">
    <i class="fa-solid fa-arrow-left"></i> Volver al inicio
</a>

<section class="page-header mb-4">
    <div class="page-icon-badge bg-warning-subtle text-warning">
        <i class="fa-solid fa-triangle-exclamation"></i>
    </div>
    <div>
        <h1>Reportar Avería</h1>
        <p class="text-muted">Sistema de incidencias técnicas en tiempo real</p>
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
        <legend>Incidencia</legend>
        <select class="form-select" name="tipo_fallo" required>
            <?php foreach (obtenerTiposFallo() as $t): ?>
                <option><?= escapar($t['nombre']) ?></option>
            <?php endforeach; ?>
        </select>
    </fieldset>

    <fieldset class="form-section">
        <legend>Estado de la máquina</legend>
        <div class="row g-2">
            <div class="col-6">
                <label class="status-card status-card-ok w-100">
                    <input type="radio" name="estado_maquina" value="operativa" class="d-none">
                    <h4>Operativa</h4>
                    <p>Puede seguir trabajando</p>
                </label>
            </div>
            <div class="col-6">
                <label class="status-card status-card-bad w-100">
                    <input type="radio" name="estado_maquina" value="parada" class="d-none">
                    <h4>Parada</h4>
                    <p>Máquina detenida</p>
                </label>
            </div>
        </div>
    </fieldset>

    <fieldset class="form-section">
        <legend>Urgencia</legend>
        <div class="d-flex flex-wrap gap-2">
            <?php
            $clases = ['critica' => 'danger', 'alta' => 'warning', 'media' => 'secondary', 'baja' => 'success'];
            foreach (obtenerUrgencias() as $urg):
                $clase = $clases[$urg['codigo']] ?? 'secondary';
            ?>
                <label class="pill-radio">
                    <input type="radio" name="urgencia" value="<?= escapar($urg['codigo']) ?>">
                    <span class="pill pill-<?= $clase ?>"><?= escapar($urg['nombre']) ?></span>
                </label>
            <?php endforeach; ?>
        </div>
    </fieldset>

    <fieldset class="form-section">
        <legend>Ubicación <small class="text-muted" style="font-size:10px;text-transform:none;letter-spacing:0;font-weight:500;">(opcional)</small></legend>
        <div class="d-flex gap-2 mb-2">
            <button type="button" class="btn btn-outline-primary" onclick="obtenerGPS('ubicacionAveria')">
                📍 Obtener ubicación
            </button>
            <input type="text" class="form-control" id="ubicacionAveria" name="ubicacion"
                   placeholder="Pulsa el botón para obtener tu ubicación" readonly>
        </div>
        <textarea class="form-control" name="ubicacion_aclar" rows="2"
                  placeholder="Aclaraciones de la ubicación..."></textarea>
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

        <small class="text-muted d-block mt-2">
           Añade fotos del fallo para análisis técnico
        </small>
    </fieldset>

    <fieldset class="form-section">
        <legend>Descripción técnica <small class="text-muted" style="font-size:10px;text-transform:none;letter-spacing:0;font-weight:500;">(opcional)</small></legend>
        <textarea class="form-control" name="descripcion" rows="4"
                  placeholder="Describe el problema con detalle..."></textarea>
    </fieldset>

    <div class="alert alert-info">
        ⚠ Esta incidencia quedará vinculada al contrato y puede afectar la devolución y facturación.
    </div>

    <button type="submit" class="btn-primary w-100">
        <i class="fa-solid fa-paper-plane"></i>
        Comunicar Avería
    </button>
</form>


<!-- ============================================================
     LISTADO  —  solo visible para admin y sede
     Los operarios NO ven el historial; solo los contadores
     en el dashboard de usuarios.php
     ============================================================ -->
<?php if ($u['rol'] !== 'operario'): ?>
<section class="mt-5">
    <h2 class="section-title">
        <?php
        $tituloListado = match($u['rol']) {
            'admin' => 'Últimas averías (todas las sedes)',
            'sede'  => 'Averías de ' . $u['sede'],
            default => 'Mis últimas averías',
        };
        ?>
        <?= escapar($tituloListado) ?>
    </h2>

    <!-- FILTROS -->
    <form method="get" class="bg-white rounded-3 shadow-sm p-3 mb-3 row g-2 align-items-end">
        <div class="col-md-3">
            <label class="form-label" style="font-size:12px;">Búsqueda</label>
            <input type="text" name="q" class="form-control form-control-sm"
                   value="<?= escapar($_GET['q'] ?? '') ?>"
                   placeholder="Cliente, máquina, ticket…">
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
        <div class="col-md-2">
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
        <div class="col-md-2">
            <label class="form-label" style="font-size:12px;">Urgencia</label>
            <select name="urgencia" class="form-select form-select-sm">
                <option value="">Todas</option>
                <option value="critica" <?= ($_GET['urgencia'] ?? '') === 'critica' ? 'selected' : '' ?>>Crítica</option>
                <option value="alta"    <?= ($_GET['urgencia'] ?? '') === 'alta'    ? 'selected' : '' ?>>Alta</option>
                <option value="media"   <?= ($_GET['urgencia'] ?? '') === 'media'   ? 'selected' : '' ?>>Media</option>
                <option value="baja"    <?= ($_GET['urgencia'] ?? '') === 'baja'    ? 'selected' : '' ?>>Baja</option>
            </select>
        </div>
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
                    · <a href="averias.php">Limpiar filtros</a>
                </small>
            </div>
        <?php endif; ?>
    </form>

    <?php if (empty($listado)): ?>
        <div class="alert alert-light text-center py-4">
            <i class="fa-regular fa-folder-open fa-2x mb-2 d-block text-muted"></i>
            <?= $filtros['activos'] ? 'Ninguna avería coincide con los filtros aplicados.' : 'Aún no hay averías registradas.' ?>
        </div>
    <?php else: ?>
        <div class="table-responsive bg-white rounded-3 shadow-sm p-3">
            <table class="table table-hover align-middle mb-0">
                <thead>
                    <tr>
                        <th>Ticket</th>
                        <th>Sede</th>
                        <th>Cliente</th>
                        <th>Máquina</th>
                        <th>Urgencia</th>
                        <th>Estado</th>
                        <th>Fecha</th>
                        <th class="text-end">Ver</th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    $bg = ['critica' => 'danger', 'alta' => 'warning', 'media' => 'secondary', 'baja' => 'success'];
                    foreach ($listado as $a):
                    ?>
                        <tr>
                            <td><code><?= escapar($a['ticket']) ?></code></td>
                            <td><?= escapar($a['sede_nombre']) ?></td>
                            <td><?= escapar($a['cliente_nombre']) ?></td>
                            <td><small><?= escapar($a['maquina_modelo'] ?: '—') ?></small></td>
                            <td>
                                <?php if ($a['urgencia_codigo']): ?>
                                    <span class="badge bg-<?= $bg[$a['urgencia_codigo']] ?? 'secondary' ?>">
                                        <?= escapar($a['urgencia_nombre']) ?>
                                    </span>
                                <?php else: ?>—<?php endif; ?>
                            </td>
                            <td>
                                <?php if ($a['impacto_operativo'] === 'parada'): ?>
                                    <span class="badge bg-danger-subtle text-danger">Parada</span>
                                <?php elseif ($a['impacto_operativo'] === 'operativa'): ?>
                                    <span class="badge bg-success-subtle text-success">Operativa</span>
                                <?php else: ?>—<?php endif; ?>
                            </td>
                            <td><small class="text-muted"><?= date('d/m/Y H:i', strtotime($a['fecha_creacion'])) ?></small></td>
                            <td class="text-end">
                                <a href="incidencia.php?id=<?= (int) $a['id_incidencia'] ?>"
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
            const lat = pos.coords.latitude.toFixed(4);
            const lng = pos.coords.longitude.toFixed(4);
            txt.value = `${lat}, ${lng}`;
            txt.dispatchEvent(new Event('input', { bubbles: true }));
        },
        () => { txt.value = 'GPS desactivado o sin permiso'; }
    );
}

document.querySelectorAll('.status-card input[type=radio]').forEach(r => {
    r.addEventListener('change', () => {
        document.querySelectorAll('.status-card').forEach(c => c.classList.remove('active'));
        if (r.checked) r.closest('.status-card').classList.add('active');
        actualizarBotonEnvio();
    });
});
document.querySelectorAll('.pill-radio input[type=radio]').forEach(r => {
    r.addEventListener('change', () => {
        document.querySelectorAll('.pill-radio .pill').forEach(p => p.classList.remove('active'));
        if (r.checked) r.nextElementSibling.classList.add('active');
        actualizarBotonEnvio();
    });
});

// ================================================================
// GESTIÓN DE FOTOS — Cámara + Galería con miniaturas y eliminar
// ================================================================
(function() {
    const dt           = new DataTransfer(); // acumulador de archivos
    const inputCamara  = document.getElementById('fotosCamara');
    const inputGaleria = document.getElementById('fotosGaleria');
    const inputFinal   = document.getElementById('fotosFinal');
    const preview      = document.getElementById('previewFotos');

    function reconstruirInputFinal() {
        // Reasignar el FileList al input que se envía con el form
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
            // Evita duplicar si ya existe (por nombre + tamaño)
            const yaEsta = Array.from(dt.files).some(x => x.name === f.name && x.size === f.size);
            if (!yaEsta) dt.items.add(f);
        }
        reconstruirInputFinal();
        renderPreview();
    }

    function eliminar(idx) {
        const archivos = Array.from(dt.files);
        archivos.splice(idx, 1);
        // Limpiar el DataTransfer
        while (dt.items.length > 0) dt.items.remove(0);
        archivos.forEach(f => dt.items.add(f));
        reconstruirInputFinal();
        renderPreview();
    }

    inputCamara.addEventListener('change', e => {
        añadirArchivos(e.target.files);
        e.target.value = ''; // permite tomar otra foto idéntica después
    });
    inputGaleria.addEventListener('change', e => {
        añadirArchivos(e.target.files);
        e.target.value = '';
    });
})();

// ================================================================
// CAMPOS REQUERIDOS — descripción, ubicación y fotos son OPCIONALES
// ================================================================
const CAMPOS_REQUERIDOS = [
    'id_contrato', 'cliente', 'obra', 'tipo_maquina', 'num_maquina', 'sede',
    'nombre', 'apellidos', 'telefono', 'email',
    'tipo_fallo'
];
const GRUPOS_RADIO_REQUERIDOS = ['estado_maquina', 'urgencia'];

function actualizarBotonEnvio() {
    const form  = document.querySelector('form[method="post"]');
    const boton = form.querySelector('button[type="submit"]');
    if (!boton) return;

    let valido = true;

    // Campos de texto / select / textarea
    for (const name of CAMPOS_REQUERIDOS) {
        const el = form.querySelector(`[name="${name}"]`);
        if (!el || !el.value || el.value.trim() === '') { valido = false; break; }
    }

    // Grupos de radio (estado_maquina, urgencia)
    if (valido) {
        for (const grupo of GRUPOS_RADIO_REQUERIDOS) {
            const sel = form.querySelector(`input[name="${grupo}"]:checked`);
            if (!sel) { valido = false; break; }
        }
    }

    boton.disabled       = !valido;
    boton.style.opacity  = valido ? '1' : '0.5';
    boton.style.cursor   = valido ? 'pointer' : 'not-allowed';
}

document.querySelectorAll('form .form-control, form .form-select').forEach(el => {
    el.addEventListener('input',  actualizarBotonEnvio);
    el.addEventListener('change', actualizarBotonEnvio);
});

actualizarBotonEnvio();
</script>

<?php renderFooter(); ?>