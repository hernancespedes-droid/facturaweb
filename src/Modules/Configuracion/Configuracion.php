<?php
declare(strict_types=1);

namespace App\Modules\Configuracion;

use App\Core\Db;

/**
 * Configuración del sistema web (tabla configuracion: clave/valor/tipo/descripcion), que edita el Administrador.
 * No confundir con la tabla parametros del esquema O&S (datos de la empresa, Tablas → Parámetros).
 * Se lee una vez por pedido.
 */
final class Configuracion
{
    public const POR_PAGINA = 'grillas.por_pagina';
    public const MAX_ENTERO = 100000;

    /** Valores por si la migración todavía no se aplicó. */
    private const PREDETERMINADOS = [self::POR_PAGINA => '50'];

    /** @var array<string,string>|null */
    private static ?array $cache = null;

    public static function reset(): void
    {
        self::$cache = null;
    }

    public static function valor(string $clave): ?string
    {
        if (self::$cache === null) {
            try {
                self::$cache = Db::pdo()->query('SELECT clave, valor FROM configuracion')->fetchAll(\PDO::FETCH_KEY_PAIR);
            } catch (\PDOException) {
                self::$cache = []; // antes de migrar
            }
        }
        return self::$cache[$clave] ?? self::PREDETERMINADOS[$clave] ?? null;
    }

    public static function entero(string $clave, int $predeterminado = 0): int
    {
        $v = self::valor($clave);
        return $v !== null && preg_match('/^\d+$/', $v) ? (int) $v : $predeterminado;
    }

    /** Renglones por página de las grillas (entre 10 y 500). */
    public static function porPagina(): int
    {
        return max(10, min(500, self::entero(self::POR_PAGINA, 50)));
    }

    /** @return array<int,array<string,mixed>> */
    public static function listar(): array
    {
        return Db::pdo()->query(
            'SELECT c.clave, c.valor, c.tipo, c.descripcion, c.actualizado_en, u.nombre AS actualizado_por
               FROM configuracion c LEFT JOIN usuarios u ON u.id = c.actualizado_por
              WHERE c.clave NOT LIKE \'interno.%\' ORDER BY c.clave'
        )->fetchAll();
    }

    /**
     * Guarda los valores que cambiaron. Solo claves existentes; los enteros, entre 0 y MAX_ENTERO; los sí/no, 0 o 1.
     *
     * @param array<string,mixed> $valores clave => valor
     * @return array{cambiados:int,errores:array<string,string>}
     */
    public static function guardar(array $valores, ?int $usuarioId): array
    {
        $errores = [];
        $cambios = [];
        foreach (self::listar() as $p) {
            if (!array_key_exists($p['clave'], $valores)) {
                continue;
            }
            $v = trim((string) $valores[$p['clave']]);
            if ($p['tipo'] === 'entero' && (!preg_match('/^\d{1,6}$/', $v) || (int) $v > self::MAX_ENTERO)) {
                $errores[$p['clave']] = 'Tiene que ser un número entero entre 0 y ' . number_format(self::MAX_ENTERO, 0, ',', '.') . '.';
                continue;
            }
            if ($p['tipo'] === 'si_no' && !in_array($v, ['0', '1'], true)) {
                $errores[$p['clave']] = 'Tiene que ser sí o no.';
                continue;
            }
            if ($p['tipo'] === 'entero') {
                $v = (string) (int) $v;
            }
            if (mb_strlen($v) > 255) {
                $errores[$p['clave']] = 'Es demasiado largo.';
                continue;
            }
            if ($v !== $p['valor']) {
                $cambios[$p['clave']] = $v;
            }
        }
        if ($errores === []) {
            $st = Db::pdo()->prepare('UPDATE configuracion SET valor = ?, actualizado_por = ? WHERE clave = ?');
            foreach ($cambios as $k => $v) {
                $st->execute([$v, $usuarioId, $k]);
            }
            self::reset();
        }
        return ['cambiados' => $errores === [] ? count($cambios) : 0, 'errores' => $errores];
    }
}
