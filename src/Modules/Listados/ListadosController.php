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
        $f = array_map(static fn ($x) => is_string($x) ? $x : '', array_intersect_key($req->query, array_flip(['desde', 'hasta', 'pv', 'tipo', 'pruebas'])));
        if (!array_key_exists('desde', $req->query)) { // por omisión, el mes en curso
            $f['desde'] = date('01/m/Y');
            $f['hasta'] = date('t/m/Y');
        }
        return $f;
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
}
