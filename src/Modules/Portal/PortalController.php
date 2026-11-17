<?php
declare(strict_types=1);

namespace App\Modules\Portal;

use App\Core\Mailer;
use App\Core\Session;
use App\Core\View;
use App\Http\Request;
use App\Http\Response;
use App\Modules\Comprobantes\ComprobanteImpreso;
use App\Modules\Comprobantes\ComprobantePdf;
use App\Modules\Comprobantes\ComprobantesService;
use App\Modules\CuentaCorriente\CuentaCorrienteService;

/** Portal de clientes (/portal). Independiente del ingreso de los usuarios del sistema: usa su propia marca en la sesión. */
final class PortalController
{
    private const SESION = '_portal_email';

    private static function pagina(string $vista, array $datos, int $status = 200): Response
    {
        $emisor = (new ComprobantesService())->emisor();
        $res = Response::html(View::render('portal/' . $vista, $datos + ['empresa' => (string) ($emisor['empresa'] ?? ''), 'title' => 'Mis comprobantes'], 'portal/layout'), $status);
        $res->headers['X-Robots-Tag'] = 'noindex';
        return $res;
    }

    /** Mail con el que entró el cliente, si su acceso sigue valiendo (algún cliente activo lo tiene en la ficha). */
    private static function mail(): ?string
    {
        $m = Session::get(self::SESION);
        return is_string($m) && $m !== '' ? $m : null;
    }

    public static function inicio(Request $req, array $params): Response
    {
        if (!PortalService::activo()) {
            return new Response(404);
        }
        if (self::mail() !== null) {
            return Response::redirect(url('/portal/comprobantes'));
        }
        return self::pagina('ingresar', ['enviado' => false, 'mailListo' => Mailer::configurado(), 'error' => null]);
    }

    public static function pedir(Request $req, array $params): Response
    {
        if (!PortalService::activo()) {
            return new Response(404);
        }
        $mail = PortalService::normalizar((string) ($req->post['email'] ?? ''));
        if (filter_var($mail, FILTER_VALIDATE_EMAIL) === false) {
            return self::pagina('ingresar', ['enviado' => false, 'mailListo' => Mailer::configurado(), 'error' => 'Escribí un mail válido.'], 422);
        }
        try {
            $empresa = (string) ((new ComprobantesService())->emisor()['empresa'] ?? '');
            (new PortalService())->pedirLink($mail, (string) ($req->server['REMOTE_ADDR'] ?? ''), static fn (string $t) => url_absoluta(url('/portal/acceso/' . $t)), $empresa);
        } catch (\RuntimeException $e) {
            \App\App::registrar($e); // se registra, pero hacia afuera se contesta igual (no se revela si el mail existe)
        }
        return self::pagina('ingresar', ['enviado' => true, 'mailListo' => true, 'error' => null, 'mail' => $mail]);
    }

    public static function acceso(Request $req, array $params): Response
    {
        if (!PortalService::activo()) {
            return new Response(404);
        }
        $mail = (new PortalService())->usarLink((string) $params['token']);
        if ($mail === null) {
            return self::pagina('ingresar', ['enviado' => false, 'mailListo' => true,
                'error' => 'El link ya se usó o venció. Pedí uno nuevo: llega al instante.'], 410);
        }
        Session::regenerate();
        Session::set(self::SESION, $mail);
        return Response::redirect(url('/portal/comprobantes'));
    }

    public static function comprobantes(Request $req, array $params): Response
    {
        if (!PortalService::activo()) {
            return new Response(404);
        }
        $mail = self::mail();
        $svc = new PortalService();
        $clientes = $mail !== null ? $svc->clientesDe($mail) : [];
        if ($clientes === []) {
            Session::forget(self::SESION);
            return Response::redirect(url('/portal'));
        }
        $codigos = array_map(static fn ($c) => (int) $c['codigo'], $clientes);
        $cliente = in_array((int) ($req->query['cliente'] ?? 0), $codigos, true) ? (int) $req->query['cliente'] : $codigos[0];
        $anios = $svc->anios($cliente);
        $anio = ctype_digit((string) ($req->query['anio'] ?? '')) ? (int) $req->query['anio'] : ($anios[0] ?? null);
        $actual = array_values(array_filter($clientes, static fn ($c) => (int) $c['codigo'] === $cliente))[0];
        return self::pagina('comprobantes', ['mail' => $mail, 'clientes' => $clientes, 'cliente' => $actual, 'anios' => $anios, 'anio' => $anio,
            'filas' => $svc->comprobantes($cliente, $anio), 'saldo' => (new CuentaCorrienteService())->saldo($cliente)]);
    }

    public static function pdf(Request $req, array $params): Response
    {
        if (!PortalService::activo()) {
            return new Response(404);
        }
        $mail = self::mail();
        $svc = new PortalService();
        $id = (int) $params['id'];
        $permitido = false;
        foreach ($mail !== null ? $svc->clientesDe($mail) : [] as $c) {
            if ($svc->puedeVer($id, (int) $c['codigo'])) {
                $permitido = true;
                break;
            }
        }
        if (!$permitido) {
            return new Response(404); // ni existe ni es suyo: misma respuesta
        }
        $cs = new ComprobantesService();
        $d = ComprobanteImpreso::datos($cs->obtener($id), $cs->emisor());
        return new Response(200, ComprobantePdf::generar($d), [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => (isset($req->query['ver']) ? 'inline' : 'attachment') . '; filename="' . $d['nombre_archivo'] . '"',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    public static function salir(Request $req, array $params): Response
    {
        Session::forget(self::SESION);
        Session::regenerate();
        return Response::redirect(url('/portal'));
    }
}
