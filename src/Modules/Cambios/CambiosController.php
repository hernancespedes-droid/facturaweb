<?php
declare(strict_types=1);

namespace App\Modules\Cambios;

use App\Core\Db;
use App\Core\Session;
use App\Core\View;
use App\Http\Request;
use App\Http\Response;
use App\Modules\Articulos\BuscadorArticulos;
use App\Modules\Auth\Auth;
use App\Modules\Comprobantes\ComprobantesService;

/**
 * Cambio de mercadería (modo ticket de cambio). Hay que tener tildado «habilita modo ticket de cambio» en Parámetros. Leer el ticket y hacer cambios:
 * permiso cambios.hacer; el PDF del ticket: comprobantes.ver (verificado en routes.php).
 */
final class CambiosController
{
    private const CAMPOS_PAGO = ['forma_pago', 'fpago', 'pagos', 'pago_nro', 'pago_banco', 'pago_vto', 'recibido', 'excede_ok'];

    private static function apagado(): ?Response
    {
        return CambiosService::habilitado() ? null : new Response(404, View::render('cambios/apagado', ['title' => 'Cambio de mercadería', 'nav' => 'cambios']));
    }

    /** Leer el ticket: se escribe (o lee con la pistola) el código del ticket o el número del comprobante y se elige entre las coincidencias. */
    public static function index(Request $req, array $params): Response
    {
        if (($r = self::apagado()) !== null) {
            return $r;
        }
        $q = trim((string) ($req->query['q'] ?? ''));
        return Response::html(View::render('cambios/index', [
            'title' => 'Cambio de mercadería', 'nav' => 'cambios', 'q' => $q, 'filas' => $q === '' ? [] : (new CambiosService())->buscar($q),
        ]));
    }

    public static function nuevo(Request $req, array $params): Response
    {
        if (($r = self::apagado()) !== null) {
            return $r;
        }
        $o = (new CambiosService())->original((int) ($req->query['cbte'] ?? 0));
        if (is_string($o)) {
            Session::flash('error', $o);
            return Response::redirect(url('/cambios'));
        }
        return self::form($o, [], [], [], 200);
    }

    public static function emitir(Request $req, array $params): Response
    {
        if (($r = self::apagado()) !== null) {
            return $r;
        }
        $svc = new CambiosService();
        $in = $req->post;
        $r = $svc->armar($in, Auth::puede('comprobantes.credito'));
        if (!$r['ok']) {
            $o = $svc->original((int) ($in['original'] ?? 0));
            if (is_string($o)) {
                Session::flash('error', $o);
                return Response::redirect(url('/cambios'));
            }
            return self::form($o, $r['errores'], $in, array_intersect_key($in, array_flip(self::CAMPOS_PAGO)), 422);
        }
        try {
            $e = $svc->emitir($r['cambio'], (string) ($in['operacion'] ?? ''), (int) Auth::id());
        } catch (\App\Modules\Licencia\LicenciaVencida $ex) {
            Session::flash('error', $ex->getMessage());
            return Response::redirect(url('/cambios'));
        } catch (CambioInvalido $ex) {
            Session::flash('error', $ex->getMessage());
            return Response::redirect(url('/cambios'));
        } catch (\InvalidArgumentException) {
            Session::flash('error', 'La pantalla venció. Volvé a leer el ticket.');
            return Response::redirect(url('/cambios'));
        }
        $dif = (float) $r['cambio']['diferencia'];
        Session::flash($e['repetido'] ? 'info' : 'ok', $e['repetido'] ? 'Ese cambio ya se había hecho (no se hizo otro).'
            : 'Cambio X ' . numero_cbte($r['cambio']['prefijo'], $e['numero']) . ' hecho.' . ($dif > 0.004 ? ' Diferencia cobrada: ' . plata_dec($dif) . '.' : ' Sin diferencia a cobrar.')
                . (($e['vuelto'] ?? 0) > 0 ? ' DAR VUELTO AL CLIENTE: ' . plata_dec($e['vuelto']) . '.' : ''));
        if (!$e['repetido'] && ($e['vuelto'] ?? 0) > 0) {
            Session::flash('vuelto', (string) $e['vuelto']); // D-134
        }
        return Response::redirect(url('/comprobantes/' . $e['id']));
    }

    /** Ticket de cambio en PDF de un presupuesto o una factura, en A4 o en ticket de 80 / 50 mm según Parámetros. */
    public static function ticket(Request $req, array $params): Response
    {
        if (!CambiosService::habilitado()) {
            return new Response(404);
        }
        $svc = new ComprobantesService();
        $h = $svc->obtener((int) $params['id']);
        if ($h === null || !in_array($h['tipo'], ['FC', 'PR'], true) || $h['estado'] !== '' || (int) $h['solo_subdiario'] === 1) {
            return new Response(404);
        }
        // mismo papel que el resto de los comprobantes (Parámetros → impresión), o el que se pida con ?papel=a4|t80|t50 (D-141)
        $pdf = TicketCambioPdf::generar($h, $svc->emisor(), isset($req->query['papel']) ? (string) $req->query['papel'] : null);
        return new Response(200, $pdf, ['Content-Type' => 'application/pdf', 'Content-Disposition' => 'inline; filename="ticket-de-cambio.pdf"', 'Cache-Control' => 'no-store']);
    }

    /** Buscador de artículos de la pantalla de cambio (JSON): el mismo de los comprobantes, con permiso propio. */
    public static function buscarArticulo(Request $req, array $params): Response
    {
        if (isset($req->query['listar'])) {
            return Response::json(BuscadorArticulos::listar(trim((string) ($req->query['q'] ?? '')), (int) ($req->query['pagina'] ?? 1)));
        }
        return Response::json(BuscadorArticulos::buscar(trim((string) ($req->query['q'] ?? '')), isset($req->query['exacto'])));
    }

    /**
     * @param array{h:array<string,mixed>,items:array<int,array<string,mixed>>,cliente:array<string,mixed>} $o
     * @param array<string,string> $errores @param array<string,mixed> $datos lo cargado @param array<string,mixed> $pagoCargado
     */
    private static function form(array $o, array $errores, array $datos, array $pagoCargado, int $status): Response
    {
        $pdo = Db::pdo();
        return Response::html(View::render('cambios/nuevo', [
            'title' => 'Cambio de mercadería', 'nav' => 'cambios', 'o' => $o, 'errores' => $errores, 'datos' => $datos, 'pagoCargado' => $pagoCargado,
            'operacion' => preg_match('/^[0-9a-f]{32}$/', (string) ($datos['operacion'] ?? '')) === 1 ? (string) $datos['operacion'] : bin2hex(random_bytes(16)),
            'formas' => $pdo->query('SELECT codigo, descripcion, tipo, acepta_vuelto FROM forma_de_pagos WHERE tipo IN (1, 2, 3, 5, 6, 10) ORDER BY tipo, descripcion')->fetchAll(),
            'bancos' => $pdo->query('SELECT codigo, descripcion FROM bancos ORDER BY descripcion')->fetchAll(), 'puedeCredito' => Auth::puede('comprobantes.credito'),
        ]), $status);
    }
}
