<?php
declare(strict_types=1);

namespace Tests;

use App\App;
use App\Core\Config;
use App\Core\Db;
use App\Modules\Arca\Arca;
use App\Modules\Arca\Padron;
use App\Modules\Arca\PadronError;
use App\Modules\Clientes\AltaCliente;

/**
 * Alta de clientes desde el CUIT con el padrón de ARCA (simulado): normalización de la constancia, caché, provincia, permisos y alta rápida.
 * CUIT del simulador: 30-71234567-1 sociedad RI · 20-12345670-0 monotributo CABA · 20-12345679-4 RI Córdoba · 20-12345678-6 sin IVA ·
 * 20-00000000-1 no existe.
 */
final class AltaClienteTest extends ComprobantesTestCase
{
    private int $ultimo = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $cfg = require App::root() . '/config/config.php';
        $cfg['afip'] = ['modo' => 'simulado'];
        Config::load($cfg);
        Arca::reiniciar();
        Padron::$transporte = null;
        $this->ultimo = (int) Db::pdo()->query('SELECT ultimo_cliente FROM parametros WHERE id = 1')->fetchColumn();
        Db::pdo()->exec('DELETE FROM padron_consultas');
    }

    protected function tearDown(): void
    {
        Padron::$transporte = null;
        Db::pdo()->exec('DELETE FROM padron_consultas');
        Db::pdo()->exec("DELETE FROM clientes WHERE razon_social LIKE '%SIMULAD%' OR razon_social LIKE 'T %'");
        Db::pdo()->exec("UPDATE parametros SET ultimo_cliente = {$this->ultimo} WHERE id = 1");
        Db::pdo()->exec("DELETE FROM usuarios WHERE rol_id IN (SELECT id FROM roles WHERE codigo LIKE 't\\_%')");
        Db::pdo()->exec("DELETE FROM roles WHERE codigo LIKE 't\\_%'");
        Config::load(require App::root() . '/config/config.php');
        Arca::reiniciar();
        parent::tearDown();
    }

    private function usuarioConPermisos(string $codigoRol, array $permisos): int
    {
        $pdo = Db::pdo();
        $pdo->prepare('INSERT INTO roles (codigo, nombre) VALUES (?, ?)')->execute([$codigoRol, 'T ' . $codigoRol]);
        $rol = (int) $pdo->lastInsertId();
        foreach ($permisos as $p) {
            $pdo->prepare('INSERT INTO rol_permisos (rol_id, permiso_id) SELECT ?, id FROM permisos WHERE codigo = ?')->execute([$rol, $p]);
        }
        $u = $this->crearUsuario('u' . $codigoRol, 'operador');
        $pdo->prepare('UPDATE usuarios SET rol_id = ? WHERE id = ?')->execute([$rol, $u]);
        return $u;
    }

    public function testSociedadInscriptaConDomicilioYActividades(): void
    {
        $p = Padron::consultar('30-71234567-1');
        $this->assertTrue($p['encontrado']);
        $this->assertSame('EMPRESA SIMULADA 4567 SA', $p['razon_social']);
        $this->assertSame(1, $p['iva']);
        $this->assertSame('JURIDICA', $p['tipo_persona']);
        $this->assertSame('MORON', $p['localidad']);
        $this->assertSame('1708', $p['codigo_postal']);
        $this->assertSame('461011', $p['actividades'][0]['id']);
        $this->assertTrue($p['actividades'][0]['principal']);
        $this->assertContains('IVA', array_column($p['impuestos'], 'descripcion'));
        $this->assertSame('S', $p['entorno']);
        $this->assertFalse($p['de_cache']);
        // la segunda vez sale de lo guardado (no se vuelve a preguntar) salvo que se fuerce
        $this->assertTrue(Padron::consultar('30712345671')['de_cache']);
        $this->assertFalse(Padron::consultar('30712345671', true)['de_cache']);
        $this->assertSame(1, (int) Db::pdo()->query('SELECT COUNT(*) FROM padron_consultas')->fetchColumn());
    }

    public function testCondicionDeIvaSegunElPadron(): void
    {
        $mono = Padron::consultar('20-12345670-0');
        $this->assertSame(7, $mono['iva']);
        $this->assertStringContainsString('Monotributo', $mono['condicion']);
        $this->assertSame('SIMULADO 5670 MARIA', $mono['razon_social']);
        $this->assertSame('CIUDAD AUTONOMA BUENOS AIRES', $mono['localidad'], 'en CABA sin localidad se usa la ciudad');
        $this->assertSame('477150', $mono['actividades'][0]['id'], 'actividad del monotributista');
        $ri = Padron::consultar('20-12345679-4');
        $this->assertSame(1, $ri['iva']);
        $this->assertSame('CORDOBA', $ri['provincia']);
        $cf = Padron::consultar('20-12345678-6');
        $this->assertSame(3, $cf['iva']);
        $this->assertTrue($cf['encontrado'], 'con error de constancia igual viene el nombre');
        $this->assertTrue(count($cf['avisos']) >= 1);
    }

    public function testNormalizarIvaExentoNoAlcanzadoYClaveInactiva(): void
    {
        $base = ['datosGenerales' => ['razonSocial' => 'X SA', 'estadoClave' => 'INACTIVO', 'domicilioFiscal' => ['idProvincia' => '12', 'direccion' => 'A 1']]];
        $exento = Padron::normalizar($base + ['datosRegimenGeneral' => ['impuesto' => ['idImpuesto' => '32', 'descripcionImpuesto' => 'IVA EXENTO', 'estadoImpuesto' => 'AC']]], '30712345671');
        $this->assertSame(4, $exento['iva']);
        $this->assertSame('SANTA FE', $exento['provincia'], 'provincia por id si no viene la descripción');
        $this->assertStringContainsString('INACTIVO', implode(' ', $exento['avisos']));
        $na = Padron::normalizar($base + ['datosRegimenGeneral' => ['impuesto' => [['idImpuesto' => '34', 'descripcionImpuesto' => 'IVA NO ALCANZADO']]]], '30712345671');
        $this->assertSame(4, $na['iva']);
        $baja = Padron::normalizar($base + ['datosRegimenGeneral' => ['impuesto' => [['idImpuesto' => '30', 'descripcionImpuesto' => 'IVA', 'estadoImpuesto' => 'BD']]]], '30712345671');
        $this->assertSame(3, $baja['iva'], 'un IVA dado de baja no cuenta');
    }

    public function testErroresClaros(): void
    {
        try {
            Padron::consultar('20-00000000-1');
            $this->fail('tenía que decir que no existe');
        } catch (PadronError $e) {
            $this->assertSame('no_existe', $e->motivo);
            $this->assertStringContainsString('no tiene registrado', $e->getMessage());
        }
        try {
            Padron::consultar('20-12345678-0');
            $this->fail('CUIT inválido');
        } catch (PadronError $e) {
            $this->assertSame('cuit', $e->motivo);
        }
        // modo interno: no se habla con ARCA y se completa a mano
        $cfg = require App::root() . '/config/config.php';
        $cfg['afip'] = ['modo' => 'interno'];
        Config::load($cfg);
        $this->assertFalse(Padron::disponible());
        $p = (new AltaCliente())->propuesta('30712345671');
        $this->assertTrue($p['ok']);
        $this->assertFalse($p['arca']);
        $this->assertSame('no_disponible', $p['motivo']);
        $this->assertSame('1', $p['datos']['iva'], 'una sociedad arranca como inscripta');
    }

    public function testPropuestaConProvinciaDeLaTablaLetraYExistentes(): void
    {
        $alta = new AltaCliente();
        $this->assertSame('02', $alta->provinciaCodigo('Buenos Aires'));
        $this->assertSame('23', $alta->provinciaCodigo('CIUDAD AUTONOMA BUENOS AIRES'));
        $this->assertSame('24', $alta->provinciaCodigo('TIERRA DEL FUEGO, ANTARTIDA E ISLAS DEL ATLANTICO SUR'));
        $this->assertSame('03', $alta->provinciaCodigo('Córdoba'));
        $this->assertNull($alta->provinciaCodigo('MARTE'));

        $p = $alta->propuesta('30-71234567-1');
        $this->assertTrue($p['arca']);
        $this->assertSame('02', $p['datos']['provincia']);
        $this->assertSame('1', $p['datos']['iva']);
        $this->assertSame('A', $p['letra'], 'emisor inscripto: A a un inscripto');
        $this->assertSame([], $p['existentes']);

        $r = $alta->crear($p['datos'] + ['telefono' => '11 5555-0000', 'emailfc' => 'compras@ejemplo.com']);
        $this->assertTrue($r['ok']);
        $this->assertSame('EMPRESA SIMULADA 4567 SA', $r['cliente']['razon_social']);
        $this->assertSame('A', $r['cliente']['letra'], 'vuelve listo para el buscador de la venta');
        $fila = $this->fila('SELECT * FROM clientes WHERE codigo = ?', [$r['cliente']['codigo']]);
        $this->assertSame('30712345671', (string) $fila['cuit']);
        $this->assertSame('AV RIVADAVIA 18500', $fila['domicilio']);
        $this->assertSame('1', (string) $fila['forma_pago']);

        $p = $alta->propuesta('30712345671');
        $this->assertCount(1, $p['existentes'], 'el CUIT ya está: se ofrece usar ese');
        $this->assertTrue($p['existentes'][0]['activo']);
        $otra = $alta->crear($p['datos']);
        $this->assertFalse($otra['ok'], 'repetido sin confirmar');
        $this->assertSame('1', $otra['errores']['cuit_repetido'] ?? '');
        $this->assertTrue($alta->crear($p['datos'] + ['cuit_repetido' => '1'])['ok'], 'confirmado: otra sucursal del mismo CUIT');
    }

    public function testAltaRapidaSoloConLosCamposPermitidos(): void
    {
        $r = (new AltaCliente())->crear(['razon_social' => 'T Final', 'iva' => '3', 'credito' => '999999', 'descuento' => '50', 'forma_pago' => '9']);
        $this->assertTrue($r['ok']);
        $f = $this->fila('SELECT credito, descuento, forma_pago FROM clientes WHERE codigo = ?', [$r['cliente']['codigo']]);
        $this->assertSame(0.0, (float) $f['credito'], 'el límite de crédito no se toca desde la venta');
        $this->assertSame(0.0, (float) $f['descuento']);
        $this->assertSame('1', (string) $f['forma_pago']);
        $e = (new AltaCliente())->crear(['razon_social' => 'T Inscripto', 'iva' => '1']);
        $this->assertStringContainsString('obligatorio', $e['errores']['cuit'] ?? '');
    }

    public function testPermisosPorRol(): void
    {
        // sin permiso de alta: no consulta ni crea
        $this->loginComo($this->usuarioConPermisos('t_ver', ['comprobantes.ver', 'clientes.ver']));
        $this->assertSame(403, $this->get('/clientes/padron', ['cuit' => '30712345671'])->status);
        $this->assertSame(403, $this->post('/clientes/rapido', ['razon_social' => 'T No', 'iva' => '3'])->status);

        // cajero con clientes.alta: crea desde la venta pero no edita clientes existentes ni entra a la ficha nueva
        $this->loginComo($this->usuarioConPermisos('t_caja', ['comprobantes.emitir', 'clientes.alta']));
        $r = $this->get('/clientes/padron', ['cuit' => '20-12345670-0']);
        $this->assertSame(200, $r->status);
        $j = json_decode($r->body, true);
        $this->assertSame('7', $j['datos']['iva']);
        $this->assertSame('23', $j['datos']['provincia']);
        $c = $this->post('/clientes/rapido', $j['datos'] + ['forma_pago' => '1']);
        $this->assertSame(200, $c->status);
        $cli = json_decode($c->body, true)['cliente'];
        $this->assertSame('SIMULADO 5670 MARIA', $cli['razon_social']);
        $this->assertSame(403, $this->post('/clientes/' . $cli['codigo'], ['razon_social' => 'T Cambio', 'iva' => '3', 'forma_pago' => '1'])->status);
        $this->assertSame(403, $this->get('/clientes/nuevo')->status);
        $mal = $this->post('/clientes/rapido', ['razon_social' => '', 'iva' => '1']);
        $this->assertSame(422, $mal->status);
        $this->assertTrue($this->post('/clientes/rapido', ['razon_social' => 'T Sin csrf', 'iva' => '3'], false)->status >= 400, 'POST sin CSRF');

        // la pantalla de factura muestra el botón «Nuevo cliente» a quien puede, y no a quien no
        $this->assertStringContainsString('data-nuevo-cliente', $this->get('/comprobantes/nuevo', ['tipo' => 'FC'])->body);
        $this->loginComo($this->usuarioConPermisos('t_emite', ['comprobantes.emitir']));
        $this->assertStringNotContainsString('data-nuevo-cliente', $this->get('/comprobantes/nuevo', ['tipo' => 'FC'])->body);
    }

    public function testOperadorTieneElPermisoPorLaMigracionYLaFichaMuestraArca(): void
    {
        $op = $this->crearUsuario('t_op', 'operador');
        $this->loginComo($op);
        $this->assertSame(200, $this->get('/clientes/padron', ['cuit' => '20-12345679-4'])->status, 'operador: clientes.editar + clientes.alta');
        $r = (new AltaCliente())->crear(['razon_social' => 'T Ficha', 'iva' => '3', 'cuit' => '20-12345679-4', 'forma_pago' => '1']);
        $ficha = $this->get('/clientes/' . $r['cliente']['codigo'])->body;
        $this->assertStringContainsString('Datos de ARCA', $ficha);
        $this->assertStringContainsString('IVA Responsable Inscripto', $ficha);
        $this->assertStringContainsString('acá está cargado como', $ficha, 'avisa que la condición no coincide');
        $this->assertStringContainsString('data-traer-arca', $ficha);
        $this->assertStringContainsString('data-traer-arca', $this->get('/clientes/nuevo')->body);
    }
}
