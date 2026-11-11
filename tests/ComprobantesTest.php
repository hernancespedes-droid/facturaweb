<?php
declare(strict_types=1);

namespace Tests;

use App\Core\Db;
use App\Modules\Comprobantes\ComprobantesService;

final class ComprobantesTest extends ComprobantesTestCase
{
    public function testFacturaAACuentaCorrienteGrabaTodoYNumera(): void
    {
        $e = $this->emitir($this->pedido(['observaciones' => 'Pedido 5']));
        $this->assertSame(1, $e['numero']);
        $h = $this->svc->obtener($e['id']);
        $this->assertSame(['FC', 'A', 99, 9, 1], [$h['tipo'], $h['letra'], (int) $h['sucursal'], (int) $h['prefijo'], (int) $h['comprobante']]);
        $this->assertSame(['200.00', '200.00', '0.00', '42.00', '242.00'], [$h['bruto'], $h['neto_grabado'], $h['neto_exento'], $h['iva_1'], $h['neto']]);
        $this->assertSame(date('Y-m-d'), $h['fecha']);
        $this->assertSame('', $h['fe_ccae'], 'todavía sin CAE (etapa 3)');
        $this->assertSame(1, (int) $h['tipoafipoys']);
        $this->assertSame('T CLIENTE RI SA', $h['cliente_razon_social']);
        $this->assertSame(1, (int) $h['iva_cliente']);

        $this->assertCount(1, $h['items']);
        $it = $h['items'][0];
        $this->assertSame(['T-ART', 'ARTICULO DE PRUEBA', '2.000', '100.0000', '200.00'], [$it['articulo'], $it['observaciones'], $it['unidades'], $it['precio'], $it['total_item']]);
        $this->assertSame('21.00', $it['por_iva']);
        $this->assertCount(1, $h['iva']);
        $this->assertSame(['21.00', '200.00', '42.00'], [$h['iva'][0]['alicuota'], $h['iva'][0]['monto'], $h['iva'][0]['importe']]);

        $this->assertSame('CC', $h['pagos'][0]['p_fpago']);
        $ct = $this->fila('SELECT * FROM ctact WHERE cliente = 990001');
        $this->assertSame([1, 'FC', 'A', 1, '242.00', '242.00'], [(int) $ct['tico'], $ct['tipo'], $ct['letra'], (int) $ct['numero'], $ct['neto'], $ct['saldo']]);
        $this->assertSame(1, (int) $this->fila('SELECT ultima_factura_a AS n FROM sucursales WHERE codigo = 99')['n']);
        $this->assertSame(date('Y-m-d'), $this->fila('SELECT ultima_compra AS f FROM clientes WHERE codigo = 990001')['f']);

        $this->assertSame(2, $this->emitir($this->pedido())['numero'], 'el siguiente número');
    }

    public function testFacturaBAConsumidorFinalContadoConIvaIncluido(): void
    {
        $e = $this->emitir($this->pedido(['cliente' => '990002', 'forma_pago' => '1', 'fpago' => 'EF',
            'items' => [['descripcion' => 'Servicio libre', 'cantidad' => '1', 'precio' => '15456', 'alicuota' => '21']]]));
        $h = $this->svc->obtener($e['id']);
        $this->assertSame('B', $h['letra']);
        $this->assertSame(['12773.55', '2682.45', '15456.00'], [$h['neto_grabado'], $h['iva_1'], $h['neto']]);
        $this->assertSame('EF', $h['pagos'][0]['p_fpago']);
        $this->assertSame(0, (int) Db::pdo()->query('SELECT COUNT(*) FROM ctact')->fetchColumn(), 'contado: no hay cuenta corriente');
        $this->assertSame(null, $h['items'][0]['articulo'], 'renglón libre');
        $this->assertSame('15456.0000', $h['items'][0]['precio'], 'en la B el precio se ve con IVA');
        $this->assertSame('12773.55', $h['items'][0]['total_siniva']);
        $this->assertSame(1, (int) $this->fila('SELECT ultima_factura_b AS n FROM sucursales WHERE codigo = 99')['n']);
        $this->assertSame(0, (int) $this->fila('SELECT ultima_factura_a AS n FROM sucursales WHERE codigo = 99')['n']);
    }

    public function testEmpresaMonotributistaEmiteC(): void
    {
        Db::pdo()->exec('UPDATE parametros SET iva = 7 WHERE id = 1');
        $e = $this->emitir($this->pedido(['cliente' => '990001']));
        $h = $this->svc->obtener($e['id']);
        $this->assertSame('C', $h['letra']);
        $this->assertSame(['200.00', '0.00', '200.00'], [$h['neto_grabado'], $h['iva_1'], $h['neto']]);
        $this->assertSame([], $h['iva'], 'la C no lleva IVA');
        $this->assertSame(1, (int) $this->fila('SELECT ultima_factura_c AS n FROM sucursales WHERE codigo = 99')['n']);
        $this->assertSame(11, (int) $h['tipoafipoys']);
    }

    public function testRiAMonotributistaLeHaceA(): void
    {
        $h = $this->svc->obtener($this->emitir($this->pedido(['cliente' => '990003']))['id']);
        $this->assertSame('A', $h['letra']);
    }

    public function testValidaciones(): void
    {
        $e = fn (array $p) => $this->svc->armar($p)['errores'];
        $this->assertTrue(isset($e($this->pedido(['items' => []]))['items']), 'sin renglones');
        $this->assertStringContainsString('el artículo NOEXISTE no existe', $e($this->pedido(['items' => [['articulo' => 'noexiste', 'cantidad' => '1', 'precio' => '1', 'alicuota' => '21']]]))['items']);
        $err = $e($this->pedido(['items' => [['descripcion' => 'x', 'cantidad' => '0', 'precio' => '-1', 'alicuota' => '99']]]))['items'];
        $this->assertStringContainsString('la cantidad tiene que ser mayor que cero', $err);
        $this->assertStringContainsString('el precio no es válido', $err);
        $this->assertStringContainsString('elegí la alícuota de IVA', $err);
        $this->assertTrue(isset($e($this->pedido(['items' => [['descripcion' => 'x', 'cantidad' => '1', 'precio' => '0', 'alicuota' => '21']]]))['items']), 'total cero');
        $this->assertTrue(isset($e($this->pedido(['cliente' => '123456']))['cliente']));
        $this->assertTrue(isset($e($this->pedido(['sucursal' => '55']))['sucursal']));
        $this->assertTrue(isset($e($this->pedido(['forma_pago' => '7']))['forma_pago']));
        $this->assertTrue(isset($e($this->pedido(['descuento' => '101']))['descuento']));
        $this->assertTrue(isset($e($this->pedido(['email' => 'a@x.com; malo']))['email']));
        $this->assertTrue(isset($e($this->pedido(['tipo' => 'XX']))['tipo']));
        $this->assertTrue(isset($e($this->pedido(['forma_pago' => '1', 'fpago' => 'CC']))['fpago']), 'contado no acepta la cuenta corriente');
        $this->assertSame([], $e($this->pedido(['items' => array_merge([['descripcion' => '', 'cantidad' => '', 'precio' => '', 'alicuota' => '']], $this->pedido()['items'])])), 'los renglones en blanco se ignoran');

        Db::pdo()->exec('UPDATE clientes SET activo = 0 WHERE codigo = 990001');
        $this->assertStringContainsString('desactivado', $e($this->pedido())['cliente']);
        Db::pdo()->exec('UPDATE clientes SET activo = 1, iva = 2 WHERE codigo = 990001');
        $this->assertStringContainsString('No hay equivalencia de ARCA', $e($this->pedido())['cliente']);
        Db::pdo()->exec('UPDATE clientes SET iva = 1 WHERE codigo = 990001');
        Db::pdo()->exec('UPDATE parametros SET cuit = 30714357538 WHERE id = 1');
        $this->assertStringContainsString('Tablas → Parámetros', $e($this->pedido())['emisor']);
        Db::pdo()->exec('UPDATE parametros SET cuit = 30714357537, iva = 3 WHERE id = 1');
        $this->assertStringContainsString('Tablas → Parámetros', $e($this->pedido())['emisor']);
    }

    public function testChequeExigeSusDatos(): void
    {
        Db::pdo()->exec("INSERT INTO bancos (codigo, descripcion) VALUES (990, 'BANCO DE PRUEBA')");
        $p = $this->pedido(['cliente' => '990002', 'forma_pago' => '1', 'fpago' => 'CH']);
        $err = $this->svc->armar($p)['errores'];
        $this->assertSame(['pago_nro', 'pago_banco', 'pago_vto'], array_keys($err));
        $e = $this->emitir($p + ['pago_nro' => '12345678', 'pago_banco' => '990', 'pago_vto' => '15/01/2027']);
        $pago = $this->svc->obtener($e['id'])['pagos'][0];
        $this->assertSame(['CH', '12345678', 990, '2027-01-15'], [$pago['p_fpago'], $pago['p_nro'], (int) $pago['p_banco'], $pago['p_fecvto']]);
    }

    public function testNotaDeCreditoNecesitaLaFacturaYNoPuedeSuperarla(): void
    {
        $fc = $this->emitir($this->pedido());                      // total 242
        $this->assertTrue(isset($this->svc->armar($this->pedido(['tipo' => 'NC']))['errores']['asociado']), 'sin asociado');
        $this->assertStringContainsString('otro cliente', $this->svc->armar($this->pedido(['tipo' => 'NC', 'cliente' => '990003', 'asociado' => (string) $fc['id']]))['errores']['asociado']);
        $this->assertStringContainsString('no existe', $this->svc->armar($this->pedido(['tipo' => 'NC', 'asociado' => '999999']))['errores']['asociado']);

        $nc1 = $this->pedido(['tipo' => 'NC', 'asociado' => (string) $fc['id'], 'sucursal' => '',
            'items' => [['descripcion' => 'Devolución', 'cantidad' => '1', 'precio' => '100', 'alicuota' => '21']]]);   // 121
        $e = $this->emitir($nc1);
        $h = $this->svc->obtener($e['id']);
        $this->assertSame(['NC', 'A', 99, 1, 'FC', 1], [$h['tipo'], $h['letra'], (int) $h['sucursal'], (int) $h['comprobante'], $h['fe_tipocbte_asoc'], (int) $h['fe_nrocbte_asoc']]);
        $this->assertSame('121.00', $h['neto']);
        $this->assertSame(0, (int) Db::pdo()->query("SELECT COUNT(*) FROM caja WHERE tipo = 'NC'")->fetchColumn(), 'la NC no mueve caja');
        $ct = $this->fila("SELECT tico, saldo FROM ctact WHERE tipo = 'NC'");
        $this->assertSame([8, '121.00'], [(int) $ct['tico'], $ct['saldo']], 'a cuenta corriente: queda a favor');
        $this->assertSame(1, (int) $this->fila('SELECT ultima_nc_a AS n FROM sucursales WHERE codigo = 99')['n']);

        $factura = $this->svc->obtener($fc['id']);
        $this->assertCount(1, $factura['notas']);
        $this->assertSame(121.0, $this->svc->porAcreditar($factura));
        // una segunda NC de 150 supera lo que queda (121)
        $nc2 = $nc1;
        $nc2['items'][0]['precio'] = '150';
        $this->assertStringContainsString('supera lo que queda por acreditar', $this->svc->armar($nc2)['errores']['items']);
        $nc2['items'][0]['precio'] = '100';
        $this->emitir($nc2);
        $this->assertSame(0.0, $this->svc->porAcreditar($this->svc->obtener($fc['id'])));

        // La nota de débito sí puede ser de cualquier importe
        $nd = $this->emitir(array_merge($nc1, ['tipo' => 'ND']));
        $this->assertSame('ND', $this->svc->obtener($nd['id'])['tipo']);
        $this->assertSame(1, (int) $this->fila('SELECT ultima_nd_a AS n FROM sucursales WHERE codigo = 99')['n']);
        // y una NC no se asocia a otra NC
        $ncId = $e['id'];
        $this->assertStringContainsString('Solo se asocian', $this->svc->armar(array_merge($nc1, ['asociado' => (string) $ncId]))['errores']['asociado']);
    }

    public function testCreditoDelCliente(): void
    {
        Db::pdo()->exec('UPDATE clientes SET credito = 300 WHERE codigo = 990001');
        $this->emitir($this->pedido());                            // deuda 242
        $this->assertSame(242.0, $this->svc->deudaCliente(990001));
        $r = $this->svc->armar($this->pedido());                   // otros 242: excede
        $this->assertStringContainsString('excede el crédito', $r['errores']['credito']);
        $this->assertStringContainsString('Pedile a un administrador', $r['errores']['credito']);
        $this->assertTrue(isset($this->svc->armar($this->pedido(['excede_ok' => '1']), false)['errores']['credito']), 'sin permiso no alcanza con tildar');
        $this->assertStringContainsString('Tildá', $this->svc->armar($this->pedido(), true)['errores']['credito']);
        $this->assertSame([], $this->svc->armar($this->pedido(['excede_ok' => '1']), true)['errores'], 'con permiso y tildado se emite');
        $this->assertSame([], $this->svc->armar($this->pedido(['forma_pago' => '1', 'fpago' => 'EF']))['errores'], 'contado no usa crédito');
        // la nota de crédito baja la deuda
        $fc = Db::pdo()->query("SELECT fe_clave FROM subdiario LIMIT 1")->fetchColumn();
        $this->emitir($this->pedido(['tipo' => 'NC', 'asociado' => (string) $fc, 'items' => [['descripcion' => 'x', 'cantidad' => '1', 'precio' => '100', 'alicuota' => '21']]]));
        $this->assertSame(121.0, $this->svc->deudaCliente(990001));
    }

    public function testDobleEnvioNoEmiteDosVeces(): void
    {
        $op = bin2hex(random_bytes(16));
        $a = $this->emitir($this->pedido(), $op);
        $b = $this->emitir($this->pedido(), $op);
        $this->assertFalse($a['repetido']);
        $this->assertTrue($b['repetido']);
        $this->assertSame($a['id'], $b['id']);
        $this->assertSame(1, (int) Db::pdo()->query('SELECT COUNT(*) FROM subdiario')->fetchColumn());
        $this->assertSame(1, (int) Db::pdo()->query('SELECT COUNT(*) FROM ctact')->fetchColumn());
        try {
            $this->svc->emitir($this->svc->armar($this->pedido())['cbte'], 'no-es-una-clave', 0);
            $this->fail('tenía que rechazar una clave inválida');
        } catch (\InvalidArgumentException) {
            $this->assertSame(1, (int) Db::pdo()->query('SELECT COUNT(*) FROM subdiario')->fetchColumn());
        }
    }

    public function testElComprobanteGuardaUnaCopiaDelClienteYNoSeCambiaDespues(): void
    {
        $e = $this->emitir($this->pedido());
        Db::pdo()->exec("UPDATE clientes SET razon_social = 'T OTRO NOMBRE', domicilio = 'OTRA CALLE' WHERE codigo = 990001");
        $h = $this->svc->obtener($e['id']);
        $this->assertSame(['T CLIENTE RI SA', 'CALLE 1'], [$h['cliente_razon_social'], $h['cliente_domicilio']]);
        $this->assertSame('T OTRO NOMBRE', $h['cliente_actual']['razon_social']);
    }

    public function testFallaALaMitadNoDejaNadaGrabadoNiConsumeNumero(): void
    {
        $r = $this->svc->armar($this->pedido());
        $r['cbte']['calculo']['items'][0]['descripcion'] = str_repeat('x', 400); // revienta el INSERT de estadistica (VARCHAR 255)
        try {
            $this->svc->emitir($r['cbte'], bin2hex(random_bytes(16)), 0);
            $this->fail('tenía que fallar');
        } catch (\Throwable) {
        }
        foreach (['subdiario', 'iva', 'estadistica', 'caja', 'ctact'] as $t) {
            $this->assertSame(0, (int) Db::pdo()->query("SELECT COUNT(*) FROM `{$t}`")->fetchColumn(), $t);
        }
        $this->assertSame(0, (int) $this->fila('SELECT ultima_factura_a AS n FROM sucursales WHERE codigo = 99')['n']);
    }

    public function testEmisionesSimultaneasNoRepitenNumero(): void
    {
        $procs = [];
        for ($i = 0; $i < 6; $i++) {
            $procs[] = proc_open([PHP_BINARY, __DIR__ . '/fixtures/emitir_factura.php'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        }
        foreach ($procs as $p) {
            proc_close($p);
        }
        $nums = Db::pdo()->query("SELECT comprobante FROM subdiario WHERE tipo = 'FC' ORDER BY comprobante")->fetchAll(\PDO::FETCH_COLUMN);
        $this->assertSame([1, 2, 3, 4, 5, 6], array_map('intval', $nums), 'seis facturas, seis números seguidos');
        $this->assertSame(6, (int) $this->fila('SELECT ultima_factura_a AS n FROM sucursales WHERE codigo = 99')['n']);
        $this->assertSame(6, (int) Db::pdo()->query('SELECT COUNT(*) FROM ctact')->fetchColumn());
    }

    public function testSigueElProximoNumeroSiElPuestoYaEstaUsadoEnElSubdiario(): void
    {
        // Como el VFP: si el número que toca ya existe (datos migrados), se salta al siguiente libre.
        Db::pdo()->exec("INSERT INTO subdiario (fecha, tipo, letra, sucursal, prefijo, comprobante, cliente) VALUES (CURDATE(), 'FC', 'A', 99, 9, 1, 990001)");
        $this->assertSame(2, $this->emitir($this->pedido())['numero']);
        $this->assertSame(2, (int) $this->fila('SELECT ultima_factura_a AS n FROM sucursales WHERE codigo = 99')['n']);
    }

    public function testListadoYFiltros(): void
    {
        $this->emitir($this->pedido());
        $this->emitir($this->pedido(['cliente' => '990002', 'forma_pago' => '1', 'fpago' => 'EF']));
        $hoy = date('d/m/Y');
        $r = $this->svc->listar(['desde' => $hoy, 'hasta' => $hoy], 1, 50);
        $this->assertSame(2, $r['total']);
        $this->assertSame(1, $this->svc->listar(['cliente' => 'consumidor'], 1, 50)['total']);
        $this->assertSame(1, $this->svc->listar(['cliente' => '990001'], 1, 50)['total'], 'por código');
        $this->assertSame(1, $this->svc->listar(['cliente' => '30-70880558-7'], 1, 50)['total'], 'por CUIT');
        $this->assertSame(0, $this->svc->listar(['cliente' => '%'], 1, 50)['total'], 'el % es literal');
        $this->assertSame(2, $this->svc->listar(['numero' => '1'], 1, 50)['total']);
        $this->assertSame(0, $this->svc->listar(['tipo' => 'NC'], 1, 50)['total']);
        $this->assertSame(0, $this->svc->listar(['desde' => '01/01/2000', 'hasta' => '02/01/2000'], 1, 50)['total']);
        $this->assertCount(1, $this->svc->asociables(990001));
    }
}
