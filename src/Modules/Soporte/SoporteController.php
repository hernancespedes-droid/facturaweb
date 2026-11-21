<?php
declare(strict_types=1);

namespace App\Modules\Soporte;

use App\App;
use App\Core\Session;
use App\Core\View;
use App\Http\Request;
use App\Http\Response;
use App\Modules\Auth\Auth;
use App\Modules\Configuracion\Configuracion;
use App\Modules\Instancias\InstanciasService;

/**
 * Ayuda → Soporte (cada instalación: abrir y seguir tickets, permiso soporte.usar) y Sistema → Tickets de soporte
 * (bandeja de O&S: solo el Master y solo en la instalación principal).
 */
final class SoporteController
{
    // ---------------------------------------------------------------- cliente

    public static function index(Request $req, array $params): Response
    {
        $estado = in_array($req->query['estado'] ?? '', ['abiertos', 'cerrados', 'todos'], true) ? (string) $req->query['estado'] : 'abiertos';
        $orden = is_string($req->query['orden'] ?? null) ? (string) $req->query['orden'] : '';
        $error = null;
        $tickets = [];
        try {
            $tickets = SoporteCliente::listar($estado, $orden);
        } catch (SoporteNoDisponible $e) {
            $error = $e->getMessage();
        }
        return Response::html(View::render('soporte/index', ['title' => 'Soporte', 'nav' => 'soporte', 'tickets' => $tickets, 'error' => $error,
            'f' => ['estado' => $estado, 'orden' => $orden]]));
    }

    public static function nuevo(Request $req, array $params): Response
    {
        $pantalla = (string) ($req->query['pantalla'] ?? '');
        if ($pantalla === '') {
            $ref = (string) ($req->server['HTTP_REFERER'] ?? '');
            $pantalla = (string) (parse_url($ref, PHP_URL_PATH) ?? '');
            $q = (string) (parse_url($ref, PHP_URL_QUERY) ?? '');
            $pantalla .= $q !== '' ? '?' . $q : '';
        }
        $u = Auth::usuario() ?? [];
        return self::form(['tipo' => 'error', 'prioridad' => 'normal', 'pantalla' => mb_substr($pantalla, 0, 255), 'operacion' => bin2hex(random_bytes(12)), 'usuario_mail' => (string) ($u['email'] ?? '')], []);
    }

    public static function crear(Request $req, array $params): Response
    {
        $u = Auth::usuario() ?? [];
        $d = array_map(static fn ($v) => is_string($v) ? $v : '', array_intersect_key($req->post, array_flip(['asunto', 'tipo', 'prioridad', 'descripcion', 'usuario_mail', 'pantalla', 'operacion'])));
        $adj = self::archivo($req, 'adjunto');
        if (is_string($adj)) {
            return self::form($d, ['adjunto' => $adj], 422);
        }
        $d += ['usuario' => (string) ($u['nombre'] ?? $u['usuario'] ?? ''), 'version' => self::version(), 'adjunto' => $adj];
        try {
            $r = SoporteCliente::crear($d);
        } catch (SoporteNoDisponible $e) {
            return self::form($d, ['general' => $e->getMessage()], 503);
        }
        if (!$r['ok']) {
            return self::form($d, $r['errores'] ?? ['general' => 'No se pudo crear el ticket.'], 422);
        }
        Session::flash('ok', 'Ticket #' . $r['id'] . ' enviado a O&S. Te avisamos por acá' . ((string) ($d['usuario_mail'] ?? '') !== '' ? ' y por mail' : '') . ' cuando respondan.');
        return Response::redirect(url('/soporte/' . $r['id']));
    }

    /** @param array<string,mixed> $d @param array<string,string> $errores */
    private static function form(array $d, array $errores, int $status = 200): Response
    {
        unset($d['adjunto']);
        return Response::html(View::render('soporte/nuevo', ['title' => 'Nuevo ticket de soporte', 'nav' => 'soporte', 'd' => $d, 'errores' => $errores,
            'configurado' => SoporteCliente::configurado()]), $status);
    }

    public static function ver(Request $req, array $params): Response
    {
        if (!ctype_digit((string) $params['id'])) {
            return new Response(404);
        }
        try {
            $t = SoporteCliente::ver((int) $params['id']);
        } catch (SoporteNoDisponible $e) {
            Session::flash('error', $e->getMessage());
            return Response::redirect(url('/soporte'));
        }
        if ($t === null) {
            return new Response(404);
        }
        return Response::html(View::render('soporte/ticket', ['title' => 'Ticket #' . $t['id'], 'nav' => 'soporte', 't' => $t]));
    }

    public static function responder(Request $req, array $params): Response
    {
        $id = (int) $params['id'];
        $adj = self::archivo($req, 'adjunto');
        if (is_string($adj)) {
            Session::flash('error', $adj);
            return Response::redirect(url('/soporte/' . $id));
        }
        $u = Auth::usuario() ?? [];
        try {
            $r = SoporteCliente::responder($id, ['texto' => (string) ($req->post['texto'] ?? ''), 'usuario' => (string) ($u['nombre'] ?? ''), 'adjunto' => $adj]);
        } catch (SoporteNoDisponible $e) {
            $r = ['ok' => false, 'error' => $e->getMessage()];
        }
        Session::flash($r['ok'] ? 'ok' : 'error', $r['ok'] ? 'Respuesta enviada a O&S.' : (string) ($r['error'] ?? 'No se pudo enviar.'));
        return Response::redirect(url('/soporte/' . $id));
    }

    public static function estado(Request $req, array $params): Response
    {
        $id = (int) $params['id'];
        $accion = (string) ($req->post['accion'] ?? '');
        $u = Auth::usuario() ?? [];
        try {
            $r = SoporteCliente::cambiarEstado($id, $accion, (string) ($u['nombre'] ?? ''));
        } catch (SoporteNoDisponible $e) {
            $r = ['ok' => false, 'error' => $e->getMessage()];
        }
        Session::flash($r['ok'] ? 'ok' : 'error', $r['ok'] ? ($accion === 'cerrar' ? 'Ticket cerrado.' : 'Ticket reabierto.') : (string) ($r['error'] ?? 'No se pudo.'));
        return Response::redirect(url('/soporte/' . $id));
    }

    public static function adjunto(Request $req, array $params): Response
    {
        try {
            $a = SoporteCliente::adjunto((int) $params['id']);
        } catch (SoporteNoDisponible) {
            $a = null;
        }
        return $a === null ? new Response(404) : self::bajar($a);
    }

    // ---------------------------------------------------------------- O&S (master)

    private static function esOys(): bool
    {
        return Auth::esMaster() && InstanciasService::disponible();
    }

    public static function bandeja(Request $req, array $params): Response
    {
        if (!self::esOys()) {
            return new Response(403);
        }
        InstanciasService::asegurarSoporte(self::urlMaster());
        $f = array_map(static fn ($x) => is_string($x) ? $x : '', array_intersect_key($req->query, array_flip(['instancia', 'estado', 'prioridad', 'tipo', 'q', 'orden'])));
        $f['estado'] ??= 'abiertos';
        $pagina = max(1, (int) ($req->query['pagina'] ?? 1));
        $por = Configuracion::porPagina();
        $s = new SoporteService();
        $r = $s->bandeja($f, $pagina, $por);
        return Response::html(View::render('soporte/bandeja', ['title' => 'Tickets de soporte', 'nav' => 'soporte_bandeja', 'f' => $f, 'filas' => $r['filas'],
            'total' => $r['total'], 'pagina' => $pagina, 'porPagina' => $por, 'instancias' => $s->instancias(), 'pendientes' => $s->pendientes(),
            'mails' => (string) (Configuracion::valor('soporte.mail_aviso') ?? ''), 'mailConfigurado' => \App\Core\Mailer::configurado()]));
    }

    public static function bandejaVer(Request $req, array $params): Response
    {
        if (!self::esOys()) {
            return new Response(403);
        }
        $t = ctype_digit((string) $params['id']) ? (new SoporteService())->ver((int) $params['id'], null) : null;
        if ($t === null) {
            return new Response(404);
        }
        return Response::html(View::render('soporte/ticket_master', ['title' => 'Ticket #' . $t['id'], 'nav' => 'soporte_bandeja', 't' => $t]));
    }

    public static function bandejaResponder(Request $req, array $params): Response
    {
        if (!self::esOys()) {
            return new Response(403);
        }
        $id = (int) $params['id'];
        $adj = self::archivo($req, 'adjunto');
        if (is_string($adj)) {
            Session::flash('error', $adj);
            return Response::redirect(url('/soporte/bandeja/' . $id));
        }
        $u = Auth::usuario() ?? [];
        $r = (new SoporteService())->responderOys($id, 'O&S · ' . (string) ($u['nombre'] ?? ''), ['texto' => (string) ($req->post['texto'] ?? ''),
            'interno' => ($req->post['interno'] ?? '') === '1', 'estado' => (string) ($req->post['estado'] ?? ''), 'adjunto' => $adj]);
        if (!$r['ok']) {
            Session::flash('error', (string) $r['error']);
        } else {
            Session::flash('ok', ($req->post['interno'] ?? '') === '1' ? 'Nota interna guardada.' : 'Guardado.'
                . (($r['aviso'] ?? null) !== null ? ' No se pudo mandar el mail al cliente: ' . $r['aviso'] : ''));
        }
        return Response::redirect(url('/soporte/bandeja/' . $id));
    }

    public static function bandejaAdjunto(Request $req, array $params): Response
    {
        if (!self::esOys()) {
            return new Response(403);
        }
        $a = (new SoporteService())->adjunto((int) $params['id'], null);
        return $a === null ? new Response(404) : self::bajar(['nombre' => (string) $a['nombre'], 'tipo' => (string) $a['tipo'], 'datos' => (string) $a['datos']]);
    }

    public static function configuracion(Request $req, array $params): Response
    {
        if (!self::esOys()) {
            return new Response(403);
        }
        $r = (new SoporteService())->guardarMailsAviso((string) ($req->post['mail_aviso'] ?? ''), Auth::id());
        Session::flash($r['ok'] ? 'ok' : 'error', $r['ok'] ? 'Mails de aviso guardados.' : (string) $r['error']);
        return Response::redirect(url('/soporte/bandeja'));
    }

    // ---------------------------------------------------------------- comunes

    /** Dirección del master que se escribe en el config de cada cliente (app.url o la del pedido). */
    public static function urlMaster(): string
    {
        return rtrim(url_absoluta(url('/')), '/');
    }

    private static function version(): string
    {
        return trim((string) @file_get_contents(App::root() . '/VERSION'));
    }

    /** @return array{nombre:string,datos:string}|string|null el adjunto, el error para mostrar o null si no se subió nada */
    private static function archivo(Request $req, string $campo): array|string|null
    {
        $f = $req->files[$campo] ?? null;
        if (!is_array($f) || (int) ($f['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            return null;
        }
        if ((int) $f['error'] === UPLOAD_ERR_INI_SIZE || (int) $f['error'] === UPLOAD_ERR_FORM_SIZE) {
            return 'El archivo pesa más de lo que acepta el servidor (' . ini_get('upload_max_filesize') . ').';
        }
        if ((int) $f['error'] !== UPLOAD_ERR_OK || !is_file((string) $f['tmp_name'])) {
            return 'No se pudo subir el archivo.';
        }
        if ((int) filesize((string) $f['tmp_name']) > SoporteService::MAX_ADJUNTO) {
            return 'El archivo es demasiado grande (hasta 3 MB).';
        }
        $datos = (string) file_get_contents((string) $f['tmp_name']);
        if (SoporteService::tipoDe($datos) === null) {
            return 'Solo se pueden adjuntar imágenes (PNG, JPG, WEBP, GIF) o PDF.';
        }
        return ['nombre' => (string) ($f['name'] ?? 'adjunto'), 'datos' => $datos];
    }

    /** @param array{nombre:string,tipo:string,datos:string} $a */
    private static function bajar(array $a): Response
    {
        $tipo = SoporteService::tipoDe($a['datos']) ?? 'application/octet-stream'; // nunca el tipo que diga otro: se mira el contenido
        $nombre = preg_replace('/[^\w.\- ]+/', '_', $a['nombre']);
        return new Response(200, $a['datos'], ['Content-Type' => $tipo, 'Content-Disposition' => 'inline; filename="' . $nombre . '"',
            'X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'private, no-store']);
    }
}
