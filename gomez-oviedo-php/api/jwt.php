<?php
/* ============================================================
   API/JWT.PHP  —  Implementación mínima de JWT (HS256)
   ------------------------------------------------------------
   Sin dependencias externas — usa las funciones nativas
   de PHP (hash_hmac, base64_encode...). Ideal para empezar
   sin Composer. Si tu proyecto crece, sustituye por la
   librería firebase/php-jwt.
   ============================================================ */

declare(strict_types=1);

/** base64url encode (sin =, sin +, sin /). */
function b64UrlEnc(string $s): string {
    return rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
}

/** base64url decode. */
function b64UrlDec(string $s): string {
    $pad = strlen($s) % 4;
    if ($pad) $s .= str_repeat('=', 4 - $pad);
    return base64_decode(strtr($s, '-_', '+/'));
}

/**
 * Crea un JWT firmado con HS256.
 *
 * @param array $datos  Información del usuario que se incrustará.
 */
function jwtCrear(array $datos): string {
    $cabecera = ['alg' => 'HS256', 'typ' => 'JWT'];

    $ahora   = time();
    $payload = array_merge($datos, [
        'iss' => JWT_ISSUER,
        'iat' => $ahora,
        'exp' => $ahora + (JWT_TTL_HORAS * 3600),
    ]);

    $h = b64UrlEnc(json_encode($cabecera, JSON_UNESCAPED_UNICODE));
    $p = b64UrlEnc(json_encode($payload, JSON_UNESCAPED_UNICODE));

    $firma = hash_hmac('sha256', "$h.$p", JWT_SECRET, true);
    $f     = b64UrlEnc($firma);

    return "$h.$p.$f";
}

/**
 * Valida un JWT y devuelve el payload. Lanza Exception si:
 *   - el formato es inválido
 *   - la firma no cuadra
 *   - el token ha expirado
 */
function jwtDecodificar(string $token): array {
    $partes = explode('.', $token);
    if (count($partes) !== 3) {
        throw new RuntimeException('Formato de token inválido');
    }
    [$h, $p, $f] = $partes;

    // Recalcular la firma y comparar
    $firmaEsperada = b64UrlEnc(hash_hmac('sha256', "$h.$p", JWT_SECRET, true));
    if (!hash_equals($firmaEsperada, $f)) {
        throw new RuntimeException('Firma inválida');
    }

    $payload = json_decode(b64UrlDec($p), true);
    if (!is_array($payload)) {
        throw new RuntimeException('Payload no es JSON');
    }

    if (isset($payload['exp']) && time() > (int) $payload['exp']) {
        throw new RuntimeException('Token expirado');
    }
    return $payload;
}
