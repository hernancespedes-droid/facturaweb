<?php
namespace OysAfip;

/**
 * Adaptador para los sistemas de O&S que guardan las ventas en "subdiario" y configuran
 * la factura electrónica en fe_parametros (Reciclados, Prenpol y el resto con el mismo esquema).
 *
 * Replica lo que hace hoy el webservice C# (cAFIP_Metodos.cs, clase Cbte_FE):
 *   1. busca el comprobante en el subdiario sin CAE;
 *   2. arma el pedido con las tablas iva, tributo y equivalencias_afip;
 *   3. controla que el último número de ARCA exista en el subdiario (correlatividad);
 *   4. pide el CAE con el número siguiente;
 *   5. graba CAE, vencimiento y número en el subdiario y renumera las tablas de fe_actualizacion.
 *
 * Mejoras sobre el C#:
 *   - Manda la condición de IVA del receptor (RG 5616). Por esto se rechazaban las facturas.
 *   - Guarda el ticket de acceso (no pide uno nuevo cada vez).
 *   - Si se corta la comunicación, consulta a ARCA antes de reintentar (no deja CAE sin grabar).
 *   - Bloqueo por punto de venta y tipo: dos usuarios no toman el mismo número.
 *   - Todo con consultas parametrizadas y grabación en una transacción.
 *   - Actualiza la columna "actualizacion" para que la sincronización con la nube vea el cambio.
 *   - Consumidor final sin documento: DocTipo 99 / DocNro 0 (el C# mandaba DNI 1).
 */
class OysSubdiario
{
    /** @var \PDO */
    private $pdo;
    /** @var Afip */
    private $afip;
    private $empresa;
    private $par = null;

    public $opciones = array(
        // Condición de IVA del receptor: se busca SIEMPRE por subdiario.iva_cliente en equivalencias_afip
        // (tabla = 'Equivaliencia', codigooys = iva_cliente => codigoafip). Si no está, no se envía y se avisa.
        // 'condicion_iva' solo sirve como respaldo donde no exista equivalencias_afip (p. ej. una base sin esa tabla).
        'condicion_iva' => array(),
        'tributo_id' => 2,              // Id ARCA de los tributos de la tabla tributo (2 = provinciales / IIBB)
        'fce_transferencia' => 'SCA',   // opcional 27: SCA (sistema de circulación abierta) o ADC (agente de depósito)
        'fce_dias_vencimiento' => 30,   // FchVtoPago de la FCE = fecha + días
        'fce_anulacion' => 'N',         // opcional 22 en NC/ND FCE
        'reintentar_anulacion' => true, // si ARCA rechaza una NC FCE con 22=N, reintenta con S (como el C#)
        // En producción está apagado (Web.config: CbteCorrelativo = N)
        'controlar_correlatividad' => false,
        // ARCA acepta fechas de hasta 5 días atrás (productos). true = si es más vieja, se factura con la fecha de hoy.
        'fecha_hoy_si_vencida' => false,
        'campo_actualizacion' => 'actualizacion', // '' para no tocarla
        'registro' => '',               // archivo .log (JSON por línea) con cada autorización; recomendado
    );

    public function __construct(\PDO $pdo, Afip $afip, $empresa, array $opciones = array())
    {
        $this->pdo = $pdo;
        $this->afip = $afip;
        $this->empresa = (int) $empresa;
        foreach ($opciones as $k => $v) {
            $this->opciones[$k] = $v;
        }
        $this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
    }

    // ------------------------------------------------------------------ parámetros

    /** Lee fe_parametros de la empresa: [codigo => valor] */
    public function parametros()
    {
        if ($this->par === null) {
            $this->par = array();
            $st = $this->pdo->prepare('SELECT par_cCodigo, par_cCampo1 FROM fe_parametros WHERE par_iEmpresa = ?');
            $st->execute(array($this->empresa));
            foreach ($st->fetchAll(\PDO::FETCH_NUM) as $f) {
                $this->par[strtoupper(trim($f[0]))] = trim((string) $f[1]);
            }
        }
        return $this->par;
    }

    public function parametro($codigo, $defecto = '')
    {
        $p = $this->parametros();
        $k = strtoupper(trim($codigo));
        return isset($p[$k]) && $p[$k] !== '' ? $p[$k] : $defecto;
    }

    /** Nombre de campo o tabla tomado de fe_parametros, validado para usarlo en SQL */
    private function id($codigo, $defecto)
    {
        $v = strtolower($this->parametro($codigo, $defecto));
        if (!preg_match('/^[a-z_][a-z0-9_]*$/', $v)) {
            throw new AfipException('Nombre de campo inválido en fe_parametros (' . $codigo . '): ' . $v);
        }
        return $v;
    }

    /** Nombres de tablas y campos (los mismos títulos que usa el C#) */
    public function campos()
    {
        $s = 'SUBDIARIO: ';
        return array(
            'subdiario' => $this->id($s . 'NOMBRE DE LA TABLA DEL SISTEMA DE VENTAS', 'subdiario'),
            'iva_tabla' => $this->id('TABLA CON EL CONTENIDO DE IVA', 'iva'),
            'trib_tabla' => $this->id('TABLA CON EL CONTENIDO DE TRIBUTOS', 'tributo'),
            'equiv_tabla' => $this->id('TABLA CON EQUIVALENCIAS AFIP OYS', 'equivalencias_afip'),
            'numero' => $this->id($s . 'CAMPO NUMERO DE LA TABLA SUBDIARIO DEL SISTEMA DE VENTAS', 'comprobante'),
            'tipo' => $this->id($s . 'CAMPO TIPO DE COMPROBANTE EN SUBDIARIO DE VENTAS', 'tipo'),
            'letra' => $this->id($s . 'CAMPO LETRA DE LA TABLA SUBDIARIO DEL SISTEMA DE VENTAS', 'letra'),
            'punto' => $this->id($s . 'CAMPO PTO DE VENTA DE LA TABLA SUBDIARIO DEL SISTEMA DE VENTAS', 'prefijo'),
            'sucursal' => $this->id($s . 'CAMPO NUMERO DE LA SUCURSAL DEL SISTEMA DE VENTAS', 'sucursal'),
            'cliente' => $this->id($s . 'CAMPO CODIGO DE CLIENTE DE LA TABLA SUBDIARIO DEL SISTEMA DE VENTAS', 'cliente'),
            'fecha' => $this->id($s . 'CAMPO FECHA DE LA TABLA SUBDIARIO DEL SISTEMA DE VENTAS', 'fecha'),
            'cuit' => $this->id($s . 'CAMPO CUIT DE CLIENTE EN SUBDIARIO DE VENTAS', 'cuit_cliente'),
            'neto' => $this->id($s . 'CAMPO NETO EN SUBDIARIO DE VENTAS', 'neto'),
            'gravado' => $this->id($s . 'CAMPO NETO GRAVADO EN SUBDIARIO DE VENTAS', 'neto_grabado'),
            'exento' => $this->id($s . 'CAMPO NETO EXENTO EN SUBDIARIO DE VENTAS', 'neto_exento'),
            'iva1' => $this->id($s . 'CAMPO IVA1 EN SUBDIARIO DE VENTAS', 'iva'),
            'iva2' => $this->id($s . 'CAMPO IVA2 EN SUBDIARIO DE VENTAS', 'iva_2'),
            'tipoafip_oys' => $this->id($s . 'CAMPO TIPO DE COMPROBANTE AFIP EN SUBDIARIO DE VENTAS', 'tipoafip_oys'),
            'moneda' => $this->id($s . 'CAMPO CODIGO DE MONEDA DE LA TABLA SUBDIARIO DEL SISTEMA DE VENTAS', 'moneda'),
            'cotizacion' => $this->id($s . 'CAMPO CODIGO DE COTIZACION DE LA TABLA SUBDIARIO DEL SISTEMA DE VENTAS', 'cotizacion'),
            'cbte_fc' => $this->id($s . 'CAMPO CON EL NUMERO DE LA FC PARA LA NC', 'cbte_fc'),
            'cae' => $this->id($s . 'CAMPO CAE', 'fe_ccae'),
            'nro_afip' => $this->id($s . 'CAMPO NUMERO POR AFIP', 'fe_nrocbte'),
            'tipo_afip' => $this->id($s . 'CAMPO TIPO AFIP', 'fe_ntipoafip'),
            'fecha_afip' => $this->id($s . 'CAMPO FECHA AFIP', 'fe_dfecha'),
            'rechazo' => $this->id($s . 'CAMPO RECHAZO POR AFIP', 'fe_lrechazado'),
            'obs' => $this->id($s . 'CAMPO OBSERVACIONES DE AFIP', 'fe_mobservaciones'),
            'vto_cae' => $this->id($s . 'CAMPO FECHA VENCIMIENTO CAE', 'fe_dvtocae'),
            'trib_alic' => $this->id('TRIBUTO: CAMPO CON ALICUOTA DEL IMPUESTO', 'alicuota'),
            'trib_base' => $this->id('TRIBUTO: CAMPO CON LA BASE IMPONIBLE', 'baseimponible'),
            'trib_desc' => $this->id('TRIBUTO: CAMPO CON LA DESCRIPCION DEL IMPUESTO', 'descripcion'),
            'trib_imp' => $this->id('TRIBUTO: CAMPO CON EL IMPORTE', 'importe'),
        );
    }

    // ------------------------------------------------------------------ consultas

    /** Comprobantes electrónicos sin CAE (los que el sistema intentó y quedaron pendientes) */
    public function pendientes()
    {
        $c = $this->campos();
        $sql = 'SELECT * FROM ' . $c['subdiario'] . ' WHERE electronica = 1 AND ' . $c['sucursal'] . ' = ?'
            . ' AND (' . $c['cae'] . " IS NULL OR " . $c['cae'] . " = '' OR " . $c['cae'] . " = '0')"
            . ' AND ' . $c['letra'] . " <> 'E' ORDER BY " . $c['fecha'];
        $st = $this->pdo->prepare($sql);
        $st->execute(array($this->empresa));
        return $st->fetchAll(\PDO::FETCH_ASSOC);
    }

    /**
     * Busca el comprobante por su clave (la misma que recibe hoy CAprobar_Comprobante_).
     * $k: tipo, letra, prefijo, comprobante, cliente, idx_registro (la sucursal es la empresa).
     */
    public function buscar(array $k)
    {
        $c = $this->campos();
        $sql = 'SELECT * FROM ' . $c['subdiario'] . ' WHERE ' . $c['tipo'] . ' = ? AND ' . $c['letra'] . ' = ? AND '
            . $c['punto'] . ' = ? AND ' . $c['sucursal'] . ' = ? AND ' . $c['numero'] . ' = ? AND ' . $c['cliente'] . ' = ?'
            . ' AND idx_registro = ?';
        $st = $this->pdo->prepare($sql);
        $st->execute(array($k['tipo'], $k['letra'], $k['prefijo'], $this->empresa, $k['comprobante'], $k['cliente'], $k['idx_registro']));
        $filas = $st->fetchAll(\PDO::FETCH_ASSOC);
        if (count($filas) === 0) {
            throw new AfipException('No se encontró el comprobante en el subdiario');
        }
        if (count($filas) > 1) {
            throw new AfipException('Hay más de un registro con la misma clave en el subdiario');
        }
        return array_change_key_case($filas[0], CASE_LOWER);
    }

    private function val(array $fila, $campo, $defecto = null)
    {
        return array_key_exists($campo, $fila) && $fila[$campo] !== null ? $fila[$campo] : $defecto;
    }

    /** Filas de una tabla de detalle (iva, tributo) del comprobante */
    private function detalle($tabla, array $f)
    {
        $c = $this->campos();
        $sql = 'SELECT * FROM ' . $tabla . ' WHERE ' . $c['tipo'] . ' = ? AND ' . $c['letra'] . ' = ? AND ' . $c['punto'] . ' = ? AND '
            . $c['sucursal'] . ' = ? AND ' . $c['numero'] . ' = ? AND ' . $c['cliente'] . ' = ? AND idx_registro = ?';
        $st = $this->pdo->prepare($sql);
        $st->execute(array($f[$c['tipo']], $f[$c['letra']], $f[$c['punto']], $f[$c['sucursal']], $f[$c['numero']],
            $f[$c['cliente']], $f['idx_registro']));
        $out = array();
        foreach ($st->fetchAll(\PDO::FETCH_ASSOC) as $r) {
            $out[] = array_change_key_case($r, CASE_LOWER);
        }
        return $out;
    }

    /** Equivalencia alícuota => Id ARCA desde equivalencias_afip (si la tabla no está, usa la estándar) */
    private function idIva($alicuota)
    {
        $c = $this->campos();
        $texto = rtrim(rtrim(number_format((float) $alicuota, 2, '.', ''), '0'), '.') . '%';
        try {
            $st = $this->pdo->prepare('SELECT codigoafip FROM ' . $c['equiv_tabla'] . " WHERE UPPER(tabla) = 'IVA' AND descripcion = ?");
            $st->execute(array($texto));
            $v = $st->fetchColumn();
            if ($v !== false && $v !== null && trim($v) !== '') {
                return (int) $v;
            }
        } catch (\PDOException $e) {
            // tabla ausente (p. ej. en la nube): se usa la estándar
        }
        $k = rtrim($texto, '%');
        if (isset(Comprobante::$idsIva[$k])) {
            return Comprobante::$idsIva[$k];
        }
        throw new AfipException('Alícuota de IVA sin equivalencia ARCA: ' . $texto);
    }

    /** Condición de IVA ARCA desde equivalencias_afip (tabla 'Equivaliencia'), como el webservice actualizado. 0 = no está. */
    private function condicionIvaEquivalencia($codigoOys)
    {
        $c = $this->campos();
        try {
            $st = $this->pdo->prepare('SELECT codigoafip FROM ' . $c['equiv_tabla']
                . " WHERE UPPER(tabla) = 'EQUIVALIENCIA' AND codigooys = ?");
            $st->execute(array((string) $codigoOys));
            $v = $st->fetchColumn();
            return ($v !== false && $v !== null && trim($v) !== '') ? (int) $v : 0;
        } catch (\PDOException $e) {
            return 0;
        }
    }

    /** Fecha del último comprobante aprobado de la empresa (ARCA no acepta fechas anteriores) */
    private function fechaUltimoAprobado()
    {
        $c = $this->campos();
        $st = $this->pdo->prepare('SELECT MAX(' . $c['fecha_afip'] . ') FROM ' . $c['subdiario'] . ' WHERE ' . $c['nro_afip'] . ' > 0 AND '
            . $c['tipo_afip'] . ' > 0 AND electronica = 1 AND ' . $c['sucursal'] . ' = ?');
        $st->execute(array($this->empresa));
        $v = $st->fetchColumn();
        return $v ? substr($v, 0, 10) : '1901-01-01';
    }

    /** CUIT del emisor (fe_configuracion) */
    public function cuitEmisor()
    {
        $st = $this->pdo->prepare('SELECT con_cCUIT FROM fe_configuracion WHERE con_iEmpresa = ?');
        $st->execute(array($this->empresa));
        return preg_replace('/\D/', '', (string) $st->fetchColumn());
    }

    // ------------------------------------------------------------------ armado

    /**
     * Arma el Comprobante a partir de la fila del subdiario (no asigna número: lo hace autorizar).
     * $detalle (opcional): ['iva' => filas, 'tributo' => filas] con los renglones en memoria, para una factura
     * nueva que todavía no está grabada (facturadores web). Si no se pasa, se leen de las tablas.
     */
    public function armar(array $f, $detalle = null)
    {
        $c = $this->campos();
        $f = array_change_key_case($f, CASE_LOWER);
        $tipoOys = (int) $this->val($f, $c['tipoafip_oys'], 0);
        $tipo = strtoupper(trim($f[$c['tipo']]));
        $letra = strtoupper(trim($f[$c['letra']]));
        $esperado = Comprobante::tipoAfip($tipo, $letra, $tipoOys > 200);
        if (!$esperado || ($tipoOys && $tipoOys != $esperado)) {
            throw new AfipException("Tipo de comprobante no reconocido: $tipo $letra (tipoafip_oys = $tipoOys)");
        }

        $cb = new Comprobante();
        $cb->tipo = $esperado;
        $cb->ptoVta = (int) $f[$c['punto']];
        $cb->concepto = 1;
        $cb->receptor($this->val($f, $c['cuit'], ''));

        // Fecha: la del comprobante, salvo que sea anterior al último aprobado
        $fecha = substr($f[$c['fecha']], 0, 10);
        $ultima = $this->fechaUltimoAprobado();
        $cb->fecha = $fecha >= $ultima ? $fecha : $ultima;
        if ($this->opciones['fecha_hoy_si_vencida'] && $cb->fecha < date('Y-m-d', strtotime('-5 days'))) {
            $cb->fecha = date('Y-m-d');
        }

        // Importes (como el C#: neto = total; gravado; exento; iva + iva2)
        $cb->impTotal = round((float) $f[$c['neto']], 2);
        if (Comprobante::esC($cb->tipo)) {
            $cb->impNeto = $cb->impTotal;
        } else {
            $cb->impNeto = round((float) $f[$c['gravado']], 2);
            $cb->impOpEx = round((float) $f[$c['exento']], 2);
            $cb->impIva = round((float) $this->val($f, $c['iva1'], 0) + (float) $this->val($f, $c['iva2'], 0), 2);
        }
        $moneda = trim((string) $this->val($f, $c['moneda'], ''));
        $cb->moneda = $moneda !== '' ? $moneda : 'PES';
        $cot = (float) $this->val($f, $c['cotizacion'], 1);
        $cb->cotizacion = $cot > 0 ? $cot : 1;

        // Condición de IVA del receptor
        $ivaCli = (int) $this->val($f, 'iva_cliente', 0);
        $equiv = $this->condicionIvaEquivalencia($ivaCli);
        if ($equiv) {
            $cb->condicionIvaReceptor = $equiv;
        } elseif (isset($this->opciones['condicion_iva'][$ivaCli])) {
            $cb->condicionIvaReceptor = (int) $this->opciones['condicion_iva'][$ivaCli];
        } else {
            throw new AfipException("No hay equivalencia ARCA para la condición de IVA del cliente (iva_cliente = $ivaCli). "
                . "Cargarla en equivalencias_afip: tabla = 'Equivaliencia', codigooys = $ivaCli, codigoafip = condición de ARCA.");
        }

        // Alícuotas de IVA
        if ($cb->impNeto > 0 && !Comprobante::esC($cb->tipo)) {
            $filasIva = $detalle !== null ? array_map(function ($x) { return array_change_key_case($x, CASE_LOWER); }, $detalle['iva'])
                : $this->detalle($c['iva_tabla'], $f);
            foreach ($filasIva as $r) {
                if ((float) $r['monto'] == 0 && (float) $r['importe'] == 0) {
                    continue;
                }
                $id = $this->idIva($r['alicuota']);
                $existe = false;
                foreach ($cb->iva as $i => $a) {
                    if ($a['id'] == $id) {
                        $cb->iva[$i]['base'] += (float) $r['monto'];
                        $cb->iva[$i]['importe'] += (float) $r['importe'];
                        $existe = true;
                    }
                }
                if (!$existe) {
                    $cb->iva[] = array('id' => $id, 'base' => (float) $r['monto'], 'importe' => (float) $r['importe']);
                }
            }
            // Renglones al 0 % junto con otros gravados: el sistema no graba la alícuota 0 en la tabla iva,
            // pero ARCA pide que las bases sumen el neto gravado. La diferencia va como IVA 0 % (Id 3).
            $bases = 0.0;
            foreach ($cb->iva as $a) {
                $bases += $a['base'];
            }
            if ($cb->iva && $cb->impNeto - $bases > 0.01) {
                $cb->agregarIva(0, round($cb->impNeto - $bases, 2), 0);
            }
        }

        // Tributos (percepciones IIBB)
        $filasTrib = $detalle !== null ? array_map(function ($x) { return array_change_key_case($x, CASE_LOWER); }, $detalle['tributo'])
            : $this->detalle($c['trib_tabla'], $f);
        foreach ($filasTrib as $r) {
            $cb->agregarTributo($this->opciones['tributo_id'], trim($r[$c['trib_desc']]), $r[$c['trib_base']],
                $r[$c['trib_alic']], round((float) $r[$c['trib_imp']], 2));
        }
        $cb->impTrib = 0.0;
        foreach ($cb->tributos as $t) {
            $cb->impTrib += $t['importe'];
        }
        $cb->impTrib = round($cb->impTrib, 2);

        // Comprobante asociado (NC y ND)
        if (!Comprobante::esFactura($cb->tipo)) {
            $nroFc = (int) $this->val($f, $c['cbte_fc'], 0);
            $tipos = Comprobante::esNotaCredito($cb->tipo) ? array('FC') : array('FC', 'NC');
            $sql = 'SELECT * FROM ' . $c['subdiario'] . ' WHERE ' . $c['tipo'] . ' IN (' . implode(',', array_fill(0, count($tipos), '?')) . ') AND '
                . $c['letra'] . ' = ? AND ' . $c['punto'] . ' = ? AND ' . $c['sucursal'] . ' = ? AND ' . $c['numero'] . ' = ? AND '
                . $c['cliente'] . ' = ?';
            $st = $this->pdo->prepare($sql);
            $st->execute(array_merge($tipos, array($letra, $f[$c['punto']], $f[$c['sucursal']], $nroFc, $f[$c['cliente']])));
            $asoc = $st->fetchAll(\PDO::FETCH_ASSOC);
            if (count($asoc) > 1) {
                // Mismo número en FC común y FCE: quedarse con el de la misma familia que la nota
                $fce = Comprobante::esFce($cb->tipo);
                $campoTipoAfip = $c['tipo_afip'];
                $asoc = array_values(array_filter($asoc, function ($a) use ($fce, $campoTipoAfip) {
                    $a = array_change_key_case($a, CASE_LOWER);
                    return Comprobante::esFce((int) $a[$campoTipoAfip]) === $fce;
                }));
            }
            if (count($asoc) !== 1) {
                throw new AfipException(count($asoc) ? 'Hay más de un comprobante asociado con el número ' . $nroFc
                    : 'No se encontró el comprobante asociado número ' . $nroFc . ' (campo ' . $c['cbte_fc'] . ')');
            }
            $a = array_change_key_case($asoc[0], CASE_LOWER);
            // tipo ARCA del asociado: el que grabó la aprobación; si no está, el del sistema (tipoafip_oys) o por tipo y letra
            $tipoAsoc = (int) $this->val($a, $c['tipo_afip'], 0);
            if (!$tipoAsoc) {
                $tipoAsoc = (int) $this->val($a, $c['tipoafip_oys'], 0);
            }
            if (!$tipoAsoc) {
                $tipoAsoc = (int) Comprobante::tipoAfip(strtoupper(trim($a[$c['tipo']])), strtoupper(trim($a[$c['letra']])), Comprobante::esFce($cb->tipo));
            }
            $cb->agregarAsociado($tipoAsoc, $cb->ptoVta, $nroFc, substr($a[$c['fecha']], 0, 10), $this->afip->cfg['cuit']);
        }

        // FCE MiPyMEs
        if (Comprobante::esFce($cb->tipo)) {
            if (Comprobante::esFactura($cb->tipo)) {
                $cb->vtoPago = date('Y-m-d', strtotime($cb->fecha . ' +' . (int) $this->opciones['fce_dias_vencimiento'] . ' days'));
                $cbu = preg_replace('/\D/', '', $this->parametro('MIPYME : CBU'));
                $alias = $this->parametro('MIPYME : ALIAS');
                if ($cbu !== '') {
                    $cb->agregarOpcional('2101', $cbu);
                } elseif ($alias !== '') {
                    $cb->agregarOpcional('2102', $alias);
                } else {
                    throw new AfipException('Falta el CBU (MIPYME : CBU en fe_parametros) para la factura de crédito MiPyME');
                }
                $cb->agregarOpcional('27', $this->opciones['fce_transferencia']);
            } else {
                $cb->agregarOpcional('22', $this->opciones['fce_anulacion']);
            }
        }
        return $cb;
    }

    // ------------------------------------------------------------------ autorización

    /** Control de correlatividad del C#: el último número de ARCA tiene que estar en el subdiario */
    private function controlarCorrelatividad(Comprobante $cb, array $f)
    {
        $ultimo = $this->afip->wsfe->ultimoAutorizado($cb->ptoVta, $cb->tipo);
        if ($ultimo === 0) {
            return;
        }
        $c = $this->campos();
        $st = $this->pdo->prepare('SELECT COUNT(*) FROM ' . $c['subdiario'] . ' WHERE ' . $c['tipo'] . ' = ? AND ' . $c['letra'] . ' = ? AND '
            . $c['punto'] . ' = ? AND ' . $c['sucursal'] . ' = ? AND ' . $c['numero'] . ' = ? AND ' . $c['cae'] . " <> ''");
        $st->execute(array($f[$c['tipo']], $f[$c['letra']], $cb->ptoVta, $this->empresa, $ultimo));
        if ((int) $st->fetchColumn() === 0) {
            throw new AfipException(sprintf('El sistema no está sincronizado con ARCA: el último %s %s %04d-%08d autorizado no está '
                . 'en el subdiario. Hay que cargarlo a mano con el CAE y el vencimiento que informa ARCA.',
                $f[$c['tipo']], $f[$c['letra']], $cb->ptoVta, $ultimo));
        }
    }

    /**
     * Pide el CAE del comprobante y graba el resultado. Devuelve el resultado de Wsfe::autorizar.
     * $k: tipo, letra, prefijo, comprobante, cliente, idx_registro
     */
    public function aprobar(array $k)
    {
        $f = $this->buscar($k);
        $c = $this->campos();
        if (trim((string) $f[$c['cae']]) !== '' && trim((string) $f[$c['cae']]) !== '0') {
            throw new AfipException('El comprobante ya tiene CAE: ' . $f[$c['cae']]);
        }
        if ((float) $f[$c['neto']] <= 0) {
            throw new AfipException('El comprobante no tiene importe');
        }
        try {
            $cb = $this->armar($f);
            $problemas = $cb->validar();
            if ($problemas) {
                throw new AfipException('El comprobante no pasa los controles: ' . implode('; ', $problemas));
            }
        } catch (AfipException $e) {
            $this->grabarRechazo($f, 'No se envió a ARCA: ' . $e->getMessage());
            throw $e;
        }

        $b = new Bloqueo($this->afip->cfg['cuit'] . '_' . $cb->ptoVta . '_' . $cb->tipo, $this->pdo,
            isset($this->afip->cfg['carpeta_temp']) ? $this->afip->cfg['carpeta_temp'] : '');
        $b->tomar();
        try {
            if ($this->opciones['controlar_correlatividad']) {
                $this->controlarCorrelatividad($cb, $f);
            }
            $r = $this->afip->wsfe->autorizar($cb);
            if (!$r['aprobado'] && $this->opciones['reintentar_anulacion'] && Comprobante::esFce($cb->tipo)
                && Comprobante::esNotaCredito($cb->tipo) && $cb->opcionales && $cb->opcionales[0]['valor'] === 'N') {
                $cb->opcionales[0]['valor'] = 'S';
                $r = $this->afip->wsfe->autorizar($cb);
            }
            $this->registrar($k, $cb, $r);
            if ($r['aprobado']) {
                $this->grabarAprobado($f, $cb, $r);
            } else {
                $this->grabarRechazo($f, $this->textoResultado($r));
            }
        } catch (\Exception $e) {
            $b->soltar();
            if (!($e instanceof AfipConexionException)) {
                try {
                    $this->grabarRechazo($f, $e->getMessage());
                } catch (\Exception $e2) {
                }
            }
            throw $e;
        }
        $b->soltar();
        $r['qr'] = $r['aprobado'] ? $this->afip->urlQr($cb, $r['cae']) : '';
        return $r;
    }

    private function textoResultado(array $r)
    {
        $t = 'Procesado: ' . date('YmdHis');
        foreach ($r['errores'] as $e) {
            $t .= ' - Error (' . $e['codigo'] . ') ' . $e['mensaje'];
        }
        foreach ($r['observaciones'] as $o) {
            $t .= ' - Obs: ' . $o;
        }
        return $t;
    }

    private static function fechaIso($yyyymmdd)
    {
        $s = preg_replace('/\D/', '', (string) $yyyymmdd);
        return strlen($s) === 8 ? substr($s, 0, 4) . '-' . substr($s, 4, 2) . '-' . substr($s, 6, 2) : null;
    }

    private function setActualizacion()
    {
        $a = $this->opciones['campo_actualizacion'];
        return $a !== '' && preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $a) ? ', ' . $a . ' = CURRENT_TIMESTAMP' : '';
    }

    private function grabarRechazo(array $f, $texto)
    {
        $c = $this->campos();
        $st = $this->pdo->prepare('UPDATE ' . $c['subdiario'] . ' SET ' . $c['obs'] . ' = ?' . $this->setActualizacion()
            . ' WHERE ' . $c['tipo'] . ' = ? AND ' . $c['letra'] . ' = ? AND ' . $c['punto'] . ' = ? AND ' . $c['sucursal'] . ' = ? AND '
            . $c['numero'] . ' = ? AND ' . $c['cliente'] . ' = ? AND idx_registro = ?');
        $st->execute(array(substr($texto, 0, 2000), $f[$c['tipo']], $f[$c['letra']], $f[$c['punto']], $f[$c['sucursal']],
            $f[$c['numero']], $f[$c['cliente']], $f['idx_registro']));
    }

    /** Graba CAE, número y fecha en el subdiario y renumera las tablas de fe_actualizacion (en una transacción) */
    private function grabarAprobado(array $f, Comprobante $cb, array $r)
    {
        $c = $this->campos();
        $fechaAfip = self::fechaIso($r['fecha']);
        $vence = self::fechaIso($r['vence']);
        $nro = (int) $r['numero'];
        $viejo = $f[$c['numero']];
        $act = $this->setActualizacion();
        $where = ' WHERE ' . $c['tipo'] . ' = ? AND ' . $c['letra'] . ' = ? AND ' . $c['punto'] . ' = ? AND ' . $c['sucursal'] . ' = ? AND '
            . $c['cliente'] . ' = ? AND idx_registro = ?';
        $clave = array($f[$c['tipo']], $f[$c['letra']], $f[$c['punto']], $f[$c['sucursal']], $f[$c['cliente']], $f['idx_registro']);

        $this->pdo->beginTransaction();
        try {
            $st = $this->pdo->prepare('UPDATE ' . $c['subdiario'] . ' SET ' . $c['tipo_afip'] . ' = ?, ' . $c['cae'] . ' = ?, '
                . $c['fecha_afip'] . ' = ?, fe_iid = ?, ' . $c['nro_afip'] . ' = ?, ' . $c['rechazo'] . ' = 0, ' . $c['vto_cae'] . ' = ?, '
                . $c['obs'] . ' = ?, ' . $c['numero'] . ' = ?, ' . $c['fecha'] . ' = ?' . $act . $where . ' AND ' . $c['numero'] . ' = ?');
            $st->execute(array_merge(array($cb->tipo, $r['cae'], $fechaAfip, $viejo, $nro, $vence, $this->textoResultado($r), $nro, $fechaAfip),
                $clave, array($viejo)));
            if ($st->rowCount() !== 1) {
                throw new AfipException('No se pudo grabar el CAE en el subdiario (filas afectadas: ' . $st->rowCount() . ')');
            }

            $st = $this->pdo->prepare('SELECT tabla, camponumero, campofecha FROM fe_actualizacion WHERE empresa = ?');
            $st->execute(array($this->empresa));
            foreach ($st->fetchAll(\PDO::FETCH_NUM) as $t) {
                $tabla = strtolower(trim($t[0]));
                $cn = strtolower(trim($t[1]));
                $cf = strtolower(trim($t[2]));
                if ($tabla === $c['subdiario']) {
                    continue; // ya renumerado arriba
                }
                foreach (array($tabla, $cn, $cf) as $n) {
                    if (!preg_match('/^[a-z_][a-z0-9_]*$/', $n)) {
                        throw new AfipException('Nombre inválido en fe_actualizacion: ' . $n);
                    }
                }
                $u = $this->pdo->prepare('UPDATE ' . $tabla . ' SET ' . $cn . ' = ?, ' . $cf . ' = ?' . $act . $where . ' AND ' . $cn . ' = ?');
                $u->execute(array_merge(array($nro, $fechaAfip), $clave, array($viejo)));
            }
            $this->pdo->commit();
        } catch (\Exception $e) {
            $this->pdo->rollBack();
            throw new AfipException('ARCA aprobó el comprobante (CAE ' . $r['cae'] . ', número ' . $nro . ') pero no se pudo grabar: '
                . $e->getMessage() . '. Quedó en el registro; se puede volver a grabar con regrabar().');
        }
    }

    /** Vuelve a grabar un resultado aprobado que no se pudo guardar (lo toma del registro o de ARCA) */
    public function regrabar(array $k, $numeroAfip)
    {
        $f = $this->buscar($k);
        $cb = $this->armar($f);
        $ya = $this->afip->wsfe->consultar($cb->ptoVta, $cb->tipo, $numeroAfip);
        if (!$ya || empty($ya['CodAutorizacion'])) {
            throw new AfipException('ARCA no tiene ese comprobante autorizado');
        }
        if (abs((float) $ya['ImpTotal'] - $cb->impTotal) > 0.01) {
            throw new AfipException('El importe en ARCA (' . $ya['ImpTotal'] . ') no coincide con el subdiario (' . $cb->impTotal . ')');
        }
        $r = array('aprobado' => true, 'numero' => (int) $numeroAfip, 'cae' => $ya['CodAutorizacion'], 'vence' => $ya['FchVto'],
            'fecha' => $ya['CbteFch'], 'observaciones' => array('Regrabado desde FECompConsultar'), 'errores' => array());
        $this->grabarAprobado($f, $cb, $r);
        return $r;
    }

    private function registrar(array $k, Comprobante $cb, array $r)
    {
        $archivo = $this->opciones['registro'];
        if ($archivo === '') {
            return;
        }
        $linea = json_encode(array('fecha' => date('c'), 'empresa' => $this->empresa, 'clave' => $k, 'tipo' => $cb->tipo,
            'ptoVta' => $cb->ptoVta, 'resultado' => $r)) . "\n";
        @file_put_contents($archivo, $linea, FILE_APPEND | LOCK_EX);
    }
}
