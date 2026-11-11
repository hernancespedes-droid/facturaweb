<?php
declare(strict_types=1);

namespace Tests;

use App\Core\Db;
use App\Modules\Migracion\Dbf;
use App\Modules\Migracion\MigradorVfp;

final class MigracionTest extends DbTestCase
{
    private string $dir = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir() . '/fw_dbf_' . bin2hex(random_bytes(4));
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        foreach (glob($this->dir . '/*') ?: [] as $f) {
            unlink($f);
        }
        @rmdir($this->dir);
        $pdo = Db::pdo();
        $pdo->exec('DELETE FROM clientes');
        $pdo->exec("DELETE FROM articulos WHERE codigo LIKE 'M-%'");
        $pdo->exec("DELETE FROM vendedores WHERE descripcion LIKE '%(DEL VFP)'");
        $pdo->exec('DELETE FROM migracion_vfp');
        foreach (['subdiario', 'iva', 'estadistica', 'caja', 'ctact'] as $t) {
            $pdo->exec("DELETE FROM `{$t}`");
        }
    }

    public function testLeeTiposMemosNulosVarcharYSalteaBorrados(): void
    {
        $f = $this->dir . '/prueba.dbf';
        DbfFalso::escribir($f, [['TEXTO', 'C', 12], ['NUM', 'N', 8, 2], ['FECHA', 'D'], ['HORA', 'T'], ['SI', 'L'], ['ENTERO', 'I'], ['NOTA', 'M'], ['VAR', 'V', 10, 0, true]], [
            ['TEXTO' => 'Ñandú', 'NUM' => 12.5, 'FECHA' => '2024-02-29', 'HORA' => '2024-02-29 13:45:10', 'SI' => true, 'ENTERO' => -7, 'NOTA' => 'Texto largo del memo', 'VAR' => 'abc'],
            ['TEXTO' => 'Borrado', '_borrado' => true],
            ['TEXTO' => '', 'NUM' => null, 'FECHA' => null, 'SI' => false, 'ENTERO' => 0, 'VAR' => null],
        ]);
        $dbf = new Dbf($f);
        $filas = iterator_to_array($dbf->filas(), false);
        $this->assertCount(2, $filas, 'el borrado no se lee');
        $this->assertSame(['TEXTO' => 'Ñandú', 'NUM' => 12.5, 'FECHA' => '2024-02-29', 'HORA' => '2024-02-29 13:45:10', 'SI' => true, 'ENTERO' => -7,
            'NOTA' => 'Texto largo del memo', 'VAR' => 'abc'], $filas[0]);
        $this->assertSame(['TEXTO' => '', 'NUM' => null, 'FECHA' => null, 'HORA' => null, 'SI' => false, 'ENTERO' => 0, 'NOTA' => null, 'VAR' => null], $filas[1]);
        $this->assertSame(['TEXTO', 'NUM', 'FECHA', 'HORA', 'SI', 'ENTERO', 'NOTA', 'VAR'], $dbf->nombres());
    }

    /** Carpeta como la del VFP: DBF con nombres cortos y datos.dbc con los largos. */
    private function carpetaVfp(): void
    {
        $d = $this->dir;
        DbfFalso::dbc($d . '/datos.dbc', [
            'clientes' => ['codigo', 'razon_social', 'iva', 'cuit', 'provincia', 'vendedor', 'fecha_alta', 'consignacion', 'codigo_postal', 'emailfc'],
            'articulos' => ['codigo', 'descripcion', 'alicuota', 'moneda', 'estado', 'precio_de_venta_1', 'precio_de_venta_2'],
            'tasas_iva' => ['codigo', 'descripcion', 'porcentaje', 'actualizacion'],
            'cla_usuarios' => ['nombre', 'clave', 'supervisor'],
            'parametros' => ['empresa', 'ultimo_cliente', 'cuit', 'iva'],
        ]);
        DbfFalso::escribir($d . '/CLIENTES.DBF', [['CODIGO', 'N', 5], ['RAZON_SOCI', 'C', 40], ['IVA', 'N', 1], ['CUIT', 'N', 11], ['PROVINCIA', 'C', 2],
            ['VENDEDOR', 'C', 2], ['FECHA_ALTA', 'D'], ['CONSIGNACI', 'L'], ['CODIGO_POS', 'N', 5], ['EMAILFC', 'C', 50]], [
            ['CODIGO' => 7, 'RAZON_SOCI' => 'PEÑA SA', 'IVA' => 1, 'CUIT' => 30714357537, 'PROVINCIA' => '02', 'VENDEDOR' => 'Q9', 'FECHA_ALTA' => '2009-07-02', 'CONSIGNACI' => true, 'CODIGO_POS' => 1759, 'EMAILFC' => 'a@b.com'],
            ['CODIGO' => 9, 'RAZON_SOCI' => 'CONSUMIDOR', 'IVA' => 3, 'CUIT' => 0, 'PROVINCIA' => '', 'VENDEDOR' => 'Q9', 'CODIGO_POS' => 0],
            ['CODIGO' => 12, 'RAZON_SOCI' => 'CUIT MALO SRL', 'IVA' => 1, 'CUIT' => 30714357538, 'VENDEDOR' => 'Q9'],
        ]);
        DbfFalso::escribir($d . '/articulos.dbf', [['CODIGO', 'C', 15], ['DESCRIPCIO', 'C', 40], ['ALICUOTA', 'C', 2], ['MONEDA', 'C', 3], ['ESTADO', 'C', 2],
            ['PRECIO_DE_', 'N', 11, 4], ['PRECIO_DE2', 'N', 11, 4]], [
            ['CODIGO' => 'M-1', 'DESCRIPCIO' => 'ABONO', 'ALICUOTA' => '21', 'MONEDA' => ' $', 'ESTADO' => 'AC', 'PRECIO_DE_' => 1500.25, 'PRECIO_DE2' => 1],
            ['CODIGO' => 'M-2', 'DESCRIPCIO' => 'SIN ESTADO', 'ALICUOTA' => '27', 'MONEDA' => ' $', 'ESTADO' => ''],
        ]);
        DbfFalso::escribir($d . '/tasas_iva.dbf', [['CODIGO', 'C', 2], ['DESCRIPCIO', 'C', 30], ['PORCENTAJE', 'N', 5, 2], ['ACTUALIZAC', 'T']], [
            ['CODIGO' => '21', 'DESCRIPCIO' => 'ALICUOTA 21 %', 'PORCENTAJE' => 21, 'ACTUALIZAC' => '2005-11-25 15:20:23'],
            ['CODIGO' => '10', 'DESCRIPCIO' => 'ALICUOTA 10,5', 'PORCENTAJE' => 10.5],
            ['CODIGO' => '0', 'DESCRIPCIO' => 'SIN IVA', 'PORCENTAJE' => 0],
        ]);
        DbfFalso::escribir($d . '/cla_usuarios.dbf', [['NOMBRE', 'C', 20], ['CLAVE', 'C', 20], ['SUPERVISOR', 'L']], [
            ['NOMBRE' => 'T_ANA', 'CLAVE' => 'xyz', 'SUPERVISOR' => true], ['NOMBRE' => 't_ana', 'CLAVE' => 'abc', 'SUPERVISOR' => false],
            ['NOMBRE' => 'T_BETO', 'CLAVE' => 'q'], ['NOMBRE' => '9', 'CLAVE' => 'q'],
        ]);
        DbfFalso::escribir($d . '/parametros.dbf', [['EMPRESA', 'C', 40], ['ULTIMO_CLI', 'N', 5], ['CUIT', 'N', 11], ['IVA', 'N', 1]], [
            ['EMPRESA' => 'EMPRESA VFP SA', 'ULTIMO_CLI' => 10, 'CUIT' => 30714357537, 'IVA' => 1],
        ]);
    }

    public function testMigraConNombresLargosConvierteYAvisa(): void
    {
        $this->carpetaVfp();
        $antes = Db::pdo()->query('SELECT empresa, cuit, iva, ultimo_cliente FROM parametros WHERE id = 1')->fetch();
        try {
            $r = (new MigradorVfp(Db::pdo(), $this->dir))->migrar(['tasas_iva', 'clientes', 'articulos', 'parametros', 'usuarios'], true);
            $pdo = Db::pdo();
            $this->assertSame(3, $r['clientes']['grabadas']);
            $c = $pdo->query('SELECT * FROM clientes WHERE codigo = 7')->fetch();
            $this->assertSame('PEÑA SA', $c['razon_social']);
            $this->assertSame('30714357537', (string) $c['cuit']);
            $this->assertSame('2009-07-02', $c['fecha_alta']);
            $this->assertSame(1, (int) $c['consignacion']);
            $this->assertSame('1759', $c['codigo_postal']);
            $c9 = $pdo->query('SELECT cuit, provincia, codigo_postal FROM clientes WHERE codigo = 9')->fetch();
            $this->assertSame(['cuit' => null, 'provincia' => null, 'codigo_postal' => null], $c9, '0 y vacío del VFP => sin dato');

            $avisos = implode("\n", $r['clientes']['avisos']);
            $this->assertStringContainsString('cliente 12 CUIT MALO SRL: el CUIT 30714357538 no es válido', $avisos);
            $this->assertStringContainsString('vendedor "Q9" lo usan 3 cliente(s)', $avisos);
            $this->assertSame('VENDEDOR Q9 (DEL VFP)', $pdo->query("SELECT descripcion FROM vendedores WHERE codigo = 'Q9'")->fetchColumn());

            $a = $pdo->query("SELECT * FROM articulos WHERE codigo = 'M-1'")->fetch();
            $this->assertSame(['$', '1500.2500', '1.0000'], [$a['moneda'], $a['precio_de_venta_1'], $a['precio_de_venta_2']]);
            $this->assertSame('AC', $pdo->query("SELECT estado FROM articulos WHERE codigo = 'M-2'")->fetchColumn(), 'vacío => valor por omisión');
            $this->assertStringContainsString('alicuota "27" lo usan 1 artículo(s) y no está en tasas_iva', implode("\n", $r['articulos']['avisos']));
            $this->assertSame('2005-11-25 15:20:23', $pdo->query("SELECT actualizacion FROM tasas_iva WHERE codigo = '21'")->fetchColumn());

            $p = $pdo->query('SELECT empresa, ultimo_cliente FROM parametros WHERE id = 1')->fetch();
            $this->assertSame(['empresa' => 'EMPRESA VFP SA', 'ultimo_cliente' => 12], $p, 'el próximo cliente sigue después del mayor código');

            $u = $pdo->query("SELECT usuario, activo, debe_cambiar_clave, usuario_vfp, r.codigo AS rol FROM usuarios JOIN roles r ON r.id = rol_id WHERE usuario LIKE 't\\_%' ORDER BY usuario")->fetchAll();
            $this->assertSame([
                ['usuario' => 't_ana', 'activo' => 0, 'debe_cambiar_clave' => 1, 'usuario_vfp' => 'T_ANA', 'rol' => 'admin'],
                ['usuario' => 't_beto', 'activo' => 0, 'debe_cambiar_clave' => 1, 'usuario_vfp' => 'T_BETO', 'rol' => 'operador'],
            ], $u);
            $this->assertStringContainsString('"9" no se migra', implode("\n", $r['usuarios']['avisos']));
            $this->assertSame(1, (int) $pdo->query('SELECT COUNT(*) FROM migracion_vfp')->fetchColumn(), 'queda registrada');
        } finally {
            Db::pdo()->prepare('UPDATE parametros SET empresa = ?, cuit = ?, iva = ?, ultimo_cliente = ? WHERE id = 1')
                ->execute([$antes['empresa'], $antes['cuit'], $antes['iva'], $antes['ultimo_cliente']]);
        }
    }

    public function testNoPisaDatosSinReemplazarYSimularNoGrabaNada(): void
    {
        $this->carpetaVfp();
        Db::pdo()->exec("INSERT INTO clientes (codigo, razon_social, iva) VALUES (500, 'CARGADO EN LA WEB', 3)");
        $m = new MigradorVfp(Db::pdo(), $this->dir);
        try {
            $m->migrar(['clientes']);
            $this->fail('tenía que frenar');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('ya tienen datos: clientes', $e->getMessage());
        }
        $sql = $this->dir . '/salida.sql';
        $r = (new MigradorVfp(Db::pdo(), $this->dir))->migrar(['clientes'], true, true, $sql);
        $this->assertSame(3, $r['clientes']['grabadas']);
        $this->assertSame('CARGADO EN LA WEB', Db::pdo()->query('SELECT razon_social FROM clientes WHERE codigo = 500')->fetchColumn(), 'la simulación se deshace');
        $this->assertSame(0, (int) Db::pdo()->query('SELECT COUNT(*) FROM clientes WHERE codigo = 7')->fetchColumn());
        $texto = (string) file_get_contents($sql);
        $this->assertStringContainsString('DELETE FROM `clientes`', $texto);
        $this->assertStringContainsString("'PEÑA SA'", $texto);
        $this->assertStringContainsString('COMMIT;', $texto);
    }

    public function testComprobantesConNumeroRepetidoSeVinculanPorFechaYElIvaPorElNeto(): void
    {
        $d = $this->dir;
        DbfFalso::dbc($d . '/datos.dbc', [
            'subdiario' => ['fecha', 'tipo', 'letra', 'sucursal', 'prefijo', 'comprobante', 'cliente', 'neto_grabado', 'iva_1', 'neto', 'fe_clave'],
            'estadistica' => ['fecha', 'tipo', 'letra', 'sucursal', 'comprobante', 'total_item', 'observaciones', 'fe_clave'],
            'iva' => ['tipo', 'letra', 'sucursal', 'comprobante', 'alicuota', 'monto', 'importe', 'fe_clave'],
            'caja' => ['fecha', 'tipo', 'letra', 'sucursal', 'comprobante', 'p_fpago', 'p_importe', 'fe_clave'],
            'ctact' => ['cliente', 'fecha', 'tipo', 'tico', 'letra', 'sucursal', 'numero', 'neto', 'saldo', 'fe_clave'],
        ]);
        $cab = [['FECHA', 'D'], ['TIPO', 'C', 2], ['LETRA', 'C', 1], ['SUCURSAL', 'N', 2], ['PREFIJO', 'N', 4], ['COMPROBANT', 'N', 7], ['CLIENTE', 'N', 5],
            ['NETO_GRABA', 'N', 11, 2], ['IVA_1', 'N', 11, 2], ['NETO', 'N', 11, 2], ['FE_CLAVE', 'I']];
        $fc = fn (string $f, int $n, float $neto, int $clave) => ['FECHA' => $f, 'TIPO' => 'FC', 'LETRA' => 'A', 'SUCURSAL' => 1, 'PREFIJO' => 4, 'COMPROBANT' => $n, 'CLIENTE' => 5,
            'NETO_GRABA' => $neto, 'IVA_1' => $neto * 0.21, 'NETO' => $neto * 1.21, 'FE_CLAVE' => $clave];
        DbfFalso::escribir($d . '/subdiario.dbf', $cab, [$fc('2019-01-02', 5, 100, 1001), $fc('2019-03-04', 5, 200, 1002), $fc('2019-03-05', 6, 50, 1003)]);
        DbfFalso::escribir($d . '/estadistica.dbf', [['FECHA', 'D'], ['TIPO', 'C', 2], ['LETRA', 'C', 1], ['SUCURSAL', 'N', 2], ['COMPROBANT', 'N', 7], ['TOTAL_ITEM', 'N', 11, 2], ['OBSERVACIO', 'M'], ['FE_CLAVE', 'I']], [
            ['FECHA' => '2019-01-02', 'TIPO' => 'FC', 'LETRA' => 'A', 'SUCURSAL' => 1, 'COMPROBANT' => 5, 'TOTAL_ITEM' => 100, 'OBSERVACIO' => str_repeat("Línea larga\n", 40), 'FE_CLAVE' => 7],
            ['FECHA' => '2019-03-04', 'TIPO' => 'FC', 'LETRA' => 'A', 'SUCURSAL' => 1, 'COMPROBANT' => 5, 'TOTAL_ITEM' => 200, 'OBSERVACIO' => 'otro', 'FE_CLAVE' => 7],
            ['FECHA' => '2018-01-01', 'TIPO' => 'FC', 'LETRA' => 'A', 'SUCURSAL' => 1, 'COMPROBANT' => 99, 'TOTAL_ITEM' => 1, 'OBSERVACIO' => 'huérfano', 'FE_CLAVE' => 8],
        ]);
        // el IVA se carga en otro orden y sin fecha: se vincula por el neto
        DbfFalso::escribir($d . '/iva.dbf', [['TIPO', 'C', 2], ['LETRA', 'C', 1], ['SUCURSAL', 'N', 2], ['COMPROBANT', 'N', 7], ['ALICUOTA', 'N', 5, 2], ['MONTO', 'N', 10, 2], ['IMPORTE', 'N', 10, 2], ['FE_CLAVE', 'N', 14]], [
            ['TIPO' => 'FC', 'LETRA' => 'A', 'SUCURSAL' => 1, 'COMPROBANT' => 5, 'ALICUOTA' => 21, 'MONTO' => 200, 'IMPORTE' => 42, 'FE_CLAVE' => 3],
            ['TIPO' => 'FC', 'LETRA' => 'A', 'SUCURSAL' => 1, 'COMPROBANT' => 5, 'ALICUOTA' => 21, 'MONTO' => 100, 'IMPORTE' => 21, 'FE_CLAVE' => 3],
            ['TIPO' => 'FC', 'LETRA' => 'A', 'SUCURSAL' => 1, 'COMPROBANT' => 6, 'ALICUOTA' => 21, 'MONTO' => 50, 'IMPORTE' => 10.5, 'FE_CLAVE' => 3],
            ['TIPO' => 'FC', 'LETRA' => 'A', 'SUCURSAL' => 1, 'COMPROBANT' => 77, 'ALICUOTA' => 21, 'MONTO' => 9, 'IMPORTE' => 1.89, 'FE_CLAVE' => 4],
        ]);
        DbfFalso::escribir($d . '/caja.dbf', [['FECHA', 'D'], ['TIPO', 'C', 2], ['LETRA', 'C', 1], ['SUCURSAL', 'N', 2], ['COMPROBANT', 'N', 7], ['P_FPAGO', 'C', 2], ['P_IMPORTE', 'N', 11, 2], ['FE_CLAVE', 'I']], [
            ['FECHA' => '2019-03-04', 'TIPO' => 'FC', 'LETRA' => 'A', 'SUCURSAL' => 1, 'COMPROBANT' => 5, 'P_FPAGO' => 'CC', 'P_IMPORTE' => 242, 'FE_CLAVE' => 9],
        ]);
        DbfFalso::escribir($d . '/ctact.dbf', [['CLIENTE', 'N', 5], ['FECHA', 'D'], ['TIPO', 'C', 2], ['TICO', 'N', 1], ['LETRA', 'C', 1], ['SUCURSAL', 'N', 2], ['NUMERO', 'N', 8], ['NETO', 'N', 10, 2], ['SALDO', 'N', 10, 2], ['FE_CLAVE', 'I']], [
            ['CLIENTE' => 5, 'FECHA' => '2019-01-02', 'TIPO' => 'FC', 'TICO' => 1, 'LETRA' => 'A', 'SUCURSAL' => 1, 'NUMERO' => 5, 'NETO' => 121, 'SALDO' => 121, 'FE_CLAVE' => 1],
            ['CLIENTE' => 5, 'FECHA' => '2019-04-01', 'TIPO' => 'AC', 'TICO' => 9, 'LETRA' => 'A', 'SUCURSAL' => 1, 'NUMERO' => 5, 'NETO' => 30, 'SALDO' => 30, 'FE_CLAVE' => 1],
        ]);

        $r = (new MigradorVfp(Db::pdo(), $d))->migrar(['subdiario', 'iva', 'estadistica', 'caja', 'ctact'], true);
        $pdo = Db::pdo();
        $this->assertSame(3, $r['subdiario']['grabadas'], 'los dos números 5 de distinta fecha entran');
        $this->assertSame(3, $r['estadistica']['grabadas'], 'el fe_clave repetido del VFP no se usa como clave');
        $id = static fn (string $f) => (int) Db::pdo()->query("SELECT fe_clave FROM subdiario WHERE comprobante = 5 AND fecha = '{$f}'")->fetchColumn();
        $primero = $id('2019-01-02');
        $segundo = $id('2019-03-04');
        $this->assertTrue($primero > 0 && $segundo > 0 && $primero !== $segundo);
        $item = static fn (int $sub) => (string) Db::pdo()->query("SELECT total_item FROM estadistica WHERE subdiario_id = {$sub}")->fetchColumn();
        $this->assertSame('100.00', $item($primero));
        $this->assertSame('200.00', $item($segundo));
        $ivaDe = static fn (int $sub) => (string) Db::pdo()->query("SELECT importe FROM iva WHERE subdiario_id = {$sub}")->fetchColumn();
        $this->assertSame('21.00', $ivaDe($primero), 'el IVA de monto 100 es del comprobante de neto 100');
        $this->assertSame('42.00', $ivaDe($segundo));
        $this->assertSame(1, (int) $pdo->query("SELECT COUNT(*) FROM caja WHERE subdiario_id = {$segundo}")->fetchColumn(), 'la caja se une por fecha');
        $this->assertSame(1, (int) $pdo->query("SELECT COUNT(*) FROM ctact WHERE subdiario_id = {$primero}")->fetchColumn());
        $this->assertSame(1, (int) $pdo->query("SELECT COUNT(*) FROM ctact WHERE subdiario_id IS NULL AND tipo = 'AC'")->fetchColumn(), 'los pagos a cuenta no son comprobantes');
        $avisos = implode("\n", $r['vinculos']['avisos']);
        $this->assertStringContainsString('1 fila(s) de estadistica no tienen comprobante', $avisos);
        $this->assertStringContainsString('1 fila(s) de iva no tienen comprobante', $avisos);
        $this->assertStringContainsString('vincularon por el neto', $avisos);
        $this->assertStringNotContainsString('ctact no tienen', $avisos);
        // la descripción larga entra completa y se puede imprimir
        $largo = (string) $pdo->query("SELECT observaciones FROM estadistica WHERE subdiario_id = {$primero}")->fetchColumn();
        $this->assertTrue(mb_strlen($largo) > 255);
        $svc = new \App\Modules\Comprobantes\ComprobantesService();
        $h = $svc->obtener($primero);
        $this->assertCount(1, $h['items']);
        $pdf = \App\Modules\Comprobantes\ComprobantePdf::generar(\App\Modules\Comprobantes\ComprobanteImpreso::datos($h, $svc->emisor()));
        $this->assertStringStartsWith('%PDF', $pdf);
    }
}
