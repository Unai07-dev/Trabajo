<?php
/* ============================================================
   DEFINE.PHP  —  Constantes globales del Portal Gómez Oviedo
   ------------------------------------------------------------
   ⚠️ ARREGLOS DE SEGURIDAD APLICADOS:
     • Credenciales (DB y SMTP) leídas de un archivo .env que
       NO se sube a git (no del propio código fuente).
     • Cae a valores por defecto si el .env no existe (modo dev).
     • APP_ENV controla mensajes de error en producción.
   ============================================================ */

// ---------- 1) CARGADOR MÍNIMO DE .env ----------
// Lee /utilidades/.env (si existe) y mete las variables en getenv().
// No depende de Composer ni de librerías externas.
(function () {
    $envFile = __DIR__ . '/.env';
    if (!is_file($envFile)) return;

    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $linea) {
        $linea = trim($linea);
        if ($linea === '' || str_starts_with($linea, '#')) continue;
        if (!str_contains($linea, '=')) continue;

        [$clave, $valor] = explode('=', $linea, 2);
        $clave = trim($clave);
        $valor = trim($valor);

        if ((str_starts_with($valor, '"') && str_ends_with($valor, '"')) ||
            (str_starts_with($valor, "'") && str_ends_with($valor, "'"))) {
            $valor = substr($valor, 1, -1);
        }

        if (getenv($clave) === false) {
            putenv("$clave=$valor");
            $_ENV[$clave] = $valor;
        }
    }
})();

/** Devuelve una variable de entorno con valor por defecto. */
function env(string $clave, $default = null) {
    $v = getenv($clave);
    return ($v === false || $v === '') ? $default : $v;
}


// ---------- 2) ENTORNO ----------
// "production" → no muestra detalles de errores al usuario
// "development" → muestra todo (útil al programar)
define('APP_ENV', env('APP_ENV', 'development'));


// ---------- 3) APLICACIÓN ----------
define('APP_NAME',     'Gómez Oviedo');
define('APP_TAGLINE',  'Portal de Gestión');
define('APP_VERSION',  '6.1');
define('SESSION_NAME', 'GO_SESSION');


// ---------- 4) BASE DE DATOS ----------
define('DB_HOST',    env('DB_HOST',    '127.0.0.1'));
define('DB_PORT',    env('DB_PORT',    '3306'));
define('DB_NAME',    env('DB_NAME',    'gomez_oviedo'));
define('DB_USER',    env('DB_USER',    'root'));
define('DB_PASS',    env('DB_PASS',    ''));
define('DB_CHARSET', 'utf8mb4');


/* ============================================================
   5) CONFIGURACIÓN DE CORREO (SMTP)
   ============================================================ */
define('MAIL_DEBUG', filter_var(env('MAIL_DEBUG', 'false'), FILTER_VALIDATE_BOOLEAN));

define('SMTP_HOST',       env('SMTP_HOST',       'smtp.gmail.com'));
define('SMTP_PORT',       (int) env('SMTP_PORT', 587));
define('SMTP_ENCRYPTION', env('SMTP_ENCRYPTION', 'tls'));
define('SMTP_USERNAME',   env('SMTP_USERNAME',   ''));
define('SMTP_PASSWORD',   env('SMTP_PASSWORD',   ''));   // ← desde .env

define('MAIL_FROM',      env('MAIL_FROM',      'noreply@gomezoviedo.com'));
define('MAIL_FROM_NAME', env('MAIL_FROM_NAME', 'Portal Gómez Oviedo'));


/* ============================================================
   6) CONTRASEÑAS INICIALES (solo se usan en install.php)
   ============================================================ */
define('PASSWORD_ADMIN',     env('USER_PWD_ADMIN',     'CAMBIAR_admin'));
define('PASSWORD_OVIEDO',    env('USER_PWD_OVIEDO',    'CAMBIAR_oviedo'));
define('PASSWORD_GIJON',     env('USER_PWD_GIJON',     'CAMBIAR_gijon'));
define('PASSWORD_CANTABRIA', env('USER_PWD_CANTABRIA', 'CAMBIAR_cantabria'));
define('PASSWORD_SF',        env('USER_PWD_SF',        'CAMBIAR_sanfernando'));
define('PASSWORD_LEGANES',   env('USER_PWD_LEGANES',   'CAMBIAR_leganes'));
define('PASSWORD_MALAGA',    env('USER_PWD_MALAGA',    'CAMBIAR_malaga'));


// ---------- 7) RATE LIMITING (anti fuerza bruta en login) ----------
define('RATE_LIMIT_LOGIN_INTENTOS', 5);
define('RATE_LIMIT_LOGIN_VENTANA',  900); // 15 minutos
define('RATE_LIMIT_DIR', __DIR__ . '/../uploads/_rate_limit/');


// ---------- 8) SUBIDA DE FOTOS ----------
define('UPLOAD_DIR', __DIR__ . '/../uploads/');
define('UPLOAD_URL', 'uploads/');
define('UPLOAD_MAX_SIZE',  10 * 1024 * 1024);
define('UPLOAD_EXT_PERMITIDAS', ['jpg', 'jpeg', 'png', 'webp', 'heic', 'heif']);


// ---------- 9) PAGINACIÓN POR DEFECTO ----------
define('LISTADO_POR_PAGINA',  20);
define('LISTADO_PAGINA_MAX',  100);


// ---------- 10) POSICIONES DE RUEDA (Pinchazo) ----------
define('POSICIONES_RUEDA', [
    'Delantera Izq.' => '↖ Delantera Izq.',
    'Delantera Der.' => '↗ Delantera Der.',
    'Trasera Izq.'   => '↙ Trasera Izq.',
    'Trasera Der.'   => '↘ Trasera Der.',
]);


// ---------- 11) LISTA DE MÁQUINAS ----------
define('MAQUINAS', [
    'Excavadora CAT 320', 'Grúa Liebherr',
    'Desbrozadora profesional 57 cc', 'Desbrozadora profesional 45 cc',
    '2100 l/min. a 8 Bar', '4100 l/min. a 8 Bar', '5100 l/min. a 8 Bar',
    '7200 l/min. a 8 Bar', '10800 l/min. a 8 Bar',
    '9100 l/min. a 10 Bar', '11600 l/min. a 12 Bar',
    '25000 l/min. a 12 Bar', '21500 l/min. a 21 Bar',
    '255 l/min. a 8 Bar', '1700 l/min. a 10 Bar',
    '2220 l/min. a 10 Bar', '3260 l/min. a 10 Bar',
    '5740 l/min. a 10 Bar', '6500 l/min. a 10 Bar',
    '11000 l/min. a 10 Bar', '16250 l/min. a 10 Bar',
    'Tijera Eléc. 8m (Ancho 0.74m)', 'Tijera Eléc. 10m',
    'Tijera Eléc. 8m Estándar', 'Tijera Eléc. 12m',
    'Microexcavadora de orugas 1200 Kg',
    'Miniexcavadora de orugas 1700 Kg',
    'Miniexcavadora de orugas 2700 Kg',
    'Miniexcavadora de orugas 3600 Kg',
    'Gasolina Insonorizado Inverter 3500 W',
    'Gasolina Trifásico 22kVA AVR',
    'Gasolina Monofásico 3000 W', 'Gasolina Trifásico 8 kVA',
    'Diesel Insonorizado 12 kVA', 'Gasolina Insonorizado 2000 W',
    'Gasolina Monofásico 4200 W', 'Gasolina Monofásico 6400 W',
    'Gasolina Monofásico 6000 W AVR',
    'Grupo electrógeno diesel portátil de 30 kVA',
    'Grupo electrógeno diesel portátil de 41 kVA',
    'Grupo electrógeno diesel portátil de 60 kVA',
    'Grupo electrógeno diesel portátil de 100 kVA',
    'Grupo electrógeno diesel de 20 kVA ECO',
    'Grupo electrógeno diesel de 45 kVA ECO',
    'Grupo electrógeno diesel de 60 kVA ECO',
    'Grupo electrógeno diesel de 100 kVA ECO',
    'Grupo electrógeno diesel de 200 kVA',
    'Grupo electrógeno diesel de 350 kVA',
    'Grupo electrógeno diesel de 810 kVA',
    'Globo de iluminación 1000 W a 5 m',
    'Foco iluminación a batería 2200 lúmenes',
    'Torre iluminación LED 8 m',
    'Mástil LED batería 6000 lúmenes',
    'Torre iluminación diesel 9000W',
    'Inverter monofásico 160 A', 'Rectificador trifásico 300 A',
    'Motosoldadora 200 A',
    'Aire portátil 2.8 kW tipo pingüino',
    'Aire acondicionado 7.1 kW + deshumidificador',
    'Enfriador evaporativo 12.000 m3/h',
    'Enfriador evaporativo 22.000 m3/h',
    'Aire autónomo 35 kW', 'Aire 6.6 kW con unidad exterior',
    'Aire portátil 7.8 kW', 'Aire autónomo 23 kW',
    'Torre aluminio 5 m', 'Torre aluminio 7 m',
    'Torre aluminio 9 m', 'Torre compacta aluminio 6,2 m',
    'Caseta diáfana 3 m', 'Caseta diáfana 4 m', 'Caseta diáfana 6 m',
    'Caseta 7,5 m oficinas + aseo',
    'Caseta 3 m sanitarios', 'Caseta 4 m sanitarios', 'Caseta 6 m sanitarios',
    'Contenedor marítimo para obra 2,4 m',
    'Contenedor marítimo para obra 3 m',
    'Depósito de agua 1.000 l (no potable)',
    'Contenedor marítimo para obra 6 m',
    'Taquilla de obra 30 cm',
    'Baño químico portátil estándar',
]);
