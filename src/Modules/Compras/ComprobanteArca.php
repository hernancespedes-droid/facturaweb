<?php
declare(strict_types=1);

namespace App\Modules\Compras;

use App\Core\Db;
use App\Core\Valida;
use App\Modules\Arca\Padron;

/**
 * Un comprobante recibido tal como lo informa ARCA (QR de la factura o «Mis Comprobantes Recibidos») → compra para revisar y grabar.
 *
 * DECISIÓN D-123: el QR se lee con la cámara del celular donde el navegador lo permite (BarcodeDetector: Chrome en Android, también en compu con cámara);
 *   donde no (iPhone, Firefox), se pega el enlace que da la cámara del teléfono o se sube una foto. No se usa ninguna librería externa.
 *   La compra se abre con lo que trae el QR (proveedor, tipo, punto de venta, número, fecha, total impreso y CAE) marcada «para revisar»:
 *   el QR no trae el neto ni el IVA, que se completan a mano (en B y C el importe es el total).
 * DECISIÓN D-124: si el CUIT del emisor no es de ningún proveedor, se da de alta solo con la razón social del padrón de ARCA (o la de
 *   «Mis Comprobantes»; si no hay ninguna, «PROVEEDOR CUIT …» para corregir) y la condición de IVA del padrón o, si no, la que indica la letra
 *   (A o M = responsable inscripto, B = responsable inscripto, C = monotributo).
 */
final class ComprobanteArca
{
    /** Código de comprobante de ARCA => [tipo, letra] de compras. */
    public const TIPOS = [
        1 => ['FC', 'A'], 2 => ['ND', 'A'], 3 => ['NC', 'A'], 6 => ['FC', 'B'], 7 => ['ND', 'B'], 8 => ['NC', 'B'], 11 => ['FC', 'C'], 12 => ['ND', 'C'], 13 => ['NC', 'C'],
        51 => ['FC', 'M'], 52 => ['ND', 'M'], 53 => ['NC', 'M'], 81 => ['TQ', 'A'], 82 => ['TQ', 'B'], 111 => ['TQ', 'C'],
        201 => ['FC', 'A'], 202 => ['ND', 'A'], 203 => ['NC', 'A'], 206 => ['FC', 'B'], 207 => ['ND', 'B'], 208 => ['NC', 'B'], 211 => ['FC', 'C'], 212 => ['ND', 'C'], 213 => ['NC', 'C'],
    ];

    /** [tipo, letra] de compras => código de ARCA (para el Libro IVA Digital). */
    public static function codigo(string $tipo, string $letra): ?int
    {
        foreach (self::TIPOS as $cod => [$t, $l]) {
            if ($t === $tipo && $l === $letra && $cod < 200) {
                return $cod;
            }
        }
        return null;
    }

    /**
     * Lee el contenido de un QR de factura electrónica (el enlace https://www.afip.gob.ar/fe/qr/?p=…, el de arca.gob.ar, el base64 solo o el JSON).
     * @return array{cuit:string,tipo_cmp:int,punto_venta:int,numero:int,fecha:string,total:float,moneda:string,cambio:float,cae:string,receptor:string}|null
     */
    public static function desdeQr(string $texto): ?array
    {
        $t = trim($texto);
        if ($t === '') {
            return null;
        }
        $json = null;
        if (str_starts_with($t, '{')) {
            $json = $t;
        } else {
            $p = $t;
            if (preg_match('/[?&]p=([^&#\s]+)/', $t, $m) === 1) {
                $p = rawurldecode($m[1]);
            }
            $p = strtr(preg_replace('/\s+/', '', $p) ?? '', '-_', '+/');
            $p .= str_repeat('=', (4 - strlen($p) % 4) % 4);
            $json = base64_decode($p, true);
        }
        $d = is_string($json) ? json_decode($json, true) : null;
        if (!is_array($d)) {
            return null;
        }
        $cuit = preg_replace('/\D/', '', (string) ($d['cuit'] ?? '')) ?? '';
        $tipo = (int) ($d['tipoCmp'] ?? 0);
        $fecha = Valida::fecha((string) ($d['fecha'] ?? ''));
        if (strlen($cuit) !== 11 || !isset(self::TIPOS[$tipo]) || $fecha === null || (int) ($d['nroCmp'] ?? 0) < 1) {
            return null;
        }
        return ['cuit' => $cuit, 'tipo_cmp' => $tipo, 'punto_venta' => (int) ($d['ptoVta'] ?? 0), 'numero' => (int) $d['nroCmp'], 'fecha' => $fecha,
            'total' => round((float) ($d['importe'] ?? 0), 2), 'moneda' => (string) ($d['moneda'] ?? 'PES'), 'cambio' => (float) ($d['ctz'] ?? 1),
            'cae' => preg_replace('/\D/', '', (string) ($d['codAut'] ?? '')) ?? '', 'receptor' => preg_replace('/\D/', '', (string) ($d['nroDocRec'] ?? '')) ?? ''];
    }

    /** El proveedor con ese CUIT (el activo primero), o null. @return array<string,mixed>|null */
    public static function proveedor(string $cuit): ?array
    {
        $st = Db::pdo()->prepare('SELECT id FROM proveedores WHERE cuit = ? ORDER BY activo DESC, id LIMIT 1');
        $st->execute([(int) $cuit]);
        $id = $st->fetchColumn();
        return $id === false ? null : (new ProveedoresService())->obtener((int) $id);
    }

    /**
     * El proveedor de ese CUIT; si no existe, lo da de alta (D-124).
     * @return array{ok:bool,proveedor?:array<string,mixed>,nuevo?:bool,error?:string}
     */
    public static function proveedorOAlta(string $cuit, string $nombre, string $letra): array
    {
        $p = self::proveedor($cuit);
        if ($p !== null) {
            return ['ok' => true, 'proveedor' => $p, 'nuevo' => false];
        }
        if (!Valida::cuit($cuit)) {
            return ['ok' => false, 'error' => 'El CUIT del emisor (' . cuit_texto($cuit) . ') no es válido.'];
        }
        $iva = in_array($letra, ['C'], true) ? 7 : 1;
        $datos = ['razon_social' => trim($nombre), 'cuit' => $cuit, 'iva' => (string) $iva];
        if (Padron::disponible()) {
            try {
                $pd = Padron::consultar($cuit);
                if (($pd['encontrado'] ?? false) && trim((string) $pd['razon_social']) !== '') {
                    $datos['razon_social'] = (string) $pd['razon_social'];
                    $datos['iva'] = (string) ((int) $pd['iva'] ?: $iva);
                    foreach (['domicilio', 'localidad', 'codigo_postal'] as $k) {
                        if (trim((string) ($pd[$k] ?? '')) !== '') {
                            $datos[$k] = (string) $pd[$k];
                        }
                    }
                }
            } catch (\Throwable) {
                // sin padrón: sigue con lo que hay
            }
        }
        if (trim($datos['razon_social']) === '') {
            $datos['razon_social'] = 'PROVEEDOR CUIT ' . cuit_texto($cuit);
        }
        $datos['razon_social'] = mb_substr(mb_strtoupper(trim($datos['razon_social'])), 0, 60);
        $r = (new ProveedoresService())->guardar(null, $datos);
        if (!$r['ok']) {
            // un dato del padrón que no pasa la validación: alta con lo mínimo
            $r = (new ProveedoresService())->guardar(null, ['razon_social' => $datos['razon_social'], 'cuit' => $cuit, 'iva' => (string) $iva]);
        }
        if (!$r['ok']) {
            return ['ok' => false, 'error' => 'No se pudo dar de alta el proveedor: ' . implode(' ', $r['errores'] ?? [])];
        }
        return ['ok' => true, 'proveedor' => (new ProveedoresService())->obtener((int) $r['id']), 'nuevo' => true];
    }

    /**
     * Datos para el formulario de compra a partir de un comprobante de ARCA.
     * @param array{tipo_cmp:int,punto_venta:int,numero:int,fecha:string,total:float,cae?:string,neto?:float,iva?:float,no_gravado?:float} $x
     * @return array<string,mixed>
     */
    public static function datosCompra(array $x, array $proveedor, string $origen): array
    {
        [$tipo, $letra] = self::TIPOS[$x['tipo_cmp']];
        $m = static fn (float $v) => number_format($v, 2, ',', '.');
        $d = ['proveedor' => (int) $proveedor['id'], 'tipo' => $tipo, 'letra' => $letra, 'punto_venta' => (string) $x['punto_venta'], 'numero' => (string) $x['numero'],
            'fecha' => date('d/m/Y', (int) strtotime($x['fecha'])), 'total_impreso' => $m((float) $x['total']), 'cae' => (string) ($x['cae'] ?? ''), 'forma' => 'C',
            'a_revisar' => '1', 'origen_arca' => $origen];
        if ((int) ($proveedor['plazo_dias'] ?? 0) > 0) {
            $d['vence'] = date('d/m/Y', (int) strtotime($x['fecha'] . ' +' . (int) $proveedor['plazo_dias'] . ' days'));
        }
        if (!ComprasService::discrimina($tipo, $letra)) {
            $d['importe'] = $m((float) $x['total']);
        } elseif (isset($x['neto']) && (float) $x['neto'] > 0 && isset($x['iva'])) {
            // «Mis Comprobantes» trae neto e IVA: si cierran con una sola alícuota, se propone esa
            foreach (ComprasService::ALICUOTAS as $k => $a) {
                if (abs(round((float) $x['neto'] * $a / 100, 2) - (float) $x['iva']) <= 0.05) {
                    $d['neto'] = [$k => $m((float) $x['neto'])];
                    $d['iva'] = [$k => $m((float) $x['iva'])];
                    break;
                }
            }
            if ((float) ($x['no_gravado'] ?? 0) > 0) {
                $d['no_gravado'] = $m((float) $x['no_gravado']);
            }
        }
        return $d;
    }
}
