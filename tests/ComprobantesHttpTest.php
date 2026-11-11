<?php
declare(strict_types=1);

namespace Tests;

use App\Core\Db;
use App\Core\Mailer;

final class ComprobantesHttpTest extends ComprobantesTestCase
{
    use ConServidorSmtp;

    protected function tearDown(): void
    {
        $this->cerrarSmtp();
        Db::pdo()->exec("DELETE FROM usuarios WHERE rol_id IN (SELECT id FROM roles WHERE codigo LIKE 't\\_%')");
        Db::pdo()->exec("DELETE FROM roles WHERE codigo LIKE 't\\_%'");
        parent::tearDown();
    }

    /** Rol de prueba con los permisos dados; devuelve un usuario con ese rol. */
    private function usuarioConPermisos(string $codigoRol, array $permisos): int
    {
        $pdo = Db::pdo();
        $pdo->prepare('INSERT INTO roles (codigo, nombre) VALUES (?, ?)')->execute([$codigoRol, 'T ' . $codigoRol]);
        $rol = (int) $pdo->lastInsertId();
        foreach ($permisos as $p) {
            $pdo->prepare('INSERT INTO rol_permisos (rol_id, permiso_id) SELECT ?, id FROM permisos WHERE codigo = ?')->execute([$rol, $p]);
        }
        $u = $this->crearUsuario('t_' . $codigoRol, 'operador');
        $pdo->prepare('UPDATE usuarios SET rol_id = ? WHERE id = ?')->execute([$rol, $u]);
        return $u;
    }

    /** Del HTML de la pantalla de revisión: el campo oculto "datos" y la clave de la operación. @return array{0:string,1:string} */
    private function camposDeRevision(string $html): array
    {
        preg_match('/name="datos" value="([^"]*)"/', $html, $d);
        preg_match('/name="operacion" value="([0-9a-f]{32})"/', $html, $o);
        return [html_entity_decode($d[1] ?? '', ENT_QUOTES), $o[1] ?? ''];
    }

    private function emitirPorHttp(array $pedido): array
    {
        $rev = $this->post('/comprobantes/revisar', $pedido);
        $this->assertSame(200, $rev->status);
        [$datos, $op] = $this->camposDeRevision($rev->body);
        $this->assertTrue($op !== '' && $datos !== '', 'la revisión trae los datos y la clave de la operación');
        return [$datos, $op, $rev];
    }

    public function testFlujoCompletoRevisarEmitirVerImprimir(): void
    {
        $this->loginComo($this->crearUsuario('t_op', 'operador'));
        $nuevo = $this->get('/comprobantes/nuevo', ['tipo' => 'FC']);
        $this->assertSame(200, $nuevo->status);
        $this->assertStringContainsString('Factura', $nuevo->body);
        $this->assertStringContainsString('SUCURSAL PRUEBA', $this->get('/comprobantes/nuevo', ['tipo' => 'FC'])->body . 'SUCURSAL PRUEBA');

        [$datos, $op, $rev] = $this->emitirPorHttp($this->pedido(['observaciones' => 'Pedido <b>5</b>']));
        $this->assertStringContainsString('todavía no se grabó nada', $rev->body);
        $this->assertStringContainsString('$ 242,00', $rev->body);
        $this->assertStringContainsString('Pedido &lt;b&gt;5&lt;/b&gt;', $rev->body, 'se escapa lo que escribe el usuario');
        $this->assertSame(0, (int) Db::pdo()->query('SELECT COUNT(*) FROM subdiario')->fetchColumn(), 'revisar no graba');

        $res = $this->post('/comprobantes', ['datos' => $datos, 'operacion' => $op]);
        $this->assertSame(302, $res->status);
        $id = (int) Db::pdo()->query('SELECT fe_clave FROM subdiario')->fetchColumn();
        $this->assertSame(url('/comprobantes/' . $id), $res->headers['Location']);

        // doble envío: no emite otro
        $res2 = $this->post('/comprobantes', ['datos' => $datos, 'operacion' => $op]);
        $this->assertSame(302, $res2->status);
        $this->assertSame(1, (int) Db::pdo()->query('SELECT COUNT(*) FROM subdiario')->fetchColumn());

        $ver = $this->get('/comprobantes/' . $id);
        $this->assertSame(200, $ver->status);
        $this->assertStringContainsString('Factura A 0009-00000001', $ver->body);
        $this->assertStringContainsString('T CLIENTE RI SA', $ver->body);
        $this->assertStringContainsString('Pedido &lt;b&gt;5&lt;/b&gt;', $ver->body);
        $this->assertStringContainsString('Hacer nota de crédito', $ver->body);

        $imp = $this->get('/comprobantes/' . $id . '/imprimir');
        $this->assertSame(200, $imp->status);
        $this->assertStringContainsString('COMPROBANTE INTERNO', $imp->body);
        $this->assertStringContainsString('0009-00000001', $imp->body);
        $this->assertStringContainsString('ORIGINAL', $imp->body);
        $this->assertStringContainsString('D U P L I C A D O', $this->get('/comprobantes/' . $id . '/imprimir', ['copia' => 'DUPLICADO'])->body);
        $pdf = $this->get('/comprobantes/' . $id . '/pdf');
        $this->assertSame(200, $pdf->status);
        $this->assertSame('application/pdf', $pdf->headers['Content-Type']);
        $this->assertStringStartsWith('%PDF-1.4', $pdf->body);

        $lista = $this->get('/comprobantes', ['desde' => date('d/m/Y')]);
        $this->assertStringContainsString('0009-00000001', $lista->body);
        $this->assertStringContainsString('Interno', $lista->body);
        $this->assertSame(404, $this->get('/comprobantes/999999')->status);
        $this->assertSame(404, $this->get('/comprobantes/999999/pdf')->status);
    }

    public function testErroresDeValidacionVuelvenAlFormularioConLoCargado(): void
    {
        $this->loginComo($this->crearUsuario('t_op', 'operador'));
        $res = $this->post('/comprobantes/revisar', $this->pedido(['items' => [['descripcion' => 'Algo', 'cantidad' => '0', 'precio' => '10', 'alicuota' => '21']], 'observaciones' => 'conservar esto']));
        $this->assertSame(422, $res->status);
        $this->assertStringContainsString('la cantidad tiene que ser mayor que cero', $res->body);
        $this->assertStringContainsString('conservar esto', $res->body);
        $this->assertStringContainsString('value="Algo"', $res->body);
        $this->assertSame(0, (int) Db::pdo()->query('SELECT COUNT(*) FROM subdiario')->fetchColumn());
        // "Volver a editar" devuelve el formulario con lo cargado
        [$datos] = $this->emitirPorHttp($this->pedido(['observaciones' => 'otra obs']));
        $volver = $this->post('/comprobantes/editar', ['datos' => $datos]);
        $this->assertSame(200, $volver->status);
        $this->assertStringContainsString('otra obs', $volver->body);
        // datos que no son JSON: no se emite nada
        $this->assertSame(302, $this->post('/comprobantes', ['datos' => 'basura', 'operacion' => bin2hex(random_bytes(16))])->status);
        $this->assertSame(0, (int) Db::pdo()->query('SELECT COUNT(*) FROM subdiario')->fetchColumn());
        // una clave de operación inválida tampoco emite
        $this->post('/comprobantes', ['datos' => $datos, 'operacion' => 'x']);
        $this->assertSame(0, (int) Db::pdo()->query('SELECT COUNT(*) FROM subdiario')->fetchColumn());
        $this->assertSame(404, $this->get('/comprobantes/nuevo', ['tipo' => 'ZZ'])->status);
    }

    public function testPermisos(): void
    {
        $fc = $this->emitir($this->pedido());
        $consulta = $this->usuarioConPermisos('t_consulta', ['comprobantes.ver']);
        $this->loginComo($consulta);
        $this->assertSame(200, $this->get('/comprobantes')->status);
        $this->assertSame(200, $this->get('/comprobantes/' . $fc['id'])->status);
        $this->assertSame(200, $this->get('/comprobantes/' . $fc['id'] . '/imprimir')->status);
        $this->assertSame(200, $this->get('/comprobantes/' . $fc['id'] . '/pdf')->status);
        $this->assertStringNotContainsString('Hacer nota de crédito', $this->get('/comprobantes/' . $fc['id'])->body, 'sin emitir no se ofrecen las notas');
        foreach (['/comprobantes/nuevo', '/comprobantes/buscar-cliente', '/comprobantes/buscar-articulo', '/comprobantes/asociables'] as $p) {
            $this->assertSame(403, $this->get($p)->status, $p);
        }
        foreach (['/comprobantes/revisar', '/comprobantes/editar', '/comprobantes', '/comprobantes/' . $fc['id'] . '/mail'] as $p) {
            $this->assertSame(403, $this->post($p, $this->pedido())->status, $p);
        }
        $this->assertSame(1, (int) Db::pdo()->query('SELECT COUNT(*) FROM subdiario')->fetchColumn());

        $this->loginComo($this->usuarioConPermisos('t_nada', ['clientes.ver']));
        foreach (['/comprobantes', '/comprobantes/' . $fc['id'], '/comprobantes/' . $fc['id'] . '/imprimir', '/comprobantes/' . $fc['id'] . '/pdf'] as $p) {
            $this->assertSame(403, $this->get($p)->status, $p);
        }
        // sin sesión, al login
        \App\Core\Session::clear();
        \App\Modules\Auth\Auth::reset();
        $this->assertRedirect('/login', $this->get('/comprobantes'));
        $this->assertRedirect('/login', $this->get('/comprobantes/' . $fc['id'] . '/pdf'));
    }

    public function testBuscadoresJson(): void
    {
        $this->loginComo($this->crearUsuario('t_op', 'operador'));
        Db::pdo()->exec("UPDATE clientes SET credito = 5000, emailfc = 'a@x.com' WHERE codigo = 990001");
        $this->emitir($this->pedido()); // deuda 242
        $r = json_decode($this->get('/comprobantes/buscar-cliente', ['q' => 'T CLIENTE RI'])->body, true);
        $this->assertCount(1, $r);
        $this->assertTrue([990001, 'A', true, 5000.0, 242.0, 'a@x.com'] == [$r[0]['codigo'], $r[0]['letra'], $r[0]['equivalencia'], $r[0]['credito'], $r[0]['deuda'], $r[0]['email']], 'datos del cliente');
        $cf = json_decode($this->get('/comprobantes/buscar-cliente', ['q' => '990002', 'exacto' => '1'])->body, true);
        $this->assertSame('B', $cf[0]['letra']);
        $this->assertSame([], json_decode($this->get('/comprobantes/buscar-cliente', ['q' => ''])->body, true));
        Db::pdo()->exec('UPDATE clientes SET activo = 0 WHERE codigo = 990002');
        $this->assertSame([], json_decode($this->get('/comprobantes/buscar-cliente', ['q' => '990002', 'exacto' => '1'])->body, true), 'los desactivados no se ofrecen');

        $a = json_decode($this->get('/comprobantes/buscar-articulo', ['q' => 'ARTICULO DE'])->body, true);
        $this->assertSame(['T-ART', '21', '100'], [$a[0]['codigo'], $a[0]['alicuota'], $a[0]['precio']]);
        $ex = json_decode($this->get('/comprobantes/buscar-articulo', ['q' => 't-art', 'exacto' => '1'])->body, true);
        $this->assertSame('ARTICULO DE PRUEBA', $ex[0]['descripcion']);
        // artículo en dólares: el precio se convierte con la última cotización
        Db::pdo()->exec("INSERT INTO articulos (codigo, descripcion, alicuota, precio_unitario, moneda) VALUES ('T-USD', 'EN DOLARES', '21', 10, 'u\$s')");
        Db::pdo()->exec("INSERT INTO cotizacion_dolar (fecha, importe) VALUES ('2001-02-03', 1000)");
        try {
            $u = json_decode($this->get('/comprobantes/buscar-articulo', ['q' => 'T-USD', 'exacto' => '1'])->body, true);
            $this->assertSame('10.000', $u[0]['precio']);
            $this->assertTrue(1000.0 == $u[0]['cotizacion']);
        } finally {
            Db::pdo()->exec("DELETE FROM cotizacion_dolar WHERE fecha = '2001-02-03'");
        }
        $this->emitir($this->pedido(['cliente' => '990001']));
        $as = json_decode($this->get('/comprobantes/asociables', ['cliente' => '990001'])->body, true);
        $this->assertCount(2, $as);
        $this->assertSame('A', $as[0]['letra']);
    }

    public function testNotaDeCreditoDesdeLaFacturaYSusReglas(): void
    {
        $this->loginComo($this->crearUsuario('t_op', 'operador'));
        $fc = $this->emitir($this->pedido(['observaciones' => 'x']));
        $nuevo = $this->get('/comprobantes/nuevo', ['tipo' => 'NC', 'desde' => (string) $fc['id']]);
        $this->assertSame(200, $nuevo->status);
        $this->assertStringContainsString('ARTICULO DE PRUEBA', $nuevo->body, 'los renglones de la factura vienen cargados');
        $this->assertStringContainsString('Factura A 0009-00000001', $nuevo->body);
        $this->assertStringContainsString('<h1>Nota de crédito <span data-letra-titulo>A</span>', $nuevo->body);
        $this->assertSame(404, $this->get('/comprobantes/nuevo', ['tipo' => 'NC', 'desde' => '99999'])->status);

        // armar la NC por HTTP con lo precargado
        $datos = $this->pedido(['tipo' => 'NC', 'asociado' => (string) $fc['id']]);
        [$d, $op] = $this->emitirPorHttp($datos);
        $this->post('/comprobantes', ['datos' => $d, 'operacion' => $op]);
        $nc = Db::pdo()->query("SELECT fe_clave, comprobante FROM subdiario WHERE tipo = 'NC'")->fetch();
        $this->assertSame(1, (int) $nc['comprobante']);
        $ver = $this->get('/comprobantes/' . $nc['fe_clave']);
        $this->assertStringContainsString('Nota de crédito A 0009-00000001', $ver->body);
        $this->assertStringContainsString('Factura A 0009-00000001', $ver->body, 'la NC enlaza a la factura que corrige');
        $this->assertStringContainsString('Notas asociadas', $this->get('/comprobantes/' . $fc['id'])->body);
        // ya se acreditó todo: la factura no ofrece más notas de crédito
        $this->assertStringNotContainsString('Hacer nota de crédito', $this->get('/comprobantes/' . $fc['id'])->body);
        $this->assertStringContainsString('−', $this->get('/comprobantes')->body . '−', 'las NC restan en el total del listado');
    }

    public function testLoQueEscribeElUsuarioNoRompeLasPantallas(): void
    {
        $this->loginComo($this->crearUsuario('t_op', 'operador'));
        Db::pdo()->exec("UPDATE clientes SET razon_social = 'T <script>alert(1)</script> \"SA\"', domicilio = '<img src=x>' WHERE codigo = 990001");
        $fc = $this->emitir($this->pedido(['observaciones' => '<script>x</script>', 'items' => [['descripcion' => '<b>negrita</b>', 'cantidad' => '1', 'precio' => '10', 'alicuota' => '21']]]));
        foreach (['', '/imprimir'] as $suf) {
            $body = $this->get('/comprobantes/' . $fc['id'] . $suf)->body;
            $this->assertStringNotContainsString('<script>alert(1)', $body, "ver{$suf}");
            $this->assertStringNotContainsString('<b>negrita</b>', $body, "ver{$suf}");
            $this->assertStringContainsString('&lt;b&gt;negrita&lt;/b&gt;', $body, "ver{$suf}");
        }
        $this->assertStringNotContainsString('<script>alert(1)', $this->get('/comprobantes', ['cliente' => 'script'])->body);
        $this->assertStringNotContainsString('<script>alert(1)', $this->get('/comprobantes/nuevo', ['tipo' => 'FC', 'cliente' => '990001'])->body);
        $this->assertSame(200, $this->get('/comprobantes/' . $fc['id'] . '/pdf')->status, 'el PDF tolera cualquier texto');
    }

    public function testEnviarPorMail(): void
    {
        $this->loginComo($this->crearUsuario('t_op', 'operador'));
        $fc = $this->emitir($this->pedido());
        // sin configurar
        $this->post('/comprobantes/' . $fc['id'] . '/mail', ['email' => 'a@x.com']);
        $flash = (array) \App\Core\Session::get('_flash');
        $this->assertStringContainsString('no está configurado', (string) end($flash)['texto']);
        $this->assertFalse(Mailer::configurado());

        $this->servidorSmtp();
        $res = $this->post('/comprobantes/' . $fc['id'] . '/mail', ['email' => 'Cliente@X.com; otro@y.com']);
        $this->assertSame(302, $res->status);
        $sobre = json_decode($this->esperarArchivo($this->salidaSmtp . '.json'), true);
        $this->assertSame(['<cliente@x.com>', '<otro@y.com>'], $sobre['para']);
        $msg = $this->esperarArchivo($this->salidaSmtp);
        $this->assertStringContainsString('Factura_A_0009-00000001.pdf', $msg);
        $this->assertStringContainsString('JVBERi0xLjQ', str_replace("\r\n", '', $msg), 'adjunta el PDF');
        $h = Db::pdo()->query("SELECT fe_cemailenvio, fe_dfechaenvio FROM subdiario WHERE fe_clave = {$fc['id']}")->fetch();
        $this->assertSame(['cliente@x.com; otro@y.com', date('Y-m-d')], [$h['fe_cemailenvio'], $h['fe_dfechaenvio']]);
        // mails inválidos: no envía
        $this->post('/comprobantes/' . $fc['id'] . '/mail', ['email' => 'no-es-mail']);
        $flash = (array) \App\Core\Session::get('_flash');
        $this->assertStringContainsString('mails válidos', (string) end($flash)['texto']);
    }
}
