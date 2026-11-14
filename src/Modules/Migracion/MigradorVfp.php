<?php
declare(strict_types=1);

namespace App\Modules\Migracion;

use PDO;

/**
 * Pasa los datos del sistema VFP (carpeta con los DBF y datos.dbc) a la base MySQL del facturador.
 *
 *  - Las tablas destino tienen los mismos nombres de campo que el .dbc (esquema O&S): cada columna se llena con el
 *    campo del mismo nombre, convertido al tipo de la columna (texto recortado, vacío => NULL, fechas, lógicos...).
 *  - Todo en una transacción: si algo falla no queda nada a medias. Con $simular se hace todo y al final se
 *    deshace (sirve para ver los avisos antes de migrar de verdad).
 *  - Una tabla que ya tiene datos cargados en la web no se pisa salvo con $reemplazar (las que vienen con valores
 *    iniciales, como alícuotas y provincias, se reemplazan siempre).
 *  - Usuarios: se crean los que no existen, desactivados y con contraseña al azar (la del VFP no se migra); el
 *    administrador los activa y les genera una contraseña temporal desde Usuarios.
 *  - Opcionalmente escribe un archivo .sql con lo mismo (para importarlo con phpMyAdmin en el hosting).
 */
final class MigradorVfp
{
    /** Tablas de la etapa 1, en orden (tabla MySQL => DBF del VFP). */
    public const TABLAS = [
        'sucursales' => 'sucursales', 'rubros' => 'rubros', 'subrubros' => 'subrubros', 'provincias' => 'provincias',
        'bancos' => 'bancos', 'cotizacion_dolar' => 'cotizacion_dolar', 'vendedores' => 'vendedores', 'tasas_iva' => 'tasas_iva',
        'condicionpago' => 'condicionpago', 'transportes' => 'transportes', 'forma_de_pagos' => 'forma_de_pagos',
        'unidad_venta' => 'unidad_venta', 'clientes' => 'clientes', 'articulos' => 'articulos', 'articulos_precios' => 'articulos_precios',
        'parametros' => 'parametros', 'usuarios' => 'cla_usuarios',
        // etapa 2: comprobantes y lo que cuelga de ellos
        'equivalencias_afip' => 'equivalencias_afip', 'subdiario' => 'subdiario', 'iva' => 'iva', 'estadistica' => 'estadistica',
        'caja' => 'caja', 'ctact' => 'ctact',
        // etapa 4: recibos e imputaciones de la cuenta corriente
        'cobra' => 'cobra', 'imputaciones' => 'imputaciones',
    ];
    /** Tablas que se completan sin borrar lo que hay (se suman las filas que faltan): las equivalencias aprobadas ya vienen cargadas. */
    private const SUMAR = ['equivalencias_afip'];
    /** Tablas con valores iniciales de la instalación: se reemplazan sin pedir confirmación. */
    private const SEMILLAS = ['tasas_iva', 'provincias', 'unidad_venta'];
    private const MAX_AVISOS = 200;
    /** Catálogos donde un código que usan los clientes/artículos y no existe se agrega solo (para no perderlo). */
    private const CREAR_FALTANTES = ['provincias', 'vendedores', 'condicionpago', 'transportes', 'rubros'];

    /** @var array<string,array<string,int>> columna => código faltante => cuántos lo usan (tabla en curso) */
    private array $faltantes = [];

    /** Modo incremental (agente de sincronización): suma solo los comprobantes que la web todavía no tiene. */
    private bool $incremental = false;
    /** Modo maestros del agente: clientes y artículos se suman y se actualizan por código (nada se borra). */
    private bool $maestros = false;
    private int $filasAfectadas = 0;
    private string $origen = '';
    /** @var array<string,bool> comprobantes ya cargados (clave tipo|letra|sucursal|número|fecha) */
    private array $existentes = [];
    /** @var array<string,bool> comprobantes nuevos de esta pasada */
    private array $nuevos = [];
    /** @var array<string,bool> lo mismo sin fecha (el IVA del VFP no la tiene) */
    private array $nuevosSinFecha = [];

    private ?Dbc $dbc = null;
    /** @var resource|null */
    private $sql = null;
    /** @var array<string,array{leidas:int,grabadas:int,avisos:string[]}> */
    private array $resumen = [];

    public function __construct(private PDO $pdo, private string $carpeta)
    {
        $this->carpeta = rtrim($carpeta, '/\\');
        if (!is_dir($this->carpeta)) {
            throw new \RuntimeException("No existe la carpeta {$carpeta}.");
        }
        $dbc = Dbf::buscar($this->carpeta, 'datos.dbc');
        if ($dbc !== null) {
            $this->dbc = new Dbc($dbc);
        }
    }

    /**
     * @param string[]|null $tablas null = todas las de TABLAS
     * @return array<string,array{leidas:int,grabadas:int,avisos:string[]}>
     */
    /**
     * Sincronización incremental de comprobantes desde el VFP: no borra nada ni toca los maestros; agrega los comprobantes (con su IVA, renglones, caja y
     * cuenta corriente) que todavía no están, por la clave tipo + letra + sucursal + número + fecha. Los que ya estaban se saltean.
     *
     * @return array<string,array{leidas:int,grabadas:int,avisos:string[]}>
     */
    public function sumarComprobantes(string $origen): array
    {
        $this->incremental = true;
        $this->origen = $origen;
        return $this->migrar(['subdiario', 'iva', 'estadistica', 'caja', 'ctact'], true);
    }

    /**
     * Sincronización de maestros desde el VFP (agente): agrega los clientes y artículos nuevos y actualiza los que ya estaban, por código, solo con las
     * columnas que trae el VFP (lo que agrega la web —activo, EAN, SKU, bultos, control de stock— no se toca). No borra nada. D-21.
     *
     * @return array<string,array{leidas:int,grabadas:int,avisos:string[]}>
     */
    public function sumarMaestros(string $origen, array $tablas = ['clientes', 'articulos']): array
    {
        $this->maestros = true;
        $this->origen = $origen;
        return $this->migrar(array_values(array_intersect($tablas, ['clientes', 'articulos'])), true);
    }

    public function migrar(?array $tablas = null, bool $reemplazar = false, bool $simular = false, ?string $archivoSql = null): array
    {
        $tablas ??= array_keys(self::TABLAS);
        foreach ($tablas as $t) {
            if (!isset(self::TABLAS[$t])) {
                throw new \RuntimeException("Tabla desconocida: {$t}. Las que se migran: " . implode(', ', array_keys(self::TABLAS)) . '.');
            }
        }
        if ($this->dbc === null) {
            $this->resumen['datos.dbc'] = ['leidas' => 0, 'grabadas' => 0, 'avisos' => ['No está datos.dbc: se usan los nombres cortos de los DBF (los campos de más de 10 letras no se van a encontrar).']];
        }
        if (!$reemplazar) {
            $conDatos = [];
            foreach ($tablas as $t) {
                if (!in_array($t, [...self::SEMILLAS, ...self::SUMAR, 'parametros', 'usuarios'], true)
                    && (int) $this->pdo->query("SELECT COUNT(*) FROM `{$t}`")->fetchColumn() > 0) {
                    $conDatos[] = $t;
                }
            }
            if ($conDatos !== []) {
                throw new \RuntimeException('Estas tablas ya tienen datos: ' . implode(', ', $conDatos)
                    . '. Para borrarlos y cargar los del VFP, repetí con la opción de reemplazar.');
            }
        }
        if ($archivoSql !== null) {
            $fh = fopen($archivoSql, 'wb');
            if ($fh === false) {
                throw new \RuntimeException("No se pudo crear {$archivoSql}.");
            }
            $this->sql = $fh;
            fwrite($fh, "-- Migración VFP -> facturador web, " . date('d/m/Y H:i') . "\nSET NAMES utf8mb4;\nSTART TRANSACTION;\n");
        }

        $this->pdo->beginTransaction();
        try {
            foreach ($tablas as $t) {
                match ($t) {
                    'parametros' => $this->parametros(),
                    'usuarios' => $this->usuarios(),
                    default => $this->tabla($t),
                };
            }
            if (array_intersect($tablas, ['subdiario', 'iva', 'estadistica', 'caja', 'ctact']) !== []) {
                $this->vincular();
            }
            if (array_intersect($tablas, ['caja', 'ctact', 'cobra', 'imputaciones']) !== []) {
                $this->vincularCuentaCorriente();
            }
            if (in_array('clientes', $tablas, true)) {
                // El próximo cliente sigue la numeración del VFP.
                $this->ejecutar('UPDATE parametros SET ultimo_cliente = GREATEST(ultimo_cliente, (SELECT COALESCE(MAX(codigo), 0) FROM clientes)) WHERE id = 1', []);
            }
            if ($simular) {
                $this->pdo->rollBack();
            } else {
                $this->pdo->prepare('INSERT INTO migracion_vfp (origen, tablas, resumen) VALUES (?, ?, ?)')
                    ->execute([mb_substr($this->origen !== '' ? $this->origen : $this->carpeta, 0, 255), implode(', ', $tablas), json_encode($this->resumen, JSON_UNESCAPED_UNICODE)]);
                $this->pdo->commit();
            }
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        } finally {
            if ($this->sql !== null) {
                fwrite($this->sql, "COMMIT;\n");
                fclose($this->sql);
                $this->sql = null;
            }
        }
        return $this->resumen;
    }

    /**
     * Cuenta corriente: el punto de venta de cada recibo, la fila de ctact y los medios de pago (caja) de cada recibo (cobra_id) y, en cada
     * imputación, qué fila de ctact se canceló (ctact_db) y cuál la canceló (ctact_cr). El VFP las relaciona por cliente + tipo + número + fecha;
     * lo que no se encuentra queda cargado igual, sin vincular, y se avisa.
     */
    private function vincularCuentaCorriente(): void
    {
        $r = &$this->iniciar('vinculos cuenta corriente');
        $this->ejecutar('UPDATE cobra c JOIN sucursales s ON s.codigo = c.sucursal SET c.prefijo = s.prefijo WHERE c.prefijo = 0', []);
        $this->ejecutar("UPDATE ctact x JOIN cobra c ON c.sucursal = x.sucursal AND c.recibo = x.numero AND c.fecha = x.fecha SET x.cobra_id = c.id WHERE x.tipo = 'RC' AND x.cobra_id IS NULL", []);
        $this->ejecutar("UPDATE caja x JOIN cobra c ON c.sucursal = x.sucursal AND c.recibo = x.comprobante AND c.fecha = x.fecha SET x.cobra_id = c.id WHERE x.tipo = 'RC' AND x.cobra_id IS NULL", []);
        $cond = 'd.cliente = i.clientes AND d.tipo = i.tipodb AND d.numero = i.nrcodb AND d.fecha = i.fecodb';
        $this->ejecutar("UPDATE imputaciones i JOIN ctact d ON {$cond} AND d.letra = i.letradb SET i.ctact_db = d.fe_clave WHERE i.ctact_db IS NULL", []);
        $this->ejecutar("UPDATE imputaciones i JOIN ctact d ON {$cond} SET i.ctact_db = d.fe_clave WHERE i.ctact_db IS NULL", []); // filas viejas sin letra
        $this->ejecutar('UPDATE imputaciones i JOIN ctact c ON c.cliente = i.clientes AND c.tipo = i.tipocr AND c.numero = i.nrcocr AND c.fecha = i.fecocr SET i.ctact_cr = c.fe_clave WHERE i.ctact_cr IS NULL', []);
        $this->ejecutar("UPDATE imputaciones i JOIN ctact c ON c.fe_clave = i.ctact_cr SET i.cobra_id = c.cobra_id WHERE i.cobra_id IS NULL AND i.tipocr = 'RC'", []);
        $r['grabadas'] = (int) $this->pdo->query('SELECT COUNT(*) FROM imputaciones WHERE ctact_db IS NOT NULL AND ctact_cr IS NOT NULL')->fetchColumn();
        $sin = (int) $this->pdo->query('SELECT COUNT(*) FROM imputaciones WHERE ctact_db IS NULL OR ctact_cr IS NULL')->fetchColumn();
        if ($sin > 0) {
            $this->aviso($r, "{$sin} imputación(es) no encontraron su comprobante en la cuenta corriente (ya estaban así en el VFP): quedan cargadas sin vincular.");
        }
        $sinCobra = (int) $this->pdo->query("SELECT COUNT(*) FROM ctact WHERE tipo = 'RC' AND cobra_id IS NULL")->fetchColumn();
        if ($sinCobra > 0) {
            $this->aviso($r, "{$sinCobra} recibo(s) de la cuenta corriente no tienen su fila en cobra (ya estaban así en el VFP): no se pueden anular desde la web.");
        }
    }

    /**
     * Une cada renglón, forma de pago, cuenta corriente e IVA con su comprobante (subdiario_id). El VFP los relaciona por
     * tipo + letra + sucursal + número + fecha (el número solo no alcanza: se repite entre fechas); el IVA no tiene fecha, así
     * que si el número está repetido se elige el comprobante con el mismo neto. Lo que no encuentra comprobante queda sin
     * vincular y se avisa (son huérfanos que ya estaban en el VFP).
     */
    private function vincular(): void
    {
        $r = &$this->iniciar('vinculos');
        $filas = [
            'estadistica' => 'x.comprobante', 'caja' => 'x.comprobante', 'ctact' => 'x.numero',
        ];
        foreach ($filas as $tabla => $colNumero) {
            $where = $tabla === 'ctact' ? "AND x.tipo IN ('FC', 'NC', 'ND')" : '';
            $this->ejecutar("UPDATE `{$tabla}` x JOIN subdiario s ON s.tipo = x.tipo AND s.letra = x.letra AND s.sucursal = x.sucursal
                              AND s.comprobante = {$colNumero} AND s.fecha = x.fecha SET x.subdiario_id = s.fe_clave WHERE x.subdiario_id IS NULL {$where}", []);
            $r['grabadas'] += (int) $this->pdo->query("SELECT COUNT(*) FROM `{$tabla}` WHERE subdiario_id IS NOT NULL")->fetchColumn();
            $huerfanas = (int) $this->pdo->query("SELECT COUNT(*) FROM `{$tabla}` x WHERE x.subdiario_id IS NULL " . ($tabla === 'ctact' ? "AND x.tipo IN ('FC', 'NC', 'ND')" : ''))->fetchColumn();
            if ($huerfanas > 0) {
                $this->aviso($r, "{$huerfanas} fila(s) de {$tabla} no tienen comprobante en el subdiario (ya estaban así en el VFP): quedan cargadas sin vincular.");
            }
        }
        // IVA: número único => directo; repetido => el comprobante con el mismo neto, en orden de fecha
        $this->ejecutar("UPDATE iva x JOIN (SELECT tipo, letra, sucursal, comprobante, MIN(fe_clave) AS id FROM subdiario GROUP BY tipo, letra, sucursal, comprobante HAVING COUNT(*) = 1) s
                          ON s.tipo = x.tipo AND s.letra = x.letra AND s.sucursal = x.sucursal AND s.comprobante = x.comprobante SET x.subdiario_id = s.id WHERE x.subdiario_id IS NULL", []);
        $pend = $this->pdo->query('SELECT fe_clave, tipo, letra, sucursal, comprobante, monto FROM iva WHERE subdiario_id IS NULL ORDER BY fe_clave')->fetchAll();
        $candidatos = $this->pdo->prepare('SELECT fe_clave, neto_grabado FROM subdiario WHERE tipo = ? AND letra = ? AND sucursal = ? AND comprobante = ? ORDER BY fecha');
        $usados = [];
        $sinPar = 0;
        foreach ($pend as $x) {
            $candidatos->execute([$x['tipo'], $x['letra'], $x['sucursal'], $x['comprobante']]);
            $cands = $candidatos->fetchAll();
            $elegido = null;
            foreach ($cands as $c) { // primero el que tiene el mismo neto y no se usó
                if (!isset($usados[$c['fe_clave']]) && abs((float) $c['neto_grabado'] - (float) $x['monto']) < 0.005) {
                    $elegido = (int) $c['fe_clave'];
                    break;
                }
            }
            if ($elegido === null) {
                foreach ($cands as $c) {
                    if (!isset($usados[$c['fe_clave']])) {
                        $elegido = (int) $c['fe_clave'];
                        break;
                    }
                }
            }
            if ($elegido === null) {
                $sinPar++;
                continue;
            }
            $usados[$elegido] = true;
            $this->ejecutar('UPDATE iva SET subdiario_id = ? WHERE fe_clave = ?', [$elegido, $x['fe_clave']]);
        }
        $r['grabadas'] += (int) $this->pdo->query('SELECT COUNT(*) FROM iva WHERE subdiario_id IS NOT NULL')->fetchColumn();
        $huerfanas = (int) $this->pdo->query('SELECT COUNT(*) FROM iva WHERE subdiario_id IS NULL')->fetchColumn();
        if ($huerfanas > 0) {
            $this->aviso($r, "{$huerfanas} fila(s) de iva no tienen comprobante en el subdiario (ya estaban así en el VFP): quedan cargadas sin vincular.");
        }
        if ($usados !== []) {
            $this->aviso($r, count($usados) . ' fila(s) de IVA de comprobantes con número repetido se vincularon por el neto (el IVA del VFP no tiene fecha): conviene controlar el Libro IVA de esos comprobantes.');
        }
        $sinIva = (int) $this->pdo->query("SELECT COUNT(*) FROM subdiario s WHERE s.iva_1 > 0 AND NOT EXISTS (SELECT 1 FROM iva i WHERE i.subdiario_id = s.fe_clave)")->fetchColumn();
        if ($sinIva > 0) {
            $this->aviso($r, "{$sinIva} comprobante(s) con IVA no tienen filas de IVA (ya estaban así en el VFP).");
        }
    }

    /** Copia una tabla del VFP a la del mismo esquema en MySQL (borrando lo que había). */
    private function tabla(string $tabla): void
    {
        $r = &$this->iniciar($tabla);
        $dbf = $this->abrir(self::TABLAS[$tabla], $r);
        if ($dbf === null) {
            return;
        }
        $columnas = $this->columnas($tabla);
        $nombres = $this->nombresLargos(self::TABLAS[$tabla], $dbf, $r, array_keys($columnas));
        $usar = array_values(array_intersect(array_keys($columnas), $nombres));
        if (in_array($tabla, ['iva', 'estadistica', 'caja', 'ctact'], true)) {
            // el número interno de estas tablas se repite en el VFP: acá lo asigna MySQL (el vínculo con el comprobante es subdiario_id)
            $usar = array_values(array_diff($usar, ['fe_clave']));
        }
        if (!in_array($tabla, self::SUMAR, true) && !$this->incremental && !$this->maestros) {
            $this->ejecutar("DELETE FROM `{$tabla}`", []);
        }
        if ($this->incremental && $tabla === 'subdiario') {
            foreach ($this->pdo->query("SELECT CONCAT_WS('|', tipo, letra, sucursal, comprobante, fecha) FROM subdiario")->fetchAll(PDO::FETCH_COLUMN) as $k) {
                $this->existentes[(string) $k] = true;
            }
        }

        $cortos = $dbf->nombres();
        $ins = 'INSERT INTO `' . $tabla . '` (`' . implode('`, `', $usar) . '`) VALUES (' . implode(', ', array_fill(0, count($usar), '?')) . ')';
        if ($this->maestros) {
            $sets = array_map(static fn (string $c) => "`{$c}` = VALUES(`{$c}`)", array_diff($usar, ['codigo']));
            $ins .= ' ON DUPLICATE KEY UPDATE ' . implode(', ', $sets);
            $r['actualizadas'] = 0;
        }
        $refs = $this->referencias($tabla);
        $this->faltantes = [];
        foreach ($dbf->filas() as $fila) {
            $r['leidas']++;
            $porNombre = array_combine($nombres, array_map(static fn ($c) => $fila[$c], $cortos));
            $valores = [];
            foreach ($usar as $col) {
                $valores[] = $this->convertir($columnas[$col], $porNombre[$col], $tabla, $col, $r);
            }
            $fila = array_combine($usar, $valores);
            if ($this->incremental && !$this->esNuevo($tabla, $fila)) {
                continue; // ya está en la web (o su comprobante ya estaba)
            }
            $this->revisar($tabla, $fila, $refs, $r);
            try {
                $this->ejecutar($ins, $valores);
                if ($this->maestros) {
                    // MySQL: 1 = fila nueva, 2 = fila existente que cambió, 0 = igual que antes
                    if ($this->filasAfectadas === 1) {
                        $r['grabadas']++;
                    } elseif ($this->filasAfectadas === 2) {
                        $r['actualizadas']++;
                    }
                } else {
                    $r['grabadas']++;
                }
            } catch (\PDOException $e) {
                if ($e->getCode() !== '23000') {
                    throw $e;
                }
                if (!in_array($tabla, self::SUMAR, true)) {
                    $this->aviso($r, 'No se cargó (' . mb_substr((string) ($e->errorInfo[2] ?? $e->getMessage()), 0, 120) . '): ' . $this->describir($tabla, $fila));
                }
            }
        }
        $this->completarFaltantes($tabla, $r);
    }

    /** Modo incremental: ¿esta fila hay que cargarla? El comprobante se carga si no estaba; sus hijas solo si el comprobante es nuevo. @param array<string,mixed> $fila */
    private function esNuevo(string $tabla, array $fila): bool
    {
        $k = static fn (array $f, string $num, bool $fecha = true) => implode('|', [$f['tipo'] ?? '', $f['letra'] ?? '', $f['sucursal'] ?? '', $f[$num] ?? '', $fecha ? ($f['fecha'] ?? '') : '']);
        if ($tabla === 'subdiario') {
            $clave = $k($fila, 'comprobante');
            if (isset($this->existentes[$clave])) {
                return false;
            }
            $this->nuevos[$clave] = true;
            $this->nuevosSinFecha[$k($fila, 'comprobante', false)] = true;
            return true;
        }
        return match ($tabla) {
            'estadistica', 'caja' => isset($this->nuevos[$k($fila, 'comprobante')]),
            'ctact' => isset($this->nuevos[$k($fila, 'numero')]),
            'iva' => isset($this->nuevosSinFecha[$k($fila, 'comprobante', false)]),
            default => true,
        };
    }

    /** Códigos usados que no están en su tabla: se agregan (los catálogos simples) o se avisa. */
    private function completarFaltantes(string $tabla, array &$r): void
    {
        $catalogos = ['provincia' => 'provincias', 'vendedor' => 'vendedores', 'condicionpago' => 'condicionpago',
            'transporte' => 'transportes', 'alicuota' => 'tasas_iva', 'rubro' => 'rubros', 'unidad_venta' => 'unidad_venta'];
        foreach ($this->faltantes as $col => $codigos) {
            $cat = $catalogos[$col];
            foreach ($codigos as $codigo => $n) {
                $codigo = (string) $codigo;
                $usan = number_format($n, 0, ',', '.') . ' ' . ($tabla === 'clientes' ? 'cliente(s)' : 'artículo(s)');
                if (in_array($cat, self::CREAR_FALTANTES, true)) {
                    $this->ejecutar("INSERT INTO `{$cat}` (codigo, descripcion) VALUES (?, ?)", [$codigo, mb_strtoupper($col) . ' ' . $codigo . ' (DEL VFP)']);
                    $this->aviso($r, "{$col} \"{$codigo}\" lo usan {$usan} y no estaba en {$cat}: se agregó como \"" . mb_strtoupper($col) . " {$codigo} (DEL VFP)\". Ponele el nombre real en Tablas.");
                } else {
                    $this->aviso($r, "{$col} \"{$codigo}\" lo usan {$usan} y no está en {$cat}. Cargalo en Tablas antes de facturar.");
                }
            }
        }
    }

    /** Datos de la empresa (parametros.dbf, una fila) sobre la fila 1 de parametros. */
    private function parametros(): void
    {
        $r = &$this->iniciar('parametros');
        $dbf = $this->abrir('parametros', $r);
        if ($dbf === null) {
            return;
        }
        $columnas = $this->columnas('parametros');
        $nombres = $this->nombresLargos('parametros', $dbf, $r, array_keys($columnas));
        $fila = null;
        foreach ($dbf->filas() as $f) {
            $r['leidas']++;
            $fila ??= array_combine($nombres, array_values($f));
        }
        if ($fila === null) {
            $this->aviso($r, 'parametros.dbf está vacía.');
            return;
        }
        $set = [];
        foreach (['empresa', 'cuit', 'iva', 'tk_direccion', 'tk_telefono', 'tk_iva', 'tk_i_brutos', 'mail', 'ultimo_cliente', 'ultimo_recibo', 'por_impuesto'] as $col) {
            if (array_key_exists($col, $fila) && isset($columnas[$col])) {
                $set[$col] = $this->convertir($columnas[$col], $fila[$col], 'parametros', $col, $r);
            }
        }
        // La razón social suele estar en fe_configuracion (en parametros.empresa a veces hay un número).
        $fe = $this->abrir('fe_configuracion', $r, false);
        if ($fe !== null) {
            foreach ($fe->filas() as $f) {
                $nombre = trim((string) (array_values($f)[1] ?? ''));
                if ($nombre !== '' && mb_strlen(trim((string) ($set['empresa'] ?? ''))) < 4) {
                    $set['empresa'] = mb_substr($nombre, 0, 120);
                    $this->aviso($r, "Razón social tomada de fe_configuracion: {$nombre}. Revisala en Tablas → Parámetros.");
                }
                break;
            }
        }
        if ($set !== []) {
            $this->ejecutar('UPDATE parametros SET ' . implode(', ', array_map(static fn ($c) => "`{$c}` = ?", array_keys($set))) . ', actualizacion = NOW() WHERE id = 1', array_values($set));
            $r['grabadas'] = 1;
        }
    }

    /** cla_usuarios => usuarios desactivados, con contraseña al azar (la del VFP no se migra). */
    private function usuarios(): void
    {
        $r = &$this->iniciar('usuarios');
        $dbf = $this->abrir('cla_usuarios', $r);
        if ($dbf === null) {
            return;
        }
        $roles = $this->pdo->query("SELECT codigo, id FROM roles WHERE codigo IN ('admin', 'operador')")->fetchAll(PDO::FETCH_KEY_PAIR);
        $porUsuario = [];
        foreach ($dbf->filas() as $f) {
            $r['leidas']++;
            $vfp = trim((string) ($f['NOMBRE'] ?? ''));
            $usuario = trim((string) preg_replace('/[^a-z0-9._-]+/', '.', mb_strtolower((string) iconv('UTF-8', 'ASCII//TRANSLIT', $vfp))), '.');
            if (strlen($usuario) < 3) {
                $this->aviso($r, "El usuario \"{$vfp}\" no se migra (el nombre tiene que tener 3 letras o más). Si se usa, crealo a mano.");
                continue;
            }
            $sup = ($f['SUPERVISOR'] ?? false) === true;
            if (isset($porUsuario[$usuario])) {
                $this->aviso($r, "\"{$vfp}\" y \"{$porUsuario[$usuario]['vfp']}\" quedan como un solo usuario ({$usuario}).");
                $porUsuario[$usuario]['sup'] = $porUsuario[$usuario]['sup'] || $sup;
                continue;
            }
            $porUsuario[$usuario] = ['vfp' => $vfp, 'sup' => $sup];
        }
        $existe = $this->pdo->prepare('SELECT 1 FROM usuarios WHERE usuario = ?');
        foreach ($porUsuario as $usuario => $u) {
            $existe->execute([$usuario]);
            if ($existe->fetchColumn() !== false) {
                $this->aviso($r, "El usuario {$usuario} ya existía en la web: no se tocó.");
                continue;
            }
            $this->ejecutar(
                'INSERT INTO usuarios (usuario, nombre, password_hash, rol_id, activo, debe_cambiar_clave, usuario_vfp) VALUES (?, ?, ?, ?, 0, 1, ?)',
                [$usuario, mb_convert_case($u['vfp'], MB_CASE_TITLE), password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT),
                    (int) $roles[$u['sup'] ? 'admin' : 'operador'], mb_substr($u['vfp'], 0, 20)]
            );
            $r['grabadas']++;
        }
        $this->aviso($r, 'Los usuarios quedan desactivados: activalos y generales una contraseña temporal en Usuarios. Supervisores => Administrador; el resto => Operador.');
    }

    // ------------------------------------------------------------------ auxiliares

    /** @return array{leidas:int,grabadas:int,avisos:string[]} */
    private function &iniciar(string $tabla): array
    {
        $this->resumen[$tabla] = ['leidas' => 0, 'grabadas' => 0, 'avisos' => []];
        return $this->resumen[$tabla];
    }

    private function aviso(array &$r, string $texto): void
    {
        if (count($r['avisos']) < self::MAX_AVISOS) {
            $r['avisos'][] = $texto;
        } elseif (count($r['avisos']) === self::MAX_AVISOS) {
            $r['avisos'][] = 'Hay más avisos que no se muestran.';
        }
    }

    private function abrir(string $dbf, array &$r, bool $avisar = true): ?Dbf
    {
        $archivo = Dbf::buscar($this->carpeta, $dbf . '.dbf');
        if ($archivo === null) {
            if ($avisar) {
                $this->aviso($r, "No está {$dbf}.dbf en la carpeta: la tabla queda como estaba.");
            }
            return null;
        }
        return new Dbf($archivo);
    }

    /**
     * Nombres largos (datos.dbc) de los campos del DBF, en orden. Sin .dbc (o si no coincide), los cortos en
     * minúsculas; un nombre corto de 10 letras que es el comienzo de una sola columna destino se toma como esa
     * (DESCRIPCIO => descripcion).
     *
     * @param string[] $destino columnas de la tabla MySQL
     * @return string[]
     */
    private function nombresLargos(string $tabla, Dbf $dbf, array &$r, array $destino): array
    {
        $cortos = array_map('mb_strtolower', $dbf->nombres());
        $largos = $this->dbc?->campos($tabla);
        if ($largos !== null && count($largos) === count($cortos)) {
            return $largos;
        }
        if ($largos !== null) {
            $this->aviso($r, "La estructura de {$tabla}.dbf no coincide con datos.dbc: se usan los nombres cortos.");
        }
        return array_map(static function (string $c) use ($destino, $cortos): string {
            if (in_array($c, $destino, true) || strlen($c) < 10) {
                return $c;
            }
            $candidatas = array_values(array_filter($destino, static fn ($d) => str_starts_with($d, $c) && !in_array($d, $cortos, true)));
            return count($candidatas) === 1 ? $candidatas[0] : $c;
        }, $cortos);
    }

    /** @return array<string,array<string,mixed>> columna => tipo, nulo, largo, escala, default */
    private function columnas(string $tabla): array
    {
        $st = $this->pdo->prepare(
            'SELECT COLUMN_NAME, DATA_TYPE, COLUMN_TYPE, IS_NULLABLE, CHARACTER_MAXIMUM_LENGTH, NUMERIC_SCALE, COLUMN_DEFAULT
               FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? ORDER BY ORDINAL_POSITION'
        );
        $st->execute([$tabla]);
        $out = [];
        foreach ($st->fetchAll() as $c) {
            $out[(string) $c['COLUMN_NAME']] = $c;
        }
        return $out;
    }

    /** Convierte un valor del DBF al tipo de la columna MySQL. */
    private function convertir(array $col, mixed $v, string $tabla, string $nombre, array &$r): mixed
    {
        $tipo = (string) $col['DATA_TYPE'];
        $nulo = $col['IS_NULLABLE'] === 'YES';
        $porDefecto = static function () use ($col, $nulo, $tipo): mixed {
            if ($nulo) {
                return null;
            }
            if ($col['COLUMN_DEFAULT'] !== null) {
                return $col['COLUMN_DEFAULT'];
            }
            return in_array($tipo, ['char', 'varchar', 'text', 'mediumtext'], true) ? '' : 0;
        };
        if (is_string($v)) {
            $v = trim($v);
        }
        if ($v === null || $v === '') {
            return $porDefecto();
        }
        switch ($tipo) {
            case 'char':
            case 'varchar':
            case 'text':
            case 'mediumtext':
                if (is_bool($v)) {
                    $v = $v ? '1' : '0';
                } elseif (is_float($v) || is_int($v)) {
                    if ((float) $v == 0.0 && $nulo) {
                        return null;
                    }
                    $v = is_float($v) && floor($v) == $v ? (string) (int) $v : (string) $v;
                }
                $max = (int) $col['CHARACTER_MAXIMUM_LENGTH'];
                if ($max > 0 && mb_strlen($v) > $max) {
                    $this->aviso($r, "{$nombre} recortado a {$max} letras: \"{$v}\"");
                    $v = mb_substr($v, 0, $max);
                }
                return $v;
            case 'tinyint':
            case 'smallint':
            case 'mediumint':
            case 'int':
            case 'bigint':
                if (is_bool($v)) {
                    return $v ? 1 : 0;
                }
                $n = is_numeric($v) ? (int) round((float) $v) : null;
                if ($n === null) {
                    $this->aviso($r, "{$nombre}: \"{$v}\" no es un número, queda vacío.");
                    return $porDefecto();
                }
                if ($n === 0 && $nulo && in_array($nombre, ['cuit', 'transporte', 'proveedor'], true)) {
                    return null; // 0 en el VFP = sin dato
                }
                if ($n < 0 && str_contains((string) $col['COLUMN_TYPE'], 'unsigned')) {
                    $this->aviso($r, "{$nombre}: {$n} es negativo, queda en 0.");
                    return 0;
                }
                return $n;
            case 'decimal':
            case 'float':
            case 'double':
                return is_numeric($v) ? round((float) $v, (int) ($col['NUMERIC_SCALE'] ?? 2)) : $porDefecto();
            case 'date':
                return is_string($v) && preg_match('/^\d{4}-\d{2}-\d{2}/', $v) === 1 ? substr($v, 0, 10) : $porDefecto();
            case 'datetime':
            case 'timestamp':
                return is_string($v) && preg_match('/^\d{4}-\d{2}-\d{2}/', $v) === 1 ? (strlen($v) === 10 ? $v . ' 00:00:00' : $v) : $porDefecto();
            default:
                return $v;
        }
    }

    /** Códigos de las tablas que se controlan en clientes y artículos. @return array<string,array<string,bool>> */
    private function referencias(string $tabla): array
    {
        $buscar = match ($tabla) {
            'clientes' => ['provincia' => 'provincias', 'vendedor' => 'vendedores', 'condicionpago' => 'condicionpago', 'transporte' => 'transportes'],
            'articulos' => ['alicuota' => 'tasas_iva', 'rubro' => 'rubros', 'unidad_venta' => 'unidad_venta'],
            default => [],
        };
        $out = [];
        foreach ($buscar as $col => $t) {
            $out[$col] = array_fill_keys(array_map('strval', $this->pdo->query("SELECT codigo FROM `{$t}`")->fetchAll(PDO::FETCH_COLUMN)), true);
        }
        return $out;
    }

    /** Avisos de datos que en la web van a molestar (CUIT inválido, códigos que no existen). */
    private function revisar(string $tabla, array $fila, array $refs, array &$r): void
    {
        foreach ($refs as $col => $codigos) {
            $v = $fila[$col] ?? null;
            if ($v !== null && $v !== '' && !isset($codigos[(string) $v])) {
                $this->faltantes[$col][(string) $v] = ($this->faltantes[$col][(string) $v] ?? 0) + 1;
            }
        }
        if ($tabla === 'clientes') {
            $cuit = $fila['cuit'] ?? null;
            if ($cuit !== null && !\App\Core\Valida::cuit(str_pad((string) $cuit, 11, '0', STR_PAD_LEFT))) {
                $this->aviso($r, $this->describir($tabla, $fila) . ": el CUIT {$cuit} no es válido.");
            }
            if ($cuit === null && !in_array((int) ($fila['iva'] ?? 0), [2, 3], true)) {
                $this->aviso($r, $this->describir($tabla, $fila) . ': no tiene CUIT y no es consumidor final.');
            }
        }
    }

    private function describir(string $tabla, array $fila): string
    {
        $cod = $fila['codigo'] ?? ($fila['articulo'] ?? '');
        $nom = $fila['razon_social'] ?? ($fila['descripcion'] ?? '');
        return trim(rtrim($tabla, 's') . " {$cod} {$nom}");
    }

    /** Ejecuta en la base y, si se pidió, lo deja escrito en el .sql. */
    private function ejecutar(string $sql, array $params): void
    {
        $st = $this->pdo->prepare($sql);
        $st->execute($params);
        $this->filasAfectadas = $st->rowCount();
        if ($this->sql !== null) {
            $i = 0;
            $texto = preg_replace_callback('/\?/', function () use ($params, &$i): string {
                $v = $params[$i++] ?? null;
                return match (true) {
                    $v === null => 'NULL',
                    is_int($v), is_float($v) => (string) $v,
                    default => $this->pdo->quote((string) $v),
                };
            }, $sql);
            fwrite($this->sql, $texto . ";\n");
        }
    }
}
