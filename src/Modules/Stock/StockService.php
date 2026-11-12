<?php
declare(strict_types=1);

namespace App\Modules\Stock;

use App\Core\Db;
use App\Modules\Configuracion\Configuracion;
use PDO;

/**
 * Stock: movimientos (historia) + saldos (caché en la misma transacción). Todo en unidades.
 *
 * - Rápido: el saldo de un artículo es UNA fila de stock_saldos; el historial solo se recorre en el kardex.
 * - Seguro con varios usuarios: cada movimiento hace INSERT ... ON DUPLICATE KEY UPDATE saldo = saldo + x (la fila queda bloqueada hasta
 *   el commit), así dos facturas simultáneas del mismo artículo no se pisan.
 * - DECISIÓN D-19 (Claude, a confirmar por Hernán): siempre se puede facturar sin stock (se avisa y sigue; el saldo puede quedar negativo);
 *   las facturas descuentan del depósito principal; las notas de crédito reponen (solo los renglones con artículo); las notas de débito no mueven.
 */
final class StockService
{
    public const ORIGENES = ['FC' => 'Factura', 'NC' => 'Nota de crédito', 'INGRESO' => 'Ingreso', 'EGRESO' => 'Egreso', 'AJUSTE' => 'Ajuste', 'TRANSF' => 'Transferencia'];

    private PDO $pdo;

    public function __construct(?PDO $pdo = null)
    {
        $this->pdo = $pdo ?? Db::pdo();
    }

    public static function activo(): bool
    {
        return Configuracion::activo('stock.controlar');
    }

    // ------------------------------------------------------------------ depósitos

    /** @return array<int,array<string,mixed>> */
    public function depositos(bool $soloActivos = true): array
    {
        return $this->pdo->query('SELECT * FROM depositos' . ($soloActivos ? ' WHERE activo = 1' : '') . ' ORDER BY principal DESC, nombre')->fetchAll();
    }

    public function depositoPrincipal(): int
    {
        $id = $this->pdo->query('SELECT id FROM depositos WHERE principal = 1 AND activo = 1 ORDER BY id LIMIT 1')->fetchColumn();
        if ($id === false) {
            $id = $this->pdo->query('SELECT id FROM depositos WHERE activo = 1 ORDER BY id LIMIT 1')->fetchColumn();
        }
        if ($id === false) {
            throw new \RuntimeException('No hay ningún depósito activo.');
        }
        return (int) $id;
    }

    /** @return array{ok:bool,error:?string} */
    public function guardarDeposito(?int $id, string $codigo, string $nombre, bool $principal, bool $activo, int $sucursalVfp = 1): array
    {
        $codigo = mb_strtoupper(trim($codigo));
        $nombre = trim($nombre);
        if (preg_match('/^[A-Z0-9_-]{1,10}$/', $codigo) !== 1 || $nombre === '' || mb_strlen($nombre) > 60) {
            return ['ok' => false, 'error' => 'Código de hasta 10 letras o números y un nombre de hasta 60 letras.'];
        }
        if ($sucursalVfp < 1 || $sucursalVfp > 99) {
            return ['ok' => false, 'error' => 'La sucursal del VFP es un número de 1 a 99.'];
        }
        $dup = $this->pdo->prepare('SELECT id FROM depositos WHERE codigo = ? AND id <> ?');
        $dup->execute([$codigo, $id ?? 0]);
        if ($dup->fetchColumn() !== false) {
            return ['ok' => false, 'error' => 'Ya existe un depósito con ese código.'];
        }
        if ($id !== null && !$activo && $principal) {
            return ['ok' => false, 'error' => 'El depósito principal no se puede desactivar.'];
        }
        $this->pdo->beginTransaction();
        try {
            if ($principal) {
                $this->pdo->exec('UPDATE depositos SET principal = 0');
            }
            if ($id === null) {
                $this->pdo->prepare('INSERT INTO depositos (codigo, nombre, principal, activo, sucursal_vfp) VALUES (?, ?, ?, 1, ?)')->execute([$codigo, $nombre, $principal ? 1 : 0, $sucursalVfp]);
            } else {
                $this->pdo->prepare('UPDATE depositos SET codigo = ?, nombre = ?, principal = IF(? = 1, 1, principal), activo = ?, sucursal_vfp = ? WHERE id = ?')
                    ->execute([$codigo, $nombre, $principal ? 1 : 0, $activo ? 1 : 0, $sucursalVfp, $id]);
            }
            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
        return ['ok' => true, 'error' => null];
    }

    // ------------------------------------------------------------------ movimientos

    /**
     * Registra un movimiento y actualiza el saldo. Si ya hay una transacción abierta (la factura) se suma a ella; si no, abre una.
     *
     * @param float $cantidad con signo, en unidades
     * @return int id del movimiento
     */
    public function mover(string $articulo, int $deposito, float $cantidad, string $origen, ?int $subdiarioId = null, ?int $usuarioId = null, ?string $motivo = null, ?string $grupo = null): int
    {
        $propia = !$this->pdo->inTransaction();
        if ($propia) {
            $this->pdo->beginTransaction();
        }
        try {
            $this->pdo->prepare('INSERT INTO stock_movimientos (articulo, deposito_id, fecha, cantidad, origen, subdiario_id, grupo, motivo, usuario_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)')
                ->execute([$articulo, $deposito, date('Y-m-d H:i:s'), round($cantidad, 3), $origen, $subdiarioId, $grupo, $motivo !== null ? mb_substr($motivo, 0, 255) : null, $usuarioId]);
            $id = (int) $this->pdo->lastInsertId();
            $this->pdo->prepare('INSERT INTO stock_saldos (articulo, deposito_id, saldo) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE saldo = saldo + VALUES(saldo)')
                ->execute([$articulo, $deposito, round($cantidad, 3)]);
            if ($propia) {
                $this->pdo->commit();
            }
            return $id;
        } catch (\Throwable $e) {
            if ($propia && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Lo que mueve un comprobante: las facturas descuentan y las notas de crédito reponen, del depósito principal y solo para renglones
     * con artículo que controla stock. Se llama DENTRO de la transacción del comprobante. No hace nada con el stock apagado.
     *
     * @param array<int,array<string,mixed>> $items renglones con 'articulo' y 'cantidad' (unidades)
     */
    public function porComprobante(string $tipo, int $subdiarioId, array $items, ?int $usuarioId): void
    {
        if (!self::activo() || !in_array($tipo, ['FC', 'NC'], true)) {
            return;
        }
        $dep = $this->depositoPrincipal();
        $signo = $tipo === 'FC' ? -1.0 : 1.0;
        $ctrl = $this->pdo->prepare('SELECT controla_stock FROM articulos WHERE codigo = ?');
        // Se ordena por artículo para que dos facturas simultáneas bloqueen las filas en el mismo orden (sin deadlocks).
        $acum = [];
        foreach ($items as $it) {
            $a = (string) ($it['articulo'] ?? '');
            if ($a === '') {
                continue;
            }
            $acum[$a] = ($acum[$a] ?? 0.0) + (float) $it['cantidad'];
        }
        ksort($acum);
        foreach ($acum as $a => $cant) {
            $ctrl->execute([$a]);
            if ((int) $ctrl->fetchColumn() !== 1) {
                continue;
            }
            $this->mover((string) $a, $dep, $signo * $cant, $tipo, $subdiarioId, $usuarioId);
        }
    }

    /**
     * Avisos para la pantalla Revisar: renglones que dejarían el saldo del depósito principal en negativo. No bloquea.
     *
     * @param array<int,array<string,mixed>> $items
     * @return string[]
     */
    public function advertencias(string $tipo, array $items): array
    {
        if (!self::activo() || $tipo !== 'FC') {
            return [];
        }
        $dep = $this->depositoPrincipal();
        $acum = [];
        foreach ($items as $it) {
            if (($it['articulo'] ?? '') !== '') {
                $acum[(string) $it['articulo']] = ($acum[(string) $it['articulo']] ?? 0.0) + (float) $it['cantidad'];
            }
        }
        $out = [];
        $q = $this->pdo->prepare('SELECT a.controla_stock, COALESCE(s.saldo, 0) FROM articulos a LEFT JOIN stock_saldos s ON s.articulo = a.codigo AND s.deposito_id = ? WHERE a.codigo = ?');
        foreach ($acum as $a => $cant) {
            $q->execute([$dep, $a]);
            $f = $q->fetch(PDO::FETCH_NUM);
            if ($f !== false && (int) $f[0] === 1 && (float) $f[1] < $cant) {
                $out[] = "{$a}: se piden " . rtrim(rtrim(number_format($cant, 3, ',', '.'), '0'), ',') . ' y hay ' . rtrim(rtrim(number_format((float) $f[1], 3, ',', '.'), '0'), ',') . ' (se factura igual).';
            }
        }
        return $out;
    }

    // ------------------------------------------------------------------ cargas manuales

    /** Convierte lo que se escribió (unidades o bultos) a unidades. @return array{0:?float,1:?string} [cantidad, error] */
    public function aUnidades(string $articulo, float $cantidad, string $unidad): array
    {
        if ($unidad !== 'bultos') {
            return [$cantidad, null];
        }
        $q = $this->pdo->prepare('SELECT unidades_por_bulto FROM articulos WHERE codigo = ?');
        $q->execute([$articulo]);
        $u = (int) $q->fetchColumn();
        return $u > 0 ? [$cantidad * $u, null] : [null, 'El artículo no tiene cargadas las unidades por bulto.'];
    }

    /** Busca el artículo por código, EAN-13, DUN-14 o SKU (lo que se escribió o leyó). @return array<string,mixed>|null */
    public function resolverArticulo(string $q): ?array
    {
        $q = trim($q);
        if ($q === '') {
            return null;
        }
        $st = $this->pdo->prepare('SELECT * FROM articulos WHERE codigo = ? OR ean13 = ? OR dun14 = ? OR sku = ? OR codigo_de_barras = ? ORDER BY codigo = ? DESC LIMIT 2');
        $st->execute([mb_strtoupper($q), $q, $q, $q, $q, mb_strtoupper($q)]);
        $f = $st->fetchAll();
        if (count($f) === 2 && mb_strtoupper($q) !== $f[0]['codigo']) {
            return null; // ambiguo
        }
        return $f[0] ?? null;
    }

    public function saldo(string $articulo, ?int $deposito = null): float
    {
        $st = $deposito === null
            ? $this->pdo->prepare('SELECT COALESCE(SUM(saldo), 0) FROM stock_saldos WHERE articulo = ?')
            : $this->pdo->prepare('SELECT COALESCE(SUM(saldo), 0) FROM stock_saldos WHERE articulo = ? AND deposito_id = ?');
        $st->execute($deposito === null ? [$articulo] : [$articulo, $deposito]);
        return (float) $st->fetchColumn();
    }

    /**
     * Ingreso, egreso o ajuste (llevar el saldo a una cantidad contada).
     *
     * @return array{ok:bool,error:?string}
     */
    public function cargar(string $modo, string $articuloTxt, int $deposito, string $cantidadTxt, string $unidad, string $motivo, ?int $usuarioId): array
    {
        $art = $this->resolverArticulo($articuloTxt);
        if ($art === null) {
            return ['ok' => false, 'error' => 'No encontré un artículo único con ese código, código de barras o SKU.'];
        }
        $cant = \App\Core\Valida::decimal($cantidadTxt);
        if ($cant === null || $cant < 0 || $cant > 999999999 || ($modo !== 'AJUSTE' && $cant <= 0)) {
            return ['ok' => false, 'error' => 'La cantidad no es válida.'];
        }
        [$u, $err] = $this->aUnidades((string) $art['codigo'], $cant, $unidad);
        if ($u === null) {
            return ['ok' => false, 'error' => $err];
        }
        if (!in_array($deposito, array_map(static fn ($d) => (int) $d['id'], $this->depositos()), true)) {
            return ['ok' => false, 'error' => 'Elegí un depósito.'];
        }
        $this->pdo->beginTransaction();
        try {
            $mov = match ($modo) {
                'INGRESO' => $u,
                'EGRESO' => -$u,
                'AJUSTE' => $u - $this->saldoBloqueado((string) $art['codigo'], $deposito), // lleva el saldo a lo contado
                default => throw new \InvalidArgumentException('Movimiento no válido.'),
            };
            if (abs($mov) > 0.0004) {
                $this->mover((string) $art['codigo'], $deposito, $mov, $modo, null, $usuarioId, $motivo !== '' ? $motivo : ($modo === 'AJUSTE' ? 'Ajuste por conteo' : null));
            }
            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
        return ['ok' => true, 'error' => null];
    }

    private function saldoBloqueado(string $articulo, int $deposito): float
    {
        $st = $this->pdo->prepare('SELECT saldo FROM stock_saldos WHERE articulo = ? AND deposito_id = ? FOR UPDATE');
        $st->execute([$articulo, $deposito]);
        return (float) ($st->fetchColumn() ?: 0);
    }

    /** @return array{ok:bool,error:?string} */
    public function transferir(string $articuloTxt, int $origen, int $destino, string $cantidadTxt, string $unidad, string $motivo, ?int $usuarioId): array
    {
        $art = $this->resolverArticulo($articuloTxt);
        if ($art === null) {
            return ['ok' => false, 'error' => 'No encontré un artículo único con ese código, código de barras o SKU.'];
        }
        $cant = \App\Core\Valida::decimal($cantidadTxt);
        if ($cant === null || $cant <= 0 || $cant > 999999999) {
            return ['ok' => false, 'error' => 'La cantidad no es válida.'];
        }
        if ($origen === $destino) {
            return ['ok' => false, 'error' => 'El origen y el destino tienen que ser distintos.'];
        }
        $ids = array_map(static fn ($d) => (int) $d['id'], $this->depositos());
        if (!in_array($origen, $ids, true) || !in_array($destino, $ids, true)) {
            return ['ok' => false, 'error' => 'Elegí los dos depósitos.'];
        }
        [$u, $err] = $this->aUnidades((string) $art['codigo'], $cant, $unidad);
        if ($u === null) {
            return ['ok' => false, 'error' => $err];
        }
        $g = bin2hex(random_bytes(16));
        $this->pdo->beginTransaction();
        try {
            $this->mover((string) $art['codigo'], $origen, -$u, 'TRANSF', null, $usuarioId, $motivo !== '' ? $motivo : null, $g);
            $this->mover((string) $art['codigo'], $destino, $u, 'TRANSF', null, $usuarioId, $motivo !== '' ? $motivo : null, $g);
            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
        return ['ok' => true, 'error' => null];
    }

    // ------------------------------------------------------------------ consultas

    /**
     * Saldos por artículo (todos los depósitos o uno). @param array<string,string> $f q, deposito, bajo (solo bajo el mínimo), todos (incluye sin movimientos)
     * @return array{filas:array<int,array<string,mixed>>,total:int}
     */
    public function saldos(array $f, int $pagina, int $porPagina): array
    {
        $w = ["a.estado = 'AC'", 'a.controla_stock = 1'];
        $p = [];
        $dep = ctype_digit((string) ($f['deposito'] ?? '')) ? (int) $f['deposito'] : 0;
        $q = trim((string) ($f['q'] ?? ''));
        if ($q !== '') {
            $like = '%' . addcslashes($q, '%_\\') . '%';
            $w[] = '(a.codigo LIKE ? OR a.descripcion LIKE ? OR a.sku LIKE ? OR a.ean13 = ? OR a.dun14 = ?)';
            array_push($p, $like, $like, $like, $q, $q);
        }
        $join = 'LEFT JOIN stock_saldos s ON s.articulo = a.codigo' . ($dep > 0 ? ' AND s.deposito_id = ' . $dep : '');
        $having = [];
        if (($f['bajo'] ?? '') === '1') {
            $having[] = 'saldo < a.stock_minimo_unidades';
        }
        if (($f['todos'] ?? '') !== '1') {
            $having[] = 'saldo <> 0';
        }
        $h = $having === [] ? '' : ' HAVING ' . implode(' AND ', $having);
        $base = 'FROM articulos a ' . $join . ' WHERE ' . implode(' AND ', $w) . ' GROUP BY a.codigo' . $h;
        $cn = $this->pdo->prepare("SELECT COUNT(*) FROM (SELECT a.codigo, COALESCE(SUM(s.saldo), 0) AS saldo {$base}) t");
        $cn->execute($p);
        $st = $this->pdo->prepare("SELECT a.codigo, a.descripcion, a.sku, a.unidades_por_bulto, a.stock_minimo_unidades AS minimo, COALESCE(SUM(s.saldo), 0) AS saldo {$base}
            ORDER BY a.descripcion LIMIT {$porPagina} OFFSET " . (max(1, $pagina) - 1) * $porPagina);
        $st->execute($p);
        return ['filas' => $st->fetchAll(), 'total' => (int) $cn->fetchColumn()];
    }

    /** Saldo por depósito de un artículo. @return array<int,array<string,mixed>> */
    public function porDeposito(string $articulo): array
    {
        $st = $this->pdo->prepare('SELECT d.nombre, d.codigo, COALESCE(s.saldo, 0) AS saldo FROM depositos d LEFT JOIN stock_saldos s ON s.deposito_id = d.id AND s.articulo = ? WHERE d.activo = 1 ORDER BY d.principal DESC, d.nombre');
        $st->execute([$articulo]);
        return $st->fetchAll();
    }

    /** Kardex: movimientos de un artículo, el más nuevo primero. @return array{filas:array<int,array<string,mixed>>,total:int} */
    public function kardex(string $articulo, int $pagina, int $porPagina): array
    {
        $cn = $this->pdo->prepare('SELECT COUNT(*) FROM stock_movimientos WHERE articulo = ?');
        $cn->execute([$articulo]);
        $st = $this->pdo->prepare("SELECT m.*, d.nombre AS deposito, u.usuario FROM stock_movimientos m JOIN depositos d ON d.id = m.deposito_id LEFT JOIN usuarios u ON u.id = m.usuario_id
            WHERE m.articulo = ? ORDER BY m.id DESC LIMIT {$porPagina} OFFSET " . (max(1, $pagina) - 1) * $porPagina);
        $st->execute([$articulo]);
        return ['filas' => $st->fetchAll(), 'total' => (int) $cn->fetchColumn()];
    }

    /** Reconstruye los saldos sumando los movimientos (por si algún día no coinciden). @return int saldos que estaban mal */
    public function recalcular(): int
    {
        $this->pdo->beginTransaction();
        try {
            $mal = (int) $this->pdo->query('SELECT COUNT(*) FROM (
                SELECT m.articulo, m.deposito_id, SUM(m.cantidad) AS s FROM stock_movimientos m GROUP BY m.articulo, m.deposito_id) t
                LEFT JOIN stock_saldos x ON x.articulo = t.articulo AND x.deposito_id = t.deposito_id WHERE x.saldo IS NULL OR x.saldo <> t.s')->fetchColumn();
            $this->pdo->exec('DELETE FROM stock_saldos');
            $this->pdo->exec('INSERT INTO stock_saldos (articulo, deposito_id, saldo) SELECT articulo, deposito_id, SUM(cantidad) FROM stock_movimientos GROUP BY articulo, deposito_id');
            $this->pdo->commit();
            return $mal;
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }
}
