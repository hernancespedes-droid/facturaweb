<?php
declare(strict_types=1);

namespace App\Modules\Migracion;

/**
 * Base de datos de VFP (.dbc): las tablas DBF guardan los nombres de campo cortados a 10 letras, pero el .dbc tiene
 * los nombres largos (ultima_factura_a, razon_social...), en el mismo orden que los campos de la tabla.
 */
final class Dbc
{
    /** @var array<string,string[]> tabla (minúsculas) => nombres largos en orden */
    private array $tablas = [];

    public function __construct(string $archivo)
    {
        $dbf = new Dbf($archivo);
        $filas = iterator_to_array($dbf->filas(), false);
        $porId = [];
        foreach ($filas as $f) {
            $porId[(int) $f['OBJECTID']] = $f;
        }
        foreach ($filas as $f) {
            if (trim((string) $f['OBJECTTYPE']) !== 'Field') {
                continue;
            }
            $padre = $porId[(int) $f['PARENTID']] ?? null;
            if ($padre === null || trim((string) $padre['OBJECTTYPE']) !== 'Table') {
                continue;
            }
            $this->tablas[mb_strtolower(trim((string) $padre['OBJECTNAME']))][] = mb_strtolower(trim((string) $f['OBJECTNAME']));
        }
    }

    /** @return string[]|null nombres largos de los campos de la tabla, o null si no está en el .dbc */
    public function campos(string $tabla): ?array
    {
        return $this->tablas[mb_strtolower($tabla)] ?? null;
    }
}
