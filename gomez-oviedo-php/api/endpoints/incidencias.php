<?php
/* ============================================================
   API/ENDPOINTS/INCIDENCIAS.PHP  —  CRUD de incidencias
   ------------------------------------------------------------
   POST /api/incidencias/averia      → crear avería     + email a sede
   POST /api/incidencias/pinchazo    → crear pinchazo   + email a sede
   POST /api/incidencias/devolucion  → crear devolución + email a sede
   GET  /api/incidencias             → listar (con filtros ?q=, ?desde=, ?hasta=, ?tipo=)
   GET  /api/incidencias/123         → ver detalle
   POST /api/incidencias/123/fotos   → subir foto (multipart)
   ============================================================ */

declare(strict_types=1);

function manejarIncidencias(string $accion, ?int $id): void {
    $usuario = autenticarPeticion();

    // Cargar la sesión PHP "manualmente" con los datos del JWT
    // para que las funciones del portal web sigan funcionando.
    iniciarSesion();
    $_SESSION['usuario'] = $usuario;

    $metodo = $_SERVER['REQUEST_METHOD'];

    // POST /api/incidencias/{tipo}      → crear
    // POST /api/incidencias/{id}/fotos  → subir foto
    if ($metodo === 'POST') {
        if (in_array($accion, ['averia', 'pinchazo', 'devolucion'], true)) {
            crearIncidenciaPorTipo($accion);
            return;
        }
        // Caso: /api/incidencias/123/fotos
        if (is_numeric($accion)) {
            $segmentos = explode('/', trim(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH), '/'));
            // p.ej. ['api','incidencias','123','fotos']
            if (end($segmentos) === 'fotos') {
                subirFotos((int) $accion);
                return;
            }
        }
        jsonError('Acción no soportada', 404);
    }

    // GET /api/incidencias       → listado
    // GET /api/incidencias/123   → detalle
    if ($metodo === 'GET') {
        if ($accion === '' || $accion === null) {
            listarIncidencias();
            return;
        }
        if (is_numeric($accion)) {
            verIncidencia((int) $accion);
            return;
        }
        jsonError('Acción no soportada', 404);
    }

    jsonError('Método no permitido', 405);
}


/* ============================================================
   CREAR (Avería / Pinchazo / Devolución) + EMAIL A SEDE
   ============================================================ */
function crearIncidenciaPorTipo(string $tipo): void {
    $b   = bodyJson();
    $u   = $_SESSION['usuario'];
    $pdo = conectarDB();

    // Campos comunes obligatorios para los 3 tipos
    $obligComunes = ['id_contrato', 'cliente', 'obra', 'tipo_maquina',
                     'num_maquina', 'sede', 'nombre', 'apellidos',
                     'telefono', 'email'];

    // Específicos según tipo
    $oblig = $obligComunes;
    if ($tipo === 'averia')     $oblig = array_merge($oblig, ['tipo_fallo', 'estado_maquina', 'urgencia']);
    if ($tipo === 'pinchazo')   $oblig = array_merge($oblig, ['posicion_rueda']);
    if ($tipo === 'devolucion') $oblig = array_merge($oblig, ['horas_finales']);

    $faltan = [];
    foreach ($oblig as $c) {
        if (!isset($b[$c]) || $b[$c] === '') $faltan[] = $c;
    }
    if ($faltan) {
        jsonError('Faltan campos: ' . implode(', ', $faltan));
    }

    $sedeNombre = trim((string) $b['sede']);
    if (!idSedePorNombre($sedeNombre)) {
        jsonError('Sede no válida');
    }
    if (!filter_var($b['email'], FILTER_VALIDATE_EMAIL)) {
        jsonError('Email no válido');
    }

    $datosIncidencia = [
        'ticket'             => $tipo === 'averia' ? generarTicket() : null,
        'sede_nombre'        => $sedeNombre,
        'contrato_codigo'    => limpiar($b['id_contrato']),
        'cliente_nombre'     => limpiar($b['cliente']),
        'obra_nombre'        => limpiar($b['obra']),
        'maquina_modelo'     => limpiar($b['tipo_maquina']),
        'numero_maquina'     => limpiar($b['num_maquina']),
        'contacto_nombre'    => limpiar($b['nombre']),
        'contacto_apellidos' => limpiar($b['apellidos']),
        'contacto_telefono'  => limpiar($b['telefono']),
        'contacto_email'     => limpiar($b['email']),
        'ubicacion'          => isset($b['ubicacion']) ? limpiar((string) $b['ubicacion']) : null,
        'ubicacion_aclar'    => isset($b['ubicacion_aclar']) ? limpiar((string) $b['ubicacion_aclar']) : null,
        'observaciones'      => isset($b['observaciones']) ? limpiar((string) $b['observaciones']) : null,
    ];

    // ⬇️ NUEVO: datos extra para construir el cuerpo del email según el tipo
    $datosEmailExtra = [];

    try {
        $pdo->beginTransaction();
        $idInc = crearIncidencia($tipo, $datosIncidencia);

        if ($tipo === 'averia') {
            $stmt = $pdo->prepare('
                INSERT INTO incidencias_averia
                    (id_incidencia, id_tipo_fallo, tipo_fallo_texto, id_urgencia,
                     impacto_operativo, descripcion_tecnica)
                VALUES (?, ?, ?, ?, ?, ?)
            ');
            $stmt->execute([
                $idInc,
                idTipoFalloPorNombre($b['tipo_fallo']),
                limpiar($b['tipo_fallo']),
                idUrgenciaPorCodigo($b['urgencia']),
                $b['estado_maquina'],
                limpiar($b['descripcion'] ?? '') ?: null,
            ]);
            // ⬇️ NUEVO: para el email
            $datosEmailExtra = [
                'tipo_fallo'        => limpiar($b['tipo_fallo']),
                'urgencia'          => strtoupper($b['urgencia']),
                'impacto_operativo' => $b['estado_maquina'],
                'descripcion'       => limpiar($b['descripcion'] ?? ''),
            ];
        } elseif ($tipo === 'pinchazo') {
            $pos = $b['posicion_rueda'];
            if (!array_key_exists($pos, POSICIONES_RUEDA)) {
                throw new RuntimeException('Posición de rueda no válida');
            }
            $stmt = $pdo->prepare('
                INSERT INTO incidencias_pinchazo (id_incidencia, posicion_rueda)
                VALUES (?, ?)
            ');
            $stmt->execute([$idInc, $pos]);
            // ⬇️ NUEVO: para el email
            $datosEmailExtra = ['posicion_rueda' => $pos];
        } elseif ($tipo === 'devolucion') {
            $horas = (int) $b['horas_finales'];
            $stmt = $pdo->prepare('
                INSERT INTO incidencias_devolucion
                    (id_incidencia, horas_finales, comentarios)
                VALUES (?, ?, ?)
            ');
            $stmt->execute([
                $idInc,
                $horas,
                limpiar($b['comentarios'] ?? '') ?: null,
            ]);
            // ⬇️ NUEVO: para el email
            $datosEmailExtra = [
                'horas_finales' => $horas,
                'comentarios'   => limpiar($b['comentarios'] ?? ''),
            ];
        }

        $pdo->commit();
    } catch (\Throwable $e) {
        $pdo->rollBack();
        jsonError('Error al guardar: ' . $e->getMessage(), 500);
    }

    // Marca como "enviada" (la app subirá fotos/email después si quiere).
    $pdo->prepare('UPDATE incidencias SET estado = "enviada" WHERE id_incidencia = ?')
        ->execute([$idInc]);

    /* ============================================================
       ⬇️ NUEVO: ENVÍO DE EMAIL A LA SEDE
       Reutiliza la lógica que ya existe en utilidades/funciones.php
       ============================================================ */
    $datosEmail = $datosIncidencia + $datosEmailExtra;
    $ticket     = $datosIncidencia['ticket'];

    $tituloEmail = match($tipo) {
        'averia'     => 'AVERÍA — Ticket ' . $ticket,
        'pinchazo'   => 'PINCHAZO — Sede ' . $sedeNombre,
        'devolucion' => 'DEVOLUCIÓN — Sede ' . $sedeNombre,
        default      => 'Incidencia',
    };

    $asunto = match($tipo) {
        'averia'     => 'Reporte de Avería ' . $ticket . ' - Sede ' . $sedeNombre,
        'pinchazo'   => 'Reporte de Pinchazo - Sede ' . $sedeNombre,
        'devolucion' => 'Acta de Devolución - Sede ' . $sedeNombre,
        default      => 'Notificación',
    };

    $cuerpo  = construirCuerpoEmail($tituloEmail, $datosEmail, []);
    $resMail = enviarEmailSede($sedeNombre, $asunto, $cuerpo);

    // Marcar el estado del email en la BD
    marcarIncidenciaEnviada($idInc, $resMail);

    // Devolver al móvil el resultado del envío
    jsonOk([
        'id'              => $idInc,
        'tipo'            => $tipo,
        'ticket'          => $ticket,
        'email_enviado'   => $resMail['ok'],
        'email_destino'   => $resMail['destino'],
        'email_error'     => $resMail['error'] ?? null,
    ], 201);
}


/* ============================================================
   LISTAR (con filtros y rol)
   ============================================================ */
function listarIncidencias(): void {
    $u   = $_SESSION['usuario'];
    $pdo = conectarDB();

    $alcance = alcanceIncidencias();
    $filtros = construirFiltrosListado($_GET);
    $tipo    = $_GET['tipo'] ?? null;

    $whereTipo = '';
    $params    = array_merge($alcance['params'], $filtros['params']);
    if (in_array($tipo, ['averia', 'pinchazo', 'devolucion'], true)) {
        $whereTipo = ' AND i.tipo = ?';
        $params[]  = $tipo;
    }

    $sql = "
        SELECT i.id_incidencia, i.tipo, i.ticket, i.sede_nombre,
               i.cliente_nombre, i.maquina_modelo, i.numero_maquina,
               i.estado, i.fecha_creacion
        FROM   incidencias i
        WHERE  {$alcance['where']} {$filtros['where']} {$whereTipo}
        ORDER BY i.fecha_creacion DESC
        LIMIT 100
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    $rows = array_map(function ($r) {
        return [
            'id'             => (int) $r['id_incidencia'],
            'tipo'           => $r['tipo'],
            'ticket'         => $r['ticket'],
            'sede'           => $r['sede_nombre'],
            'cliente'        => $r['cliente_nombre'],
            'maquina'        => $r['maquina_modelo'],
            'num_maquina'    => $r['numero_maquina'],
            'estado'         => $r['estado'],
            'fecha_creacion' => $r['fecha_creacion'],
        ];
    }, $stmt->fetchAll());

    jsonOk(['incidencias' => $rows, 'total' => count($rows)]);
}


/* ============================================================
   DETALLE (verifica acceso por rol)
   ============================================================ */
function verIncidencia(int $id): void {
    $datos = cargarIncidenciaConAcceso($id);
    if (!$datos) jsonError('No tienes acceso o no existe', 404);

    $base = (defined('UPLOAD_URL') ? UPLOAD_URL : 'uploads/');
    $fotos = array_map(fn($f) => [
        'id'  => (int) $f['id_foto'],
        'url' => $f['ruta'], // ruta relativa (Android la prefijará con BASE_URL)
    ], $datos['fotos']);

    jsonOk([
        'incidencia' => $datos['inc'],
        'detalle'    => $datos['hija'],
        'fotos'      => $fotos,
    ]);
}


/* ============================================================
   SUBIR FOTOS
   ============================================================ */
function subirFotos(int $idIncidencia): void {
    $u = $_SESSION['usuario'];

    // Verifica que tiene acceso a esa incidencia
    $datos = cargarIncidenciaConAcceso($idIncidencia);
    if (!$datos) jsonError('No tienes acceso a esa incidencia', 403);

    if (empty($_FILES['foto'])) {
        jsonError('Falta el archivo "foto"');
    }

    // Reutiliza la función ya existente; espera la estructura
    // de $_FILES con índices [name][0], [tmp_name][0], etc.
    $files = [
        'name'     => [$_FILES['foto']['name']],
        'type'     => [$_FILES['foto']['type']],
        'tmp_name' => [$_FILES['foto']['tmp_name']],
        'error'    => [$_FILES['foto']['error']],
        'size'     => [$_FILES['foto']['size']],
    ];

    $tipo  = $datos['inc']['tipo']; // averia / pinchazo / devolucion
    $rutas = guardarFotosSubidas($tipo, $idIncidencia, $files);

    if (!$rutas) jsonError('No se pudo subir la foto', 500);

    jsonOk(['rutas' => $rutas], 201);
}