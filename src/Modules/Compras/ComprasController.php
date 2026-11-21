<?php
declare(strict_types=1);

namespace App\Modules\Compras;

use App\Core\ExcelListado;
use App\Core\Session;
use App\Core\View;
use App\Http\Request;
use App\Http\Response;
use App\Modules\Auth\Auth;
use App\Modules\Configuracion\Configuracion;

/** Pantallas de compras y centros de costos (rutas y permisos en routes.php). */
final class ComprasController
{
    /** @param string[] $claves @return array<string,string> */
    private static function tomar(Request $req, array $claves): array
    {
        return array_map(static fn ($x) => is_string($x) ? trim($x) : '', array_intersect_key($req->query, array_flip($claves)));
    }

    private static function periodo(Request $req): string
    {
        $p = (string) ($req->query['periodo'] ?? '');
        return CentrosService::periodoValido($p) ? $p : date('Y-m');
    }

    private static function uid(): int
    {
        return (int) Auth::id();
    }

    /** Opciones comunes de los filtros. @return array<string,mixed> */
    private static function opciones(): array
    {
        $cs = new CentrosService();
        return ['centros' => $cs->centros(), 'conceptos' => $cs->conceptos()];
    }

    private static function proveedorElegido(array $f): ?array
    {
        $id = (int) ($f['proveedor'] ?? 0);
        return $id > 0 ? (new ProveedoresService())->obtener($id) : null;
    }

    // ------------------------------------------------------------------ tablero

    public static function tablero(Request $req, array $params): Response
    {
        $periodo = self::periodo($req);
        return Response::html(View::render('compras/tablero', ['title' => 'Tablero de compras', 'nav' => 'compras', 'periodo' => $periodo,
            't' => ComprasListados::tablero($periodo), 'puedeCargar' => Auth::puede('compras.cargar'), 'puedeCentros' => Auth::puede('compras.centros')]));
    }

    // ------------------------------------------------------------------ compras cargadas

    private const FILTROS_LISTA = ['desde', 'hasta', 'proveedor', 'centro', 'concepto', 'tipo', 'forma', 'estado', 'q', 'orden'];

    public static function lista(Request $req, array $params): Response
    {
        $f = self::tomar($req, self::FILTROS_LISTA);
        if (!array_key_exists('desde', $req->query) && !array_key_exists('estado', $req->query)) {
            $f['desde'] = date('01/m/Y');
            $f['hasta'] = date('t/m/Y');
        }
        $svc = new ComprasService();
        if (ExcelListado::pedido($req)) {
            $r = $svc->listar($f + ['sin_paginar' => 1], 1, 1);
            $n = static fn (string $k) => static fn (array $x) => ExcelListado::numero((float) $x[$k] * ComprasService::signo($x['tipo']));
            return ExcelListado::respuesta('compras', 'Compras', [
                ['Fecha', 12, static fn (array $x) => fecha($x['fecha'])], ['Comprobante', 30, static fn (array $x) => ComprasService::titulo($x)],
                ['Proveedor', 36, static fn (array $x) => (string) $x['razon_social']], ['CUIT', 14, static fn (array $x) => cuit_texto($x['cuit'])],
                ['Centros', 28, static fn (array $x) => (string) $x['centros']], ['Total', 15, $n('total')], ['Costo', 15, $n('costo')],
                ['Pago', 22, static fn (array $x) => ComprasService::FORMAS[$x['forma']] ?? ''], ['Vence', 12, static fn (array $x) => $x['vence'] ? fecha($x['vence']) : ''],
                ['Saldo', 15, $n('saldo')], ['Estado', 10, static fn (array $x) => $x['estado'] === 'A' ? 'ANULADA' : ''],
            ], $r['filas']);
        }
        $pagina = max(1, (int) ($req->query['pagina'] ?? 1));
        $por = Configuracion::porPagina();
        $r = $svc->listar($f, $pagina, $por);
        return Response::html(View::render('compras/lista', ['title' => 'Compras cargadas', 'nav' => 'compras_lista', 'f' => $f, 'r' => $r, 'pagina' => $pagina,
            'porPagina' => $por, 'prov' => self::proveedorElegido($f), 'puedeCargar' => Auth::puede('compras.cargar')] + self::opciones()));
    }

    // ------------------------------------------------------------------ cargar

    /** @param array<string,mixed> $datos @param array<string,string> $errores */
    private static function form(array $datos, array $errores, ?string $operacion, int $status = 200): Response
    {
        $cs = new CentrosService();
        $prov = (int) ($datos['proveedor'] ?? 0) > 0 ? (new ProveedoresService())->obtener((int) $datos['proveedor']) : null;
        $svc = new ComprasService();
        return Response::html(View::render('compras/form', [
            'title' => 'Nueva compra o gasto', 'nav' => 'compras_nueva', 'datos' => $datos, 'errores' => $errores, 'prov' => $prov,
            'provElegir' => $prov !== null ? ((new ProveedoresService())->paraElegir([$prov])[0] ?? null) : null,
            'centros' => $cs->centros(true), 'conceptos' => $cs->conceptos(true), 'plantillas' => $cs->plantillas(), 'general' => $cs->general(),
            'conceptoOmision' => $cs->conceptoPorOmision(), 'inscripta' => $svc->empresaInscripta(), 'operacion' => $operacion ?? bin2hex(random_bytes(16)),
            'turno' => \App\Core\Modulos::activo('caja') ? (new \App\Modules\Caja\CajaService())->turnoAbierto(self::uid()) : ['id' => 0],
            'puedeCentros' => Auth::puede('compras.centros'),
        ]), $status);
    }

    public static function nueva(Request $req, array $params): Response
    {
        $d = ['fecha' => date('d/m/Y'), 'tipo' => 'FC', 'forma' => 'C'];
        if ((int) ($req->query['proveedor'] ?? 0) > 0) {
            $d['proveedor'] = (int) $req->query['proveedor'];
            $pr = (new ProveedoresService())->obtener($d['proveedor']);
            if ($pr !== null) { // la letra que corresponde a ese proveedor (lo mismo que hace compras.js al elegirlo)
                $d['letra'] = (int) $pr['iva'] === 1 ? ((new ComprasService())->empresaInscripta() ? 'A' : 'B') : 'C';
            }
        }
        return self::form($d, [], null);
    }

    public static function grabar(Request $req, array $params): Response
    {
        $svc = new ComprasService();
        $p = $req->post;
        $op = is_string($p['operacion'] ?? null) ? $p['operacion'] : null;
        $a = $svc->armar($p);
        if (!$a['ok']) {
            return self::form($p, $a['errores'], $op, 422);
        }
        $g = $svc->grabar($a['compra'], self::uid(), $op, ComprasService::archivosSubidos($req->files['adjuntos'] ?? null));
        if (!$g['ok']) {
            return self::form($p, ['general' => $g['error']], $op, 422);
        }
        $aviso = '';
        if (($p['guardar_plantilla'] ?? '') === '1' && Auth::puede('compras.centros') && count($a['compra']['reparto']) > 1) {
            $pl = (new CentrosService())->guardarPlantilla((string) ($p['nombre_plantilla'] ?? ''), array_map(static fn ($r) => ['centro' => $r['centro_id'],
                'porcentaje' => (float) $r['porcentaje']], $a['compra']['reparto']));
            $aviso = $pl['ok'] ? ' El reparto quedó guardado como plantilla.' : ' La plantilla no se guardó: ' . $pl['error'];
        }
        Session::flash('ok', 'Compra grabada.' . $aviso);
        return Response::redirect(url(($p['seguir'] ?? '') === '1' ? '/compras/nueva' : '/compras/ver/' . $g['id']));
    }

    public static function ver(Request $req, array $params, ?string $error = null, int $status = 200): Response
    {
        $c = (new ComprasService())->obtener((int) ($params['id'] ?? 0));
        if ($c === null) {
            return new Response(404);
        }
        return Response::html(View::render('compras/ver', ['title' => ComprasService::titulo($c), 'nav' => 'compras_lista', 'c' => $c, 'error' => $error,
            'puedeCargar' => Auth::puede('compras.cargar'), 'puedePagar' => Auth::puede('compras.pagar')]), $status);
    }

    public static function adjunto(Request $req, array $params): Response
    {
        $a = (new ComprasService())->adjunto((int) ($req->query['compra'] ?? 0), (int) ($req->query['id'] ?? 0));
        if ($a === null) {
            return new Response(404);
        }
        $nombre = str_replace(['"', "\r", "\n"], '', $a['nombre']);
        return new Response(200, $a['contenido'], ['Content-Type' => $a['mime'], 'Content-Disposition' => 'inline; filename="' . $nombre . '"',
            'Cache-Control' => 'private, max-age=600', 'X-Content-Type-Options' => 'nosniff']);
    }

    public static function anular(Request $req, array $params): Response
    {
        $id = (int) ($req->post['compra'] ?? 0);
        $r = (new ComprasService())->anular($id, self::uid(), (string) ($req->post['motivo'] ?? ''));
        if (!$r['ok']) {
            return self::ver($req, ['id' => $id], $r['error'], 422);
        }
        Session::flash('ok', 'Compra anulada.');
        return Response::redirect(url('/compras/ver/' . $id));
    }

    public static function revisada(Request $req, array $params): Response
    {
        $id = (int) ($req->post['compra'] ?? 0);
        (new ComprasService())->revisada($id);
        Session::flash('ok', 'Compra marcada como revisada.');
        return Response::redirect(url('/compras/ver/' . $id));
    }

    public static function pagar(Request $req, array $params): Response
    {
        $p = $req->post;
        $id = (int) ($p['compra'] ?? 0);
        $r = (new ComprasService())->pagar($id, self::uid(), (string) ($p['medio'] ?? ''), (string) ($p['importe'] ?? ''), (string) ($p['detalle'] ?? ''), (string) ($p['fecha'] ?? ''));
        if (!$r['ok']) {
            return self::ver($req, ['id' => $id], $r['error'], 422);
        }
        Session::flash('ok', 'Pago registrado.');
        $volver = (string) ($p['volver'] ?? '');
        return Response::redirect(url($volver === 'a-pagar' ? '/compras/a-pagar' : '/compras/ver/' . $id));
    }

    // ------------------------------------------------------------------ listados

    public static function aPagar(Request $req, array $params): Response
    {
        $f = self::tomar($req, ['proveedor', 'vence_hasta']);
        $r = ComprasListados::aPagar($f);
        if (ExcelListado::pedido($req)) {
            $n = static fn (string $k) => static fn (array $x) => ExcelListado::numero($x[$k]);
            return ExcelListado::respuesta('compras-a-pagar', 'A pagar', [
                ['Vence', 12, static fn (array $x) => $x['vence'] ? fecha($x['vence']) : ''], ['Proveedor', 36, static fn (array $x) => (string) $x['razon_social']],
                ['Comprobante', 30, static fn (array $x) => ComprasService::titulo($x)], ['Fecha', 12, static fn (array $x) => fecha($x['fecha'])],
                ['Días vencido', 10, static fn (array $x) => max(0, (int) $x['dias'])], ['Total', 15, static fn (array $x) => ExcelListado::numero((float) $x['total'] * ComprasService::signo($x['tipo']))],
                ['Saldo', 15, $n('saldo_signo')],
            ], $r['detalle']);
        }
        return Response::html(View::render('compras/a_pagar', ['title' => 'A pagar y vencimientos', 'nav' => 'compras_apagar', 'f' => $f, 'r' => $r,
            'prov' => self::proveedorElegido($f), 'puedePagar' => Auth::puede('compras.pagar'),
            'turno' => \App\Core\Modulos::activo('caja') ? (new \App\Modules\Caja\CajaService())->turnoAbierto(self::uid()) : ['id' => 0]]));
    }

    public static function analisis(Request $req, array $params): Response
    {
        $f = self::tomar($req, ['desde', 'hasta', 'proveedor', 'centro', 'concepto', 'agrupar', 'por_mes']);
        if (!array_key_exists('desde', $req->query)) { // por omisión, los últimos 6 meses con columnas por mes
            $f['desde'] = date('01/m/Y', strtotime(date('Y-m-01') . ' -5 months'));
            $f['hasta'] = date('t/m/Y');
            $f['por_mes'] = '1';
        }
        $r = ComprasListados::analisis($f);
        $porMes = ($f['por_mes'] ?? '') === '1' && $r['agrupar'] !== 'mes';
        if (ExcelListado::pedido($req)) {
            $cols = [[ComprasListados::AGRUPAR[$r['agrupar']][0], 38, static fn (array $x) => (string) $x['nombre']]];
            if ($porMes) {
                foreach ($r['meses'] as $ym) {
                    $cols[] = [ComprasListados::mesCorto($ym), 13, static fn (array $x) => ExcelListado::numero($x['meses'][$ym] ?? 0)];
                }
            }
            $cols[] = ['Total', 15, static fn (array $x) => ExcelListado::numero($x['total'])];
            $cols[] = ['% del total', 10, static fn (array $x) => $x['pct'] === null ? '' : round($x['pct'], 1)];
            $cols[] = ['Comprobantes', 10, static fn (array $x) => (int) $x['comprobantes']];
            return ExcelListado::respuesta('analisis-de-gastos', 'Gastos', $cols, $r['filas']);
        }
        return Response::html(View::render('compras/analisis', ['title' => 'Análisis de gastos', 'nav' => 'compras_analisis', 'f' => $f, 'r' => $r, 'porMes' => $porMes,
            'prov' => self::proveedorElegido($f)] + self::opciones()));
    }

    public static function presupuesto(Request $req, array $params): Response
    {
        $periodo = self::periodo($req);
        return Response::html(View::render('compras/presupuesto', ['title' => 'Presupuesto contra real', 'nav' => 'compras_presupuesto', 'periodo' => $periodo,
            'r' => ComprasListados::presupuestoReal($periodo), 'puedeCentros' => Auth::puede('compras.centros')]));
    }

    public static function iva(Request $req, array $params): Response
    {
        $periodo = self::periodo($req);
        $r = ComprasListados::ivaCompras($periodo);
        if (ExcelListado::pedido($req)) {
            $n = static fn (string $k) => static fn (array $x) => ExcelListado::numero($x[$k]);
            $cols = [['Fecha', 12, static fn (array $x) => fecha($x['fecha'])], ['Comprobante', 30, static fn (array $x) => ComprasService::titulo($x)],
                ['Proveedor', 36, static fn (array $x) => (string) $x['razon_social']], ['CUIT', 14, static fn (array $x) => cuit_texto($x['cuit'])],
                ['Neto gravado', 15, $n('gravado')], ['No gravado', 14, $n('no_gravado')], ['Exento', 13, $n('exento')]];
            foreach (array_keys(ComprasService::ALICUOTAS) as $k) {
                $cols[] = ['IVA ' . str_replace('.', ',', (string) $k) . ' %', 13, $n('iva_' . str_replace('.', '_', (string) $k))];
            }
            array_push($cols, ['Percepción IVA', 13, $n('perc_iva')], ['Percepción IIBB', 13, $n('perc_iibb')], ['Otros impuestos', 13, $n('perc_otras')],
                ['Total', 15, $n('total')], ['Crédito fiscal', 14, $n('credito')]);
            return ExcelListado::respuesta('subdiario-iva-compras', 'IVA compras', $cols, $r['filas']);
        }
        return Response::html(View::render('compras/iva', ['title' => 'Subdiario de IVA compras', 'nav' => 'compras_iva', 'periodo' => $periodo, 'r' => $r]));
    }

    // ------------------------------------------------------------------ proveedores

    public static function proveedores(Request $req, array $params): Response
    {
        $f = self::tomar($req, ['q', 'estado', 'orden']);
        $svc = new ProveedoresService();
        if (ExcelListado::pedido($req)) {
            $r = $svc->listar($f + ['sin_paginar' => 1], 1, 1);
            return ExcelListado::respuesta('proveedores', 'Proveedores', [
                ['Código', 8, static fn (array $x) => (int) $x['codigo']], ['Razón social', 38, static fn (array $x) => (string) $x['razon_social']],
                ['CUIT', 14, static fn (array $x) => cuit_texto($x['cuit'])], ['Condición de IVA', 22, static fn (array $x) => ProveedoresService::CONDICIONES[(int) $x['iva']] ?? ''],
                ['Localidad', 20, static fn (array $x) => (string) $x['localidad']], ['Teléfono', 16, static fn (array $x) => (string) $x['telefono']],
                ['Mail', 26, static fn (array $x) => (string) $x['email']], ['CBU / alias', 24, static fn (array $x) => (string) $x['cbu_alias']],
                ['Centro habitual', 22, static fn (array $x) => (string) $x['centro']], ['Concepto habitual', 22, static fn (array $x) => (string) $x['concepto']],
                ['Saldo', 15, static fn (array $x) => ExcelListado::numero($x['saldo'])], ['Activo', 8, static fn (array $x) => (int) $x['activo'] === 1 ? 'Sí' : 'No'],
            ], $r['filas']);
        }
        $pagina = max(1, (int) ($req->query['pagina'] ?? 1));
        $por = Configuracion::porPagina();
        return Response::html(View::render('compras/proveedores', ['title' => 'Proveedores', 'nav' => 'proveedores', 'f' => $f, 'r' => $svc->listar($f, $pagina, $por),
            'pagina' => $pagina, 'porPagina' => $por, 'puedeCargar' => Auth::puede('compras.cargar')]));
    }

    /** Ficha del proveedor: datos (editables con compras.cargar), saldo y últimas compras. ?id= vacío = alta. */
    public static function proveedorFicha(Request $req, array $params, ?array $datos = null, array $errores = [], int $status = 200): Response
    {
        $svc = new ProveedoresService();
        $id = (int) ($req->query['id'] ?? ($req->post['id'] ?? 0));
        $p = $id > 0 ? $svc->obtener($id) : null;
        if ($id > 0 && $p === null) {
            return new Response(404);
        }
        if ($p === null && !Auth::puede('compras.cargar')) {
            return new Response(403);
        }
        $cs = new CentrosService();
        $compras = $p !== null ? (new ComprasService())->listar(['proveedor' => (string) $id], 1, 15) : null;
        return Response::html(View::render('compras/proveedor', ['title' => $p['razon_social'] ?? 'Nuevo proveedor', 'nav' => 'proveedores', 'p' => $p,
            'datos' => $datos ?? ($p ?? ['iva' => 1, 'codigo' => $svc->siguienteCodigo()]), 'errores' => $errores, 'centros' => $cs->centros(true), 'conceptos' => $cs->conceptos(true),
            'saldo' => $p !== null ? $svc->saldo($id) : 0.0, 'compras' => $compras, 'puedeCargar' => Auth::puede('compras.cargar'),
            'volver' => (string) ($req->query['volver'] ?? ($req->post['volver'] ?? ''))]), $status);
    }

    public static function proveedorGuardar(Request $req, array $params): Response
    {
        $id = (int) ($req->post['id'] ?? 0);
        $r = (new ProveedoresService())->guardar($id > 0 ? $id : null, $req->post);
        if (!$r['ok']) {
            return self::proveedorFicha($req, $params, $req->post, $r['errores'], 422);
        }
        Session::flash('ok', $id > 0 ? 'Proveedor guardado.' : 'Proveedor dado de alta.');
        if (($req->post['volver'] ?? '') === 'nueva') {
            return Response::redirect(url('/compras/nueva?proveedor=' . $r['id']));
        }
        return Response::redirect(url('/compras/proveedores/ficha?id=' . $r['id']));
    }

    /** Buscador y lupa de proveedores: mismo formato que el de clientes (cliente-picker.js y VentanaLista). */
    public static function buscarProveedor(Request $req, array $params): Response
    {
        $svc = new ProveedoresService();
        $q = trim((string) ($req->query['q'] ?? ''));
        if (isset($req->query['listar'])) {
            $pagina = max(1, (int) ($req->query['pagina'] ?? 1));
            $r = $svc->listar(['q' => $q, 'estado' => 'activos'], $pagina, 20);
            return Response::json(['items' => $svc->paraElegir($r['filas']), 'hay_mas' => $pagina * 20 < $r['total']]);
        }
        $filas = $q === '' ? [] : $svc->listar(['q' => $q, 'estado' => 'activos'], 1, 15)['filas'];
        return Response::json($svc->paraElegir($filas));
    }

    // ------------------------------------------------------------------ centros, presupuesto, conceptos y plantillas

    public static function centros(Request $req, array $params, ?string $error = null, int $status = 200): Response
    {
        $cs = new CentrosService();
        $periodo = self::periodo($req);
        return Response::html(View::render('compras/centros', ['title' => 'Centros de costos y conceptos', 'nav' => 'compras_centros', 'periodo' => $periodo,
            'centros' => $cs->centros(), 'pr' => ComprasListados::presupuestoReal($periodo), 'conceptos' => $cs->conceptos(), 'plantillas' => $cs->plantillas(),
            'error' => $error]), $status);
    }

    private static function volverCentros(Request $req, string $ok, string $ancla = ''): Response
    {
        Session::flash('ok', $ok);
        $p = (string) ($req->post['periodo'] ?? '');
        return Response::redirect(url('/compras/centros' . (CentrosService::periodoValido($p) ? '?periodo=' . $p : '') . ($ancla !== '' ? '#' . $ancla : '')));
    }

    public static function centroGuardar(Request $req, array $params): Response
    {
        $p = $req->post;
        $id = (int) ($p['id'] ?? 0);
        $r = (new CentrosService())->guardarCentro($id > 0 ? $id : null, (string) ($p['nombre'] ?? ''), (int) ($p['padre_id'] ?? 0) ?: null, ($p['activo'] ?? '1') !== '0');
        return $r['ok'] ? self::volverCentros($req, $id > 0 ? 'Centro guardado.' : 'Centro agregado.', 'centros') : self::centros($req, $params, $r['error'], 422);
    }

    public static function presupuestoGuardar(Request $req, array $params): Response
    {
        $r = (new CentrosService())->guardarPresupuesto((string) ($req->post['periodo'] ?? ''), is_array($req->post['importe'] ?? null) ? $req->post['importe'] : []);
        return $r['ok'] ? self::volverCentros($req, 'Presupuesto guardado.', 'presupuesto') : self::centros($req, $params, $r['error'], 422);
    }

    public static function presupuestoCopiar(Request $req, array $params): Response
    {
        $n = (new CentrosService())->copiarPresupuestoAnterior((string) ($req->post['periodo'] ?? ''));
        return self::volverCentros($req, $n > 0 ? "Se copió el presupuesto del mes anterior ({$n} centros)." : 'No había nada para copiar del mes anterior.', 'presupuesto');
    }

    public static function conceptoGuardar(Request $req, array $params): Response
    {
        $p = $req->post;
        $id = (int) ($p['id'] ?? 0);
        $r = (new CentrosService())->guardarConcepto($id > 0 ? $id : null, (string) ($p['nombre'] ?? ''), ($p['activo'] ?? '1') !== '0');
        return $r['ok'] ? self::volverCentros($req, $id > 0 ? 'Concepto guardado.' : 'Concepto agregado.', 'conceptos') : self::centros($req, $params, $r['error'], 422);
    }

    public static function plantillaGuardar(Request $req, array $params): Response
    {
        $r = (new CentrosService())->guardarPlantilla((string) ($req->post['nombre'] ?? ''), is_array($req->post['rep'] ?? null) ? $req->post['rep'] : []);
        return $r['ok'] ? self::volverCentros($req, 'Plantilla guardada.', 'plantillas') : self::centros($req, $params, $r['error'], 422);
    }

    public static function plantillaBorrar(Request $req, array $params): Response
    {
        (new CentrosService())->borrarPlantilla((int) ($req->post['id'] ?? 0));
        return self::volverCentros($req, 'Plantilla borrada.', 'plantillas');
    }
}
