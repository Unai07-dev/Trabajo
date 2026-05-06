<?php
/* ============================================================
   SmtpMailer.php 
   ------------------------------------------------------------
   ============================================================ */

class SmtpMailer
{
    public string $host       = '';
    public int    $port       = 587;
    public string $username   = '';
    public string $password   = '';
    public string $encryption = 'tls';   // 'tls' (STARTTLS), 'ssl' (SMTPS), '' (plano)
    public int    $timeout    = 15;
    public string $charset    = 'UTF-8';
    public string $hostname   = 'localhost';
    public bool   $debug      = false;

    public string $lastError  = '';
    public string $debugLog   = '';

    private $socket = null;


    /**
     * Envía un correo. Devuelve true si OK, false si hubo error
     * (ver $this->lastError).
     */
    public function send(string $from, string $fromName, string $to, string $subject, string $body): bool
    {
        $this->lastError = '';
        $this->debugLog  = '';

        try {
            $this->connect();
            $this->ehlo();

            // STARTTLS (puerto 587 con Gmail/Outlook)
            if (strtolower($this->encryption) === 'tls') {
                $this->cmd("STARTTLS\r\n", 220);
                $crypto = STREAM_CRYPTO_METHOD_TLS_CLIENT
                        | STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT
                        | STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT;
                if (!@stream_socket_enable_crypto($this->socket, true, $crypto)) {
                    throw new RuntimeException('No se pudo habilitar STARTTLS (¿openssl activado en PHP?).');
                }
                $this->ehlo();   // EHLO de nuevo tras activar TLS
            }

            // AUTH LOGIN (si hay credenciales)
            if ($this->username !== '') {
                $this->cmd("AUTH LOGIN\r\n",                            334);
                $this->cmd(base64_encode($this->username) . "\r\n",     334);
                $this->cmd(base64_encode($this->password) . "\r\n",     235);
            }

            // MAIL FROM
            $this->cmd('MAIL FROM:<' . $this->cleanAddress($from) . ">\r\n", 250);

            // RCPT TO (admite varios separados por coma)
            foreach (preg_split('/\s*,\s*/', $to) as $rcpt) {
                if ($rcpt === '') continue;
                $this->cmd('RCPT TO:<' . $this->cleanAddress($rcpt) . ">\r\n", 250);
            }

            // DATA
            $this->cmd("DATA\r\n", 354);

            $mensaje  = $this->buildHeaders($from, $fromName, $to, $subject) . "\r\n\r\n";
            $mensaje .= $this->normalizeLineEndings($body);
            $mensaje  = preg_replace('/^\./m', '..', $mensaje);  // dot-stuffing (RFC 5321 §4.5.2)

            $this->cmd($mensaje . "\r\n.\r\n", 250);

            $this->cmd("QUIT\r\n", 221);
            $this->disconnect();
            return true;

        } catch (\Throwable $e) {
            $this->lastError = $e->getMessage();
            $this->disconnect();
            return false;
        }
    }


    /* --------------------------------------------------------
       Privados
       -------------------------------------------------------- */

    private function connect(): void
    {
        $proto  = (strtolower($this->encryption) === 'ssl') ? 'ssl://' : '';
        $errno  = 0;
        $errstr = '';

        $this->socket = @stream_socket_client(
            $proto . $this->host . ':' . $this->port,
            $errno, $errstr,
            $this->timeout,
            STREAM_CLIENT_CONNECT
        );

        if (!$this->socket) {
            throw new RuntimeException("Conexión SMTP fallida ($this->host:$this->port): $errstr [$errno]");
        }
        stream_set_timeout($this->socket, $this->timeout);
        $this->expect(220);
    }

    private function ehlo(): void
    {
        $this->cmd('EHLO ' . $this->hostname . "\r\n", 250);
    }

    private function cmd(string $cmd, int $expected): void
    {
        $this->log('> ' . rtrim($cmd));
        if (@fwrite($this->socket, $cmd) === false) {
            throw new RuntimeException('Error al escribir en el socket SMTP.');
        }
        $this->expect($expected);
    }

    private function expect(int $code): void
    {
        $resp = '';
        while (!feof($this->socket)) {
            $line = @fgets($this->socket, 515);
            if ($line === false) break;
            $resp .= $line;
            $this->log('< ' . rtrim($line));
            // Una respuesta multi-línea termina cuando el código va seguido de espacio
            if (preg_match('/^\d{3} /', $line)) break;
        }
        if (!preg_match("/^$code/", $resp)) {
            throw new RuntimeException('Respuesta SMTP inesperada (esperaba ' . $code . '): ' . trim($resp));
        }
    }

    private function buildHeaders(string $from, string $fromName, string $to, string $subject): string
    {
        $h   = [];
        $h[] = 'Date: '      . date('r');
        $h[] = 'From: '      . $this->encodeHeader($fromName) . ' <' . $this->cleanAddress($from) . '>';
        $h[] = 'To: '        . $to;
        $h[] = 'Subject: '   . $this->encodeHeader($subject);
        $h[] = 'Reply-To: '  . $this->cleanAddress($from);
        $h[] = 'MIME-Version: 1.0';
        $h[] = 'Content-Type: text/plain; charset=' . $this->charset;
        $h[] = 'Content-Transfer-Encoding: 8bit';
        $h[] = 'X-Mailer: SmtpMailer/1.0 (PHP ' . phpversion() . ')';
        return implode("\r\n", $h);
    }

    private function encodeHeader(string $s): string
    {
        return preg_match('/[^\x20-\x7E]/', $s)
             ? '=?UTF-8?B?' . base64_encode($s) . '?='
             : $s;
    }

    private function cleanAddress(string $a): string
    {
        return trim(preg_replace('/[\r\n<>]/', '', $a));
    }

    private function normalizeLineEndings(string $s): string
    {
        // Convertir todo a \r\n
        $s = str_replace(["\r\n", "\r"], "\n", $s);
        return str_replace("\n", "\r\n", $s);
    }

    private function disconnect(): void
    {
        if ($this->socket) {
            @fclose($this->socket);
            $this->socket = null;
        }
    }

    private function log(string $msg): void
    {
        if ($this->debug) {
            // Oculta contraseña base64 en log
            if (preg_match('/^> [A-Za-z0-9+\/=]{20,}$/', $msg)) $msg = '> [credenciales ocultas]';
            $this->debugLog .= $msg . "\n";
        }
    }
}
