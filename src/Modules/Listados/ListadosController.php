<?php
declare(strict_types=1);

namespace App\Modules\Listados;

use App\Core\ExcelListado;
use App\Core\View;
use App\Http\Request;
use App\Http\Response;
use App\Modules\Comprobantes\Tipos;
use App\Modules\Configuracion\Configuracion;

/** Listados (permiso listados.ver): subdiario de IVA ventas en pantalla y en Excel. */
final class ListadosController
{
    private static function filtros(Request $req): array
    {
        $f = array_map(static fn ($x) => is_string($x) ? $x : '', array_intersect_key($req->query, array_flip(['desde', 'hasta', 'pv', 'tipo', 'pruebas', 'orden'])));
        if (!array_key_exists('desde', $req->query)) { // por omisión, el mes en curso
            $f['desde'] = date('01/m/Y');
            $f['hasta'] = date('t/m/Y');
        }
        return $f;
    }

    /** Caja diaria para el dueño: lo vendido, lo facturado a cuenta corriente y lo cobrado por forma de pago (hoy por omisión). */
    public static function cajaDiaria(Request $req, array $params): Response
    {
        $f = array_map(static fn ($x) => is_string($x) ? $x : '', array_intersect_key($req->query, array_flip(['desde', 'hasta', 'orden'])));
        if (!array_key_exists('desde', $req->query)) {
            $f['desde'] = $f['hasta'] = date('d/m/Y');
        }
        $pagina = max(1, (int) ($req->query['pagina'] ?? 1));
        $por = Configuracion::porPagina();
        $m = CajaDiaria::movimientos($f, $pagina, $por);
        return Response::html(View::render('listados/caja_diaria', [
            'title' => 'Caja diaria', 'nav' => 'caja_diaria', 'f' => $f, 'r' => CajaDiaria::resumen($f), 'manuales' => CajaDiaria::manuales($f), 'aperturas' => CajaDiaria::aperturas($f), 'filas' => $m['filas'], 'total' => $m['total'],
            'pagina' => $pagina, 'porPagina' => $por,
        ]));
    }

    /** Reporte de caja para imprimir (A4): lo vendido, cómo se cobró, a dónde fue cada peso y el detalle (D-152). Mismo período que la caja diaria. */
    public static function cajaImprimir(Request $req, array $params): Response
    {
        $f = array_map(static fn ($x) => is_string($x) ? $x : '', array_intersect_key($req->query, array_flip(['desde', 'hasta'])));
        if (!array_key_exists('desde', $req->query)) {
            $f['desde'] = $f['hasta'] = date('d/m/Y');
        }
        return Response::html(View::render('listados/caja_imprimir', ['d' => ReporteCaja::datos($f), 'f' => $f], null));
    }

    /** Ventas diarias: presupuestos y facturas cobrados o a cuenta corriente, mezclados (por omisión, lo de hoy). Excel: el genérico de las grillas. */
    public static function ventasDiarias(Request $req, array $params): Response
    {
        $f = array_map(static fn ($x) => is_string($x) ? $x : '', array_intersect_key($req->query, array_flip(['desde', 'hasta', 'tipo', 'forma', 'cliente', 'orden'])));
        if (!array_key_exists('desde', $req->query)) {
            $f['desde'] = $f['hasta'] = date('d/m/Y');
        }
        $pagina = max(1, (int) ($req->query['pagina'] ?? 1));
        $por = Configuracion::porPagina();
        $r = VentasDiarias::listar($f, $pagina, $por);
        return Response::html(View::render('listados/ventas_diarias', [
            'title' => 'Ventas diarias', 'nav' => 'ventas_diarias', 'f' => $f, 'filas' => $r['filas'], 'total' => $r['total'],
            'totales' => $r['totales'], 'pagina' => $pagina, 'porPagina' => $por,
        ]));
    }

    public static function ivaVentas(Request $req, array $params): Response
    {
        $f = self::filtros($req);
        if (ExcelListado::pedido($req)) {
            return self::excel($f);
        }
        $pagina = max(1, (int) ($req->query['pagina'] ?? 1));
        $por = Configuracion::porPagina();
        $r = IvaVentas::listar($f, $pagina, $por);
        return Response::html(View::render('listados/iva_ventas', [
            'title' => 'Subdiario de IVA ventas', 'nav' => 'iva_ventas', 'f' => $f, 'filas' => $r['filas'], 'total' => $r['total'],
            'totales' => $r['totales'], 'pagina' => $pagina, 'porPagina' => $por, 'pvs' => IvaVentas::puntosVenta(), 'alicuotas' => IvaVentas::ALICUOTAS,
        ]));
    }

    /** Subdiario impreso para el libro contable (PDF apaisado, con folio y transporte). ?folio=N para seguir la numeración del libro. */
    public static function ivaVentasPdf(Request $req, array $params): Response
    {
        $f = self::filtros($req);
        $folio = max(1, min(99999, (int) ($req->query['folio'] ?? 1)));
        $pdf = SubdiarioPdf::generar(IvaVentas::libro($f), (new \App\Modules\Comprobantes\ComprobantesService())->emisor(), self::periodo($f), $folio);
        return new Response(200, $pdf, ['Content-Type' => 'application/pdf', 'Content-Disposition' => 'inline; filename="subdiario-iva-ventas.pdf"', 'Cache-Control' => 'no-store']);
    }

    /** "Noviembre 2026" si es un mes completo; si no, "01/11/2026 al 15/11/2026". */
    private static function periodo(array $f): string
    {
        $d = \App\Core\Valida::fecha((string) ($f['desde'] ?? ''));
        $h = \App\Core\Valida::fecha((string) ($f['hasta'] ?? ''));
        if ($d !== null && $h !== null && substr($d, 8) === '01' && $h === date('Y-m-t', (int) strtotime($d))) {
            $meses = ['Enero', 'Febrero', 'Marzo', 'Abril', 'Mayo', 'Junio', 'Julio', 'Agosto', 'Septiembre', 'Octubre', 'Noviembre', 'Diciembre'];
            return $meses[(int) substr($d, 5, 2) - 1] . ' ' . substr($d, 0, 4);
        }
        return ($d !== null ? date('d/m/Y', (int) strtotime($d)) : 'el inicio') . ' al ' . ($h !== null ? date('d/m/Y', (int) strtotime($h)) : date('d/m/Y'));
    }

    private static function excel(array $f): Response
    {
        $r = IvaVentas::listar($f, 1, 1, true);
        $n = static fn (string $k) => static fn (array $x) => ExcelListado::numero($x[$k]);
        $cols = [
            ['Fecha', 12, static fn (array $x) => date('d/m/Y', (int) strtotime($x['fecha']))],
            ['Comprobante', 24, static fn (array $x) => (Tipos::NOMBRES[$x['tipo']] ?? $x['tipo']) . ' ' . $x['letra'] . ' ' . sprintf('%04d-%08d', $x['prefijo'], $x['numero'])],
            ['Cliente', 36, static fn (array $x) => (string) $x['razon_social']],
            ['CUIT', 14, static fn (array $x) => (string) ($x['cuit_cliente'] ?? '')],
            ['Neto gravado', 15, $n('gravado')],
            ['Exento', 13, $n('exento')],
        ];
        foreach (IvaVentas::ALICUOTAS as $i => $a) {
            $cols[] = ['IVA ' . rtrim(rtrim(number_format($a, 2, ',', ''), '0'), ',') . ' %', 13, $n("iva_{$i}")];
        }
        $cols[] = ['IVA otras', 13, $n('iva_otras')];
        $cols[] = ['Total', 15, $n('total')];
        $cols[] = ['Prueba', 8, static fn (array $x) => (int) $x['es_prueba'] === 1 ? 'Sí' : ''];
        // Fila de totales al final
        $filas = $r['filas'];
        $t = $r['totales'];
        $filas[] = ['fecha' => '', 'tipo' => '', 'letra' => '', 'prefijo' => 0, 'numero' => 0, 'razon_social' => 'TOTALES', 'cuit_cliente' => '', 'es_prueba' => 0,
            'gravado' => $t['gravado'], 'exento' => $t['exento'], 'total' => $t['total'], 'iva_otras' => $t['iva_otras']]
            + array_combine(array_map(static fn ($i) => "iva_{$i}", array_keys(IvaVentas::ALICUOTAS)), array_map(static fn ($i) => $t["iva_{$i}"], array_keys(IvaVentas::ALICUOTAS)));
        $cols[0][2] = static fn (array $x) => $x['fecha'] === '' ? '' : date('d/m/Y', (int) strtotime($x['fecha']));
        $cols[1][2] = static fn (array $x) => $x['tipo'] === '' ? '' : (Tipos::NOMBRES[$x['tipo']] ?? $x['tipo']) . ' ' . $x['letra'] . ' ' . sprintf('%04d-%08d', $x['prefijo'], $x['numero']);
        return ExcelListado::respuesta('subdiario-iva-ventas', 'IVA ventas', $cols, $filas);
    }

    // ------------------------------------------------------------------ Libro IVA Digital

    public static function libroIva(Request $req, array $params): Response
    {
        $periodo = trim((string) ($req->query['periodo'] ?? date('Y-m')));
        $libro = LibroIvaDigital::generar($periodo);
        return Response::html(View::render('listados/libro_iva', ['title' => 'Libro IVA Digital (ventas)', 'nav' => 'libro_iva', 'periodo' => $periodo, 'libro' => $libro,
            'hayZip' => class_exists(\ZipArchive::class)]), $libro === null ? 422 : 200);
    }

    /** Descarga de un archivo del libro: ?periodo=AAAA-MM&archivo=cbte|alicuotas|zip */
    public static function libroIvaDescarga(Request $req, array $params): Response
    {
        $libro = LibroIvaDigital::generar(trim((string) ($req->query['periodo'] ?? '')));
        $archivo = (string) ($req->query['archivo'] ?? '');
        if ($libro === null || !in_array($archivo, ['cbte', 'alicuotas', 'zip'], true)) {
            return new Response(404);
        }
        $sufijo = '-' . str_replace('-', '', $libro['periodo']);
        if ($archivo === 'zip') {
            $bin = LibroIvaDigital::zip($libro);
            if ($bin === null) {
                return new Response(404);
            }
            return new Response(200, $bin, ['Content-Type' => 'application/zip', 'Content-Disposition' => 'attachment; filename="LIBRO_IVA_DIGITAL_VENTAS' . $sufijo . '.zip"', 'Cache-Control' => 'no-store']);
        }
        $nombre = $archivo === 'cbte' ? LibroIvaDigital::ARCHIVO_CBTE : LibroIvaDigital::ARCHIVO_ALICUOTAS;
        return new Response(200, $archivo === 'cbte' ? $libro['cbte'] : $libro['alicuotas'], [
            'Content-Type' => 'text/plain; charset=ISO-8859-1', 'Content-Disposition' => 'attachment; filename="' . $nombre . $sufijo . '.txt"', 'Cache-Control' => 'no-store',
        ]);
    }

    // ------------------------------------------------------------------ Estadística, rentabilidad y costos (0.32.0)

    /** @param string[] $claves @return array<string,string> */
    private static function tomar(Request $req, array $claves): array
    {
        return array_map(static fn ($x) => is_string($x) ? trim($x) : '', array_intersect_key($req->query, array_flip($claves)));
    }

    private const FILTROS_ESTADISTICA = ['desde', 'hasta', 'sucursal', 'tipo', 'cliente', 'cliente_nombre', 'articulo', 'rubro', 'subrubro', 'proveedor', 'vendedor', 'fiscales',
        'agrupar', 'primeros', 'por', 'detalle', 'orden'];

    /** Opciones de los filtros: rubros, subrubros, vendedores y sucursales. @return array<string,array<int,array<string,mixed>>> */
    public static function opciones(): array
    {
        $pdo = \App\Core\Db::pdo();
        return [
            'rubros' => $pdo->query('SELECT codigo, descripcion FROM rubros ORDER BY descripcion')->fetchAll(),
            'subrubros' => $pdo->query('SELECT rubro, codigo, descripcion FROM subrubros ORDER BY rubro, descripcion')->fetchAll(),
            'vendedores' => $pdo->query('SELECT codigo, descripcion FROM vendedores ORDER BY descripcion')->fetchAll(),
            'sucursales' => $pdo->query('SELECT codigo, descripcion FROM sucursales ORDER BY codigo')->fetchAll(),
        ];
    }

    private static function estadisticaVista(Request $req, bool $rentabilidad): Response
    {
        $f = self::tomar($req, self::FILTROS_ESTADISTICA);
        if (!array_key_exists('desde', $req->query)) { // por omisión, el mes en curso
            $f['desde'] = date('01/m/Y');
            $f['hasta'] = date('t/m/Y');
        }
        $detalle = ($f['detalle'] ?? '') === '1';
        $pagina = max(1, (int) ($req->query['pagina'] ?? 1));
        $por = Configuracion::porPagina();
        $datos = ['f' => $f, 'detalle' => $detalle, 'rentabilidad' => $rentabilidad, 'costos' => $rentabilidad || \App\Modules\Auth\Auth::puede('listados.costos'),
            'opciones' => self::opciones(), 'pagina' => $pagina, 'porPagina' => $por];
        $datos['r'] = Estadistica::resumen($f);
        if ($detalle) {
            $datos['d'] = Estadistica::detalle($f, $pagina, $por);
        }
        $titulo = $rentabilidad ? 'Rentabilidad de ventas' : 'Estadística de ventas';
        return Response::html(View::render('listados/estadistica', ['title' => $titulo, 'nav' => $rentabilidad ? 'rentabilidad' : 'estadistica'] + $datos));
    }

    public static function estadistica(Request $req, array $params): Response
    {
        return self::estadisticaVista($req, false);
    }

    /** Permiso listados.costos (lo controla la ruta). */
    public static function rentabilidad(Request $req, array $params): Response
    {
        return self::estadisticaVista($req, true);
    }

    /** Lista de costos y márgenes de los artículos (permiso listados.costos). */
    public static function costos(Request $req, array $params): Response
    {
        $f = self::tomar($req, ['q', 'rubro', 'subrubro', 'proveedor', 'moneda', 'bajas', 'ver', 'margen', 'precios', 'orden']);
        $pagina = max(1, (int) ($req->query['pagina'] ?? 1));
        $por = Configuracion::porPagina();
        $r = ListaCostos::listar($f, $pagina, $por);
        return Response::html(View::render('listados/costos', ['title' => 'Costos y márgenes', 'nav' => 'lista_costos', 'f' => $f, 'r' => $r, 'opciones' => self::opciones(),
            'pagina' => $pagina, 'porPagina' => $por]));
    }

    /** Buscadores de artículos y clientes de los listados (los mismos de siempre, con el permiso de los listados). */
    public static function buscarArticulo(Request $req, array $params): Response
    {
        return \App\Modules\Stock\StockController::buscarArticulo($req, $params);
    }

    public static function buscarCliente(Request $req, array $params): Response
    {
        return \App\Modules\Comprobantes\ComprobantesController::buscarCliente($req, $params);
    }
}
