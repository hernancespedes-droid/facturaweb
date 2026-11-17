<?php
declare(strict_types=1);

namespace Tests;

use App\Core\Db;
use App\Modules\Configuracion\Configuracion;
use App\Modules\Portal\PortalService;

/** Portal de clientes: link por mail (una vez, vence), solo sus comprobantes fiscales, PDF, límites y apagado. */
final class PortalTest extends ComprobantesTestCase
{
    use ConServidorSmtp;

    protected function setUp(): void
    {
        parent::setUp();
        $pdo = Db::pdo();
        $pdo->exec('DELETE FROM portal_accesos');
        $pdo->exec("UPDATE configuracion SET valor = '1' WHERE clave = 'portal.activo'");
        $pdo->exec("UPDATE clientes SET emailfc = 'Pagos@Cliente-RI.com; otro@x.com' WHERE codigo = 990001");
        $pdo->exec("UPDATE clientes SET emailfc = 'final@x.com' WHERE codigo = 990002");
        Configuracion::reset();
    }

    protected function tearDown(): void
    {
        $this->cerrarSmtp();
        Db::pdo()->exec('DELETE FROM portal_accesos');
        Db::pdo()->exec("UPDATE configuracion SET valor = '0' WHERE clave = 'portal.activo'");
        Configuracion::reset();
        parent::tearDown();
    }

    /** Un link válido sin pasar por el mail. */
    private function link(string $mail, int $minutos = 30): string
    {
        $t = bin2hex(random_bytes(32));
        Db::pdo()->prepare('INSERT INTO portal_accesos (email, token_hash, vence) VALUES (?, ?, ?)')->execute([$mail, hash('sha256', $t), date('Y-m-d H:i:s', time() + $minutos * 60)]);
        return $t;
    }

    /** Factura fiscal (no de prueba) del cliente dado. */
    private function factura(string $cliente = '990001', array $extra = []): int
    {
        $id = $this->emitir($this->pedido(['cliente' => $cliente] + $extra))['id'];
        Db::pdo()->exec("UPDATE subdiario SET es_prueba = 0 WHERE fe_clave = {$id}");
        return $id;
    }

    public function testPideLinkPorMailEntraYDescarga(): void
    {
        $id = $this->factura();
        $this->servidorSmtp();
        $r = $this->post('/portal', ['email' => ' pagos@cliente-ri.com ']);
        $this->assertSame(200, $r->status);
        $this->assertStringContainsString('Revisá tu correo', $r->body);
        $crudo = $this->esperarArchivo($this->salidaSmtp);
        preg_match('/Content-Transfer-Encoding: base64\r\n\r\n([A-Za-z0-9+\/=\r\n]+)/', $crudo, $b64);
        $msg = base64_decode(str_replace("\r\n", '', $b64[1] ?? ''));
        $this->assertSame(1, preg_match('#/portal/acceso/([a-f0-9]{64})#', $msg, $m), 'el mail trae el link');
        $this->assertStringNotContainsString($m[1], (string) Db::pdo()->query('SELECT GROUP_CONCAT(token_hash) FROM portal_accesos')->fetchColumn(), 'solo se guarda el hash');

        $this->assertRedirect('/portal/comprobantes', $this->get('/portal/acceso/' . $m[1]));
        $p = $this->get('/portal/comprobantes');
        $this->assertSame(200, $p->status);
        $this->assertStringContainsString('T CLIENTE RI SA', $p->body);
        $this->assertStringContainsString('/portal/comprobantes/' . $id . '/pdf', $p->body);
        $pdf = $this->get('/portal/comprobantes/' . $id . '/pdf');
        $this->assertSame(200, $pdf->status);
        $this->assertStringStartsWith('%PDF', $pdf->body);

        $this->assertSame(410, $this->get('/portal/acceso/' . $m[1])->status, 'el link sirve una sola vez');
    }

    public function testMailDesconocidoContestaIgualYNoMandaNada(): void
    {
        $r = $this->post('/portal', ['email' => 'nadie@x.com']);
        $this->assertSame(200, $r->status);
        $this->assertStringContainsString('Revisá tu correo', $r->body, 'no revela si el mail existe');
        $this->assertSame(0, (int) Db::pdo()->query('SELECT COUNT(*) FROM portal_accesos')->fetchColumn());
        $this->assertSame(422, $this->post('/portal', ['email' => 'no es mail'])->status);
    }

    public function testSoloVeLoSuyoYNoLosDePrueba(): void
    {
        $mio = $this->factura();
        $ajeno = $this->factura('990002', ['forma_pago' => '1', 'fpago' => 'EF']);
        $prueba = $this->emitir($this->pedido())['id']; // interno: es de prueba
        $this->get('/portal/acceso/' . $this->link('otro@x.com'));
        $body = $this->get('/portal/comprobantes')->body;
        $this->assertStringContainsString('/portal/comprobantes/' . $mio . '/pdf', $body);
        $this->assertStringNotContainsString('/portal/comprobantes/' . $prueba . '/pdf', $body, 'los de prueba no se muestran');
        $this->assertSame(404, $this->get('/portal/comprobantes/' . $ajeno . '/pdf')->status, 'el de otro cliente no');
        $this->assertSame(404, $this->get('/portal/comprobantes/' . $prueba . '/pdf')->status);
        $this->assertSame(200, $this->get('/portal/comprobantes', ['cliente' => '990002'])->status);
        $this->assertStringNotContainsString('CONSUMIDOR FINAL', $this->get('/portal/comprobantes', ['cliente' => '990002'])->body, 'no puede elegir un cliente que no es suyo');
    }

    public function testUnMailConVariosClientesElige(): void
    {
        Db::pdo()->exec("UPDATE clientes SET emailfc = 'contador@x.com' WHERE codigo IN (990001, 990002)");
        $this->factura();
        $this->get('/portal/acceso/' . $this->link('contador@x.com'));
        $b = $this->get('/portal/comprobantes', ['cliente' => '990002'])->body;
        $this->assertStringContainsString('T CONSUMIDOR FINAL', $b);
        $this->assertStringContainsString('T CLIENTE RI SA', $b, 'aparece en el selector');
    }

    public function testSinSesionVencidoQuitadoOApagado(): void
    {
        $this->assertRedirect('/portal', $this->get('/portal/comprobantes'));
        $this->assertSame(410, $this->get('/portal/acceso/' . $this->link('otro@x.com', -1))->status, 'vencido');
        $this->assertSame(410, $this->get('/portal/acceso/' . str_repeat('a', 64))->status, 'inventado');

        $this->get('/portal/acceso/' . $this->link('otro@x.com'));
        Db::pdo()->exec("UPDATE clientes SET emailfc = 'nuevo@x.com' WHERE codigo = 990001");
        $this->assertRedirect('/portal', $this->get('/portal/comprobantes'), 'si sacan el mail de la ficha, pierde el acceso');

        Db::pdo()->exec("UPDATE configuracion SET valor = '0' WHERE clave = 'portal.activo'");
        Configuracion::reset();
        $this->assertSame(404, $this->get('/portal')->status, 'apagado no existe');
        $this->assertSame(404, $this->post('/portal', ['email' => 'otro@x.com'])->status);
    }

    public function testLimiteDePedidos(): void
    {
        $svc = new PortalService();
        $enviados = 0;
        $this->servidorSmtp();
        // el servidor de mentira atiende un solo envío: el primero sale y los demás se cuentan igual por la tabla
        $this->assertNotNull($svc->pedirLink('otro@x.com', '9.9.9.9', static fn ($t) => 'http://x/' . $t, 'EMPRESA'));
        Db::pdo()->exec("INSERT INTO portal_accesos (email, token_hash, vence, ip) VALUES
            ('otro@x.com', REPEAT('1', 64), NOW(), '9.9.9.9'), ('otro@x.com', REPEAT('2', 64), NOW(), '9.9.9.9')");
        $this->assertNull($svc->pedirLink('otro@x.com', '9.9.9.9', static fn ($t) => 'http://x/' . $t, 'EMPRESA'), 'más de 3 en 15 minutos: no manda');
        $this->assertSame(3, (int) Db::pdo()->query('SELECT COUNT(*) FROM portal_accesos')->fetchColumn());
    }
}
