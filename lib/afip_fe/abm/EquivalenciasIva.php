<?php
namespace OysAfip;

/**
 * ABM de la equivalencia "código de condición de IVA del sistema" -> "condición de IVA del receptor de ARCA" (RG 5616).
 *
 * Se guarda en la tabla equivalencias_afip de los sistemas O&S, en las filas con tabla = 'Equivaliencia':
 *   codigooys  = código de IVA del sistema (en Reciclados: cliente.catg_iva / subdiario.iva_cliente)
 *   codigoafip = Id de ARCA (FEParamGetCondicionIvaReceptor)
 *   descripcion = descripción de ARCA
 * Es lo que leen el webservice C# y el adaptador OysSubdiario para mandar CondicionIVAReceptorId.
 *
 * No depende de ninguna web: arma operaciones {t, a, v, k} y las graba con un callback.
 *   - Por defecto las ejecuta directo con PDO (sistemas solo nube, p. ej. Oeste Parts).
 *   - Reciclados pasa un callback que las manda a la cola, para que el agente las grabe en SQL Server.
 */
class EquivalenciasIva
{
    const TABLA_TIPO = 'Equivaliencia';   // así está escrito en los sistemas O&S (con la "i" de más)

    /** Condiciones de ARCA (FEParamGetCondicionIvaReceptor). Se pueden refrescar con condicionesDesdeArca(). */
    public static $condicionesArca = array(
        1 => 'IVA Responsable Inscripto',
        4 => 'IVA Sujeto Exento',
        5 => 'Consumidor Final',
        6 => 'Responsable Monotributo',
        7 => 'Sujeto No Categorizado',
        8 => 'Proveedor del Exterior',
        9 => 'Cliente del Exterior',
        10 => 'IVA Liberado - Ley N° 19.640',
        13 => 'Monotributista Social',
        15 => 'IVA No Alcanzado',
        16 => 'Monotributo Trabajador Independiente Promovido',
    );

    /** Códigos de IVA de los sistemas O&S (Frm_Cliente) y su equivalencia habitual en ARCA */
    public static $codigosOys = array(
        1 => 'Responsable Inscripto',
        2 => 'Responsable No Inscripto',
        3 => 'Monotributo',
        4 => 'Exento',
        5 => 'Consumidor Final',
    );
    public static $sugeridas = array(1 => 1, 3 => 6, 4 => 4, 5 => 5);   // RNI (2) ya no existe en ARCA: sin sugerencia

    private $pdo;
    private $tabla;
    private $grabar;

    /**
     * @param \PDO          $pdo     base de la empresa (MySQL/MariaDB o SQL Server)
     * @param callable|null $grabar  function(array $datos, $tipo, $referencia) que graba las operaciones.
     *                               null = ejecutar directo con PDO. $datos = ['ops' => [...], 'conflicto' => , 'guardas' => ]
     */
    public function __construct(\PDO $pdo, $grabar = null, $tabla = 'equivalencias_afip')
    {
        $this->pdo = $pdo;
        $this->tabla = preg_replace('/[^A-Za-z0-9_]/', '', $tabla);
        $this->grabar = $grabar;
    }

    public function tabla()
    {
        return $this->tabla;
    }

    private function mysql()
    {
        return $this->pdo->getAttribute(\PDO::ATTR_DRIVER_NAME) === 'mysql';
    }

    public function existeTabla()
    {
        try {
            $this->pdo->query('SELECT 1 FROM ' . $this->tabla . ' WHERE 1 = 0');
            return true;
        } catch (\PDOException $e) {
            return false;
        }
    }

    /**
     * Crea la tabla si no existe (solo MySQL/MariaDB), con la misma estructura que crea el agente de sincronización
     * en la nube de Reciclados. En SQL Server la tabla ya existe en los sistemas O&S.
     */
    public function asegurarTabla()
    {
        if ($this->existeTabla()) {
            return false;
        }
        if (!$this->mysql()) {
            throw new AfipException('No existe la tabla ' . $this->tabla);
        }
        $this->pdo->exec('CREATE TABLE IF NOT EXISTS ' . $this->tabla . ' (
            tabla varchar(30) NOT NULL,
            codigoafip varchar(4) NOT NULL,
            codigooys varchar(4) NOT NULL DEFAULT \'\',
            descripcion varchar(100) NULL,
            PRIMARY KEY (tabla, codigoafip, codigooys)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
        return true;
    }

    /** Filas 'Equivaliencia' ordenadas por código del sistema: [['codigooys'=>'1','codigoafip'=>'1','descripcion'=>...], ...] */
    public function listar()
    {
        if (!$this->existeTabla()) {
            return array();
        }
        $st = $this->pdo->prepare('SELECT tabla, codigoafip, codigooys, descripcion FROM ' . $this->tabla . ' WHERE UPPER(tabla) = ?');
        $st->execute(array(strtoupper(self::TABLA_TIPO)));
        $out = array();
        foreach ($st->fetchAll(\PDO::FETCH_ASSOC) as $r) {
            $r = array_change_key_case($r, CASE_LOWER);
            $r['codigooys'] = trim((string) $r['codigooys']);
            $r['codigoafip'] = trim((string) $r['codigoafip']);
            $r['tabla'] = trim((string) $r['tabla']);
            $out[] = $r;
        }
        usort($out, function ($a, $b) {
            return (int) $a['codigooys'] - (int) $b['codigooys'];
        });
        return $out;
    }

    /** Equivalencia de un código del sistema, o null */
    public function buscar($codigoOys)
    {
        foreach ($this->listar() as $r) {
            if ($r['codigooys'] === trim((string) $codigoOys)) {
                return $r;
            }
        }
        return null;
    }

    /** Códigos en uso que no tienen equivalencia (para avisar). $enUso = lista de códigos del sistema. */
    public function faltantes(array $enUso)
    {
        $hay = array();
        foreach ($this->listar() as $r) {
            $hay[$r['codigooys']] = true;
        }
        $out = array();
        foreach ($enUso as $c) {
            $c = trim((string) $c);
            if ($c !== '' && !isset($hay[$c])) {
                $out[$c] = $c;
            }
        }
        ksort($out);
        return array_values($out);
    }

    public static function textoArca($id)
    {
        return isset(self::$condicionesArca[(int) $id]) ? self::$condicionesArca[(int) $id] : 'Código ' . $id;
    }

    /** Reemplaza la lista de condiciones con la que informa ARCA (requiere conexión y certificado). */
    public static function condicionesDesdeArca(Afip $afip)
    {
        $lista = array();
        foreach ($afip->wsfe->condicionesIvaReceptor() as $c) {
            $lista[(int) $c['Id']] = $c['Desc'];
        }
        if ($lista) {
            ksort($lista);
            self::$condicionesArca = $lista;
        }
        return self::$condicionesArca;
    }

    // ------------------------------------------------------------------ altas, modificaciones y bajas

    /** Controles. Devuelve lista de errores. */
    public function validar($codigoOys, $codigoArca, $alta)
    {
        $e = array();
        $codigoOys = trim((string) $codigoOys);
        if (!preg_match('/^\d{1,4}$/', $codigoOys)) {
            $e[] = 'El código del sistema tiene que ser un número de hasta 4 dígitos.';
        }
        if (!isset(self::$condicionesArca[(int) $codigoArca]) || !preg_match('/^\d{1,4}$/', trim((string) $codigoArca))) {
            $e[] = 'Elegí la condición de IVA de ARCA.';
        }
        if (!$e) {
            $ya = $this->buscar($codigoOys);
            if ($alta && $ya) {
                $e[] = 'El código ' . $codigoOys . ' ya tiene equivalencia (' . self::textoArca($ya['codigoafip']) . '). Modificala.';
            }
            if (!$alta && !$ya) {
                $e[] = 'El código ' . $codigoOys . ' ya no tiene equivalencia.';
            }
        }
        return $e;
    }

    private function k($codigoOys)
    {
        return array('tabla' => self::TABLA_TIPO, 'codigooys' => (string) $codigoOys);
    }

    /** Operaciones de alta / modificación / baja en el formato de la cola {t, a, v, k} */
    public function operaciones($accion, $codigoOys, $codigoArca = null)
    {
        $codigoOys = trim((string) $codigoOys);
        $t = $this->tabla;
        if ($accion === 'baja') {
            return array('ops' => array(array('t' => $t, 'a' => 'delete', 'k' => $this->k($codigoOys))));
        }
        $v = array('codigoafip' => (string) (int) $codigoArca, 'descripcion' => mb_substr(self::textoArca($codigoArca), 0, 100, 'UTF-8'));
        if ($accion === 'alta') {
            return array(
                'ops' => array(array('t' => $t, 'a' => 'insert', 'v' => array('tabla' => self::TABLA_TIPO, 'codigooys' => $codigoOys) + $v)),
                'conflicto' => array('t' => $t, 'k' => $this->k($codigoOys),
                    'mensaje' => 'El código ' . $codigoOys . ' ya tiene equivalencia en el sistema.'),
            );
        }
        return array(
            'ops' => array(array('t' => $t, 'a' => 'update', 'v' => $v, 'k' => $this->k($codigoOys))),
            'guardas' => array(array('t' => $t, 'k' => $this->k($codigoOys), 'campo' => 'codigooys', 'valor' => '-', 'debe_existir' => true,
                'mensaje' => '', 'mensaje_no_existe' => 'El código ' . $codigoOys . ' ya no tiene equivalencia en el sistema.')),
        );
    }

    /**
     * Valida y graba. $accion = alta | modificacion | baja. Devuelve lista de errores (vacía = grabado).
     */
    public function guardar($accion, $codigoOys, $codigoArca = null)
    {
        if ($accion === 'baja') {
            $err = $this->buscar($codigoOys) ? array() : array('El código ' . $codigoOys . ' no tiene equivalencia.');
        } else {
            $err = $this->validar($codigoOys, $codigoArca, $accion === 'alta');
        }
        if ($err) {
            return $err;
        }
        $datos = $this->operaciones($accion, $codigoOys, $codigoArca);
        $ref = ($accion === 'alta' ? 'Alta' : ($accion === 'baja' ? 'Baja' : 'Modificación')) . ' equivalencia IVA ' . trim($codigoOys)
            . ($accion === 'baja' ? '' : ' -> ' . (int) $codigoArca . ' ' . self::textoArca($codigoArca));
        if ($this->grabar) {
            call_user_func($this->grabar, $datos, $accion, $ref);
        } else {
            $this->ejecutar($datos['ops']);
        }
        return array();
    }

    /** Carga de una vez las sugeridas que falten (solo para los códigos indicados, o todos los sugeridos). */
    public function cargarSugeridas(array $soloCodigos = array())
    {
        $hechas = array();
        foreach (self::$sugeridas as $oys => $arca) {
            if ($soloCodigos && !in_array((string) $oys, array_map('strval', $soloCodigos), true)) {
                continue;
            }
            if (!$this->buscar($oys) && !$this->guardar('alta', $oys, $arca)) {
                $hechas[] = $oys;
            }
        }
        return $hechas;
    }

    /** Ejecución directa (sin cola), en una transacción */
    public function ejecutar(array $ops)
    {
        $this->pdo->beginTransaction();
        try {
            foreach ($ops as $op) {
                $w = array();
                $p = array();
                foreach (isset($op['k']) ? $op['k'] : array() as $c => $val) {
                    $w[] = $c . ' = ?';
                    $p[] = $val;
                }
                if ($op['a'] === 'insert') {
                    $st = $this->pdo->prepare('INSERT INTO ' . $op['t'] . ' (' . implode(',', array_keys($op['v'])) . ') VALUES ('
                        . implode(',', array_fill(0, count($op['v']), '?')) . ')');
                    $st->execute(array_values($op['v']));
                } elseif ($op['a'] === 'update') {
                    $set = array();
                    foreach ($op['v'] as $c => $val) {
                        $set[] = $c . ' = ?';
                    }
                    $st = $this->pdo->prepare('UPDATE ' . $op['t'] . ' SET ' . implode(',', $set) . ' WHERE ' . implode(' AND ', $w));
                    $st->execute(array_merge(array_values($op['v']), $p));
                } else {
                    $st = $this->pdo->prepare('DELETE FROM ' . $op['t'] . ' WHERE ' . implode(' AND ', $w));
                    $st->execute($p);
                }
            }
            $this->pdo->commit();
        } catch (\Exception $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }
}
