<?php
declare(strict_types=1);

namespace Tests;

use App\App;
use App\Core\Config;
use App\Core\Db;
use App\Modules\Arca\Arca;
use App\Modules\Arca\AutorizacionRechazada;
use App\Modules\Arca\GrabacionFallida;
use App\Modules\Comprobantes\ComprobanteImpreso;
use App\Modules\Comprobantes\ComprobantePdf;

/** Factura electrónica contra el ARCA simulado (sin internet ni certificado real). */
final class ArcaTest extends ComprobantesTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $cfg = require App::root() . '/config/config.php';
        $cfg['afip'] = ['modo' => 'simulado'];
        Config::load($cfg);
        Arca::reiniciar();
        Db::pdo()->exec("DELETE FROM afip_operaciones");
        Db::pdo()->exec("UPDATE sucursales SET fac_electronica = 1 WHERE codigo = 99");
    }

    protected function tearDown(): void
    {
        Db::pdo()->exec("DELETE FROM afip_operaciones");
        Config::load(require App::root() . '/config/config.php');
        Arca::reiniciar();
        parent::tearDown();
    }

    public function testFacturaAConCaeSimulado(): void
    {
        $e = $this->emitir($this->pedido());
        $h = $this->svc->obtener($e['id']);
        $this->assertSame(1, $e['numero']);
        $this->assertTrue(str_starts_with($h['fe_ccae'], '99') && strlen($h['fe_ccae']) === 14, 'CAE simulado de 14 dígitos');
        $this->assertSame(['S', 1, 1], [$h['fe_entorno'], (int) $h['es_prueba'], (int) $h['electronica']]);
        $this->assertSame(1, (int) $h['fe_nrocbte']);
        $op = $this->fila('SELECT estado, numero, cae FROM afip_operaciones');
        $this->assertSame(['grabada', 1, $h['fe_ccae']], [$op['estado'], (int) $op['numero'], $op['cae']]);
        $this->assertSame(2, $this->emitir($this->pedido())['numero'], 'ARCA numera correlativo');
        $this->assertSame(2, (int) $this->fila('SELECT ultima_factura_a AS n FROM sucursales WHERE codigo = 99')['n']);
    }

    public function testFacturaBYCEnLaMismaSucursal(): void
    {
        $b = $this->emitir($this->pedido(['cliente' => '990002', 'forma_pago' => '1', 'fpago' => 'EF']));
        $this->assertSame('B', $this->svc->obtener($b['id'])['letra']);
        $this->assertSame(1, $b['numero']);
    }

    public function testNotaDeCreditoNecesitaFacturaConCaeYSeEmite(): void
    {
        // una factura "interna" sin CAE no se puede asociar cuando la sucursal es electrónica
        $fc = $this->emitir($this->pedido())['id'];
        $nc = $this->pedido(['tipo' => 'NC', 'asociado' => (string) $fc, 'sucursal' => '',
            'items' => [['descripcion' => 'Devolución', 'cantidad' => '1', 'precio' => '100', 'alicuota' => '21']]]);
        $e = $this->emitir($nc);
        $h = $this->svc->obtener($e['id']);
        $this->assertSame(['NC', 'A', 1], [$h['tipo'], $h['letra'], (int) $h['fe_nrocbte']]);
        $this->assertTrue($h['fe_ccae'] !== '');

        Db::pdo()->exec("UPDATE subdiario SET fe_ccae = '' WHERE fe_clave = {$fc}");
        $r = $this->svc->armar($nc);
        $this->assertTrue(isset($r['errores']['asociado']), 'asociado sin CAE');
    }

    public function testRechazoNoGrabaNiConsumeNumero(): void
    {
        $pedido = $this->pedido(['items' => [['descripcion' => 'x', 'cantidad' => '1', 'precio' => '100.13', 'alicuota' => '0']]]);
        $r = $this->svc->armar($pedido);
        $this->assertSame([], $r['errores']);
        $op = bin2hex(random_bytes(16));
        $capturada = null;
        try {
            $this->svc->emitir($r['cbte'], $op, 0);
        } catch (AutorizacionRechazada $e) {
            $capturada = $e;
        }
        $this->assertNotNull($capturada, 'ARCA rechaza');
        $this->assertSame(0, (int) Db::pdo()->query('SELECT COUNT(*) FROM subdiario')->fetchColumn());
        $this->assertSame(0, (int) $this->fila('SELECT ultima_factura_a AS n FROM sucursales WHERE codigo = 99')['n']);
        $this->assertSame('rechazada', $this->fila('SELECT estado FROM afip_operaciones')['estado']);
    }

    public function testDobleEnvioNoPideOtroCae(): void
    {
        $op = bin2hex(random_bytes(16));
        $a = $this->emitir($this->pedido(), $op);
        $b = $this->emitir($this->pedido(), $op);
        $this->assertTrue($b['repetido']);
        $this->assertSame($a['id'], $b['id']);
        $this->assertSame(1, (int) Db::pdo()->query('SELECT COUNT(*) FROM subdiario')->fetchColumn());
    }

    public function testAprobadoQueNoSeGrabaSeRecupera(): void
    {
        // un comprobante interno ocupa el lugar donde ARCA va a numerar: grabar falla DESPUÉS de tener el CAE
        $pdo = Db::pdo();
        $pdo->exec("INSERT INTO subdiario (tipo, letra, sucursal, comprobante, fecha, cliente, operacion) VALUES ('FC', 'A', 99, 1, CURDATE(), 990001, 'ocupado')");
        $r = $this->svc->armar($this->pedido());
        $op = bin2hex(random_bytes(16));
        $falla = null;
        try {
            $this->svc->emitir($r['cbte'], $op, 0);
        } catch (GrabacionFallida $e) {
            $falla = $e;
        }
        $this->assertNotNull($falla, 'ARCA aprobó y no se pudo grabar');
        $f = $this->fila('SELECT id, estado, cae FROM afip_operaciones');
        $this->assertSame('error', $f['estado']);
        $this->assertTrue($f['cae'] !== '');

        $pdo->exec("DELETE FROM subdiario WHERE operacion = 'ocupado'");
        $g = $this->svc->recuperar((int) $f['id']);
        $this->assertSame($f['cae'], $this->svc->obtener($g['id'])['fe_ccae']);
        $this->assertSame('grabada', $this->fila('SELECT estado FROM afip_operaciones')['estado']);
    }

    public function testSucursalNoElectronicaSigueInterna(): void
    {
        Db::pdo()->exec("UPDATE sucursales SET fac_electronica = 0 WHERE codigo = 99");
        $h = $this->svc->obtener($this->emitir($this->pedido())['id']);
        $this->assertSame(['', 0, 1], [$h['fe_ccae'], (int) $h['electronica'], (int) $h['es_prueba']]);
    }

    public function testImpresionLlevaCaeQrYAvisoDePrueba(): void
    {
        $id = $this->emitir($this->pedido())['id'];
        $d = ComprobanteImpreso::datos($this->svc->obtener($id), $this->svc->emisor());
        $this->assertNotNull($d['qr'], 'QR de ARCA');
        $this->assertStringContainsString('https://www.afip.gob.ar/fe/qr/?p=', $d['qr']);
        $this->assertStringContainsString('SIMULADO', $d['aviso_prueba']);
        $pdf = ComprobantePdf::generar($d);
        $this->assertStringStartsWith('%PDF', $pdf);
    }

    public function testDiagnosticoSimuladoPasaTodo(): void
    {
        foreach (Arca::diagnostico() as $p) {
            $this->assertTrue($p['ok'], $p['paso'] . ': ' . $p['detalle']);
        }
    }
}
