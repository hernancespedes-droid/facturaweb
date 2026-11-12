<?php
declare(strict_types=1);

namespace App\Modules\Agente;

use App\Core\Db;

/**
 * Web → VFP. Arma el archivo de intercambio con lo emitido en la web que el VFP todavía no tiene (subdiario con su IVA, renglones, caja y cuenta
 * corriente, más los movimientos de stock) y registra lo que el VFP confirmó o rechazó. El agente baja el archivo a una carpeta de la PC del VFP,
 * un programa dentro del VFP (vfp-importador/IMPORTAR_WEB.PRG) lo graba con las reglas del VFP —así mantiene sus índices— y devuelve un resultado.
 *
 * Formato (texto, UTF-8, una línea por registro; los campos van como nombre=valor separados por | con %, |, = y saltos de línea en %xx):
 *   #FACTURAWEB|1|<lote>|<fecha>
 *   S|<id>|campo=valor|...   comprobante (subdiario); I, E, J, T = su IVA, renglones (estadística), caja y cuenta corriente (cuelgan del id de la S anterior)
 *   M|<id>|campo=valor|...   movimiento de stock (subdiario_id=0 si no pertenece a un comprobante)
 * Resultado que devuelve el VFP:  OK|S|<id>   OK|M|<id>   ERR|S|<id>|motivo   ERR|M|<id>|motivo
 *
 * DECISIÓN D-22 (Claude, a confirmar por Hernán): solo viajan los comprobantes emitidos en la web con validez fiscal (no los de prueba).
 * DECISIÓN D-23: el número de movimiento de stock, el FE_CLAVE y demás números internos los asigna el VFP al grabar; los números de comprobante
 * y de ARCA (CAE) viajan tal cual.
 */
final class ExportadorVfp
{
    /** Campos que existen en el VFP (nombres largos de datos.dbc). Los números internos autoincrementales (fe_clave) y la fecha de actualización los pone el VFP. */
    public const CAMPOS = [
        'subdiario' => ['fecha', 'tipo', 'letra', 'sucursal', 'prefijo', 'comprobante', 'cliente', 'bruto', 'neto_grabado', 'neto_exento', 'iva_1', 'iva_2',
            'liberado', 'bonificacion', 'neto', 'porcentaje_descuento', 'estado', 'observaciones', 'canal', 'terminal', 'iva_cliente', 'cuit_cliente',
            'recargo_f_pago', 'impuesto', 'recibo', 'cobro', 'vendedor', 'forma_pago', 'orden_compra', 'fe_nrocbte', 'fe_ccae', 'fe_dfecha',
            'fe_limprimio', 'fe_cemailenvio', 'fe_dfechaenvio', 'fe_mobservaciones', 'fe_lrechazado', 'fe_ntipoafip', 'cond_pago', 'fe_dvtocae',
            'nrointerno', 'electronica', 'tipoafipoys', 'fe_vencimiento', 'fe_tipocbte_asoc', 'fe_nrocbte_asoc', 'fe_fecha_asoc'],
        'iva' => ['tipo', 'letra', 'sucursal', 'comprobante', 'alicuota', 'monto', 'importe'],
        'estadistica' => ['fecha', 'tipo', 'letra', 'sucursal', 'prefijo', 'comprobante', 'pedido', 'articulo', 'codigo_de_barra', 'bultos', 'unidades',
            'kilos', 'lista', 'precio', 'precio_siniva', 'porcentaje_descuento', 'total_item', 'total_siniva', 'cliente', 'grabado', 'por_iva', 'costo',
            'observaciones', 'merma', 'unidades_ori'],
        'caja' => ['fecha', 'tipo', 'letra', 'canal', 'terminal', 'sucursal', 'prefijo', 'comprobante', 'cliente', 'p_fpago', 'p_cuotas', 'p_nro',
            'p_fecvto', 'p_banco', 'p_cotiz', 'p_importe', 'a_pagar'],
        'ctact' => ['cliente', 'fecha', 'tipo', 'tico', 'letra', 'sucursal', 'prefijo', 'numero', 'neto', 'descuento', 'saldo', 'vencimiento', 'valor_dolar',
            'fecha_asiento'],
    ];
    private const LETRA_REGISTRO = ['iva' => 'I', 'estadistica' => 'E', 'caja' => 'J', 'ctact' => 'T'];

    /** Condición de lo pendiente: emitido en la web, con validez fiscal, que el VFP no tiene ni rechazó. */
    private const PENDIENTE = "canal = 'W' AND es_prueba = 0 AND vfp_enviado IS NULL AND vfp_error IS NULL";

    public static function enc(mixed $v): string
    {
        return strtr((string) ($v ?? ''), ['%' => '%25', '|' => '%7C', '=' => '%3D', "\r" => '%0D', "\n" => '%0A']);
    }

    /** @param array<string,mixed> $fila @param string[] $campos */
    private static function linea(string $tipo, int $id, array $fila, array $campos): string
    {
        $partes = [$tipo, (string) $id];
        foreach ($campos as $c) {
            if (array_key_exists($c, $fila) && $fila[$c] !== null) {
                $partes[] = $c . '=' . self::enc($fila[$c]);
            }
        }
        return implode('|', $partes);
    }

    /** Columnas que de verdad tiene la tabla web (las instalaciones viejas pueden no tener alguna). @return string[] */
    private static function columnas(string $tabla): array
    {
        static $cache = [];
        if (!isset($cache[$tabla])) {
            $st = Db::pdo()->prepare('SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
            $st->execute([$tabla]);
            $cache[$tabla] = array_map('strtolower', $st->fetchAll(\PDO::FETCH_COLUMN));
        }
        return $cache[$tabla];
    }

    /** @return array{lote:string,comprobantes:int,movimientos:int,texto:string} */
    public static function pendientes(int $limite = 50): array
    {
        $pdo = Db::pdo();
        $limite = max(1, min(200, $limite));
        $lote = date('YmdHis') . '-' . bin2hex(random_bytes(2));
        $lineas = ['#FACTURAWEB|1|' . $lote . '|' . date('Y-m-d H:i:s')];

        $sub = $pdo->query('SELECT * FROM subdiario WHERE ' . self::PENDIENTE . ' ORDER BY fecha, fe_clave LIMIT ' . $limite)->fetchAll();
        $nSub = 0;
        foreach ($sub as $s) {
            $id = (int) $s['fe_clave'];
            $nSub++;
            $lineas[] = self::linea('S', $id, $s, array_values(array_intersect(self::CAMPOS['subdiario'], self::columnas('subdiario'))));
            foreach (self::LETRA_REGISTRO as $tabla => $letra) {
                $st = $pdo->prepare("SELECT * FROM `{$tabla}` WHERE subdiario_id = ? ORDER BY fe_clave");
                $st->execute([$id]);
                $campos = array_values(array_intersect(self::CAMPOS[$tabla], self::columnas($tabla)));
                foreach ($st->fetchAll() as $f) {
                    $lineas[] = self::linea($letra, $id, $f, $campos);
                }
            }
            $lineas = [...$lineas, ...self::movimientos('m.subdiario_id = ' . $id)];
        }
        $sueltos = self::movimientos('m.subdiario_id IS NULL', 200);
        $nMov = count($sueltos);
        foreach ($sub as $s) {
            $nMov += (int) $pdo->query('SELECT COUNT(*) FROM stock_movimientos WHERE vfp_enviado IS NULL AND vfp_error IS NULL AND subdiario_id = ' . (int) $s['fe_clave'])->fetchColumn();
        }
        return ['lote' => $lote, 'comprobantes' => $nSub, 'movimientos' => $nMov, 'texto' => implode("\n", [...$lineas, ...$sueltos]) . "\n"];
    }

    /** @return string[] líneas M de los movimientos pendientes que cumplen $donde */
    private static function movimientos(string $donde, int $limite = 1000): array
    {
        $st = Db::pdo()->query("SELECT m.id, m.articulo, m.fecha, m.cantidad, m.origen, m.motivo, m.grupo, COALESCE(m.subdiario_id, 0) AS subdiario_id,
                d.sucursal_vfp AS sucursal
            FROM stock_movimientos m JOIN depositos d ON d.id = m.deposito_id
            WHERE m.vfp_enviado IS NULL AND m.vfp_error IS NULL AND {$donde} ORDER BY m.id LIMIT " . $limite);
        $out = [];
        foreach ($st->fetchAll() as $m) {
            $out[] = self::linea('M', (int) $m['id'], $m, ['subdiario_id', 'sucursal', 'articulo', 'fecha', 'cantidad', 'origen', 'motivo', 'grupo']);
        }
        return $out;
    }

    /**
     * Registra el resultado que devolvió el VFP. @return array{confirmados:int,errores:int}
     */
    public static function confirmar(string $texto): array
    {
        $pdo = Db::pdo();
        $ok = $err = 0;
        foreach (preg_split('/\r\n|\n|\r/', $texto) ?: [] as $linea) {
            $p = explode('|', trim($linea), 4);
            if (count($p) < 3 || !in_array($p[0], ['OK', 'ERR'], true) || !in_array($p[1], ['S', 'M'], true) || !ctype_digit($p[2])) {
                continue;
            }
            $id = (int) $p[2];
            $motivo = $p[0] === 'ERR' ? mb_substr(rawurldecode((string) ($p[3] ?? 'Error sin detalle')), 0, 250) : null;
            if ($p[1] === 'S') {
                $tabla = 'subdiario';
                $clave = 'fe_clave';
            } else {
                $tabla = 'stock_movimientos';
                $clave = 'id';
            }
            $set = $p[0] === 'OK' ? 'vfp_enviado = NOW(), vfp_error = NULL' : 'vfp_error = ?';
            $st = $pdo->prepare("UPDATE `{$tabla}` SET {$set} WHERE {$clave} = ? AND vfp_enviado IS NULL");
            $st->execute($p[0] === 'OK' ? [$id] : [$motivo, $id]);
            if ($p[1] === 'S') {
                // los movimientos de stock del comprobante siguen su suerte
                $st = $pdo->prepare('UPDATE stock_movimientos SET ' . $set . ' WHERE subdiario_id = ? AND vfp_enviado IS NULL');
                $st->execute($p[0] === 'OK' ? [$id] : [$motivo, $id]);
            }
            $p[0] === 'OK' ? $ok++ : $err++;
        }
        return ['confirmados' => $ok, 'errores' => $err];
    }

    /** Vuelve a poner como pendientes los que el VFP rechazó (después de que alguien arregló el motivo). */
    public static function reintentar(): int
    {
        $pdo = Db::pdo();
        $n = $pdo->exec('UPDATE subdiario SET vfp_error = NULL WHERE vfp_error IS NOT NULL AND vfp_enviado IS NULL');
        $pdo->exec('UPDATE stock_movimientos SET vfp_error = NULL WHERE vfp_error IS NOT NULL AND vfp_enviado IS NULL');
        return (int) $n;
    }

    /** @return array{pendientes:int,con_error:int,movimientos:int,errores:array<int,array<string,mixed>>} para la pantalla del agente */
    public static function estado(): array
    {
        $pdo = Db::pdo();
        return [
            'pendientes' => (int) $pdo->query('SELECT COUNT(*) FROM subdiario WHERE ' . self::PENDIENTE)->fetchColumn(),
            'con_error' => (int) $pdo->query("SELECT COUNT(*) FROM subdiario WHERE canal = 'W' AND vfp_error IS NOT NULL AND vfp_enviado IS NULL")->fetchColumn(),
            'movimientos' => (int) $pdo->query('SELECT COUNT(*) FROM stock_movimientos WHERE vfp_enviado IS NULL AND vfp_error IS NULL AND subdiario_id IS NULL')->fetchColumn(),
            'errores' => $pdo->query("SELECT fe_clave, tipo, letra, prefijo, comprobante, fecha, vfp_error FROM subdiario WHERE canal = 'W' AND vfp_error IS NOT NULL AND vfp_enviado IS NULL ORDER BY fe_clave DESC LIMIT 20")->fetchAll(),
        ];
    }
}
