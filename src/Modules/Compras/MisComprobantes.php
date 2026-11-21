<?php
declare(strict_types=1);

namespace App\Modules\Compras;

use App\Core\Db;
use App\Core\Valida;
use App\Modules\Auth\Auth;
use App\Modules\Importacion\Lector;

/**
 * «Mis Comprobantes Recibidos» de ARCA: se importa el Excel o CSV que se baja del portal y se compara con lo cargado en compras.
 *
 * DECISIÓN D-125: el archivo se reconoce por los títulos de las columnas (los de ARCA, en cualquier orden y aunque arriba haya un renglón de título):
 *   fecha, tipo, punto de venta, número (desde), CUIT del emisor, denominación, neto gravado, no gravado, exento, IVA (una columna o una por
 *   alícuota), otros tributos e importe total. Volver a importar actualiza (clave CUIT + tipo + punto de venta + número). Los importes en otra
 *   moneda se pasan a pesos con el tipo de cambio del archivo.
 * DECISIÓN D-126: un comprobante de ARCA está «cargado» si hay una compra vigente del proveedor con ese CUIT, mismo tipo y letra, punto de venta y
 *   número; «con diferencia» si el total difiere en más de $ 1. Las compras A, B, C o M del período que no figuran en ARCA se listan aparte
 *   (los tiques y gastos sin comprobante no, porque ARCA no los informa).
 */
final class MisComprobantes
{
    public const ESTADOS = ['falta' => 'Falta cargar', 'diferencia' => 'Diferencia de importe', 'ok' => 'Cargado'];

    /** Título de columna sin tildes, puntos ni mayúsculas. */
    private static function clave(string $t): string
    {
        $t = mb_strtolower(trim($t));
        $t = strtr($t, ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ñ' => 'n', '°' => '', 'º' => '']);
        return trim((string) preg_replace('/[^a-z0-9%]+/', ' ', $t));
    }

    /**
     * Lee el archivo y devuelve los comprobantes normalizados.
     * @return array{ok:bool,error?:string,filas?:array<int,array<string,mixed>>,errores?:string[]}
     */
    public static function leer(string $archivo, string $nombre): array
    {
        try {
            $l = Lector::leer($archivo, $nombre, false);
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => 'No se pudo leer el archivo: ' . $e->getMessage()];
        }
        // el renglón de títulos: el primero que tenga «tipo», «fecha» y algo de «total»
        $tit = null;
        foreach ($l['filas'] as $i => $f) {
            $c = array_map([self::class, 'clave'], $f['celdas']);
            if (in_array('fecha', $c, true) || preg_grep('/^fecha( de emision)?$/', $c)) {
                if (preg_grep('/^tipo/', $c) && preg_grep('/total/', $c)) {
                    $tit = $i;
                    break;
                }
            }
            if ($i > 10) {
                break;
            }
        }
        if ($tit === null) {
            return ['ok' => false, 'error' => 'El archivo no tiene los títulos de «Mis Comprobantes» de ARCA (fecha, tipo, punto de venta, número, emisor, total). Bajalo de nuevo desde el portal, en Excel o CSV.'];
        }
        $cols = array_map([self::class, 'clave'], $l['filas'][$tit]['celdas']);
        $buscar = static function (array $patrones) use ($cols): ?int {
            foreach ($patrones as $p) {
                foreach ($cols as $i => $c) {
                    if (preg_match($p, $c) === 1) {
                        return $i;
                    }
                }
            }
            return null;
        };
        $ix = [
            'fecha' => $buscar(['/^fecha( de emision)?$/', '/^fecha/']),
            'tipo' => $buscar(['/^tipo( de comprobante)?$/', '/^tipo(?! doc| cambio)/']),
            'pv' => $buscar(['/^punto de venta$/', '/punto de venta/', '/^pto/']),
            'numero' => $buscar(['/^numero desde$/', '/^nro desde$/', '/^numero$/', '/^nro$/', '/^numero de comprobante$/']),
            'cuit' => $buscar(['/^nro doc emisor$/', '/doc emisor$/', '/^cuit emisor$/', '/^cuit/']),
            'nombre' => $buscar(['/denominacion emisor/', '/^denominacion/', '/razon social/']),
            'cae' => $buscar(['/cod autorizacion/', '/^cae$/', '/autorizacion/']),
            'cambio' => $buscar(['/^tipo cambio$/', '/tipo de cambio/']),
            'moneda' => $buscar(['/^moneda$/']),
            'total' => $buscar(['/^imp total$/', '/^importe total$/', '/^total$/', '/total/']),
            'no_gravado' => $buscar(['/neto no gravado/', '/no gravado/']),
            'exento' => $buscar(['/exent/']),
            'otros' => $buscar(['/otros tributos/']),
        ];
        foreach (['fecha', 'tipo', 'pv', 'numero', 'cuit', 'total'] as $k) {
            if ($ix[$k] === null) {
                return ['ok' => false, 'error' => 'Al archivo le falta la columna ' . ['fecha' => 'de la fecha', 'tipo' => 'del tipo de comprobante', 'pv' => 'del punto de venta',
                    'numero' => 'del número', 'cuit' => 'del CUIT del emisor', 'total' => 'del importe total'][$k] . '.'];
            }
        }
        // neto gravado e IVA: una columna o una por alícuota («Neto Grav. IVA 21%», «IVA 21%»…)
        $netos = $ivas = ['total' => [], 'alicuota' => []];
        foreach ($cols as $i => $c) {
            $k = str_contains($c, '%') ? 'alicuota' : 'total';
            if (preg_match('/neto grav/', $c) === 1 && !str_contains($c, 'no grav')) {
                $netos[$k][] = $i;
            } elseif (preg_match('/^(total )?iva( \d+([ ,]\d+)? ?%)?$/', $c) === 1 || preg_match('/^imp(orte)? iva$/', $c) === 1) {
                $ivas[$k][] = $i;
            }
        }
        // si está la columna del total, esa; si no, la suma de las de cada alícuota
        $netos = $netos['total'] !== [] ? [$netos['total'][0]] : $netos['alicuota'];
        $ivas = $ivas['total'] !== [] ? [$ivas['total'][0]] : $ivas['alicuota'];
        $num = static function (array $celdas, ?int $i): float {
            if ($i === null) {
                return 0.0;
            }
            $v = trim((string) ($celdas[$i] ?? ''));
            if ($v === '') {
                return 0.0;
            }
            if (preg_match('/^-?\d+\.\d+$/', $v) === 1 && !str_contains($v, ',')) {
                return (float) $v; // número del Excel con punto decimal
            }
            return Valida::decimal($v) ?? 0.0;
        };
        $filas = [];
        $errores = [];
        foreach ($l['filas'] as $i => $f) {
            if ($i <= $tit) {
                continue;
            }
            $c = $f['celdas'];
            if (trim(implode('', $c)) === '') {
                continue;
            }
            $tipoTxt = trim((string) ($c[$ix['tipo']] ?? ''));
            $tipo = preg_match('/^(\d+)/', $tipoTxt, $m) === 1 ? (int) $m[1] : 0;
            $fechaTxt = trim((string) ($c[$ix['fecha']] ?? ''));
            $fecha = Valida::fecha($fechaTxt) ?? (is_numeric($fechaTxt) && (float) $fechaTxt > 20000 ? Lector::fechaExcel((float) $fechaTxt) : null);
            $cuit = preg_replace('/\D/', '', (string) ($c[$ix['cuit']] ?? '')) ?? '';
            $numero = (int) preg_replace('/\D/', '', (string) ($c[$ix['numero']] ?? ''));
            if (!isset(ComprobanteArca::TIPOS[$tipo]) || $fecha === null || strlen($cuit) !== 11 || $numero < 1) {
                $errores[] = 'Renglón ' . $f['n'] . ': ' . (!isset(ComprobanteArca::TIPOS[$tipo]) ? 'tipo de comprobante «' . $tipoTxt . '» que compras no maneja' : 'datos incompletos') . '.';
                continue;
            }
            $cambio = $ix['cambio'] !== null ? $num($c, $ix['cambio']) : 1.0;
            $cambio = $cambio > 0 ? $cambio : 1.0;
            $p = static fn (float $v) => round($v * $cambio, 2);
            $neto = array_sum(array_map(static fn ($i) => $num($c, $i), $netos));
            $iva = array_sum(array_map(static fn ($i) => $num($c, $i), $ivas));
            $filas[] = ['cuit' => $cuit, 'tipo_cmp' => $tipo, 'punto_venta' => (int) preg_replace('/\D/', '', (string) ($c[$ix['pv']] ?? '')), 'numero' => $numero,
                'fecha' => $fecha, 'denominacion' => mb_substr(trim((string) ($c[$ix['nombre']] ?? '')), 0, 120),
                'neto' => $p($neto), 'no_gravado' => $p($num($c, $ix['no_gravado']) + $num($c, $ix['exento'])), 'iva' => $p($iva), 'otros' => $p($num($c, $ix['otros'])),
                'total' => $p($num($c, $ix['total'])), 'moneda' => mb_substr(trim((string) ($c[$ix['moneda']] ?? '$')), 0, 5) ?: '$', 'cambio' => $cambio,
                'cae' => mb_substr(preg_replace('/\D/', '', (string) ($c[$ix['cae']] ?? '')) ?? '', 0, 20)];
        }
        if ($filas === []) {
            return ['ok' => false, 'error' => 'El archivo no tiene comprobantes para importar.' . ($errores !== [] ? ' ' . $errores[0] : '')];
        }
        return ['ok' => true, 'filas' => $filas, 'errores' => $errores];
    }

    /** Graba (o actualiza) los comprobantes leídos. @param array<int,array<string,mixed>> $filas @return array{nuevos:int,actualizados:int,desde:string,hasta:string} */
    public static function guardar(array $filas): array
    {
        $pdo = Db::pdo();
        $st = $pdo->prepare('INSERT INTO compras_arca (cuit, tipo_cmp, punto_venta, numero, fecha, denominacion, neto, no_gravado, iva, otros, total, moneda, cambio, cae, importado_en, usuario_id)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,NOW(),?) ON DUPLICATE KEY UPDATE fecha = VALUES(fecha), denominacion = VALUES(denominacion), neto = VALUES(neto),
            no_gravado = VALUES(no_gravado), iva = VALUES(iva), otros = VALUES(otros), total = VALUES(total), moneda = VALUES(moneda), cambio = VALUES(cambio), cae = VALUES(cae),
            importado_en = NOW(), usuario_id = VALUES(usuario_id)');
        $n = $a = 0;
        $pdo->beginTransaction();
        try {
            foreach ($filas as $f) {
                $st->execute([(int) $f['cuit'], $f['tipo_cmp'], $f['punto_venta'], $f['numero'], $f['fecha'], $f['denominacion'], $f['neto'], $f['no_gravado'], $f['iva'],
                    $f['otros'], $f['total'], $f['moneda'], $f['cambio'], $f['cae'], Auth::id()]);
                $st->rowCount() === 1 ? $n++ : $a++;
            }
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
        $fechas = array_column($filas, 'fecha');
        return ['nuevos' => $n, 'actualizados' => $a, 'desde' => min($fechas), 'hasta' => max($fechas)];
    }

    /** Un comprobante importado. @return array<string,mixed>|null */
    public static function obtener(int $id): ?array
    {
        $st = Db::pdo()->prepare('SELECT * FROM compras_arca WHERE id = ?');
        $st->execute([$id]);
        $f = $st->fetch();
        return $f ?: null;
    }

    public static function titulo(array $x): string
    {
        [$tipo, $letra] = ComprobanteArca::TIPOS[(int) $x['tipo_cmp']] ?? ['??', ''];
        return ComprasService::titulo(['tipo' => $tipo, 'letra' => $letra, 'punto_venta' => $x['punto_venta'], 'numero' => $x['numero']]);
    }

    /**
     * Control de un período (meses por fecha de emisión) contra lo cargado.
     * @return array{filas:array<int,array<string,mixed>>,sobrantes:array<int,array<string,mixed>>,cuenta:array<string,int>,importados:int}
     */
    public static function control(string $desde, string $hasta, string $estado = ''): array
    {
        $pdo = Db::pdo();
        $st = $pdo->prepare('SELECT a.*, p.id AS proveedor_id, p.razon_social FROM compras_arca a LEFT JOIN proveedores p ON p.id = (SELECT MIN(x.id) FROM proveedores x WHERE x.cuit = a.cuit)
            WHERE a.fecha BETWEEN ? AND ? ORDER BY a.fecha, a.cuit, a.tipo_cmp, a.punto_venta, a.numero');
        $st->execute([$desde, $hasta]);
        $arca = $st->fetchAll();
        $buscar = $pdo->prepare("SELECT c.id, c.total FROM compras c JOIN proveedores p ON p.id = c.proveedor_id
            WHERE p.cuit = ? AND c.tipo = ? AND c.letra = ? AND c.punto_venta = ? AND c.numero = ? AND c.estado = 'V' ORDER BY c.id LIMIT 1");
        $vistas = [];
        $cuenta = array_fill_keys(array_keys(self::ESTADOS), 0);
        $filas = [];
        foreach ($arca as $x) {
            [$tipo, $letra] = ComprobanteArca::TIPOS[(int) $x['tipo_cmp']] ?? ['', ''];
            $buscar->execute([(int) $x['cuit'], $tipo, $letra, (int) $x['punto_venta'], (int) $x['numero']]);
            $c = $buscar->fetch();
            $x['titulo'] = self::titulo($x);
            $x['compra_id'] = $c ? (int) $c['id'] : null;
            $x['compra_total'] = $c ? (float) $c['total'] : null;
            $x['estado'] = !$c ? 'falta' : (abs((float) $c['total'] - (float) $x['total']) > 1.0 ? 'diferencia' : 'ok');
            if ($c) {
                $vistas[(int) $c['id']] = true;
            }
            $cuenta[$x['estado']]++;
            if ($estado === '' || $estado === $x['estado']) {
                $filas[] = $x;
            }
        }
        // compras del período que ARCA no tiene (solo si se importó algo de esas fechas)
        $sobrantes = [];
        if ($arca !== []) {
            $st = $pdo->prepare("SELECT c.id, c.fecha, c.tipo, c.letra, c.punto_venta, c.numero, c.total, p.razon_social, p.cuit FROM compras c JOIN proveedores p ON p.id = c.proveedor_id
                WHERE c.estado = 'V' AND c.tipo IN ('FC', 'ND', 'NC') AND c.letra IN ('A', 'B', 'C', 'M') AND c.fecha BETWEEN ? AND ? ORDER BY c.fecha, c.id");
            $st->execute([$desde, $hasta]);
            foreach ($st->fetchAll() as $c) {
                if (!isset($vistas[(int) $c['id']])) {
                    $c['titulo'] = ComprasService::titulo($c);
                    $sobrantes[] = $c;
                }
            }
        }
        return ['filas' => $filas, 'sobrantes' => $sobrantes, 'cuenta' => $cuenta, 'importados' => count($arca)];
    }
}
