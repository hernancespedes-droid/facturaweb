<?php
declare(strict_types=1);

namespace App\Modules\Comprobantes;

use App\Core\Db;
use App\Core\Session;
use App\Core\Valida;
use App\Core\View;
use App\Http\Request;
use App\Http\Response;
use App\Modules\Auth\Auth;
use App\Modules\Clientes\ClientesService;
use App\Modules\Configuracion\Configuracion;

/**
 * Pantallas de facturación. Consulta e impresión: comprobantes.ver. Emitir y los buscadores: comprobantes.emitir
 * (verificado en routes.php y de nuevo acá donde hace falta).
 *
 * Flujo: formulario → "Revisar" (se valida y calcula todo en el servidor, todavía no se graba nada) → "Emitir". El
 * comprobante revisado viaja como JSON en un campo oculto junto con una clave de operación: si llega dos veces (doble
 * clic, volver atrás) se muestra el que ya se emitió.
 */
final class ComprobantesController
{
    // ------------------------------------------------------------------ consulta

    public static function index(Request $req, array $params): Response
    {
        $f = array_map(static fn ($x) => is_string($x) ? $x : '', array_intersect_key($req->query, array_flip(['desde', 'hasta', 'tipo', 'cliente', 'numero', 'estado'])));
        if (!array_key_exists('desde', $req->query)) {
            $f['desde'] = date('01/m/Y'); // por omisión, lo del mes
        }
        $pagina = max(1, (int) ($req->query['pagina'] ?? 1));
        $por = Configuracion::porPagina();
        $r = (new ComprobantesService())->listar($f, $pagina, $por);
        return Response::html(View::render('comprobantes/index', [
            'title' => 'Comprobantes', 'nav' => 'comprobantes', 'f' => $f, 'filas' => $r['filas'], 'total' => $r['total'], 'suma' => $r['suma'],
            'pagina' => $pagina, 'porPagina' => $por,
        ]));
    }

    public static function ver(Request $req, array $params): Response
    {
        $svc = new ComprobantesService();
        $h = $svc->obtener((int) $params['id']);
        if ($h === null) {
            return new Response(404);
        }
        return Response::html(View::render('comprobantes/ver', [
            'title' => Tipos::titulo($h['tipo'], $h['letra']) . ' ' . numero_visible($h), 'nav' => 'comprobantes',
            'h' => $h, 'emisor' => $svc->emisor(), 'porAcreditar' => $h['tipo'] === 'NC' ? 0.0 : $svc->porAcreditar($h),
            'puedeEmitir' => Auth::puede('comprobantes.emitir'),
        ]));
    }

    // ------------------------------------------------------------------ emisión

    public static function nuevo(Request $req, array $params): Response
    {
        $svc = new ComprobantesService();
        $tipo = strtoupper((string) ($req->query['tipo'] ?? 'FC'));
        if (!Tipos::valido($tipo)) {
            return new Response(404);
        }
        $datos = ['tipo' => $tipo, 'forma_pago' => '2', 'items' => []];
        $desde = (int) ($req->query['desde'] ?? 0);
        if ($desde > 0 && $tipo !== 'FC') {
            $origen = $svc->obtener($desde);
            if ($origen === null || !in_array($origen['tipo'], ['FC', 'ND'], true) || $origen['estado'] !== '') {
                return new Response(404);
            }
            $porCodigo = [];
            foreach ($svc->alicuotas() as $cod => $pct) {
                $porCodigo[(string) $pct] = $cod;
            }
            $items = [];
            foreach ($origen['items'] as $it) {
                $items[] = ['articulo' => (string) $it['articulo'], 'descripcion' => (string) $it['observaciones'], 'cantidad' => numero($it['unidades'], 3),
                    'precio' => numero($it['precio'], 4), 'alicuota' => $porCodigo[(string) (float) $it['por_iva']] ?? ''];
            }
            $datos = ['tipo' => $tipo, 'cliente' => (string) $origen['cliente'], 'asociado' => (string) $origen['fe_clave'], 'forma_pago' => (string) $origen['forma_pago'],
                'descuento' => (float) $origen['porcentaje_descuento'] > 0 ? numero($origen['porcentaje_descuento']) : '', 'vendedor' => (string) $origen['vendedor'],
                'cond_pago' => (string) $origen['cond_pago'], 'email' => (string) $origen['fe_cemailenvio'], 'sucursal' => (string) $origen['sucursal'], 'items' => $items,
                'observaciones' => ''];
        } elseif (ctype_digit((string) ($req->query['cliente'] ?? ''))) {
            $datos['cliente'] = (string) $req->query['cliente'];
        }
        return self::form($svc, $datos, []);
    }

    /** Vuelve del "Revisar" al formulario con lo que se había cargado. */
    public static function editar(Request $req, array $params): Response
    {
        $datos = self::datosDelPedido($req);
        return self::form(new ComprobantesService(), $datos ?? ['tipo' => 'FC', 'items' => []], []);
    }

    public static function revisar(Request $req, array $params): Response
    {
        $svc = new ComprobantesService();
        $datos = self::formulario($req->post);
        $r = $svc->armar($datos, Auth::puede('comprobantes.credito'));
        if (!$r['ok']) {
            return self::form($svc, $datos, $r['errores'], 422);
        }
        return Response::html(View::render('comprobantes/revisar', [
            'title' => 'Revisar ' . Tipos::titulo($r['cbte']['tipo'], $r['cbte']['letra']), 'nav' => 'comprobantes_' . strtolower($r['cbte']['tipo']),
            'c' => $r['cbte'], 'datos' => json_encode($datos, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 'operacion' => bin2hex(random_bytes(16)),
        ]));
    }

    public static function emitir(Request $req, array $params): Response
    {
        $svc = new ComprobantesService();
        $datos = self::datosDelPedido($req);
        if ($datos === null) {
            Session::flash('error', 'No se pudo leer el comprobante. Volvé a cargarlo.');
            return Response::redirect(url('/comprobantes/nuevo'));
        }
        $r = $svc->armar($datos, Auth::puede('comprobantes.credito'));
        if (!$r['ok']) {
            return self::form($svc, $datos, $r['errores'], 422);
        }
        try {
            $e = $svc->emitir($r['cbte'], (string) ($req->post['operacion'] ?? ''), (int) Auth::id());
        } catch (\InvalidArgumentException) {
            Session::flash('error', 'La pantalla de revisión venció. Revisá el comprobante de nuevo.');
            return Response::redirect(url('/comprobantes/nuevo'));
        }
        Session::flash($e['repetido'] ? 'info' : 'ok', $e['repetido']
            ? 'Ese comprobante ya se había emitido (no se emitió otro).'
            : Tipos::titulo($r['cbte']['tipo'], $r['cbte']['letra']) . ' ' . numero_cbte($r['cbte']['prefijo'], $e['numero']) . ' emitida.');
        return Response::redirect(url('/comprobantes/' . $e['id']));
    }

    // ------------------------------------------------------------------ buscadores (JSON)

    public static function buscarCliente(Request $req, array $params): Response
    {
        $svc = new ComprobantesService();
        $emisor = $svc->emisor();
        $q = trim((string) ($req->query['q'] ?? ''));
        if (ctype_digit($q) && isset($req->query['exacto'])) {
            $lista = (new ClientesService())->obtener((int) $q);
            $filas = $lista !== null && (int) $lista['activo'] === 1 ? [$lista] : [];
        } else {
            $filas = $q === '' ? [] : (new ClientesService())->listar(['q' => $q, 'estado' => 'activos'], 1, 15)['filas'];
        }
        $out = [];
        foreach ($filas as $f) {
            $c = (new ClientesService())->obtener((int) $f['codigo']);
            $cond = $svc->condicionArca((int) $c['iva']);
            $letra = $emisor['condicion'] !== null && $cond !== null ? Tipos::letra((string) $emisor['condicion'], $cond) : '';
            $out[] = [
                'codigo' => (int) $c['codigo'], 'razon_social' => $c['razon_social'], 'cuit' => cuit_texto($c['cuit']), 'localidad' => (string) $c['localidad'],
                'iva_texto' => ClientesService::CONDICIONES_IVA[(int) $c['iva']] ?? '', 'letra' => $letra, 'forma_pago' => (int) $c['forma_pago'],
                'vendedor' => (string) $c['vendedor'], 'cond_pago' => (string) $c['condicionpago'], 'descuento' => numero($c['descuento']),
                'email' => (string) $c['emailfc'], 'credito' => (float) $c['credito'], 'deuda' => $svc->deudaCliente((int) $c['codigo']),
                'equivalencia' => $cond !== null,
            ];
        }
        return Response::json($out);
    }

    public static function buscarArticulo(Request $req, array $params): Response
    {
        $svc = new ComprobantesService();
        $q = trim((string) ($req->query['q'] ?? ''));
        $pdo = Db::pdo();
        if (isset($req->query['exacto'])) {
            $st = $pdo->prepare("SELECT * FROM articulos WHERE codigo = ? AND estado = 'AC'");
            $st->execute([mb_strtoupper($q)]);
            $filas = $st->fetchAll();
        } elseif ($q === '') {
            $filas = [];
        } else {
            $like = '%' . addcslashes($q, '%_\\') . '%';
            $st = $pdo->prepare("SELECT * FROM articulos WHERE estado = 'AC' AND (codigo LIKE ? OR descripcion LIKE ? OR codigo_de_barras = ?) ORDER BY descripcion LIMIT 15");
            $st->execute([$like, $like, $q]);
            $filas = $st->fetchAll();
        }
        $cot = $svc->cotizacionDolar(date('Y-m-d'));
        $out = [];
        foreach ($filas as $a) {
            $precio = (float) $a['precio_unitario'] > 0 ? (float) $a['precio_unitario'] : (float) $a['precio_de_venta_1'];
            $dolares = $a['moneda'] === 'u$s';
            $out[] = ['codigo' => $a['codigo'], 'descripcion' => $a['descripcion'], 'alicuota' => (string) $a['alicuota'],
                'precio' => numero($dolares ? $precio * $cot : $precio, 4), 'moneda' => $a['moneda'], 'cotizacion' => $dolares ? $cot : null];
        }
        return Response::json($out);
    }

    public static function asociables(Request $req, array $params): Response
    {
        $out = [];
        foreach ((new ComprobantesService())->asociables((int) ($req->query['cliente'] ?? 0)) as $a) {
            $out[] = ['id' => (int) $a['fe_clave'], 'letra' => $a['letra'], 'texto' => Tipos::titulo($a['tipo'], $a['letra']) . ' ' . numero_visible($a)
                . ' del ' . fecha($a['fecha']) . ' · ' . plata($a['neto'])];
        }
        return Response::json($out);
    }

    // ------------------------------------------------------------------ auxiliares

    /** Lo del formulario, sin el token ni la clave de la operación. @return array<string,mixed> */
    private static function formulario(array $post): array
    {
        unset($post['_csrf'], $post['operacion'], $post['datos']);
        return $post;
    }

    /** El comprobante revisado (JSON del campo oculto), o null si no se puede leer. @return array<string,mixed>|null */
    private static function datosDelPedido(Request $req): ?array
    {
        $d = json_decode((string) ($req->post['datos'] ?? ''), true);
        return is_array($d) ? $d : null;
    }

    /** @param array<string,mixed> $datos @param array<string,string> $errores */
    private static function form(ComprobantesService $svc, array $datos, array $errores, int $status = 200): Response
    {
        $tipo = strtoupper((string) ($datos['tipo'] ?? 'FC'));
        $pdo = Db::pdo();
        $cliente = null;
        $asociables = [];
        if (ctype_digit((string) ($datos['cliente'] ?? '')) && (int) $datos['cliente'] > 0) {
            $cliente = (new ClientesService())->obtener((int) $datos['cliente']);
            if ($cliente !== null && $tipo !== 'FC') {
                $asociables = $svc->asociables((int) $cliente['codigo']);
            }
        }
        $emisor = $svc->emisor();
        // Letra prevista: la de la factura que se corrige, o la que sale de la condición del emisor y del cliente
        $letra = '';
        if ($tipo !== 'FC') {
            foreach ($asociables as $a) {
                if ((string) $a['fe_clave'] === (string) ($datos['asociado'] ?? '')) {
                    $letra = (string) $a['letra'];
                }
            }
        } elseif ($cliente !== null && $emisor['condicion'] !== null) {
            $cond = $svc->condicionArca((int) $cliente['iva']);
            $letra = $cond !== null ? Tipos::letra((string) $emisor['condicion'], $cond) : '';
        }
        $formas = $pdo->query('SELECT codigo, descripcion, tipo FROM forma_de_pagos WHERE tipo IN (1, 2, 3, 5) ORDER BY tipo, descripcion')->fetchAll();
        return Response::html(View::render('comprobantes/form', [
            'title' => Tipos::NOMBRES[$tipo] ?? 'Comprobante', 'nav' => 'comprobantes_' . strtolower($tipo),
            'tipo' => $tipo, 'datos' => $datos, 'errores' => $errores, 'cliente' => $cliente, 'asociables' => $asociables,
            'sucursales' => $svc->sucursales(), 'alicuotas' => $pdo->query('SELECT codigo, descripcion, porcentaje FROM tasas_iva ORDER BY porcentaje DESC')->fetchAll(),
            'vendedores' => $pdo->query('SELECT codigo, descripcion FROM vendedores ORDER BY descripcion')->fetchAll(),
            'condiciones' => $pdo->query('SELECT codigo, descripcion FROM condicionpago ORDER BY descripcion')->fetchAll(),
            'formas' => $formas, 'bancos' => $pdo->query('SELECT codigo, descripcion FROM bancos ORDER BY descripcion')->fetchAll(),
            'emisor' => $emisor, 'letra' => $letra, 'puedeCredito' => Auth::puede('comprobantes.credito'),
            'deuda' => $cliente !== null ? $svc->deudaCliente((int) $cliente['codigo']) : 0.0,
        ]), $status);
    }
}
