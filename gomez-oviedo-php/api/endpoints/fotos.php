<?php
/* ============================================================
   API/ENDPOINTS/FOTOS.PHP  —  Servir fotos protegidas (opcional)
   ------------------------------------------------------------
   En producción podrías querer que las fotos de incidencias
   solo se sirvan a usuarios autenticados con permiso.
   Por simplicidad, la app móvil descargará directamente
   /uploads/foto.png con un Bearer Token también admitido aquí.
   ============================================================ */

declare(strict_types=1);

function manejarFotos(string $accion): void {
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        jsonError('Método no permitido', 405);
    }

    autenticarPeticion();

    $nombre = basename($accion);
    if (!$nombre || strpos($nombre, '..') !== false) {
        jsonError('Nombre de archivo inválido', 400);
    }

    $ruta = UPLOAD_DIR . $nombre;
    if (!is_file($ruta)) {
        jsonError('Foto no encontrada', 404);
    }

    // Servir el archivo (saltando la cabecera JSON)
    header_remove('Content-Type');
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    header('Content-Type: ' . finfo_file($finfo, $ruta));
    finfo_close($finfo);

    header('Content-Length: ' . filesize($ruta));
    readfile($ruta);
    exit;
}
