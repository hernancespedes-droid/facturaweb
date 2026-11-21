<?php
declare(strict_types=1);

namespace App\Modules\Compras;

use App\Core\Db;
use App\Core\Session;
use App\Core\Valida;
use App\Core\View;
use App\Http\Request;
use App\Http\Response;
use App\Modules\Auth\Auth;
use App\Modules\Importacion\Lector;

/** Compras, entrega C (0.47.0): QR de la factura, «Mis Comprobantes Recibidos», Libro IVA Digital de compras y resultado del mes. */
final class ComprasArcaController
{
    private static function periodo(Request $req): string
    {
        $p = (string) ($req->query['periodo'] ?? '');
        return CentrosService::periodoValido($p) ? $p : date('Y-m');
    }

    // ------------------------------------------------------------------ QR

    public static function qr(Request $req, array $params, ?string $error = null, string $texto = '', int $status = 200): Response
    {
        return Response::html(View::render('compras/qr', ['title' => 'Cargar desde el QR', 'nav' => 'compras_qr', 'error' => $error, 'texto' => $texto]), $status);
    }

    public static function qrLeer(Request $req, array $params): Response
    {
        $texto = trim((string) ($req->post['qr'] ?? ''));
        $x = ComprobanteArca::desdeQr($texto);
        if ($x === null) {
            return self::qr($req, $params, $texto === '' ? 'No llegó ningún código. Apuntá la cámara al QR de la factura o pegá el enlace.'
                : 'Ese código no es el QR de una factura electrónica de ARCA.', $texto, 422);
        }
        return self::abrirCompra($req, $params, $x, '', 'qr');
    }

    /** Busca si ya está cargada; si no, abre la carga con lo que trae ARCA (y el proveedor, dado de alta si hace falta). */
    private static function abrirCompra(Request $req, array $params, array $x, string $nombre, string $origen): Response
    {
        [$tipo, $letra] = ComprobanteArca::TIPOS[$x['tipo_cmp']];
        $prov = ComprobanteArca::proveedor($x['cuit']);
        if ($prov !== null) {
            $st = Db::pdo()->prepare("SELECT id FROM compras WHERE proveedor_id = ? AND tipo = ? AND letra = ? AND punto_venta = ? AND numero = ? AND estado = 'V' LIMIT 1");
            $st->execute([(int) $prov['id'], $tipo, $letra, $x['punto_venta'], $x['numero']]);
            $ya = $st->fetchColumn();
            if ($ya !== false) {
                Session::flash('ok', 'Ese comprobante ya está cargado.');
                return Response::redirect(url('/compras/ver/' . (int) $ya));
            }
        }
        $p = ComprobanteArca::proveedorOAlta($x['cuit'], $nombre, $letra);
        if (!$p['ok']) {
            return $origen === 'qr' ? self::qr($req, $params, $p['error'], '', 422) : self::misComprobantes($req, $params, $p['error'], 422);
        }
        $d = ComprobanteArca::datosCompra($x, $p['proveedor'], $origen);
        $avisos = [];
        if ($p['nuevo']) {
            $avisos[] = 'Se dio de alta el proveedor ' . $p['proveedor']['razon_social'] . ' (C.U.I.T. ' . cuit_texto((string) $x['cuit']) . '): revisá sus datos en Proveedores.';
        }
        $nuestro = preg_replace('/\D/', '', (string) Db::pdo()->query('SELECT cuit FROM parametros WHERE id = 1')->fetchColumn()) ?? '';
        if (($x['receptor'] ?? '') !== '' && $nuestro !== '' && $x['receptor'] !== $nuestro) {
            $avisos[] = 'Atención: el comprobante está a nombre de otro CUIT (' . cuit_texto($x['receptor']) . '), no del de la empresa.';
        }
        if (($x['moneda'] ?? 'PES') !== 'PES' && ($x['moneda'] ?? '') !== '$') {
            $avisos[] = 'El comprobante está en ' . $x['moneda'] . ' (cotización ' . str_replace('.', ',', (string) $x['cambio']) . '): pasá los importes a pesos.';
        }
        $avisos[] = ComprasService::discrimina($tipo, $letra) && !isset($d['neto'])
            ? 'Completá el neto por alícuota: el total impreso ya está cargado para controlarlo.' : 'Revisá los importes y elegí el centro de costos.';
        $d['avisos_arca'] = $avisos;
        return ComprasController::form($d, [], null);
    }

    // ------------------------------------------------------------------ Mis Comprobantes

    public static function misComprobantes(Request $req, array $params, ?string $error = null, int $status = 200): Response
    {
        $desde = Valida::fecha((string) ($req->query['desde'] ?? ''));
        $hasta = Valida::fecha((string) ($req->query['hasta'] ?? ''));
        if ($desde === null || $hasta === null) {
            $ult = Db::pdo()->query('SELECT MAX(fecha) FROM compras_arca')->fetchColumn();
            $base = $ult ? (string) $ult : date('Y-m-d');
            $desde = date('Y-m-01', (int) strtotime($base));
            $hasta = date('Y-m-t', (int) strtotime($base));
        }
        $estado = (string) ($req->query['estado'] ?? '');
        $estado = isset(MisComprobantes::ESTADOS[$estado]) ? $estado : '';
        return Response::html(View::render('compras/mis_comprobantes', ['title' => 'Mis Comprobantes de ARCA', 'nav' => 'compras_arca', 'error' => $error,
            'desde' => $desde, 'hasta' => $hasta, 'estado' => $estado, 'r' => MisComprobantes::control($desde, $hasta, $estado),
            'puedeCargar' => Auth::puede('compras.cargar')]), $status);
    }

    public static function misComprobantesImportar(Request $req, array $params): Response
    {
        $f = $req->files['archivo'] ?? null;
        if (!is_array($f) || ($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_file((string) $f['tmp_name'])) {
            return self::misComprobantes($req, $params, 'Elegí el archivo que bajaste de «Mis Comprobantes» (Excel .xlsx o CSV).', 422);
        }
        $nombre = basename(str_replace('\\', '/', (string) ($f['name'] ?? 'archivo')));
        $ext = strtolower(pathinfo($nombre, PATHINFO_EXTENSION));
        if (!in_array($ext, Lector::EXTENSIONES, true) || (int) ($f['size'] ?? 0) > 10 * 1024 * 1024) {
            return self::misComprobantes($req, $params, $ext === 'xls' ? 'El Excel viejo (.xls) no se puede leer: guardalo como .xlsx o bajalo en CSV.' : 'El archivo tiene que ser .xlsx o .csv de hasta 10 MB.', 422);
        }
        $r = MisComprobantes::leer((string) $f['tmp_name'], $nombre);
        if (!$r['ok']) {
            return self::misComprobantes($req, $params, $r['error'], 422);
        }
        $g = MisComprobantes::guardar($r['filas']);
        $omitidos = count($r['errores']);
        Session::flash('ok', 'Importados ' . ($g['nuevos'] + $g['actualizados']) . ' comprobantes de ARCA (' . $g['nuevos'] . ' nuevos)'
            . ($omitidos > 0 ? '; ' . $omitidos . ' renglones no se tomaron (' . $r['errores'][0] . ($omitidos > 1 ? '…' : '') . ')' : '') . '.');
        return Response::redirect(url('/compras/mis-comprobantes?desde=' . $g['desde'] . '&hasta=' . $g['hasta']));
    }

    public static function desdeArca(Request $req, array $params): Response
    {
        $a = MisComprobantes::obtener((int) ($req->post['id'] ?? 0));
        if ($a === null) {
            return new Response(404);
        }
        $x = ['cuit' => (string) $a['cuit'], 'tipo_cmp' => (int) $a['tipo_cmp'], 'punto_venta' => (int) $a['punto_venta'], 'numero' => (int) $a['numero'],
            'fecha' => (string) $a['fecha'], 'total' => (float) $a['total'], 'neto' => (float) $a['neto'], 'iva' => (float) $a['iva'], 'no_gravado' => (float) $a['no_gravado'],
            'cae' => (string) $a['cae'], 'moneda' => 'PES', 'cambio' => 1.0, 'receptor' => ''];
        if (!isset(ComprobanteArca::TIPOS[$x['tipo_cmp']])) {
            return new Response(404);
        }
        return self::abrirCompra($req, $params, $x, (string) $a['denominacion'], 'arca');
    }

    // ------------------------------------------------------------------ Libro IVA Digital compras

    /** ?periodo=AAAA-MM&archivo=cbte|alicuotas|zip */
    public static function libroIva(Request $req, array $params): Response
    {
        $libro = LibroIvaCompras::generar(self::periodo($req));
        $archivo = (string) ($req->query['archivo'] ?? '');
        if ($libro === null || !in_array($archivo, ['cbte', 'alicuotas', 'zip'], true)) {
            return new Response(404);
        }
        $sufijo = '-' . str_replace('-', '', $libro['periodo']);
        if ($archivo === 'zip') {
            $bin = LibroIvaCompras::zip($libro);
            return $bin === null ? new Response(404) : new Response(200, $bin, ['Content-Type' => 'application/zip',
                'Content-Disposition' => 'attachment; filename="LIBRO_IVA_DIGITAL_COMPRAS' . $sufijo . '.zip"', 'Cache-Control' => 'no-store']);
        }
        $nombre = $archivo === 'cbte' ? LibroIvaCompras::ARCHIVO_CBTE : LibroIvaCompras::ARCHIVO_ALICUOTAS;
        return new Response(200, $archivo === 'cbte' ? $libro['cbte'] : $libro['alicuotas'], ['Content-Type' => 'text/plain; charset=ISO-8859-1',
            'Content-Disposition' => 'attachment; filename="' . $nombre . $sufijo . '.txt"', 'Cache-Control' => 'no-store']);
    }

    // ------------------------------------------------------------------ resultado del mes

    public static function resultado(Request $req, array $params): Response
    {
        $periodo = self::periodo($req);
        return Response::html(View::render('compras/resultado', ['title' => 'Resultado del mes', 'nav' => 'compras_resultado', 'periodo' => $periodo,
            'r' => ComprasListados::resultado($periodo), 'verCostos' => Auth::puede('listados.costos')]));
    }
}
