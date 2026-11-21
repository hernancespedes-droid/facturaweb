<?php
declare(strict_types=1);

namespace App\Modules\Articulos;

use App\Core\Db;
use App\Core\Valida;
use PDO;

/**
 * Actualización masiva de precios por filtro (0.60.0), como «Actualización de Precios» del VFP: desde/hasta código, proveedor, rubro y subrubro;
 * qué se actualiza (una lista, todas las listas, el costo, o todas las listas y el costo) y cómo (coeficiente, porcentaje o monto fijo).
 * Sirve para aumentar por inflación aunque el costo no cambie.
 *
 * DECISIÓN D-144: coeficiente = precio × valor; porcentaje = precio × (1 + valor / 100) (negativo baja); monto fijo = precio + valor, solo en artículos
 * en pesos (los de dólares se dejan afuera y se avisa). Los precios se redondean con el redondeo de Parámetros hacia arriba (D-132; se puede apagar)
 * y el costo queda a 4 decimales sin redondeo. Un precio en 0 sigue en 0 (no se inventan precios) y si el resultado queda en cero o negativo el
 * artículo no se toca. Solo artículos activos. Se ve todo en una vista previa y se graba en una sola transacción, con las filas bloqueadas y
 * revisando que lo que se confirma sea lo mismo que se vio.
 * DECISIÓN D-145: convivencia con «Precio automático» (D-133):
 *  - Actualizar el costo (solo o con las listas) en un artículo automático recalcula sus listas con el costo nuevo y los márgenes (las listas sin
 *    margen siguen la fórmula); el artículo sigue automático.
 *  - Actualizar solo listas en un artículo automático: por omisión se aumenta y el artículo pasa a manual (si no, el próximo cambio de costo
 *    pisaría el aumento); la otra opción es dejar afuera los automáticos. La vista previa muestra cuántos pasan a manual.
 *  - En un artículo manual, «Precio de costo» cambia solo el costo (los precios quedan) y «Todas las listas y costo» aplica la fórmula a todo.
 * DECISIÓN D-146: cada actualización queda en precios_actualizaciones y cada artículo en articulos_precios_historial (origen AUMENTO). Se puede
 * deshacer la última que siga vigente: vuelve costo, listas y «Precio automático» de cada artículo, salvo los que alguien cambió después (esos se
 * dejan como están y se informan). Deshacer una deja disponible la anterior.
 */
final class ActualizacionMasiva
{
    public const QUE = ['lista' => 'Una lista', 'listas' => 'Todas las listas', 'costo' => 'Precio de costo', 'todo' => 'Todas las listas y el costo'];
    public const FORMAS = ['coeficiente' => 'Coeficiente', 'porcentaje' => 'Porcentaje', 'monto' => 'Monto fijo'];
    public const AUTOMATICOS = ['manual' => 'Aumentarlos y pasarlos a precio manual', 'excluir' => 'Dejarlos afuera (siguen con su margen)'];
    /** Campos de filtro: desde/hasta. */
    public const FILTROS = ['codigo' => 'Código', 'proveedor' => 'Proveedor', 'rubro' => 'Rubro', 'subrubro' => 'Sub rubro'];

    private PDO $pdo;
    private Precios $precios;

    public function __construct(?PDO $pdo = null)
    {
        $this->pdo = $pdo ?? Db::pdo();
        $this->precios = new Precios($this->pdo);
    }

    /**
     * Lee y valida lo que llega del formulario.
     *
     * @param array<string,mixed> $in
     * @return array{d:array<string,mixed>,errores:array<string,string>}
     */
    public static function leer(array $in): array
    {
        $s = static fn (string $k): string => is_string($in[$k] ?? null) ? trim((string) $in[$k]) : '';
        $d = [];
        $errores = [];
        foreach (array_keys(self::FILTROS) as $k) {
            $d[$k . '_desde'] = mb_strtoupper($s($k . '_desde'));
            $d[$k . '_hasta'] = mb_strtoupper($s($k . '_hasta'));
            if ($k === 'proveedor') {
                foreach (['desde', 'hasta'] as $x) {
                    if ($d['proveedor_' . $x] !== '' && !ctype_digit($d['proveedor_' . $x])) {
                        $errores['proveedor_' . $x] = 'El proveedor es un código numérico.';
                    }
                }
            }
            if ($d[$k . '_desde'] !== '' && $d[$k . '_hasta'] !== '' && !isset($errores[$k . '_desde'], $errores[$k . '_hasta'])
                && ($k === 'proveedor' ? (int) $d[$k . '_desde'] > (int) $d[$k . '_hasta'] : strcmp($d[$k . '_desde'], $d[$k . '_hasta']) > 0)) {
                $errores[$k . '_hasta'] = '«Hasta» tiene que ser mayor o igual que «Desde».';
            }
        }
        $d['que'] = array_key_exists($s('que'), self::QUE) ? $s('que') : 'lista';
        $d['lista'] = array_key_exists($s('lista'), Precios::LISTAS) ? $s('lista') : 'l1';
        $d['forma'] = array_key_exists($s('forma'), self::FORMAS) ? $s('forma') : 'porcentaje';
        $d['automaticos'] = array_key_exists($s('automaticos'), self::AUTOMATICOS) ? $s('automaticos') : 'manual';
        $d['redondear'] = !isset($in['enviado']) || ($in['redondear'] ?? '') === '1';
        $d['valor_texto'] = $s('valor');
        $v = Valida::decimal($d['valor_texto']);
        $d['valor'] = $v;
        if ($d['valor_texto'] === '') {
            $errores['valor'] = 'Escribí el ' . mb_strtolower(self::FORMAS[$d['forma']]) . '.';
        } elseif ($v === null) {
            $errores['valor'] = 'No es un número válido (usá coma para los decimales).';
        } elseif ($d['forma'] === 'coeficiente' && ($v <= 0 || $v > 100)) {
            $errores['valor'] = 'El coeficiente va entre 0 y 100 (1,10 = 10 % más; 0,95 = 5 % menos).';
        } elseif ($d['forma'] === 'porcentaje' && ($v <= -100 || $v > 10000)) {
            $errores['valor'] = 'El porcentaje va de -99,99 a 10.000.';
        } elseif (abs($v) < 0.0000001 || ($d['forma'] === 'coeficiente' && abs($v - 1) < 0.0000001)) {
            $errores['valor'] = 'Con ese valor no cambia ningún precio.';
        }
        return ['d' => $d, 'errores' => $errores];
    }

    /** Aplica la forma de actualización a un importe. */
    public static function formula(float $v, string $forma, float $valor): float
    {
        return match ($forma) {
            'coeficiente' => $v * $valor,
            'porcentaje' => $v * (1 + $valor / 100),
            default => $v + $valor,
        };
    }

    /** Resumen legible (para el registro y la confirmación). @param array<string,mixed> $d */
    public static function descripcion(array $d): string
    {
        $que = $d['que'] === 'lista' ? Precios::LISTAS[$d['lista']][1] : self::QUE[$d['que']];
        $n = number_format((float) $d['valor'], $d['forma'] === 'coeficiente' ? 4 : 2, ',', '.');
        $n = rtrim(rtrim($n, '0'), ',');
        $como = match ($d['forma']) {
            'coeficiente' => '× ' . $n,
            'porcentaje' => ((float) $d['valor'] > 0 ? '+' : '') . $n . ' %',
            default => ((float) $d['valor'] > 0 ? '+ $ ' : '− $ ') . ltrim($n, '-'),
        };
        $f = [];
        foreach (self::FILTROS as $k => $t) {
            [$a, $b] = [$d[$k . '_desde'], $d[$k . '_hasta']];
            if ($a !== '' || $b !== '') {
                $f[] = $t . ' ' . ($a === $b ? $a : ($a !== '' ? $a : '…') . ' a ' . ($b !== '' ? $b : '…'));
            }
        }
        return $que . ' ' . $como . ($f === [] ? ' · todos los artículos' : ' · ' . implode(', ', $f));
    }

    /** WHERE del filtro. @param array<string,mixed> $d @return array{0:string,1:array<int,mixed>} */
    private static function where(array $d): array
    {
        $w = ["a.estado = 'AC'"];
        $p = [];
        foreach (array_keys(self::FILTROS) as $k) {
            foreach (['desde' => '>=', 'hasta' => '<='] as $x => $op) {
                $v = $d[$k . '_' . $x];
                if ($v === '') {
                    continue;
                }
                $w[] = $k === 'proveedor' ? "COALESCE(a.proveedor, 0) {$op} ?" : "COALESCE(a.`{$k}`, '') {$op} ?";
                $p[] = $k === 'proveedor' ? (int) $v : $v;
            }
        }
        return [implode(' AND ', $w), $p];
    }

    /**
     * Lo que le pasaría a un artículo.
     *
     * @param array<string,mixed> $a fila de articulos
     * @param array<string,mixed> $d
     * @return array{antes:array<string,float>,despues:array<string,float>,a_manual:bool,motivo:?string,recalculadas:array<int,string>}
     */
    public function plan(array $a, array $d): array
    {
        $antes = Precios::valores($a);
        $despues = $antes;
        $auto = (int) ($a['precio_automatico'] ?? 0) === 1;
        $listas = match ($d['que']) {
            'lista' => [$d['lista']],
            'costo' => [],
            default => array_keys(Precios::LISTAS),
        };
        $tocaCosto = in_array($d['que'], ['costo', 'todo'], true);
        $r = ['antes' => $antes, 'despues' => $antes, 'a_manual' => false, 'motivo' => null, 'recalculadas' => []];
        $valor = (float) $d['valor'];
        if ($d['forma'] === 'monto' && ($a['moneda'] ?? '$') === 'u$s') {
            return ['motivo' => 'En dólares: el monto fijo es en pesos'] + $r;
        }
        if ($auto && !$tocaCosto && $d['automaticos'] === 'excluir') {
            return ['motivo' => 'Precio automático (se dejó afuera)'] + $r;
        }
        $redondeo = $d['redondear'] ? (int) $this->precios->config()['redondeo'] : 0;
        if ($tocaCosto) {
            if ($antes['costo'] <= 0) {
                if ($d['que'] === 'costo') {
                    return ['motivo' => 'Sin costo'] + $r;
                }
            } else {
                $c = round(self::formula($antes['costo'], $d['forma'], $valor), 4);
                if ($c <= 0) {
                    return ['motivo' => 'El costo quedaría en cero o negativo'] + $r;
                }
                $despues['costo'] = $c;
                if ($auto) {
                    // D-145: el automático recalcula sus listas con el costo nuevo (y no se le aplica la fórmula a esas listas)
                    foreach ($this->precios->calcular($a, $c)['listas'] as $k => $l) {
                        if ($l['nuevo'] !== null) {
                            $despues[$k] = $l['nuevo'];
                            $r['recalculadas'][] = $k;
                        }
                    }
                }
            }
        }
        foreach ($listas as $k) {
            if (in_array($k, $r['recalculadas'], true) || $antes[$k] <= 0) {
                continue; // un precio en 0 sigue en 0 (D-144)
            }
            $n = self::formula($antes[$k], $d['forma'], $valor);
            if ($n <= 0) {
                return ['motivo' => Precios::LISTAS[$k][1] . ' quedaría en cero o negativo'] + $r;
            }
            $despues[$k] = $redondeo > 0 ? Precios::redondear($n, $redondeo) : round($n, 2);
        }
        $cambia = false;
        foreach ($antes as $k => $v) {
            if (abs($despues[$k] - $v) >= 0.00005) {
                $cambia = true;
            }
        }
        if (!$cambia) {
            return ['motivo' => 'No cambia'] + $r;
        }
        $r['despues'] = $despues;
        $r['a_manual'] = $auto && (!$tocaCosto || $antes['costo'] <= 0); // sin costo, el automático no tiene de dónde recalcular
        return $r;
    }

    /**
     * Vista previa: cuántos cambian, cuántos pasan a manual, los que no se tocan y por qué, y una muestra para ver.
     *
     * @param array<string,mixed> $d
     * @return array{filas:array<int,array<string,mixed>>,cambian:int,a_manual:int,salteados:array<string,array<int,string>>,sin_cambio:int,firma:string,
     *               limite:bool,total:int,suba:array{antes:float,despues:float}}
     */
    public function simular(array $d, int $muestra = 300): array
    {
        return $this->recorrer($d, false, $muestra);
    }

    /**
     * @param array<string,mixed> $d
     * @return array<string,mixed>
     */
    private function recorrer(array $d, bool $bloquear, int $muestra): array
    {
        [$w, $p] = self::where($d);
        $st = $this->pdo->prepare('SELECT a.*, r.descripcion AS rubro_nombre FROM articulos a LEFT JOIN rubros r ON r.codigo = a.rubro WHERE ' . $w
            . ' ORDER BY a.codigo' . ($bloquear ? ' FOR UPDATE' : ''));
        $st->execute($p);
        $out = ['filas' => [], 'planes' => [], 'cambian' => 0, 'a_manual' => 0, 'salteados' => [], 'sin_cambio' => 0, 'limite' => false, 'total' => 0,
            'suba' => ['antes' => 0.0, 'despues' => 0.0]];
        $firma = [];
        while (($a = $st->fetch(PDO::FETCH_ASSOC)) !== false) {
            $out['total']++;
            $pl = $this->plan($a, $d);
            if ($pl['motivo'] !== null) {
                if ($pl['motivo'] === 'No cambia') {
                    $out['sin_cambio']++;
                } else {
                    $out['salteados'][$pl['motivo']][] = (string) $a['codigo'];
                }
                continue;
            }
            $out['cambian']++;
            $out['a_manual'] += $pl['a_manual'] ? 1 : 0;
            $k = $d['que'] === 'costo' ? 'costo' : ($d['que'] === 'lista' ? $d['lista'] : 'l1');
            $out['suba']['antes'] += $pl['antes'][$k];
            $out['suba']['despues'] += $pl['despues'][$k];
            $firma[] = [(string) $a['codigo'], $pl['despues'], $pl['a_manual']];
            if ($bloquear) {
                $out['planes'][] = ['a' => $a, 'plan' => $pl];
            } elseif (count($out['filas']) < $muestra) {
                $out['filas'][] = ['a' => $a, 'plan' => $pl];
            } else {
                $out['limite'] = true;
            }
        }
        $out['firma'] = hash('sha256', json_encode($firma, JSON_PRESERVE_ZERO_FRACTION) ?: '');
        return $out;
    }

    /**
     * Graba la actualización si coincide con la vista previa ($firma). Todo o nada.
     *
     * @param array<string,mixed> $d
     * @return array{ok:bool,id:?int,cambiados:int,a_manual:int,error:?string}
     */
    public function confirmar(array $d, string $firma, ?int $usuarioId): array
    {
        $this->pdo->beginTransaction();
        try {
            $r = $this->recorrer($d, true, 0);
            if (!hash_equals($r['firma'], $firma)) {
                $this->pdo->rollBack();
                return ['ok' => false, 'id' => null, 'cambiados' => 0, 'a_manual' => 0,
                    'error' => 'Algo cambió desde la vista previa (otro usuario tocó artículos o precios). Revisá la vista previa de nuevo y confirmá.'];
            }
            if ($r['cambian'] === 0) {
                $this->pdo->rollBack();
                return ['ok' => false, 'id' => null, 'cambiados' => 0, 'a_manual' => 0, 'error' => 'No hay precios para cambiar con esos filtros.'];
            }
            $guardar = $d;
            unset($guardar['valor_texto']);
            $this->pdo->prepare('INSERT INTO precios_actualizaciones (fecha, usuario_id, descripcion, parametros, articulos, a_manual) VALUES (NOW(), ?, ?, ?, ?, ?)')
                ->execute([$usuarioId, mb_substr(self::descripcion($d), 0, 255), json_encode($guardar, JSON_UNESCAPED_UNICODE), $r['cambian'], $r['a_manual']]);
            $id = (int) $this->pdo->lastInsertId();
            $detalle = mb_substr('#' . $id . ' ' . self::descripcion($d), 0, 160);
            foreach ($r['planes'] as $x) {
                $a = $x['a'];
                $pl = $x['plan'];
                $autoAntes = (int) $a['precio_automatico'];
                $autoDespues = $pl['a_manual'] ? 0 : $autoAntes;
                $this->grabar((string) $a['codigo'], $pl['despues'], $autoDespues);
                $this->precios->registrar((string) $a['codigo'], $pl['antes'], $pl['despues'], 'AUMENTO', $usuarioId, $detalle,
                    ['actualizacion_id' => $id, 'automatico_anterior' => $autoAntes, 'automatico' => $autoDespues]);
            }
            $this->pdo->commit();
            return ['ok' => true, 'id' => $id, 'cambiados' => $r['cambian'], 'a_manual' => $r['a_manual'], 'error' => null];
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    /** @param array<string,float> $v */
    private function grabar(string $codigo, array $v, int $automatico): void
    {
        $set = ['costo = ?', 'precio_automatico = ?'];
        $val = [$v['costo'], $automatico];
        foreach (Precios::LISTAS as $k => [$col]) {
            $set[] = "`{$col}` = ?";
            $val[] = $v[$k];
        }
        $val[] = $codigo;
        $this->pdo->prepare('UPDATE articulos SET ' . implode(', ', $set) . ', actualizacion = NOW() WHERE codigo = ?')->execute($val);
    }

    /** Últimas actualizaciones. @return array<int,array<string,mixed>> */
    public function registro(int $limite = 15): array
    {
        return $this->pdo->query("SELECT pa.*, u.nombre AS usuario, d.nombre AS deshizo FROM precios_actualizaciones pa
            LEFT JOIN usuarios u ON u.id = pa.usuario_id LEFT JOIN usuarios d ON d.id = pa.deshecha_por ORDER BY pa.id DESC LIMIT " . max(1, $limite))
            ->fetchAll(PDO::FETCH_ASSOC);
    }

    /** La que se puede deshacer: la última que sigue vigente. */
    public function deshacible(): ?int
    {
        $id = $this->pdo->query('SELECT MAX(id) FROM precios_actualizaciones WHERE deshecha_en IS NULL')->fetchColumn();
        return $id === null || $id === false ? null : (int) $id;
    }

    /**
     * Deshace la actualización $id (tiene que ser la última vigente). Los artículos que cambiaron después quedan como están.
     *
     * @return array{ok:bool,deshechos:int,no_deshechos:array<int,string>,error:?string}
     */
    public function deshacer(int $id, ?int $usuarioId): array
    {
        $this->pdo->beginTransaction();
        try {
            $st = $this->pdo->prepare('SELECT * FROM precios_actualizaciones WHERE id = ? FOR UPDATE');
            $st->execute([$id]);
            $pa = $st->fetch(PDO::FETCH_ASSOC);
            $ultima = $this->deshacible();
            if ($pa === false || $pa['deshecha_en'] !== null || $ultima !== $id) {
                $this->pdo->rollBack();
                return ['ok' => false, 'deshechos' => 0, 'no_deshechos' => [], 'error' => 'Solo se puede deshacer la última actualización que sigue vigente.'];
            }
            $h = $this->pdo->prepare('SELECT * FROM articulos_precios_historial WHERE actualizacion_id = ? AND origen = ? ORDER BY articulo');
            $h->execute([$id, 'AUMENTO']);
            $art = $this->pdo->prepare('SELECT * FROM articulos WHERE codigo = ? FOR UPDATE');
            $n = 0;
            $no = [];
            foreach ($h->fetchAll(PDO::FETCH_ASSOC) as $x) {
                $art->execute([$x['articulo']]);
                $a = $art->fetch(PDO::FETCH_ASSOC);
                if ($a === false) {
                    $no[] = (string) $x['articulo'];
                    continue;
                }
                $ahora = Precios::valores($a);
                $dejo = ['costo' => (float) $x['costo']];
                $previo = ['costo' => (float) $x['costo_anterior']];
                foreach (array_keys(Precios::LISTAS) as $k) {
                    $dejo[$k] = (float) $x[$k];
                    $previo[$k] = (float) $x[$k . '_anterior'];
                }
                $igual = (int) $a['precio_automatico'] === (int) ($x['automatico'] ?? $a['precio_automatico']);
                foreach ($dejo as $k => $v) {
                    if (abs($ahora[$k] - $v) >= 0.00005) {
                        $igual = false;
                    }
                }
                if (!$igual) {
                    $no[] = (string) $x['articulo'];
                    continue;
                }
                $autoPrevio = $x['automatico_anterior'] === null ? (int) $a['precio_automatico'] : (int) $x['automatico_anterior'];
                $this->grabar((string) $x['articulo'], $previo, $autoPrevio);
                $this->precios->registrar((string) $x['articulo'], $ahora, $previo, 'DESHACER', $usuarioId, mb_substr('Deshace #' . $id . ' ' . $pa['descripcion'], 0, 160),
                    ['actualizacion_id' => $id, 'automatico_anterior' => (int) $a['precio_automatico'], 'automatico' => $autoPrevio]);
                $n++;
            }
            $this->pdo->prepare('UPDATE precios_actualizaciones SET deshecha_en = NOW(), deshecha_por = ?, deshechos = ?, no_deshechos = ? WHERE id = ?')
                ->execute([$usuarioId, $n, count($no), $id]);
            $this->pdo->commit();
            return ['ok' => true, 'deshechos' => $n, 'no_deshechos' => $no, 'error' => null];
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }
}
