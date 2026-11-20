<?php
declare(strict_types=1);

namespace App\Modules\Instancias;

use App\Core\Config;
use App\Core\Session;
use App\Core\View;
use App\Http\Request;
use App\Http\Response;
use App\Modules\Auth\Auth;

/** Sistema → Instalaciones de clientes (solo Master, solo en la instalación principal). Ver InstanciasService. */
final class InstanciasController
{
    private static function puede(): bool
    {
        return Auth::esMaster() && InstanciasService::disponible();
    }

    /** Dirección con la que se entró (ej. oys.orgysis.site). */
    private static function hostActual(Request $req): string
    {
        $h = strtolower((string) ($req->server['HTTP_HOST'] ?? $_SERVER['HTTP_HOST'] ?? 'localhost'));
        return (string) preg_replace('/:\d+$/', '', $h);
    }

    public static function index(Request $req, array $params): Response
    {
        if (!self::puede()) {
            return new Response(403);
        }
        $resultado = Session::get('instancias_resultado');
        Session::forget('instancias_resultado');
        return Response::html(View::render('instancias/index', [
            'title' => 'Instalaciones de clientes',
            'nav' => 'instancias',
            'instancias' => InstanciasService::listar(),
            'principal' => InstanciasService::principal(),
            'hostActual' => self::hostActual($req),
            'resultado' => $resultado,
        ]));
    }

    public static function nueva(Request $req, array $params): Response
    {
        if (!self::puede()) {
            return new Response(403);
        }
        return self::form($req, ['iva' => '1', 'licencia' => '1', 'master_usuario' => 'oys', 'master_nombre' => 'O&S Sistemas', 'admin_usuario' => 'admin',
            'db_host' => (string) Config::get('db.host', 'localhost'), 'mail_port' => '587', 'mail_secure' => 'tls'], []);
    }

    public static function crear(Request $req, array $params): Response
    {
        if (!self::puede()) {
            return new Response(403);
        }
        $usuario = (string) (Auth::usuario()['usuario'] ?? '');
        $r = InstanciasService::crear($req->post, self::hostActual($req), $usuario);
        if (!$r['ok']) {
            $datos = $req->post;
            unset($datos['master_clave'], $datos['master_confirmacion'], $datos['db_pass'], $datos['mail_pass']);
            return self::form($req, $datos, $r['errores'], 422);
        }
        return Response::html(View::render('instancias/creada', ['title' => 'Instalación creada', 'nav' => 'instancias', 'r' => $r,
            'carpetaPublica' => dirname(__DIR__, 3) . '/public']));
    }

    /** @param array<string,mixed> $datos @param array<string,string> $errores */
    private static function form(Request $req, array $datos, array $errores, int $status = 200): Response
    {
        return Response::html(View::render('instancias/form', ['title' => 'Nuevo cliente', 'nav' => 'instancias', 'datos' => $datos, 'errores' => $errores,
            'dominio' => InstanciasService::dominioSugerido(self::hostActual($req))]), $status);
    }

    public static function editarForm(Request $req, array $params): Response
    {
        if (!self::puede()) {
            return new Response(403);
        }
        $host = (string) ($req->query['host'] ?? '');
        try {
            $c = InstanciasService::config($host);
        } catch (\RuntimeException) {
            return new Response(404);
        }
        $m = (array) ($c['mail'] ?? []);
        $datos = ['afip_modo' => (string) ($c['afip']['modo'] ?? 'interno'), 'afip_config' => (string) ($c['afip']['config'] ?? ''),
            'licencia' => !empty($c['licencia']['exigir']) ? '1' : '', 'mail_host' => $m['host'] ?? '', 'mail_port' => (string) ($m['port'] ?? 587),
            'mail_secure' => $m['secure'] ?? 'tls', 'mail_user' => $m['user'] ?? '', 'mail_from' => $m['from'] ?? '', 'mail_from_name' => $m['from_name'] ?? ''];
        return self::editarVista($host, $datos, [], (string) ($m['pass'] ?? '') !== '');
    }

    public static function editar(Request $req, array $params): Response
    {
        if (!self::puede()) {
            return new Response(403);
        }
        $host = (string) ($req->post['host'] ?? '');
        try {
            InstanciasService::config($host);
        } catch (\RuntimeException) {
            return new Response(404);
        }
        $errores = InstanciasService::editar($host, $req->post);
        if ($errores !== []) {
            return self::editarVista($host, $req->post, $errores, false, 422);
        }
        Session::flash('ok', 'Guardado: ' . $host . '.');
        return Response::redirect(url('/instancias'));
    }

    /** @param array<string,mixed> $datos @param array<string,string> $errores */
    private static function editarVista(string $host, array $datos, array $errores, bool $tieneClaveMail, int $status = 200): Response
    {
        return Response::html(View::render('instancias/editar', ['title' => 'Editar ' . $host, 'nav' => 'instancias', 'host' => $host, 'datos' => $datos,
            'errores' => $errores, 'tieneClaveMail' => $tieneClaveMail,
            'rutaAfipSugerida' => InstanciasService::raiz() . '/' . $host . '/afip/config_afip.php']), $status);
    }

    /** Aplica los cambios de base en la principal y en todos los clientes (después de actualizar el código con GIT). */
    public static function actualizar(Request $req, array $params): Response
    {
        if (!self::puede()) {
            return new Response(403);
        }
        Session::set('instancias_resultado', InstanciasService::actualizarTodas());
        Session::flash('ok', 'Listo: se revisaron todas las instalaciones (detalle abajo).');
        return Response::redirect(url('/instancias'));
    }

    public static function principal(Request $req, array $params): Response
    {
        if (!self::puede()) {
            return new Response(403);
        }
        $hosts = preg_split('/[\s,]+/', (string) ($req->post['hosts'] ?? '')) ?: [];
        // La dirección con la que se está trabajando siempre queda (si no, la principal dejaría de responder acá mismo).
        $hosts[] = self::hostActual($req);
        InstanciasService::guardarPrincipal($hosts);
        Session::flash('ok', 'Direcciones de la instalación principal guardadas.');
        return Response::redirect(url('/instancias'));
    }
}
