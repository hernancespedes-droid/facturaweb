<?php
declare(strict_types=1);

namespace App\Modules\Articulos;

use App\Core\Db;
use App\Core\Orden;
use App\Core\Validador;
use PDO;

/**
 * Maestro de artículos (VFP MB, articulos.scx) sobre la tabla articulos del esquema O&S. El código lo escribe el
 * usuario (como en el VFP), en mayúsculas, y no se cambia después. No se borra: se da de baja (estado BA).
 * Los campos de producción textil quedan en la tabla pero no se muestran.
 */
final class ArticulosService
{
    public const ESTADOS = ['AC' => 'Activo', 'BA' => 'Baja'];
    public const MONEDAS = ['$' => 'Pesos ($)', 'u$s' => 'Dólares (u$s)'];

    /** Campos del formulario (mismo formato que Tablas\Definiciones), por sección. */
    public static function campos(): array
    {
        return [
            'descripcion' => ['label' => 'Descripción', 'tipo' => 'texto', 'largo' => 100, 'requerido' => true],
            'rubro' => ['label' => 'Rubro', 'tipo' => 'ref', 'ref' => 'rubros'],
            'subrubro' => ['label' => 'Subrubro (código)', 'tipo' => 'texto', 'largo' => 3, 'ayuda' => 'Código de subrubro del rubro elegido.'],
            'unidad_venta' => ['label' => 'Unidad de venta', 'tipo' => 'ref', 'ref' => 'unidad_venta'],
            'codigo_de_barras' => ['label' => 'Código de barras', 'tipo' => 'texto', 'largo' => 20],
            'codigo_asociado' => ['label' => 'Código asociado', 'tipo' => 'texto', 'largo' => 15],
            'estado' => ['label' => 'Estado', 'tipo' => 'opciones', 'opciones' => self::ESTADOS, 'requerido' => true],
            'alicuota' => ['label' => 'Alícuota de IVA', 'tipo' => 'ref', 'ref' => 'tasas_iva', 'requerido' => true, 'grupo' => 'Precios'],
            'grabado' => ['label' => 'Gravado con IVA', 'tipo' => 'si_no', 'grupo' => 'Precios'],
            'moneda' => ['label' => 'Moneda', 'tipo' => 'opciones', 'opciones' => self::MONEDAS, 'requerido' => true, 'grupo' => 'Precios'],
            'costo' => ['label' => 'Costo', 'tipo' => 'decimal', 'dec' => 4, 'min' => 0, 'max' => 99999999999, 'grupo' => 'Precios'],
            'margen' => ['label' => 'Margen (%)', 'tipo' => 'decimal', 'dec' => 2, 'min' => 0, 'max' => 9999, 'grupo' => 'Precios'],
            'precio_unitario' => ['label' => 'Precio unitario', 'tipo' => 'decimal', 'dec' => 4, 'min' => 0, 'max' => 99999999999, 'grupo' => 'Precios'],
            'precio_de_venta_1' => ['label' => 'Precio lista 1', 'tipo' => 'decimal', 'dec' => 4, 'min' => 0, 'max' => 99999999999, 'grupo' => 'Precios'],
            'precio_de_venta_2' => ['label' => 'Precio lista 2', 'tipo' => 'decimal', 'dec' => 4, 'min' => 0, 'max' => 99999999999, 'grupo' => 'Precios'],
            'precio_de_venta_3' => ['label' => 'Precio lista 3', 'tipo' => 'decimal', 'dec' => 4, 'min' => 0, 'max' => 99999999999, 'grupo' => 'Precios'],
            'precio_de_oferta' => ['label' => 'Precio de oferta', 'tipo' => 'decimal', 'dec' => 4, 'min' => 0, 'max' => 99999999999, 'grupo' => 'Precios'],
            'bonificacion_a' => ['label' => 'Bonificación A (%)', 'tipo' => 'decimal', 'dec' => 2, 'min' => 0, 'max' => 100, 'grupo' => 'Precios'],
            'bonificacion_b' => ['label' => 'Bonificación B (%)', 'tipo' => 'decimal', 'dec' => 2, 'min' => 0, 'max' => 100, 'grupo' => 'Precios'],
            'incluirlista' => ['label' => 'Incluir en la lista de precios', 'tipo' => 'si_no', 'grupo' => 'Precios'],
            'proveedor' => ['label' => 'Proveedor (código)', 'tipo' => 'entero', 'min' => 0, 'max' => 99999, 'grupo' => 'Otros datos'],
            'stock_minimo_unidades' => ['label' => 'Stock mínimo (unidades)', 'tipo' => 'entero', 'min' => 0, 'max' => 999999999, 'grupo' => 'Otros datos'],
            'cta_contable' => ['label' => 'Cuenta contable', 'tipo' => 'entero', 'min' => 0, 'max' => 9999, 'grupo' => 'Otros datos'],
        ];
    }

    private const ORDEN = [
        'codigo' => 'a.codigo', 'descripcion' => 'a.descripcion', 'rubro' => 'a.rubro IS NULL, a.rubro, a.subrubro',
        'precio' => 'a.precio_de_venta_1', 'costo' => 'a.costo',
    ];

    private PDO $pdo;

    public function __construct()
    {
        $this->pdo = Db::pdo();
    }

    /**
     * @param array<string,mixed> $f q, rubro, estado (AC|BA|todos), orden
     * @return array{filas:array<int,array<string,mixed>>,total:int}
     */
    public function listar(array $f, int $pagina = 1, int $porPagina = 50): array
    {
        $where = [];
        $params = [];
        $q = trim((string) ($f['q'] ?? ''));
        if ($q !== '') {
            $like = '%' . addcslashes($q, '%_\\') . '%';
            $where[] = '(a.codigo LIKE ? OR a.descripcion LIKE ? OR a.codigo_de_barras = ? OR a.codigo_asociado LIKE ?)';
            array_push($params, $like, $like, $q, $like);
        }
        if (($f['rubro'] ?? '') !== '') {
            $where[] = 'a.rubro = ?';
            $params[] = (string) $f['rubro'];
        }
        $estado = (string) ($f['estado'] ?? 'AC');
        if (isset(self::ESTADOS[$estado])) {
            $where[] = 'a.estado = ?';
            $params[] = $estado;
        }
        $w = $where === [] ? '' : ' WHERE ' . implode(' AND ', $where);
        $st = $this->pdo->prepare("SELECT COUNT(*) FROM articulos a{$w}");
        $st->execute($params);
        $total = (int) $st->fetchColumn();
        $orden = Orden::sql(self::ORDEN, (string) ($f['orden'] ?? ''), 'a.descripcion, a.codigo');
        $desde = max(0, ($pagina - 1) * $porPagina);
        $st = $this->pdo->prepare(
            "SELECT a.codigo, a.descripcion, a.rubro, a.subrubro, a.estado, a.moneda, a.costo, a.precio_de_venta_1, a.precio_unitario,
                    a.alicuota, t.porcentaje AS iva_porcentaje, r.descripcion AS rubro_nombre
               FROM articulos a
               LEFT JOIN tasas_iva t ON t.codigo = a.alicuota
               LEFT JOIN rubros r ON r.codigo = a.rubro{$w}
              ORDER BY {$orden} LIMIT {$porPagina} OFFSET {$desde}"
        );
        $st->execute($params);
        return ['filas' => $st->fetchAll(), 'total' => $total];
    }

    /** @return array<string,mixed>|null */
    public function obtener(string $codigo): ?array
    {
        $st = $this->pdo->prepare('SELECT * FROM articulos WHERE codigo = ?');
        $st->execute([$codigo]);
        $r = $st->fetch();
        return $r === false ? null : $r;
    }

    /**
     * Alta ($codigo null: viene en $data['codigo']) o modificación.
     *
     * @return array{ok:bool,errores:array<string,string>,codigo:?string}
     */
    public function guardar(?string $codigo, array $data): array
    {
        $actual = $codigo === null ? null : $this->obtener($codigo);
        if ($codigo !== null && $actual === null) {
            return ['ok' => false, 'errores' => ['general' => 'El artículo ya no existe.'], 'codigo' => null];
        }
        [$v, $errores] = Validador::validar(self::campos(), $data);
        if ($actual === null) {
            $nuevo = mb_strtoupper(trim((string) ($data['codigo'] ?? '')));
            if ($nuevo === '' || mb_strlen($nuevo) > 15 || preg_match('/^[^\s\/\\\\?#%]+( [^\s\/\\\\?#%]+)*$/u', $nuevo) !== 1) {
                $errores['codigo'] = 'Completá el código: hasta 15 caracteres, sin barras ni signos ? # %.';
            } elseif ($this->obtener($nuevo) !== null) {
                $errores['codigo'] = 'Ya existe un artículo con ese código.';
            }
        }
        // El subrubro tiene que existir dentro del rubro elegido.
        if (!isset($errores['rubro']) && ($v['subrubro'] ?? null) !== null) {
            $v['subrubro'] = mb_strtoupper((string) $v['subrubro']);
            $st = $this->pdo->prepare('SELECT 1 FROM subrubros WHERE rubro = ? AND codigo = ?');
            $st->execute([(string) ($v['rubro'] ?? ''), $v['subrubro']]);
            if ($st->fetchColumn() === false) {
                $errores['subrubro'] = 'Ese subrubro no existe en el rubro elegido (Tablas → Subrubros).';
            }
        }
        if ($errores !== []) {
            return ['ok' => false, 'errores' => $errores, 'codigo' => null];
        }
        $v['actualizacion'] = date('Y-m-d H:i:s');
        try {
            if ($actual === null) {
                $v = ['codigo' => $nuevo] + $v;
                $cols = array_keys($v);
                $this->pdo->prepare('INSERT INTO articulos (`' . implode('`, `', $cols) . '`) VALUES (' . implode(', ', array_fill(0, count($cols), '?')) . ')')
                    ->execute(array_values($v));
                $codigo = $nuevo;
            } else {
                $cols = array_keys($v);
                $this->pdo->prepare('UPDATE articulos SET ' . implode(', ', array_map(static fn ($c) => "`{$c}` = ?", $cols)) . ' WHERE codigo = ?')
                    ->execute([...array_values($v), $codigo]);
            }
        } catch (\PDOException $e) {
            if ($e->getCode() === '23000') {
                return ['ok' => false, 'errores' => ['codigo' => 'Ya existe un artículo con ese código.'], 'codigo' => null];
            }
            throw $e;
        }
        return ['ok' => true, 'errores' => [], 'codigo' => $codigo];
    }
}
