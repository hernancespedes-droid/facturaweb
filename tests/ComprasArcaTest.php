<?php
declare(strict_types=1);

namespace Tests;

use App\Core\Db;
use App\Modules\Arca\Padron;
use App\Modules\Compras\ComprasListados;
use App\Modules\Compras\ComprasService;
use App\Modules\Compras\ComprobanteArca;
use App\Modules\Compras\LibroIvaCompras;
use App\Modules\Compras\MisComprobantes;
use App\Modules\Compras\ProveedoresService;

/** Compras, entrega C (0.47.0): QR de la factura, Mis Comprobantes de ARCA, Libro IVA Digital de compras y resultado del mes. */
final class ComprasArcaTest extends ComprobantesTestCase
{
    private ComprasService $c;
    private int $admin;
    private int $prov;
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->limpiar();
        $this->c = new ComprasService();
        $this->admin = $this->crearUsuario('t_carca_admin', 'admin');
        $r = (new ProveedoresService())->guardar(null, ['codigo' => '99021', 'razon_social' => 'T MAYORISTA ARCA SA', 'cuit' => '30708805587', 'iva' => '1', 'plazo_dias' => '15']);
        $this->assertTrue($r['ok'], json_encode($r['errores'] ?? []));
        $this->prov = $r['id'];
        $this->dir = sys_get_temp_dir() . '/fw_carca_' . bin2hex(random_bytes(4));
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        $this->limpiar();
        Padron::$transporte = null;
        array_map('unlink', glob($this->dir . '/*') ?: []);
        @rmdir($this->dir);
        parent::tearDown();
    }

    private function limpiar(): void
    {
        $pdo = Db::pdo();
        foreach (['compras', 'compras_iva', 'compras_tributos', 'compras_reparto', 'compras_adjuntos', 'compras_pagos', 'compras_items', 'compras_arca'] as $t) {
            $pdo->exec("DELETE FROM {$t}");
        }
        $pdo->exec("DELETE FROM proveedores WHERE razon_social LIKE 'T %' OR razon_social LIKE 'PROVEEDOR CUIT%' OR cuit IN (30711111111, 33555555550, 20222222223)");
    }

    private function compra(array $extra = []): int
    {
        $a = $this->c->armar($extra + ['proveedor' => (string) $this->prov, 'tipo' => 'FC', 'letra' => 'A', 'punto_venta' => '3', 'numero' => (string) random_int(1, 99999999),
            'fecha' => date('d/m/Y'), 'neto' => ['21' => '1.000,00'], 'forma' => 'C']);
        $this->assertSame([], $a['errores'], 'sin errores');
        $g = $this->c->grabar($a['compra'], $this->admin, bin2hex(random_bytes(16)));
        $this->assertTrue($g['ok'], $g['error'] ?? '');
        return $g['id'];
    }

    private static function qr(array $d): string
    {
        return 'https://www.afip.gob.ar/fe/qr/?p=' . base64_encode((string) json_encode($d + ['ver' => 1, 'fecha' => date('Y-m-d'), 'cuit' => 30711111111, 'ptoVta' => 7, 'tipoCmp' => 1,
            'nroCmp' => 1234, 'importe' => 12100, 'moneda' => 'PES', 'ctz' => 1, 'tipoDocRec' => 80, 'nroDocRec' => 30714357537, 'tipoCodAut' => 'E', 'codAut' => 70417054367476]));
    }

    public function testLeeElQrDeLaFactura(): void
    {
        $x = ComprobanteArca::desdeQr(self::qr([]));
        $this->assertSame(['30711111111', 1, 7, 1234, 12100.0, '70417054367476', '30714357537'],
            [$x['cuit'], $x['tipo_cmp'], $x['punto_venta'], $x['numero'], $x['total'], $x['cae'], $x['receptor']]);
        $this->assertSame(6, ComprobanteArca::desdeQr(rtrim(strtr(base64_encode((string) json_encode(['fecha' => '2026-01-02', 'cuit' => '20222222223', 'ptoVta' => 1,
            'tipoCmp' => 6, 'nroCmp' => 9, 'importe' => 50])), '+/', '-_'), '='))['tipo_cmp'], 'el base64 solo, sin relleno y url-safe');
        $this->assertNull(ComprobanteArca::desdeQr('https://www.google.com'));
        $this->assertNull(ComprobanteArca::desdeQr(self::qr(['tipoCmp' => 99])), 'tipo que compras no maneja');
        $this->assertSame([1, 6, 11, 3, null], [ComprobanteArca::codigo('FC', 'A'), ComprobanteArca::codigo('FC', 'B'), ComprobanteArca::codigo('FC', 'C'),
            ComprobanteArca::codigo('NC', 'A'), ComprobanteArca::codigo('GS', '')]);
    }

    public function testQrAbreLaCompraYDaDeAltaElProveedor(): void
    {
        $this->loginComo($this->admin);
        $r = $this->post('/compras/qr', ['qr' => self::qr([])]);
        $this->assertSame(200, $r->status, $r->body);
        $p = ComprobanteArca::proveedor('30711111111');
        $this->assertNotNull($p, 'da de alta el proveedor');
        $this->assertSame('PROVEEDOR CUIT 30-71111111-1', $p['razon_social'], 'sin padrón, un nombre para corregir');
        foreach (['Datos leídos del QR', 'Se dio de alta el proveedor', 'value="1234"', 'value="12.100,00"', '70417054367476', 'Completá el neto por alícuota'] as $txt) {
            $this->assertStringContainsString($txt, $r->body);
        }
        $this->assertSame(422, $this->post('/compras/qr', ['qr' => 'cualquier cosa'])->status);
        // con el padrón: toma la razón social; y una factura C propone el importe
        Padron::$transporte = static fn (string $cuit) => ['cuit' => $cuit, 'encontrado' => true, 'razon_social' => 'T MONOTRIBUTISTA DEL PADRON', 'iva' => 7,
            'domicilio' => 'CALLE 123', 'localidad' => 'MORON', 'codigo_postal' => '', 'provincia' => '', 'tipo_persona' => 'FISICA', 'estado' => 'ACTIVO',
            'condicion' => '', 'monotributo' => '', 'impuestos' => [], 'actividades' => [], 'avisos' => []];
        $r = $this->post('/compras/qr', ['qr' => self::qr(['cuit' => 20222222223, 'tipoCmp' => 11, 'importe' => 5000, 'nroDocRec' => 30111111118])]);
        $this->assertSame(200, $r->status);
        $this->assertSame(['T MONOTRIBUTISTA DEL PADRON', 7], [ComprobanteArca::proveedor('20222222223')['razon_social'], (int) ComprobanteArca::proveedor('20222222223')['iva']]);
        $this->assertStringContainsString('value="5.000,00"', $r->body);
        $this->assertStringContainsString('a nombre de otro CUIT', $r->body, 'avisa si la factura no es de la empresa');
        // ya cargada: va a la compra
        $id = $this->compra(['punto_venta' => '7', 'numero' => '1234', 'proveedor' => (string) ComprobanteArca::proveedor('30711111111')['id']]);
        $this->assertRedirect('/compras/ver/' . $id, $this->post('/compras/qr', ['qr' => self::qr([])]));
    }

    private function archivoArca(array $renglones): string
    {
        $f = $this->dir . '/mis_comprobantes.csv';
        $l = ['"Mis Comprobantes Recibidos - CUIT 30714357537"',
            '"Fecha";"Tipo";"Punto de Venta";"Número Desde";"Número Hasta";"Cód. Autorización";"Tipo Doc. Emisor";"Nro. Doc. Emisor";"Denominación Emisor";"Tipo Cambio";"Moneda";"Imp. Neto Gravado";"Imp. Neto No Gravado";"Imp. Op. Exentas";"Otros Tributos";"IVA";"Imp. Total"'];
        foreach ($renglones as $r) {
            $l[] = implode(';', array_map(static fn ($v) => '"' . $v . '"', $r));
        }
        file_put_contents($f, implode("\r\n", $l) . "\r\n");
        return $f;
    }

    public function testMisComprobantesControlaContraLoCargado(): void
    {
        $hoy = date('d/m/Y');
        $ok = $this->compra(['punto_venta' => '3', 'numero' => '100']);       // 1.210
        $dif = $this->compra(['punto_venta' => '3', 'numero' => '101']);      // 1.210 y ARCA dice 1.500
        $sobra = $this->compra(['punto_venta' => '3', 'numero' => '999']);    // no está en ARCA
        $this->compra(['tipo' => 'GS', 'importe' => '300', 'numero' => '']);  // gasto sin comprobante: no se controla
        $f = $this->archivoArca([
            [$hoy, '1 - Factura A', '3', '100', '100', '7001', 'CUIT', '30708805587', 'T MAYORISTA ARCA SA', '1', '$', '1000,00', '0,00', '0,00', '0,00', '210,00', '1210,00'],
            [$hoy, '1 - Factura A', '3', '101', '101', '7002', 'CUIT', '30708805587', 'T MAYORISTA ARCA SA', '1', '$', '1239,67', '0,00', '0,00', '0,00', '260,33', '1500,00'],
            [$hoy, '1 - Factura A', '5', '77', '77', '7003', 'CUIT', '33555555550', 'NUEVO PROVEEDOR SRL', '1', '$', '2000,00', '100,00', '0,00', '0,00', '420,00', '2520,00'],
            [$hoy, '6 - Factura B', '2', '8', '8', '7004', 'CUIT', '30708805587', 'T MAYORISTA ARCA SA', '1', '$', '0,00', '0,00', '0,00', '0,00', '0,00', '605,00'],
            [$hoy, '19 - Factura E', '1', '1', '1', '', 'CUIT', '30708805587', 'X', '1', '$', '0', '0', '0', '0', '0', '1'],
        ]);
        $l = MisComprobantes::leer($f, 'mis.csv');
        $this->assertTrue($l['ok'], $l['error'] ?? '');
        $this->assertCount(4, $l['filas']);
        $this->assertCount(1, $l['errores'], 'la factura E no se toma');
        $this->assertSame([2000.0, 100.0, 420.0, 2520.0], [$l['filas'][2]['neto'], $l['filas'][2]['no_gravado'], $l['filas'][2]['iva'], $l['filas'][2]['total']]);
        $g = MisComprobantes::guardar($l['filas']);
        $this->assertSame(4, $g['nuevos']);
        $this->assertSame(0, MisComprobantes::guardar($l['filas'])['nuevos'], 'volver a importar no duplica');
        $r = MisComprobantes::control(date('Y-m-01'), date('Y-m-t'));
        $this->assertSame(['falta' => 2, 'diferencia' => 1, 'ok' => 1], $r['cuenta']);
        $this->assertSame([$sobra], array_map(static fn ($x) => (int) $x['id'], $r['sobrantes']), 'lo cargado que ARCA no tiene (sin el gasto sin comprobante)');
        $porNumero = array_column($r['filas'], null, 'numero');
        $this->assertSame([$ok, $dif], [$porNumero[100]['compra_id'], $porNumero[101]['compra_id']]);
        $this->assertCount(2, MisComprobantes::control(date('Y-m-01'), date('Y-m-t'), 'falta')['filas']);

        // por la pantalla: importar y cargar el que falta (proveedor nuevo con la denominación de ARCA, neto e IVA propuestos)
        $this->loginComo($this->admin);
        $this->assertStringContainsString('Falta cargar', $this->get('/compras/mis-comprobantes')->body);
        $id = (int) Db::pdo()->query('SELECT id FROM compras_arca WHERE cuit = 33555555550')->fetchColumn();
        $res = $this->post('/compras/desde-arca', ['id' => (string) $id]);
        $this->assertSame(200, $res->status);
        $this->assertSame('NUEVO PROVEEDOR SRL', ComprobanteArca::proveedor('33555555550')['razon_social']);
        foreach (['Datos de Mis Comprobantes', 'value="2.000,00"', 'value="420,00"', 'value="100,00"', 'value="2.520,00"'] as $txt) {
            $this->assertStringContainsString($txt, $res->body);
        }
        $this->assertRedirect('/compras/ver/' . $ok, $this->post('/compras/desde-arca', ['id' => (string) Db::pdo()->query('SELECT id FROM compras_arca WHERE numero = 100')->fetchColumn()]));
        $mal = $this->dir . '/otra.csv';
        file_put_contents($mal, "a;b;c\n1;2;3\n");
        $this->assertStringContainsString('Mis Comprobantes', MisComprobantes::leer($mal, 'otra.csv')['error']);
    }

    public function testLibroIvaDigitalDeCompras(): void
    {
        $periodo = date('Y-m');
        $this->compra(['numero' => '55', 'neto' => ['21' => '1000', '10.5' => '200'], 'trib' => [['tipo' => 'IVA', 'importe' => '30'], ['tipo' => 'IIBB', 'importe' => '20']]]);
        $mono = (new ProveedoresService())->guardar(null, ['razon_social' => 'T MONOTRIBUTO LIBRO', 'cuit' => '20111111112', 'iva' => '7'])['id'];
        $this->compra(['proveedor' => (string) $mono, 'letra' => 'C', 'numero' => '9', 'importe' => '500']);
        $this->compra(['tipo' => 'NC', 'numero' => '56', 'neto' => ['21' => '100']]);
        $this->compra(['tipo' => 'GS', 'importe' => '300', 'numero' => '']);
        $l = LibroIvaCompras::generar($periodo);
        $this->assertSame([3, 1], [$l['cantidad'], $l['sin_comprobante']]);
        $cb = explode("\r\n", rtrim($l['cbte'], "\r\n"));
        $al = explode("\r\n", rtrim($l['alicuotas'], "\r\n"));
        $this->assertCount(3, $cb);
        $this->assertCount(3, $al, 'dos alícuotas de la A, una de la NC, ninguna de la C');
        foreach ($cb as $x) {
            $this->assertSame(LibroIvaCompras::LARGO_CBTE, strlen($x));
        }
        foreach ($al as $x) {
            $this->assertSame(LibroIvaCompras::LARGO_ALICUOTA, strlen($x));
        }
        $fa = $cb[0];
        $this->assertSame(['001', '00003', str_pad('55', 20, '0', STR_PAD_LEFT), '80', str_pad('30708805587', 20, '0', STR_PAD_LEFT)],
            [substr($fa, 8, 3), substr($fa, 11, 5), substr($fa, 16, 20), substr($fa, 52, 2), substr($fa, 54, 20)]);
        $this->assertSame(str_pad('148100', 15, '0', STR_PAD_LEFT), substr($fa, 104, 15), 'total 1.481,00 (1.000 + 210 + 200 + 21 + 30 + 20)');
        $this->assertSame([str_pad('3000', 15, '0', STR_PAD_LEFT), str_pad('2000', 15, '0', STR_PAD_LEFT)], [substr($fa, 149, 15), substr($fa, 179, 15)], 'percepciones de IVA e IIBB');
        $this->assertSame(['PES', '0001000000', '2', ' ', str_pad('23100', 15, '0', STR_PAD_LEFT)], [substr($fa, 224, 3), substr($fa, 227, 10), substr($fa, 237, 1), substr($fa, 238, 1), substr($fa, 239, 15)]);
        $this->assertSame(['0005', str_pad('21000', 15, '0', STR_PAD_LEFT)], [substr($al[0], 65, 4), substr($al[0], 69, 15)]);
        $fc = $cb[1];
        $this->assertSame(['011', '0', str_pad('50000', 15, '0', STR_PAD_LEFT)], [substr($fc, 8, 3), substr($fc, 237, 1), substr($fc, 119, 15)], 'la C sin alícuotas y con el total como no gravado');
        $this->assertSame('003', substr($cb[2], 8, 3));
        $this->assertNull(LibroIvaCompras::generar('2026-13'));
        $this->loginComo($this->admin);
        $r = $this->get('/compras/iva/libro', ['periodo' => $periodo, 'archivo' => 'cbte']);
        $this->assertSame([200, $l['cbte']], [$r->status, $r->body]);
        $this->assertStringContainsString('Libro IVA Digital (compras)', $this->get('/compras/iva', ['periodo' => $periodo])->body);
        $this->assertSame(404, $this->get('/compras/iva/libro', ['periodo' => $periodo, 'archivo' => 'otro'])->status);
    }

    public function testResultadoDelMes(): void
    {
        $this->compra(['neto' => ['21' => '1000']]); // costo 1.000
        $this->compra(['tipo' => 'NC', 'neto' => ['21' => '100']]);
        $r = ComprasListados::resultado(date('Y-m'));
        $mes = end($r['meses']);
        $this->assertCount(12, $r['meses']);
        $this->assertSame([date('Y-m'), 900.0, round($mes['ventas'] - 900, 2)], [$mes['mes'], $mes['compras'], $mes['resultado']]);
        $this->assertSame(900.0, array_sum(array_column($r['conceptos'], 'importe')));
        $this->loginComo($this->admin);
        $this->assertStringContainsString('Compras y gastos', $this->get('/compras/resultado')->body);
    }

    public function testPermisos(): void
    {
        $oper = $this->crearUsuario('t_carca_oper', 'operador');
        $this->loginComo($oper);
        foreach (['/compras/qr', '/compras/mis-comprobantes', '/compras/resultado', '/compras/iva/libro'] as $ruta) {
            $this->assertSame(403, $this->get($ruta)->status, "el operador no entra a {$ruta}");
        }
        $this->assertSame(403, $this->post('/compras/qr', ['qr' => self::qr([])])->status);
        $this->assertSame(403, $this->post('/compras/desde-arca', ['id' => '1'])->status);
        $this->assertNull(ComprobanteArca::proveedor('30711111111'), 'no dio de alta nada');
        $this->loginComo($this->admin);
        foreach (['/compras/qr', '/compras/mis-comprobantes', '/compras/resultado'] as $ruta) {
            $this->assertSame(200, $this->get($ruta)->status, $ruta);
        }
    }
}
