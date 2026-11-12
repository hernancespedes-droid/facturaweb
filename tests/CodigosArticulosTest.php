<?php
declare(strict_types=1);

namespace Tests;

use App\Core\Db;
use App\Core\Validador;
use App\Modules\Articulos\ArticulosService;
use App\Modules\Comprobantes\ComprobanteImpreso;
use App\Modules\Comprobantes\ComprobantePdf;

/** EAN-13, DUN-14, SKU, unidades por bulto, buscador de renglones y texto adicional por renglón. */
final class CodigosArticulosTest extends ComprobantesTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Db::pdo()->exec("UPDATE articulos SET sku = 'SKU-T-1', ean13 = '7790895000782', dun14 = '17790895000789', unidades_por_bulto = 12, descripcion = 'TORNILLO HEXAGONAL ACERO' WHERE codigo = 'T-ART'");
    }

    private ?int $usuario = null;

    private function buscar(array $q): array
    {
        $this->usuario ??= $this->crearUsuario('t_op', 'operador');
        $this->loginComo($this->usuario);
        $r = $this->get('/comprobantes/buscar-articulo', $q);
        $this->assertSame(200, $r->status);
        return json_decode($r->body, true);
    }

    public function testListadoParaLaVentanaDeBusqueda(): void
    {
        $d = $this->buscar(['listar' => '1']);
        $this->assertTrue(isset($d['items']));
        $this->assertTrue(isset($d['hay_mas']));
        $this->assertContains('T-ART', array_column($d['items'], 'codigo'), 'sin filtro lista los artículos activos');
        $f = $this->buscar(['listar' => '1', 'q' => 'tornillo hexag']);
        $this->assertSame(['T-ART'], array_column($f['items'], 'codigo'), 'filtra por palabras de la descripción');
        $this->assertSame([], $this->buscar(['listar' => '1', 'q' => 'zzzinexistente'])['items']);
        $this->assertFalse($this->buscar(['listar' => '1', 'pagina' => '999'])['hay_mas']);
    }

    public function testListadoDeClientesParaLaVentanaDeBusqueda(): void
    {
        $this->usuario ??= $this->crearUsuario('t_op', 'operador');
        $this->loginComo($this->usuario);
        $r = $this->get('/comprobantes/buscar-cliente', ['listar' => '1']);
        $this->assertSame(200, $r->status);
        $d = json_decode($r->body, true);
        $this->assertTrue(isset($d['items']) && isset($d['hay_mas']));
        $this->assertTrue(in_array('T CLIENTE RI SA', array_column($d['items'], 'razon_social'), true), 'sin filtro lista los clientes activos');
        $f = json_decode($this->get('/comprobantes/buscar-cliente', ['listar' => '1', 'q' => 'monotrib'])->body, true);
        $this->assertSame(['T MONOTRIBUTISTA'], array_column($f['items'], 'razon_social'), 'filtra por nombre');
        $this->assertTrue(isset($f['items'][0]['iva_texto'], $f['items'][0]['letra']), 'trae lo mismo que el buscador');
        $this->assertSame([], json_decode($this->get('/comprobantes/buscar-cliente', ['listar' => '1', 'q' => 'zzzinexistente'])->body, true)['items']);
        $this->assertFalse(json_decode($this->get('/comprobantes/buscar-cliente', ['listar' => '1', 'pagina' => '999'])->body, true)['hay_mas']);
    }

    public function testCamposDeCodigosSeValidanYSeGuardan(): void
    {
        $campos = ArticulosService::campos();
        foreach (['ean13', 'dun14', 'sku', 'unidades_por_bulto'] as $c) {
            $this->assertTrue(isset($campos[$c]), "existe el campo {$c}");
        }
        $base = ['descripcion' => 'X', 'estado' => 'AC', 'alicuota' => '21', 'moneda' => '$'];
        [$val, $ok] = Validador::validar($campos, $base + ['ean13' => '7790895000782', 'dun14' => '17790895000789', 'sku' => 'ABC-1', 'unidades_por_bulto' => '12']);
        $this->assertSame([], $ok, 'códigos correctos');
        $this->assertSame([12, '7790895000782'], [$val['unidades_por_bulto'], $val['ean13']]);
        [, $mal] = Validador::validar($campos, $base + ['ean13' => '7790895000781', 'dun14' => '123']);
        $this->assertTrue(isset($mal['ean13']) && isset($mal['dun14']), 'dígito verificador y largo');
        $this->assertTrue(Validador::gs1Valido('7790895000782'));
        $this->assertFalse(Validador::gs1Valido('7790895000783'));
    }

    public function testBuscadorEncuentraPorCodigoBarrasSkuYDescripcion(): void
    {
        $this->assertSame('T-ART', $this->buscar(['q' => '7790895000782', 'exacto' => '1'])[0]['codigo'], 'por EAN-13');
        $this->assertSame('T-ART', $this->buscar(['q' => 'SKU-T-1', 'exacto' => '1'])[0]['codigo'], 'por SKU');
        $this->assertSame('T-ART', $this->buscar(['q' => 't-art', 'exacto' => '1'])[0]['codigo'], 'por código interno');
        $this->assertSame('T-ART', $this->buscar(['q' => 'sku-t'])[0]['codigo'], 'sugerencia por SKU');
        $this->assertSame('T-ART', $this->buscar(['q' => 'hexag acero'])[0]['codigo'], 'sugerencia por palabras de la descripción');
        $this->assertSame([], $this->buscar(['q' => 'zzzzinexistente']), 'sin coincidencias');
        $bulto = $this->buscar(['q' => '17790895000789', 'exacto' => '1']);
        $this->assertSame(12, $bulto[0]['cantidad'], 'el DUN-14 trae las unidades por bulto');
        $this->assertNull($this->buscar(['q' => '7790895000782', 'exacto' => '1'])[0]['cantidad'], 'el EAN-13 no cambia la cantidad');
    }

    public function testRenglonAceptaCodigoDeBarrasYGuardaTextoAdicional(): void
    {
        $pedido = $this->pedido(['items' => [['articulo' => '7790895000782', 'descripcion' => '', 'texto' => "Entrega en depósito 2\nLote 45", 'cantidad' => '2', 'precio' => '100', 'alicuota' => '21']]]);
        $e = $this->emitir($pedido);
        $h = $this->svc->obtener($e['id']);
        $this->assertSame('T-ART', $h['items'][0]['articulo'], 'el EAN se resolvió al artículo');
        $this->assertSame('TORNILLO HEXAGONAL ACERO', $h['items'][0]['observaciones']);
        $this->assertSame("Entrega en depósito 2\nLote 45", $h['items'][0]['texto_impresion']);

        $d = ComprobanteImpreso::datos($h, $this->svc->emisor());
        $this->assertSame("Entrega en depósito 2\nLote 45", $d['items'][0]['texto']);
        $this->assertStringStartsWith('%PDF', ComprobantePdf::generar($d));

        $largo = $this->svc->armar($this->pedido(['items' => [['articulo' => 'T-ART', 'texto' => str_repeat('x', 2001), 'cantidad' => '1', 'precio' => '1', 'alicuota' => '21']]]));
        $this->assertTrue(isset($largo['errores']['items']), 'el texto adicional tiene tope');
    }

    public function testCodigoAmbiguoNoSeResuelveSolo(): void
    {
        Db::pdo()->exec("INSERT INTO articulos (codigo, descripcion, alicuota, precio_unitario, sku) VALUES ('T-ART2', 'OTRO', '21', 5, 'SKU-T-1')");
        $r = $this->svc->armar($this->pedido(['items' => [['articulo' => 'SKU-T-1', 'descripcion' => '', 'cantidad' => '1', 'precio' => '1', 'alicuota' => '21']]]));
        $this->assertTrue(isset($r['errores']['items']), 'un SKU repetido no elige un artículo al azar');
    }
}
