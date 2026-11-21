<?php
declare(strict_types=1);

namespace Tests;

/**
 * Los .sh de bin/ tienen que quedar idénticos a los que tiene el servidor de Ferozo: el hosting les cambia los
 * permisos y GIT se niega a sincronizar si una versión nueva los toca («local changes would be overwritten»).
 * Pasó con 0.11.1 y otra vez el 21/11/2026 (bin/pruebas.sh, el sitio quedó en 0.32). Si esta prueba falla,
 * volver el archivo a como estaba y hacer el cambio en un archivo nuevo (los recorridos de navegador, en e2e/extras.js).
 */
final class ScriptsCongeladosTest extends TestCase
{
    /** Hash de GIT (blob) de cada script, tal como está en el servidor. */
    private const CONGELADOS = [
        'bin/package.sh'    => 'fb7d05a613a2c4ee9275d193c7c8f130db1b7235',
        'bin/test.sh'       => 'd17bddd7c217d5c828f5d073b84e2469c28277f3',
        'bin/setup-dev.sh'  => '792604cf00c88331dc5a49b8f8a3d94ef7cc63be',
        'bin/dev-server.sh' => '6b6ae9cef8b541aa4fc43c45c24587dfdd386c17',
        'bin/env.sh'        => '06b9982c56cdaf040e1a75f859b62de229c0ecd8',
        'bin/pruebas.sh'    => '6f7fb3c6edc8d144ecf1a3331d91cf64d1e1424f',
        'bin/empaquetar.sh' => '16a64afcf81988ccb864e29808d47a9e4578aa09',
    ];

    public function testLosScriptsDeBinNoCambian(): void
    {
        $raiz = dirname(__DIR__);
        foreach (self::CONGELADOS as $archivo => $hash) {
            $contenido = (string) file_get_contents($raiz . '/' . $archivo);
            $real = sha1('blob ' . strlen($contenido) . "\0" . $contenido);
            $this->assertSame($hash, $real, $archivo . ' cambió: Ferozo no va a poder sincronizar por GIT');
        }
    }

    public function testNoHayScriptsNuevosEnBin(): void
    {
        $raiz = dirname(__DIR__);
        $hay = array_map(fn (string $f): string => 'bin/' . basename($f), glob($raiz . '/bin/*.sh') ?: []);
        sort($hay);
        $congelados = array_keys(self::CONGELADOS);
        sort($congelados);
        $this->assertSame($congelados, $hay, 'Un .sh nuevo en bin/ también quedaría trabado en Ferozo: usar PHP o node');
    }
}
