<?php
declare(strict_types=1);

namespace Tests;

use App\Core\Config;

/** Levanta el servidor SMTP de mentira (fixtures/smtp_falso.php) y configura la sección mail para usarlo. */
trait ConServidorSmtp
{
    /** @var resource|null */
    private $procSmtp = null;
    private string $salidaSmtp = '';

    private function servidorSmtp(array $extra = []): void
    {
        $puerto = random_int(20000, 40000);
        $this->salidaSmtp = sys_get_temp_dir() . '/fw_smtp_' . bin2hex(random_bytes(4));
        $this->procSmtp = proc_open([PHP_BINARY, __DIR__ . '/fixtures/smtp_falso.php', (string) $puerto, $this->salidaSmtp], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        // el servidor avisa con "LISTO" cuando ya está escuchando (no se prueba conectando: gastaría su única conexión)
        $r = [$pipes[1]];
        $w = $e = null;
        stream_select($r, $w, $e, 5);
        $this->assertSame("LISTO\n", fgets($pipes[1]));
        $cfg = require dirname(__DIR__) . '/config/config.php';
        $cfg['mail'] = $extra + ['host' => '127.0.0.1', 'port' => $puerto, 'secure' => '', 'user' => 'facturas', 'pass' => 'secreta', 'from' => 'facturas@empresa.com', 'from_name' => 'Empresa Ñandú SA'];
        Config::load($cfg);
    }

    private function cerrarSmtp(): void
    {
        if (is_resource($this->procSmtp)) {
            proc_terminate($this->procSmtp);
            proc_close($this->procSmtp);
        }
        foreach ([$this->salidaSmtp, $this->salidaSmtp . '.json'] as $f) {
            if ($f !== '' && is_file($f)) {
                unlink($f);
            }
        }
        Config::load(require dirname(__DIR__) . '/config/config.php');
    }

    private function esperarArchivo(string $f): string
    {
        for ($i = 0; $i < 30 && !is_file($f); $i++) {
            usleep(100000);
        }
        return is_file($f) ? (string) file_get_contents($f) : '';
    }
}
