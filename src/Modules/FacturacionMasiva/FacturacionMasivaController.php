<?php
declare(strict_types=1);

namespace App\Modules\FacturacionMasiva;

use App\Core\Db;
use App\Core\Session;
use App\Core\View;
use App\Http\Request;
use App\Http\Response;
use App\Modules\Arca\Arca;
use App\Modules\Auth\Auth;
use App\Modules\Clientes\ClientesService;
use App\Modules\Comprobantes\ComprobantesService;
use App\Modules\Configuracion\Configuracion;
use App\Modules\Importacion\Lector;

/**
 * Facturación → Facturación masiva (permiso comprobantes.masiva, verificado en routes.php).
 *  1) Se sube una planilla (.xlsx, .csv o .txt): cada factura queda como pre-factura pendiente (no toca stock, caja, cuenta corriente ni ARCA).
 *  2) En la grilla se eligen, se modifican (con el formulario de la factura) o se eliminan.
 *  3) «Aprobar y facturar»: la pantalla las manda de a una a /facturacion-masiva/procesar (y muestra el avance); sin JavaScript se procesan juntas.
 */
final class FacturacionMasivaController
{
    private const MAX_BYTES = 10 * 1024 * 1024;
    /** Segundos de facturación por pedido cuando se mandan varias juntas (sin JavaScript): lo que falta queda para el próximo clic. */
    private const SEGUNDOS_POR_PEDIDO = 40;

    /** @return array{credito:bool,stock:bool} lo que puede autorizar quien aprueba (D-156) */
    public static function permisos(): array
    {
        return ['credito' => Auth::puede('comprobantes.credito'), 'stock' => Auth::puede('stock.autorizar')];
    }

    public static function index(Request $req, array $params): Response
    {
        $f = ['estado' => (string) ($req->query['estado'] ?? ''), 'lote' => (string) ($req->query['lote'] ?? '')];
        $svc = new PreFacturasService();
        $comp = new ComprobantesService();
        return Response::html(View::render('masiva/index', [
            'title' => 'Facturación masiva', 'nav' => 'masiva', 'f' => $f, 'filas' => $svc->listar($f), 'lotes' => $svc->lotes(),
            'formas' => Planilla::formasDePago(), 'sucursales' => $comp->sucursales(), 'cobroDefecto' => 'CC',
            'etiquetaArca' => Arca::etiqueta(), 'mailConfigurado' => \App\Core\Mailer::configurado(),
        ]));
    }

    /** Planilla modelo con los títulos que se reconocen y dos facturas de ejemplo (una con dos renglones). */
    public static function plantilla(Request $req, array $params): Response
    {
        $campos = ['referencia', 'cliente', 'fecha', 'articulo', 'descripcion', 'cantidad', 'precio', 'alicuota', 'descuento', 'forma_pago', 'email', 'observaciones'];
        $x = new \App\Core\XlsxWriter('Facturas', array_map(static fn ($c) => Planilla::CAMPOS[$c][0], $campos), [14, 18, 12, 14, 34, 10, 12, 8, 12, 18, 26, 30]);
        $hoy = date('d/m/Y');
        foreach ([
            ['P-001', '30-71234567-8', $hoy, '', 'Abono mensual de mantenimiento', '1', '121000', '21', '', 'Cuenta corriente', '', 'Período ' . date('m/Y')],
            ['P-002', '1001', $hoy, 'A001', '', '2', '1500', '21', '10', 'Efectivo', '', ''],
            ['P-002', '', '', '', 'Flete', '1', '5000', '21', '', '', '', ''],
        ] as $fila) {
            $x->fila($fila);
        }
        return new Response(200, $x->contenido(), ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename="modelo-facturacion-masiva.xlsx"', 'Cache-Control' => 'no-store']);
    }

    public static function subir(Request $req, array $params): Response
    {
        $volver = Response::redirect(url('/facturacion-masiva'));
        $f = $req->files['archivo'] ?? null;
        if (!is_array($f) || ($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_file((string) $f['tmp_name'])) {
            Session::flash('error', 'Elegí la planilla (.xlsx, .csv o .txt).');
            return $volver;
        }
        $nombre = basename(str_replace('\\', '/', (string) ($f['name'] ?? 'planilla')));
        if ((int) ($f['size'] ?? 0) > self::MAX_BYTES) {
            Session::flash('error', 'La planilla puede tener hasta 10 MB.');
            return $volver;
        }
        $cobro = (string) ($req->post['cobro'] ?? 'CC');
        $planilla = new Planilla();
        $cobro = $planilla->cobro($cobro) ?? 'CC';
        $sucursal = (string) ($req->post['sucursal'] ?? '');
        try {
            $hoja = Lector::leer((string) $f['tmp_name'], $nombre, true);
            $r = (new PreFacturasService())->cargarPlanilla($hoja, $nombre, ['cobro' => $cobro, 'sucursal' => $sucursal], (int) Auth::id(), self::permisos());
        } catch (\RuntimeException $e) {
            Session::flash('error', $e->getMessage());
            return $volver;
        }
        Session::flash($r['con_error'] > 0 ? 'info' : 'ok', 'Se cargaron ' . $r['prefacturas'] . ' pre-factura(s) de «' . $nombre . '».'
            . ($r['con_error'] > 0 ? ' ' . $r['con_error'] . ' con algo para corregir (marcadas en color): usá «Modificar» o corregí la planilla, eliminá ese lote y subila de nuevo.' : ' Revisalas y aprobalas.'));
        return Response::redirect(url('/facturacion-masiva') . '?lote=' . $r['lote']);
    }

    // ------------------------------------------------------------------ modificar (con el formulario de la factura)

    public static function editar(Request $req, array $params): Response
    {
        $svc = new PreFacturasService();
        $p = $svc->obtener((int) $params['id']);
        if ($p === null || $p['estado'] === 'eliminada') {
            return new Response(404);
        }
        if (($b = $svc->bloqueo($p)) !== null) {
            Session::flash('error', $b);
            return Response::redirect(url('/facturacion-masiva'));
        }
        $datos = $p['datos'];
        if (($datos['fecha'] ?? '') === '') {
            unset($datos['fecha']);
        }
        $errores = [];
        if ((string) $p['errores'] !== '') {
            $errores['prefactura'] = "Para corregir:\n" . (string) $p['errores']; // lo que vino de la planilla o del último intento de facturar
        }
        return self::form($p, $datos, Planilla::codigoCobro($p['datos']), $errores);
    }

    public static function guardar(Request $req, array $params): Response
    {
        $svc = new PreFacturasService();
        $id = (int) $params['id'];
        $p = $svc->obtener($id);
        if ($p === null || $p['estado'] === 'eliminada') {
            return new Response(404);
        }
        $cobro = (string) ($req->post['cobro'] ?? '');
        $datos = $req->post;
        $r = $svc->guardar($id, $datos, $cobro, (int) Auth::id(), self::permisos());
        if (!$r['ok']) {
            return self::form($p, $datos, $cobro, $r['errores'], 422);
        }
        $q = $svc->obtener($id);
        Session::flash($q !== null && (string) $q['errores'] !== '' ? 'info' : 'ok', 'Pre-factura guardada.'
            . ($q !== null && (string) $q['errores'] !== '' ? ' Ojo: ' . str_replace("\n", ' ', (string) $q['errores']) : ''));
        return Response::redirect(url('/facturacion-masiva') . ($p['lote_id'] ? '?lote=' . (int) $p['lote_id'] : '') . '#pf-' . $id);
    }

    /** El formulario de la factura (views/comprobantes/form.php) apuntando a la pre-factura. @param array<string,mixed> $p @param array<string,mixed> $datos @param array<string,string> $errores */
    private static function form(array $p, array $datos, string $cobro, array $errores, int $status = 200): Response
    {
        $svc = new ComprobantesService();
        $pdo = Db::pdo();
        $datos['tipo'] = 'FC';
        $cliente = ctype_digit((string) ($datos['cliente'] ?? '')) && (int) $datos['cliente'] > 0 ? (new ClientesService())->obtener((int) $datos['cliente']) : null;
        $emisor = $svc->emisor();
        $letra = '';
        if ($cliente !== null && $emisor['condicion'] !== null && ($cond = $svc->condicionArca((int) $cliente['iva'])) !== null) {
            $letra = \App\Modules\Comprobantes\Tipos::letra((string) $emisor['condicion'], $cond);
        }
        $titulo = 'Modificar pre-factura' . ($p['referencia'] !== '' ? ' ' . $p['referencia'] : '');
        return Response::html(View::render('comprobantes/form', [
            'title' => $titulo, 'nav' => 'masiva', 'tipo' => 'FC', 'datos' => $datos, 'reserva' => null, 'errores' => $errores, 'cliente' => $cliente, 'asociables' => [], 'sinIva' => false,
            'sucursales' => $svc->sucursales(), 'alicuotas' => $pdo->query('SELECT codigo, descripcion, porcentaje FROM tasas_iva ORDER BY porcentaje DESC')->fetchAll(),
            'vendedores' => $pdo->query('SELECT codigo, descripcion FROM vendedores ORDER BY descripcion')->fetchAll(),
            'condiciones' => $pdo->query('SELECT codigo, descripcion FROM condicionpago ORDER BY descripcion')->fetchAll(),
            'emisor' => $emisor, 'letra' => $letra, 'modoArca' => Arca::modo(), 'etiquetaArca' => Arca::etiqueta(),
            'electronicas' => array_filter($svc->sucursales(), static fn ($s) => Arca::electronica($s)),
            'conceptoDefecto' => Configuracion::entero('comprobantes.concepto_defecto', 1), 'puedeCredito' => false,
            'deuda' => $cliente !== null ? $svc->deudaCliente((int) $cliente['codigo']) : 0.0,
            'prefactura' => ['id' => (int) $p['id'], 'titulo' => $titulo, 'cobro' => $cobro, 'formas' => Planilla::formasDePago(),
                'accion' => url('/facturacion-masiva/' . (int) $p['id'] . '/modificar'),
                'volver' => url('/facturacion-masiva') . ($p['lote_id'] ? '?lote=' . (int) $p['lote_id'] : '')],
        ]), $status);
    }

    // ------------------------------------------------------------------ acciones sobre las elegidas

    /** @return int[] */
    private static function ids(Request $req): array
    {
        return array_values(array_filter(array_map('intval', (array) ($req->post['ids'] ?? [])), static fn ($i) => $i > 0));
    }

    public static function eliminar(Request $req, array $params): Response
    {
        $ids = self::ids($req);
        if ($ids === []) {
            Session::flash('error', 'Elegí las pre-facturas a eliminar.');
            return Response::redirect(url('/facturacion-masiva'));
        }
        $r = (new PreFacturasService())->eliminar($ids, (int) Auth::id());
        Session::flash($r['no'] === [] ? 'ok' : 'info', 'Se eliminaron ' . $r['eliminadas'] . ' pre-factura(s).' . ($r['no'] !== [] ? ' No se eliminaron: ' . implode(' ', $r['no']) : ''));
        return Response::redirect(url('/facturacion-masiva') . self::volverA($req));
    }

    /** Vuelve a revisar las elegidas (por ejemplo, después de dar de alta un cliente o cargar stock). */
    public static function revisar(Request $req, array $params): Response
    {
        $svc = new PreFacturasService();
        $listas = 0;
        $ids = self::ids($req);
        foreach ($ids as $id) {
            $listas += $svc->revalidar($id, (int) Auth::id(), self::permisos()) ? 1 : 0;
        }
        Session::flash('ok', 'Revisadas: ' . count($ids) . '. Listas para facturar: ' . $listas . '.');
        return Response::redirect(url('/facturacion-masiva') . self::volverA($req));
    }

    /**
     * Aprueba y factura las elegidas, en orden de fecha. La pantalla manda una por pedido y pide JSON (avance a la vista); sin JavaScript llegan todas
     * juntas y se procesan hasta SEGUNDOS_POR_PEDIDO (lo que falta sigue elegido para el próximo clic).
     */
    public static function procesar(Request $req, array $params): Response
    {
        $svc = new PreFacturasService();
        $ids = $svc->ordenar(self::ids($req));
        $mail = ($req->post['mail'] ?? '') === '1';
        $json = ($req->post['json'] ?? '') === '1';
        @set_time_limit(self::SEGUNDOS_POR_PEDIDO + 60);
        $inicio = microtime(true);
        $resultados = [];
        foreach ($ids as $id) {
            if ($resultados !== [] && microtime(true) - $inicio > self::SEGUNDOS_POR_PEDIDO) {
                break;
            }
            $resultados[$id] = $svc->procesar($id, (int) Auth::id(), self::permisos(), $mail);
        }
        if ($json) {
            return Response::json(['resultados' => $resultados, 'faltan' => array_values(array_diff($ids, array_keys($resultados)))]);
        }
        $ok = count(array_filter($resultados, static fn ($r) => $r['ok']));
        $faltan = count($ids) - count($resultados);
        $mal = count($resultados) - $ok;
        Session::flash($mal > 0 || $faltan > 0 ? 'info' : 'ok', 'Facturadas: ' . $ok . '.' . ($mal > 0 ? ' Quedaron pendientes ' . $mal . ' (el motivo está en cada una).' : '')
            . ($faltan > 0 ? ' Faltan ' . $faltan . ': volvé a «Aprobar y facturar».' : ''));
        return Response::redirect(url('/facturacion-masiva') . self::volverA($req));
    }

    private static function volverA(Request $req): string
    {
        $lote = (string) ($req->post['lote'] ?? '');
        return ctype_digit($lote) && (int) $lote > 0 ? '?lote=' . (int) $lote : '';
    }
}
