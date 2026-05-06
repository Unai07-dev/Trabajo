<?php
/* ============================================================
   LOGOUT.PHP  —  Cierre de sesión
   ============================================================ */

require_once __DIR__ . '/utilidades/funciones.php';

cerrarSesion();
header('Location: login.php');
exit;