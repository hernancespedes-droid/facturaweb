<?php
declare(strict_types=1);

namespace App\Modules\Articulos;

use App\Core\Db;
use PDO;

/**
 * Precios de venta calculados con un margen sobre el costo (0.49.0).
 *
 * DECISIÓN D-130: cada lista (1, 2, 3 y oferta) tiene un margen = % de recargo sobre el costo (costo 100 con 30 % → 130 sin IVA). Se busca en el
 * artículo, después en su rubro y después el general de Parámetros; vacío en los tres = esa lista no se calcula (queda el precio que tenga).
 * DECISIÓN D-131: el precio lleva el IVA del artículo solo si la empresa es responsable inscripta, el artículo es gravado y en Parámetros
 * «Los precios de venta incluyen IVA» está tildado (para monotributo y exento el costo ya es final y no se suma IVA). Por omisión esa casilla queda
 * como ya suponía «Costos y márgenes» (D-64): netos para el responsable inscripto, finales para el resto. El precio queda en la moneda del costo.
 * DECISIÓN D-132: el redondeo de Parámetros es siempre hacia arriba (para no perder margen); sin redondeo, a centavos.
 * DECISIÓN D-133: «Precio automático» del artículo = la ficha recalcula los precios al guardar y la actualización masiva lo propone. Los artículos
 * que ya existían quedan manuales. Al cambiar el costo por stock, compra o importador siempre se pregunta antes de tocar los precios.
 * Cada cambio de costo o precios queda en articulos_precios_historial.
 */
final class Precios
{
    /** Lista => [columna del precio en articulos, nombre]. Los márgenes van en margen_<lista> de parametros, rubros y articulos. */
    public const LISTAS = [
        'l1' => ['precio_de_venta_1', 'Lista 1'],
        'l2' => ['precio_de_venta_2', 'Lista 2'],
        'l3' => ['precio_de_venta_3', 'Lista 3'],
        'oferta' => ['precio_de_oferta', 'Oferta'],
    ];

    public const ORIGENES = ['FICHA' => 'Ficha del artículo', 'MASIVO' => 'Actualizar precios', 'STOCK' => 'Ingreso de stock', 'COMPRA' => 'Compra',
        'IMPORTACION' => 'Importación', 'AUMENTO' => 'Actualización masiva', 'DESHACER' => 'Deshacer actualización'];

    private PDO $pdo;
    /** @var array{margenes:array<string,?float>,redondeo:int,suma_iva:bool}|null */
    private ?array $config = null;
    /** @var array<string,array<string,?float>> */
    private array $rubros = [];
    /** @var array<string,float> */
    private array $iva = [];

    public function __construct(?PDO $pdo = null)
    {
        $this->pdo = $pdo ?? Db::pdo();
    }

    /** Márgenes generales, redondeo y si se suma el IVA (de Parámetros). @return array{margenes:array<string,?float>,redondeo:int,suma_iva:bool} */
    public function config(): array
    {
        if ($this->config === null) {
            $p = $this->pdo->query('SELECT iva, margen_l1, margen_l2, margen_l3, margen_oferta, precio_redondeo, precios_con_iva FROM parametros WHERE id = 1')->fetch(PDO::FETCH_ASSOC) ?: [];
            $m = [];
            foreach (array_keys(self::LISTAS) as $k) {
                $m[$k] = isset($p['margen_' . $k]) ? (float) $p['margen_' . $k] : null;
            }
            $ri = !in_array((int) ($p['iva'] ?? 1), [4, 7], true);
            $this->config = ['margenes' => $m, 'redondeo' => (int) ($p['precio_redondeo'] ?? 0), 'suma_iva' => $ri && (int) ($p['precios_con_iva'] ?? 1) === 1];
        }
        return $this->config;
    }

    /** Márgenes de un rubro (null = no tiene). @return array<string,?float> */
    public function margenesRubro(?string $rubro): array
    {
        $rubro = (string) $rubro;
        if (!isset($this->rubros[$rubro])) {
            $r = false;
            if ($rubro !== '') {
                $st = $this->pdo->prepare('SELECT margen_l1, margen_l2, margen_l3, margen_oferta FROM rubros WHERE codigo = ?');
                $st->execute([$rubro]);
                $r = $st->fetch(PDO::FETCH_ASSOC);
            }
            $this->rubros[$rubro] = [];
            foreach (array_keys(self::LISTAS) as $k) {
                $this->rubros[$rubro][$k] = is_array($r) && $r['margen_' . $k] !== null ? (float) $r['margen_' . $k] : null;
            }
        }
        return $this->rubros[$rubro];
    }

    /** Porcentaje de IVA de una alícuota (tasas_iva). */
    public function porcentajeIva(?string $alicuota): float
    {
        $a = (string) $alicuota;
        if (!isset($this->iva[$a])) {
            $st = $this->pdo->prepare('SELECT porcentaje FROM tasas_iva WHERE codigo = ?');
            $st->execute([$a]);
            $this->iva[$a] = (float) $st->fetchColumn();
        }
        return $this->iva[$a];
    }

    /** Precio con el margen, el IVA (si corresponde) y el redondeo. */
    public static function precio(float $costo, float $margen, float $ivaPct, int $redondeo): float
    {
        return self::redondear($costo * (1 + $margen / 100) * (1 + $ivaPct / 100), $redondeo);
    }

    /** Redondeo de Parámetros, siempre hacia arriba (D-132); sin redondeo, a centavos. */
    public static function redondear(float $p, int $redondeo): float
    {
        if ($redondeo > 0) {
            return (float) (ceil(round($p / $redondeo, 6)) * $redondeo);
        }
        return round($p, 2);
    }

    /** IVA que lleva el precio del artículo (0 si no se suma). */
    public function ivaDelPrecio(array $a): float
    {
        return $this->config()['suma_iva'] && (int) ($a['grabado'] ?? 1) === 1 ? $this->porcentajeIva($a['alicuota'] ?? null) : 0.0;
    }

    /**
     * Cómo quedarían los precios de un artículo con su costo (o con $costo, si se pasa). $a es la fila de articulos (o el formulario).
     *
     * @return array{costo:float,iva:float,cambia:bool,listas:array<string,array{nombre:string,actual:float,margen:?float,origen:?string,nuevo:?float,real:?float}>}
     */
    public function calcular(array $a, ?float $costo = null): array
    {
        $cfg = $this->config();
        $costo ??= (float) ($a['costo'] ?? 0);
        $rub = $this->margenesRubro($a['rubro'] ?? null);
        $iva = $this->ivaDelPrecio($a);
        $listas = [];
        $cambia = false;
        foreach (self::LISTAS as $k => [$col, $nombre]) {
            $art = $a['margen_' . $k] ?? null;
            [$m, $origen] = $art !== null && $art !== '' ? [(float) $art, 'artículo'] : ($rub[$k] !== null ? [$rub[$k], 'rubro'] : ($cfg['margenes'][$k] !== null ? [$cfg['margenes'][$k], 'general'] : [null, null]));
            $actual = (float) ($a[$col] ?? 0);
            $nuevo = $m !== null && $costo > 0 ? self::precio($costo, $m, $iva, $cfg['redondeo']) : null;
            if ($nuevo !== null && abs($nuevo - $actual) >= 0.005) {
                $cambia = true;
            }
            $listas[$k] = ['nombre' => $nombre, 'actual' => $actual, 'margen' => $m, 'origen' => $origen, 'nuevo' => $nuevo,
                'real' => self::margenReal($nuevo ?? $actual, $costo, $iva)];
        }
        return ['costo' => $costo, 'iva' => $iva, 'cambia' => $cambia, 'listas' => $listas];
    }

    /** Margen que deja un precio sobre el costo (sin IVA), o null sin costo. */
    public static function margenReal(float $precio, float $costo, float $ivaPct): ?float
    {
        if ($costo <= 0 || $precio <= 0) {
            return null;
        }
        return round(($precio / (1 + $ivaPct / 100) - $costo) / $costo * 100, 1);
    }

    /**
     * Graba costo y/o precios de un artículo, con la fila bloqueada y su historial. Usa la transacción de quien llama si hay una abierta.
     * $costo null = no cambia el costo; $precios = recalcular las listas que tienen margen.
     *
     * @return array{cambio:bool,antes:array<string,float>,despues:array<string,float>}
     */
    public function aplicar(string $codigo, ?float $costo, bool $precios, string $origen, ?int $usuarioId, string $detalle = ''): array
    {
        $propia = !$this->pdo->inTransaction();
        if ($propia) {
            $this->pdo->beginTransaction();
        }
        try {
            $st = $this->pdo->prepare('SELECT * FROM articulos WHERE codigo = ? FOR UPDATE');
            $st->execute([$codigo]);
            $a = $st->fetch(PDO::FETCH_ASSOC);
            if ($a === false) {
                throw new \RuntimeException('El artículo ' . $codigo . ' ya no existe.');
            }
            $antes = self::valores($a);
            $despues = $antes;
            if ($costo !== null) {
                $despues['costo'] = round($costo, 4);
            }
            if ($precios) {
                foreach ($this->calcular($a, $despues['costo'])['listas'] as $k => $l) {
                    if ($l['nuevo'] !== null) {
                        $despues[$k] = $l['nuevo'];
                    }
                }
            }
            $cambio = $this->registrar($codigo, $antes, $despues, $origen, $usuarioId, $detalle);
            if ($cambio) {
                $set = ['costo = ?'];
                $val = [$despues['costo']];
                foreach (self::LISTAS as $k => [$col]) {
                    $set[] = "`{$col}` = ?";
                    $val[] = $despues[$k];
                }
                $val[] = $codigo;
                $this->pdo->prepare('UPDATE articulos SET ' . implode(', ', $set) . ', actualizacion = NOW() WHERE codigo = ?')->execute($val);
            }
            if ($propia) {
                $this->pdo->commit();
            }
            return ['cambio' => $cambio, 'antes' => $antes, 'despues' => $despues];
        } catch (\Throwable $e) {
            if ($propia) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    /** Costo y precios de una fila de articulos. @return array<string,float> */
    public static function valores(array $a): array
    {
        $v = ['costo' => round((float) ($a['costo'] ?? 0), 4)];
        foreach (self::LISTAS as $k => [$col]) {
            $v[$k] = round((float) ($a[$col] ?? 0), 4);
        }
        return $v;
    }

    /**
     * Anota en el historial si algo cambió (no graba el artículo). $extra: actualizacion_id, automatico_anterior y automatico (actualización masiva, D-145).
     * @param array<string,float> $antes @param array<string,float> $despues @param array{actualizacion_id?:?int,automatico_anterior?:?int,automatico?:?int} $extra
     */
    public function registrar(string $codigo, array $antes, array $despues, string $origen, ?int $usuarioId, string $detalle = '', array $extra = []): bool
    {
        $cambio = false;
        foreach ($antes as $k => $v) {
            if (abs(($despues[$k] ?? $v) - $v) >= 0.00005) {
                $cambio = true;
            }
        }
        if (!$cambio) {
            return false;
        }
        $this->pdo->prepare('INSERT INTO articulos_precios_historial (articulo, fecha, usuario_id, origen, detalle, costo_anterior, costo, l1_anterior, l1, l2_anterior, l2,
                l3_anterior, l3, oferta_anterior, oferta, actualizacion_id, automatico_anterior, automatico) VALUES (?, NOW(), ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
            ->execute([$codigo, $usuarioId, $origen, mb_substr($detalle, 0, 160), $antes['costo'], $despues['costo'] ?? $antes['costo'],
                $antes['l1'], $despues['l1'] ?? $antes['l1'], $antes['l2'], $despues['l2'] ?? $antes['l2'], $antes['l3'], $despues['l3'] ?? $antes['l3'],
                $antes['oferta'], $despues['oferta'] ?? $antes['oferta'], $extra['actualizacion_id'] ?? null, $extra['automatico_anterior'] ?? null, $extra['automatico'] ?? null]);
        return true;
    }

    /** Últimos cambios de un artículo. @return array<int,array<string,mixed>> */
    public function historial(string $codigo, int $limite = 20): array
    {
        $st = $this->pdo->prepare("SELECT h.*, u.nombre AS usuario FROM articulos_precios_historial h LEFT JOIN usuarios u ON u.id = h.usuario_id
            WHERE h.articulo = ? ORDER BY h.id DESC LIMIT {$limite}");
        $st->execute([$codigo]);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Artículos para la pantalla «Actualizar precios», con lo que cambiaría. Filtros: q, rubro, proveedor, ver (automaticos|todos|cambian), codigos.
     *
     * @param array<string,mixed> $f
     * @return array{filas:array<int,array<string,mixed>>,total:int,limite:bool}
     */
    public function propuesta(array $f, int $limite = 1000): array
    {
        $w = ["a.estado = 'AC'"];
        $p = [];
        $q = trim((string) ($f['q'] ?? ''));
        if ($q !== '') {
            $like = '%' . addcslashes($q, '%_\\') . '%';
            $w[] = '(a.codigo LIKE ? OR a.descripcion LIKE ?)';
            array_push($p, $like, $like);
        }
        if (($f['rubro'] ?? '') !== '') {
            $w[] = 'a.rubro = ?';
            $p[] = (string) $f['rubro'];
        }
        if (($f['proveedor'] ?? '') !== '' && ctype_digit((string) $f['proveedor'])) {
            $w[] = 'a.proveedor = ?';
            $p[] = (int) $f['proveedor'];
        }
        $codigos = array_values(array_filter((array) ($f['codigos'] ?? []), 'is_string'));
        if ($codigos !== []) {
            $w[] = 'a.codigo IN (' . implode(', ', array_fill(0, count($codigos), '?')) . ')';
            array_push($p, ...$codigos);
        } elseif (($f['ver'] ?? 'automaticos') === 'automaticos') {
            $w[] = 'a.precio_automatico = 1';
        }
        $st = $this->pdo->prepare('SELECT a.*, r.descripcion AS rubro_nombre FROM articulos a LEFT JOIN rubros r ON r.codigo = a.rubro WHERE ' . implode(' AND ', $w)
            . ' ORDER BY a.descripcion, a.codigo LIMIT ' . ($limite + 1));
        $st->execute($p);
        $filas = [];
        $total = 0;
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $a) {
            if (++$total > $limite) {
                break;
            }
            $c = $this->calcular($a);
            if (($f['ver'] ?? '') === 'cambian' && !$c['cambia']) {
                continue;
            }
            $filas[] = ['a' => $a, 'calc' => $c];
        }
        return ['filas' => $filas, 'total' => count($filas), 'limite' => $total > $limite];
    }

    /** Recalcula los precios de los artículos elegidos (y opcionalmente los deja automáticos). @param array<int,string> $codigos @return int artículos cambiados */
    public function actualizar(array $codigos, bool $marcarAutomatico, ?int $usuarioId): int
    {
        $codigos = array_values(array_unique(array_filter($codigos, static fn ($c) => is_string($c) && $c !== '')));
        sort($codigos); // mismo orden de bloqueo siempre
        $n = 0;
        $this->pdo->beginTransaction();
        try {
            foreach ($codigos as $c) {
                if ($this->aplicar($c, null, true, 'MASIVO', $usuarioId)['cambio']) {
                    $n++;
                }
                if ($marcarAutomatico) {
                    $this->pdo->prepare('UPDATE articulos SET precio_automatico = 1 WHERE codigo = ?')->execute([$c]);
                }
            }
            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
        return $n;
    }

    /** Datos para que la ficha recalcule en el navegador (márgenes de los rubros, generales, IVA por alícuota, redondeo). @return array<string,mixed> */
    public function datosFicha(): array
    {
        $rub = [];
        foreach ($this->pdo->query('SELECT codigo, margen_l1, margen_l2, margen_l3, margen_oferta FROM rubros')->fetchAll(PDO::FETCH_ASSOC) as $r) {
            foreach (array_keys(self::LISTAS) as $k) {
                if ($r['margen_' . $k] !== null) {
                    $rub[(string) $r['codigo']][$k] = (float) $r['margen_' . $k];
                }
            }
        }
        $iva = [];
        foreach ($this->pdo->query('SELECT codigo, porcentaje FROM tasas_iva')->fetchAll(PDO::FETCH_ASSOC) as $t) {
            $iva[(string) $t['codigo']] = (float) $t['porcentaje'];
        }
        $cfg = $this->config();
        $cols = [];
        foreach (self::LISTAS as $k => [$col]) {
            $cols[$k] = $col;
        }
        return ['general' => $cfg['margenes'], 'rubros' => $rub, 'iva' => $iva, 'redondeo' => $cfg['redondeo'], 'suma_iva' => $cfg['suma_iva'], 'columnas' => $cols];
    }
}
