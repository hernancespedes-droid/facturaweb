<?php
declare(strict_types=1);

namespace Tests;

use App\Core\Db;
use App\Modules\Comprobantes\PresupuestoYaFacturado;
use App\Modules\CuentaCorriente\CuentaCorrienteService;
use App\Modules\Listados\IvaVentas;
use App\Modules\Listados\VentasDiarias;
use App\Modules\Stock\StockService;

/**
 * Presupuesto (PR) y su contrapartida (NX): letra X, sin ARCA ni subdiario de IVA; descuenta stock; se cobra o va a cuenta corriente;
 * genera UNA factura (solo al subdiario); entra a la cuenta corriente como una factura; sale en Ventas diarias.
 */
final class PresupuestoTest extends ComprobantesTestCase
{
    private StockService $stock;

    protected function setUp(): void
    {
        parent::setUp();
        Db::pdo()->exec('DELETE FROM stock_movimientos');
        Db::pdo()->exec('DELETE FROM stock_saldos');
        Db::pdo()->exec("UPDATE configuracion SET valor = '1' WHERE clave = 'stock.controlar'");
        \App\Modules\Configuracion\Configuracion::reset();
        $this->stock = new StockService();
        $this->stock->cargar('INGRESO', 'T-ART', $this->stock->depositoPrincipal(), '10', 'unidades', 'stock inicial', null);
    }

    protected function tearDown(): void
    {
        Db::pdo()->exec('DELETE FROM stock_movimientos');
        Db::pdo()->exec('DELETE FROM stock_saldos');
        Db::pdo()->exec("UPDATE configuracion SET valor = '0' WHERE clave = 'stock.controlar'");
        \App\Modules\Configuracion\Configuracion::reset();
        parent::tearDown();
    }

    /** Presupuesto de 2 x 121 (IVA incluido) = 242, del cliente RI, a cuenta corriente salvo que se pida contado. */
    private function presupuesto(array $extra = []): array
    {
        return $this->emitir($this->pedido($extra + ['tipo' => 'PR', 'items' => [['articulo' => 'T-ART', 'descripcion' => '', 'cantidad' => '2', 'precio' => '121', 'alicuota' => '21']]]));
    }

    public function testPresupuestoNumeraConLetraXNoEsFiscalYDescuentaStock(): void
    {
        $e = $this->presupuesto(['forma_pago' => '1', 'fpago' => 'EF']);
        $h = $this->svc->obtener($e['id']);
        $this->assertSame(['PR', 'X', 99, 1, 0], [$h['tipo'], $h['letra'], (int) $h['sucursal'], (int) $h['comprobante'], (int) $h['es_prueba']], 'PR X número 1, no es de prueba');
        $this->assertSame('', (string) $h['fe_ccae'] === '.' ? '' : (string) $h['fe_ccae'], 'sin CAE');
        $this->assertSame('242.00', $h['neto']);
        $this->assertSame(1, (int) $this->fila('SELECT ultimo_presupuesto AS n FROM sucursales WHERE codigo = 99')['n']);
        $this->assertSame(8.0, $this->stock->saldo('T-ART'), 'descuenta stock');
        $this->assertSame('PR', $this->fila("SELECT origen FROM stock_movimientos WHERE subdiario_id = ?", [$e['id']])['origen']);
        $caja = $this->fila('SELECT p_fpago, p_importe FROM caja WHERE subdiario_id = ?', [$e['id']]);
        $this->assertSame(['EF', '242.00'], [$caja['p_fpago'], $caja['p_importe']], 'contado: entra a caja');
        $this->assertSame(0, (int) Db::pdo()->query('SELECT COUNT(*) FROM ctact')->fetchColumn(), 'contado: no va a cuenta corriente');
        $this->assertSame(2, $this->presupuesto()['numero'], 'el siguiente es el 2 (numeración propia)');
        $this->assertSame(0, (int) $this->fila('SELECT ultima_factura_a AS n FROM sucursales WHERE codigo = 99')['n'], 'no toca la numeración de las facturas');
    }

    public function testPresupuestoACuentaCorrienteEsUnDebitoComoUnaFactura(): void
    {
        $e = $this->presupuesto();
        $ct = $this->fila('SELECT tico, tipo, saldo FROM ctact WHERE subdiario_id = ?', [$e['id']]);
        $this->assertSame([4, 'PR', '242.00'], [(int) $ct['tico'], $ct['tipo'], $ct['saldo']]);
        $this->assertSame(242.0, $this->svc->deudaCliente(990001));
        $cc = new CuentaCorrienteService();
        $pend = $cc->pendientes(990001);
        $this->assertCount(1, $pend['debitos'], 'aparece entre los débitos para cobrar e imputar');
        $this->assertSame('PR', $pend['debitos'][0]['tipo']);
    }

    public function testNoVaAlSubdiarioDeIvaNiAlPortalNiAlVfp(): void
    {
        $e = $this->presupuesto();
        $desde = date('d/m/Y');
        $r = IvaVentas::listar(['desde' => $desde, 'hasta' => $desde, 'pruebas' => '1'], 1, 50);
        $this->assertSame(0, $r['total'], 'IVA ventas no lo ve');
        $this->assertSame(0, IvaVentas::listar(['desde' => $desde, 'hasta' => $desde, 'pruebas' => '1', 'tipo' => 'PR'], 1, 50)['total']);
        $this->assertCount(0, (new \App\Modules\Portal\PortalService())->comprobantes(990001, null), 'el portal no lo muestra');
        $pend = (int) Db::pdo()->query("SELECT COUNT(*) FROM subdiario WHERE canal = 'W' AND es_prueba = 0 AND tipo NOT IN ('PR', 'NX') AND vfp_enviado IS NULL")->fetchColumn();
        $this->assertSame(0, $pend, 'no hay nada fiscal pendiente de mandar al VFP');
        $this->assertTrue($e['id'] > 0);
    }

    public function testDeUnPresupuestoSaleUnaSolaFacturaSoloAlSubdiario(): void
    {
        $pr = $this->presupuesto();
        $saldoStock = $this->stock->saldo('T-ART');
        $this->assertSame([], $this->svc->armar(['tipo' => 'FC', 'presupuesto' => (string) $pr['id'], 'cliente' => '990001'])['errores']);
        $r = $this->svc->armar(['tipo' => 'FC', 'presupuesto' => (string) $pr['id']]);
        $cb = $r['cbte'];
        $this->assertSame('A', $cb['letra'], 'cliente RI: factura A');
        $this->assertSame(242.0, $cb['calculo']['total'], 'el total es el del presupuesto (el precio con IVA se pasa a neto)');
        $this->assertSame(100.0, (float) $cb['calculo']['items'][0]['precio'], 'la factura A va con precio neto');
        $this->assertTrue($cb['solo_subdiario']);
        $fc = $this->svc->emitir($cb, bin2hex(random_bytes(16)), 0);
        $h = $this->svc->obtener($fc['id']);
        $this->assertSame(['FC', 1, (int) $pr['id']], [$h['tipo'], (int) $h['solo_subdiario'], (int) $h['presupuesto_id']]);
        $this->assertSame(0, (int) $this->fila('SELECT COUNT(*) AS n FROM caja WHERE subdiario_id = ?', [$fc['id']])['n'], 'sin caja');
        $this->assertSame(0, (int) $this->fila('SELECT COUNT(*) AS n FROM ctact WHERE subdiario_id = ?', [$fc['id']])['n'], 'sin cuenta corriente');
        $this->assertSame($saldoStock, $this->stock->saldo('T-ART'), 'sin stock: ya lo movió el presupuesto');
        $this->assertSame(1, (int) $this->fila('SELECT COUNT(*) AS n FROM iva WHERE subdiario_id = ?', [$fc['id']])['n'], 'sí va al subdiario de IVA');
        $this->assertSame(1, IvaVentas::listar(['desde' => date('d/m/Y'), 'hasta' => date('d/m/Y'), 'pruebas' => '1'], 1, 50)['total']);
        $this->assertSame(242.0, $this->svc->deudaCliente(990001), 'la deuda sigue siendo la del presupuesto (no se duplica)');

        // una sola vez
        $this->assertStringContainsString('una sola vez', $this->svc->armar(['tipo' => 'FC', 'presupuesto' => (string) $pr['id']])['errores']['presupuesto']);
        try {
            $this->svc->emitir($cb, bin2hex(random_bytes(16)), 0);
            $this->assertTrue(false, 'no debía emitir una segunda factura');
        } catch (PresupuestoYaFacturado) {
            $this->assertSame(1, (int) Db::pdo()->query("SELECT COUNT(*) FROM subdiario WHERE tipo = 'FC'")->fetchColumn());
        }
        // se enlazan en las dos direcciones
        $this->assertSame($fc['id'], (int) $this->svc->obtener($pr['id'])['factura_generada']['fe_clave']);
        $this->assertSame($pr['id'], (int) $h['presupuesto_id']);
        $this->assertSame($pr['id'], (int) $this->svc->obtener($fc['id'])['presupuesto_origen']['fe_clave']);

        // la nota de crédito de esa factura también va solo al subdiario
        $nc = $this->emitir(['tipo' => 'NC', 'cliente' => '990001', 'asociado' => (string) $fc['id'], 'forma_pago' => '2',
            'items' => [['descripcion' => 'Devolución', 'cantidad' => '1', 'precio' => '100', 'alicuota' => '21']]]);
        $this->assertSame(1, (int) $this->svc->obtener($nc['id'])['solo_subdiario']);
        $this->assertSame(0, (int) $this->fila('SELECT COUNT(*) AS n FROM ctact WHERE subdiario_id = ?', [$nc['id']])['n']);
    }

    public function testFacturaBDesdePresupuestoConsumidorFinal(): void
    {
        $pr = $this->presupuesto(['cliente' => '990002', 'forma_pago' => '1', 'fpago' => 'EF']);
        $cb = $this->svc->armar(['tipo' => 'FC', 'presupuesto' => (string) $pr['id']])['cbte'];
        $this->assertSame(['B', 242.0, 121.0], [$cb['letra'], $cb['calculo']['total'], (float) $cb['calculo']['items'][0]['precio']]);
    }

    public function testContrapartidaReponeStockDejaSaldoAFavorYNoSePuedeFacturarDespues(): void
    {
        $pr = $this->presupuesto();
        $this->assertSame(8.0, $this->stock->saldo('T-ART'));
        $nx = $this->emitir(['tipo' => 'NX', 'cliente' => '990001', 'asociado' => (string) $pr['id'], 'forma_pago' => '2',
            'items' => [['articulo' => 'T-ART', 'descripcion' => '', 'cantidad' => '2', 'precio' => '121', 'alicuota' => '21']]]);
        $h = $this->svc->obtener($nx['id']);
        $this->assertSame(['NX', 'X', 1, 'PR'], [$h['tipo'], $h['letra'], (int) $h['comprobante'], $h['fe_tipocbte_asoc']]);
        $this->assertSame(10.0, $this->stock->saldo('T-ART'), 'repone');
        $ct = $this->fila('SELECT tico, saldo FROM ctact WHERE subdiario_id = ?', [$nx['id']]);
        $this->assertSame([10, '242.00'], [(int) $ct['tico'], $ct['saldo']], 'saldo a favor');
        $this->assertSame(0, (int) $this->fila('SELECT COUNT(*) AS n FROM caja WHERE subdiario_id = ?', [$nx['id']])['n']);
        $this->assertSame(0.0, $this->svc->porAcreditar($this->svc->obtener($pr['id'])));
        $this->assertCount(1, $this->svc->obtener($pr['id'])['notas']);
        // no supera el presupuesto, no se asocia a una factura, y el presupuesto ya no se factura
        $mas = $this->svc->armar(['tipo' => 'NX', 'cliente' => '990001', 'asociado' => (string) $pr['id'], 'forma_pago' => '2',
            'items' => [['descripcion' => 'x', 'cantidad' => '1', 'precio' => '10', 'alicuota' => '21']]]);
        $this->assertStringContainsString('supera', $mas['errores']['items']);
        $fc = $this->emitir($this->pedido());
        $this->assertStringContainsString('Solo se asocian', $this->svc->armar(['tipo' => 'NC', 'cliente' => '990001', 'asociado' => (string) $nx['id'], 'forma_pago' => '2',
            'items' => [['descripcion' => 'x', 'cantidad' => '1', 'precio' => '10', 'alicuota' => '21']]])['errores']['asociado']);
        $this->assertStringContainsString('solo corrige presupuestos', $this->svc->armar(['tipo' => 'NX', 'cliente' => '990001', 'asociado' => (string) $fc['id'], 'forma_pago' => '2',
            'items' => [['descripcion' => 'x', 'cantidad' => '1', 'precio' => '10', 'alicuota' => '21']]])['errores']['asociado']);
        $this->assertStringContainsString('nota de crédito X', $this->svc->armar(['tipo' => 'FC', 'presupuesto' => (string) $pr['id']])['errores']['presupuesto']);
    }

    public function testElPresupuestoYSuContrapartidaSeImputanComoFacturas(): void
    {
        $pr = $this->presupuesto();
        $nx = $this->emitir(['tipo' => 'NX', 'cliente' => '990001', 'asociado' => (string) $pr['id'], 'forma_pago' => '2',
            'items' => [['descripcion' => 'Contrapartida', 'cantidad' => '1', 'precio' => '121', 'alicuota' => '21']]]); // 121 de 242
        $cc = new CuentaCorrienteService();
        $this->assertSame(121.0, $cc->saldo(990001), 'saldo = 242 − 121');
        $pend = $cc->pendientes(990001);
        $db = (int) $pend['debitos'][0]['fe_clave'];
        $cr = (int) $pend['creditos'][0]['fe_clave'];
        $r = $cc->imputarManual(990001, [$cr => '121'], [$db => '121'], 0);
        $this->assertTrue($r['ok'], (string) ($r['error'] ?? ''));
        $this->assertSame(121.0, (float) $this->fila('SELECT saldo FROM ctact WHERE fe_clave = ?', [$db])['saldo'], 'el presupuesto queda con 121');
        $this->assertSame(0, (int) Db::pdo()->query("SELECT COUNT(*) FROM ctact WHERE tico >= 7 AND saldo > 0")->fetchColumn(), 'la contrapartida quedó aplicada');
        $this->assertTrue($nx['id'] > 0);
    }

    public function testVentasDiariasMezclaPresupuestosYFacturasSinRepetirLoFacturado(): void
    {
        $pr1 = $this->presupuesto(['forma_pago' => '1', 'fpago' => 'EF']);    // 242 contado
        $pr2 = $this->presupuesto();                                         // 242 cuenta corriente
        $this->emitir($this->pedido());                                      // factura 242 a cuenta corriente
        $this->svc->emitir($this->svc->armar(['tipo' => 'FC', 'presupuesto' => (string) $pr1['id']])['cbte'], bin2hex(random_bytes(16)), 0); // solo subdiario
        $this->emitir(['tipo' => 'NX', 'cliente' => '990001', 'asociado' => (string) $pr2['id'], 'forma_pago' => '2',
            'items' => [['descripcion' => 'Contrapartida', 'cantidad' => '1', 'precio' => '121', 'alicuota' => '21']]]); // −121
        $hoy = date('d/m/Y');
        $r = VentasDiarias::listar(['desde' => $hoy, 'hasta' => $hoy], 1, 50);
        $tipos = array_map(static fn (array $x) => $x['tipo'], $r['filas']);
        sort($tipos);
        $this->assertSame(['FC', 'NX', 'PR', 'PR'], $tipos, 'la factura de un presupuesto no se lista');
        $this->assertSame(242.0 * 3 - 121.0, $r['totales']['total']);
        $this->assertSame(242.0, $r['totales']['contado']);
        $this->assertSame(242.0 * 2 - 121.0, $r['totales']['cuenta']);
        $this->assertSame(242.0 * 2 - 121.0, $r['totales']['presupuestos']);
        $solo = VentasDiarias::listar(['desde' => $hoy, 'hasta' => $hoy, 'tipo' => 'PR'], 1, 50);
        $this->assertSame(2, $solo['total']);
        $this->assertSame(1, VentasDiarias::listar(['desde' => $hoy, 'hasta' => $hoy, 'forma' => 'contado'], 1, 50)['total']);
        $this->assertSame(0, VentasDiarias::listar(['desde' => '01/01/2000', 'hasta' => '02/01/2000'], 1, 50)['total']);
    }

    public function testVentasDiariasEnPantallaYExcel(): void
    {
        $this->presupuesto(['forma_pago' => '1', 'fpago' => 'EF']);
        $this->loginComo($this->crearUsuario('t_adm_pr', 'admin'));
        $r = $this->get('/listados/ventas-diarias');
        $this->assertSame(200, $r->status);
        $this->assertStringContainsString('Ventas diarias', $r->body);
        $this->assertStringContainsString('Presupuesto X 0009-00000001', $r->body);
        $this->assertStringContainsString('Contado · EFECTIVO', $r->body);
        $this->assertSame(200, $this->get('/listados/ventas-diarias', ['excel' => 'grilla'])->status, 'Excel genérico de la grilla');
    }

    public function testPantallasImpresionYPermisos(): void
    {
        $pr = $this->presupuesto();
        $this->loginComo($this->crearUsuario('t_op_pr', 'operador'));
        $this->assertSame(200, $this->get('/comprobantes/nuevo', ['tipo' => 'PR'])->status);
        $this->assertStringContainsString('Presupuesto', $this->get('/comprobantes/nuevo', ['tipo' => 'PR'])->body);
        $ver = $this->get('/comprobantes/' . $pr['id']);
        $this->assertStringContainsString('Generar factura', $ver->body);
        $this->assertStringContainsString('WhatsApp', $ver->body);
        $pdf = $this->get('/comprobantes/' . $pr['id'] . '/pdf');
        $this->assertSame(200, $pdf->status);
        $this->assertStringContainsString('%PDF', substr($pdf->body, 0, 8));
        $imp = $this->get('/comprobantes/' . $pr['id'] . '/imprimir');
        $this->assertStringContainsString('PRESUPUESTO', $imp->body);
        $this->assertStringContainsString('NO VÁLIDO COMO FACTURA', $imp->body);
        $this->assertSame(403, $this->get('/listados/ventas-diarias')->status, 'el operador no tiene listados');
        $this->assertSame(200, $this->get('/comprobantes/' . $pr['id'] . '/facturar')->status, 'pantalla de revisión de la factura');
        $this->assertSame(200, $this->get('/comprobantes/nuevo', ['tipo' => 'NX', 'desde' => (string) $pr['id']])->status);
        // sin permiso de emitir: nada
        Db::pdo()->exec("DELETE rp FROM rol_permisos rp JOIN permisos p ON p.id = rp.permiso_id JOIN roles r ON r.id = rp.rol_id WHERE r.codigo = 'operador' AND p.codigo = 'comprobantes.emitir'");
        \App\Modules\Auth\Auth::reset();
        try {
            $this->assertSame(403, $this->get('/comprobantes/' . $pr['id'] . '/facturar')->status);
            $this->assertSame(403, $this->get('/comprobantes/nuevo', ['tipo' => 'PR'])->status);
            $this->assertSame(200, $this->get('/comprobantes/' . $pr['id'] . '/pdf')->status, 'ver e imprimir solo pide comprobantes.ver');
        } finally {
            Db::pdo()->exec("INSERT IGNORE INTO rol_permisos (rol_id, permiso_id) SELECT r.id, p.id FROM roles r JOIN permisos p ON p.codigo = 'comprobantes.emitir' WHERE r.codigo = 'operador'");
        }
    }
}
