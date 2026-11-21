<?php
declare(strict_types=1);

namespace App\Modules\Compras;

use App\Core\Db;
use App\Core\Valida;
use App\Modules\Comprobantes\Calculadora;
use PDO;

/**
 * Centros de costos (dos niveles, con presupuesto mensual optativo), conceptos de gasto y plantillas de reparto.
 *
 * DECISIÓN D-110 (aprobada como D-1 de la propuesta): toda compra tiene centro; si nadie elige, va a «General» (el centro marcado general = 1,
 * que no se desactiva). Un centro usado no se borra: se desactiva. Dos niveles como mucho: el padre no puede tener padre.
 */
final class CentrosService
{
    private PDO $pdo;

    public function __construct()
    {
        $this->pdo = Db::pdo();
    }

    // ---------------------------------------------------------------- centros

    /**
     * Centros ordenados como árbol (cada padre seguido de sus hijos), con el nombre completo «Padre › Hijo».
     *
     * @return array<int,array<string,mixed>> id => fila (+ nivel, completo)
     */
    public function centros(bool $soloActivos = false): array
    {
        $filas = $this->pdo->query('SELECT * FROM centros_costo ORDER BY general DESC, nombre')->fetchAll();
        $porId = [];
        foreach ($filas as $f) {
            $porId[(int) $f['id']] = $f;
        }
        $out = [];
        foreach ($filas as $f) {
            if ($f['padre_id'] !== null && isset($porId[(int) $f['padre_id']])) {
                continue;
            }
            if (!$soloActivos || (int) $f['activo'] === 1) {
                $out[(int) $f['id']] = $f + ['nivel' => 0, 'completo' => $f['nombre']];
            }
            foreach ($filas as $h) {
                if ((int) ($h['padre_id'] ?? 0) === (int) $f['id'] && (!$soloActivos || ((int) $h['activo'] === 1 && (int) $f['activo'] === 1))) {
                    $out[(int) $h['id']] = $h + ['nivel' => 1, 'completo' => $f['nombre'] . ' › ' . $h['nombre']];
                }
            }
        }
        return $out;
    }

    public function general(): int
    {
        $id = $this->pdo->query('SELECT id FROM centros_costo WHERE general = 1 ORDER BY id LIMIT 1')->fetchColumn();
        if ($id === false) { // por si alguien lo borró a mano
            $this->pdo->exec("INSERT INTO centros_costo (nombre, general) VALUES ('General', 1)");
            return (int) $this->pdo->lastInsertId();
        }
        return (int) $id;
    }

    /** @return array{ok:bool,error?:string,id?:int} */
    public function guardarCentro(?int $id, string $nombre, ?int $padreId, bool $activo): array
    {
        $nombre = trim($nombre);
        if ($nombre === '' || mb_strlen($nombre) > 60) {
            return ['ok' => false, 'error' => 'Escribí el nombre del centro (hasta 60 letras).'];
        }
        $actual = null;
        if ($id !== null) {
            $st = $this->pdo->prepare('SELECT * FROM centros_costo WHERE id = ?');
            $st->execute([$id]);
            $actual = $st->fetch() ?: null;
            if ($actual === null) {
                return ['ok' => false, 'error' => 'Ese centro no existe.'];
            }
        }
        if ($padreId !== null) {
            $st = $this->pdo->prepare('SELECT padre_id, general FROM centros_costo WHERE id = ?');
            $st->execute([$padreId]);
            $p = $st->fetch();
            if (!$p || $p['padre_id'] !== null) {
                return ['ok' => false, 'error' => 'El centro de arriba tiene que ser uno principal (hay dos niveles como mucho).'];
            }
            if ((int) $p['general'] === 1) {
                return ['ok' => false, 'error' => '«General» no lleva centros adentro.'];
            }
            if ($id !== null && $padreId === $id) {
                return ['ok' => false, 'error' => 'Un centro no puede estar adentro de sí mismo.'];
            }
            if ($id !== null) {
                $h = $this->pdo->prepare('SELECT COUNT(*) FROM centros_costo WHERE padre_id = ?');
                $h->execute([$id]);
                if ((int) $h->fetchColumn() > 0) {
                    return ['ok' => false, 'error' => 'Ese centro tiene centros adentro: no puede pasar a ser de segundo nivel.'];
                }
            }
        }
        if ($actual !== null && (int) $actual['general'] === 1) {
            $activo = true; // General nunca se desactiva
            $padreId = null;
        }
        $rep = $this->pdo->prepare('SELECT COUNT(*) FROM centros_costo WHERE nombre = ? AND padre_id <=> ? AND id <> ?');
        $rep->execute([$nombre, $padreId, $id ?? 0]);
        if ((int) $rep->fetchColumn() > 0) {
            return ['ok' => false, 'error' => 'Ya hay un centro con ese nombre en el mismo lugar.'];
        }
        if ($id === null) {
            $this->pdo->prepare('INSERT INTO centros_costo (nombre, padre_id, activo) VALUES (?, ?, ?)')->execute([$nombre, $padreId, $activo ? 1 : 0]);
            return ['ok' => true, 'id' => (int) $this->pdo->lastInsertId()];
        }
        $this->pdo->prepare('UPDATE centros_costo SET nombre = ?, padre_id = ?, activo = ? WHERE id = ?')->execute([$nombre, $padreId, $activo ? 1 : 0, $id]);
        return ['ok' => true, 'id' => $id];
    }

    // ---------------------------------------------------------------- presupuestos

    /** Presupuesto de un mes. @return array<int,float> centro => importe */
    public function presupuesto(string $periodo): array
    {
        $st = $this->pdo->prepare('SELECT centro_id, importe FROM centros_presupuesto WHERE periodo = ?');
        $st->execute([$periodo]);
        $out = [];
        foreach ($st->fetchAll() as $f) {
            $out[(int) $f['centro_id']] = (float) $f['importe'];
        }
        return $out;
    }

    /** @param array<int|string,string> $importes centro => texto del importe ('' o 0 = sin presupuesto) @return array{ok:bool,error?:string} */
    public function guardarPresupuesto(string $periodo, array $importes): array
    {
        if (!self::periodoValido($periodo)) {
            return ['ok' => false, 'error' => 'El mes no es válido.'];
        }
        $centros = $this->centros();
        $this->pdo->beginTransaction();
        try {
            foreach ($importes as $cid => $txt) {
                $cid = (int) $cid;
                if (!isset($centros[$cid])) {
                    continue;
                }
                $txt = trim((string) $txt);
                $v = $txt === '' ? 0.0 : Valida::decimal($txt);
                if ($v === null || $v < 0) {
                    $this->pdo->rollBack();
                    return ['ok' => false, 'error' => 'El presupuesto de «' . $centros[$cid]['completo'] . '» no es un importe válido.'];
                }
                if ($v < 0.005) {
                    $this->pdo->prepare('DELETE FROM centros_presupuesto WHERE centro_id = ? AND periodo = ?')->execute([$cid, $periodo]);
                } else {
                    $this->pdo->prepare('INSERT INTO centros_presupuesto (centro_id, periodo, importe) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE importe = VALUES(importe)')
                        ->execute([$cid, $periodo, Calculadora::r2($v)]);
                }
            }
            $this->pdo->commit();
            return ['ok' => true];
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    /** Copia el presupuesto del mes anterior (solo los centros que en este mes no tienen). @return int cuántos copió */
    public function copiarPresupuestoAnterior(string $periodo): int
    {
        if (!self::periodoValido($periodo)) {
            return 0;
        }
        $anterior = date('Y-m', strtotime($periodo . '-01 -1 month'));
        $st = $this->pdo->prepare('INSERT IGNORE INTO centros_presupuesto (centro_id, periodo, importe) SELECT centro_id, ?, importe FROM centros_presupuesto WHERE periodo = ?');
        $st->execute([$periodo, $anterior]);
        return $st->rowCount();
    }

    public static function periodoValido(string $p): bool
    {
        return preg_match('/^(19|20|21)\d\d-(0[1-9]|1[0-2])$/', $p) === 1;
    }

    // ---------------------------------------------------------------- conceptos

    /** @return array<int,array<string,mixed>> id => fila */
    public function conceptos(bool $soloActivos = false): array
    {
        $out = [];
        foreach ($this->pdo->query('SELECT * FROM conceptos_gasto' . ($soloActivos ? ' WHERE activo = 1' : '') . ' ORDER BY nombre')->fetchAll() as $f) {
            $out[(int) $f['id']] = $f;
        }
        return $out;
    }

    /** El concepto «Otros gastos» (o el primero activo): el que se usa si nadie elige. */
    public function conceptoPorOmision(): int
    {
        $id = $this->pdo->query("SELECT id FROM conceptos_gasto WHERE activo = 1 ORDER BY nombre = 'Otros gastos' DESC, nombre LIMIT 1")->fetchColumn();
        if ($id === false) {
            $this->pdo->exec("INSERT INTO conceptos_gasto (nombre) VALUES ('Otros gastos') ON DUPLICATE KEY UPDATE activo = 1");
            return (int) $this->pdo->query("SELECT id FROM conceptos_gasto WHERE nombre = 'Otros gastos'")->fetchColumn();
        }
        return (int) $id;
    }

    /** @return array{ok:bool,error?:string,id?:int} */
    public function guardarConcepto(?int $id, string $nombre, bool $activo): array
    {
        $nombre = trim($nombre);
        if ($nombre === '' || mb_strlen($nombre) > 60) {
            return ['ok' => false, 'error' => 'Escribí el nombre del concepto (hasta 60 letras).'];
        }
        $rep = $this->pdo->prepare('SELECT COUNT(*) FROM conceptos_gasto WHERE nombre = ? AND id <> ?');
        $rep->execute([$nombre, $id ?? 0]);
        if ((int) $rep->fetchColumn() > 0) {
            return ['ok' => false, 'error' => 'Ya hay un concepto con ese nombre.'];
        }
        if ($id === null) {
            $this->pdo->prepare('INSERT INTO conceptos_gasto (nombre, activo) VALUES (?, ?)')->execute([$nombre, $activo ? 1 : 0]);
            return ['ok' => true, 'id' => (int) $this->pdo->lastInsertId()];
        }
        $st = $this->pdo->prepare('UPDATE conceptos_gasto SET nombre = ?, activo = ? WHERE id = ?');
        $st->execute([$nombre, $activo ? 1 : 0, $id]);
        return ['ok' => true, 'id' => $id];
    }

    // ---------------------------------------------------------------- plantillas de reparto

    /** @return array<int,array{id:int,nombre:string,detalle:array<int,array{centro:int,porcentaje:float}>}> */
    public function plantillas(): array
    {
        $out = [];
        foreach ($this->pdo->query('SELECT * FROM reparto_plantillas ORDER BY nombre')->fetchAll() as $f) {
            $det = json_decode((string) $f['detalle'], true);
            $out[(int) $f['id']] = ['id' => (int) $f['id'], 'nombre' => (string) $f['nombre'], 'detalle' => is_array($det) ? $det : []];
        }
        return $out;
    }

    /**
     * @param array<int,array{centro:int|string,porcentaje:string|float}> $renglones
     * @return array{ok:bool,error?:string,id?:int}
     */
    public function guardarPlantilla(string $nombre, array $renglones): array
    {
        $nombre = trim($nombre);
        if ($nombre === '' || mb_strlen($nombre) > 60) {
            return ['ok' => false, 'error' => 'Escribí el nombre de la plantilla (hasta 60 letras).'];
        }
        $centros = $this->centros(true);
        $det = [];
        $suma = 0.0;
        foreach ($renglones as $r) {
            $c = (int) ($r['centro'] ?? 0);
            $p = is_float($r['porcentaje'] ?? null) ? $r['porcentaje'] : Valida::decimal((string) ($r['porcentaje'] ?? ''));
            if ($c === 0 && ($p === null || $p == 0.0)) {
                continue;
            }
            if (!isset($centros[$c]) || $p === null || $p <= 0) {
                return ['ok' => false, 'error' => 'Cada renglón de la plantilla necesita un centro activo y un porcentaje mayor que cero.'];
            }
            $det[] = ['centro' => $c, 'porcentaje' => round($p, 2)];
            $suma += $p;
        }
        if (count($det) < 2 || abs($suma - 100) > 0.005) {
            return ['ok' => false, 'error' => 'La plantilla necesita dos centros o más y tiene que sumar 100 %.'];
        }
        $this->pdo->prepare('INSERT INTO reparto_plantillas (nombre, detalle) VALUES (?, ?) ON DUPLICATE KEY UPDATE detalle = VALUES(detalle)')
            ->execute([$nombre, json_encode($det)]);
        $id = (int) $this->pdo->query('SELECT id FROM reparto_plantillas WHERE nombre = ' . $this->pdo->quote($nombre))->fetchColumn();
        return ['ok' => true, 'id' => $id];
    }

    public function borrarPlantilla(int $id): void
    {
        $this->pdo->prepare('DELETE FROM reparto_plantillas WHERE id = ?')->execute([$id]);
    }
}
