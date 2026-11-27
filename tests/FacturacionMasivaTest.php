<?php
declare(strict_types=1);

namespace Tests;

use App\App;
use App\Core\Config;
use App\Core\Db;
use App\Modules\Arca\Arca;
use App\Modules\FacturacionMasiva\Planilla;
use App\Modules\FacturacionMasiva\PreFacturasService;

/** Facturación masiva: planilla → pre-facturas (sin tocar nada) → modificar / eliminar → aprobar y facturar con ARCA simulado. */
final class FacturacionMasivaTest extends ComprobantesTestCase
{
    private const PERM = ['credito' => false, 'stock' => false];

    protected function setUp(): void
    {
        parent::setUp();
        $cfg = require App::root() . '/config/config.php';
        $cfg['afip'] = ['modo' => 'simulado'];
        Config::load($cfg);
        Arca::reiniciar();
        $pdo = Db::pdo();
        $pdo->exec('DELETE FROM afip_operaciones');
        $pdo->exec('DELETE FROM prefacturas');
        $pdo->exec('DELETE FROM prefacturas_lotes');
        $pdo->exec('UPDATE sucursales SET fac_electronica = 1 WHERE codigo = 99');
        $pdo->exec("UPDATE clientes SET emailfc = 'cliente@ejemplo.com' WHERE codigo = 990001");
    }

    protected function tearDown(): void
    {
        $pdo = Db::pdo();
        $pdo->exec('DELETE FROM afip_operaciones');
        $pdo->exec('DELETE FROM prefacturas');
        $pdo->exec('DELETE FROM prefacturas_lotes');
        $pdo->exec("UPDATE configuracion SET valor = '0' WHERE clave = 'stock.controlar'");
        \App\Modules\Configuracion\Configuracion::reset();
        $pdo->exec("DELETE FROM usuarios WHERE rol_id IN (SELECT id FROM roles WHERE codigo LIKE 't\\_%')");
        $pdo->exec("DELETE FROM roles WHERE codigo LIKE 't\\_%'");
        Config::load(require App::root() . '/config/config.php');
        Arca::reiniciar();
        parent::tearDown();
    }

    /** Planilla de prueba: una factura A de dos renglones (referencia R1), una B en efectivo y un cliente que no existe. */
    private function hoja(): array
    {
        return ['titulos' => ['Ref.', 'CUIT', 'Descripción', 'Cant.', 'Precio', 'IVA', 'Forma de pago'], 'filas' => [
            ['n' => 2, 'celdas' => ['R1', '30-70880558-7', 'Abono', '1', '121', '21', '']],
            ['n' => 3, 'celdas' => ['R1', '', 'Flete', '2', '60,50', '21', '']],
            ['n' => 4, 'celdas' => ['', '990002', 'Venta mostrador', '', '242', '', 'Efectivo']],
            ['n' => 5, 'celdas' => ['', 'NO EXISTE SA', 'Algo', '1', '10', '', '']],
        ]];
    }

    private function cargar(): array
    {
        $svc = new PreFacturasService();
        $r = $svc->cargarPlanilla($this->hoja(), 'prueba.xlsx', ['cobro' => 'CC', 'sucursal' => '99'], 0, self::PERM);
        $ids = array_map('intval', Db::pdo()->query('SELECT id FROM prefacturas ORDER BY id')->fetchAll(\PDO::FETCH_COLUMN));
        return [$svc, $r, $ids];
    }

    private function cuenta(string $tabla): int
    {
        return (int) Db::pdo()->query("SELECT COUNT(*) FROM `{$tabla}`")->fetchColumn();
    }

    public function testPlanillaReconoceColumnasYAgrupaPorReferencia(): void
    {
        $col = Planilla::columnas(['Ref.', 'CUIT', 'Descripción', 'Cant.', 'Precio', 'IVA', 'Forma de pago', 'Precio neto']);
        $this->assertSame(['referencia' => 0, 'cliente' => 1, 'descripcion' => 2, 'cantidad' => 3, 'precio' => 4, 'precio_neto' => 7, 'alicuota' => 5, 'forma_pago' => 6], $col);
        $l = (new Planilla())->leer($this->hoja(), ['cobro' => 'CC', 'sucursal' => '99']);
        $f = $l['facturas'];
        $this->assertCount(3, $f);
        // A (cliente RI): el precio final se pasa a neto; dos renglones; sin forma de pago → cuenta corriente; el mail sale de la ficha
        $this->assertSame([990001, [2, 3], '2', 'cliente@ejemplo.com'], [$f[0]['cliente'], $f[0]['filas'], $f[0]['datos']['forma_pago'], $f[0]['datos']['email']]);
        $this->assertSame(['100', '50'], array_column($f[0]['datos']['items'], 'precio'));
        $this->assertSame('1', $f[1]['datos']['items'][0]['cantidad'], 'sin cantidad va 1');
        // B (consumidor final): el precio queda con IVA; en efectivo
        $this->assertSame(['242', 'EF'], [$f[1]['datos']['items'][0]['precio'], $f[1]['datos']['pagos'][0]['fpago']]);
        $this->assertSame([], $f[1]['errores']);
        $this->assertStringContainsString('NO EXISTE SA', $f[2]['errores'][0]);
        try {
            (new Planilla())->leer(['titulos' => ['Descripción', 'Precio'], 'filas' => [['n' => 2, 'celdas' => ['x', '1']]]], []);
            $this->fail('sin columna de cliente no sirve');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('Cliente', $e->getMessage());
        }
    }

    public function testCargarNoTocaNadaYAprobarFacturaConArcaSinRepetir(): void
    {
        [$svc, $r, $ids] = $this->cargar();
        $this->assertSame(['prefacturas' => 3, 'con_error' => 1], ['prefacturas' => $r['prefacturas'], 'con_error' => $r['con_error']]);
        foreach (['subdiario', 'ctact', 'caja', 'estadistica', 'afip_operaciones'] as $t) {
            $this->assertSame(0, $this->cuenta($t), "la pre-factura no toca {$t}");
        }
        $a = $svc->obtener($ids[0]);
        $this->assertSame(['A', '242.00', null], [$a['letra'], $a['total'], $a['errores']]);

        $orden = $svc->ordenar($ids);
        $res = [];
        foreach ($orden as $id) {
            $res[$id] = $svc->procesar($id, 0, self::PERM);
        }
        $this->assertTrue($res[$ids[0]]['ok'] && $res[$ids[1]]['ok'], 'las dos válidas se facturan');
        $this->assertFalse($res[$ids[2]]['ok'], 'la del cliente inexistente queda pendiente');
        $this->assertSame('pendiente', $svc->obtener($ids[2])['estado']);

        $fa = $this->fila('SELECT * FROM subdiario WHERE fe_clave = ?', [$res[$ids[0]]['comprobante_id']]);
        $this->assertSame(['FC', 'A', 2, '242.00'], [$fa['tipo'], $fa['letra'], (int) $fa['forma_pago'], $fa['neto']]);
        $this->assertTrue(str_starts_with((string) $fa['fe_ccae'], '99'), 'con CAE (simulado)');
        $this->assertSame(1, (int) $this->fila('SELECT COUNT(*) n FROM ctact WHERE subdiario_id = ?', [$fa['fe_clave']])['n'], 'cuenta corriente');
        $fb = $this->fila('SELECT * FROM subdiario WHERE fe_clave = ?', [$res[$ids[1]]['comprobante_id']]);
        $this->assertSame(['B', 1, '242.00'], [$fb['letra'], (int) $fb['forma_pago'], $fb['neto']]);
        $this->assertSame('242.00', (string) $this->fila('SELECT SUM(p_importe) s FROM caja WHERE subdiario_id = ?', [$fb['fe_clave']])['s'], 'efectivo a la caja');
        $this->assertSame(0, (int) $this->fila('SELECT COUNT(*) n FROM ctact WHERE subdiario_id = ?', [$fb['fe_clave']])['n']);
        $this->assertSame(['emitida', (int) $fa['fe_clave']], [$svc->obtener($ids[0])['estado'], (int) $svc->obtener($ids[0])['subdiario_id']]);

        // de nuevo (doble clic, otra pestaña): no factura otra vez
        $otra = $svc->procesar($ids[0], 0, self::PERM);
        $this->assertTrue($otra['ok']);
        $this->assertSame(2, $this->cuenta('subdiario'));
        // una facturada no se modifica ni se elimina
        $this->assertSame(0, $svc->eliminar([$ids[0]], 0)['eliminadas']);
        $this->assertFalse($svc->guardar($ids[0], ['cliente' => '990001'], 'CC', 0, self::PERM)['ok']);
    }

    public function testRechazoDeArcaQuedaPendienteYSeCorrigeModificando(): void
    {
        $svc = new PreFacturasService();
        $svc->cargarPlanilla(['titulos' => ['Cliente', 'Descripción', 'Precio'], 'filas' => [['n' => 2, 'celdas' => ['990002', 'Servicio', '100,13']]]], 'x.csv', ['cobro' => 'CC', 'sucursal' => '99'], 0, self::PERM);
        $id = (int) Db::pdo()->query('SELECT id FROM prefacturas')->fetchColumn();
        $r = $svc->procesar($id, 0, self::PERM);
        $this->assertFalse($r['ok']);
        $this->assertStringContainsString('ARCA no la aprobó', $r['mensaje']);
        $p = $svc->obtener($id);
        $this->assertSame('pendiente', $p['estado']);
        $this->assertNull($svc->bloqueo($p), 'lo rechazado se puede modificar');
        $this->assertSame(0, $this->cuenta('subdiario'));

        // se modifica con el formulario de la factura (renglón vacío incluido) y se factura
        $form = ['tipo' => 'FC', 'cliente' => '990002', 'sucursal' => '99', 'fecha' => date('Y-m-d'), 'concepto' => '1', 'email' => '',
            'items' => [['articulo' => '', 'descripcion' => 'Servicio', 'cantidad' => '1', 'precio' => '100', 'alicuota' => '21'], ['articulo' => '', 'descripcion' => '', 'cantidad' => '', 'precio' => '']]];
        $mal = $svc->guardar($id, ['items' => []] + $form, 'CC', 0, self::PERM);
        $this->assertFalse($mal['ok'], 'sin renglones no se guarda');
        $this->assertTrue($svc->guardar($id, $form, 'EF', 0, self::PERM)['ok']);
        $p = $svc->obtener($id);
        $this->assertSame([null, 'Efectivo', '100.00', null], [$p['fecha'], $p['cobro'], $p['total'], $p['errores']]);
        $this->assertCount(1, $p['datos']['items']);
        $r = $svc->procesar($id, 0, self::PERM);
        $this->assertTrue($r['ok'], $r['mensaje']);
        $this->assertSame(1, $this->cuenta('subdiario'));
    }

    public function testSinRespuestaDeArcaNoSeModificaNiSeElimina(): void
    {
        [$svc, , $ids] = $this->cargar();
        $p = $svc->obtener($ids[0]);
        Db::pdo()->prepare("INSERT INTO afip_operaciones (operacion, estado, entorno, tipo_afip, pto_vta, total) VALUES (?, 'pendiente', 'S', 1, 9, 242)")->execute([$p['operacion']]);
        $this->assertStringContainsString('Sistema → ARCA', (string) $svc->bloqueo($p));
        $this->assertSame(0, $svc->eliminar([$ids[0]], 0)['eliminadas']);
        $r = $svc->procesar($ids[0], 0, self::PERM);
        $this->assertFalse($r['ok'], 'no se vuelve a pedir el CAE');
        $this->assertSame(0, $this->cuenta('subdiario'));
        // eliminar las otras
        $this->assertSame(2, $svc->eliminar([$ids[1], $ids[2]], 0)['eliminadas']);
        $this->assertFalse($svc->procesar($ids[1], 0, self::PERM)['ok'], 'una eliminada no se factura');
    }

    public function testStockQueNoAlcanzaLoAutorizaQuienPuede(): void
    {
        Db::pdo()->exec("UPDATE configuracion SET valor = '1' WHERE clave = 'stock.controlar'");
        \App\Modules\Configuracion\Configuracion::reset();
        $svc = new PreFacturasService();
        $svc->cargarPlanilla(['titulos' => ['Cliente', 'Artículo', 'Cantidad'], 'filas' => [['n' => 2, 'celdas' => ['990002', 'T-ART', '3']]]], 'x.csv', ['cobro' => 'CC', 'sucursal' => '99'], 0, self::PERM);
        $p = (array) Db::pdo()->query('SELECT * FROM prefacturas')->fetch();
        $this->assertStringContainsString('stock', mb_strtolower((string) $p['errores']), 'sin stock y sin quien autorice');
        $this->assertSame('300.00', $p['total'], 'el total se muestra aunque falte la autorización');
        $item = json_decode((string) $p['datos'], true)['items'][0];
        $this->assertSame([100.0, '3'], [\App\Core\Valida::decimal((string) $item['precio']), $item['cantidad']], 'el precio sale de la ficha del artículo');
        $this->assertFalse($svc->procesar((int) $p['id'], 0, self::PERM)['ok']);
        $r = $svc->procesar((int) $p['id'], $this->supervisorId(), ['credito' => false, 'stock' => true]);
        $this->assertTrue($r['ok'], $r['mensaje']);
        $this->assertSame(1, (int) $this->fila('SELECT COUNT(*) n FROM stock_autorizaciones')['n'], 'queda registrada la autorización');
    }

    public function testOrdenDeFacturacionPorFecha(): void
    {
        [$svc, , $ids] = $this->cargar();
        Db::pdo()->prepare('UPDATE prefacturas SET fecha = ? WHERE id = ?')->execute([date('Y-m-d', strtotime('-2 days')), $ids[2]]);
        Db::pdo()->prepare('UPDATE prefacturas SET fecha = ? WHERE id = ?')->execute([date('Y-m-d', strtotime('+1 day')), $ids[0]]);
        $this->assertSame([$ids[2], $ids[1], $ids[0]], $svc->ordenar([$ids[0], $ids[1], $ids[2], 0]));
    }

    public function testPantallasYPermisos(): void
    {
        [, , $ids] = $this->cargar();
        $this->loginComo($this->crearUsuario('t_op', 'operador'));
        $this->assertSame(403, $this->get('/facturacion-masiva')->status, 'el operador no tiene facturación masiva');
        $this->assertSame(403, $this->post('/facturacion-masiva/procesar', ['ids' => [$ids[0]], 'json' => '1'])->status);

        $this->loginComo($this->crearUsuario('t_adm', 'admin'));
        $index = $this->get('/facturacion-masiva');
        $this->assertSame(200, $index->status);
        $this->assertStringContainsString('NO EXISTE SA', $index->body);
        $this->assertStringContainsString('Aprobar y facturar', $index->body);
        $mod = $this->get('/facturacion-masiva/' . $ids[2] . '/modificar');
        $this->assertSame(200, $mod->status);
        $this->assertStringContainsString('Guardar pre-factura', $mod->body);
        $this->assertStringContainsString('Para corregir', $mod->body);
        $this->assertStringContainsString('/facturacion-masiva/' . $ids[2] . '/modificar', $mod->body);
        $this->assertStringNotContainsString('data-reserva ', $mod->body, 'modificar no reserva stock');

        $r = $this->post('/facturacion-masiva/procesar', ['ids' => [$ids[0]], 'json' => '1']);
        $this->assertSame(200, $r->status);
        $j = json_decode($r->body, true);
        $this->assertTrue($j['resultados'][(string) $ids[0]]['ok']);
        $this->assertSame(1, $this->cuenta('subdiario'));

        $this->assertRedirect('/facturacion-masiva', $this->post('/facturacion-masiva/eliminar', ['ids' => [$ids[1]]]));
        $this->assertSame('eliminada', (new PreFacturasService())->obtener($ids[1])['estado']);
        $plantilla = $this->get('/facturacion-masiva/planilla');
        $this->assertSame(200, $plantilla->status);
        $this->assertStringStartsWith('PK', $plantilla->body);
    }
}
