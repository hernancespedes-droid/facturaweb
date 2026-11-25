<?php
declare(strict_types=1);

namespace App\Modules\Caja;

use App\Core\Session;
use App\Core\View;
use App\Http\Request;
use App\Http\Response;
use App\Modules\Auth\Auth;
use App\Modules\Configuracion\Configuracion;

/** Pantallas de caja: mi turno y movimientos (caja.turno), ingresos/egresos manuales (caja.mover), turnos y auditoría (caja.auditar). Rutas en routes.php. */
final class CajaController
{
    private static function uid(): int
    {
        return (int) Auth::id();
    }

    private static function filtros(Request $req, array $claves): array
    {
        return array_map(static fn ($x) => is_string($x) ? $x : '', array_intersect_key($req->query, array_flip($claves)));
    }

    /** Mi caja: el turno (abrir / arqueo y cerrar), ingresos y egresos manuales y los movimientos de mi turno. */
    public static function index(Request $req, array $params, ?string $error = null, int $status = 200): Response
    {
        $svc = new CajaService();
        $turno = $svc->turnoAbierto(self::uid());
        $f = self::filtros($req, ['orden']);
        $mov = $turno !== null ? $svc->movimientos(['desde' => '1900-01-01', 'hasta' => '2100-12-31', 'turno' => (string) $turno['id'], 'orden' => $f['orden'] ?? ''], 1, 200) : ['filas' => [], 'total' => 0];
        return Response::html(View::render('caja/index', [
            'title' => 'Caja', 'nav' => 'caja', 'turno' => $turno, 'resumen' => $turno !== null ? $svc->resumenTurno((int) $turno['id']) : null,
            'fondoSugerido' => $svc->fondoSugerido(), 'filas' => $mov['filas'], 'puedeMover' => Auth::puede('caja.mover'), 'tope' => CajaService::tope(), 'error' => $error,
            'f' => $f, 'sinTurno' => $svc->sinTurno(self::uid(), $turno !== null ? substr((string) $turno['abierto_en'], 0, 10) : date('Y-m-d')),
        ]), $status);
    }

    public static function abrir(Request $req, array $params): Response
    {
        $r = (new CajaService())->abrir(self::uid(), (string) ($req->post['fondo'] ?? ''));
        if (!$r['ok']) {
            return self::index($req, $params, $r['error'], 422);
        }
        Session::flash('ok', 'Turno abierto.');
        return Response::redirect(url('/caja'));
    }

    public static function sumar(Request $req, array $params): Response
    {
        $r = (new CajaService())->sumarSinTurno(self::uid());
        if (!$r['ok']) {
            return self::index($req, $params, $r['error'], 422);
        }
        Session::flash('ok', $r['cantidad'] > 0 ? 'Se sumaron ' . $r['cantidad'] . ' cobro(s) a tu turno.' : 'No había cobros sin turno.');
        return Response::redirect(url('/caja'));
    }

    public static function cerrar(Request $req, array $params): Response
    {
        $id = (int) ($req->post['turno'] ?? 0);
        $r = (new CajaService())->cerrar($id, self::uid(), Auth::puede('caja.auditar'), (string) ($req->post['contado'] ?? ''), (string) ($req->post['observaciones'] ?? ''));
        if (!$r['ok']) {
            return self::index($req, $params, $r['error'], 422);
        }
        $dif = (float) $r['diferencia'];
        Session::flash('ok', 'Turno cerrado. ' . (abs($dif) < 0.005 ? 'El arqueo cierra justo.' : 'Diferencia de ' . plata_dec($dif) . ' (' . ($dif > 0 ? 'sobra' : 'falta') . ').'));
        return Response::redirect(url('/caja/turnos'));
    }

    public static function mover(Request $req, array $params): Response
    {
        $p = $req->post;
        $r = (new CajaService())->mover(self::uid(), (string) ($p['tipo'] ?? ''), (string) ($p['importe'] ?? ''), (string) ($p['motivo'] ?? ''));
        if (!$r['ok']) {
            return self::index($req, $params, $r['error'], 422);
        }
        Session::flash('ok', 'Movimiento registrado.');
        return Response::redirect(url('/caja'));
    }

    public static function anular(Request $req, array $params): Response
    {
        $r = (new CajaService())->anular(self::uid(), (int) ($req->post['movimiento'] ?? 0), (string) ($req->post['motivo'] ?? ''));
        if (!$r['ok']) {
            return self::index($req, $params, $r['error'], 422);
        }
        Session::flash('ok', 'Movimiento anulado con un contra-asiento.');
        return Response::redirect(url('/caja'));
    }

    /** Historial de turnos y arqueos: cada cajero ve los suyos; quien audita, los de todos. */
    public static function turnos(Request $req, array $params): Response
    {
        $f = self::filtros($req, ['desde', 'hasta', 'usuario', 'orden']);
        $svc = new CajaService();
        $audita = Auth::puede('caja.auditar');
        return Response::html(View::render('caja/turnos', [
            'title' => 'Turnos y arqueos', 'nav' => 'caja_turnos', 'f' => $f, 'filas' => $svc->turnos($f, $audita ? null : self::uid()), 'audita' => $audita, 'usuarios' => $svc->usuarios(),
        ]));
    }

    /** Auditoría: todos los movimientos de la caja con hora y usuario, el registro de acciones y la verificación de la cadena. */
    public static function auditoria(Request $req, array $params): Response
    {
        $f = self::filtros($req, ['desde', 'hasta', 'usuario', 'origen', 'turno', 'orden']);
        if (!array_key_exists('desde', $req->query)) {
            $f['desde'] = $f['hasta'] = date('d/m/Y');
        }
        $pagina = max(1, (int) ($req->query['pagina'] ?? 1));
        $por = Configuracion::porPagina();
        $svc = new CajaService();
        $m = $svc->movimientos($f, $pagina, $por);
        return Response::html(View::render('caja/auditoria', [
            'title' => 'Auditoría de caja', 'nav' => 'caja_auditoria', 'f' => $f, 'filas' => $m['filas'], 'total' => $m['total'], 'pagina' => $pagina, 'porPagina' => $por,
            'usuarios' => $svc->usuarios(), 'registro' => $svc->registro(), 'cadena' => $svc->verificar(),
        ]));
    }
}
