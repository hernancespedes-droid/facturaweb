<?php
declare(strict_types=1);

namespace App\Modules\Clientes;

use App\Core\Db;
use App\Core\Orden;
use App\Core\Validador;
use PDO;

/**
 * Maestro de clientes (VFP MA, clientes.scx) sobre la tabla clientes del esquema O&S.
 *  - El código se asigna solo, como en el VFP: el mayor entre parametros.ultimo_cliente y el último código + 1,
 *    con la fila de parámetros bloqueada (dos altas a la vez no reciben el mismo número).
 *  - No se borra (lo van a usar los comprobantes): se desactiva.
 */
final class ClientesService
{
    /** Condición de IVA (combo de clientes.scx; el código es clientes.iva). */
    public const CONDICIONES_IVA = [
        1 => 'Inscripto', 2 => 'No Inscripto', 3 => 'Consumidor Final', 4 => 'Exento (B)', 5 => 'Exento (A)',
        6 => 'Liberado', 7 => 'Monotributo', 8 => 'Exento Especial (A)', 9 => 'Exento Especial (B)',
    ];
    /** Condiciones en las que no hace falta CUIT. */
    public const SIN_CUIT = [2, 3];
    public const FORMAS_PAGO = [1 => 'Contado', 2 => 'Cuenta corriente'];

    /** Campos del formulario (mismo formato que Tablas\Definiciones), por sección. */
    public static function campos(): array
    {
        return [
            'razon_social' => ['label' => 'Razón social', 'tipo' => 'texto', 'largo' => 100, 'requerido' => true],
            'nombre_fantasia' => ['label' => 'Nombre de fantasía', 'tipo' => 'texto', 'largo' => 100],
            'iva' => ['label' => 'Condición de IVA', 'tipo' => 'opciones', 'opciones' => self::CONDICIONES_IVA, 'requerido' => true],
            'cuit' => ['label' => 'CUIT', 'tipo' => 'cuit', 'ayuda' => 'Obligatorio salvo para consumidor final.'],
            'domicilio' => ['label' => 'Domicilio', 'tipo' => 'texto', 'largo' => 80],
            'localidad' => ['label' => 'Localidad', 'tipo' => 'texto', 'largo' => 40],
            'codigo_postal' => ['label' => 'Código postal', 'tipo' => 'texto', 'largo' => 8],
            'provincia' => ['label' => 'Provincia', 'tipo' => 'ref', 'ref' => 'provincias'],
            'telefono' => ['label' => 'Teléfono', 'tipo' => 'texto', 'largo' => 60],
            'fax' => ['label' => 'Otro teléfono / fax', 'tipo' => 'texto', 'largo' => 60],
            'emailfc' => ['label' => 'Mails para las facturas', 'tipo' => 'mails', 'largo' => 255, 'grupo' => 'Mails'],
            'emaillis' => ['label' => 'Mails para resúmenes y listados', 'tipo' => 'mails', 'largo' => 255, 'grupo' => 'Mails'],
            'vendedor' => ['label' => 'Vendedor', 'tipo' => 'ref', 'ref' => 'vendedores', 'grupo' => 'Venta'],
            'forma_pago' => ['label' => 'Forma de pago', 'tipo' => 'opciones', 'opciones' => self::FORMAS_PAGO, 'requerido' => true, 'grupo' => 'Venta'],
            'condicionpago' => ['label' => 'Condición de pago', 'tipo' => 'ref', 'ref' => 'condicionpago', 'grupo' => 'Venta'],
            'transporte' => ['label' => 'Transporte', 'tipo' => 'ref', 'ref' => 'transportes', 'grupo' => 'Venta'],
            'lista_habitual' => ['label' => 'Lista de precios habitual', 'tipo' => 'texto', 'largo' => 3, 'grupo' => 'Venta'],
            'descuento' => ['label' => 'Descuento (%)', 'tipo' => 'decimal', 'dec' => 2, 'min' => 0, 'max' => 100, 'grupo' => 'Venta'],
            'credito' => ['label' => 'Límite de crédito', 'tipo' => 'decimal', 'dec' => 2, 'min' => 0, 'max' => 9999999999, 'grupo' => 'Venta'],
            'calcula_impuesto' => ['label' => 'Calcula percepción / impuesto', 'tipo' => 'si_no', 'grupo' => 'Venta'],
            'consignacion' => ['label' => 'Consignación', 'tipo' => 'si_no', 'grupo' => 'Venta'],
            'observaciones' => ['label' => 'Observaciones', 'tipo' => 'texto', 'largo' => 255, 'grupo' => 'Otros datos'],
            'tipo_cliente' => ['label' => 'Tipo de cliente', 'tipo' => 'texto', 'largo' => 2, 'grupo' => 'Otros datos'],
            'cta_neto' => ['label' => 'Cuenta contable neto', 'tipo' => 'entero', 'min' => 0, 'max' => 9999, 'grupo' => 'Otros datos'],
            'cta_iva1' => ['label' => 'Cuenta contable IVA 1', 'tipo' => 'entero', 'min' => 0, 'max' => 9999, 'grupo' => 'Otros datos'],
            'cta_iva2' => ['label' => 'Cuenta contable IVA 2', 'tipo' => 'entero', 'min' => 0, 'max' => 9999, 'grupo' => 'Otros datos'],
            'cta_bruto' => ['label' => 'Cuenta contable bruto', 'tipo' => 'entero', 'min' => 0, 'max' => 9999, 'grupo' => 'Otros datos'],
        ];
    }

    /** Columnas ordenables de la grilla (expresiones fijas). */
    private const ORDEN = [
        'codigo' => 'c.codigo', 'razon_social' => 'c.razon_social', 'cuit' => 'c.cuit IS NULL, c.cuit',
        'localidad' => 'c.localidad IS NULL, c.localidad', 'iva' => 'c.iva',
    ];

    private PDO $pdo;

    public function __construct()
    {
        $this->pdo = Db::pdo();
    }

    /**
     * @param array<string,mixed> $f q, iva, provincia, estado (activos|inactivos|todos), orden
     * @return array{filas:array<int,array<string,mixed>>,total:int}
     */
    public function listar(array $f, int $pagina = 1, int $porPagina = 50): array
    {
        $where = [];
        $params = [];
        $q = trim((string) ($f['q'] ?? ''));
        if ($q !== '') {
            $digitos = preg_replace('/\D/', '', $q) ?? '';
            $like = '%' . addcslashes($q, '%_\\') . '%';
            $or = ['c.razon_social LIKE ?', 'c.nombre_fantasia LIKE ?', 'c.localidad LIKE ?'];
            array_push($params, $like, $like, $like);
            if ($digitos !== '' && preg_match('/^[\d\s.-]+$/', $q) === 1) {
                $or[] = 'c.codigo = ?';
                $params[] = (int) $digitos;
                $or[] = 'CAST(c.cuit AS CHAR) LIKE ?';
                $params[] = '%' . $digitos . '%';
            }
            $where[] = '(' . implode(' OR ', $or) . ')';
        }
        if (isset(self::CONDICIONES_IVA[(int) ($f['iva'] ?? 0)])) {
            $where[] = 'c.iva = ?';
            $params[] = (int) $f['iva'];
        }
        if (($f['provincia'] ?? '') !== '') {
            $where[] = 'c.provincia = ?';
            $params[] = (string) $f['provincia'];
        }
        $estado = (string) ($f['estado'] ?? 'activos');
        if ($estado !== 'todos') {
            $where[] = 'c.activo = ?';
            $params[] = $estado === 'inactivos' ? 0 : 1;
        }
        $w = $where === [] ? '' : ' WHERE ' . implode(' AND ', $where);
        $st = $this->pdo->prepare("SELECT COUNT(*) FROM clientes c{$w}");
        $st->execute($params);
        $total = (int) $st->fetchColumn();

        $orden = Orden::sql(self::ORDEN, (string) ($f['orden'] ?? ''), 'c.razon_social, c.codigo');
        $desde = max(0, ($pagina - 1) * $porPagina);
        $st = $this->pdo->prepare(
            "SELECT c.codigo, c.razon_social, c.nombre_fantasia, c.iva, c.cuit, c.localidad, c.telefono, c.activo, p.descripcion AS provincia_nombre
               FROM clientes c LEFT JOIN provincias p ON p.codigo = c.provincia{$w}
              ORDER BY {$orden} LIMIT {$porPagina} OFFSET {$desde}"
        );
        $st->execute($params);
        return ['filas' => $st->fetchAll(), 'total' => $total];
    }

    /** @return array<string,mixed>|null */
    public function obtener(int $codigo): ?array
    {
        $st = $this->pdo->prepare('SELECT * FROM clientes WHERE codigo = ?');
        $st->execute([$codigo]);
        $r = $st->fetch();
        return $r === false ? null : $r;
    }

    /** Próximo código (solo para mostrar; el definitivo se asigna al grabar). */
    public function proximoCodigo(): int
    {
        return (int) $this->pdo->query('SELECT GREATEST(COALESCE((SELECT ultimo_cliente FROM parametros WHERE id = 1), 0),
                                                       COALESCE((SELECT MAX(codigo) FROM clientes), 0)) + 1')->fetchColumn();
    }

    /**
     * Alta ($codigo null) o modificación.
     *
     * @param array<string,mixed> $data
     * @return array{ok:bool,errores:array<string,string>,codigo:?int,avisos:string[]}
     */
    public function guardar(?int $codigo, array $data): array
    {
        $actual = $codigo === null ? null : $this->obtener($codigo);
        if ($codigo !== null && $actual === null) {
            return ['ok' => false, 'errores' => ['general' => 'El cliente ya no existe.'], 'codigo' => null, 'avisos' => []];
        }
        [$v, $errores] = Validador::validar(self::campos(), $data);
        if (!isset($errores['iva']) && !isset($errores['cuit']) && ($v['cuit'] ?? null) === null && !in_array((int) ($v['iva'] ?? 0), self::SIN_CUIT, true)) {
            $errores['cuit'] = 'Para ' . self::CONDICIONES_IVA[(int) $v['iva']] . ' el CUIT es obligatorio.';
        }
        // Mismo CUIT en otro cliente activo: se avisa y hay que confirmarlo (puede ser una sucursal).
        if (!isset($errores['cuit']) && ($v['cuit'] ?? null) !== null && ($data['cuit_repetido'] ?? '') !== '1') {
            $st = $this->pdo->prepare('SELECT codigo, razon_social FROM clientes WHERE cuit = ? AND activo = 1 AND codigo <> ? LIMIT 1');
            $st->execute([$v['cuit'], (int) $codigo]);
            $otro = $st->fetch();
            if ($otro !== false) {
                $errores['cuit'] = 'Ese CUIT ya lo tiene el cliente ' . $otro['codigo'] . ' (' . $otro['razon_social'] . '). Si es correcto, tildá "Es otro cliente con el mismo CUIT".';
                $errores['cuit_repetido'] = '1';
            }
        }
        if ($errores !== []) {
            return ['ok' => false, 'errores' => $errores, 'codigo' => null, 'avisos' => []];
        }
        $v['actualizacion'] = date('Y-m-d H:i:s');

        $this->pdo->beginTransaction();
        try {
            if ($actual === null) {
                // El número sale de parametros.ultimo_cliente con la fila bloqueada hasta el commit.
                $ultimo = (int) $this->pdo->query('SELECT ultimo_cliente FROM parametros WHERE id = 1 FOR UPDATE')->fetchColumn();
                $max = (int) $this->pdo->query('SELECT COALESCE(MAX(codigo), 0) FROM clientes')->fetchColumn();
                $codigo = max($ultimo, $max) + 1;
                $v = ['codigo' => $codigo, 'fecha_alta' => date('Y-m-d')] + $v;
                $cols = array_keys($v);
                $this->pdo->prepare('INSERT INTO clientes (`' . implode('`, `', $cols) . '`) VALUES (' . implode(', ', array_fill(0, count($cols), '?')) . ')')
                    ->execute(array_values($v));
                $this->pdo->prepare('UPDATE parametros SET ultimo_cliente = ? WHERE id = 1')->execute([$codigo]);
            } else {
                $cols = array_keys($v);
                $this->pdo->prepare('UPDATE clientes SET ' . implode(', ', array_map(static fn ($c) => "`{$c}` = ?", $cols)) . ' WHERE codigo = ?')
                    ->execute([...array_values($v), $codigo]);
            }
            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
        return ['ok' => true, 'errores' => [], 'codigo' => $codigo, 'avisos' => []];
    }

    public function cambiarActivo(int $codigo, bool $activo): bool
    {
        $st = $this->pdo->prepare('UPDATE clientes SET activo = ?, actualizacion = NOW() WHERE codigo = ?');
        $st->execute([$activo ? 1 : 0, $codigo]);
        return $this->obtener($codigo) !== null;
    }
}
