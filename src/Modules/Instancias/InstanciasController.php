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
        InstanciasService::asegurarSoporte(\App\Modules\Soporte\SoporteController::urlMaster()); // dirección y clave de soporte de cada cliente
        $resultado = Session::get('instancias_resultado');
        Session::forget('instancias_resultado');
        return Response::html(View::render('instancias/index', [
            'title' => 'Instalaciones de clientes',
            'nav' => 'instancias',
            'instancias' => InstanciasService::listar(),
            'principal' => InstanciasService::principal(),
            'hostActual' => self::hostActual($req),
            'resultado' => $resultado,
            'master' => InstanciasService::codigo(\App\App::root()),
            'clientes' => InstanciasService::codigo(InstanciasService::carpetaClientes()),
            'dosCarpetas' => InstanciasService::dosCarpetas(),
            'carpetaClientes' => InstanciasService::carpetaClientes(),
            'hayToken' => Promocion::hayToken(),
            'rama' => Promocion::rama(),
            'enCarpetaMaster' => InstanciasService::enCarpetaMaster(),
            'bajas' => InstanciasService::bajas(),
        ]));
    }

    public static function nueva(Request $req, array $params): Response
    {
        if (!self::puede()) {
            return new Response(403);
        }
        return self::form($req, ['modulos' => array_map(static fn (array $m) => $m['por_omision'] ? '1' : '', \App\Core\Modulos::LISTA), 'iva' => '1', 'licencia' => '1', 'master_usuario' => 'oys', 'master_nombre' => 'O&S Sistemas', 'admin_usuario' => 'admin',
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
            'carpetaPublica' => InstanciasService::carpetaClientes() . '/public']));
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
        $sus = (array) ($c['suscripcion'] ?? []);
        $mods = [];
        foreach (\App\Core\Modulos::LISTA as $k => $d) {
            $mods[$k] = ($c['modulos'][$k] ?? $d['por_omision']) ? '1' : '';
        }
        $datos = ['suscripcion' => (string) ($sus['estado'] ?? 'activa'), 'suscripcion_mensaje' => (string) ($sus['mensaje'] ?? ''),
            'sin_emision_desde' => (string) ($sus['sin_emision_desde'] ?? ''), 'bloqueo_desde' => (string) ($sus['bloqueo_desde'] ?? ''), 'modulos' => $mods,
            'efectivo' => Suscripcion::estado($sus),'afip_modo' => (string) ($c['afip']['modo'] ?? 'interno'), 'afip_config' => (string) ($c['afip']['config'] ?? ''),
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
            'rutaAfipSugerida' => InstanciasService::raiz() . '/' . $host . '/afip/config_afip.php',
            'info' => json_decode((string) @file_get_contents(InstanciasService::raiz() . '/' . $host . '/info.json'), true) ?: []]), $status);
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

    /** Vincula como cliente del master una instalación que no lo tiene (las creadas antes de 0.29.0). */
    public static function crearCliente(Request $req, array $params): Response
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
        $r = InstanciasService::crearCliente($host);
        Session::flash($r['cliente'] !== null ? 'ok' : 'error', $r['cliente'] !== null ? 'Cliente ' . $r['cliente'] . ' vinculado a ' . $host . '. ' . ($r['aviso_cliente'] ?? '') : (string) $r['aviso_cliente']);
        return Response::redirect(url('/instancias/editar?host=' . rawurlencode($host)));
    }

    /** Usuarios de la base del cliente, para resetear la contraseña de quien la perdió (UsuariosCliente). */
    public static function usuarios(Request $req, array $params): Response
    {
        if (!self::puede()) {
            return new Response(403);
        }
        $host = (string) ($req->query['host'] ?? '');
        try {
            InstanciasService::config($host);
        } catch (\RuntimeException) {
            return new Response(404);
        }
        $error = null;
        try {
            $usuarios = UsuariosCliente::listar($host);
        } catch (\PDOException $e) {
            \App\App::registrar($e);
            $usuarios = [];
            $error = 'No se pudo leer la base del cliente (' . $e->getMessage() . ').';
        }
        $hecho = Session::get('instancias_clave');
        Session::forget('instancias_clave');
        return Response::html(View::render('instancias/usuarios', ['title' => 'Usuarios de ' . $host, 'nav' => 'instancias', 'host' => $host,
            'usuarios' => $usuarios, 'error' => $error, 'hecho' => is_array($hecho) && ($hecho['host'] ?? '') === $host ? $hecho : null,
            'historial' => UsuariosCliente::historial($host), 'tickets' => UsuariosCliente::ticketsAbiertos($host),
            'ticket' => (int) ($req->query['ticket'] ?? 0), 'info' => json_decode((string) @file_get_contents(InstanciasService::raiz() . '/' . $host . '/info.json'), true) ?: []]));
    }

    public static function resetear(Request $req, array $params): Response
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
        $ticket = (int) ($req->post['ticket'] ?? 0);
        $yo = Auth::usuario() ?? [];
        try {
            $r = UsuariosCliente::resetear($host, (int) ($req->post['usuario_id'] ?? 0), ['id' => (int) ($yo['id'] ?? 0), 'usuario' => (string) ($yo['usuario'] ?? '')],
                !empty($req->post['reactivar'][(string) (int) ($req->post['usuario_id'] ?? 0)]), (string) ($req->post['motivo'] ?? ''), $ticket > 0 ? $ticket : null, (string) ($req->server['REMOTE_ADDR'] ?? ''));
        } catch (\PDOException $e) {
            \App\App::registrar($e);
            $r = ['ok' => false, 'error' => 'No se pudo cambiar en la base del cliente (' . $e->getMessage() . ').'];
        }
        $volver = url('/instancias/usuarios?host=' . rawurlencode($host) . ($ticket > 0 ? '&ticket=' . $ticket : ''));
        if (!$r['ok']) {
            Session::flash('error', (string) $r['error']);
            return Response::redirect($volver);
        }
        // Se muestra una sola vez en la pantalla siguiente (como «Resetear contraseña» de Usuarios) y se borra de la sesión.
        Session::set('instancias_clave', ['host' => $host, 'usuario' => $r['usuario'], 'nombre' => $r['nombre'], 'clave' => $r['clave'],
            'reactivado' => $r['reactivado'], 'ticket' => $ticket > 0 ? $ticket : null]);
        if ($r['aviso'] !== null) {
            Session::flash('error', (string) $r['aviso']);
        }
        return Response::redirect($volver);
    }

    public static function copia(Request $req, array $params): Response
    {
        if (!self::puede()) {
            return new Response(403);
        }
        try {
            $c = InstanciasService::copia((string) ($req->query['host'] ?? ''));
        } catch (\RuntimeException) {
            return new Response(404);
        }
        return new Response(200, $c['contenido'], ['Content-Type' => 'application/gzip', 'Content-Disposition' => 'attachment; filename="' . $c['nombre'] . '"',
            'Cache-Control' => 'no-store']);
    }

    public static function baja(Request $req, array $params): Response
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
        $error = InstanciasService::baja($host, (string) ($req->post['confirmacion'] ?? ''), (string) (Auth::usuario()['usuario'] ?? ''));
        if ($error !== null) {
            Session::flash('error', $error);
            return Response::redirect(url('/instancias/editar?host=' . rawurlencode($host)));
        }
        Session::flash('ok', 'Baja hecha: se borraron los datos de ' . $host . '. Falta borrar en el panel del hosting su subdominio y su base (ya vacía).');
        return Response::redirect(url('/instancias'));
    }

    /** Mueve la rama de los clientes al commit que corre el master (ver Promocion). */
    public static function pasarAClientes(Request $req, array $params): Response
    {
        if (!self::puede()) {
            return new Response(403);
        }
        $r = Promocion::pasarAClientes((string) Promocion::commit(\App\App::root()));
        Session::flash($r['ok'] ? 'ok' : 'error', $r['mensaje'] . ($r['ok'] ? ' Ahora actualizá la carpeta de clientes con GIT en el hosting y tocá «Aplicar cambios en todas».' : ''));
        return Response::redirect(url('/instancias'));
    }

    public static function mover(Request $req, array $params): Response
    {
        if (!self::puede()) {
            return new Response(403);
        }
        $hechas = InstanciasService::moverACarpetaClientes();
        Session::flash('ok', $hechas === [] ? 'No había nada para mover.' : 'Pasadas a la carpeta de clientes: ' . implode(', ', $hechas)
            . '. Ahora apuntá su subdominio a ' . InstanciasService::carpetaClientes() . '/public.');
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
