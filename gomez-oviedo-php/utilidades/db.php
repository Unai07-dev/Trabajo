<?php
/* ============================================================
   DB.PHP  —  Conexión PDO a MySQL
   ------------------------------------------------------------
   ⚠️ ARREGLO: en producción NO se muestra el detalle técnico
   del error al usuario (impide filtrar credenciales).
   ============================================================ */

require_once __DIR__ . '/define.php';

function conectarDB(): PDO {
    static $pdo = null;

    if ($pdo === null) {
        $dsn = sprintf(
            'mysql:host=%s;port=%s;dbname=%s;charset=%s',
            DB_HOST, DB_PORT, DB_NAME, DB_CHARSET
        );

        try {
            $pdo = new PDO($dsn, DB_USER, DB_PASS, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]);
        } catch (PDOException $e) {
            // Loguear el detalle real para los administradores
            error_log('DB CONNECTION ERROR: ' . $e->getMessage());

            http_response_code(500);

            // En producción: mensaje genérico
            $esDev = (APP_ENV === 'development');
            $msgTec = $esDev
                ? htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8')
                : '(detalle oculto en producción — revisa el log del servidor)';

            echo "<!DOCTYPE html><html lang='es'><head><meta charset='utf-8'><title>Error</title>";
            echo "<style>body{font-family:sans-serif;background:#0b1929;color:#fff;padding:40px;line-height:1.6}";
            echo ".box{background:#1e293b;padding:30px;border-radius:12px;max-width:760px;margin:auto;border-left:4px solid #dc2626}";
            echo "code{background:#0b1929;padding:2px 8px;border-radius:4px;color:#06b6d4}</style></head><body>";
            echo "<div class='box'><h1>⚠️ No se pudo conectar a la base de datos</h1>";

            if ($esDev) {
                echo "<p><strong>Detalle:</strong> <code>$msgTec</code></p>";
                echo "<hr style='border-color:#334155'>";
                echo "<h3>Cómo solucionarlo</h3><ol>";
                echo "<li>Comprueba que <strong>MySQL está arrancado</strong> en XAMPP.</li>";
                echo "<li>Verifica las credenciales en <code>utilidades/.env</code>.</li>";
                echo "<li>Ejecuta el instalador: <a href='install.php' style='color:#06b6d4'>install.php</a> "
                    ."o importa <code>gomez_oviedo.sql</code> en phpMyAdmin.</li>";
                echo "</ol>";
            } else {
                echo "<p>Estamos teniendo problemas técnicos. Por favor, prueba de nuevo en unos minutos.</p>";
                echo "<p style='opacity:.6;font-size:12px;'>Si el problema persiste, contacta con el administrador.</p>";
            }

            echo "</div></body></html>";
            exit;
        }
    }

    return $pdo;
}

function conectarServidorMySQL(): PDO {
    $dsn = sprintf('mysql:host=%s;port=%s;charset=%s', DB_HOST, DB_PORT, DB_CHARSET);
    return new PDO($dsn, DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE          => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
}
