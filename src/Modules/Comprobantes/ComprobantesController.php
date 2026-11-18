<?php
declare(strict_types=1);

namespace App\Modules\Comprobantes;

use App\Core\Db;
use App\Core\Session;
use App\Core\Valida;
use App\Core\View;
use App\Http\Request;
use App\Http\Response;
use App\Modules\Arca\Arca;
use App\Modules\Arca\ArcaSinRespuesta;
use App\Modules\Arca\AutorizacionRechazada;
use App\Modules\Arca\GrabacionFallida;
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
        $f = array_map(static fn ($x) => is_string($x) ? $x : '', array_intersect_key($req->query, array_flip(['desde', 'hasta', 'tipo', 'cliente', 'numero', 'estado', 'orden'])));
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
            'h' => $h, 'emisor' => $svc->emisor(), 'porAcreditar' => Tipos::credito($h['tipo']) ? 0.0 : $svc->porAcreditar($h),
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
        $datos = ['tipo' => $tipo, 'forma_pago' => '2', 'items' => [], 'concepto' => (string) \App\Modules\Configuracion\Configuracion::entero('comprobantes.concepto_defecto', 1)];
        $desde = (int) ($req->query['desde'] ?? 0);
        if ($desde > 0 && in_array($tipo, ['NC', 'ND', 'NX'], true)) {
            $origen = $svc->obtener($desde);
            if ($origen === null || !in_array($origen['tipo'], $tipo === 'NX' ? ['PR'] : ['FC', 'ND'], true) || $origen['estado'] !== '') {
                return new Response(404);
            }
            $porCodigo = [];
            foreach ($svc->alicuotas() as $cod => $pct) {
                $porCodigo[(string) $pct] = $cod;
            }
            $items = [];
            foreach ($origen['items'] as $it) {
                $items[] = ['articulo' => (string) $it['articulo'], 'descripcion' => (string) $it['observaciones'], 'texto' => (string) ($it['texto_impresion'] ?? ''), 'cantidad' => numero($it['unidades'], 3),
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
        return self::pantallaRevisar($r['cbte'], $datos);
    }

    /** Campos de «cómo se paga»: se piden en la pantalla de revisión (con el total a la vista) y se suman a lo cargado antes de emitir. */
    private const CAMPOS_PAGO = ['forma_pago', 'fpago', 'pagos', 'pago_nro', 'pago_banco', 'pago_vto', 'recibido', 'excede_ok'];

    /**
     * Pantalla de revisión: el comprobante calculado y, si se cobra (factura, nota de débito, presupuesto), cómo se paga.
     * @param array<string,mixed> $cbte el 'cbte' de armar() @param array<string,mixed> $datos lo cargado en el formulario (sin el pago)
     * @param array<string,string> $errores errores del pago @param array<string,mixed> $pagoCargado lo que se había elegido de pago
     */
    private static function pantallaRevisar(array $cbte, array $datos, array $errores = [], array $pagoCargado = [], int $status = 200): Response
    {
        $pdo = Db::pdo();
        $pagar = !Tipos::credito($cbte['tipo']) && empty($cbte['solo_subdiario']);
        if ($pagar) { // el pago se elige acá: no viaja en lo cargado (las notas de crédito sí conservan su elección)
            $datos = array_diff_key($datos, array_flip(self::CAMPOS_PAGO));
        }
        return Response::html(View::render('comprobantes/revisar', [
            'title' => ($cbte['presupuesto'] ?? null) ? 'Facturar presupuesto' : 'Revisar ' . Tipos::titulo($cbte['tipo'], $cbte['letra']), 'nav' => 'comprobantes_' . strtolower($cbte['tipo']),
            'c' => $cbte, 'etiquetaArca' => Arca::etiqueta(), 'datos' => json_encode($datos, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 'operacion' => bin2hex(random_bytes(16)),
            'errores' => $errores, 'pagoCargado' => $pagoCargado, 'puedeCredito' => Auth::puede('comprobantes.credito'),
            'pagar' => $pagar,
            'formas' => $pdo->query('SELECT codigo, descripcion, tipo, acepta_vuelto FROM forma_de_pagos WHERE tipo IN (1, 2, 3, 5, 6, 10) ORDER BY tipo, descripcion')->fetchAll(),
            'bancos' => $pdo->query('SELECT codigo, descripcion FROM bancos ORDER BY descripcion')->fetchAll(),
        ]), $status);
    }

    public static function emitir(Request $req, array $params): Response
    {
        $svc = new ComprobantesService();
        $datos = self::datosDelPedido($req);
        if ($datos === null) {
            Session::flash('error', 'No se pudo leer el comprobante. Volvé a cargarlo.');
            return Response::redirect(url('/comprobantes/nuevo'));
        }
        $pagoCargado = array_intersect_key($req->post, array_flip(self::CAMPOS_PAGO));
        $datos = array_merge($datos, $pagoCargado);
        $r = $svc->armar($datos, Auth::puede('comprobantes.credito'));
        if (!$r['ok']) {
            // Si solo falla lo del pago, se vuelve a la revisión (con el total y lo ya elegido) en vez de al formulario
            $soloPago = ['forma_pago', 'fpago', 'pagos', 'pago_nro', 'pago_banco', 'pago_vto', 'recibido', 'credito'];
            if ($pagoCargado !== [] && array_diff(array_keys($r['errores']), $soloPago) === []) {
                $base = $svc->armar(array_diff_key($datos, array_flip(self::CAMPOS_PAGO)), Auth::puede('comprobantes.credito'));
                if ($base['ok']) {
                    return self::pantallaRevisar($base['cbte'], $datos, $r['errores'], $pagoCargado, 422);
                }
            }
            return self::form($svc, $datos, $r['errores'], 422);
        }
        try {
            $e = $svc->emitir($r['cbte'], (string) ($req->post['operacion'] ?? ''), (int) Auth::id());
        } catch (\App\Modules\Licencia\LicenciaVencida $ex) {
            return self::form($svc, $datos, ['licencia' => $ex->getMessage()], 403);
        } catch (\InvalidArgumentException) {
            Session::flash('error', 'La pantalla de revisión venció. Revisá el comprobante de nuevo.');
            return Response::redirect(url('/comprobantes/nuevo'));
        } catch (PresupuestoYaFacturado $ex) {
            Session::flash('error', $ex->getMessage());
            return Response::redirect(url('/comprobantes/' . (int) ($r['cbte']['presupuesto'] ?? 0)));
        } catch (AutorizacionRechazada $ex) { // ARCA dijo que no: no se grabó nada, se vuelve al formulario con el motivo
            return self::form($svc, $datos, ['arca' => $ex->getMessage()], 422);
        } catch (ArcaSinRespuesta $ex) {
            return self::form($svc, $datos, ['arca' => $ex->getMessage()], 502);
        } catch (GrabacionFallida $ex) {
            Session::flash('error', $ex->getMessage());
            return Response::redirect(url(Auth::puede('arca.administrar') ? '/arca' : '/comprobantes'));
        }
        Session::flash($e['repetido'] ? 'info' : 'ok', $e['repetido']
            ? 'Ese comprobante ya se había emitido (no se emitió otro).'
            : Tipos::titulo($r['cbte']['tipo'], $r['cbte']['letra']) . ' ' . numero_cbte($r['cbte']['prefijo'], $e['numero']) . ($r['cbte']['tipo'] === 'PR' ? ' emitido.' : ' emitida.')
                . (($e['vuelto'] ?? 0) > 0 ? ' DAR VUELTO AL CLIENTE: ' . plata_dec($e['vuelto']) . '.' : ''));
        return Response::redirect(url('/comprobantes/' . $e['id']));
    }

    /** Factura que sale de un presupuesto: se arma con sus datos y se pasa directo a revisar (no se edita). Solo una vez por presupuesto. */
    public static function facturarPresupuesto(Request $req, array $params): Response
    {
        $svc = new ComprobantesService();
        $id = (int) $params['id'];
        $p = $svc->presupuestoParaFacturar($id);
        if (is_string($p)) {
            Session::flash('error', $p);
            return Response::redirect(url('/comprobantes/' . $id));
        }
        $datos = $svc->entradaDesdePresupuesto($p);
        $r = $svc->armar($datos, Auth::puede('comprobantes.credito'));
        if (!$r['ok']) {
            Session::flash('error', implode(' ', $r['errores']));
            return Response::redirect(url('/comprobantes/' . $id));
        }
        return self::pantallaRevisar($r['cbte'], $datos);
    }

    // ------------------------------------------------------------------ buscadores (JSON)

    public static function buscarCliente(Request $req, array $params): Response
    {
        $q = trim((string) ($req->query['q'] ?? ''));
        if (isset($req->query['listar'])) {
            // Ventana «ver lista»: clientes activos por razón social, de a 20, con filtro opcional (nombre, código o CUIT).
            $pagina = max(1, (int) ($req->query['pagina'] ?? 1));
            $r = (new ClientesService())->listar(['q' => $q, 'estado' => 'activos'], $pagina, 20);
            return Response::json(['items' => self::clientesParaElegir($r['filas']), 'hay_mas' => $pagina * 20 < (int) $r['total']]);
        }
        if (ctype_digit($q) && isset($req->query['exacto'])) {
            $c = (new ClientesService())->obtener((int) $q);
            $filas = $c !== null && (int) $c['activo'] === 1 ? [$c] : [];
        } else {
            $filas = $q === '' ? [] : (new ClientesService())->listar(['q' => $q, 'estado' => 'activos'], 1, 15)['filas'];
        }
        return Response::json(self::clientesParaElegir($filas));
    }

    /** @param array<int,array<string,mixed>> $filas @return array<int,array<string,mixed>> */
    private static function clientesParaElegir(array $filas): array
    {
        $svc = new ComprobantesService();
        $emisor = $svc->emisor();
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
        return $out;
    }

    /** Buscador de artículos del renglón: ver Articulos\BuscadorArticulos (lo usan también las pantallas de stock). */
    public static function buscarArticulo(Request $req, array $params): Response
    {
        if (isset($req->query['listar'])) {
            return Response::json(\App\Modules\Articulos\BuscadorArticulos::listar(trim((string) ($req->query['q'] ?? '')), (int) ($req->query['pagina'] ?? 1)));
        }
        return Response::json(\App\Modules\Articulos\BuscadorArticulos::buscar(trim((string) ($req->query['q'] ?? '')), isset($req->query['exacto'])));
    }

    public static function asociables(Request $req, array $params): Response
    {
        $out = [];
        foreach ((new ComprobantesService())->asociables((int) ($req->query['cliente'] ?? 0), strtoupper((string) ($req->query['tipo'] ?? 'NC'))) as $a) {
            $out[] = ['id' => (int) $a['fe_clave'], 'letra' => $a['letra'], 'sin_iva' => (int) $a['sin_iva'], 'texto' => Tipos::titulo($a['tipo'], $a['letra']) . ' ' . numero_visible($a)
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
            if ($cliente !== null && in_array($tipo, ['NC', 'ND', 'NX'], true)) {
                $asociables = $svc->asociables((int) $cliente['codigo'], $tipo);
            }
        }
        $emisor = $svc->emisor();
        // Presupuesto sin IVA: la marca de Configuración (para la contrapartida, la del presupuesto elegido)
        $sinIva = $tipo === 'PR' && Configuracion::activo('presupuestos.sin_iva');
        foreach ($asociables as $a) {
            if ($tipo === 'NX' && (string) $a['fe_clave'] === (string) ($datos['asociado'] ?? '') && (int) $a['sin_iva'] === 1) {
                $sinIva = true;
            }
        }
        // Letra prevista: la de la factura que se corrige, o la que sale de la condición del emisor y del cliente
        $letra = '';
        if (Tipos::interno($tipo)) {
            $letra = Tipos::LETRA_INTERNA;
        } elseif ($tipo !== 'FC') {
            foreach ($asociables as $a) {
                if ((string) $a['fe_clave'] === (string) ($datos['asociado'] ?? '')) {
                    $letra = (string) $a['letra'];
                }
            }
        } elseif ($cliente !== null && $emisor['condicion'] !== null) {
            $cond = $svc->condicionArca((int) $cliente['iva']);
            $letra = $cond !== null ? Tipos::letra((string) $emisor['condicion'], $cond) : '';
        }
        return Response::html(View::render('comprobantes/form', [
            'title' => Tipos::NOMBRES[$tipo] ?? 'Comprobante', 'nav' => 'comprobantes_' . strtolower($tipo),
            'tipo' => $tipo, 'datos' => $datos, 'errores' => $errores, 'cliente' => $cliente, 'asociables' => $asociables, 'sinIva' => $sinIva,
            'sucursales' => $svc->sucursales(), 'alicuotas' => $pdo->query('SELECT codigo, descripcion, porcentaje FROM tasas_iva ORDER BY porcentaje DESC')->fetchAll(),
            'vendedores' => $pdo->query('SELECT codigo, descripcion FROM vendedores ORDER BY descripcion')->fetchAll(),
            'condiciones' => $pdo->query('SELECT codigo, descripcion FROM condicionpago ORDER BY descripcion')->fetchAll(),
            'emisor' => $emisor, 'letra' => $letra, 'modoArca' => Arca::modo(), 'etiquetaArca' => Arca::etiqueta(),
            'electronicas' => array_filter($svc->sucursales(), static fn ($s) => Arca::electronica($s)),
            'conceptoDefecto' => \App\Modules\Configuracion\Configuracion::entero('comprobantes.concepto_defecto', 1), 'puedeCredito' => Auth::puede('comprobantes.credito'),
            'deuda' => $cliente !== null ? $svc->deudaCliente((int) $cliente['codigo']) : 0.0,
        ]), $status);
    }
}
