<?php
declare(strict_types=1);

namespace Tests;

use App\Core\Config;
use App\Core\Db;
use App\Core\Menu;
use App\Http\Request;
use App\Modules\Auth\Auth;
use App\Modules\Configuracion\Configuracion;
use App\Modules\MercadoPago\IngresosService;
use App\Modules\MercadoPago\MercadoPago;
use App\Modules\MercadoPago\MercadoPagoController;
use App\Modules\MercadoPago\MercadoPagoService;

/** Ingresos de dinero a la cuenta de Mercado Pago: notificación, consulta periódica, sin duplicados, avisos por usuario, permisos y mail opcional. */
final class MercadoPagoIngresosTest extends ComprobantesTestCase
{
    private IngresosService $ing;
    private int $admin;
    private int $operador;

    protected function setUp(): void
    {
        parent::setUp();
        Config::set('mp', ['modo' => 'simulado']);
        MercadoPago::$transporte = null;
        MercadoPagoController::$cuerpoCrudo = null;
        IngresosService::$ahora = null;
        $this->limpiar();
        $this->ing = new IngresosService();
        $this->admin = $this->crearUsuario('t_mpi_admin', 'admin');
        $this->operador = $this->crearUsuario('t_mpi_oper', 'operador');
    }

    protected function tearDown(): void
    {
        Config::load(require dirname(__DIR__) . '/config/config.php');
        Config::set('mp', ['modo' => 'apagado']);
        MercadoPago::$transporte = null;
        IngresosService::$ahora = null;
        $this->limpiar();
        parent::tearDown();
    }

    private function limpiar(): void
    {
        $pdo = Db::pdo();
        $pdo->exec('DELETE FROM mp_ingresos');
        $pdo->exec('DELETE FROM mp_ingresos_vistos');
        $pdo->exec('DELETE FROM mp_cobros');
        $pdo->exec("UPDATE configuracion SET valor = '' WHERE clave IN ('interno.mp_ingresos_consulta', 'mercadopago.ingresos_mails')");
        Configuracion::reset();
    }

    private function cantidad(): int
    {
        return (int) Db::pdo()->query('SELECT COUNT(*) FROM mp_ingresos')->fetchColumn();
    }

    /** Un pago como lo devuelve /v1/payments. @return array<string,mixed> */
    private function pago(int $id, float $monto, array $extra = []): array
    {
        return $extra + ['id' => $id, 'status' => 'approved', 'date_approved' => date('c'), 'date_created' => date('c'), 'transaction_amount' => $monto,
            'transaction_details' => ['net_received_amount' => round($monto * 0.96, 2)], 'currency_id' => 'ARS', 'payment_type_id' => 'account_money',
            'payment_method_id' => 'account_money', 'collector_id' => 123456, 'description' => 'Transferencia', 'external_reference' => '',
            'payer' => ['first_name' => 'Juan', 'last_name' => 'Pérez', 'email' => 'juan@ejemplo.com', 'identification' => ['type' => 'DNI', 'number' => '20111222']]];
    }

    /** @param array<int,array<string,mixed>> $pagos los que «tiene» Mercado Pago */
    private function produccion(array &$pagos, array &$llamadas = []): void
    {
        Config::set('mp', ['modo' => 'produccion', 'access_token' => 'TEST-TOKEN', 'webhook_secret' => 'secreto-de-prueba', 'user_id' => '123456']);
        MercadoPago::$transporte = function (string $m, string $url) use (&$pagos, &$llamadas): array {
            $llamadas[] = $url;
            if (str_contains($url, '/v1/payments/search')) {
                return ['status' => 200, 'body' => json_encode(['results' => array_values($pagos)])];
            }
            if (preg_match('#/v1/payments/(\d+)$#', $url, $mm) && isset($pagos[(int) $mm[1]])) {
                return ['status' => 200, 'body' => json_encode($pagos[(int) $mm[1]])];
            }
            return ['status' => 404, 'body' => '{}'];
        };
    }

    public function testElPagoDeMercadoPagoSeArmaComoIngreso(): void
    {
        Config::set('mp', ['modo' => 'produccion', 'user_id' => '123456']);
        $i = MercadoPago::ingreso($this->pago(55, 1500.5));
        $this->assertSame('55', $i['mp_pago_id']);
        $this->assertSame(1500.5, $i['monto']);
        $this->assertSame(1440.48, $i['neto']);
        $this->assertSame('Juan Pérez', $i['pagador']);
        $this->assertSame('DNI 20111222', $i['pagador_doc']);
        $this->assertSame('account_money', $i['medio']);
        // un pago que hizo la cuenta (otro cobra) no es un ingreso
        $this->assertNull(MercadoPago::ingreso($this->pago(56, 10, ['collector_id' => 999])));
    }

    public function testConsultaPeriodicaRegistraSinDuplicadosYComoMuchoUnaPorMinuto(): void
    {
        $pagos = [101 => $this->pago(101, 1000), 102 => $this->pago(102, 250, ['status' => 'pending']), 103 => $this->pago(103, 300, ['collector_id' => 777])];
        $llamadas = [];
        $this->produccion($pagos, $llamadas);
        IngresosService::$ahora = time();
        $this->assertSame(1, $this->ing->consultarSiToca(), 'solo el aprobado y cobrado por esta cuenta');
        $this->assertStringContainsString('begin_date=', $llamadas[0]);
        // menos de un minuto después no vuelve a preguntar
        $this->assertSame(-1, $this->ing->consultarSiToca());
        $this->assertCount(1, $llamadas);
        // un minuto después: el pendiente se aprobó y el 101 vuelve a venir (no se duplica)
        $pagos[102]['status'] = 'approved';
        IngresosService::$ahora = time() + 61;
        $this->assertSame(1, $this->ing->consultarSiToca());
        $this->assertSame(2, $this->cantidad());
        // se devolvió el 101: queda con su estado nuevo, no se borra
        $pagos[101]['status'] = 'refunded';
        IngresosService::$ahora = time() + 130;
        $this->assertSame(0, $this->ing->consultarSiToca());
        $this->assertSame('refunded', Db::pdo()->query("SELECT estado FROM mp_ingresos WHERE mp_pago_id = '101'")->fetchColumn());
        $r = $this->ing->listar([], 1, 50);
        $this->assertSame(250.0, $r['suma'], 'el total no cuenta lo devuelto');
    }

    public function testSinConexionNoPierdeElTramo(): void
    {
        $pagos = [];
        $this->produccion($pagos);
        IngresosService::$ahora = time();
        $this->ing->consultarSiToca();
        $antes = Configuracion::valor(IngresosService::CLAVE_CONSULTA);
        MercadoPago::$transporte = fn () => ['status' => 500, 'body' => '{"message":"caído"}'];
        IngresosService::$ahora = time() + 120;
        $this->assertSame(0, $this->ing->consultarSiToca());
        $this->assertSame($antes, Configuracion::valor(IngresosService::CLAVE_CONSULTA), 'la próxima vuelve a pedir desde la consulta buena');
    }

    public function testLaNotificacionRegistraCualquierPagoAunqueNoSeaUnaVenta(): void
    {
        $pagos = [4242 => $this->pago(4242, 777)];
        $this->produccion($pagos);
        $ts = (string) time();
        $firma = 'ts=' . $ts . ',v1=' . hash_hmac('sha256', 'id:4242;request-id:r9;ts:' . $ts . ';', 'secreto-de-prueba');
        $enviar = fn () => $this->app->handle(new Request('POST', '/api/v1/mercadopago/webhook', ['data.id' => '4242', 'type' => 'payment'], [], ['HTTP_X_SIGNATURE' => $firma, 'HTTP_X_REQUEST_ID' => 'r9']));
        $this->assertSame(200, $enviar()->status);
        $this->assertSame(200, $enviar()->status);
        $this->assertSame(1, $this->cantidad());
        $x = Db::pdo()->query("SELECT * FROM mp_ingresos WHERE mp_pago_id = '4242'")->fetch();
        $this->assertSame('webhook', $x['origen']);
        $this->assertSame('777.00', $x['monto']);
        // firma falsa: nada
        $this->assertSame(401, $this->app->handle(new Request('POST', '/api/v1/mercadopago/webhook', ['data.id' => '4243', 'type' => 'payment'], [], ['HTTP_X_SIGNATURE' => 'ts=1,v1=00', 'HTTP_X_REQUEST_ID' => 'r9']))->status);
    }

    public function testCadaUsuarioVeSusNovedadesYLasMarcaVistas(): void
    {
        $a = $this->ing->simular(100, 'Ana');
        $b = $this->ing->simular(200, 'Beto');
        $this->assertNotNull($a);
        $n = $this->ing->novedades($this->admin, $a);
        $this->assertSame(2, $n['sin_ver']);
        $this->assertCount(1, $n['nuevos'], 'solo lo posterior a lo que ya tenía la pantalla');
        $this->assertStringContainsString('Ingresó $ 200,00 de Beto', $n['nuevos'][0]['texto']);
        $this->ing->marcarVistos($this->admin, (int) $b);
        $this->assertSame(0, $this->ing->novedades($this->admin)['sin_ver']);
        $this->assertSame(2, $this->ing->novedades($this->operador)['sin_ver'], 'lo visto es de cada usuario');
        $this->ing->marcarVistos($this->admin, 0);
        $this->assertSame(0, $this->ing->novedades($this->admin)['sin_ver'], 'no vuelve para atrás');
    }

    public function testPantallaCampanitaYPermisos(): void
    {
        $this->loginComo($this->admin);
        $this->assertTrue(Menu::visible('mp_ingresos'));
        $this->assertSame(302, $this->post('/mercadopago/ingresos/simular', ['monto' => '1.234,50', 'pagador' => 'Carla'])->status);
        $pag = $this->get('/mercadopago/ingresos');
        $this->assertSame(200, $pag->status);
        foreach (['Ingresos de Mercado Pago', '1.234,50', 'Carla', 'Sin venta asociada', 'Marcar como vistos (1)', 'Simular ingreso'] as $t) {
            $this->assertStringContainsString($t, $pag->body, $t);
        }
        $this->assertStringContainsString('data-mp-campana', $pag->body, 'la campanita en la barra');
        $nov = json_decode($this->get('/mercadopago/ingresos/novedades', ['desde' => '0'])->body, true);
        $this->assertSame(1, $nov['sin_ver']);
        $this->assertSame(302, $this->post('/mercadopago/ingresos/vistos', ['hasta' => (string) $nov['ultimo_id']])->status);
        $this->assertSame(0, json_decode($this->get('/mercadopago/ingresos/novedades')->body, true)['sin_ver']);
        // el operador no tiene el permiso: ni pantalla, ni campanita, ni novedades
        $this->loginComo($this->operador);
        $this->assertFalse(Menu::visible('mp_ingresos'));
        $this->assertSame(403, $this->get('/mercadopago/ingresos')->status);
        $this->assertSame(403, $this->get('/mercadopago/ingresos/novedades')->status);
        $this->assertSame(403, $this->post('/mercadopago/ingresos/simular', ['monto' => '10'])->status);
        $this->assertStringNotContainsString('data-mp-campana', $this->get('/')->body);
        // con el permiso dado al rol, sí
        Db::pdo()->exec("INSERT IGNORE INTO rol_permisos (rol_id, permiso_id) SELECT r.id, p.id FROM roles r JOIN permisos p ON p.codigo = 'mercadopago.ingresos' WHERE r.codigo = 'operador'");
        try {
            Auth::reset();
            $this->assertSame(200, $this->get('/mercadopago/ingresos')->status);
        } finally {
            Db::pdo()->exec("DELETE rp FROM rol_permisos rp JOIN roles r ON r.id = rp.rol_id JOIN permisos p ON p.id = rp.permiso_id WHERE r.codigo = 'operador' AND p.codigo = 'mercadopago.ingresos'");
            Auth::reset();
        }
        // apagado: nada
        Config::set('mp', ['modo' => 'apagado']);
        $this->loginComo($this->admin);
        $this->assertFalse(Menu::visible('mp_ingresos'));
        $this->assertSame(404, $this->get('/mercadopago/ingresos')->status);
        $this->assertSame(404, $this->post('/mercadopago/ingresos/simular', ['monto' => '10'])->status);
    }

    public function testUnaVentaCobradaConElSimuladorQuedaVinculada(): void
    {
        $ana = $this->crearUsuario('t_mpi_ana', 'operador');
        $venta = $this->pedido();
        unset($venta['forma_pago']);
        $c = (new MercadoPagoService())->iniciar($venta, $ana)['cobro'];
        $this->loginComo($ana);
        $this->assertSame(302, $this->post('/mercadopago/simulador/' . $c['token'] . '/pagar')->status);
        $r = $this->ing->listar([], 1, 50);
        $this->assertSame(1, $r['total']);
        $this->assertNotNull($r['filas'][0]['comprobante_id'], 'el ingreso muestra su comprobante');
    }

    public function testElMailEsOpcionalYSeValidaEnConfiguracion(): void
    {
        // sin mails cargados no se manda nada
        $id = $this->ing->simular(50);
        $this->assertNull(Db::pdo()->query('SELECT mail_enviado_en FROM mp_ingresos WHERE id = ' . (int) $id)->fetchColumn() ?: null);
        $this->assertSame('No es un mail válido: no-es-mail.', Configuracion::guardar(['mercadopago.ingresos_mails' => 'a@b.com, no-es-mail'], $this->admin)['errores']['mercadopago.ingresos_mails']);
        $this->assertSame(1, Configuracion::guardar(['mercadopago.ingresos_mails' => 'caja@empresa.com'], $this->admin)['cambiados']);
        // con mails cargados se intenta mandar (acá a un servidor que no existe): el resultado queda en el ingreso, sin romper el registro
        Config::set('mail', ['host' => '127.0.0.1', 'port' => 1, 'secure' => '', 'user' => '', 'pass' => '', 'from' => 'facturas@empresa.com', 'from_name' => 'Empresa']);
        Config::set('mp', ['modo' => 'simulado']);
        $id = $this->ing->simular(3210, 'Dora');
        $this->assertNotNull($id);
        $x = Db::pdo()->query('SELECT mail_enviado_en, mail_error FROM mp_ingresos WHERE id = ' . (int) $id)->fetch();
        $this->assertNull($x['mail_enviado_en']);
        $this->assertTrue((string) $x['mail_error'] !== '', 'quedó anotado por qué no salió');
        $this->assertSame(['caja@empresa.com'], IngresosService::mails());
    }
}
