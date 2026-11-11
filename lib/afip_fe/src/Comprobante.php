<?php
namespace OysAfip;

/**
 * Un comprobante para WSFEv1. No sabe nada de las tablas de ningún sistema:
 * el sistema lo llena (directo o con un adaptador) y Wsfe lo manda.
 *
 * Importes en pesos con 2 decimales. Fechas como 'Y-m-d' o 'Ymd'.
 */
class Comprobante
{
    // ---- Tipos de comprobante (CbteTipo) ----
    const FC_A = 1;  const ND_A = 2;  const NC_A = 3;
    const FC_B = 6;  const ND_B = 7;  const NC_B = 8;
    const FC_C = 11; const ND_C = 12; const NC_C = 13;
    const FC_M = 51; const ND_M = 52; const NC_M = 53;
    const FCE_A = 201; const NDE_A = 202; const NCE_A = 203;
    const FCE_B = 206; const NDE_B = 207; const NCE_B = 208;
    const FCE_C = 211; const NDE_C = 212; const NCE_C = 213;

    // ---- Tipos de documento ----
    const DOC_CUIT = 80;
    const DOC_CUIL = 86;
    const DOC_DNI = 96;
    const DOC_SIN_IDENTIFICAR = 99;

    // ---- Condición de IVA del receptor (RG 5616; confirmar con FEParamGetCondicionIvaReceptor) ----
    const IVA_RESPONSABLE_INSCRIPTO = 1;
    const IVA_EXENTO = 4;
    const IVA_CONSUMIDOR_FINAL = 5;
    const IVA_MONOTRIBUTO = 6;
    const IVA_NO_CATEGORIZADO = 7;
    const IVA_PROVEEDOR_EXTERIOR = 8;
    const IVA_CLIENTE_EXTERIOR = 9;
    const IVA_LIBERADO = 10;
    const IVA_MONOTRIBUTO_SOCIAL = 13;
    const IVA_NO_ALCANZADO = 15;
    const IVA_MONOTRIBUTO_TRAB_INDEP = 16;

    /** Alícuota en % => Id de AFIP */
    public static $idsIva = array('0' => 3, '2.5' => 9, '5' => 8, '10.5' => 4, '21' => 5, '27' => 6);

    public $tipo = 0;
    public $ptoVta = 0;
    public $numero = 0;
    public $concepto = 1;           // 1 productos, 2 servicios, 3 ambos
    public $docTipo = self::DOC_CUIT;
    public $docNro = 0;
    public $fecha = '';             // Y-m-d
    public $impTotal = 0.0;
    public $impTotConc = 0.0;       // no gravado
    public $impNeto = 0.0;          // neto gravado (en C: el total)
    public $impOpEx = 0.0;          // exento
    public $impIva = 0.0;
    public $impTrib = 0.0;
    public $servDesde = '';         // concepto 2 y 3
    public $servHasta = '';
    public $vtoPago = '';           // servicios y FCE
    public $moneda = 'PES';
    public $cotizacion = 1.0;
    public $condicionIvaReceptor = 0;
    /** [['id'=>5,'base'=>100,'importe'=>21], ...]  (usar agregarIva) */
    public $iva = array();
    /** [['id'=>2,'desc'=>'IIBB','base'=>..,'alic'=>..,'importe'=>..], ...] */
    public $tributos = array();
    /** [['tipo'=>1,'ptoVta'=>3,'nro'=>10,'cuit'=>'30...','fecha'=>'Y-m-d'], ...] */
    public $asociados = array();
    /** [['id'=>'2101','valor'=>'CBU...'], ...] */
    public $opcionales = array();

    /**
     * Tipo AFIP a partir de la forma en que lo guardan los sistemas de O&S: FC/ND/NC + letra (+ MiPyME).
     * Devuelve 0 si la combinación no existe.
     */
    public static function tipoAfip($tipo, $letra, $mipyme = false)
    {
        $tipo = strtoupper(trim($tipo));
        $letra = strtoupper(trim($letra));
        $base = array('A' => 1, 'B' => 6, 'C' => 11, 'M' => 51);
        $desp = array('FC' => 0, 'ND' => 1, 'NC' => 2);
        if (!isset($base[$letra]) || !isset($desp[$tipo])) {
            return 0;
        }
        if ($mipyme) {
            $fce = array('A' => 201, 'B' => 206, 'C' => 211);
            return isset($fce[$letra]) ? $fce[$letra] + $desp[$tipo] : 0;
        }
        return $base[$letra] + $desp[$tipo];
    }

    public static function esC($tipo)
    {
        return in_array((int) $tipo, array(11, 12, 13, 211, 212, 213), true);
    }

    public static function esFce($tipo)
    {
        return (int) $tipo >= 201 && (int) $tipo <= 213;
    }

    public static function esFactura($tipo)
    {
        return in_array((int) $tipo, array(1, 6, 11, 51, 201, 206, 211), true);
    }

    public static function esNotaCredito($tipo)
    {
        return in_array((int) $tipo, array(3, 8, 13, 53, 203, 208, 213), true);
    }

    /** Documento del receptor: CUIT (11 dígitos), DNI, o consumidor final sin identificar */
    public function receptor($documento)
    {
        $n = preg_replace('/\D/', '', (string) $documento);
        if ($n === '' || (int) $n === 0) {
            $this->docTipo = self::DOC_SIN_IDENTIFICAR;
            $this->docNro = 0;
        } elseif (strlen($n) == 11) {
            $this->docTipo = self::DOC_CUIT;
            $this->docNro = $n;
        } else {
            $this->docTipo = self::DOC_DNI;
            $this->docNro = $n;
        }
        return $this;
    }

    /** Agrega una alícuota. $alicuota en % (21, 10.5...) */
    public function agregarIva($alicuota, $base, $importe)
    {
        $k = rtrim(rtrim(number_format((float) $alicuota, 2, '.', ''), '0'), '.');
        if (!isset(self::$idsIva[$k])) {
            throw new AfipException('Alícuota de IVA desconocida: ' . $alicuota);
        }
        $id = self::$idsIva[$k];
        // Si la alícuota se repite, se suma (ARCA no acepta dos renglones con el mismo Id)
        foreach ($this->iva as $i => $a) {
            if ($a['id'] == $id) {
                $this->iva[$i]['base'] += (float) $base;
                $this->iva[$i]['importe'] += (float) $importe;
                return $this;
            }
        }
        $this->iva[] = array('id' => $id, 'base' => (float) $base, 'importe' => (float) $importe);
        return $this;
    }

    /** Agrega un tributo. Ids habituales: 1 nacionales, 2 provinciales (IIBB), 3 municipales, 4 internos, 99 otros */
    public function agregarTributo($id, $descripcion, $base, $alicuota, $importe)
    {
        $this->tributos[] = array('id' => (int) $id, 'desc' => $descripcion, 'base' => (float) $base,
            'alic' => (float) $alicuota, 'importe' => (float) $importe);
        return $this;
    }

    public function agregarAsociado($tipo, $ptoVta, $nro, $fecha = '', $cuit = '')
    {
        $this->asociados[] = array('tipo' => (int) $tipo, 'ptoVta' => (int) $ptoVta, 'nro' => (int) $nro,
            'fecha' => $fecha, 'cuit' => $cuit);
        return $this;
    }

    public function agregarOpcional($id, $valor)
    {
        $this->opcionales[] = array('id' => (string) $id, 'valor' => (string) $valor);
        return $this;
    }

    /** Recalcula impIva e impTrib a partir del detalle (opcional). */
    public function totalizar()
    {
        $this->impIva = 0.0;
        foreach ($this->iva as $a) {
            $this->impIva += $a['importe'];
        }
        $this->impTrib = 0.0;
        foreach ($this->tributos as $t) {
            $this->impTrib += $t['importe'];
        }
        $this->impIva = round($this->impIva, 2);
        $this->impTrib = round($this->impTrib, 2);
        return $this;
    }

    /** Controles que hace ARCA, para avisar antes de mandar. Devuelve lista de problemas (vacía = OK). */
    public function validar()
    {
        $p = array();
        if (!$this->tipo) {
            $p[] = 'Falta el tipo de comprobante';
        }
        if ($this->ptoVta < 1) {
            $p[] = 'Falta el punto de venta';
        }
        if (!$this->condicionIvaReceptor) {
            $p[] = 'Falta la condición de IVA del receptor (obligatoria, RG 5616)';
        }
        if ($this->fecha === '') {
            $p[] = 'Falta la fecha';
        }
        $c = self::esC($this->tipo);
        $sumIva = 0.0;
        foreach ($this->iva as $a) {
            $sumIva += $a['importe'];
        }
        $sumTrib = 0.0;
        foreach ($this->tributos as $t) {
            $sumTrib += $t['importe'];
        }
        if (!$c) {
            if (abs($sumIva - $this->impIva) > 0.01) {
                $p[] = sprintf('El IVA total (%.2f) no coincide con la suma de alícuotas (%.2f)', $this->impIva, $sumIva);
            }
            $sumBase = 0.0;
            foreach ($this->iva as $a) {
                $sumBase += $a['base'];
            }
            if ($this->impNeto > 0 && abs($sumBase - $this->impNeto) > 0.01) {
                $p[] = sprintf('El neto gravado (%.2f) no coincide con la suma de bases de IVA (%.2f)', $this->impNeto, $sumBase);
            }
            if ($this->impNeto > 0 && !$this->iva) {
                $p[] = 'Hay neto gravado sin alícuotas de IVA';
            }
        }
        if (abs($sumTrib - $this->impTrib) > 0.01) {
            $p[] = sprintf('Los tributos (%.2f) no coinciden con su detalle (%.2f)', $this->impTrib, $sumTrib);
        }
        $total = $this->impTotConc + $this->impNeto + $this->impOpEx + $this->impTrib + ($c ? 0 : $this->impIva);
        if (abs($total - $this->impTotal) > 0.01) {
            $p[] = sprintf('El total (%.2f) no es la suma de neto + exento + no gravado + IVA + tributos (%.2f)', $this->impTotal, $total);
        }
        if (self::esFce($this->tipo) && self::esFactura($this->tipo) && $this->vtoPago === '') {
            $p[] = 'La factura de crédito MiPyME necesita fecha de vencimiento del pago';
        }
        if ((self::esNotaCredito($this->tipo) || in_array((int) $this->tipo, array(2, 7, 12, 52, 202, 207, 212), true)) && !$this->asociados) {
            $p[] = 'La nota de crédito o débito necesita el comprobante asociado';
        }
        foreach ($this->asociados as $a) {
            if ($a['tipo'] <= 0 || $a['nro'] <= 0) {
                $p[] = 'El comprobante asociado no tiene tipo o número de ARCA';
            }
        }
        if ($this->concepto > 1 && ($this->servDesde === '' || $this->servHasta === '' || $this->vtoPago === '')) {
            $p[] = 'Para servicios hay que informar período (desde/hasta) y vencimiento de pago';
        }
        return $p;
    }

    private static function f($fecha)
    {
        if ($fecha === '' || $fecha === null) {
            return '';
        }
        $t = preg_match('/^\d{8}$/', $fecha) ? $fecha : date('Ymd', strtotime($fecha));
        return $t;
    }

    private static function n($v)
    {
        return number_format(round((float) $v, 2), 2, '.', '');
    }

    private static function e($nombre, $valor)
    {
        return '<ar:' . $nombre . '>' . Soap::x($valor) . '</ar:' . $nombre . '>';
    }

    public function xmlCabecera()
    {
        return '<ar:FeCabReq>' . self::e('CantReg', 1) . self::e('PtoVta', (int) $this->ptoVta)
            . self::e('CbteTipo', (int) $this->tipo) . '</ar:FeCabReq>';
    }

    /** XML de FECAEDetRequest, en el orden del WSDL. */
    public function xmlDetalle()
    {
        $c = self::esC($this->tipo);
        $x = '<ar:FECAEDetRequest>'
            . self::e('Concepto', (int) $this->concepto)
            . self::e('DocTipo', (int) $this->docTipo)
            . self::e('DocNro', $this->docNro ? $this->docNro : 0)
            . self::e('CbteDesde', (int) $this->numero)
            . self::e('CbteHasta', (int) $this->numero)
            . self::e('CbteFch', self::f($this->fecha))
            . self::e('ImpTotal', self::n($this->impTotal))
            . self::e('ImpTotConc', self::n($c ? 0 : $this->impTotConc))
            . self::e('ImpNeto', self::n($this->impNeto))
            . self::e('ImpOpEx', self::n($c ? 0 : $this->impOpEx))
            . self::e('ImpTrib', self::n($this->impTrib))
            . self::e('ImpIVA', self::n($c ? 0 : $this->impIva));
        if ($this->concepto > 1) {
            $x .= self::e('FchServDesde', self::f($this->servDesde)) . self::e('FchServHasta', self::f($this->servHasta));
        }
        if ($this->vtoPago !== '') {
            $x .= self::e('FchVtoPago', self::f($this->vtoPago));
        }
        $x .= self::e('MonId', $this->moneda) . self::e('MonCotiz', $this->moneda === 'PES' ? '1' : $this->cotizacion);
        if ($this->condicionIvaReceptor) {
            $x .= self::e('CondicionIVAReceptorId', (int) $this->condicionIvaReceptor);
        }
        if ($this->asociados) {
            $x .= '<ar:CbtesAsoc>';
            foreach ($this->asociados as $a) {
                $x .= '<ar:CbteAsoc>' . self::e('Tipo', $a['tipo']) . self::e('PtoVta', $a['ptoVta']) . self::e('Nro', $a['nro']);
                if ($a['cuit'] !== '') {
                    $x .= self::e('Cuit', preg_replace('/\D/', '', $a['cuit']));
                }
                if ($a['fecha'] !== '') {
                    $x .= self::e('CbteFch', self::f($a['fecha']));
                }
                $x .= '</ar:CbteAsoc>';
            }
            $x .= '</ar:CbtesAsoc>';
        }
        if ($this->tributos) {
            $x .= '<ar:Tributos>';
            foreach ($this->tributos as $t) {
                $x .= '<ar:Tributo>' . self::e('Id', $t['id']) . self::e('Desc', $t['desc']) . self::e('BaseImp', self::n($t['base']))
                    . self::e('Alic', self::n($t['alic'])) . self::e('Importe', self::n($t['importe'])) . '</ar:Tributo>';
            }
            $x .= '</ar:Tributos>';
        }
        if ($this->iva && !$c) {
            $x .= '<ar:Iva>';
            foreach ($this->iva as $a) {
                $x .= '<ar:AlicIva>' . self::e('Id', $a['id']) . self::e('BaseImp', self::n($a['base']))
                    . self::e('Importe', self::n($a['importe'])) . '</ar:AlicIva>';
            }
            $x .= '</ar:Iva>';
        }
        if ($this->opcionales) {
            $x .= '<ar:Opcionales>';
            foreach ($this->opcionales as $o) {
                $x .= '<ar:Opcional>' . self::e('Id', $o['id']) . self::e('Valor', $o['valor']) . '</ar:Opcional>';
            }
            $x .= '</ar:Opcionales>';
        }
        return $x . '</ar:FECAEDetRequest>';
    }

    /** Interpreta el resultado de FECAESolicitar */
    public function leerRespuesta(array $r)
    {
        $out = array('aprobado' => false, 'resultado' => '', 'numero' => $this->numero, 'cae' => '', 'vence' => '',
            'fecha' => '', 'observaciones' => array(), 'errores' => Wsfe::errores($r));
        if (isset($r['FeDetResp'])) {
            $det = Soap::lista($r['FeDetResp']['FECAEDetResponse']);
            $d = $det[0];
            $out['resultado'] = $d['Resultado'];
            $out['cae'] = is_string($d['CAE']) ? $d['CAE'] : '';
            $out['vence'] = is_string($d['CAEFchVto']) ? $d['CAEFchVto'] : '';
            $out['fecha'] = $d['CbteFch'];
            $out['numero'] = (int) $d['CbteDesde'];
            if (isset($d['Observaciones'])) {
                foreach (Soap::lista($d['Observaciones']['Obs']) as $o) {
                    $out['observaciones'][] = '(' . $o['Code'] . ') ' . $o['Msg'];
                }
            }
            $out['aprobado'] = ($d['Resultado'] === 'A' && $out['cae'] !== '');
        }
        return $out;
    }
}
