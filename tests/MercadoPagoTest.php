<?php
declare(strict_types=1);

namespace Tests;

use App\Core\Config;
use App\Core\Db;
use App\Http\Request;
use App\Modules\MercadoPago\MercadoPago;
use App\Modules\MercadoPago\MercadoPagoController;
use App\Modules\MercadoPago\MercadoPagoService;

/** Mercado Pago: la venta se emite solo cuando se acredita el pago (simulado y con respuestas de ejemplo de la API real), notificación firmada, permisos y transferencia. */
final class MercadoPagoTest extends ComprobantesTestCase
{
    private MercadoPagoService $mp;
    private int $ana;
    private int $beto;

    protected function setUp(): void
    {
        parent::setUp();
        Config::set('mp', ['modo' => 'simulado', 'expira_minutos' => 30]);
        MercadoPago::$transporte = null;
        MercadoPagoController::$cuerpoCrudo = null;
        Db::pdo()->exec('DELETE FROM mp_cobros');
        $this->mp = new MercadoPagoService();
        $this->ana = $this->crearUsuario('t_mp_ana', 'operador');
        $this->beto = $this->crearUsuario('t_mp_beto', 'admin');
    }

    protected function tearDown(): void
    {
        Config::set('mp', ['modo' => 'apagado']);
        MercadoPago::$transporte = null;
        MercadoPagoController::$cuerpoCrudo = null;
        Db::pdo()->exec('DELETE FROM mp_cobros');
        parent::tearDown();
    }

    /** Venta de 242 sin elegir cómo se paga, como llega de la pantalla de revisión. @return array<string,mixed> */
    private function venta(array $extra = []): array
    {
        $p = $this->pedido($extra);
        unset($p['forma_pago']);
        return $p;
    }

    private function subdiarios(): int
    {
        return (int) Db::pdo()->query("SELECT COUNT(*) FROM subdiario")->fetchColumn();
    }

    /** @return array<string,mixed> */
    private function iniciar(?array $venta = null, ?int $usuario = null): array
    {
        $r = $this->mp->iniciar($venta ?? $this->venta(), $usuario ?? $this->ana);
        $this->assertTrue($r['ok'], json_encode($r['errores']));
        return $r['cobro'];
    }

    private function aprobado(array $cobro, float $monto = null, string $id = '555001'): array
    {
        return ['id' => $id, 'estado' => 'approved', 'referencia' => (string) $cobro['token'], 'monto' => $monto ?? (float) $cobro['importe']];
    }

    public function testElCobroNoEmiteNadaHastaQueSeAcredita(): void
    {
        $c = $this->iniciar();
        $this->assertSame(['pendiente', 242.0, 'S'], [$c['estado'], (float) $c['importe'], $c['modo']]);
        $this->assertSame(0, $this->subdiarios(), 'sin acreditación no hay comprobante, ni número, ni CAE');
        $this->assertSame(0, (int) Db::pdo()->query('SELECT COUNT(*) FROM caja')->fetchColumn());
        $this->assertTrue(!empty($c['link']));
        $this->assertTrue(!empty($c['qr_data']));
        // no se guarda ninguna clave ni la forma de pago elegida
        $this->assertStringNotContainsString('autoriza_clave', $c['datos']);
        // se acredita: se emite sola, con Mercado Pago como forma de pago y a nombre de quien inició el cobro
        $r = $this->mp->confirmar($c['token'], $this->aprobado($c));
        $this->assertTrue($r['ok']);
        $this->assertSame('emitido', $r['cobro']['estado']);
        $this->assertSame(1, $this->subdiarios());
        $caja = $this->fila('SELECT p_fpago, p_importe, usuario_id FROM caja ORDER BY fe_clave DESC LIMIT 1');
        $this->assertSame(['MP', '242.00', $this->ana], [$caja['p_fpago'], $caja['p_importe'], (int) $caja['usuario_id']]);
        $this->assertNull(\App\Modules\Caja\CajaService::$actor, 'no queda un usuario «pegado»');
        // la notificación repetida no emite otra vez
        $this->assertTrue($this->mp->confirmar($c['token'], $this->aprobado($c))['ok']);
        $this->assertSame(1, $this->subdiarios());
        $this->assertSame(MercadoPagoService::urlFinal($r['cobro']), url('/comprobantes/' . (int) $r['cobro']['comprobante_id']));
    }

    public function testImporteDistintoOPagoDeOtroCobroNoEmiten(): void
    {
        $c = $this->iniciar();
        $r = $this->mp->confirmar($c['token'], $this->aprobado($c, 100.0));
        $this->assertFalse($r['ok']);
        $this->assertSame('error_emision', $r['cobro']['estado']);
        $this->assertStringContainsString('no coincide', $r['mensaje']);
        $this->assertSame(0, $this->subdiarios());
        $otro = $this->aprobado($c);
        $otro['referencia'] = str_repeat('a', 32);
        $this->assertFalse($this->mp->confirmar($c['token'], $otro)['ok'], 'el pago tiene que ser de este cobro');
        $c2 = $this->iniciar();
        $pend = $this->aprobado($c2);
        $pend['estado'] = 'pending';
        $this->assertFalse($this->mp->confirmar($c2['token'], $pend)['ok'], 'un pago no aprobado no emite');
        $this->assertSame(0, $this->subdiarios());
    }

    public function testVencidoCanceladoYPagoTardioLoResuelveUnAdministrador(): void
    {
        $c = $this->iniciar();
        Db::pdo()->prepare('UPDATE mp_cobros SET vence_en = NOW() - INTERVAL 1 MINUTE WHERE token = ?')->execute([$c['token']]);
        $this->assertSame('vencido', $this->mp->obtener($c['token'])['estado']);
        $r = $this->mp->confirmar($c['token'], $this->aprobado($c));
        $this->assertFalse($r['ok'], 'llegó plata de un cobro vencido: no se emite sola');
        $this->assertSame('error_emision', $r['cobro']['estado']);
        $this->assertStringContainsString('vencido', (string) $r['cobro']['error']);
        $this->assertSame(0, $this->subdiarios());
        $e = $this->mp->reintentar((int) $r['cobro']['id']);
        $this->assertTrue($e['ok'], $e['mensaje']);
        $this->assertSame(1, $this->subdiarios());
        // cancelar: solo un cobro pendiente
        $c2 = $this->iniciar();
        $this->assertTrue($this->mp->cancelar($c2['token']));
        $this->assertFalse($this->mp->cancelar($c2['token']));
        $this->assertFalse($this->mp->reintentar((int) $c2['id'])['ok'], 'solo se reintenta lo acreditado que no se pudo emitir');
    }

    public function testSiLaVentaYaNoSePuedeEmitirElCobroQuedaParaReintentar(): void
    {
        $c = $this->iniciar();
        Db::pdo()->prepare("UPDATE mp_cobros SET datos = '{no es json' WHERE token = ?")->execute([$c['token']]);
        $r = $this->mp->confirmar($c['token'], $this->aprobado($c));
        $this->assertFalse($r['ok']);
        $this->assertSame('error_emision', $r['cobro']['estado']);
        $this->assertNotNull($r['cobro']['acreditado_en'], 'queda registrado que se acreditó');
        $this->assertSame(0, $this->subdiarios());
    }

    public function testErroresDeLaVentaSeDevuelvenYNoDejanNadaGuardado(): void
    {
        $r = $this->mp->iniciar($this->venta(['cliente' => '99999999']), $this->ana);
        $this->assertFalse($r['ok']);
        $this->assertTrue(!empty($r['errores']));
        $this->assertSame(0, (int) Db::pdo()->query('SELECT COUNT(*) FROM mp_cobros')->fetchColumn());
    }

    public function testFlujoPorHttpPermisosYSimulador(): void
    {
        $this->loginComo($this->ana);
        $rev = $this->post('/comprobantes', $this->venta(['items' => [['articulo' => 'T-ART', 'descripcion' => '', 'cantidad' => '2', 'precio' => '100', 'alicuota' => '21']]]) + ['accion' => 'revisar']);
        // la pantalla de revisión ofrece el botón
        $this->assertStringContainsString('Cobrar con Mercado Pago', $this->revision());
        $datos = json_encode($this->venta(), JSON_UNESCAPED_UNICODE);
        $r = $this->post('/mercadopago/iniciar', ['datos' => $datos]);
        $this->assertSame(302, $r->status);
        $url = (string) $r->headers['Location'];
        $this->assertStringContainsString('/mercadopago/cobro/', $url);
        $token = substr($url, -32);
        $pag = $this->get('/mercadopago/cobro/' . $token);
        $this->assertSame(200, $pag->status);
        foreach (['Pendiente de pago', 'todavía no se emitió', 'Link de pago', 'QR para pagar', 'Mandar por WhatsApp'] as $t) {
            $this->assertStringContainsString($t, $pag->body, $t);
        }
        $est = json_decode($this->get('/mercadopago/cobro/' . $token . '/estado')->body, true);
        $this->assertSame('pendiente', $est['estado']);
        // otro cajero no ve el cobro ajeno; el administrador sí
        $this->loginComo($this->crearUsuario('t_mp_otro', 'operador'));
        $this->assertSame(404, $this->get('/mercadopago/cobro/' . $token)->status);
        $this->assertSame(404, $this->get('/mercadopago/cobro/' . $token . '/estado')->status);
        $this->assertSame(403, $this->post('/mercadopago/' . '1' . '/reintentar')->status, 'reintentar es de quien administra');
        $this->loginComo($this->beto);
        $this->assertSame(200, $this->get('/mercadopago/cobro/' . $token)->status);
        $this->assertStringContainsString('Pendiente de pago', $this->get('/mercadopago')->body);
        // el simulador aprueba el pago y la venta se emite
        $this->loginComo($this->ana);
        $this->assertSame(200, $this->get('/mercadopago/simulador/' . $token)->status);
        $this->assertSame(302, $this->post('/mercadopago/simulador/' . $token . '/pagar')->status);
        $est = json_decode($this->get('/mercadopago/cobro/' . $token . '/estado')->body, true);
        $this->assertSame('emitido', $est['estado']);
        $this->assertStringContainsString('/comprobantes/', (string) $est['url_final']);
        $this->assertSame(1, $this->subdiarios());
        // sin el permiso, nada
        Db::pdo()->exec("DELETE rp FROM rol_permisos rp JOIN roles r ON r.id = rp.rol_id JOIN permisos p ON p.id = rp.permiso_id WHERE r.codigo = 'operador' AND p.codigo = 'mercadopago.cobrar'");
        try {
            \App\Modules\Auth\Auth::reset();
            $this->assertSame(403, $this->get('/mercadopago')->status);
            $this->assertSame(403, $this->post('/mercadopago/iniciar', ['datos' => $datos])->status);
        } finally {
            Db::pdo()->exec("INSERT IGNORE INTO rol_permisos (rol_id, permiso_id) SELECT r.id, p.id FROM roles r JOIN permisos p ON p.codigo = 'mercadopago.cobrar' WHERE r.codigo = 'operador'");
            \App\Modules\Auth\Auth::reset();
        }
    }

    private function revision(): string
    {
        $svc = new \App\Modules\Comprobantes\ComprobantesService();
        $r = $svc->armar($this->venta(), false, $this->ana);
        return \App\Modules\Comprobantes\ComprobantesController::pantallaRevisar($r['cbte'], $this->venta())->body;
    }

    public function testApagadoNoOfreceNiAceptaNada(): void
    {
        Config::set('mp', ['modo' => 'apagado']);
        $this->loginComo($this->ana);
        $this->assertStringNotContainsString('Cobrar con Mercado Pago', $this->revision());
        $this->assertSame(404, $this->post('/mercadopago/iniciar', ['datos' => json_encode($this->venta())])->status);
        $this->assertSame(404, $this->get('/mercadopago')->status);
        $this->assertFalse(\App\Core\Menu::visible('mp'));
        $this->assertSame(404, $this->app->handle(new Request('POST', '/api/v1/mercadopago/webhook', [], []))->status);
    }

    public function testElKioscoCobraConMercadoPagoConLosPreciosDeLista(): void
    {
        Db::pdo()->exec('UPDATE parametros SET kiosco = 1 WHERE id = 1');
        \App\Modules\Kiosco\KioscoService::reset();
        Db::pdo()->exec("UPDATE articulos SET precio_unitario = 100 WHERE codigo = 'T-ART'");
        try {
            $this->loginComo($this->ana);
            $datos = json_encode(['tipo' => 'PR', 'origen' => 'kiosco', 'cliente' => '990002', 'items' => [['articulo' => 'T-ART', 'descripcion' => '', 'cantidad' => '2', 'precio' => '1', 'alicuota' => '21']]]);
            $r = $this->post('/mercadopago/iniciar', ['datos' => $datos]);
            $this->assertSame(302, $r->status);
            $c = $this->mp->obtener(substr((string) $r->headers['Location'], -32));
            $this->assertSame(['kiosco', 200.0], [$c['origen'], (float) $c['importe']], 'sin permiso de precio: 2 × 100 de lista, no 2 × 1');
            $this->post('/mercadopago/simulador/' . $c['token'] . '/pagar');
            $this->assertStringContainsString('/kiosco?hecho=', (string) MercadoPagoService::urlFinal($this->mp->obtener($c['token'])));
            // un cobro de «kiosco» que no es presupuesto no se acepta
            $malo = json_encode(['tipo' => 'FC', 'origen' => 'kiosco', 'cliente' => '990002', 'items' => []]);
            $this->assertSame(403, $this->post('/mercadopago/iniciar', ['datos' => $malo])->status);
        } finally {
            Db::pdo()->exec('UPDATE parametros SET kiosco = 0 WHERE id = 1');
            \App\Modules\Kiosco\KioscoService::reset();
        }
    }

    // ---------------------------------------------------------------- producción con respuestas de ejemplo de la API

    /** @param array<int,array{0:string,1:string,2:?string}> $llamadas se va llenando con [método, url, cuerpo] */
    private function produccion(array &$llamadas, ?callable $respuesta = null): void
    {
        Config::set('mp', ['modo' => 'produccion', 'access_token' => 'TEST-TOKEN', 'webhook_secret' => 'secreto-de-prueba', 'user_id' => '123456', 'pos_id' => 'CAJA1', 'expira_minutos' => 30]);
        MercadoPago::$transporte = function (string $m, string $url, array $cab, ?string $cuerpo) use (&$llamadas, $respuesta): array {
            $llamadas[] = [$m, $url, $cuerpo, $cab];
            if ($respuesta !== null && ($r = $respuesta($m, $url, $cuerpo)) !== null) {
                return $r;
            }
            if (str_contains($url, '/checkout/preferences')) {
                return ['status' => 201, 'body' => json_encode(['id' => 'PREF-1', 'init_point' => 'https://www.mercadopago.com.ar/checkout/v1/redirect?pref_id=PREF-1'])];
            }
            if (str_contains($url, '/instore/orders/qr')) {
                return ['status' => 201, 'body' => json_encode(['in_store_order_id' => 'ORD-1', 'qr_data' => '00020101021243650016com.mercadolibre0201306363EJEMPLO5204000053030325802AR6304ABCD'])];
            }
            return ['status' => 404, 'body' => '{"message":"no existe"}'];
        };
    }

    public function testProduccionCreaLinkYQrConLosDatosDelCobroYSinGuardarClaves(): void
    {
        $llamadas = [];
        $this->produccion($llamadas);
        $c = $this->iniciar();
        $this->assertSame('P', $c['modo']);
        $this->assertSame('00020101021243650016com.mercadolibre0201306363EJEMPLO5204000053030325802AR6304ABCD', $c['qr_data']);
        $this->assertStringContainsString('pref_id=PREF-1', $c['link']);
        $this->assertSame('ORD-1', $c['mp_ref']);
        [$pref, $qr] = $llamadas;
        $cuerpo = json_decode((string) $pref[2], true);
        $this->assertSame([$c['token'], 242.0], [$cuerpo['external_reference'], (float) $cuerpo['items'][0]['unit_price']]);
        $this->assertStringContainsString('/api/v1/mercadopago/webhook', $cuerpo['notification_url']);
        $this->assertContains('Authorization: Bearer TEST-TOKEN', $pref[3]);
        $this->assertStringContainsString('/instore/orders/qr/seller/collectors/123456/pos/CAJA1/qrs', $qr[1]);
        $this->assertSame(242.0, (float) json_decode((string) $qr[2], true)['total_amount']);
        // el token nunca se guarda en la base
        $this->assertStringNotContainsString('TEST-TOKEN', json_encode(Db::pdo()->query('SELECT * FROM mp_cobros')->fetchAll()));
        // sin caja configurada, solo link
        Config::set('mp.pos_id', '');
        $c2 = $this->iniciar();
        $this->assertNull($c2['qr_data']);
        $this->assertTrue(!empty($c2['link']));
    }

    public function testSiMercadoPagoFallaNoQuedaCobroGuardado(): void
    {
        $llamadas = [];
        $this->produccion($llamadas, static fn () => ['status' => 401, 'body' => '{"message":"invalid access token"}']);
        $r = null;
        try {
            $this->mp->iniciar($this->venta(), $this->ana);
        } catch (\App\Modules\MercadoPago\MercadoPagoError $e) {
            $r = $e->getMessage();
        }
        $this->assertStringContainsString('401', (string) $r);
        $this->assertSame(0, (int) Db::pdo()->query('SELECT COUNT(*) FROM mp_cobros')->fetchColumn());
        // y por HTTP vuelve a la revisión con el mensaje
        $this->loginComo($this->ana);
        $res = $this->post('/mercadopago/iniciar', ['datos' => json_encode($this->venta())]);
        $this->assertSame(422, $res->status);
        $this->assertStringContainsString('Mercado Pago:', $res->body);
        // configuración incompleta
        MercadoPago::$transporte = null;
        Config::set('mp.access_token', '');
        $this->assertContains('access_token (credencial de producción)', MercadoPago::faltantes());
    }

    public function testNotificacionFirmadaConfirmaConsultandoElPagoYRechazaLasFalsas(): void
    {
        $llamadas = [];
        $this->produccion($llamadas);
        $c = $this->iniciar();
        $pagoDeMp = ['id' => 987654321, 'status' => 'approved', 'external_reference' => $c['token'], 'transaction_amount' => 242];
        MercadoPago::$transporte = function (string $m, string $url) use ($pagoDeMp): array {
            return str_contains($url, '/v1/payments/987654321') ? ['status' => 200, 'body' => json_encode($pagoDeMp)] : ['status' => 404, 'body' => '{}'];
        };
        $reqId = 'req-abc-123';
        $ts = (string) time();
        $firma = 'ts=' . $ts . ',v1=' . hash_hmac('sha256', 'id:987654321;request-id:' . $reqId . ';ts:' . $ts . ';', 'secreto-de-prueba');
        $enviar = fn (string $firmaEnviada, string $id = '987654321') => $this->app->handle(new Request('POST', '/api/v1/mercadopago/webhook', ['data.id' => $id, 'type' => 'payment'], [],
            ['HTTP_X_SIGNATURE' => $firmaEnviada, 'HTTP_X_REQUEST_ID' => $reqId]));
        // firma falsa o ausente: 401 y no pasa nada
        $this->assertSame(401, $enviar('ts=' . $ts . ',v1=' . str_repeat('0', 64))->status);
        $this->assertSame(401, $enviar('')->status);
        $this->assertSame(0, $this->subdiarios());
        // firma buena: se consulta el pago en Mercado Pago y se emite
        $this->assertSame(200, $enviar($firma)->status);
        $this->assertSame('emitido', $this->mp->obtener($c['token'])['estado']);
        $this->assertSame(1, $this->subdiarios());
        $this->assertSame('987654321', $this->mp->obtener($c['token'])['mp_pago_id']);
        // la misma notificación otra vez no emite de nuevo
        $this->assertSame(200, $enviar($firma)->status);
        $this->assertSame(1, $this->subdiarios());
    }

    public function testNotificacionDeOrdenComercialYConsultaPorReferencia(): void
    {
        $llamadas = [];
        $this->produccion($llamadas);
        $c = $this->iniciar();
        $c2 = $this->iniciar();
        MercadoPago::$transporte = function (string $m, string $url) use ($c, $c2): array {
            if (str_contains($url, '/merchant_orders/77')) {
                return ['status' => 200, 'body' => json_encode(['payments' => [['id' => 31, 'status' => 'approved']]])];
            }
            if (str_contains($url, '/v1/payments/31')) {
                return ['status' => 200, 'body' => json_encode(['id' => 31, 'status' => 'approved', 'external_reference' => $c['token'], 'transaction_amount' => 242.0])];
            }
            if (str_contains($url, '/v1/payments/search') && str_contains($url, $c2['token'])) {
                return ['status' => 200, 'body' => json_encode(['results' => [['id' => 32, 'status' => 'rejected', 'external_reference' => $c2['token'], 'transaction_amount' => 242], ['id' => 33, 'status' => 'approved', 'external_reference' => $c2['token'], 'transaction_amount' => 242]]])];
            }
            return ['status' => 404, 'body' => '{}'];
        };
        $ts = (string) time();
        $firma = 'ts=' . $ts . ',v1=' . hash_hmac('sha256', 'id:77;request-id:r1;ts:' . $ts . ';', 'secreto-de-prueba');
        $res = $this->app->handle(new Request('POST', '/api/v1/mercadopago/webhook', ['data.id' => '77', 'type' => 'merchant_order'], [], ['HTTP_X_SIGNATURE' => $firma, 'HTTP_X_REQUEST_ID' => 'r1']));
        $this->assertSame(200, $res->status);
        $this->assertSame('emitido', $this->mp->obtener($c['token'])['estado'], 'la orden comercial paga emite el cobro');
        // sin notificación (hosting sin dirección pública): al mirar el cobro el sistema le pregunta a Mercado Pago
        $this->loginComo($this->ana);
        $est = json_decode($this->get('/mercadopago/cobro/' . $c2['token'] . '/estado')->body, true);
        $this->assertSame('emitido', $est['estado']);
        $this->assertSame(2, $this->subdiarios());
    }

    public function testFirmaValidaSegunLaDocumentacion(): void
    {
        $manifiesto = 'id:abc123;request-id:xyz;ts:1700000000;';
        $v1 = hash_hmac('sha256', $manifiesto, 'clave');
        $this->assertTrue(MercadoPago::firmaValida('ABC123', 'ts=1700000000,v1=' . $v1, 'xyz', 'clave'), 'el id alfanumérico va en minúsculas');
        $this->assertFalse(MercadoPago::firmaValida('abc123', 'ts=1700000000,v1=' . $v1, 'otro', 'clave'));
        $this->assertFalse(MercadoPago::firmaValida('abc123', 'ts=1700000001,v1=' . $v1, 'xyz', 'clave'));
        $this->assertFalse(MercadoPago::firmaValida('abc123', 'ts=1700000000,v1=' . $v1, 'xyz', ''), 'sin clave configurada no se acepta nada');
        $this->assertFalse(MercadoPago::firmaValida('abc123', 'basura', 'xyz', 'clave'));
    }

    public function testTransferenciaEsUnaFormaDePagoDeBilletera(): void
    {
        $f = $this->fila("SELECT descripcion, tipo FROM forma_de_pagos WHERE codigo = 'TR'");
        $this->assertSame(['TRANSFERENCIA', 10], [$f['descripcion'], (int) $f['tipo']], 'se carga como billetera y dice que es transferencia');
        $mp = $this->fila("SELECT descripcion, tipo FROM forma_de_pagos WHERE codigo = 'MP'");
        $this->assertSame(['MERCADO PAGO', 10], [$mp['descripcion'], (int) $mp['tipo']]);
        $e = $this->emitir($this->pedido(['forma_pago' => '1', 'fpago' => 'TR']));
        $this->assertSame('TR', $this->fila('SELECT p_fpago FROM caja WHERE subdiario_id = ?', [$e['id']])['p_fpago']);
        $r = \App\Modules\Listados\CajaDiaria::resumen([]);
        $this->assertSame(242.0, $r['por_tipo'][10], 'la transferencia suma a las billeteras virtuales');
        $this->loginComo($this->beto);
        $this->assertStringContainsString('TRANSFERENCIA', $this->get('/comprobantes/' . $e['id'])->body);
    }
}
