<?php
declare(strict_types=1);

namespace App\Modules\Roles;

use App\Core\Db;
use App\Modules\Auth\Auth;
use PDO;

/**
 * ABM de roles (tabla roles + rol_permisos).
 *  - Cada opción del menú tiene un permiso que la habilita y, debajo, sus acciones: tildarlos es dar el permiso,
 *    que se verifica en el servidor en cada ruta.
 *  - "Es administrador": todos los permisos menos los exclusivos del Master (módulo 'master').
 *  - "Es master": todos. Solo un Master ve, edita o crea roles Master (como en Usuarios).
 * Los permisos de los roles administrador/master se guardan completos en rol_permisos, así las consultas
 * que unen con rol_permisos siguen valiendo.
 */
final class RolesService
{
    /** Opción del menú => [permiso que la habilita, acciones de esa opción]. Lo que no está acá va a "Otros". */
    public const MENU = [
        'clientes' => ['clientes.ver', ['clientes.editar']],
        'articulos' => ['articulos.ver', ['articulos.editar']],
        'comprobantes' => ['comprobantes.ver', ['comprobantes.emitir', 'comprobantes.credito']],
        'ctacte' => ['ctacte.ver', ['ctacte.cobrar', 'ctacte.imputar', 'ctacte.ajustar']],
        'iva_ventas' => ['listados.ver', []],
        'stock' => ['stock.ver', ['stock.mover', 'stock.administrar']],
        'sucursales' => ['tablas.sucursales', []],
        'rubros' => ['tablas.rubros', []],
        'subrubros' => ['tablas.subrubros', []],
        'provincias' => ['tablas.provincias', []],
        'parametros' => ['tablas.parametros', []],
        'bancos' => ['tablas.bancos', []],
        'cotizacion_dolar' => ['tablas.cotizacion_dolar', []],
        'vendedores' => ['tablas.vendedores', []],
        'tasas_iva' => ['tablas.tasas_iva', []],
        'condicionpago' => ['tablas.condicionpago', []],
        'transportes' => ['tablas.transportes', []],
        'forma_de_pagos' => ['tablas.forma_de_pagos', []],
        'usuarios' => ['usuarios.administrar', []],
        'configuracion' => ['configuracion.administrar', []],
        'arca' => ['arca.administrar', []],
        'agente' => ['agente.administrar', []],
    ];
    /** Qué más habilita cada opción en el menú (para que se entienda al tildar). */
    public const INCLUYE = [
        'usuarios' => 'roles',
    ];
    public const MODULO_MASTER = 'master';

    private PDO $pdo;

    public function __construct(private int $actorId, private bool $actorEsMaster)
    {
        $this->pdo = Db::pdo();
    }

    public static function desdeSesion(): self
    {
        return new self((int) Auth::id(), Auth::esMaster());
    }

    public function actorEsMaster(): bool
    {
        return $this->actorEsMaster;
    }

    /** @return array<int,array<string,mixed>> roles visibles, con cuántos usuarios y permisos tienen */
    public function listar(): array
    {
        return $this->pdo->query(
            'SELECT r.id, r.codigo, r.nombre, r.es_sistema, r.es_admin, r.es_master,
                    (SELECT COUNT(*) FROM usuarios u WHERE u.rol_id = r.id) AS usuarios,
                    (SELECT COUNT(*) FROM rol_permisos rp WHERE rp.rol_id = r.id) AS permisos
               FROM roles r' . ($this->actorEsMaster ? '' : ' WHERE r.es_master = 0') . '
              ORDER BY r.es_master DESC, r.es_admin DESC, r.id'
        )->fetchAll();
    }

    /** @return array<string,mixed>|null con 'permisos' => [codigo => true] */
    public function obtener(int $id): ?array
    {
        $st = $this->pdo->prepare('SELECT * FROM roles WHERE id = ?' . ($this->actorEsMaster ? '' : ' AND es_master = 0'));
        $st->execute([$id]);
        $r = $st->fetch();
        if ($r === false) {
            return null;
        }
        $st = $this->pdo->prepare('SELECT p.codigo FROM rol_permisos rp JOIN permisos p ON p.id = rp.permiso_id WHERE rp.rol_id = ?');
        $st->execute([$id]);
        $r['permisos'] = array_fill_keys($st->fetchAll(PDO::FETCH_COLUMN), true);
        return $r;
    }

    /** @return array<string,array<string,mixed>> codigo => permiso (sin los exclusivos del Master) */
    public function permisos(): array
    {
        $out = [];
        foreach ($this->pdo->query("SELECT codigo, modulo, descripcion FROM permisos WHERE modulo <> '" . self::MODULO_MASTER . "' ORDER BY id")->fetchAll() as $p) {
            $out[(string) $p['codigo']] = $p;
        }
        return $out;
    }

    /** @return string[] permisos que no están en ninguna opción del menú */
    public function otros(): array
    {
        $enMenu = [];
        foreach (self::MENU as [$principal, $acciones]) {
            $enMenu[] = $principal;
            array_push($enMenu, ...$acciones);
        }
        return array_values(array_diff(array_keys($this->permisos()), $enMenu));
    }

    /**
     * Alta (id null) o modificación.
     *
     * @param array<string,mixed> $data nombre, es_admin, es_master, permisos[] (códigos)
     * @return array{ok:bool,errores:array<string,string>,id:?int}
     */
    public function guardar(?int $id, array $data): array
    {
        $actual = $id === null ? null : $this->obtener($id);
        if ($id !== null && $actual === null) {
            return ['ok' => false, 'errores' => ['general' => 'El rol no existe.'], 'id' => null];
        }
        $nombre = trim((string) ($data['nombre'] ?? ''));
        $esMaster = ($data['es_master'] ?? '') === '1';
        $esAdmin = !$esMaster && ($data['es_admin'] ?? '') === '1';
        $errores = [];
        if ($nombre === '' || mb_strlen($nombre) > 60) {
            $errores['nombre'] = 'Completá el nombre (hasta 60 caracteres).';
        }
        if ($esMaster && !$this->actorEsMaster) {
            $errores['es_master'] = 'Solo un Master puede marcar un rol como Master.';
        }
        $st = $this->pdo->prepare('SELECT 1 FROM roles WHERE nombre = ? AND id <> ?');
        $st->execute([$nombre, (int) $id]);
        if ($st->fetchColumn() !== false) {
            $errores['nombre'] = 'Ya hay un rol con ese nombre.';
        }
        $codigos = $this->codigosElegidos($data['permisos'] ?? [], $esAdmin, $esMaster);
        // Que nadie se deje sin acceso a Usuarios y Roles por editar su propio rol.
        if ($id !== null && !in_array('usuarios.administrar', $codigos, true) && $this->rolDelActor($id)) {
            $errores['permisos'] = 'Es tu rol: no le podés quitar Usuarios (te quedarías sin poder administrar). Pedíselo a otro administrador.';
        }
        if ($id !== null && (int) $actual['es_master'] === 1 && !$esMaster && $this->rolDelActor($id)) {
            $errores['es_master'] = 'Es tu rol: no le podés quitar la marca Master.';
        }
        if ($errores !== []) {
            return ['ok' => false, 'errores' => $errores, 'id' => null];
        }

        $this->pdo->beginTransaction();
        try {
            if ($id === null) {
                $this->pdo->prepare('INSERT INTO roles (codigo, nombre, es_sistema, asignable, es_admin, es_master) VALUES (?, ?, 0, ?, ?, ?)')
                    ->execute([$this->codigoNuevo($nombre), $nombre, $esMaster ? 0 : 1, $esAdmin ? 1 : 0, $esMaster ? 1 : 0]);
                $id = (int) $this->pdo->lastInsertId();
            } else {
                $this->pdo->prepare('UPDATE roles SET nombre = ?, asignable = ?, es_admin = ?, es_master = ? WHERE id = ?')
                    ->execute([$nombre, $esMaster ? 0 : 1, $esAdmin ? 1 : 0, $esMaster ? 1 : 0, $id]);
            }
            $this->pdo->prepare('DELETE FROM rol_permisos WHERE rol_id = ?')->execute([$id]);
            if ($codigos !== []) {
                $in = implode(',', array_fill(0, count($codigos), '?'));
                $this->pdo->prepare("INSERT INTO rol_permisos (rol_id, permiso_id) SELECT ?, id FROM permisos WHERE codigo IN ({$in})")
                    ->execute(array_merge([$id], $codigos));
            }
            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
        return ['ok' => true, 'errores' => [], 'id' => $id];
    }

    /**
     * Permisos a guardar: todos para Master, todos menos los del Master para Administrador; si no, los tildados
     * (una acción sin la opción del menú que la contiene no vale).
     *
     * @return string[]
     */
    private function codigosElegidos(mixed $tildados, bool $esAdmin, bool $esMaster): array
    {
        if ($esMaster) {
            return $this->pdo->query('SELECT codigo FROM permisos')->fetchAll(PDO::FETCH_COLUMN);
        }
        $validos = $this->permisos();
        if ($esAdmin) {
            return array_keys($validos);
        }
        $t = array_flip(array_filter(is_array($tildados) ? array_map('strval', $tildados) : [], static fn ($c) => isset($validos[$c])));
        foreach (self::MENU as [$principal, $acciones]) {
            if (!isset($t[$principal])) {
                foreach ($acciones as $a) {
                    unset($t[$a]);
                }
            }
        }
        return array_keys($t);
    }

    private function rolDelActor(int $rolId): bool
    {
        $st = $this->pdo->prepare('SELECT 1 FROM usuarios WHERE id = ? AND rol_id = ?');
        $st->execute([$this->actorId, $rolId]);
        return $st->fetchColumn() !== false;
    }

    private function codigoNuevo(string $nombre): string
    {
        $base = strtolower(trim((string) preg_replace('/[^a-z0-9]+/i', '_', iconv('UTF-8', 'ASCII//TRANSLIT', $nombre) ?: 'rol'), '_'));
        $base = substr($base !== '' ? $base : 'rol', 0, 25);
        $codigo = $base;
        $st = $this->pdo->prepare('SELECT 1 FROM roles WHERE codigo = ?');
        for ($i = 2; ; $i++) {
            $st->execute([$codigo]);
            if ($st->fetchColumn() === false) {
                return $codigo;
            }
            $codigo = $base . '_' . $i;
        }
    }

    /** Borra un rol propio del cliente que nadie usa. */
    public function borrar(int $id): ?string
    {
        $r = $this->obtener($id);
        if ($r === null) {
            return 'El rol no existe.';
        }
        if ((int) $r['es_sistema'] === 1) {
            return 'Los roles que vienen con el sistema no se borran (se pueden editar).';
        }
        $st = $this->pdo->prepare('SELECT COUNT(*) FROM usuarios WHERE rol_id = ?');
        $st->execute([$id]);
        if ((int) $st->fetchColumn() > 0) {
            return 'Hay usuarios con este rol: cambiales el rol antes de borrarlo.';
        }
        $this->pdo->prepare('DELETE FROM roles WHERE id = ?')->execute([$id]);
        return null;
    }
}
