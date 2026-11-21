<?php
declare(strict_types=1);

namespace App\Modules\Stock;

use App\Core\Session;
use App\Core\View;
use App\Http\Request;
use App\Http\Response;
use App\Modules\Auth\Auth;
use App\Modules\Configuracion\Configuracion;

final class StockController
{
    /** Informe «Ventas sin stock»: lo vendido sin que alcanzara el stock, quién lo vendió y cómo se autorizó. */
    public static function ventasSinStock(Request $req, array $params): Response
    {
        $f = array_map(static fn ($x) => is_string($x) ? $x : '', array_intersect_key($req->query, array_flip(['desde', 'hasta', 'modo', 'orden'])));
        $pagina = max(1, (int) ($req->query['pagina'] ?? 1));
        $por = Configuracion::porPagina();
        $r = VentasSinStock::listar($f, $pagina, $por);
        return Response::html(View::render('stock/ventas_sin_stock', ['title' => 'Ventas sin stock', 'nav' => 'stock_ventas_sin_stock', 'f' => $f, 'filas' => $r['filas'],
            'total' => $r['total'], 'unidades' => $r['unidades'], 'pagina' => $pagina, 'porPagina' => $por]));
    }

    /** Movimientos de stock (trazabilidad), en detalle o resumidos por artículo y depósito. */
    public static function movimientos(Request $req, array $params): Response
    {
        $f = array_map(static fn ($x) => is_string($x) ? trim($x) : '', array_intersect_key($req->query, array_flip(['desde', 'hasta', 'articulo', 'deposito', 'origen', 'usuario',
            'cliente', 'cliente_nombre', 'rubro', 'subrubro', 'resumido', 'orden'])));
        $per = MovimientosStock::periodo($f);
        $f['desde'] = date('d/m/Y', (int) strtotime($per['desde']));
        $f['hasta'] = date('d/m/Y', (int) strtotime($per['hasta']));
        $pagina = max(1, (int) ($req->query['pagina'] ?? 1));
        $por = Configuracion::porPagina();
        $resumido = ($f['resumido'] ?? '') === '1';
        $r = $resumido ? MovimientosStock::resumen($f, $pagina, $por) : MovimientosStock::listar($f, $pagina, $por);
        return Response::html(View::render('stock/movimientos', ['title' => 'Movimientos de stock', 'nav' => 'stock_movimientos', 'f' => $f, 'r' => $r, 'resumido' => $resumido,
            'depositos' => (new StockService())->depositos(), 'usuarios' => MovimientosStock::usuarios(), 'opciones' => \App\Modules\Listados\ListadosController::opciones(),
            'costos' => Auth::puede('listados.costos'), 'pagina' => $pagina, 'porPagina' => $por]));
    }

    /** Existencias por depósito a una fecha (valorizado con listados.costos). */
    public static function existencias(Request $req, array $params): Response
    {
        $f = array_map(static fn ($x) => is_string($x) ? trim($x) : '', array_intersect_key($req->query, array_flip(['fecha', 'q', 'rubro', 'subrubro', 'proveedor', 'deposito',
            'mostrar', 'valorizar', 'orden'])));
        if (!Auth::puede('listados.costos')) {
            unset($f['valorizar']);
        }
        $deps = (new StockService())->depositos();
        $pagina = max(1, (int) ($req->query['pagina'] ?? 1));
        $por = Configuracion::porPagina();
        $r = Existencias::listar($f, $deps, $pagina, $por);
        return Response::html(View::render('stock/existencias', ['title' => 'Existencias por depósito', 'nav' => 'stock_existencias', 'f' => $f, 'r' => $r, 'depositos' => $deps,
            'opciones' => \App\Modules\Listados\ListadosController::opciones(), 'costos' => Auth::puede('listados.costos'), 'pagina' => $pagina, 'porPagina' => $por]));
    }

    public static function index(Request $req, array $params): Response
    {
        $svc = new StockService();
        $f = array_map(static fn ($x) => is_string($x) ? $x : '', array_intersect_key($req->query, array_flip(['q', 'deposito', 'bajo', 'todos', 'orden'])));
        $pagina = max(1, (int) ($req->query['pagina'] ?? 1));
        $por = Configuracion::porPagina();
        $r = $svc->saldos($f, $pagina, $por);
        return Response::html(View::render('stock/index', ['title' => 'Saldos de stock', 'nav' => 'stock', 'f' => $f, 'filas' => $r['filas'], 'total' => $r['total'],
            'pagina' => $pagina, 'porPagina' => $por, 'depositos' => $svc->depositos(), 'activo' => StockService::activo(), 'puedeMover' => Auth::puede('stock.mover')]));
    }

    /** Buscador de artículos de las pantallas de stock (mismo buscador que el renglón de la factura). */
    public static function buscarArticulo(Request $req, array $params): Response
    {
        if (isset($req->query['listar'])) {
            return Response::json(\App\Modules\Articulos\BuscadorArticulos::listar(trim((string) ($req->query['q'] ?? '')), (int) ($req->query['pagina'] ?? 1)));
        }
        return Response::json(\App\Modules\Articulos\BuscadorArticulos::buscar(trim((string) ($req->query['q'] ?? '')), isset($req->query['exacto'])));
    }

    public static function kardex(Request $req, array $params): Response
    {
        $svc = new StockService();
        $art = $svc->resolverArticulo((string) $params['codigo']);
        if ($art === null) {
            return new Response(404);
        }
        $pagina = max(1, (int) ($req->query['pagina'] ?? 1));
        $por = Configuracion::porPagina();
        $orden = preg_replace('/[^a-z_]/', '', (string) ($req->query['orden'] ?? '')) ?? '';
        $r = $svc->kardex((string) $art['codigo'], $pagina, $por, $orden);
        return Response::html(View::render('stock/kardex', ['title' => 'Stock de ' . $art['codigo'], 'nav' => 'stock', 'art' => $art, 'filas' => $r['filas'], 'total' => $r['total'],
            'pagina' => $pagina, 'porPagina' => $por, 'orden' => $orden, 'porDeposito' => $svc->porDeposito((string) $art['codigo']), 'saldo' => $svc->saldo((string) $art['codigo'])]));
    }

    private static function formulario(string $vista, string $titulo, array $datos, ?string $error, int $status = 200): Response
    {
        $svc = new StockService();
        return Response::html(View::render($vista, ['title' => $titulo, 'nav' => $vista === 'stock/ajuste' ? 'stock_ajuste' : 'stock_transferir', 'datos' => $datos, 'error' => $error,
            'depositos' => $svc->depositos(), 'principal' => $svc->depositoPrincipal(), 'activo' => StockService::activo()]), $status);
    }

    public static function ajusteForm(Request $req, array $params): Response
    {
        return self::formulario('stock/ajuste', 'Ingreso, egreso o ajuste de stock', ['articulo' => (string) ($req->query['articulo'] ?? ''), 'modo' => 'INGRESO', 'unidad' => 'unidades'], null);
    }

    public static function ajuste(Request $req, array $params): Response
    {
        $p = $req->post;
        $datos = ['articulo' => trim((string) ($p['articulo'] ?? '')), 'modo' => (string) ($p['modo'] ?? ''), 'deposito' => (string) ($p['deposito'] ?? ''), 'cantidad' => (string) ($p['cantidad'] ?? ''),
            'unidad' => (string) ($p['unidad'] ?? 'unidades'), 'motivo' => trim((string) ($p['motivo'] ?? ''))];
        if (!in_array($datos['modo'], ['INGRESO', 'EGRESO', 'AJUSTE'], true)) {
            return self::formulario('stock/ajuste', 'Ingreso, egreso o ajuste de stock', $datos, 'Elegí qué querés hacer.', 422);
        }
        $r = (new StockService())->cargar($datos['modo'], $datos['articulo'], (int) $datos['deposito'], $datos['cantidad'], $datos['unidad'], $datos['motivo'], Auth::id());
        if (!$r['ok']) {
            return self::formulario('stock/ajuste', 'Ingreso, egreso o ajuste de stock', $datos, $r['error'], 422);
        }
        Session::flash('ok', 'Movimiento de stock registrado.');
        $svc = new StockService();
        $art = $svc->resolverArticulo($datos['articulo']);
        return Response::redirect(url('/stock/articulo/' . rawurlencode((string) $art['codigo'])));
    }

    public static function transferirForm(Request $req, array $params): Response
    {
        return self::formulario('stock/transferir', 'Transferencia entre depósitos', ['articulo' => (string) ($req->query['articulo'] ?? ''), 'unidad' => 'unidades'], null);
    }

    public static function transferir(Request $req, array $params): Response
    {
        $p = $req->post;
        $datos = ['articulo' => trim((string) ($p['articulo'] ?? '')), 'origen' => (string) ($p['origen'] ?? ''), 'destino' => (string) ($p['destino'] ?? ''), 'cantidad' => (string) ($p['cantidad'] ?? ''),
            'unidad' => (string) ($p['unidad'] ?? 'unidades'), 'motivo' => trim((string) ($p['motivo'] ?? ''))];
        $svc = new StockService();
        $r = $svc->transferir($datos['articulo'], (int) $datos['origen'], (int) $datos['destino'], $datos['cantidad'], $datos['unidad'], $datos['motivo'], Auth::id());
        if (!$r['ok']) {
            return self::formulario('stock/transferir', 'Transferencia entre depósitos', $datos, $r['error'], 422);
        }
        Session::flash('ok', 'Transferencia registrada.');
        $art = $svc->resolverArticulo($datos['articulo']);
        return Response::redirect(url('/stock/articulo/' . rawurlencode((string) $art['codigo'])));
    }

    public static function depositos(Request $req, array $params): Response
    {
        return Response::html(View::render('stock/depositos', ['title' => 'Depósitos', 'nav' => 'stock_depositos', 'depositos' => (new StockService())->depositos(false), 'error' => null]));
    }

    public static function guardarDeposito(Request $req, array $params): Response
    {
        $p = $req->post;
        $id = ctype_digit((string) ($p['id'] ?? '')) && (int) $p['id'] > 0 ? (int) $p['id'] : null;
        $svc = new StockService();
        $r = $svc->guardarDeposito($id, (string) ($p['codigo'] ?? ''), (string) ($p['nombre'] ?? ''), ($p['principal'] ?? '') === '1', ($p['activo'] ?? '1') === '1', ctype_digit((string) ($p['sucursal_vfp'] ?? '')) ? (int) $p['sucursal_vfp'] : 1);
        if (!$r['ok']) {
            return Response::html(View::render('stock/depositos', ['title' => 'Depósitos', 'nav' => 'stock_depositos', 'depositos' => $svc->depositos(false), 'error' => $r['error']]), 422);
        }
        Session::flash('ok', 'Depósito guardado.');
        return Response::redirect(url('/stock/depositos'));
    }

    public static function recalcular(Request $req, array $params): Response
    {
        $mal = (new StockService())->recalcular();
        Session::flash('ok', $mal === 0 ? 'Los saldos estaban bien: no hubo nada que corregir.' : "Se corrigieron {$mal} saldo(s).");
        return Response::redirect(url('/stock'));
    }
}
