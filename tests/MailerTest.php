<?php
declare(strict_types=1);

namespace Tests;

use App\Core\Config;
use App\Core\Mailer;

final class MailerTest extends TestCase
{
    use ConServidorSmtp;

    protected function tearDown(): void
    {
        $this->cerrarSmtp();
    }

    public function testEnviaConAdjuntoYAutenticacion(): void
    {
        $this->servidorSmtp();
        $this->assertTrue(Mailer::configurado());
        $error = Mailer::enviar(['cliente@x.com', 'otro@y.com'], "Factura A 0004-00000001 - Ñandú\r\nBcc: robo@x.com", "Hola\r\n.\r\nlínea con punto arriba", ['Factura.pdf' => "%PDF-falso\n.punto\n"]);
        $this->assertNull($error);
        $msg = $this->esperarArchivo($this->salidaSmtp);
        $sobre = json_decode($this->esperarArchivo($this->salidaSmtp . '.json'), true);
        $this->assertSame(['facturas', 'secreta'], $sobre['auth']);
        $this->assertSame(['<cliente@x.com>', '<otro@y.com>'], $sobre['para']);
        $this->assertSame('<facturas@empresa.com>', $sobre['de']);
        $this->assertStringContainsString('Subject: =?UTF-8?B?', $msg);
        $this->assertStringNotContainsString("\r\nBcc:", $msg, 'no se puede colar una cabecera por el asunto');
        $this->assertStringContainsString('filename="Factura.pdf"', $msg);
        $this->assertStringContainsString(base64_encode("%PDF-falso\n.punto\n"), str_replace("\r\n", '', $msg));
        $this->assertStringContainsString('From: =?UTF-8?B?', $msg);
        // el texto vuelve igual (base64): incluye el renglón que es solo un punto
        preg_match('/Content-Transfer-Encoding: base64\r\n\r\n(.*?)\r\n--/s', $msg, $m);
        $this->assertSame("Hola\r\n.\r\nlínea con punto arriba", base64_decode(str_replace("\r\n", '', $m[1])));
    }

    public function testErroresSeDevuelvenSinRomperNada(): void
    {
        $this->servidorSmtp(['pass' => 'mal']);
        $this->assertStringContainsString('rechazó el usuario o la contraseña', (string) Mailer::enviar(['a@b.com'], 'x', 'y'));
        $this->assertStringContainsString('No hay ningún mail válido', (string) Mailer::enviar(['no-es-mail'], 'x', 'y'));
        $cfg = require dirname(__DIR__) . '/config/config.php';
        $cfg['mail'] = ['host' => '127.0.0.1', 'port' => 1, 'secure' => '', 'from' => 'a@b.com'];
        Config::load($cfg);
        $this->assertStringContainsString('No se pudo conectar', (string) Mailer::enviar(['a@b.com'], 'x', 'y'));
        $cfg['mail'] = ['host' => '', 'from' => ''];
        Config::load($cfg);
        $this->assertFalse(Mailer::configurado());
        $this->assertStringContainsString('no está configurado', (string) Mailer::enviar(['a@b.com'], 'x', 'y'));
    }

    public function testDestinatarioRechazado(): void
    {
        $this->servidorSmtp();
        $this->assertStringContainsString('no acepta el destinatario rechazado@x.com', (string) Mailer::enviar(['rechazado@x.com'], 'x', 'y'));
    }
}
