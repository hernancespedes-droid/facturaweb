<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Envío de mails por SMTP, sin librerías: SSL directo (puerto 465), STARTTLS (587) o sin cifrado (solo para pruebas locales),
 * con usuario y contraseña (AUTH LOGIN). El certificado del servidor siempre se verifica. Se configura en config/local.php:
 *
 *   'mail' => ['host' => 'smtp.hosting.com', 'port' => 587, 'secure' => 'tls', 'user' => 'facturas@dominio.com',
 *              'pass' => '...', 'from' => 'facturas@dominio.com', 'from_name' => 'Mi Empresa SA']
 */
final class Mailer
{
    public static function configurado(): bool
    {
        $c = (array) Config::get('mail', []);
        return trim((string) ($c['host'] ?? '')) !== '' && filter_var((string) ($c['from'] ?? ''), FILTER_VALIDATE_EMAIL) !== false;
    }

    /**
     * @param string[] $para
     * @param array<string,string> $adjuntos nombre de archivo => contenido
     * @return string|null el motivo del error, o null si el servidor aceptó el mensaje
     */
    public static function enviar(array $para, string $asunto, string $texto, array $adjuntos = []): ?string
    {
        if (!self::configurado()) {
            return 'El envío de mails no está configurado en este servidor.';
        }
        $c = (array) Config::get('mail', []);
        $para = array_values(array_filter($para, static fn ($m) => filter_var($m, FILTER_VALIDATE_EMAIL) !== false));
        if ($para === []) {
            return 'No hay ningún mail válido para enviar.';
        }
        $mensaje = self::mensaje((string) $c['from'], (string) ($c['from_name'] ?? ''), $para, $asunto, $texto, $adjuntos);
        try {
            self::smtp($c, (string) $c['from'], $para, $mensaje);
        } catch (\RuntimeException $e) {
            return $e->getMessage();
        }
        return null;
    }

    /** El mensaje completo (cabeceras y cuerpo MIME). @param string[] $para @param array<string,string> $adjuntos */
    public static function mensaje(string $de, string $nombreDe, array $para, string $asunto, string $texto, array $adjuntos = []): string
    {
        $limpio = static fn (string $s): string => trim(str_replace(["\r", "\n"], ' ', $s));
        $codificar = static fn (string $s): string => preg_match('/^[\x20-\x7e]*$/', $s) === 1 ? $s : '=?UTF-8?B?' . base64_encode($s) . '?=';
        $borde = 'fw_' . bin2hex(random_bytes(12));
        $dominio = substr((string) strrchr($de, '@'), 1) ?: 'localhost';
        $h = [
            'From: ' . ($nombreDe !== '' ? $codificar($limpio($nombreDe)) . ' <' . $de . '>' : $de),
            'To: ' . implode(', ', $para),
            'Subject: ' . $codificar($limpio($asunto)),
            'Date: ' . date('r'),
            'Message-ID: <' . bin2hex(random_bytes(12)) . '@' . $dominio . '>',
            'MIME-Version: 1.0',
            'Content-Type: multipart/mixed; boundary="' . $borde . '"',
        ];
        $m = implode("\r\n", $h) . "\r\n\r\n--{$borde}\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n"
            . chunk_split(base64_encode($texto), 76, "\r\n");
        foreach ($adjuntos as $nombre => $contenido) {
            $n = $limpio(str_replace('"', '', (string) $nombre));
            $m .= "--{$borde}\r\nContent-Type: application/pdf; name=\"{$n}\"\r\nContent-Transfer-Encoding: base64\r\n"
                . "Content-Disposition: attachment; filename=\"{$n}\"\r\n\r\n" . chunk_split(base64_encode($contenido), 76, "\r\n");
        }
        return $m . "--{$borde}--\r\n";
    }

    /** @param array<string,mixed> $c @param string[] $para */
    private static function smtp(array $c, string $de, array $para, string $mensaje): void
    {
        $host = (string) $c['host'];
        $secure = (string) ($c['secure'] ?? 'tls');
        $port = (int) ($c['port'] ?? ($secure === 'ssl' ? 465 : 587));
        $timeout = (int) ($c['timeout'] ?? 20);
        $errno = 0;
        $errstr = '';
        $ctx = stream_context_create(['ssl' => ['verify_peer' => true, 'verify_peer_name' => true, 'peer_name' => $host]]);
        $s = @stream_socket_client(($secure === 'ssl' ? 'ssl://' : 'tcp://') . $host . ':' . $port, $errno, $errstr, $timeout, STREAM_CLIENT_CONNECT, $ctx);
        if ($s === false) {
            throw new \RuntimeException("No se pudo conectar con el servidor de mail ({$host}:{$port}): {$errstr}");
        }
        stream_set_timeout($s, $timeout);
        try {
            self::esperar($s, [220]);
            $yo = preg_replace('/[^a-z0-9.-]/i', '', (string) ($_SERVER['SERVER_NAME'] ?? 'localhost')) ?: 'localhost';
            self::cmd($s, 'EHLO ' . $yo, [250]);
            if ($secure === 'tls') {
                self::cmd($s, 'STARTTLS', [220]);
                if (!@stream_socket_enable_crypto($s, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                    throw new \RuntimeException('No se pudo establecer la conexión segura (TLS) con el servidor de mail.');
                }
                self::cmd($s, 'EHLO ' . $yo, [250]);
            }
            if (($c['user'] ?? '') !== '') {
                self::cmd($s, 'AUTH LOGIN', [334]);
                self::cmd($s, base64_encode((string) $c['user']), [334]);
                self::cmd($s, base64_encode((string) ($c['pass'] ?? '')), [235], 'El servidor de mail rechazó el usuario o la contraseña.');
            }
            self::cmd($s, 'MAIL FROM:<' . $de . '>', [250]);
            foreach ($para as $p) {
                self::cmd($s, 'RCPT TO:<' . $p . '>', [250, 251], "El servidor de mail no acepta el destinatario {$p}.");
            }
            self::cmd($s, 'DATA', [354]);
            // punto al comienzo de un renglón: se duplica (transparencia SMTP)
            fwrite($s, preg_replace('/^\./m', '..', $mensaje) . "\r\n.\r\n");
            self::esperar($s, [250]);
            @fwrite($s, "QUIT\r\n");
        } finally {
            fclose($s);
        }
    }

    /** @param resource $s @param int[] $ok */
    private static function cmd($s, string $linea, array $ok, ?string $mensajeError = null): void
    {
        fwrite($s, $linea . "\r\n");
        self::esperar($s, $ok, $mensajeError);
    }

    /** Lee la respuesta (puede tener varios renglones) y comprueba el código. @param resource $s @param int[] $ok */
    private static function esperar($s, array $ok, ?string $mensajeError = null): void
    {
        $resp = '';
        while (($l = fgets($s, 1024)) !== false) {
            $resp .= $l;
            if (strlen($l) < 4 || $l[3] !== '-') {
                break;
            }
        }
        $codigo = (int) substr($resp, 0, 3);
        if (!in_array($codigo, $ok, true)) {
            $detalle = trim(preg_replace('/\s+/', ' ', $resp) ?? '');
            throw new \RuntimeException(($mensajeError ?? 'El servidor de mail respondió con un error') . ($detalle !== '' ? " ({$detalle})" : ' (sin respuesta: se cortó la conexión o tardó demasiado)'));
        }
    }
}
