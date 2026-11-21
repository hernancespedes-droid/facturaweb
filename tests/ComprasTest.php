<?php
declare(strict_types=1);

namespace Tests;

use App\Core\Db;
use App\Modules\Caja\CajaService;
use App\Modules\Compras\CentrosService;
use App\Modules\Compras\ComprasListados;
use App\Modules\Compras\ComprasService;
use App\Modules\Compras\ProveedoresService;

/** Compras y centros de costos (0.41.0): importes e IVA, costo, reparto, repetidos, caja, cuenta corriente, listados, tablero y permisos. */
final class ComprasTest extends ComprobantesTestCase
{
    private ComprasService $c;
    private CentrosService $cs;
    private int $admin;
    private int $prov;
    private int $provMono;
    private int $local;
    private int $deposito;
    private int $sucursal;

    protected function setUp(): void
    {
        parent::setUp();
        $this->limpiar();
        $this->c = new ComprasService();
        $this->cs = new CentrosService();
        $this->admin = $this->crearUsuario('t_compras_admin', 'admin');
        $this->local = $this->cs->guardarCentro(null, 'T Local', null, true)['id'];
        $this->sucursal = $this->cs->guardarCentro(null, 'T Sucursal Norte', $this->local, true)['id'];
        $this->deposito = $this->cs->guardarCentro(null, 'T Depósito', null, true)['id'];
        $ps = new ProveedoresService();
        $r = $ps->guardar(null, ['codigo' => '99001', 'razon_social' => 'T DISTRIBUIDORA SUR SA', 'cuit' => '30-70880558-7', 'iva' => '1', 'plazo_dias' => '30',
            'centro_id' => (string) $this->local]);
        $this->assertTrue($r['ok'], json_encode($r['errores']));
        $this->prov = $r['id'];
        $this->provMono = $ps->guardar(null, ['codigo' => '99002', 'razon_social' => 'T PLOMERO MONOTRIBUTO', 'cuit' => '20111111112', 'iva' => '7'])['id'];
    }

    protected function tearDown(): void
    {
        $this->limpiar();
        parent::tearDown();
    }

    private function limpiar(): void
    {
        $pdo = Db::pdo();
        foreach (['compras', 'compras_iva', 'compras_tributos', 'compras_reparto', 'compras_adjuntos', 'compras_pagos'] as $t) {
            $pdo->exec("DELETE FROM {$t}");
        }
        $pdo->exec("DELETE FROM proveedores WHERE razon_social LIKE 'T %'");
        $pdo->exec("DELETE p FROM centros_presupuesto p JOIN centros_costo c ON c.id = p.centro_id WHERE c.nombre LIKE 'T %'");
        $pdo->exec("DELETE FROM centros_costo WHERE nombre LIKE 'T %' AND padre_id IS NOT NULL");
        $pdo->exec("DELETE FROM centros_costo WHERE nombre LIKE 'T %'");
        $pdo->exec("DELETE FROM reparto_plantillas WHERE nombre LIKE 'T %'");
        $pdo->exec("DELETE FROM conceptos_gasto WHERE nombre LIKE 'T %'");
    }

    /** @return array<string,mixed> */
    private function datos(array $extra = []): array
    {
        return $extra + ['proveedor' => (string) $this->prov, 'tipo' => 'FC', 'letra' => 'A', 'punto_venta' => '3', 'numero' => (string) random_int(1, 99999999),
            'fecha' => date('d/m/Y'), 'neto' => ['21' => '1.000,00'], 'forma' => 'C'];
    }

    private function grabar(array $extra = [], ?int $uid = null): int
    {
        $a = $this->c->armar($this->datos($extra));
        $this->assertSame([], $a['errores'], 'sin errores');
        $g = $this->c->grabar($a['compra'], $uid ?? $this->admin, bin2hex(random_bytes(16)));
        $this->assertTrue($g['ok'], $g['error'] ?? '');
        return $g['id'];
    }

    public function testFacturaAConAlicuotasPercepcionesTotalYCosto(): void
    {
        $a = $this->c->armar($this->datos(['neto' => ['21' => '1.000', '10.5' => '200'], 'no_gravado' => '50', 'trib' => [['tipo' => 'IIBB', 'detalle' => 'Bs. As.', 'importe' => '30']],
            'total_impreso' => '1.511,00']));
        $this->assertSame([], $a['errores']);
        $x = $a['compra'];
        $this->assertSame([1200.0, 231.0, 30.0, 1511.0, 1250.0, 1], [$x['gravado'], $x['iva'], $x['percepciones'], $x['total'], $x['costo'], $x['computa_iva']],
            'el costo es el total sin IVA computable ni percepciones');
        $this->assertSame('General', Db::pdo()->query('SELECT nombre FROM centros_costo WHERE general = 1')->fetchColumn());
        $this->assertSame($this->local, $x['reparto'][0]['centro_id'], 'el centro que propone el proveedor');
        $this->assertSame(date('Y-m-d', strtotime('+30 days')), $x['vence'], 'vence con el plazo del proveedor');
        // total impreso distinto, IVA fuera de tolerancia, IVA corregido por centavos
        $this->assertStringContainsString('no coincide', $this->c->armar($this->datos(['total_impreso' => '1.300']))['errores']['total_impreso']);
        $this->assertArrayKey('neto', $this->c->armar($this->datos(['iva' => ['21' => '250']]))['errores']);
        $this->assertSame(210.4, $this->c->armar($this->datos(['iva' => ['21' => '210,40']]))['compra']['iva'], 'el IVA se puede ajustar al impreso');
        // B o C: un solo importe, todo al costo; gasto sin comprobante sin número; empresa monotributista no computa
        $b = $this->c->armar($this->datos(['proveedor' => (string) $this->provMono, 'letra' => 'C', 'importe' => '5.000']))['compra'];
        $this->assertSame([5000.0, 5000.0, 0], [$b['total'], $b['costo'], $b['computa_iva']]);
        $g = $this->c->armar(['proveedor' => (string) $this->provMono, 'tipo' => 'GS', 'fecha' => date('d/m/Y'), 'importe' => '800', 'forma' => 'O']);
        $this->assertSame([], $g['errores'], 'el gasto sin comprobante no pide número');
        Db::pdo()->exec('UPDATE parametros SET iva = 6 WHERE id = 1');
        $this->assertSame([0, 1210.0], [$this->c->armar($this->datos())['compra']['computa_iva'], $this->c->armar($this->datos())['compra']['costo']]);
        // validaciones básicas
        $e = $this->c->armar(['tipo' => 'FC', 'letra' => 'Z', 'fecha' => '31/02/2026', 'forma' => 'X'])['errores'];
        foreach (['proveedor', 'letra', 'punto_venta', 'numero', 'fecha', 'forma'] as $k) {
            $this->assertArrayKey($k, $e);
        }
        $this->assertArrayKey('fecha', $this->c->armar($this->datos(['fecha' => date('d/m/Y', strtotime('+2 days'))]))['errores'], 'no se carga con fecha futura');
    }

    private function assertArrayKey(string $k, array $a): void
    {
        $this->assertTrue(array_key_exists($k, $a), "falta el error de «{$k}»: " . json_encode($a, JSON_UNESCAPED_UNICODE));
    }

    public function testRepartoSumaCienYElUltimoSeLlevaLosCentavos(): void
    {
        $gen = $this->cs->general();
        $con = $this->cs->conceptoPorOmision();
        $r = $this->c->armar($this->datos(['neto' => ['21' => '100'], 'rep' => [
            ['centro' => $this->local, 'concepto' => $con, 'porcentaje' => '33,33'], ['centro' => $this->deposito, 'concepto' => $con, 'porcentaje' => '33,33'],
            ['centro' => $gen, 'concepto' => $con, 'porcentaje' => '33,34']]]));
        $this->assertSame([], $r['errores']);
        $this->assertSame([33.33, 33.33, 33.34], array_column($r['compra']['reparto'], 'importe'));
        $mal = $this->c->armar($this->datos(['rep' => [['centro' => $this->local, 'concepto' => $con, 'porcentaje' => '50'], ['centro' => $this->deposito, 'concepto' => $con, 'porcentaje' => '40']]]));
        $this->assertStringContainsString('100 %', $mal['errores']['rep']);
        $this->cs->guardarCentro($this->deposito, 'T Depósito', null, false);
        $inact = $this->c->armar($this->datos(['rep' => [['centro' => $this->deposito, 'concepto' => $con, 'porcentaje' => '100']]]));
        $this->assertArrayKey('rep', $inact['errores']);
        // plantilla
        $this->assertFalse($this->cs->guardarPlantilla('T Luz', [['centro' => $this->local, 'porcentaje' => '60']])['ok'], 'hace falta más de un centro');
        $this->assertTrue($this->cs->guardarPlantilla('T Luz', [['centro' => $this->local, 'porcentaje' => '60'], ['centro' => $gen, 'porcentaje' => '40']])['ok']);
    }

    public function testRepetidaYDobleEnvio(): void
    {
        $d = $this->datos(['numero' => '14587']);
        $a = $this->c->armar($d)['compra'];
        $op = bin2hex(random_bytes(16));
        $g1 = $this->c->grabar($a, $this->admin, $op);
        $g2 = $this->c->grabar($a, $this->admin, $op);
        $this->assertSame([$g1['id'], true], [$g2['id'], $g2['repetida'] ?? false], 'la misma operación devuelve la misma compra');
        $this->assertStringContainsString('ya está cargado', $this->c->armar($d)['errores']['numero']);
        $this->assertStringContainsString('ya está cargado', $this->c->grabar($a, $this->admin, bin2hex(random_bytes(16)))['error'], 'se vuelve a controlar al grabar');
        $this->c->anular($g1['id'], $this->admin, 'cargada mal');
        $this->assertSame([], $this->c->armar($d)['errores'], 'anulada, se puede volver a cargar');
    }

    public function testEfectivoSaleDeLaCajaYAnularLaDevuelve(): void
    {
        $a = $this->c->armar($this->datos(['forma' => 'E']))['compra'];
        $this->assertStringContainsString('turno', $this->c->grabar($a, $this->admin, null)['error']);
        $caja = new CajaService();
        $t = $caja->abrir($this->admin, '5.000')['id'];
        $id = $this->c->grabar($a, $this->admin, null)['id'];
        $this->assertSame([1210.0, 3790.0], [$caja->resumenTurno($t)['egresos'], $caja->resumenTurno($t)['esperado']], 'el pago es un egreso del turno');
        $c = $this->c->obtener($id);
        $this->assertSame(['0.00', 'E'], [$c['saldo'], $c['pagos'][0]['medio']]);
        $mov = (int) $c['pagos'][0]['caja_movimiento_id'];
        $this->assertStringContainsString('compra', $caja->anular($this->admin, $mov, 'x')['error'], 'la caja no lo anula suelto');
        $this->assertTrue($this->c->anular($id, $this->admin, 'era de otro')['ok']);
        $this->assertSame(0.0, $caja->resumenTurno($t)['egresos'] - $caja->resumenTurno($t)['ingresos'], 'la anulación devuelve el dinero');
        $this->assertFalse($this->c->anular($id, $this->admin, 'otra vez')['ok']);
        $this->assertTrue($caja->verificar()['ok'], 'la auditoría de caja sigue cerrando');
        // nota de crédito en efectivo: entra a la caja
        $nc = $this->grabar(['tipo' => 'NC', 'forma' => 'E', 'neto' => ['21' => '100']]);
        $this->assertSame('I', Db::pdo()->query('SELECT m.tipo FROM caja_movimientos m JOIN compras_pagos p ON p.caja_movimiento_id = m.id WHERE p.compra_id = ' . $nc)->fetchColumn());
    }

    public function testCuentaCorrientePagosYAPagar(): void
    {
        $vieja = $this->grabar(['fecha' => date('d/m/Y', strtotime('-50 days')), 'vence' => date('d/m/Y', strtotime('-40 days'))]);
        $semana = $this->grabar(['vence' => date('d/m/Y', strtotime('+3 days')), 'neto' => ['21' => '500']]);
        $this->grabar(['tipo' => 'NC', 'neto' => ['21' => '100']]);
        $ap = ComprasListados::aPagar([]);
        $this->assertSame([1210.0 + 605.0 - 121.0, 1210.0, 605.0, 1], [$ap['totales']['saldo'], $ap['totales']['d60'], $ap['totales']['semana'], $ap['totales']['vence_semana_cant']]);
        $this->assertSame(1694.0, (new ProveedoresService())->saldo($this->prov));
        $this->assertFalse($this->c->pagar($vieja, $this->admin, 'T', '2.000', '')['ok'], 'no más que el saldo');
        $this->assertTrue($this->c->pagar($vieja, $this->admin, 'T', '1.000', 'Bco. Nación')['ok']);
        $this->assertTrue($this->c->pagar($vieja, $this->admin, 'O', '', '')['ok'], 'vacío = todo el saldo');
        $this->assertStringContainsString('ya está pagada', $this->c->pagar($vieja, $this->admin, 'T', '1', '')['error']);
        $nc = (int) Db::pdo()->query("SELECT id FROM compras WHERE tipo = 'NC'")->fetchColumn();
        $this->assertStringContainsString('nota de crédito', $this->c->pagar($nc, $this->admin, 'T', '', '')['error']);
        $this->assertStringContainsString('turno', $this->c->pagar($semana, $this->admin, 'E', '', '')['error'], 'en efectivo hace falta turno');
        $this->assertSame(484.0, ComprasListados::aPagar([])['totales']['saldo']);
    }

    public function testListadosPresupuestoIvaYTablero(): void
    {
        $con = $this->cs->conceptoPorOmision();
        $alq = $this->cs->guardarConcepto(null, 'T Alquiler', true)['id'];
        $this->grabar(['neto' => ['21' => '1.000'], 'rep' => [['centro' => $this->sucursal, 'concepto' => $alq, 'porcentaje' => '60'], ['centro' => $this->deposito, 'concepto' => $con, 'porcentaje' => '40']]]);
        $this->grabar(['proveedor' => (string) $this->provMono, 'letra' => 'C', 'importe' => '500', 'centro_id' => (string) $this->deposito, 'trib' => [['tipo' => 'IVA', 'importe' => '10']]]);
        $this->grabar(['tipo' => 'NC', 'neto' => ['21' => '100'], 'centro_id' => (string) $this->local]);
        $anul = $this->grabar(['neto' => ['21' => '9.999']]);
        $this->c->anular($anul, $this->admin, 'no va');
        $hoy = date('d/m/Y');
        $an = ComprasListados::analisis(['desde' => $hoy, 'hasta' => $hoy, 'agrupar' => 'centro']);
        $porNombre = array_column($an['filas'], 'total', 'nombre');
        $this->assertSame([500.0, 900.0, 1400.0], [$porNombre['T Local'], $porNombre['T Depósito'], $an['total']], 'el principal suma su segundo nivel y la NC resta');
        $sub = array_column(ComprasListados::analisis(['desde' => $hoy, 'hasta' => $hoy, 'agrupar' => 'subcentro'])['filas'], 'total', 'nombre');
        $this->assertSame([600.0, -100.0], [$sub['T Local › T Sucursal Norte'], $sub['T Local']]);
        $this->assertSame(600.0, array_column(ComprasListados::analisis(['desde' => $hoy, 'hasta' => $hoy, 'agrupar' => 'concepto', 'concepto' => (string) $alq])['filas'], 'total', 'nombre')['T Alquiler']);
        // presupuesto
        $per = date('Y-m');
        $this->assertTrue($this->cs->guardarPresupuesto($per, [$this->local => '200', $this->sucursal => '300', $this->deposito => '1.000'])['ok']);
        $pr = array_column(ComprasListados::presupuestoReal($per)['filas'], null, 'id');
        $this->assertSame([500.0, 500.0, 0.0], [$pr[$this->local]['presupuesto'], $pr[$this->local]['gastado'], $pr[$this->local]['diferencia']]);
        $this->assertSame([0, 600.0, -300.0], [ComprasListados::presupuestoReal($per)['sobre'], $pr[$this->sucursal]['gastado'], $pr[$this->sucursal]['diferencia']], 'el semáforo cuenta los principales');
        $sig = date('Y-m', strtotime(date('Y-m-01') . ' +1 month'));
        $this->assertSame(3, $this->cs->copiarPresupuestoAnterior($sig));
        // IVA compras: solo vigentes, la NC resta, la C no da crédito
        $iva = ComprasListados::ivaCompras($per);
        $this->assertSame([3, 210.0 - 21.0, 10.0, 1210.0 + 510.0 - 121.0], [count($iva['filas']), $iva['totales']['credito'], $iva['totales']['perc_iva'], $iva['totales']['total']]);
        // tablero
        $t = ComprasListados::tablero($per);
        $this->assertSame(1400.0, $t['kpi']['gastado']);
        $this->assertSame(6, count($t['etiquetas']));
        $this->assertTrue(in_array('T Local', array_column($t['centros'], 'nombre'), true));
    }

    public function testCentrosDosNivelesYGeneralNoSeDesactiva(): void
    {
        $this->assertFalse($this->cs->guardarCentro(null, 'T Nieto', $this->sucursal, true)['ok'], 'dos niveles como mucho');
        $this->assertFalse($this->cs->guardarCentro($this->local, 'T Local', $this->deposito, true)['ok'], 'un centro con hijos no baja de nivel');
        $this->assertFalse($this->cs->guardarCentro(null, 'T Sucursal Norte', $this->local, true)['ok'], 'nombre repetido en el mismo lugar');
        $gen = $this->cs->general();
        $this->cs->guardarCentro($gen, 'General', null, false);
        $this->assertSame('1', (string) Db::pdo()->query("SELECT activo FROM centros_costo WHERE id = {$gen}")->fetchColumn());
        $this->assertSame('T Local › T Sucursal Norte', $this->cs->centros()[$this->sucursal]['completo']);
    }

    public function testProveedoresValidanCuitYBuscan(): void
    {
        $ps = new ProveedoresService();
        $this->assertArrayKey('cuit', $ps->guardar(null, ['razon_social' => 'T X', 'cuit' => '30-12345678-0', 'iva' => '1'])['errores']);
        $rep = $ps->guardar(null, ['razon_social' => 'T OTRO', 'cuit' => '30708805587', 'iva' => '1']);
        $this->assertSame('T DISTRIBUIDORA SUR SA', $rep['repetido'] ?? null);
        $this->assertTrue($ps->guardar(null, ['razon_social' => 'T OTRO', 'cuit' => '30708805587', 'iva' => '1', 'confirmar_cuit' => '1'])['ok']);
        $this->assertTrue($ps->guardar(null, ['razon_social' => 'T VARIOS', 'iva' => '3'])['ok'], 'el informal va sin CUIT');
        $this->loginComo($this->admin);
        $j = json_decode($this->get('/compras/buscar-proveedor', ['q' => 'distri'])->body, true);
        $this->assertSame([$this->prov, 99001, $this->local], [$j[0]['codigo'], $j[0]['numero'], $j[0]['centro_id']]);
        $l = json_decode($this->get('/compras/buscar-proveedor', ['q' => '3070880', 'listar' => '1'])->body, true);
        $this->assertTrue(count($l['items']) >= 1);
    }

    public function testAdjuntosSoloFotosOPdf(): void
    {
        $this->assertSame(['application/pdf', 'image/png', null], [ComprasService::mimeDe("%PDF-1.4\n"), ComprasService::mimeDe("\x89PNG\r\n\x1A\nxx"), ComprasService::mimeDe('<?php echo 1;')]);
        $tmp = tempnam(sys_get_temp_dir(), 'adj');
        file_put_contents($tmp, "%PDF-1.4\nprueba");
        $a = $this->c->armar($this->datos())['compra'];
        $g = $this->c->grabar($a, $this->admin, null, [['name' => 'factura.pdf', 'tmp_name' => $tmp, 'error' => UPLOAD_ERR_OK, 'size' => 14]]);
        $this->assertTrue($g['ok']);
        $c = $this->c->obtener($g['id']);
        $this->assertSame('factura.pdf', $c['adjuntos'][0]['nombre']);
        file_put_contents($tmp, 'MZ ejecutable');
        $this->assertStringContainsString('PDF', $this->c->grabar($this->c->armar($this->datos())['compra'], $this->admin, null, [['name' => 'x.exe', 'tmp_name' => $tmp, 'error' => 0, 'size' => 13]])['error']);
        @unlink($tmp);
        $this->loginComo($this->admin);
        $r = $this->get('/compras/adjunto', ['compra' => (string) $g['id'], 'id' => (string) $c['adjuntos'][0]['id']]);
        $this->assertSame(200, $r->status);
    }

    public function testPantallasYPermisosPorRol(): void
    {
        $id = $this->grabar();
        $oper = $this->crearUsuario('t_compras_oper', 'operador');
        $this->loginComo($oper);
        foreach (['/compras', '/compras/nueva', '/compras/lista', '/compras/ver/' . $id, '/compras/centros', '/compras/proveedores', '/compras/iva'] as $ruta) {
            $this->assertSame(403, $this->get($ruta)->status, "el operador no entra a {$ruta}");
        }
        $this->assertSame(403, $this->post('/compras/anular', ['compra' => (string) $id, 'motivo' => 'x'])->status);
        $this->loginComo($this->admin);
        foreach (['/compras', '/compras/nueva', '/compras/lista', '/compras/ver/' . $id, '/compras/centros', '/compras/proveedores', '/compras/proveedores/ficha',
            '/compras/proveedores/ficha?id=' . $this->prov, '/compras/iva', '/compras/a-pagar', '/compras/analisis', '/compras/presupuesto'] as $ruta) {
            [$p, $q] = array_pad(explode('?', $ruta, 2), 2, '');
            parse_str($q, $qq);
            $r = $this->get($p, $qq);
            $this->assertSame(200, $r->status, "el administrador ve {$ruta}");
        }
        foreach (['/compras/lista', '/compras/a-pagar', '/compras/analisis', '/compras/iva', '/compras/proveedores'] as $ruta) {
            $r = $this->get($ruta, ['excel' => '1']);
            $this->assertStringContainsString('spreadsheetml', $r->headers['Content-Type'] ?? '', "Excel de {$ruta}");
        }
        // carga completa por la pantalla
        $r = $this->post('/compras/nueva', $this->datos(['numero' => '777', 'operacion' => bin2hex(random_bytes(16))]));
        $this->assertSame(302, $r->status, $r->body);
        $this->assertSame('1', (string) Db::pdo()->query("SELECT COUNT(*) FROM compras WHERE numero = 777")->fetchColumn());
        // solo compras.cargar: carga pero no paga ni ve centros
        $rol = (int) Db::pdo()->query("SELECT id FROM roles WHERE codigo = 'operador'")->fetchColumn();
        Db::pdo()->exec("INSERT IGNORE INTO rol_permisos (rol_id, permiso_id) SELECT {$rol}, id FROM permisos WHERE codigo IN ('compras.ver', 'compras.cargar')");
        try {
            $this->loginComo($oper);
            $this->assertSame(200, $this->get('/compras/nueva')->status);
            $this->assertSame(403, $this->get('/compras/centros')->status);
            $this->assertSame(403, $this->post('/compras/pagar', ['compra' => (string) $id, 'medio' => 'T'])->status);
        } finally {
            Db::pdo()->exec("DELETE rp FROM rol_permisos rp JOIN permisos p ON p.id = rp.permiso_id WHERE rp.rol_id = {$rol} AND p.codigo LIKE 'compras.%'");
        }
    }
}
