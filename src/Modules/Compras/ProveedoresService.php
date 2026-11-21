<?php
declare(strict_types=1);

namespace App\Modules\Compras;

use App\Core\Db;
use App\Core\ExcelListado;
use App\Core\Orden;
use App\Core\Valida;
use App\Modules\Clientes\ClientesService;
use PDO;

/**
 * Proveedores: alta y modificación, buscador (mismo formato que el de clientes, para reutilizar cliente-picker.js y la lupa) y saldo.
 * El código es el mismo número que usa articulos.proveedor. No se borran: se desactivan.
 */
final class ProveedoresService
{
    /** Condiciones de IVA posibles de un proveedor (mismos códigos que clientes.iva). */
    public const CONDICIONES = [1 => 'Responsable inscripto', 7 => 'Monotributo', 4 => 'Exento', 3 => 'Consumidor final / informal'];

    private PDO $pdo;

    public function __construct()
    {
        $this->pdo = Db::pdo();
    }

    public const ORDEN = ['codigo' => 'p.codigo', 'razon_social' => 'p.razon_social', 'cuit' => 'p.cuit IS NULL, p.cuit', 'localidad' => 'p.localidad',
        'saldo' => 'saldo'];

    /** @return array{filas:array<int,array<string,mixed>>,total:int} */
    public function listar(array $f, int $pagina, int $porPagina): array
    {
        [$where, $params] = $this->filtro($f);
        $st = $this->pdo->prepare("SELECT COUNT(*) FROM proveedores p WHERE {$where}");
        $st->execute($params);
        $total = (int) $st->fetchColumn();
        $orden = Orden::sql(self::ORDEN, (string) ($f['orden'] ?? ''), 'p.razon_social, p.codigo');
        $st = $this->pdo->prepare("SELECT p.*, cc.nombre AS centro, cg.nombre AS concepto,
                (SELECT COALESCE(SUM(IF(c.tipo = 'NC', -c.saldo, c.saldo)), 0) FROM compras c WHERE c.proveedor_id = p.id AND c.estado = 'V' AND c.forma = 'C') AS saldo
            FROM proveedores p LEFT JOIN centros_costo cc ON cc.id = p.centro_id LEFT JOIN conceptos_gasto cg ON cg.id = p.concepto_id
            WHERE {$where} ORDER BY {$orden}" . ExcelListado::limite($f + ['pagina' => $pagina], $porPagina));
        $st->execute($params);
        return ['filas' => $st->fetchAll(), 'total' => $total];
    }

    /** @return array{0:string,1:array<int,mixed>} */
    private function filtro(array $f): array
    {
        $w = ['1=1'];
        $p = [];
        $q = trim((string) ($f['q'] ?? ''));
        if ($q !== '') {
            $digitos = preg_replace('/\D/', '', $q) ?? '';
            $or = ['p.razon_social LIKE ?'];
            $p[] = '%' . $q . '%';
            if ($digitos !== '' && strlen($digitos) === strlen(preg_replace('/[\s\-.]/', '', $q) ?? '')) {
                $or[] = 'p.codigo = ?';
                $p[] = (int) substr($digitos, 0, 9);
                $or[] = 'p.cuit LIKE ?';
                $p[] = $digitos . '%';
            }
            $w[] = '(' . implode(' OR ', $or) . ')';
        }
        $estado = (string) ($f['estado'] ?? 'activos');
        if ($estado === 'activos') {
            $w[] = 'p.activo = 1';
        } elseif ($estado === 'inactivos') {
            $w[] = 'p.activo = 0';
        }
        return [implode(' AND ', $w), $p];
    }

    /** @return array<string,mixed>|null */
    public function obtener(int $id): ?array
    {
        $st = $this->pdo->prepare('SELECT * FROM proveedores WHERE id = ?');
        $st->execute([$id]);
        return $st->fetch() ?: null;
    }

    public function siguienteCodigo(): int
    {
        return (int) $this->pdo->query('SELECT COALESCE(MAX(codigo), 0) + 1 FROM proveedores')->fetchColumn();
    }

    /**
     * Alta (id null) o modificación. Un CUIT repetido se confirma (confirmar_cuit = 1), como en clientes.
     *
     * @param array<string,mixed> $d
     * @return array{ok:bool,errores:array<string,string>,id:?int,repetido?:string}
     */
    public function guardar(?int $id, array $d): array
    {
        $e = [];
        $v = [];
        $v['razon_social'] = trim((string) ($d['razon_social'] ?? ''));
        if ($v['razon_social'] === '' || mb_strlen($v['razon_social']) > 100) {
            $e['razon_social'] = 'Escribí la razón social (hasta 100 letras).';
        }
        $v['iva'] = (int) ($d['iva'] ?? 1);
        if (!isset(self::CONDICIONES[$v['iva']])) {
            $e['iva'] = 'Elegí la condición de IVA.';
        }
        $cuit = preg_replace('/\D/', '', (string) ($d['cuit'] ?? '')) ?? '';
        $v['cuit'] = $cuit === '' ? null : $cuit;
        if ($cuit !== '' && !Valida::cuit($cuit)) {
            $e['cuit'] = 'El CUIT no es válido (revisá los 11 números).';
        } elseif ($cuit === '' && $v['iva'] !== 3) {
            $e['cuit'] = 'El CUIT es obligatorio (salvo para un proveedor informal).';
        }
        foreach (['domicilio' => 100, 'localidad' => 60, 'telefono' => 40, 'cbu_alias' => 40, 'observaciones' => 255] as $k => $largo) {
            $v[$k] = trim((string) ($d[$k] ?? ''));
            if (mb_strlen($v[$k]) > $largo) {
                $e[$k] = 'Hasta ' . $largo . ' letras.';
            }
        }
        $v['email'] = trim((string) ($d['email'] ?? ''));
        if ($v['email'] !== '' && (mb_strlen($v['email']) > 120 || filter_var($v['email'], FILTER_VALIDATE_EMAIL) === false)) {
            $e['email'] = 'El mail no es válido.';
        }
        $plazo = trim((string) ($d['plazo_dias'] ?? ''));
        $v['plazo_dias'] = $plazo === '' ? 0 : (ctype_digit($plazo) ? (int) $plazo : -1);
        if ($v['plazo_dias'] < 0 || $v['plazo_dias'] > 365) {
            $e['plazo_dias'] = 'El plazo va en días (0 a 365).';
        }
        $centros = (new CentrosService())->centros();
        $conceptos = (new CentrosService())->conceptos();
        $v['centro_id'] = ($d['centro_id'] ?? '') === '' ? null : (int) $d['centro_id'];
        if ($v['centro_id'] !== null && !isset($centros[$v['centro_id']])) {
            $e['centro_id'] = 'Elegí un centro de la lista.';
        }
        $v['concepto_id'] = ($d['concepto_id'] ?? '') === '' ? null : (int) $d['concepto_id'];
        if ($v['concepto_id'] !== null && !isset($conceptos[$v['concepto_id']])) {
            $e['concepto_id'] = 'Elegí un concepto de la lista.';
        }
        $v['activo'] = ($d['activo'] ?? '1') === '0' ? 0 : 1;
        $codigo = trim((string) ($d['codigo'] ?? ''));
        if ($id === null) {
            $v['codigo'] = $codigo === '' ? $this->siguienteCodigo() : (ctype_digit($codigo) ? (int) $codigo : 0);
            if ($v['codigo'] < 1 || $v['codigo'] > 99999) {
                $e['codigo'] = 'El código es un número de 1 a 99999 (vacío = el siguiente).';
            } else {
                $st = $this->pdo->prepare('SELECT COUNT(*) FROM proveedores WHERE codigo = ?');
                $st->execute([$v['codigo']]);
                if ((int) $st->fetchColumn() > 0) {
                    $e['codigo'] = 'Ese código ya es de otro proveedor.';
                }
            }
        } elseif ($this->obtener($id) === null) {
            return ['ok' => false, 'errores' => ['general' => 'El proveedor no existe.'], 'id' => null];
        }
        if ($e === [] && $v['cuit'] !== null && ($d['confirmar_cuit'] ?? '') !== '1') {
            $st = $this->pdo->prepare('SELECT razon_social FROM proveedores WHERE cuit = ? AND id <> ? LIMIT 1');
            $st->execute([$v['cuit'], $id ?? 0]);
            $otro = $st->fetchColumn();
            if ($otro !== false) {
                return ['ok' => false, 'errores' => ['cuit' => 'Ese CUIT ya es de «' . $otro . '». Si es correcto, tildá «El CUIT repetido está bien».'], 'id' => null,
                    'repetido' => (string) $otro];
            }
        }
        if ($e !== []) {
            return ['ok' => false, 'errores' => $e, 'id' => null];
        }
        $cols = ['razon_social', 'cuit', 'iva', 'domicilio', 'localidad', 'telefono', 'email', 'cbu_alias', 'plazo_dias', 'concepto_id', 'centro_id', 'observaciones', 'activo'];
        if ($id === null) {
            $cols[] = 'codigo';
            $this->pdo->prepare('INSERT INTO proveedores (' . implode(', ', $cols) . ', creado_en) VALUES (' . implode(', ', array_fill(0, count($cols), '?')) . ', NOW())')
                ->execute(array_map(static fn ($c) => $v[$c], $cols));
            return ['ok' => true, 'errores' => [], 'id' => (int) $this->pdo->lastInsertId()];
        }
        $this->pdo->prepare('UPDATE proveedores SET ' . implode(', ', array_map(static fn ($c) => "{$c} = ?", $cols)) . ' WHERE id = ?')
            ->execute([...array_map(static fn ($c) => $v[$c], $cols), $id]);
        return ['ok' => true, 'errores' => [], 'id' => $id];
    }

    /** Lo que se le debe (compras a cuenta corriente con saldo; las NC restan). */
    public function saldo(int $id): float
    {
        $st = $this->pdo->prepare("SELECT COALESCE(SUM(IF(tipo = 'NC', -saldo, saldo)), 0) FROM compras WHERE proveedor_id = ? AND estado = 'V' AND forma = 'C'");
        $st->execute([$id]);
        return round((float) $st->fetchColumn(), 2);
    }

    /**
     * Proveedores para el buscador y la lupa, en el formato de clientes (codigo = id interno) más lo que propone la carga.
     *
     * @param array<int,array<string,mixed>> $filas
     * @return array<int,array<string,mixed>>
     */
    public function paraElegir(array $filas): array
    {
        $out = [];
        foreach ($filas as $p) {
            $out[] = [
                'codigo' => (int) $p['id'], 'numero' => (int) $p['codigo'], 'razon_social' => (string) $p['razon_social'], 'cuit' => cuit_texto($p['cuit']),
                'localidad' => (string) $p['localidad'], 'iva' => (int) $p['iva'], 'iva_texto' => self::CONDICIONES[(int) $p['iva']] ?? (ClientesService::CONDICIONES_IVA[(int) $p['iva']] ?? ''),
                'centro_id' => $p['centro_id'] === null ? null : (int) $p['centro_id'], 'concepto_id' => $p['concepto_id'] === null ? null : (int) $p['concepto_id'],
                'plazo_dias' => (int) $p['plazo_dias'],
            ];
        }
        return $out;
    }
}
