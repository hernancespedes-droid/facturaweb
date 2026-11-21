<?php
declare(strict_types=1);

namespace App\Modules\Compras;

use App\Core\ExcelListado;
use App\Core\Mailer;
use App\Core\Modulos;
use App\Core\Session;
use App\Core\Valida;
use App\Core\View;
use App\Http\Request;
use App\Http\Response;
use App\Modules\Auth\Auth;
use App\Modules\Caja\CajaService;
use App\Modules\Configuracion\Configuracion;

/** Órdenes de pago y cuenta corriente de proveedores (rutas y permisos en routes.php). */
final class OrdenesPagoController
{
    private static function uid(): int
    {
        return (int) Auth::id();
    }

    // ------------------------------------------------------------------ nueva

    /** @param array<string,mixed> $datos @param array<string,string> $errores */
    private static function form(array $datos, array $errores, ?string $operacion, int $status = 200): Response
    {
        $svc = new OrdenesPagoService();
        $provId = (int) ($datos['proveedor'] ?? 0);
        $ps = new ProveedoresService();
        $prov = $provId > 0 ? $ps->obtener($provId) : null;
        return Response::html(View::render('compras/pago_form', [
            'title' => 'Nueva orden de pago', 'nav' => 'compras_pagos', 'datos' => $datos, 'errores' => $errores, 'prov' => $prov,
            'provElegir' => $prov !== null ? ($ps->paraElegir([$prov])[0] ?? null) : null,
            'pend' => $prov !== null ? $svc->pendientes((int) $prov['id']) : null, 'cheques' => $prov !== null ? $svc->chequesEnCartera() : [],
            'operacion' => $operacion ?? bin2hex(random_bytes(16)),
            'turno' => Modulos::activo('caja') ? (new CajaService())->turnoAbierto(self::uid()) : ['id' => 0],
        ]), $status);
    }

    public static function nueva(Request $req, array $params): Response
    {
        $d = ['proveedor' => (int) ($req->query['proveedor'] ?? 0), 'fecha' => date('d/m/Y')];
        $compra = (int) ($req->query['compra'] ?? 0);
        if ($compra > 0) { // desde la ficha de una compra: queda tildada con todo su saldo
            $c = (new ComprasService())->obtener($compra);
            if ($c !== null && $c['estado'] === 'V' && $c['forma'] === 'C' && (float) $c['saldo'] > 0.005) {
                $d['proveedor'] = (int) $c['proveedor_id'];
                $d[$c['tipo'] === 'NC' ? 'cre' : 'deb'] = [$compra => number_format((float) $c['saldo'], 2, ',', '')];
            }
        }
        return self::form($d, [], null);
    }

    public static function emitir(Request $req, array $params): Response
    {
        $p = $req->post;
        $op = is_string($p['operacion'] ?? null) ? $p['operacion'] : null;
        $svc = new OrdenesPagoService();
        $a = $svc->armar($p);
        if (!$a['ok']) {
            return self::form($p, $a['errores'], $op, 422);
        }
        $r = $svc->emitir($a['orden'], self::uid(), $op);
        if (!$r['ok']) {
            return self::form($p, ['general' => $r['error']], $op, 422);
        }
        Session::flash('ok', OrdenesPagoService::numeroVisible($r['numero']) . ' emitida.');
        return Response::redirect(url('/compras/pagos/ver/' . $r['id']));
    }

    // ------------------------------------------------------------------ ver, imprimir, mail, anular

    public static function ver(Request $req, array $params, ?string $error = null, int $status = 200): Response
    {
        $o = (new OrdenesPagoService())->obtener((int) ($params['id'] ?? 0));
        if ($o === null) {
            return new Response(404);
        }
        return Response::html(View::render('compras/pago_ver', ['title' => OrdenesPagoService::numeroVisible((int) $o['numero']), 'nav' => 'compras_pagos', 'o' => $o,
            'error' => $error, 'puedePagar' => Auth::puede('compras.pagar'), 'mailListo' => Mailer::configurado()]), $status);
    }

    public static function pdf(Request $req, array $params): Response
    {
        $o = (new OrdenesPagoService())->obtener((int) ($params['id'] ?? 0));
        if ($o === null) {
            return new Response(404);
        }
        $nombre = 'orden-de-pago-' . sprintf('%08d', (int) $o['numero']) . '.pdf';
        return new Response(200, OrdenPagoPdf::generar($o), ['Content-Type' => 'application/pdf', 'Content-Disposition' => 'inline; filename="' . $nombre . '"',
            'Cache-Control' => 'private, max-age=60']);
    }

    public static function mail(Request $req, array $params): Response
    {
        $id = (int) ($req->post['orden'] ?? 0);
        $o = (new OrdenesPagoService())->obtener($id);
        if ($o === null) {
            return new Response(404);
        }
        $para = trim((string) ($req->post['para'] ?? ''));
        if (filter_var($para, FILTER_VALIDATE_EMAIL) === false) {
            return self::ver($req, ['id' => $id], 'Escribí un mail válido.', 422);
        }
        $emp = OrdenPagoPdf::empresa();
        $titulo = OrdenesPagoService::numeroVisible((int) $o['numero']);
        $texto = "Hola,\n\nTe enviamos la orden de pago {$titulo} del " . fecha($o['fecha']) . ' por ' . plata_dec((float) $o['pagado']) . ".\n"
            . "El detalle de lo que se cancela y de los medios de pago va en el PDF adjunto.\n\nSaludos,\n" . $emp['nombre'];
        $error = Mailer::enviar([$para], $titulo . ' · ' . $emp['nombre'], $texto, ['orden-de-pago-' . sprintf('%08d', (int) $o['numero']) . '.pdf' => OrdenPagoPdf::generar($o)]);
        if ($error !== null) {
            return self::ver($req, ['id' => $id], 'No se pudo enviar el mail: ' . $error, 422);
        }
        Session::flash('ok', 'Orden de pago enviada a ' . $para . '.');
        return Response::redirect(url('/compras/pagos/ver/' . $id));
    }

    public static function anular(Request $req, array $params): Response
    {
        $id = (int) ($req->post['orden'] ?? 0);
        $r = (new OrdenesPagoService())->anular($id, self::uid(), (string) ($req->post['motivo'] ?? ''));
        if (!$r['ok']) {
            return self::ver($req, ['id' => $id], $r['error'], 422);
        }
        Session::flash('ok', 'Orden de pago anulada: los comprobantes vuelven a estar pendientes.');
        return Response::redirect(url('/compras/pagos/ver/' . $id));
    }

    // ------------------------------------------------------------------ listados

    public static function lista(Request $req, array $params): Response
    {
        $f = array_map(static fn ($x) => is_string($x) ? trim($x) : '', array_intersect_key($req->query, array_flip(['desde', 'hasta', 'proveedor', 'estado', 'medio', 'orden'])));
        if (!array_key_exists('desde', $req->query)) {
            $f['desde'] = date('01/m/Y');
            $f['hasta'] = date('t/m/Y');
        }
        $svc = new OrdenesPagoService();
        if (ExcelListado::pedido($req)) {
            $r = $svc->listar($f + ['sin_paginar' => 1]);
            $n = static fn (string $k) => static fn (array $x) => ExcelListado::numero((float) $x[$k]);
            return ExcelListado::respuesta('ordenes-de-pago', 'Órdenes de pago', [
                ['Número', 14, static fn (array $x) => OrdenesPagoService::numeroVisible((int) $x['numero'])], ['Fecha', 12, static fn (array $x) => fecha($x['fecha'])],
                ['Proveedor', 36, static fn (array $x) => (string) $x['razon_social']], ['CUIT', 14, static fn (array $x) => cuit_texto($x['cuit'])],
                ['Cancela', 15, $n('debitos')], ['Descuenta', 15, $n('creditos')], ['Retenciones', 15, $n('retenciones')], ['Pagado', 15, $n('pagado')],
                ['Anticipo', 15, $n('anticipo')], ['Medios', 30, static fn (array $x) => self::medios((string) $x['medios'])],
                ['Estado', 10, static fn (array $x) => $x['estado'] === 'A' ? 'ANULADA' : ''],
            ], $r['filas']);
        }
        $pagina = max(1, (int) ($req->query['pagina'] ?? 1));
        $por = Configuracion::porPagina();
        $prov = (int) ($f['proveedor'] ?? 0) > 0 ? (new ProveedoresService())->obtener((int) $f['proveedor']) : null;
        return Response::html(View::render('compras/pagos', ['title' => 'Órdenes de pago', 'nav' => 'compras_pagos', 'f' => $f, 'r' => $svc->listar($f, $pagina, $por),
            'pagina' => $pagina, 'porPagina' => $por, 'prov' => $prov, 'puedePagar' => Auth::puede('compras.pagar')]));
    }

    /** «ETQ» → «Efectivo de la caja, Transferencia, Cheque de terceros…» */
    public static function medios(string $codigos): string
    {
        $out = [];
        foreach (str_split($codigos) as $c) {
            if (isset(OrdenesPagoService::MEDIOS[$c])) {
                $out[] = OrdenesPagoService::MEDIOS[$c];
            }
        }
        return implode(', ', $out);
    }

    public static function cuenta(Request $req, array $params): Response
    {
        $ps = new ProveedoresService();
        $prov = $ps->obtener((int) ($req->query['proveedor'] ?? 0));
        $desdeTxt = trim((string) ($req->query['desde'] ?? ''));
        $hastaTxt = trim((string) ($req->query['hasta'] ?? ''));
        $r = $prov !== null ? (new OrdenesPagoService())->cuentaCorriente((int) $prov['id'], Valida::fecha($desdeTxt), Valida::fecha($hastaTxt)) : null;
        if ($r !== null && ExcelListado::pedido($req)) {
            $n = static fn (string $k) => static fn (array $x) => ExcelListado::numero((float) $x[$k]);
            return ExcelListado::respuesta('cuenta-proveedor', 'Cuenta corriente', [
                ['Fecha', 12, static fn (array $x) => fecha($x['fecha'])], ['Comprobante', 34, static fn (array $x) => (string) $x['titulo']],
                ['Vence', 12, static fn (array $x) => $x['vence'] ? fecha($x['vence']) : ''], ['Debe', 15, $n('debe')], ['Haber', 15, $n('haber')], ['Saldo', 15, $n('saldo')],
            ], $r['filas']);
        }
        return Response::html(View::render('compras/cuenta', ['title' => 'Cuenta corriente de proveedores', 'nav' => 'proveedores', 'prov' => $prov,
            'provElegir' => $prov !== null ? ($ps->paraElegir([$prov])[0] ?? null) : null, 'r' => $r, 'desde' => $desdeTxt, 'hasta' => $hastaTxt,
            'pend' => $prov !== null ? (new OrdenesPagoService())->pendientes((int) $prov['id']) : null, 'puedePagar' => Auth::puede('compras.pagar')]));
    }
}
