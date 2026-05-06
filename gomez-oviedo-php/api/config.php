<?php
/* ============================================================
   API/CONFIG.PHP  —  Configuración de la API
   ------------------------------------------------------------
   Reutiliza define.php, db.php y funciones.php del portal web.
   El JWT_SECRET sale del archivo .env por seguridad.
   ============================================================ */

declare(strict_types=1);

require_once __DIR__ . '/../utilidades/define.php';
require_once __DIR__ . '/../utilidades/db.php';
require_once __DIR__ . '/../utilidades/funciones.php';

/*
 * JWT_SECRET — Genera una con:
 *   php -r "echo bin2hex(random_bytes(32));"
 * y guárdala en utilidades/.env como JWT_SECRET=...
 */
$jwtSecret = env('JWT_SECRET', '');
if ($jwtSecret === '' || strlen($jwtSecret) < 32) {
    // En producción esto lanzaría un error. En dev, valor de respaldo.
    $jwtSecret = 'DEV_ONLY_INSECURE_KEY_change_me_via_dotenv_min_32_chars';
}
define('JWT_SECRET',     $jwtSecret);
define('JWT_ISSUER',     'gomez-oviedo-api');
define('JWT_TTL_HORAS',  (int) env('JWT_TTL_HORAS', 24 * 7)); // 7 días por defecto
