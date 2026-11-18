<?php
declare(strict_types=1);

namespace Tests;

use App\Core\Db;
use App\Modules\Articulos\BuscadorArticulos;
use App\Modules\Stock\ReservasService;
use App\Modules\Stock\StockNoDisponible;
use App\Modules\Stock\StockService;

/** Reservas de stock entre puestos: quien toma un producto lo reserva hasta facturar; si no alcanza, vender igual pide autorización de un supervisor. */
final class ReservasTest extends ComprobantesTestCase
{
    private StockService $stock;
    private ReservasService $res;
    private int $ana;
    private int $beto;

    protected function setUp(): void
    {
        parent::setUp();
        $pdo = Db::pdo();
        $pdo->exec('DELETE FROM stock_movimientos');
        $pdo->exec('DELETE FROM stock_saldos');
        $pdo->exec("UPDATE configuracion SET valor = '1' WHERE clave = 'stock.controlar'");
        \App\Modules\Configuracion\Configuracion::reset();
        $this->stock = new StockService();
        $this->res = new ReservasService();
        $this->stock->cargar('INGRESO', 'T-ART', $this->stock->depositoPrincipal(), '5', 'unidades', 'inicial', null);
        $this->ana = $this->crearUsuario('t_ana_res', 'operador');
        $this->beto = $this->crearUsuario('t_beto_res', 'operador');
        BuscadorArticulos::$reserva = null;
    }

    protected function tearDown(): void
    {
        $pdo = Db::pdo();
        $pdo->exec('DELETE FROM stock_movimientos');
        $pdo->exec('DELETE FROM stock_saldos');
        $pdo->exec("UPDATE configuracion SET valor = '0' WHERE clave = 'stock.controlar'");
        \App\Modules\Configuracion\Configuracion::reset();
        BuscadorArticulos::$reserva = null;
        parent::tearDown();
    }

    private function token(): string
    {
        return bin2hex(random_bytes(16));
    }

    private function venta(int $cant, string $token, array $extra = []): array
    {
        $p = $this->pedido($extra + ['reserva' => $token, 'items' => [['articulo' => 'T-ART', 'descripcion' => '', 'cantidad' => (string) $cant, 'precio' => '100', 'alicuota' => '21']]]);
        return $p;
    }

    public function testQuienTomaUnProductoLoReservaYLosOtrosVenQuienEs(): void
    {
        $tAna = $this->token();
        $e = $this->res->sincronizar($tAna, [['articulo' => 'T-ART', 'cantidad' => '2']], $this->ana, 'kiosco');
        $this->assertSame([5.0, 5.0, 0.0, []], [$e[0]['saldo'], $e[0]['disponible'], $e[0]['falta'], $e[0]['otros']], 'a quien reserva no se le cuenta su propia reserva');
        // Beto, con su propia venta, ve que Ana tiene 2 reservados
        $tBeto = $this->token();
        $b = $this->res->sincronizar($tBeto, [['articulo' => 'T-ART', 'cantidad' => '2']], $this->beto, 'comprobante');
        $this->assertSame([3.0, 0.0], [$b[0]['disponible'], $b[0]['falta']]);
        $this->assertSame([['nombre' => 'Nombre t_ana_res', 'cantidad' => 2.0]], $b[0]['otros'], 'se sabe por quién');
        $mas = $this->res->sincronizar($tBeto, [['articulo' => 'T-ART', 'cantidad' => '4']], $this->beto);
        $this->assertSame([3.0, 1.0], [$mas[0]['disponible'], $mas[0]['falta']], 'pide 4 y hay 3 libres');
        $this->assertStringContainsString('reservado por Nombre t_ana_res 2', $mas[0]['mensaje']);
        // en el buscador cada tarjeta muestra lo reservado por otros (no lo propio)
        BuscadorArticulos::$reserva = $tBeto;
        $a = BuscadorArticulos::buscar('T-ART', true)[0]['stock'];
        $this->assertSame([[['n' => 'Nombre t_ana_res', 'c' => 2.0]], 3.0], [$a['reservas'], $a['disponible']]);
        BuscadorArticulos::$reserva = $tAna;
        $this->assertSame([['n' => 'Nombre t_beto_res', 'c' => 4.0]], BuscadorArticulos::buscar('T-ART', true)[0]['stock']['reservas'], 'Ana ve lo de Beto');
        // sacar un producto libera su reserva; liberar la venta, todo; y vencen solas
        $this->res->sincronizar($tBeto, [], $this->beto);
        $this->assertSame(0, (int) $this->fila('SELECT COUNT(*) AS n FROM stock_reservas WHERE token = ?', [$tBeto])['n']);
        $this->res->liberar($tAna);
        $this->assertSame(0, (int) $this->fila('SELECT COUNT(*) AS n FROM stock_reservas')['n']);
        $this->res->sincronizar($tAna, [['articulo' => 'T-ART', 'cantidad' => '1']], $this->ana);
        Db::pdo()->exec("UPDATE stock_reservas SET vence = NOW() - INTERVAL 1 MINUTE");
        $c = $this->res->sincronizar($this->token(), [['articulo' => 'T-ART', 'cantidad' => '1']], $this->beto);
        $this->assertSame([], $c[0]['otros'], 'la reserva vencida ya no cuenta');
    }

    public function testElServicioYLoQueNoControlaStockNoReservan(): void
    {
        Db::pdo()->exec("UPDATE articulos SET controla_stock = 0 WHERE codigo = 'T-ART'");
        $this->assertSame([], $this->res->sincronizar($this->token(), [['articulo' => 'T-ART', 'cantidad' => '9']], $this->ana));
        $this->assertSame(0, (int) $this->fila('SELECT COUNT(*) AS n FROM stock_reservas')['n']);
        Db::pdo()->exec("UPDATE articulos SET controla_stock = 1 WHERE codigo = 'T-ART'");
        Db::pdo()->exec("UPDATE configuracion SET valor = '0' WHERE clave = 'stock.controlar'");
        \App\Modules\Configuracion\Configuracion::reset();
        $this->assertSame([], $this->res->sincronizar($this->token(), [['articulo' => 'T-ART', 'cantidad' => '1']], $this->ana), 'con el stock apagado no se reserva');
    }

    public function testVenderLoReservadoPorOtroPideAutorizacionYLaFacturaLiberaLaReserva(): void
    {
        $tAna = $this->token();
        $tBeto = $this->token();
        $this->res->sincronizar($tAna, [['articulo' => 'T-ART', 'cantidad' => '4']], $this->ana);     // Ana reserva 4 de 5
        $this->res->sincronizar($tBeto, [['articulo' => 'T-ART', 'cantidad' => '3']], $this->beto);   // Beto quiere 3: hay 1 libre
        // sin autorización: no. Con la de un supervisor: sí, y queda asentado
        $this->assertStringContainsString('autorización de un supervisor', $this->svc->armar($this->venta(3, $tBeto), false, $this->beto)['errores']['stock']);
        $sup = $this->supervisorId();
        $r = $this->svc->armar($this->venta(3, $tBeto, ['autoriza_yo' => '1']), false, $sup);
        $this->assertSame([], $r['errores']);
        $this->assertCount(1, $r['cbte']['stock_faltantes']);
        $e = $this->svc->emitir($r['cbte'], bin2hex(random_bytes(16)), $this->beto);
        $a = $this->fila('SELECT articulo, cantidad, disponible, pide_usuario_id, autoriza_usuario_id, subdiario_id FROM stock_autorizaciones');
        $this->assertSame(['T-ART', '2.000', '1.000', $this->beto, $sup, $e['id']], [$a['articulo'], $a['cantidad'], $a['disponible'], (int) $a['pide_usuario_id'], (int) $a['autoriza_usuario_id'], (int) $a['subdiario_id']], 'queda quién pidió y quién autorizó');
        $this->assertSame(2.0, $this->stock->saldo('T-ART'), '5 − 3');
        $this->assertSame(0, (int) $this->fila('SELECT COUNT(*) AS n FROM stock_reservas WHERE token = ?', [$tBeto])['n'], 'al facturar se libera la reserva de Beto');
        $this->assertSame(1, (int) $this->fila('SELECT COUNT(*) AS n FROM stock_reservas WHERE token = ?', [$tAna])['n'], 'la de Ana sigue');
        // Ana factura lo suyo (4): ahora el stock real es 2 y no alcanza → también necesita autorización
        $this->assertStringContainsString('autorización', $this->svc->armar($this->venta(4, $tAna), false, $this->ana)['errores']['stock']);
        // lo que sí alcanza (2 libres de verdad) sale sin autorización y libera
        $ok = $this->svc->armar($this->venta(2, $tAna), false, $this->ana);
        $this->assertSame([], $ok['errores']);
        $this->svc->emitir($ok['cbte'], bin2hex(random_bytes(16)), $this->ana);
        $this->assertSame([0.0, 0], [$this->stock->saldo('T-ART'), (int) $this->fila('SELECT COUNT(*) AS n FROM stock_reservas')['n']]);
    }

    public function testDosPuestosQueEmitenLoUltimoNoLoVendenLosDosSinAutorizacion(): void
    {
        Db::pdo()->exec('DELETE FROM stock_saldos');
        $this->stock->cargar('INGRESO', 'T-ART', $this->stock->depositoPrincipal(), '1', 'unidades', 'ajuste', null); // queda 1... (cargar suma al saldo anterior de 5 → se dejó en 0 arriba)
        $this->assertSame(1.0, $this->stock->saldo('T-ART'));
        // los dos puestos arman la venta de 1 sin haber reservado nada: los dos ven que alcanza
        $a = $this->svc->armar($this->venta(1, $this->token()), false, $this->ana);
        $b = $this->svc->armar($this->venta(1, $this->token()), false, $this->beto);
        $this->assertSame([[], []], [$a['errores'], $b['errores']]);
        $this->svc->emitir($a['cbte'], bin2hex(random_bytes(16)), $this->ana);   // el primero se lo lleva
        try {
            $this->svc->emitir($b['cbte'], bin2hex(random_bytes(16)), $this->beto); // el segundo, al emitir, ve que ya no queda
            $this->assertTrue(false, 'no debía vender lo mismo dos veces sin autorización');
        } catch (StockNoDisponible $e) {
            $this->assertStringContainsString('no hay disponibles', $e->getMessage());
        }
        $this->assertSame(0.0, $this->stock->saldo('T-ART'));
        $this->assertSame(1, (int) Db::pdo()->query("SELECT COUNT(*) FROM subdiario WHERE tipo = 'FC'")->fetchColumn(), 'una sola factura');
        // con autorización de un supervisor el segundo sí puede (queda negativo y asentado)
        $b2 = $this->svc->armar($this->venta(1, $this->token(), ['autoriza_yo' => '1']), false, $this->supervisorId());
        $this->svc->emitir($b2['cbte'], bin2hex(random_bytes(16)), $this->beto);
        $this->assertSame(-1.0, $this->stock->saldo('T-ART'));
    }

    public function testEndpointsYRevisionPorHttp(): void
    {
        $this->loginComo($this->crearUsuario('t_op_rv', 'operador'));
        $tok = $this->token();
        $r = $this->post('/stock/reservar', ['token' => $tok, 'origen' => 'comprobante', 'lineas' => json_encode([['articulo' => 'T-ART', 'cantidad' => '2']])]);
        $this->assertSame(200, $r->status);
        $j = json_decode($r->body, true);
        $this->assertSame(['T-ART', 2.0], [$j['estados'][0]['articulo'], (float) $j['estados'][0]['cantidad']]);
        $this->assertSame(1, (int) $this->fila('SELECT COUNT(*) AS n FROM stock_reservas WHERE token = ?', [$tok])['n']);
        $this->assertSame(422, $this->post('/stock/reservar', ['token' => 'malo', 'lineas' => '[]'])->status);
        $this->post('/stock/liberar', ['token' => $tok]);
        $this->assertSame(0, (int) $this->fila('SELECT COUNT(*) AS n FROM stock_reservas')['n']);
        // la revisión muestra el bloque de autorización cuando no alcanza, y lo pide al emitir
        $p = $this->venta(9, $this->token());
        unset($p['forma_pago']);
        $rev = $this->post('/comprobantes/revisar', $p);
        $this->assertSame(200, $rev->status);
        foreach (['Stock no disponible', 'autorización de un supervisor', 'name="autoriza_usuario"', 'name="autoriza_clave"'] as $t) {
            $this->assertStringContainsString($t, $rev->body, $t);
        }
        $this->assertStringNotContainsString('name="autoriza_yo"', $rev->body, 'el operador no se autoriza solo');
        preg_match('/name="datos" value="([^"]*)"/', $rev->body, $m);
        $datos = html_entity_decode($m[1], ENT_QUOTES);
        $op = bin2hex(random_bytes(16));
        $this->crearUsuario('t_sup_http', 'admin');
        $mal = $this->post('/comprobantes', ['datos' => $datos, 'operacion' => $op, 'forma_pago' => '2', 'autoriza_usuario' => 't_sup_http', 'autoriza_clave' => 'incorrecta']);
        $this->assertSame(422, $mal->status);
        $this->assertStringContainsString('no es válida', $mal->body);
        $this->assertStringNotContainsString('incorrecta', $mal->body, 'la clave no se devuelve');
        $this->assertSame(0, (int) Db::pdo()->query("SELECT COUNT(*) FROM subdiario WHERE tipo = 'FC'")->fetchColumn());
        $ok = $this->post('/comprobantes', ['datos' => $datos, 'operacion' => $op, 'forma_pago' => '2', 'autoriza_usuario' => 't_sup_http', 'autoriza_clave' => 'Prueba1234']);
        $this->assertSame(302, $ok->status);
        $this->assertSame(1, (int) Db::pdo()->query('SELECT COUNT(*) FROM stock_autorizaciones')->fetchColumn());
        // sin permisos de venta: no se reserva
        Db::pdo()->exec("DELETE rp FROM rol_permisos rp JOIN permisos p ON p.id = rp.permiso_id JOIN roles r ON r.id = rp.rol_id WHERE r.codigo = 'operador' AND p.codigo IN ('comprobantes.emitir', 'kiosco.usar')");
        \App\Modules\Auth\Auth::reset();
        try {
            $this->assertSame(403, $this->post('/stock/reservar', ['token' => $this->token(), 'lineas' => '[]'])->status);
            $this->assertSame(403, $this->post('/stock/liberar', ['token' => $this->token()])->status);
        } finally {
            Db::pdo()->exec("INSERT IGNORE INTO rol_permisos (rol_id, permiso_id) SELECT r.id, p.id FROM roles r JOIN permisos p ON p.codigo IN ('comprobantes.emitir', 'kiosco.usar') WHERE r.codigo = 'operador'");
        }
    }
}
