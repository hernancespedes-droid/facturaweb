<?php
declare(strict_types=1);

namespace Tests;

use App\Core\Config;
use App\Modules\Instancias\InstanciasService;

/**
 * Panel de instalaciones de clientes. Usa una segunda base vacía, facturaweb_test_inst (crearla una vez con root:
 * CREATE DATABASE facturaweb_test_inst; GRANT ALL ON facturaweb_test_inst.* TO 'facturaweb'@'localhost'). Si no existe, la prueba
 * de alta se saltea avisando.
 */
final class InstanciasTest extends DbTestCase
{
    private const PRINCIPAL = 'panel.prueba.test';
    private const HOST = 'tcliente.prueba.test';
    private const BASE = 'facturaweb_test_inst';

    private ?string $principalPrevio = null;
    private ?string $bajasPrevio = null;
    private ?string $tmp = null;

    protected function setUp(): void
    {
        parent::setUp();
        $f = \App\App::root() . '/storage/instancias/_principal.php';
        $this->principalPrevio = is_file($f) ? (string) file_get_contents($f) : null;
        $b = InstanciasService::raiz() . '/_bajas.json';
        $this->bajasPrevio = is_file($b) ? (string) file_get_contents($b) : null;
        $_SERVER['HTTP_HOST'] = self::PRINCIPAL;
    }

    protected function tearDown(): void
    {
        unset($_SERVER['HTTP_HOST']);
        foreach ([self::HOST, 'tlleno.prueba.test'] as $h) {
            $this->borrar(InstanciasService::raiz() . '/' . $h);
        }
        $f = \App\App::root() . '/storage/instancias/_principal.php';
        $this->principalPrevio === null ? @unlink($f) : file_put_contents($f, $this->principalPrevio);
        $b = InstanciasService::raiz() . '/_bajas.json';
        $this->bajasPrevio === null ? @unlink($b) : file_put_contents($b, $this->bajasPrevio);
        if ($this->tmp !== null) {
            $this->borrar($this->tmp);
        }
        \App\Core\Db::pdo()->exec("DELETE FROM clientes WHERE cuit = 30712345671");
        \App\Core\Db::pdo()->exec("DELETE FROM instancias_resets WHERE instancia LIKE '%.prueba.test'");
        \App\Core\Db::pdo()->exec("DELETE m FROM soporte_mensajes m JOIN soporte_tickets t ON t.id = m.ticket_id WHERE t.instancia LIKE '%.prueba.test'");
        \App\Core\Db::pdo()->exec("DELETE FROM soporte_tickets WHERE instancia LIKE '%.prueba.test'");
        \App\Modules\Instancias\Promocion::$transporte = null;
        foreach (['instancia', 'suscripcion', 'modulos', 'panel'] as $k) {
            Config::set($k, []);
        }
        parent::tearDown();
    }

    public function testSoloElMasterYSoloEnLaPrincipal(): void
    {
        $this->loginComo($this->crearUsuario('t_adm', 'admin'));
        $this->assertSame(403, $this->get('/instancias')->status);
        $this->assertSame(403, $this->post('/instancias/nueva', ['nombre' => 'X'])->status);
        $this->assertSame(403, $this->post('/instancias/actualizar')->status);

        $this->loginComo($this->crearUsuario('t_mas', 'master'));
        $res = $this->get('/instancias');
        $this->assertSame(200, $res->status);
        $this->assertStringContainsString('Nuevo cliente', $res->body);
        $this->assertSame(200, $this->get('/instancias/nueva')->status);

        // Dentro de la instalación de un cliente el panel no existe, ni para su Master.
        Config::set('instancia', ['host' => self::HOST, 'dir' => '/tmp/x']);
        $this->assertSame(403, $this->get('/instancias')->status);
        $this->assertFalse(\App\Core\Menu::visible('instancias'));
    }

    public function testValidaLosDatosAntesDeTocarLaBase(): void
    {
        $this->loginComo($this->crearUsuario('t_mas', 'master'));
        $res = $this->post('/instancias/nueva', ['nombre' => '', 'cuit' => '20111111111', 'subdominio' => 'Mal Nombre', 'db_name' => '',
            'master_usuario' => 'oys', 'master_clave' => 'corta', 'master_confirmacion' => 'corta']);
        $this->assertSame(422, $res->status);
        foreach (['La razón social es obligatoria', 'CUIT inválido', 'Subdominio de letras', 'Completá la base', 'al menos'] as $txt) {
            $this->assertStringContainsString($txt, $res->body);
        }
        // La base de la principal nunca se usa para un cliente.
        $res = $this->post('/instancias/nueva', $this->datos(['db_name' => (string) Config::get('db.name'), 'db_host' => (string) Config::get('db.host')]));
        $this->assertStringContainsString('Esa es la base de la instalación principal', $res->body);
        $this->assertFalse(is_dir(InstanciasService::raiz() . '/' . self::HOST));
    }

    public function testAltaCompletaConfiguracionPorDireccionYActualizacion(): void
    {
        $pdo = $this->baseCliente();
        if ($pdo === null) {
            echo "      (se saltea: falta la base " . self::BASE . ")\n";
            return;
        }
        $this->loginComo($this->crearUsuario('t_mas', 'master'));
        $res = $this->post('/instancias/nueva', $this->datos());
        $this->assertSame(200, $res->status, substr($res->body, 0, 3000));
        $this->assertStringContainsString('Listo: ' . self::HOST, $res->body);
        $this->assertTrue(preg_match('/contraseña provisoria <b><code>([^<]+)<\/code>/', $res->body, $m) === 1);

        // La base del cliente: estructura completa, empresa y dos usuarios (el Administrador con clave provisoria).
        $this->assertSame('Cliente de Prueba SA', $pdo->query('SELECT empresa FROM parametros WHERE id = 1')->fetchColumn());
        $this->assertSame('30712345671', (string) $pdo->query('SELECT cuit FROM parametros WHERE id = 1')->fetchColumn());
        $usuarios = $pdo->query('SELECT u.usuario, u.debe_cambiar_clave, r.codigo, u.password_hash FROM usuarios u JOIN roles r ON r.id = u.rol_id ORDER BY u.usuario')->fetchAll();
        $this->assertCount(2, $usuarios);
        $this->assertSame(['admin', 1, 'admin'], [$usuarios[0]['usuario'], (int) $usuarios[0]['debe_cambiar_clave'], $usuarios[0]['codigo']]);
        $this->assertTrue(password_verify($m[1], $usuarios[0]['password_hash']));
        $this->assertSame(['tmaster', 'master'], [$usuarios[1]['usuario'], $usuarios[1]['codigo']]);

        // config/config.php elige la configuración por la dirección del pedido.
        $_SERVER['HTTP_HOST'] = self::HOST;
        $c = require \App\App::root() . '/config/config.php';
        $this->assertSame(self::BASE, $c['db']['name']);
        $this->assertSame(InstanciasService::raiz() . '/' . self::HOST, $c['instancia']['dir']);
        $this->assertSame(true, $c['licencia']['exigir']);
        $this->assertSame('', (string) ($c['mp']['access_token'] ?? ''), 'nada de local.php pasa a un cliente');
        $_SERVER['HTTP_HOST'] = 'otro.prueba.test';
        $c = require \App\App::root() . '/config/config.php';
        $this->assertSame('otro.prueba.test', $c['instancia']['desconocida'] ?? null, 'un subdominio sin instalación no ve la principal');
        $_SERVER['HTTP_HOST'] = self::PRINCIPAL . ':443';
        $c = require \App\App::root() . '/config/config.php';
        $this->assertFalse(isset($c['instancia']), 'la principal sigue respondiendo en su dirección');
        $_SERVER['HTTP_HOST'] = self::PRINCIPAL;

        // No se puede repetir la dirección; una base con tablas no se pisa y no deja carpeta a medias.
        $this->assertStringContainsString('Ya existe una instalación', $this->post('/instancias/nueva', $this->datos())->body);
        $res = $this->post('/instancias/nueva', $this->datos(['subdominio' => 'tlleno']));
        $this->assertStringContainsString('ya tiene tablas', $res->body);
        $this->assertFalse(is_dir(InstanciasService::raiz() . '/tlleno.prueba.test'));

        // Listado, edición (la contraseña del mail se conserva si no se escribe) y actualización de todas.
        $this->assertStringContainsString('Cliente de Prueba SA', $this->get('/instancias')->body);
        $this->assertSame(200, $this->get('/instancias/editar', ['host' => self::HOST])->status);
        $this->assertSame(404, $this->get('/instancias/editar', ['host' => '../../config'])->status);
        $this->assertRedirect('/instancias', $this->post('/instancias/editar', ['host' => self::HOST, 'afip_modo' => 'simulado', 'mail_host' => 'smtp.cliente.test',
            'mail_user' => 'f@cliente.test', 'mail_pass' => 'secreta', 'mail_from' => 'f@cliente.test']));
        $this->post('/instancias/editar', ['host' => self::HOST, 'afip_modo' => 'simulado', 'licencia' => '1', 'mail_host' => 'smtp.cliente.test',
            'mail_user' => 'f@cliente.test', 'mail_pass' => '', 'mail_from' => 'f@cliente.test']);
        $c = InstanciasService::config(self::HOST);
        $this->assertSame(['simulado', 'secreta', true], [$c['afip']['modo'], $c['mail']['pass'], $c['licencia']['exigir']]);
        $this->assertSame(422, $this->post('/instancias/editar', ['host' => self::HOST, 'afip_modo' => 'produccion', 'afip_config' => ''])->status);

        $r = InstanciasService::actualizarTodas();
        $this->assertSame('al día', $r[self::HOST]);
        $this->assertSame('al día', $r['Principal']);
    }

    public function testElMasterReseteaLaContraseñaDeUnUsuarioDelClienteYQuedaRegistrado(): void
    {
        $pdo = $this->baseCliente();
        if ($pdo === null) {
            echo "      (se saltea: falta la base " . self::BASE . ")\n";
            return;
        }
        $mas = $this->crearUsuario('t_mas', 'master');
        $this->loginComo($mas);
        $this->assertSame(200, $this->post('/instancias/nueva', $this->datos())->status);
        $admin = (int) $pdo->query("SELECT id FROM usuarios WHERE usuario = 'admin'")->fetchColumn();
        // Un usuario del cliente que se quedó afuera: bloqueado por intentos, con «recordarme», y otro inactivo.
        $pdo->exec("UPDATE usuarios SET debe_cambiar_clave = 0, intentos_fallidos = 5, bloqueado_hasta = NOW() + INTERVAL 1 HOUR WHERE id = {$admin}");
        $pdo->exec("INSERT INTO recordarme (selector, validador, usuario_id, vence_en) VALUES ('t_sel', 'x', {$admin}, NOW() + INTERVAL 1 DAY)");
        $pdo->exec("INSERT INTO usuarios (usuario, nombre, password_hash, rol_id, activo) SELECT 'cajera', 'Ana Caja', 'x', id, 0 FROM roles WHERE codigo = 'operador'");
        $cajera = (int) $pdo->lastInsertId();

        // Solo el Master del master.
        $adm = $this->crearUsuario('t_adm', 'admin');
        $this->loginComo($adm);
        $this->assertSame(403, $this->get('/instancias/usuarios', ['host' => self::HOST])->status);
        $this->assertSame(403, $this->post('/instancias/usuarios/resetear', ['host' => self::HOST, 'usuario_id' => (string) $admin])->status);
        $this->loginComo($mas);
        $this->assertSame(404, $this->get('/instancias/usuarios', ['host' => '../../config'])->status);

        $lista = $this->get('/instancias/usuarios', ['host' => self::HOST]);
        $this->assertSame(200, $lista->status);
        foreach (['admin', 'tmaster', 'Ana Caja', 'Bloqueado', 'Inactivo'] as $t) {
            $this->assertStringContainsString($t, $lista->body);
        }
        $this->assertStringContainsString('/instancias/usuarios?host=' . self::HOST, $this->get('/instancias/editar', ['host' => self::HOST])->body);

        // Un ticket del cliente y otro de otra instalación.
        $db = \App\Core\Db::pdo();
        $alta = $db->prepare("INSERT INTO soporte_tickets (instancia, asunto, tipo, estado, creado_en, actualizado_en) VALUES (?, 'No puedo entrar', 'consulta', 'nuevo', NOW(), NOW())");
        $alta->execute([self::HOST]);
        $ticket = (int) $db->lastInsertId();
        $alta->execute(['otra.prueba.test']);
        $ajeno = (int) $db->lastInsertId();
        $hashAntes = (string) $pdo->query("SELECT password_hash FROM usuarios WHERE id = {$admin}")->fetchColumn();
        $this->post('/instancias/usuarios/resetear', ['host' => self::HOST, 'usuario_id' => (string) $admin, 'ticket' => (string) $ajeno]);
        $this->assertSame($hashAntes, (string) $pdo->query("SELECT password_hash FROM usuarios WHERE id = {$admin}")->fetchColumn(), 'ticket de otra instalación: no cambia nada');

        $res = $this->post('/instancias/usuarios/resetear', ['host' => self::HOST, 'usuario_id' => (string) $admin, 'ticket' => (string) $ticket, 'motivo' => 'Perdió la clave']);
        $this->assertRedirect('/instancias/usuarios?host=' . self::HOST . '&ticket=' . $ticket, $res);
        $pantalla = $this->get('/instancias/usuarios', ['host' => self::HOST])->body;
        $this->assertTrue(preg_match('/<code data-clave>([^<]+)<\/code>/', $pantalla, $m) === 1, 'la contraseña se muestra');
        $this->assertStringNotContainsString($m[1], $this->get('/instancias/usuarios', ['host' => self::HOST])->body, 'una sola vez');

        $u = $pdo->query("SELECT password_hash, debe_cambiar_clave, intentos_fallidos, bloqueado_hasta FROM usuarios WHERE id = {$admin}")->fetch();
        $this->assertTrue(password_verify($m[1], $u['password_hash']));
        $this->assertSame([1, 0, null], [(int) $u['debe_cambiar_clave'], (int) $u['intentos_fallidos'], $u['bloqueado_hasta']]);
        $this->assertSame(0, (int) $pdo->query("SELECT COUNT(*) FROM recordarme WHERE usuario_id = {$admin}")->fetchColumn());

        $reg = $db->query("SELECT * FROM instancias_resets WHERE instancia = '" . self::HOST . "'")->fetchAll();
        $this->assertCount(1, $reg);
        $this->assertSame(['admin', 't_mas', $mas, $ticket, 'Perdió la clave', 0], [$reg[0]['usuario'], $reg[0]['por'], (int) $reg[0]['por_id'], (int) $reg[0]['ticket_id'], $reg[0]['motivo'], (int) $reg[0]['reactivado']]);
        $nota = $db->query("SELECT texto, interno FROM soporte_mensajes WHERE ticket_id = {$ticket}")->fetch();
        $this->assertSame(1, (int) $nota['interno'], 'nota interna: el cliente no la ve');
        $this->assertStringContainsString('«admin»', $nota['texto']);
        $this->assertStringNotContainsString($m[1], $nota['texto'], 'la contraseña nunca va al ticket');
        $this->assertSame(0, (int) $db->query('SELECT COUNT(*) FROM instancias_resets WHERE motivo LIKE \'%' . $m[1] . '%\'')->fetchColumn());

        // Inactivo: sin tildar «Reactivarlo» queda inactivo; tildándolo, se activa.
        $this->post('/instancias/usuarios/resetear', ['host' => self::HOST, 'usuario_id' => (string) $cajera]);
        $this->assertSame(0, (int) $pdo->query("SELECT activo FROM usuarios WHERE id = {$cajera}")->fetchColumn());
        $this->post('/instancias/usuarios/resetear', ['host' => self::HOST, 'usuario_id' => (string) $cajera, 'reactivar' => [(string) $cajera => '1']]);
        $this->assertSame([1, 1], array_map('intval', array_values($pdo->query("SELECT activo, debe_cambiar_clave FROM usuarios WHERE id = {$cajera}")->fetch())));
        $hist = $this->get('/instancias/usuarios', ['host' => self::HOST])->body;
        $this->assertStringContainsString('reactivado', $hist);
        $this->assertSame(3, (int) $db->query("SELECT COUNT(*) FROM instancias_resets WHERE instancia = '" . self::HOST . "'")->fetchColumn());
        $this->assertStringContainsString('Ese usuario no existe', $this->seguir($this->post('/instancias/usuarios/resetear', ['host' => self::HOST, 'usuario_id' => '99999'])));
        $this->assertStringContainsString('Usuarios y contraseñas de esta instalación', $this->get('/soporte/bandeja/' . $ticket)->body);
    }

    private function seguir(\App\Http\Response $r): string
    {
        $q = [];
        parse_str((string) parse_url((string) ($r->headers['Location'] ?? ''), PHP_URL_QUERY), $q);
        return $this->get('/instancias/usuarios', $q)->body;
    }

    /** @param array<string,string> $cambios @return array<string,string> */
    private function datos(array $cambios = []): array
    {
        return $cambios + ['nombre' => 'Cliente de Prueba SA', 'cuit' => '30-71234567-1', 'iva' => '1', 'licencia' => '1', 'subdominio' => 'tcliente',
            'dominio' => 'prueba.test', 'db_host' => (string) Config::get('db.host'), 'db_port' => (string) Config::get('db.port'), 'db_name' => self::BASE,
            'db_user' => (string) Config::get('db.user'), 'db_pass' => (string) Config::get('db.pass'), 'master_usuario' => 'tmaster',
            'master_clave' => 'ClaveMaster123', 'master_confirmacion' => 'ClaveMaster123', 'admin_usuario' => 'admin'];
    }

    /** La base del cliente, vacía. Null si no existe en este entorno. */
    private function baseCliente(): ?\PDO
    {
        try {
            $pdo = InstanciasService::conectar(['host' => Config::get('db.host'), 'port' => Config::get('db.port'), 'name' => self::BASE,
                'user' => Config::get('db.user'), 'pass' => Config::get('db.pass')]);
        } catch (\PDOException) {
            return null;
        }
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
        foreach ($pdo->query('SHOW TABLES')->fetchAll(\PDO::FETCH_COLUMN) as $t) {
            $pdo->exec('DROP TABLE `' . str_replace('`', '', (string) $t) . '`');
        }
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
        return $pdo;
    }

    private function borrar(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $f) {
            $f->isDir() && !$f->isLink() ? rmdir($f->getPathname()) : unlink($f->getPathname());
        }
        rmdir($dir);
    }

    public function testCadaClienteNuevoQuedaComoClienteDelMasterYSeDaDeBaja(): void
    {
        $pdo = $this->baseCliente();
        if ($pdo === null) {
            echo "      (se saltea: falta la base " . self::BASE . ")\n";
            return;
        }
        $this->loginComo($this->crearUsuario('t_mas', 'master'));
        $res = $this->post('/instancias/nueva', $this->datos(['email' => 'pagos@cliente.test', 'domicilio' => 'Calle 1', 'modulos_enviados' => '1',
            'modulos' => ['stock' => '1', 'caja' => '1']]));
        $this->assertStringContainsString('Quedó como cliente', $res->body);
        $cli = \App\Core\Db::pdo()->query('SELECT codigo, razon_social, iva, emailfc, domicilio FROM clientes WHERE cuit = 30712345671')->fetch();
        $this->assertSame(['Cliente de Prueba SA', 1, 'pagos@cliente.test', 'Calle 1'], [$cli['razon_social'], (int) $cli['iva'], $cli['emailfc'], $cli['domicilio']]);
        $this->assertStringContainsString('/comprobantes/nuevo?tipo=FC&amp;cliente=' . $cli['codigo'], $this->get('/instancias')->body);
        $c = InstanciasService::config(self::HOST);
        $this->assertSame([true, false, false], [$c['modulos']['stock'], $c['modulos']['kiosco'], $c['modulos']['agente']]);
        $this->assertSame('activa', $c['suscripcion']['estado']);

        // Suscripción y módulos desde «Administrar».
        $this->post('/instancias/editar', ['host' => self::HOST, 'afip_modo' => 'interno', 'suscripcion' => 'aviso', 'suscripcion_mensaje' => 'Debés noviembre',
            'sin_emision_desde' => '2030-01-10', 'bloqueo_desde' => '', 'modulos_enviados' => '1', 'modulos' => ['kiosco' => '1']]);
        $c = InstanciasService::config(self::HOST);
        $this->assertSame(['aviso', 'Debés noviembre', '2030-01-10'], [$c['suscripcion']['estado'], $c['suscripcion']['mensaje'], $c['suscripcion']['sin_emision_desde']]);
        $this->assertSame([false, true], [$c['modulos']['stock'], $c['modulos']['kiosco']]);
        $this->assertStringContainsString('Aviso de pago pendiente', $this->get('/instancias')->body);

        // Copia de la base del cliente.
        $copia = $this->get('/instancias/copia', ['host' => self::HOST]);
        $this->assertSame(200, $copia->status);
        $sql = (string) gzdecode($copia->body);
        $this->assertStringContainsString('CREATE TABLE `parametros`', $sql);
        $this->assertStringContainsString('Cliente de Prueba SA', $sql);

        // Baja: solo escribiendo la dirección; borra tablas y carpeta y deja el registro.
        $this->post('/instancias/baja', ['host' => self::HOST, 'confirmacion' => 'otra cosa']);
        $this->assertTrue(is_dir(InstanciasService::raiz() . '/' . self::HOST), 'sin la confirmación no se borra nada');
        $this->assertRedirect('/instancias', $this->post('/instancias/baja', ['host' => self::HOST, 'confirmacion' => self::HOST]));
        $this->assertFalse(is_dir(InstanciasService::raiz() . '/' . self::HOST));
        $this->assertSame([], $pdo->query('SHOW TABLES')->fetchAll());
        $bajas = InstanciasService::bajas();
        $this->assertSame([self::HOST, 'Cliente de Prueba SA'], [end($bajas)['host'], end($bajas)['nombre']]);
        $this->assertStringContainsString('dados de baja', $this->get('/instancias')->body);
    }

    public function testEstadosDeLaSuscripcionAvanzanConLasFechas(): void
    {
        $s = ['estado' => 'aviso', 'sin_emision_desde' => '2026-12-10', 'bloqueo_desde' => '2026-12-20'];
        $this->assertSame('aviso', \App\Modules\Instancias\Suscripcion::estado($s, '2026-12-09'));
        $this->assertSame('sin_emision', \App\Modules\Instancias\Suscripcion::estado($s, '2026-12-10'));
        $this->assertSame('bloqueada', \App\Modules\Instancias\Suscripcion::estado($s, '2026-12-25'));
        $this->assertSame('bloqueada', \App\Modules\Instancias\Suscripcion::estado(['estado' => 'bloqueada', 'sin_emision_desde' => '2030-01-01'], '2026-12-01'), 'nunca retrocede sola');
        $this->assertSame('activa', \App\Modules\Instancias\Suscripcion::estado([], '2026-12-01'));
    }

    public function testEnUnClienteSuspendidoNoSeEmiteYBloqueadoSoloEntraElMaster(): void
    {
        $adm = $this->crearUsuario('t_adm', 'admin');
        $mas = $this->crearUsuario('t_mas', 'master');
        Config::set('instancia', ['host' => self::HOST, 'dir' => sys_get_temp_dir()]);

        Config::set('suscripcion', ['estado' => 'aviso', 'mensaje' => 'Debés noviembre']);
        \App\Modules\Licencia\Licencia::exigirParaEmitir();
        $this->loginComo($adm);
        $this->assertStringContainsString('Debés noviembre', $this->get('/clientes')->body, 'el cartel aparece en todas las pantallas');

        Config::set('suscripcion', ['estado' => 'sin_emision']);
        try {
            \App\Modules\Licencia\Licencia::exigirParaEmitir();
            $this->assertTrue(false, 'sin emitir: no tendría que dejar emitir');
        } catch (\App\Modules\Licencia\LicenciaVencida $e) {
            $this->assertStringContainsString('no se pueden emitir', $e->getMessage());
        }
        $this->assertSame(200, $this->get('/clientes')->status, 'sin emitir: se sigue consultando');

        Config::set('suscripcion', ['estado' => 'bloqueada']);
        $res = $this->get('/clientes');
        $this->assertSame(403, $res->status);
        $this->assertStringContainsString('Servicio suspendido', $res->body);
        $this->assertSame(404, $this->get('/portal')->status);
        $this->assertSame(404, $this->get('/api/v1/ping')->status);
        $this->loginComo($mas);
        $this->assertSame(200, $this->get('/clientes')->status, 'el Master de O&S entra igual');
    }

    public function testModulosApagadosPorClienteYElMasterConTodo(): void
    {
        $this->loginComo($this->crearUsuario('t_mas', 'master'));
        $this->assertSame(200, $this->get('/stock')->status);
        Config::set('instancia', ['host' => self::HOST, 'dir' => sys_get_temp_dir()]);
        Config::set('modulos', ['stock' => false, 'caja' => true]);
        $this->assertSame(404, $this->get('/stock')->status);
        $this->assertSame(404, $this->get('/stock/ajuste')->status);
        $this->assertFalse(\App\Core\Menu::visible('stock'));
        $this->assertSame(200, $this->get('/caja')->status);
        $this->assertSame(404, $this->get('/agente')->status, 'el agente viene apagado si no se eligió');
        Config::set('modulos', ['stock' => true, 'caja' => false]);
        $this->assertSame(404, $this->get('/listados/caja-diaria')->status, 'la caja diaria es del módulo caja aunque esté en /listados');
        $this->assertSame(200, $this->get('/listados/ventas-diarias')->status);
    }

    public function testPasarAClientesMueveLaRamaEstableAlCommitDelMaster(): void
    {
        $this->tmp = sys_get_temp_dir() . '/fw_git_' . bin2hex(random_bytes(4));
        $sha = str_repeat('ab', 20);
        mkdir($this->tmp . '/.git/refs/heads', 0775, true);
        file_put_contents($this->tmp . '/.git/HEAD', "ref: refs/heads/main\n");
        file_put_contents($this->tmp . '/.git/packed-refs', "# pack-refs\n{$sha} refs/heads/main\n");
        $P = \App\Modules\Instancias\Promocion::class;
        $this->assertSame($sha, $P::commit($this->tmp), 'lee el commit de packed-refs');
        $this->assertSame('main', $P::ramaDe($this->tmp));
        file_put_contents($this->tmp . '/.git/refs/heads/main', str_repeat('cd', 20) . "\n");
        $this->assertSame(str_repeat('cd', 20), $P::commit($this->tmp));

        $this->assertFalse($P::pasarAClientes($sha)['ok'], 'sin token no hace nada');
        Config::set('panel', ['github_token' => 'tok']);
        $pedidos = [];
        $P::$transporte = static function (string $m, string $url, array $cab, ?string $cuerpo) use (&$pedidos): array {
            $pedidos[] = [$m, $url, json_decode((string) $cuerpo, true)];
            return ['status' => 200, 'body' => '{}'];
        };
        $this->assertTrue($P::pasarAClientes($sha)['ok']);
        $this->assertSame(['PATCH', 'https://api.github.com/repos/hernancespedes-droid/facturaweb/git/refs/heads/estable', ['sha' => $sha, 'force' => false]], $pedidos[0]);
        $P::$transporte = static fn (): array => ['status' => 422, 'body' => '{"message":"Update is not a fast forward"}'];
        $r = $P::pasarAClientes($sha);
        $this->assertFalse($r['ok']);
        $this->assertStringContainsString('no se pisa nada', $r['mensaje']);
    }

    public function testDosCarpetasLasInstalacionesVanALaDeClientes(): void
    {
        $pdo = $this->baseCliente();
        if ($pdo === null) {
            echo "      (se saltea: falta la base " . self::BASE . ")\n";
            return;
        }
        $this->tmp = sys_get_temp_dir() . '/fw_clientes_' . bin2hex(random_bytes(4));
        mkdir($this->tmp . '/storage', 0775, true);
        symlink(\App\App::root() . '/database', $this->tmp . '/database');
        file_put_contents($this->tmp . '/VERSION', "0.1.0\n");
        Config::set('panel', ['carpeta_clientes' => $this->tmp]);
        $this->loginComo($this->crearUsuario('t_mas', 'master'));
        $this->assertStringContainsString('Clientes: <b>0.1.0</b>', $this->get('/instancias')->body);
        $this->assertStringContainsString('Listo: ' . self::HOST, $this->post('/instancias/nueva', $this->datos())->body);
        $this->assertTrue(is_file($this->tmp . '/storage/instancias/' . self::HOST . '/config.php'));
        $this->assertSame([], require $this->tmp . '/storage/instancias/_principal.php', 'en la carpeta de clientes ninguna dirección usa su local.php');
        $this->assertFalse(is_dir(\App\App::root() . '/storage/instancias/' . self::HOST));
    }
}
