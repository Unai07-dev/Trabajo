<?php
/* ============================================================
   INDEX.PHP  —  Punto de entrada
   ------------------------------------------------------------
   ============================================================ */

require_once __DIR__ . '/utilidades/funciones.php';

if (usuarioActual()) {
    header('Location: secciones/usuarios.php');
} else {
    header('Location: login.php');
}
exit;
