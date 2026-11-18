<?php
declare(strict_types=1);

namespace App\Modules\Kiosco;

use App\Core\Db;

/** Modo Kiosco: ventas de mostrador con los productos en tarjetas y un carrito siempre visible (pedido de Hernán, 18/11). */
final class KioscoService
{
    private static ?bool $habilitado = null;

    /** ¿Está tildado «habilitar modo Kiosco» en Parámetros? */
    public static function habilitado(): bool
    {
        if (self::$habilitado === null) {
            try {
                self::$habilitado = (int) Db::pdo()->query('SELECT kiosco FROM parametros WHERE id = 1')->fetchColumn() === 1;
            } catch (\Throwable) {
                self::$habilitado = false;
            }
        }
        return self::$habilitado;
    }

    public static function reset(): void
    {
        self::$habilitado = null;
    }

    /** Cliente con el que arranca cada venta de mostrador: el consumidor final (se puede cambiar por otro). @return array{codigo:int,razon_social:string}|null */
    public static function clientePorOmision(): ?array
    {
        $f = Db::pdo()->query("SELECT codigo, razon_social FROM clientes WHERE activo = 1 AND iva = 3 ORDER BY (razon_social LIKE '%CONSUMIDOR%') DESC, codigo LIMIT 1")->fetch();
        return $f === false ? null : ['codigo' => (int) $f['codigo'], 'razon_social' => (string) $f['razon_social']];
    }

    /** Rubros con artículos activos, para filtrar las tarjetas. @return array<int,array{codigo:string,descripcion:string}> */
    public static function rubros(): array
    {
        return Db::pdo()->query("SELECT r.codigo, r.descripcion FROM rubros r WHERE EXISTS (SELECT 1 FROM articulos a WHERE a.rubro = r.codigo AND a.estado = 'AC') ORDER BY r.descripcion")->fetchAll();
    }
}
