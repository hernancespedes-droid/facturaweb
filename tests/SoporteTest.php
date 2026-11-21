<?php
declare(strict_types=1);

namespace Tests;

use App\Core\Config;
use App\Core\Db;
use App\Http\Request;
use App\Modules\Instancias\InstanciasService;
use App\Modules\Soporte\SoporteCliente;
use App\Modules\Soporte\SoporteService;

/**
 * Tickets de soporte: el cliente abre y sigue sus tickets, O&S los administra en la bandeja del master y se avisa por mail.
 * Un cliente habla con el master por la API firmada; acá la «red» es SoporteCliente::$transporte, que despacha el pedido en el master.
 */
final class SoporteTest extends DbTestCase
{
    private const HOST = 'tsoporte.prueba.test';
    private const OTRO = 'totro.prueba.test';
    private const PNG = "\x89PNG\r\n\x1a\n" . 'datos-de-prueba';

    /** @var array<int,array{para:string[],asunto:string,texto:string}> */
    private array $mails = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->limpiar();
        $this->mails = [];
        SoporteService::$enviador = function (array $para, string $asunto, string $texto): ?string {
            $this->mails[] = ['para' => $para, 'asunto' => $asunto, 'texto' => $texto];
            return null;
        };
        (new SoporteService())->guardarMailsAviso('soporte@oys.test, otro@oys.test', null);
    }

    protected function tearDown(): void
    {
        $this->limpiar();
        SoporteService::$enviador = null;
        SoporteCliente::$transporte = null;
        foreach (['instancia', 'soporte', 'modulos'] as $k) {
            Config::set($k, []);
        }
        foreach ([self::HOST, self::OTRO] as $h) {
            $d = InstanciasService::raiz() . '/' . $h;
            @unlink($d . '/config.php');
            @unlink($d . '/info.json');
            @rmdir($d);
        }
        parent::tearDown();
    }

    private function limpiar(): void
    {
        $pdo = Db::pdo();
        $pdo->exec('DELETE FROM soporte_adjuntos');
        $pdo->exec('DELETE FROM soporte_mensajes');
        $pdo->exec('DELETE FROM soporte_tickets');
        $pdo->exec("UPDATE configuracion SET valor = '' WHERE clave = 'soporte.mail_aviso'");
        \App\Modules\Configuracion\Configuracion::reset();
    }

    private function ultimoTicket(): array
    {
        return Db::pdo()->query('SELECT * FROM soporte_tickets ORDER BY id DESC LIMIT 1')->fetch();
    }

    /** Crea una instalación de cliente de mentira (solo la carpeta con su config) y le escribe la clave de soporte como lo hace el master. */
    private function clienteFalso(string $host, string $nombre): string
    {
        $d = InstanciasService::raiz() . '/' . $host;
        @mkdir($d, 0775, true);
        file_put_contents($d . '/config.php', "<?php\nreturn " . var_export(['db' => ['name' => 'nada'], 'suscripcion' => ['estado' => 'activa']], true) . ";\n");
        file_put_contents($d . '/info.json', json_encode(['nombre' => $nombre]));
        InstanciasService::asegurarSoporte('https://master.prueba.test');
        return (string) InstanciasService::claveSoporte($host);
    }

    /** Pasa a ser la instalación del cliente $host: su config y la «red» hacia el master (que corre en este mismo proceso). */
    private function serCliente(string $host, string $clave): void
    {
        Config::set('instancia', ['host' => $host, 'dir' => '/tmp/x']);
        Config::set('soporte', ['url' => 'https://master.prueba.test', 'clave' => $clave]);
        SoporteCliente::$transporte = function (string $metodo, string $url, array $cab, ?array $cuerpo): array {
            $partes = parse_url($url);
            parse_str((string) ($partes['query'] ?? ''), $query);
            $instancia = Config::get('instancia');
            Config::set('instancia', []); // el pedido lo atiende el master
            try {
                $res = $this->app->handle(new Request($metodo, (string) $partes['path'], $query, $cuerpo ?? [],
                    ['HTTP_X_FW_INSTANCIA' => $cab['X-FW-Instancia'], 'HTTP_X_FW_CLAVE' => $cab['X-FW-Clave']]));
            } finally {
                Config::set('instancia', $instancia);
            }
            return ['status' => $res->status, 'body' => $res->body];
        };
    }

    public function testEnLaPrincipalSeAbreSeAvisaYOysResponde(): void
    {
        $ana = $this->crearUsuario('t_sop_ana', 'operador');
        Db::pdo()->prepare('UPDATE usuarios SET email = ? WHERE id = ?')->execute(['ana@cliente.test', $ana]);
        $this->loginComo($ana);
        $this->assertSame(200, $this->get('/soporte')->status);
        $form = $this->get('/soporte/nuevo', ['pantalla' => '/comprobantes/nuevo?tipo=FC']);
        $this->assertStringContainsString('ana@cliente.test', $form->body);

        $res = $this->post('/soporte/nuevo', ['asunto' => '', 'tipo' => 'error', 'descripcion' => 'x']);
        $this->assertSame(422, $res->status);
        $this->assertStringContainsString('Escribí un asunto', $res->body);

        $res = $this->post('/soporte/nuevo', ['asunto' => 'No emite factura B', 'tipo' => 'arca', 'prioridad' => 'urgente',
            'descripcion' => 'Sale un error al emitir.', 'usuario_mail' => 'ana@cliente.test', 'pantalla' => '/comprobantes/nuevo?tipo=FC']);
        $t = $this->ultimoTicket();
        $this->assertRedirect('/soporte/' . $t['id'], $res);
        $this->assertSame(['', 'nuevo', 1, 'Nombre t_sop_ana', '/comprobantes/nuevo?tipo=FC'], [$t['instancia'], $t['estado'], (int) $t['pendiente'], $t['usuario'], $t['pantalla']]);
        $this->assertSame(trim((string) file_get_contents(\App\App::root() . '/VERSION')), $t['version']);
        $this->assertCount(1, $this->mails);
        $this->assertSame(['soporte@oys.test', 'otro@oys.test'], $this->mails[0]['para']);
        $this->assertStringContainsString('URGENTE', $this->mails[0]['asunto']);
        $this->assertStringContainsString('Sale un error al emitir.', $this->mails[0]['texto']);
        $this->assertStringContainsString('No emite factura B', $this->get('/soporte')->body);

        // O&S: nota interna (el cliente no la ve, no se le avisa) y respuesta (va por mail al cliente, queda esperando al cliente).
        $this->loginComo($this->crearUsuario('t_sop_oys', 'master'));
        $bandeja = $this->get('/soporte/bandeja');
        $this->assertSame(200, $bandeja->status);
        $this->assertStringContainsString('No emite factura B', $bandeja->body);
        $this->assertStringContainsString('Para contestar: <b>1</b>', $bandeja->body);
        $this->assertSame(200, $this->get('/soporte/bandeja/' . $t['id'])->status);
        $this->post('/soporte/bandeja/' . $t['id'] . '/responder', ['texto' => 'Revisar el certificado.', 'interno' => '1']);
        $this->assertCount(1, $this->mails);
        $this->assertSame('en_curso', $this->ultimoTicket()['estado']);
        $this->post('/soporte/bandeja/' . $t['id'] . '/responder', ['texto' => 'Ya está, probá de nuevo.']);
        $t2 = $this->ultimoTicket();
        $this->assertSame(['esperando', 0], [$t2['estado'], (int) $t2['pendiente']]);
        $this->assertCount(2, $this->mails);
        $this->assertSame(['ana@cliente.test'], $this->mails[1]['para']);
        $this->assertStringContainsString('Ya está, probá de nuevo.', $this->mails[1]['texto']);

        // El cliente ve la respuesta pero no la nota; contesta (vuelve a O&S) y lo cierra.
        $this->loginComo($ana);
        $ver = $this->get('/soporte/' . $t['id']);
        $this->assertStringContainsString('Ya está, probá de nuevo.', $ver->body);
        $this->assertStringNotContainsString('Revisar el certificado', $ver->body);
        $this->post('/soporte/' . $t['id'] . '/responder', ['texto' => 'Sigue igual.']);
        $t3 = $this->ultimoTicket();
        $this->assertSame(['en_curso', 1], [$t3['estado'], (int) $t3['pendiente']]);
        $this->assertCount(3, $this->mails);
        $this->assertStringContainsString('Respuesta del cliente', $this->mails[2]['texto']);
        $this->post('/soporte/' . $t['id'] . '/estado', ['accion' => 'cerrar']);
        $this->assertSame('cerrado', $this->ultimoTicket()['estado']);
        $this->assertStringNotContainsString('No emite factura B', $this->get('/soporte')->body, 'cerrado no está en «abiertos»');
        $this->assertStringContainsString('No emite factura B', $this->get('/soporte', ['estado' => 'cerrados'])->body);
    }

    public function testPermisosYMenu(): void
    {
        $ana = $this->crearUsuario('t_sop_ana', 'operador');
        $this->loginComo($ana);
        $this->assertTrue(\App\Core\Menu::visible('soporte'));
        $this->assertFalse(\App\Core\Menu::visible('soporte_bandeja'));
        foreach (['/soporte/bandeja', '/soporte/bandeja/1'] as $r) {
            $this->assertSame(403, $this->get($r)->status, $r);
        }
        $this->assertSame(403, $this->post('/soporte/bandeja/configuracion', ['mail_aviso' => 'yo@x.test'])->status);
        $this->assertSame(403, $this->post('/soporte/bandeja/1/responder', ['texto' => 'x'])->status);

        // Sin el permiso (se lo sacaron al rol) no entra.
        $rol = $this->rolId('operador');
        $pdo = Db::pdo();
        $pdo->prepare("DELETE rp FROM rol_permisos rp JOIN permisos p ON p.id = rp.permiso_id WHERE rp.rol_id = ? AND p.codigo = 'soporte.usar'")->execute([$rol]);
        try {
            $this->loginComo($ana);
            $this->assertSame(403, $this->get('/soporte')->status);
            $this->assertSame(403, $this->post('/soporte/nuevo', ['asunto' => 'x', 'tipo' => 'error', 'descripcion' => 'xxxxxx'])->status);
        } finally {
            $pdo->prepare("INSERT IGNORE INTO rol_permisos (rol_id, permiso_id) SELECT ?, id FROM permisos WHERE codigo = 'soporte.usar'")->execute([$rol]);
        }

        // El Master en la principal usa la bandeja; dentro de un cliente la bandeja no existe ni para su Master.
        $this->loginComo($this->crearUsuario('t_sop_oys', 'master'));
        $this->assertTrue(\App\Core\Menu::visible('soporte_bandeja'));
        $this->assertFalse(\App\Core\Menu::visible('soporte'));
        Config::set('instancia', ['host' => self::HOST, 'dir' => '/tmp/x']);
        $this->assertFalse(\App\Core\Menu::visible('soporte_bandeja'));
        $this->assertTrue(\App\Core\Menu::visible('soporte'));
        $this->assertSame(403, $this->get('/soporte/bandeja')->status);
        // Módulo apagado para el cliente desde el master: ni menú ni ruta.
        Config::set('modulos', ['soporte' => false]);
        $this->assertFalse(\App\Core\Menu::visible('soporte'));
        $this->assertSame(404, $this->get('/soporte')->status);
    }

    public function testApiSoloConLaClaveDelClienteYCadaUnoVeLosSuyos(): void
    {
        $clave = $this->clienteFalso(self::HOST, 'Cliente Uno SA');
        $claveOtro = $this->clienteFalso(self::OTRO, 'Otro SRL');
        $this->assertStringStartsWith('fws_', $clave);
        $this->assertTrue($clave !== $claveOtro);
        // La clave no cambia al volver a entrar al panel; la dirección del master sí se actualiza.
        InstanciasService::asegurarSoporte('https://master2.prueba.test');
        $c = InstanciasService::config(self::HOST);
        $this->assertSame([$clave, 'https://master2.prueba.test'], [$c['soporte']['clave'], $c['soporte']['url']]);
        $this->assertSame('activa', $c['suscripcion']['estado'], 'el resto del config queda igual');

        $api = fn (string $m, string $p, array $post, string $host, string $k) => $this->app->handle(new Request($m, $p, [], $post, ['HTTP_X_FW_INSTANCIA' => $host, 'HTTP_X_FW_CLAVE' => $k]));
        $nuevo = ['asunto' => 'Consulta', 'tipo' => 'consulta', 'descripcion' => 'Cómo hago un recibo', 'usuario' => 'Juan'];
        $this->assertSame(401, $api('POST', '/api/v1/soporte/tickets', $nuevo, self::HOST, 'fws_mal')->status);
        $this->assertSame(401, $api('POST', '/api/v1/soporte/tickets', $nuevo, self::HOST, $claveOtro)->status, 'la clave de otro cliente no sirve');
        $this->assertSame(401, $api('POST', '/api/v1/soporte/tickets', $nuevo, 'noexiste.prueba.test', $clave)->status);
        $res = $api('POST', '/api/v1/soporte/tickets', $nuevo, self::HOST, $clave);
        $this->assertSame(200, $res->status, $res->body);
        $id = (int) json_decode($res->body, true)['id'];
        $t = $this->ultimoTicket();
        $this->assertSame([self::HOST, 'Cliente Uno SA'], [$t['instancia'], $t['instancia_nombre']]);
        $this->assertStringContainsString('Cliente Uno SA', $this->mails[0]['asunto']);

        // El mismo formulario reenviado (el master tardó y el cliente reintentó) no duplica el ticket ni el aviso.
        $conOp = $nuevo + ['operacion' => 'op1234567890abcd'];
        $id1 = (int) json_decode($api('POST', '/api/v1/soporte/tickets', $conOp, self::HOST, $clave)->body, true)['id'];
        $id2 = (int) json_decode($api('POST', '/api/v1/soporte/tickets', $conOp, self::HOST, $clave)->body, true)['id'];
        $this->assertSame($id1, $id2);
        $this->assertCount(2, $this->mails);
        $this->assertTrue($id1 !== (int) json_decode($api('POST', '/api/v1/soporte/tickets', $conOp, self::OTRO, $claveOtro)->body, true)['id'], 'la clave es por cliente');
        Db::pdo()->prepare('DELETE m FROM soporte_mensajes m JOIN soporte_tickets t ON t.id = m.ticket_id WHERE t.operacion = ?')->execute(['op1234567890abcd']);
        Db::pdo()->prepare('DELETE FROM soporte_tickets WHERE operacion = ?')->execute(['op1234567890abcd']);

        $this->assertSame(404, $api('GET', '/api/v1/soporte/tickets/' . $id, [], self::OTRO, $claveOtro)->status, 'otro cliente no lo ve');
        $this->assertSame([], json_decode($api('GET', '/api/v1/soporte/tickets', [], self::OTRO, $claveOtro)->body, true)['tickets']);
        $this->assertSame(422, $api('POST', '/api/v1/soporte/tickets/' . $id . '/responder', ['texto' => 'x'], self::OTRO, $claveOtro)->status);
        $this->assertCount(1, json_decode($api('GET', '/api/v1/soporte/tickets', [], self::HOST, $clave)->body, true)['tickets']);

        // Dentro de una instalación de cliente la API no existe.
        Config::set('instancia', ['host' => self::HOST, 'dir' => '/tmp/x']);
        $this->assertSame(401, $api('GET', '/api/v1/soporte/tickets', [], self::HOST, $clave)->status);
    }

    public function testClienteRemotoAbreSigueYAdjunta(): void
    {
        $clave = $this->clienteFalso(self::HOST, 'Cliente Uno SA');
        $this->serCliente(self::HOST, $clave);
        $ana = $this->crearUsuario('t_sop_ana', 'operador');
        $this->loginComo($ana);
        $tmp = tempnam(sys_get_temp_dir(), 'sop');
        file_put_contents($tmp, self::PNG);
        $res = $this->app->handle(Request::make('POST', '/soporte/nuevo', ['asunto' => 'Pantalla rara', 'tipo' => 'error', 'descripcion' => 'Mirá la captura.',
            \App\Core\Csrf::CAMPO => \App\Core\Csrf::token()], [], ['adjunto' => ['name' => 'captura.png', 'tmp_name' => $tmp, 'error' => UPLOAD_ERR_OK, 'size' => strlen(self::PNG)]]));
        $t = $this->ultimoTicket();
        $this->assertRedirect('/soporte/' . $t['id'], $res);
        $this->assertSame(self::HOST, $t['instancia']);
        $ver = $this->get('/soporte/' . $t['id']);
        $this->assertSame(200, $ver->status);
        $this->assertStringContainsString('captura.png', $ver->body);
        $adj = (int) Db::pdo()->query('SELECT id FROM soporte_adjuntos ORDER BY id DESC LIMIT 1')->fetchColumn();
        $bajada = $this->get('/soporte/adjunto/' . $adj);
        $this->assertSame([200, self::PNG, 'image/png'], [$bajada->status, $bajada->body, $bajada->headers['Content-Type']]);

        // Un archivo que no es imagen ni PDF no se acepta.
        file_put_contents($tmp, '<?php echo 1;');
        $res = $this->app->handle(Request::make('POST', '/soporte/nuevo', ['asunto' => 'X', 'tipo' => 'error', 'descripcion' => 'xxxxxx',
            \App\Core\Csrf::CAMPO => \App\Core\Csrf::token()], [], ['adjunto' => ['name' => 'a.png', 'tmp_name' => $tmp, 'error' => UPLOAD_ERR_OK, 'size' => 13]]));
        $this->assertSame(422, $res->status);
        $this->assertStringContainsString('Solo se pueden adjuntar', $res->body);
        @unlink($tmp);

        // O&S lo marca resuelto; el cliente lo ve y lo reabre por la API.
        (new SoporteService())->responderOys((int) $t['id'], 'O&S', ['texto' => 'Arreglado en la 0.42.1', 'estado' => 'resuelto']);
        $ver = $this->get('/soporte/' . $t['id']);
        $this->assertStringContainsString('Arreglado en la 0.42.1', $ver->body);
        $this->assertStringContainsString('se cierra solo', $ver->body);
        $this->post('/soporte/' . $t['id'] . '/estado', ['accion' => 'reabrir']);
        $this->assertSame(['en_curso', 1], [$this->ultimoTicket()['estado'], (int) $this->ultimoTicket()['pendiente']]);

        // Master caído o clave borrada: se avisa sin romper la pantalla.
        Config::set('soporte', ['url' => 'https://master.prueba.test', 'clave' => 'fws_vieja']);
        $lista = $this->get('/soporte');
        $this->assertSame(200, $lista->status);
        $this->assertStringContainsString('no reconoció esta instalación', $lista->body);
        Config::set('soporte', []);
        $this->assertStringContainsString('todavía no está conectada', $this->get('/soporte/nuevo')->body);
    }

    public function testResueltoSeCierraSoloALosSieteDias(): void
    {
        $s = new SoporteService();
        $r = $s->crear('', ['asunto' => 'Viejo', 'tipo' => 'consulta', 'descripcion' => 'Una consulta', 'usuario' => 'X']);
        $s->responderOys($r['id'], 'O&S', ['texto' => 'Respondido', 'estado' => 'resuelto']);
        $this->assertSame(0, $s->cerrarVencidos());
        Db::pdo()->prepare('UPDATE soporte_tickets SET resuelto_en = ? WHERE id = ?')->execute([date('Y-m-d H:i:s', time() - 8 * 86400), $r['id']]);
        $this->assertSame([], $s->listarDe('', ['estado' => 'abiertos']));
        $this->assertSame('cerrado', $s->ticket($r['id'])['estado']);
        // Sin mails de aviso configurados no se manda nada (queda en la bandeja); un mail inválido no se guarda.
        $this->assertFalse($s->guardarMailsAviso('esto no es un mail', null)['ok']);
        $s->guardarMailsAviso('', null);
        $this->mails = [];
        $s->crear('', ['asunto' => 'Otro', 'tipo' => 'consulta', 'descripcion' => 'Otra consulta']);
        $this->assertCount(0, $this->mails);
    }
}
