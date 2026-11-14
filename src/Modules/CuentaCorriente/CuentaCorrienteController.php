<?php
declare(strict_types=1);

namespace App\Modules\CuentaCorriente;

use App\Core\ExcelListado;
use App\Core\Session;
use App\Core\Valida;
use App\Core\View;
use App\Http\Request;
use App\Http\Response;
use App\Modules\Auth\Auth;
use App\Modules\Comprobantes\ComprobantesController;
use App\Modules\Comprobantes\ComprobantesService;
use App\Modules\Configuracion\Configuracion;

/** Cuenta corriente, recibos, imputación manual, ajustes y cartera de cheques. Permisos: ctacte.ver / cobrar / imputar / ajustar (en routes.php). */
final class CuentaCorrienteController
{
    private static function filtros(Request $req, array $claves): array
    {
        return array_map(static fn ($x) => is_string($x) ? $x : '', array_intersect_key($req->query, array_flip($claves)));
    }

    private static function fechaIso(string $txt): ?string
    {
        return $txt === '' ? null : Valida::fecha($txt);
    }

    // ------------------------------------------------------------------ cuenta corriente

    public static function resumen(Request $req, array $params): Response
    {
        $f = self::filtros($req, ['q', 'al', 'solo_deudores']);
        if (!array_key_exists('q', $req->query)) {
            $f['solo_deudores'] = '1';
        }
        $svc = new CuentaCorrienteService();
        if (ExcelListado::pedido($req)) {
            $r = $svc->resumenDeuda($f, 1, 1, true);
            $n = static fn (string $k) => static fn (array $x) => ExcelListado::numero($x[$k]);
            return ExcelListado::respuesta('resumen-de-deuda', 'Resumen de deuda', [
                ['Cliente', 8, static fn (array $x) => (int) $x['cliente']], ['Razón social', 38, static fn (array $x) => (string) $x['razon_social']],
                ['CUIT', 14, static fn (array $x) => (string) ($x['cuit'] ?? '')], ['Deuda', 15, $n('deuda')], ['Saldo a favor', 15, $n('a_favor')],
                ['Saldo', 15, $n('saldo')], ['Vencido', 15, $n('vencido')],
                ['Primer vencimiento', 16, static fn (array $x) => fecha($x['primer_vencimiento'])],
            ], $r['filas']);
        }
        $pagina = max(1, (int) ($req->query['pagina'] ?? 1));
        $por = Configuracion::porPagina();
        $r = $svc->resumenDeuda($f, $pagina, $por);
        return Response::html(View::render('ctacte/resumen', ['title' => 'Cuenta corriente', 'nav' => 'ctacte', 'f' => $f, 'filas' => $r['filas'], 'total' => $r['total'],
            'totales' => $r['totales'], 'pagina' => $pagina, 'porPagina' => $por]));
    }

    public static function cliente(Request $req, array $params): Response
    {
        $svc = new CuentaCorrienteService();
        $cli = $svc->cliente((int) $params['codigo']);
        if ($cli === null) {
            return new Response(404);
        }
        $f = self::filtros($req, ['desde', 'hasta']);
        $desde = self::fechaIso($f['desde'] ?? '');
        $hasta = self::fechaIso($f['hasta'] ?? '');
        $m = $svc->movimientos((int) $cli['codigo'], $desde, $hasta);
        if (ExcelListado::pedido($req)) {
            $filas = $m['filas'];
            $n = static fn (string $k) => static fn (array $x) => ExcelListado::numero($x[$k]);
            return ExcelListado::respuesta('cuenta-corriente-' . (int) $cli['codigo'], 'Cuenta corriente', [
                ['Fecha', 12, static fn (array $x) => fecha($x['fecha'])],
                ['Comprobante', 28, static fn (array $x) => (CuentaCorrienteService::TIPOS[$x['tipo']] ?? $x['tipo']) . ' ' . $x['letra'] . ' ' . numero_cbte($x['prefijo'], $x['numero_visible'])],
                ['Debe', 15, $n('debe')], ['Haber', 15, $n('haber')], ['Saldo acumulado', 16, $n('acumulado')], ['Pendiente', 15, $n('saldo')],
                ['Vencimiento', 12, static fn (array $x) => (int) $x['tico'] < 7 ? fecha($x['vencimiento']) : ''],
            ], $filas);
        }
        return Response::html(View::render('ctacte/cliente', ['title' => 'Cuenta corriente de ' . $cli['razon_social'], 'nav' => 'ctacte', 'cli' => $cli, 'f' => $f, 'm' => $m,
            'pend' => $svc->pendientes((int) $cli['codigo']), 'saldo' => $svc->saldo((int) $cli['codigo'])]));
    }

    /** Desde el buscador de clientes: ?cliente=123 va al extracto de ese cliente. */
    public static function clienteIr(Request $req, array $params): Response
    {
        $c = (int) ($req->query['cliente'] ?? 0);
        return Response::redirect($c > 0 ? url('/cuenta-corriente/cliente/' . $c) : url('/cuenta-corriente'));
    }

    public static function buscarCliente(Request $req, array $params): Response
    {
        return ComprobantesController::buscarCliente($req, $params);
    }

    // ------------------------------------------------------------------ recibos

    public static function recibos(Request $req, array $params): Response
    {
        $f = self::filtros($req, ['desde', 'hasta', 'cliente']);
        if (!array_key_exists('desde', $req->query)) {
            $f['desde'] = date('01/m/Y');
            $f['hasta'] = date('t/m/Y');
        }
        $svc = new CuentaCorrienteService();
        if (ExcelListado::pedido($req)) {
            $r = $svc->recibos($f, 1, 50000);
            $n = static fn (string $k) => static fn (array $x) => ExcelListado::numero($x[$k]);
            return ExcelListado::respuesta('cobranzas', 'Cobranzas', [
                ['Fecha', 12, static fn (array $x) => fecha($x['fecha'])], ['Recibo', 16, static fn (array $x) => numero_cbte($x['prefijo'], $x['recibo'])],
                ['Cliente', 8, static fn (array $x) => (int) $x['cliente']], ['Razón social', 38, static fn (array $x) => (string) $x['razon_social']],
                ['Cancela', 15, $n('bruto')], ['Cobrado', 15, $n('neto')], ['Estado', 10, static fn (array $x) => $x['estado'] === 'A' ? 'ANULADO' : ''],
            ], $r['filas']);
        }
        $pagina = max(1, (int) ($req->query['pagina'] ?? 1));
        $por = Configuracion::porPagina();
        $r = $svc->recibos($f, $pagina, $por);
        return Response::html(View::render('ctacte/recibos', ['title' => 'Recibos', 'nav' => 'recibos', 'f' => $f, 'filas' => $r['filas'], 'total' => $r['total'], 'pagina' => $pagina, 'porPagina' => $por]));
    }

    /** @param array<string,mixed> $datos @param array<string,string> $errores */
    private static function formRecibo(CuentaCorrienteService $svc, array $datos, array $errores, ?string $operacion, int $status = 200): Response
    {
        $cli = $svc->cliente((int) ($datos['cliente'] ?? 0));
        $sucs = (new ComprobantesService())->sucursales();
        return Response::html(View::render('ctacte/recibo_form', [
            'title' => 'Nuevo recibo', 'nav' => 'recibos_nuevo', 'cli' => $cli, 'datos' => $datos, 'errores' => $errores,
            'pend' => $cli !== null ? $svc->pendientes((int) $cli['codigo']) : ['debitos' => [], 'creditos' => []],
            'saldo' => $cli !== null ? $svc->saldo((int) $cli['codigo']) : 0.0, 'sucursales' => $sucs, 'formas' => $svc->formasDePago(),
            'bancos' => \App\Core\Db::pdo()->query('SELECT codigo, descripcion FROM bancos ORDER BY descripcion')->fetchAll(),
            'retenciones' => $svc->retenciones(), 'operacion' => $operacion ?? bin2hex(random_bytes(16)),
        ]), $status);
    }

    public static function reciboNuevo(Request $req, array $params): Response
    {
        $svc = new CuentaCorrienteService();
        $datos = ['cliente' => (string) ($req->query['cliente'] ?? ''), 'fecha' => date('d/m/Y')];
        if ($svc->cliente((int) $datos['cliente']) === null) {
            $datos['cliente'] = '';
        }
        return self::formRecibo($svc, $datos, [], null);
    }

    public static function reciboGrabar(Request $req, array $params): Response
    {
        $p = $req->post;
        $datos = ['cliente' => (string) ($p['cliente'] ?? ''), 'fecha' => (string) ($p['fecha'] ?? ''), 'sucursal' => (string) ($p['sucursal'] ?? ''), 'anticipo' => (string) ($p['anticipo'] ?? ''),
            'descuento' => (string) ($p['descuento'] ?? ''), 'observaciones' => (string) ($p['observaciones'] ?? ''), 'imputar' => (array) ($p['imputar'] ?? []), 'ret' => array_values((array) ($p['ret'] ?? [])),
            'pagos' => array_values((array) ($p['pagos'] ?? []))];
        $operacion = (string) ($p['operacion'] ?? '');
        $svc = new CuentaCorrienteService();
        $r = $svc->armarRecibo($datos);
        if ($r['errores'] !== []) {
            return self::formRecibo($svc, $datos, $r['errores'], preg_match('/^[0-9a-f]{32}$/', $operacion) === 1 ? $operacion : null, 422);
        }
        try {
            $e = $svc->emitirRecibo($r['recibo'], $operacion, (int) Auth::id());
        } catch (CuentaCorrienteError $ex) {
            return self::formRecibo($svc, $datos, ['general' => $ex->getMessage()], null, 422);
        } catch (\InvalidArgumentException $ex) {
            return self::formRecibo($svc, $datos, ['general' => 'Se perdió la clave del envío. Volvé a confirmar el recibo.'], null, 422);
        }
        Session::flash('ok', $e['repetido'] ? 'Ese recibo ya estaba cargado.' : 'Recibo N.º ' . numero_cbte($r['recibo']['prefijo'], $e['numero']) . ' grabado.');
        return Response::redirect(url('/recibos/' . $e['id']));
    }

    public static function recibo(Request $req, array $params): Response
    {
        $svc = new CuentaCorrienteService();
        $c = $svc->recibo((int) $params['id']);
        if ($c === null) {
            return new Response(404);
        }
        return Response::html(View::render('ctacte/recibo', ['title' => 'Recibo ' . numero_cbte($c['prefijo'], $c['recibo']), 'nav' => 'recibos', 'c' => $c, 'puedeAnular' => Auth::puede('ctacte.ajustar')]));
    }

    public static function imprimir(Request $req, array $params): Response
    {
        $c = (new CuentaCorrienteService())->recibo((int) $params['id']);
        if ($c === null) {
            return new Response(404);
        }
        return Response::html(View::render('ctacte/recibo_imprimir', ['c' => $c], null));
    }

    public static function anular(Request $req, array $params): Response
    {
        $r = (new CuentaCorrienteService())->anularRecibo((int) $params['id'], (int) Auth::id());
        Session::flash($r['ok'] ? 'ok' : 'error', $r['ok'] ? 'Recibo anulado: la deuda volvió a su saldo.' : (string) $r['error']);
        return Response::redirect(url('/recibos/' . (int) $params['id']));
    }

    // ------------------------------------------------------------------ imputación manual y ajustes

    private static function formImputar(CuentaCorrienteService $svc, string $cliente, ?string $error, int $status = 200): Response
    {
        $cli = $svc->cliente((int) $cliente);
        return Response::html(View::render('ctacte/imputar', ['title' => 'Imputación manual', 'nav' => 'imputar', 'cli' => $cli, 'error' => $error,
            'pend' => $cli !== null ? $svc->pendientes((int) $cli['codigo']) : ['debitos' => [], 'creditos' => []]]), $status);
    }

    public static function imputarForm(Request $req, array $params): Response
    {
        return self::formImputar(new CuentaCorrienteService(), (string) ($req->query['cliente'] ?? ''), null);
    }

    public static function imputar(Request $req, array $params): Response
    {
        $svc = new CuentaCorrienteService();
        $p = $req->post;
        $r = $svc->imputarManual((int) ($p['cliente'] ?? 0), (array) ($p['credito'] ?? []), (array) ($p['debito'] ?? []), (int) Auth::id());
        if (!$r['ok']) {
            return self::formImputar($svc, (string) ($p['cliente'] ?? ''), (string) $r['error'], 422);
        }
        Session::flash('ok', 'Imputación hecha: ' . plata_dec($r['total']) . ' aplicados.');
        return Response::redirect(url('/cuenta-corriente/cliente/' . (int) $p['cliente']));
    }

    private static function formAjuste(array $datos, ?string $error, int $status = 200): Response
    {
        $svc = new CuentaCorrienteService();
        return Response::html(View::render('ctacte/ajuste', ['title' => 'Ajuste de cuenta corriente', 'nav' => 'ctacte_ajuste', 'datos' => $datos, 'error' => $error,
            'cli' => $svc->cliente((int) ($datos['cliente'] ?? 0)), 'sucursales' => (new ComprobantesService())->sucursales()]), $status);
    }

    public static function ajusteForm(Request $req, array $params): Response
    {
        return self::formAjuste(['cliente' => (string) ($req->query['cliente'] ?? ''), 'tipo' => 'AD', 'fecha' => date('d/m/Y')], null);
    }

    public static function ajuste(Request $req, array $params): Response
    {
        $p = $req->post;
        $datos = ['cliente' => (string) ($p['cliente'] ?? ''), 'tipo' => (string) ($p['tipo'] ?? ''), 'fecha' => (string) ($p['fecha'] ?? ''), 'importe' => (string) ($p['importe'] ?? ''),
            'sucursal' => (string) ($p['sucursal'] ?? ''), 'observaciones' => (string) ($p['observaciones'] ?? '')];
        $sucs = (new ComprobantesService())->sucursales();
        $suc = (int) ($datos['sucursal'] !== '' ? $datos['sucursal'] : (count($sucs) === 1 ? array_key_first($sucs) : 0));
        $r = (new CuentaCorrienteService())->ajuste($datos['tipo'], (int) $datos['cliente'], $datos['fecha'], $datos['importe'], $suc, $datos['observaciones'], (int) Auth::id());
        if (!$r['ok']) {
            return self::formAjuste($datos, (string) $r['error'], 422);
        }
        Session::flash('ok', 'Ajuste grabado.');
        return Response::redirect(url('/cuenta-corriente/cliente/' . (int) $datos['cliente']));
    }

    // ------------------------------------------------------------------ cheques

    public static function cheques(Request $req, array $params): Response
    {
        $f = self::filtros($req, ['estado', 'desde', 'hasta', 'q', 'diferidos']);
        $svc = new CuentaCorrienteService();
        if (ExcelListado::pedido($req)) {
            $r = $svc->cheques($f, 1, 1, true);
            return ExcelListado::respuesta('cheques', 'Cheques', [
                ['Cobro', 12, static fn (array $x) => fecha($x['p_fecvto'])], ['Número', 14, static fn (array $x) => (string) $x['p_nro']], ['Banco', 24, static fn (array $x) => (string) ($x['banco'] ?? '')],
                ['Cliente', 36, static fn (array $x) => (string) $x['razon_social']], ['Importe', 15, static fn (array $x) => ExcelListado::numero($x['p_importe'])],
                ['Estado', 22, static fn (array $x) => CuentaCorrienteService::ESTADOS_CHEQUE[$x['cheque_estado']] ?? ''],
                ['Recibido', 12, static fn (array $x) => fecha($x['fecha'])],
            ], $r['filas']);
        }
        $pagina = max(1, (int) ($req->query['pagina'] ?? 1));
        $por = Configuracion::porPagina();
        $r = $svc->cheques($f, $pagina, $por);
        return Response::html(View::render('ctacte/cheques', ['title' => 'Cheques', 'nav' => 'cheques', 'f' => $f, 'filas' => $r['filas'], 'total' => $r['total'], 'suma' => $r['suma'],
            'pagina' => $pagina, 'porPagina' => $por, 'puedeCambiar' => Auth::puede('ctacte.ajustar')]));
    }

    public static function estadoCheque(Request $req, array $params): Response
    {
        $p = $req->post;
        $r = (new CuentaCorrienteService())->estadoCheque((int) $params['id'], (string) ($p['estado'] ?? 'x'), (string) ($p['fecha'] ?? date('d/m/Y')), (string) ($p['obs'] ?? ''));
        Session::flash($r['ok'] ? 'ok' : 'error', $r['ok'] ? 'Estado del cheque actualizado.' : (string) $r['error']);
        return Response::redirect(url('/cheques' . (isset($p['volver']) && str_starts_with((string) $p['volver'], '?') ? $p['volver'] : '')));
    }
}
