<?php
declare(strict_types=1);

namespace App\Modules\Importacion;

use App\Core\Db;

/**
 * Modelos de importación guardados: para cada destino, qué columna (por su TÍTULO) va a cada campo y las opciones. Así un cliente que siempre
 * exporta la misma planilla de su sistema anterior la importa sin volver a elegir las columnas, aunque cambie el orden.
 */
final class Modelos
{
    /** @return array<int,array{id:int,nombre:string}> */
    public static function listar(string $destino): array
    {
        $st = Db::pdo()->prepare('SELECT id, nombre FROM importacion_modelos WHERE destino = ? ORDER BY nombre');
        $st->execute([$destino]);
        return array_map(static fn ($r) => ['id' => (int) $r['id'], 'nombre' => (string) $r['nombre']], $st->fetchAll());
    }

    /** @return array{id:int,destino:string,nombre:string,mapa:array<string,string>,opciones:array<string,mixed>}|null */
    public static function obtener(int $id): ?array
    {
        $st = Db::pdo()->prepare('SELECT * FROM importacion_modelos WHERE id = ?');
        $st->execute([$id]);
        $r = $st->fetch();
        if ($r === false) {
            return null;
        }
        return ['id' => (int) $r['id'], 'destino' => (string) $r['destino'], 'nombre' => (string) $r['nombre'],
            'mapa' => (array) json_decode((string) $r['mapa'], true), 'opciones' => (array) json_decode((string) $r['opciones'], true)];
    }

    /** @param array<string,string> $mapa campo => título @param array<string,mixed> $opciones */
    public static function guardar(string $destino, string $nombre, array $mapa, array $opciones, ?int $usuarioId): void
    {
        Db::pdo()->prepare('INSERT INTO importacion_modelos (destino, nombre, mapa, opciones, creado_por, actualizado_en) VALUES (?, ?, ?, ?, ?, NOW())
            ON DUPLICATE KEY UPDATE mapa = VALUES(mapa), opciones = VALUES(opciones), actualizado_en = NOW()')
            ->execute([$destino, mb_substr($nombre, 0, 60), json_encode($mapa, JSON_UNESCAPED_UNICODE), json_encode($opciones, JSON_UNESCAPED_UNICODE), $usuarioId]);
    }

    public static function borrar(int $id): void
    {
        Db::pdo()->prepare('DELETE FROM importacion_modelos WHERE id = ?')->execute([$id]);
    }

    /** @param array<string,int> $mapa @param string[] $titulos @return array<string,string> */
    public static function aTitulos(array $mapa, array $titulos): array
    {
        $out = [];
        foreach ($mapa as $campo => $i) {
            if (isset($titulos[$i])) {
                $out[$campo] = $titulos[$i];
            }
        }
        return $out;
    }

    /** Del modelo (títulos) a las columnas de este archivo: por título exacto y, si no, por título normalizado. @return array<string,int> */
    public static function aIndices(array $mapa, array $titulos): array
    {
        $norm = array_map([Destinos::class, 'normalizar'], $titulos);
        $out = [];
        foreach ($mapa as $campo => $t) {
            $i = array_search((string) $t, $titulos, true);
            if ($i === false) {
                $i = array_search(Destinos::normalizar((string) $t), $norm, true);
            }
            if ($i !== false) {
                $out[(string) $campo] = (int) $i;
            }
        }
        return $out;
    }
}
