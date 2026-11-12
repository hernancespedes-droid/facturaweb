<?php
declare(strict_types=1);

namespace Tests;

use App\Core\Db;
use App\Modules\Agente\ApiClaves;
use App\Modules\Agente\ExportadorVfp;
use App\Modules\Stock\StockService;

/** Web → VFP: archivo de intercambio con lo emitido en la web, confirmación y rechazos. */
final class ExportadorVfpTest extends ComprobantesTestCase
{
    private StockService $stock;

    protected function setUp(): void
    {
        parent::setUp();
        Db::pdo()->exec('DELETE FROM stock_movimientos');
        Db::pdo()->exec('DELETE FROM stock_saldos');
        Db::pdo()->exec("DELETE FROM api_claves WHERE nombre LIKE 'T %'");
        Db::pdo()->exec("UPDATE configuracion SET valor = '1' WHERE clave = 'stock.controlar'");
        \App\Modules\Configuracion\Configuracion::reset();
        $this->stock = new StockService();
    }

    protected function tearDown(): void
    {
        Db::pdo()->exec('DELETE FROM stock_movimientos');
        Db::pdo()->exec('DELETE FROM stock_saldos');
        Db::pdo()->exec("DELETE FROM api_claves WHERE nombre LIKE 'T %'");
        Db::pdo()->exec("UPDATE configuracion SET valor = '0' WHERE clave = 'stock.controlar'");
        \App\Modules\Configuracion\Configuracion::reset();
        parent::tearDown();
    }

    /** Emite una factura y la deja "con validez fiscal" (en las pruebas el modo es interno: es_prueba = 1). */
    private function facturaReal(array $extra = []): int
    {
        $fc = $this->emitir($this->pedido($extra));
        Db::pdo()->prepare('UPDATE subdiario SET es_prueba = 0 WHERE fe_clave = ?')->execute([$fc['id']]);
        return $fc['id'];
    }

    public function testExportaElComprobanteConSusHijasYElStock(): void
    {
        $this->stock->cargar('INGRESO', 'T-ART', $this->stock->depositoPrincipal(), '10', 'unidades', 'stock inicial', null);
        $id = $this->facturaReal(['observaciones' => "con | barra = igual\ny salto"]);
        $p = ExportadorVfp::pendientes();
        $this->assertSame(1, $p['comprobantes']);
        $this->assertSame(2, $p['movimientos'], 'el ingreso suelto y el de la factura');
        $t = $p['texto'];
        $this->assertStringStartsWith('#FACTURAWEB|1|' . $p['lote'], $t);
        foreach (['S', 'I', 'E', 'J', 'T'] as $r) {
            $this->assertStringContainsString("\n{$r}|{$id}|", "\n" . $t, "hay registros {$r}");
        }
        $this->assertStringContainsString('M|', $t);
        $this->assertStringContainsString('subdiario_id=' . $id, $t, 'el movimiento de la factura cuelga de ella');
        $this->assertStringContainsString('subdiario_id=0', $t, 'el ingreso no es de un comprobante');
        $this->assertStringContainsString('cantidad=-2', $t);
        $this->assertStringNotContainsString('fe_clave=', $t, 'el número interno lo asigna el VFP');
        $this->assertStringNotContainsString('es_prueba', $t);
        // los caracteres del formato viajan escapados: cada registro es una línea
        $this->assertStringContainsString('con %7C barra %3D igual%0Ay salto', $t);
        foreach (explode("\n", trim($t)) as $linea) {
            $this->assertTrue(preg_match('/^(#FACTURAWEB|[SIEJTM])\|/', $linea) === 1, $linea);
        }
    }

    public function testLosComprobantesDePruebaYLosDelVfpNoViajan(): void
    {
        $this->emitir($this->pedido()); // es_prueba = 1 (modo interno)
        $id = $this->facturaReal();
        Db::pdo()->exec("INSERT INTO subdiario (fecha, tipo, letra, sucursal, prefijo, comprobante, cliente, canal, es_prueba) VALUES ('2020-01-01', 'FC', 'A', 1, 4, 5, 990001, 'm', 0)");
        $p = ExportadorVfp::pendientes();
        $this->assertSame(1, $p['comprobantes']);
        $this->assertStringContainsString("S|{$id}|", $p['texto']);
    }

    public function testConfirmarRechazarYReintentar(): void
    {
        $a = $this->facturaReal();
        $b = $this->facturaReal();
        $r = ExportadorVfp::confirmar("OK|S|{$a}\nERR|S|{$b}|No%20existe%20la%20sucursal%207\nbasura\nOK|S|abc\n");
        $this->assertSame(['confirmados' => 1, 'errores' => 1], $r);
        $this->assertSame(0, ExportadorVfp::pendientes()['comprobantes'], 'uno grabado y el otro rechazado: ninguno se reenvía');
        $e = ExportadorVfp::estado();
        $this->assertSame(1, $e['con_error']);
        $this->assertStringContainsString('No existe la sucursal 7', (string) $e['errores'][0]['vfp_error']);
        $this->assertSame(1, ExportadorVfp::reintentar());
        $p = ExportadorVfp::pendientes();
        $this->assertSame(1, $p['comprobantes']);
        $this->assertStringContainsString("S|{$b}|", $p['texto']);
        ExportadorVfp::confirmar("OK|S|{$b}");
        $this->assertSame(0, ExportadorVfp::estado()['pendientes']);
        // lo ya grabado no se pisa con un error tardío
        ExportadorVfp::confirmar("ERR|S|{$b}|tarde");
        $this->assertSame(0, ExportadorVfp::estado()['con_error']);
    }

    public function testConfirmarElComprobanteMarcaSusMovimientosDeStock(): void
    {
        $this->stock->cargar('INGRESO', 'T-ART', $this->stock->depositoPrincipal(), '10', 'unidades', 'inicial', null);
        $id = $this->facturaReal();
        ExportadorVfp::confirmar("OK|S|{$id}");
        $pend = (int) Db::pdo()->query("SELECT COUNT(*) FROM stock_movimientos WHERE vfp_enviado IS NULL AND subdiario_id IS NOT NULL")->fetchColumn();
        $this->assertSame(0, $pend);
        $suelto = (int) Db::pdo()->query('SELECT id FROM stock_movimientos WHERE subdiario_id IS NULL')->fetchColumn();
        ExportadorVfp::confirmar("OK|M|{$suelto}");
        $this->assertSame(0, ExportadorVfp::pendientes()['movimientos']);
    }

    public function testElDepositoSeGrabaEnLaSucursalDelVfp(): void
    {
        Db::pdo()->exec('UPDATE depositos SET sucursal_vfp = 3 WHERE principal = 1');
        $this->stock->cargar('INGRESO', 'T-ART', $this->stock->depositoPrincipal(), '5', 'unidades', 'x', null);
        $this->assertStringContainsString('sucursal=3', ExportadorVfp::pendientes()['texto']);
        Db::pdo()->exec('UPDATE depositos SET sucursal_vfp = 1 WHERE principal = 1');
    }

    public function testGuardarDepositoConSucursalDelVfp(): void
    {
        $r = $this->stock->guardarDeposito(null, 'TVFP', 'Deposito VFP', false, true, 5);
        $this->assertTrue($r['ok']);
        $this->assertSame(5, (int) $this->fila("SELECT sucursal_vfp FROM depositos WHERE codigo = 'TVFP'")['sucursal_vfp']);
        $this->assertFalse($this->stock->guardarDeposito(null, 'TVFQ', 'Otro', false, true, 0)['ok'], 'la sucursal del VFP va de 1 a 99');
        Db::pdo()->exec("DELETE FROM depositos WHERE codigo LIKE 'TVF%'");
    }

    public function testLaPantallaDelAgenteMuestraLaColaYElReintento(): void
    {
        $id = $this->facturaReal();
        ExportadorVfp::confirmar("ERR|S|{$id}|Falta la sucursal 7");
        $this->loginComo($this->crearUsuario('t_adm_vfp', 'admin'));
        $r = $this->get('/agente');
        $this->assertSame(200, $r->status);
        $this->assertStringContainsString('Web → VFP', $r->body);
        $this->assertStringContainsString('Falta la sucursal 7', $r->body);
        $re = $this->post('/agente/vfp/reintentar', []);
        $this->assertSame(302, $re->status);
        $this->assertSame(1, ExportadorVfp::pendientes()['comprobantes']);
        $this->loginComo($this->crearUsuario('t_op_vfp', 'operador'));
        $this->assertSame(403, $this->post('/agente/vfp/reintentar', [])->status, 'el operador no reintenta');
    }

    public function testDescargarElLoteNoMarcaNada(): void
    {
        $id = $this->facturaReal();
        $this->loginComo($this->crearUsuario('t_op_vfp', 'operador'));
        $this->assertSame(403, $this->get('/agente/vfp/lote')->status, 'el operador no baja lotes');
        $this->loginComo($this->crearUsuario('t_adm_vfp', 'admin'));
        $r = $this->get('/agente/vfp/lote');
        $this->assertSame(200, $r->status);
        $this->assertStringContainsString('attachment', $r->headers['Content-Disposition']);
        $this->assertStringContainsString("S|{$id}|", $r->body);
        $this->assertSame(1, ExportadorVfp::estado()['pendientes'], 'sigue pendiente');
    }

    public function testApiPendientesYConfirmarPorHttp(): void
    {
        $id = $this->facturaReal();
        $this->assertSame(401, $this->get('/api/v1/vfp/pendientes')->status, 'sin clave');
        $clave = ApiClaves::crear('T PC web-vfp', null);
        $r = $this->conClave($clave, 'GET', '/api/v1/vfp/pendientes');
        $this->assertSame(200, $r->status, $r->body);
        $d = json_decode($r->body, true);
        $this->assertSame(1, $d['comprobantes']);
        $this->assertStringContainsString("S|{$id}|", $d['archivo']);
        $r = $this->conClave($clave, 'POST', '/api/v1/vfp/confirmar', [], ['resultado' => "OK|S|{$id}\n"]);
        $this->assertSame(1, json_decode($r->body, true)['confirmados']);
        $vacio = json_decode($this->conClave($clave, 'GET', '/api/v1/vfp/pendientes')->body, true);
        $this->assertSame(['comprobantes' => 0, 'archivo' => ''], ['comprobantes' => $vacio['comprobantes'], 'archivo' => $vacio['archivo']]);
    }

    private function conClave(string $clave, string $metodo, string $ruta, array $files = [], array $post = []): \App\Http\Response
    {
        $req = new \App\Http\Request($metodo, $ruta, [], $post, ['HTTP_AUTHORIZATION' => 'Bearer ' . $clave, 'REMOTE_ADDR' => '10.0.0.1'], $files);
        return $this->app->handle($req);
    }
}
