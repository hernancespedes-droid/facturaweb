<?php
namespace OysAfip;

/**
 * Emisión completa para cualquier sistema PHP (sin el esquema O&S): de los renglones a la factura aprobada.
 *
 *   $f = new OysAfip\Facturador($afip, $pdo);            // $pdo opcional: bloqueo de números con GET_LOCK
 *   $r = $f->emitir(array(
 *       'tipo' => 'FC', 'letra' => '', 'mipyme' => false,  // letra vacía = se calcula (emisor RI: A o B; monotributo/exento: C)
 *       'pto_vta' => 5, 'fecha' => date('Y-m-d'), 'concepto' => 1,
 *       'receptor' => array('documento' => '30-71000000-1', 'condicion_iva' => 1),   // condición = Id de ARCA
 *       'items' => array(array('descripcion' => 'Cartón', 'cantidad' => 2.5, 'precio' => 1000, 'alicuota' => 21)),
 *       'percepciones' => array(array('descripcion' => 'Percepción IIBB Bs. As.', 'alicuota' => 1.5)),
 *       'asociado' => array('tipo_afip' => 1, 'pto_vta' => 5, 'numero' => 12, 'fecha' => '2026-11-01'),   // NC / ND
 *   ));
 *   if ($r['aprobado']) { grabar $r['numero'], $r['cae'], $r['vence'] ... ; imprimir con FacturaHtml }
 *
 * Reglas (las mismas que usamos en Reciclados y que controla ARCA):
 *  - cálculo por renglón redondeado a 2 decimales (CalculoComprobante); IVA agrupado por alícuota;
 *  - renglón con 'exento' => true va a ImpOpEx; alícuota 0 % va como IVA Id 3 (gravado al 0 %);
 *  - letra C: sin IVA discriminado (ImpNeto = subtotal);
 *  - FCE MiPyMEs (201/206/211): vencimiento de pago (30 días por defecto), opcionales 2101 (CBU) y 27 (SCA/ADC);
 *    NC/ND FCE: opcional 22 (anulación N/S);
 *  - NC/ND: el comprobante asociado es obligatorio;
 *  - condición de IVA del receptor (RG 5616) obligatoria: la pasa el sistema ya traducida al Id de ARCA
 *    (en los sistemas O&S, con la tabla equivalencias_afip; ver EquivalenciasIva).
 */
class Facturador
{
    /** @var Afip */
    public $afip;
    public $pdo;
    public $opciones = array(
        'condicion_emisor' => 'RI',     // 'RI', 'MT' (monotributo) o 'EX' (exento)
        'cbu' => '',                    // FCE: CBU del emisor (opcional 2101)
        'fce_transferencia' => 'SCA',   // FCE: SCA (sistema de circulación abierta) o ADC (agente de depósito)
        'fce_dias_vencimiento' => 30,
        'fce_anulacion' => 'N',
        'tributo_id' => 2,              // percepciones de IIBB = tributos provinciales
    );

    public function __construct(Afip $afip, \PDO $pdo = null, array $opciones = array())
    {
        $this->afip = $afip;
        $this->pdo = $pdo;
        $this->opciones = $opciones + $this->opciones;
    }

    /** Letra según la condición del emisor y la del receptor (Id de ARCA). */
    public static function letra($condicionEmisor, $condicionReceptor)
    {
        if ($condicionEmisor !== 'RI') {
            return 'C';
        }
        // RI: factura A a responsables inscriptos y monotributistas (Ley 27.618); B al resto
        return in_array((int) $condicionReceptor, array(1, 6, 13, 16), true) ? 'A' : 'B';
    }

    /** Arma el comprobante sin mandarlo. Devuelve array(Comprobante, cálculo). */
    public function armar(array $d)
    {
        $o = $this->opciones;
        $tipo = strtoupper(isset($d['tipo']) ? $d['tipo'] : 'FC');
        $rec = isset($d['receptor']) ? $d['receptor'] : array();
        $cond = (int) (isset($rec['condicion_iva']) ? $rec['condicion_iva'] : 0);
        $letra = strtoupper(isset($d['letra']) ? (string) $d['letra'] : '');
        if ($letra === '') {
            $letra = self::letra($o['condicion_emisor'], $cond);
        }
        $mipyme = !empty($d['mipyme']);
        $c = new Comprobante();
        $c->tipo = Comprobante::tipoAfip($tipo, $letra, $mipyme);
        if (!$c->tipo) {
            throw new AfipException("No existe el comprobante $tipo $letra" . ($mipyme ? ' MiPyMEs' : ''));
        }
        $c->ptoVta = (int) $d['pto_vta'];
        $c->fecha = isset($d['fecha']) && $d['fecha'] !== '' ? $d['fecha'] : date('Y-m-d');
        $c->concepto = isset($d['concepto']) ? (int) $d['concepto'] : 1;
        if ($c->concepto > 1) {
            $c->servDesde = isset($d['serv_desde']) ? $d['serv_desde'] : $c->fecha;
            $c->servHasta = isset($d['serv_hasta']) ? $d['serv_hasta'] : $c->fecha;
            $c->vtoPago = isset($d['vto_pago']) ? $d['vto_pago'] : $c->fecha;
        }
        $c->receptor(isset($rec['documento']) ? $rec['documento'] : '');
        $c->condicionIvaReceptor = $cond;
        if (isset($d['moneda'])) {
            $c->moneda = $d['moneda'];
            $c->cotizacion = isset($d['cotizacion']) ? (float) $d['cotizacion'] : 1.0;
        }

        // cálculo: gravados (con IVA, también 0 %) y exentos por separado
        $grav = array(); $exen = array();
        foreach ((array) $d['items'] as $it) {
            if (!empty($it['exento'])) {
                $it['alicuota'] = 0;
                $exen[] = $it;
            } else {
                $grav[] = $it;
            }
        }
        $esC = Comprobante::esC($c->tipo);
        $perc = array();
        foreach ((array) (isset($d['percepciones']) ? $d['percepciones'] : array()) as $p) {
            $perc[] = $p;
        }
        $calc = CalculoComprobante::calcular(array_merge($grav, $exen), $perc);
        $cg = CalculoComprobante::calcular($grav);
        $ce = CalculoComprobante::calcular($exen);
        if ($esC) {
            // C: no se discrimina IVA; el importe de cada renglón ya es final
            $c->impNeto = $calc['subtotal'];
            $c->impOpEx = 0.0;
        } else {
            foreach ($cg['alicuotas'] as $a) {
                $c->agregarIva($a['alicuota'], $a['base'], $a['importe']);
            }
            $c->impNeto = $cg['subtotal'];
            $c->impOpEx = $ce['subtotal'];
        }
        foreach ($calc['percepciones'] as $p) {
            $c->agregarTributo(isset($p['tributo_id']) ? $p['tributo_id'] : $o['tributo_id'], $p['descripcion'], $p['base'], $p['alicuota'], $p['importe']);
        }
        $c->totalizar();
        $c->impTotal = CalculoComprobante::r2($c->impNeto + $c->impOpEx + ($esC ? 0 : $c->impIva) + $c->impTrib);
        if ($esC) {
            $calc['iva'] = 0.0;
            $calc['total'] = $c->impTotal;
        }

        // asociado (NC/ND)
        if ($tipo !== 'FC') {
            if (empty($d['asociado'])) {
                throw new AfipException('La nota de crédito o débito necesita el comprobante asociado');
            }
            $a = $d['asociado'];
            $c->agregarAsociado((int) $a['tipo_afip'], (int) (isset($a['pto_vta']) ? $a['pto_vta'] : $c->ptoVta), (int) $a['numero'],
                isset($a['fecha']) ? $a['fecha'] : '', $this->afip->cfg['cuit']);
        }
        // FCE MiPyMEs
        if (Comprobante::esFce($c->tipo)) {
            if ($tipo === 'FC') {
                $c->vtoPago = isset($d['vto_pago']) ? $d['vto_pago'] : date('Y-m-d', strtotime($c->fecha . ' +' . (int) $o['fce_dias_vencimiento'] . ' days'));
                if ($o['cbu'] !== '') {
                    $c->agregarOpcional('2101', $o['cbu']);
                }
                $c->agregarOpcional('27', $o['fce_transferencia']);
            } else {
                $c->agregarOpcional('22', isset($d['anulacion']) ? $d['anulacion'] : $o['fce_anulacion']);
            }
        }
        return array($c, $calc);
    }

    /**
     * Arma, valida y pide el CAE. Devuelve el resultado de Afip::autorizar (aprobado, numero, cae, vence, fecha,
     * observaciones, errores) más 'comprobante', 'calculo' y 'qr'. Si ARCA rechaza, 'aprobado' = false y no se
     * numera nada: el sistema no graba.
     */
    public function emitir(array $d)
    {
        list($c, $calc) = $this->armar($d);
        $r = $this->afip->autorizar($c, $this->pdo);
        $r['comprobante'] = $c;
        $r['calculo'] = $calc;
        $r['qr'] = !empty($r['aprobado']) ? $this->afip->urlQr($c, $r['cae']) : '';
        return $r;
    }
}
