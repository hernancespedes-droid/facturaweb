<?php
declare(strict_types=1);

namespace App\Modules\Tablas;

use App\Core\Db;
use App\Core\Validador;
use PDO;

/**
 * ABM genérico de las tablas declaradas en Definiciones. Los nombres de tabla y columna salen siempre de las
 * definiciones (nunca de lo que manda el usuario) y los valores van como parámetros.
 */
final class TablasService
{
    /** Tablas del esquema O&S que no tienen el campo actualizacion. */
    private const SIN_ACTUALIZACION = ['provincias', 'vendedores', 'condicionpago'];

    private PDO $pdo;

    /** @param array<string,mixed> $def de Definiciones::de() */
    public function __construct(private array $def)
    {
        $this->pdo = Db::pdo();
    }

    /** @return array<int,array<string,mixed>> */
    public function listar(string $buscar = '', int $limite = 500): array
    {
        $t = $this->def['tabla'];
        $sql = "SELECT * FROM `{$t}`";
        $params = [];
        $buscar = trim($buscar);
        if ($buscar !== '') {
            $cols = array_values(array_filter(['codigo', 'descripcion', 'observaciones'], fn ($c) => isset($this->def['campos'][$c])));
            if ($cols !== []) {
                $like = '%' . addcslashes($buscar, '%_\\') . '%';
                $sql .= ' WHERE ' . implode(' OR ', array_map(static fn ($c) => "`{$c}` LIKE ?", $cols));
                $params = array_fill(0, count($cols), $like);
            }
        }
        $sql .= ' ORDER BY ' . $this->def['orden'] . ' LIMIT ' . max(1, $limite);
        $st = $this->pdo->prepare($sql);
        $st->execute($params);
        return $st->fetchAll();
    }

    /** @return array<string,mixed>|null */
    public function obtener(int $id): ?array
    {
        $st = $this->pdo->prepare("SELECT * FROM `{$this->def['tabla']}` WHERE id = ?");
        $st->execute([$id]);
        $r = $st->fetch();
        return $r === false ? null : $r;
    }

    /** Opciones de un campo "ref": código => "código - descripción". @return array<string,string> */
    public static function opcionesRef(string $tabla): array
    {
        if (Definiciones::de($tabla) === null && $tabla !== 'unidad_venta') {
            return []; // el nombre de la tabla va en el SQL: solo las conocidas
        }
        $out = [];
        foreach (Db::pdo()->query("SELECT codigo, descripcion FROM `{$tabla}` ORDER BY descripcion")->fetchAll() as $r) {
            $out[(string) $r['codigo']] = (string) $r['descripcion'];
        }
        return $out;
    }

    /**
     * Alta ($id null) o modificación. En la modificación, la clave O&S no se cambia.
     *
     * @param array<string,mixed> $data lo que vino del formulario
     * @return array{ok:bool,errores:array<string,string>,id:?int}
     */
    public function guardar(?int $id, array $data): array
    {
        $actual = $id === null ? null : $this->obtener($id);
        if ($id !== null && $actual === null) {
            return ['ok' => false, 'errores' => ['general' => 'El registro ya no existe.'], 'id' => null];
        }
        $clave = Definiciones::clave($this->def);
        [$valores, $errores] = Validador::validar($this->def['campos'], $data, $actual === null ? [] : $clave);
        if ($errores === [] && $actual === null && $clave !== []) {
            $where = implode(' AND ', array_map(static fn ($c) => "`{$c}` = ?", $clave));
            $st = $this->pdo->prepare("SELECT 1 FROM `{$this->def['tabla']}` WHERE {$where}");
            $st->execute(array_map(static fn ($c) => $valores[$c], $clave));
            if ($st->fetchColumn() !== false) {
                $errores[end($clave)] = 'Ya hay otro registro con ese código.';
            }
        }
        if ($errores !== []) {
            return ['ok' => false, 'errores' => $errores, 'id' => null];
        }
        $t = $this->def['tabla'];
        if (!in_array($t, self::SIN_ACTUALIZACION, true)) {
            $valores['actualizacion'] = date('Y-m-d H:i:s');
        }
        $cols = array_keys($valores);
        try {
            if ($actual === null) {
                $this->pdo->prepare("INSERT INTO `{$t}` (`" . implode('`, `', $cols) . '`) VALUES (' . implode(', ', array_fill(0, count($cols), '?')) . ')')
                    ->execute(array_values($valores));
                $id = (int) $this->pdo->lastInsertId();
            } else {
                $this->pdo->prepare("UPDATE `{$t}` SET " . implode(', ', array_map(static fn ($c) => "`{$c}` = ?", $cols)) . ' WHERE id = ?')
                    ->execute([...array_values($valores), $id]);
            }
        } catch (\PDOException $e) {
            if ($e->getCode() === '23000') {
                return ['ok' => false, 'errores' => ['general' => 'Ya existe un registro con esa clave.'], 'id' => null];
            }
            throw $e;
        }
        return ['ok' => true, 'errores' => [], 'id' => $id];
    }

    /** Borra un registro que nadie usa. Devuelve el problema o null. */
    public function borrar(int $id): ?string
    {
        if ($this->def['unico']) {
            return 'Este registro no se borra.';
        }
        $r = $this->obtener($id);
        if ($r === null) {
            return 'El registro ya no existe.';
        }
        foreach ($this->def['usos'] as [$tabla, $mapa, $texto]) {
            $where = implode(' AND ', array_map(static fn ($c) => "`{$c}` = ?", array_keys($mapa)));
            $st = $this->pdo->prepare("SELECT COUNT(*) FROM `{$tabla}` WHERE {$where}");
            $st->execute(array_map(static fn ($own) => $r[$own], array_values($mapa)));
            $n = (int) $st->fetchColumn();
            if ($n > 0) {
                return 'No se puede borrar: lo usan ' . number_format($n, 0, ',', '.') . ' ' . $texto . '.';
            }
        }
        $this->pdo->prepare("DELETE FROM `{$this->def['tabla']}` WHERE id = ?")->execute([$id]);
        return null;
    }
}
