<?php
declare(strict_types=1);

namespace App\Modules\Comprobantes;

use App\Core\Db;
use App\Core\Valida;
use App\Modules\Arca\Arca;
use App\Modules\Arca\ArcaSinRespuesta;
use App\Modules\Arca\AutorizacionRechazada;
use App\Modules\Arca\GrabacionFallida;
use App\Modules\Configuracion\Configuracion;
use App\Modules\Stock\ReservasService;
use App\Modules\Stock\StockNoDisponible;
use PDO;

/**
 * Emisión y consulta de comprobantes de venta (facturas, notas de crédito y de débito A, B y C) sobre el esquema O&S.
 *
 * armar()  valida lo que vino del formulario y calcula todo en el servidor (nunca se confía en totales del navegador).
 * emitir() numera con la sucursal bloqueada y graba en UNA transacción: subdiario, iva, estadistica, caja y ctact. La
 *          clave de la operación evita que un doble clic o un "volver atrás" emita el comprobante dos veces.
 *
 * Todavía no pide CAE a ARCA (etapa 3): los comprobantes quedan como internos, sin CAE. La numeración sale de
 * sucursales (ultima_factura_a, ultima_nc_b...) igual que en el VFP.
 */
final class ComprobantesService
{
    public const MAX_RENGLONES = 100;
    private const TIPOS_PAGO_CONTADO = [1, 2, 3, 5, 10]; // efectivo, tickets, tarjeta, cheques, billetera virtual
    private const CANAL = 'W';

    private PDO $pdo;

    public function __construct()
    {
        $this->pdo = Db::pdo();
    }

    // ------------------------------------------------------------------ contexto

    /** Datos de la empresa que factura (parametros, fila 1) y su condición (RI, MT o EX). @return array<string,mixed> */
    public function emisor(): array
    {
        $e = $this->pdo->query('SELECT * FROM parametros WHERE id = 1')->fetch() ?: [];
        $e['condicion'] = Tipos::condicionEmisor(isset($e['iva']) ? (int) $e['iva'] : null);
        return $e;
    }

    /** @return array<int,array<string,mixed>> sucursales con punto de venta, por código */
    public function sucursales(): array
    {
        $out = [];
        foreach ($this->pdo->query('SELECT * FROM sucursales WHERE prefijo > 0 ORDER BY codigo')->fetchAll() as $s) {
            $out[(int) $s['codigo']] = $s;
        }
        return $out;
    }

    /** Id de ARCA de la condición de IVA de un cliente (tabla de equivalencias), o null si no está cargada. */
    public function condicionArca(int $ivaCliente): ?int
    {
        $st = $this->pdo->prepare("SELECT codigoafip FROM equivalencias_afip WHERE tabla = 'Equivaliencia' AND codigooys = ? LIMIT 1");
        $st->execute([(string) $ivaCliente]);
        $v = $st->fetchColumn();
        return $v === false ? null : (int) $v;
    }

    /** Deuda del cliente en cuenta corriente (como el VFP: débitos menos créditos y descuentos). */
    public function deudaCliente(int $cliente): float
    {
        $st = $this->pdo->prepare('SELECT COALESCE(SUM(IF(tico < 7, neto, -neto - descuento)), 0) FROM ctact WHERE cliente = ? AND neto > 0.009');
        $st->execute([$cliente]);
        return Calculadora::r2((float) $st->fetchColumn());
    }

    /** Alícuotas por código: codigo => porcentaje. @return array<string,float> */
    public function alicuotas(): array
    {
        $out = [];
        foreach ($this->pdo->query('SELECT codigo, porcentaje FROM tasas_iva ORDER BY porcentaje DESC')->fetchAll() as $a) {
            $out[(string) $a['codigo']] = (float) $a['porcentaje'];
        }
        return $out;
    }

    // ------------------------------------------------------------------ armar (validar y calcular)

    /**
     * @param array<string,mixed> $in lo del formulario: tipo, cliente, sucursal, forma_pago, fpago, descuento, items[], asociado...
     * @param bool $puedeExcederCredito el usuario tiene comprobantes.credito
     * @return array{ok:bool,errores:array<string,string>,cbte:?array<string,mixed>}
     */
    /** @param array{usuario_id:int,nombre:string}|null $autorizoPrevio quién ya autorizó vender sin stock (lo fija el código, nunca el navegador: lo usa el cobro de Mercado Pago que se emite después) */
    public function armar(array $in, bool $puedeExcederCredito = false, int $usuarioId = 0, ?array $autorizoPrevio = null): array
    {
        $err = [];
        $tipo = strtoupper(trim((string) ($in['tipo'] ?? '')));
        if (!Tipos::valido($tipo)) {
            return ['ok' => false, 'errores' => ['tipo' => 'Elegí el tipo de comprobante.'], 'cbte' => null];
        }
        $interno = Tipos::interno($tipo); // presupuesto o su contrapartida: sin ARCA, sin IVA fiscal

        // Factura que sale de un presupuesto: los renglones, el cliente y el descuento son los del presupuesto (no se editan)
        $presupuesto = null;
        $presId = $tipo === 'FC' ? (int) ($in['presupuesto'] ?? 0) : 0;
        if ($presId > 0) {
            $presupuesto = $this->presupuestoParaFacturar($presId);
            if (is_string($presupuesto)) {
                return ['ok' => false, 'errores' => ['presupuesto' => $presupuesto], 'cbte' => null];
            }
            $in = $this->entradaDesdePresupuesto($presupuesto) + $in;
        }

        // Emisor
        $emisor = $this->emisor();
        if (!$interno && ($emisor['condicion'] === null || !Valida::cuit((string) ($emisor['cuit'] ?? '')))) {
            $err['emisor'] = 'Faltan los datos de la empresa: cargá razón social, CUIT y condición de IVA en Tablas → Parámetros.';
        }

        // Cliente
        $cliente = null;
        $codCli = (int) ($in['cliente'] ?? 0);
        if ($codCli > 0) {
            $st = $this->pdo->prepare('SELECT * FROM clientes WHERE codigo = ?');
            $st->execute([$codCli]);
            $cliente = $st->fetch() ?: null;
        }
        $condArca = null;
        if ($cliente === null) {
            $err['cliente'] = 'Elegí el cliente.';
        } elseif ((int) $cliente['activo'] !== 1) {
            $err['cliente'] = 'El cliente está desactivado.';
        } else {
            $condArca = $this->condicionArca((int) $cliente['iva']);
            if ($condArca === null && !$interno) {
                $err['cliente'] = 'No hay equivalencia de ARCA para la condición de IVA del cliente (código ' . (int) $cliente['iva']
                    . '). Corregí la condición del cliente o cargá la equivalencia.';
            }
        }

        // Asociado (notas de crédito y débito; la contrapartida X corrige un presupuesto)
        $asoc = null;
        if (in_array($tipo, ['NC', 'ND', 'NX'], true)) {
            $asoc = $this->asociado((int) ($in['asociado'] ?? 0), $codCli, $tipo);
            if (!is_array($asoc)) {
                $err['asociado'] = $asoc;
            }
        }
        // Lo que sale de un presupuesto (la factura y sus notas) va solo al subdiario: sin caja, cuenta corriente ni stock
        $solo = $presupuesto !== null || (is_array($asoc) && (int) ($asoc['solo_subdiario'] ?? 0) === 1);
        // DECISIÓN D-48 (pedido de Hernán, 18/11): con la marca «Presupuestos sin IVA» (Configuración) el presupuesto no calcula IVA sobre el valor de los productos
        // (el precio es el final, como una factura C); la contrapartida hereda lo del presupuesto. Cada presupuesto guarda cómo se hizo (subdiario.sin_iva).
        $sinIva = ($tipo === 'PR' && Configuracion::activo('presupuestos.sin_iva')) || ($tipo === 'NX' && is_array($asoc) && (int) ($asoc['sin_iva'] ?? 0) === 1);

        // Sucursal: la de la factura asociada, o la elegida
        $sucs = $this->sucursales();
        $codSuc = is_array($asoc) ? (int) $asoc['sucursal'] : (int) ($in['sucursal'] ?? (count($sucs) === 1 ? array_key_first($sucs) : 0));
        $suc = $sucs[$codSuc] ?? null;
        if ($suc === null) {
            $err['sucursal'] = 'Elegí la sucursal (punto de venta). Si no hay ninguna, cargá el punto de venta en Tablas → Sucursales.';
        }
        // Con ARCA: la sucursal tiene que poder emitir electrónicamente y lo que se corrige tiene que tener CAE
        $electronica = $suc !== null && !$interno && Arca::electronica($suc);
        if ($electronica && ($problema = Arca::problema($suc)) !== null) {
            $err['sucursal'] = $problema;
        }
        if ($electronica && is_array($asoc) && ((string) $asoc['fe_ccae'] === '' || (string) $asoc['fe_ccae'] === '.')) {
            $err['asociado'] = 'El comprobante que se corrige no tiene CAE (es interno o de prueba): ARCA no lo conoce, no se puede hacer una nota electrónica sobre él.';
        }

        // Letra
        $letra = '';
        if ($interno) {
            $letra = Tipos::LETRA_INTERNA;
        } elseif (is_array($asoc)) {
            $letra = (string) $asoc['letra'];
        } elseif ($emisor['condicion'] !== null && $condArca !== null) {
            $letra = Tipos::letra((string) $emisor['condicion'], $condArca);
        }
        if ($letra !== '' && $suc !== null && Tipos::columnaNumerador($tipo, $letra) === null) {
            $err['tipo'] = 'No se emiten ' . mb_strtolower(Tipos::NOMBRES[$tipo]) . ' ' . $letra . '.';
        }
        if (!$interno && is_array($asoc) && $emisor['condicion'] !== null && $emisor['condicion'] !== 'RI' && $letra !== 'C') {
            $err['asociado'] = 'El comprobante asociado no es compatible con la condición de IVA de la empresa.';
        }

        // Concepto (ARCA): productos, servicios o ambos. Con servicios hace falta el período y el vencimiento del pago.
        // DECISIÓN D-11 (Claude, a confirmar por Hernán): el concepto por omisión sale de Configuración (el VFP mandaba siempre 1, productos).
        $concepto = $interno ? 1 : (int) ($in['concepto'] ?? Configuracion::entero('comprobantes.concepto_defecto', 1));
        if (!in_array($concepto, [1, 2, 3], true)) {
            $err['concepto'] = 'Elegí productos, servicios o ambos.';
            $concepto = 1;
        }
        $servDesde = $servHasta = $vtoPago = null;
        if ($concepto > 1) {
            $hoy = date('Y-m-d');
            foreach (['serv_desde' => &$servDesde, 'serv_hasta' => &$servHasta, 'vto_pago' => &$vtoPago] as $campo => &$ref) {
                $ref = trim((string) ($in[$campo] ?? '')) === '' ? $hoy : Valida::fecha((string) $in[$campo]);
                if ($ref === null) {
                    $err[$campo] = 'La fecha no es válida.';
                }
            }
            unset($ref);
            if ($servDesde !== null && $servHasta !== null && $servHasta < $servDesde) {
                $err['serv_hasta'] = 'El fin del período no puede ser anterior al inicio.';
            }
        }

        // Forma de pago
        // DECISIÓN D-43 (Claude, a confirmar por Hernán): en facturas, notas de débito y presupuestos la forma de pago NO se pide en el formulario: se
        // pide al terminar la venta, en la pantalla de revisión, con el total a la vista. Mientras no llega forma_pago el comprobante queda con el
        // pago pendiente (armar() valida todo lo demás) y emitir() no lo graba. Las notas de crédito conservan su elección en el formulario.
        // DECISIÓN D-49 (pedido de Hernán, 18/11): se puede pagar con varias formas de pago a la vez (efectivo + tarjeta, cheque + billetera, y hasta una parte a cuenta
        // corriente); tienen que sumar el total. Se validan con el total ya calculado (resolverPagos, más abajo).
        $necesitaPago = !Tipos::credito($tipo) && !$solo;
        $hayPago = trim((string) ($in['forma_pago'] ?? '')) !== '' || $this->hayLineasDePago($in['pagos'] ?? null);
        $pagoPendiente = $necesitaPago && !$hayPago;
        $formaPago = $pagoPendiente || $necesitaPago ? 0 : (int) ($in['forma_pago'] ?? 0);
        if (!$necesitaPago && !in_array($formaPago, [1, 2], true)) {
            $err['forma_pago'] = 'Elegí contado o cuenta corriente.';
        }
        $pagos = null;

        // DECISIÓN D-06 (Claude, a confirmar por Hernán): la fecha es siempre la de hoy, como el VFP (ARCA aceptaría hasta 5 días
        // atrás en productos y 10 en servicios, nunca antes del último comprobante aprobado). Para permitir otra fecha habría que
        // agregar el campo al formulario y validar contra el último comprobante de ese punto de venta y tipo.
        $fecha = date('Y-m-d');

        // Descuento, vendedor, condición de pago
        $desc = 0.0;
        if (trim((string) ($in['descuento'] ?? '')) !== '') {
            $d = Valida::decimal((string) $in['descuento']);
            if ($d === null || $d < 0 || $d > 100) {
                $err['descuento'] = 'El descuento tiene que estar entre 0 y 100.';
            } else {
                $desc = $d;
            }
        }
        $vendedor = $this->codigoExistente('vendedores', (string) ($in['vendedor'] ?? ''), 'vendedor', $err);
        $condPago = $this->codigoExistente('condicionpago', (string) ($in['cond_pago'] ?? ''), 'cond_pago', $err);

        // Renglones
        $alic = $this->alicuotas();
        $items = [];
        $errItems = [];
        $n = 0;
        foreach ((array) ($in['items'] ?? []) as $r) {
            $r = is_array($r) ? $r : [];
            $texto = trim((string) ($r['descripcion'] ?? ''));
            $cantTxt = trim((string) ($r['cantidad'] ?? ''));
            $precioTxt = trim((string) ($r['precio'] ?? ''));
            $codArt = trim((string) ($r['articulo'] ?? ''));
            $adicional = trim((string) ($r['texto'] ?? '')); // texto extra del renglón, se imprime debajo de la descripción
            if ($texto === '' && $cantTxt === '' && $precioTxt === '' && $codArt === '') {
                continue; // renglón en blanco
            }
            $n++;
            $e = [];
            $art = null;
            if ($codArt !== '') {
                // Se acepta el código interno, el EAN-13, el DUN-14, el SKU o el código de barras anterior (si es uno solo).
                $st = $this->pdo->prepare('SELECT codigo, descripcion, codigo_de_barras FROM articulos WHERE codigo = ?');
                $st->execute([mb_strtoupper($codArt)]);
                $art = $st->fetch() ?: null;
                if ($art === null) {
                    $st = $this->pdo->prepare("SELECT codigo, descripcion, codigo_de_barras FROM articulos
                        WHERE estado = 'AC' AND (ean13 = ? OR dun14 = ? OR sku = ? OR codigo_de_barras = ?) LIMIT 2");
                    $st->execute([$codArt, $codArt, $codArt, $codArt]);
                    $hallados = $st->fetchAll();
                    $art = count($hallados) === 1 ? $hallados[0] : null;
                }
                if ($art === null) {
                    $e[] = 'el artículo ' . mb_strtoupper($codArt) . ' no existe';
                }
            }
            if ($texto === '' && $art !== null) {
                $texto = (string) $art['descripcion'];
            }
            if ($texto === '' || mb_strlen($texto) > 2000) { // D-10 (Claude, a confirmar): hasta 2.000 letras por renglón (el VFP de OYS llegó a 3.900)
                $e[] = 'falta la descripción (hasta 2.000 letras)';
            }
            if (mb_strlen($adicional) > 2000) {
                $e[] = 'el texto para imprimir admite hasta 2.000 letras';
            }
            $cant = Valida::decimal($cantTxt);
            if ($cant === null || $cant <= 0 || $cant > 999999999.999) {
                $e[] = 'la cantidad tiene que ser mayor que cero';
            }
            $precio = Valida::decimal($precioTxt);
            if ($precio === null || $precio < 0 || $precio > 99999999999.0) {
                $e[] = 'el precio no es válido';
            }
            $codAlic = trim((string) ($r['alicuota'] ?? ''));
            if (!isset($alic[$codAlic])) {
                $e[] = 'elegí la alícuota de IVA';
            } elseif ($presupuesto !== null && $letra === 'A' && $precio !== null && $alic[$codAlic] > 0) {
                $precio = $precio / (1 + $alic[$codAlic] / 100); // el presupuesto tiene el IVA incluido; la factura A va neta
            }
            if ($e !== []) {
                $errItems[] = "Renglón {$n}: " . implode(', ', $e) . '.';
                continue;
            }
            $items[] = [
                'articulo' => $art['codigo'] ?? null, 'codigo_de_barras' => $art['codigo_de_barras'] ?? null,
                'descripcion' => $texto, 'texto' => $adicional, 'cantidad' => round($cant, 3), 'precio' => round($precio, 4),
                'alicuota' => $alic[$codAlic], 'cod_alicuota' => $codAlic,
            ];
        }
        if ($items === [] && $errItems === []) {
            $errItems[] = 'Cargá al menos un renglón.';
        }
        if (count($items) + count($errItems) > self::MAX_RENGLONES) {
            $errItems[] = 'Máximo ' . self::MAX_RENGLONES . ' renglones por comprobante.';
        }
        if ($errItems !== []) {
            $err['items'] = implode("\n", $errItems);
        }

        // Cálculo
        $calc = null;
        if ($letra !== '' && $items !== []) {
            $calc = Calculadora::calcular($sinIva ? 'C' : $letra, $items, $desc);
            if ($sinIva) { // sin IVA discriminado, pero cada renglón conserva su alícuota (se usa si después se factura)
                foreach ($calc['items'] as $i => $it) {
                    $calc['items'][$i]['alicuota'] = (float) $items[$i]['alicuota'];
                }
            }
            if ($calc['total'] <= 0) {
                $err['items'] = trim(($err['items'] ?? '') . "\nEl total del comprobante tiene que ser mayor que cero.");
            } elseif ($calc['total'] > Calculadora::MAX_IMPORTE) {
                $err['items'] = trim(($err['items'] ?? '') . "\nEl total es demasiado grande.");
            }
        }

        // Cómo se paga: una o varias formas de pago que suman el total (con vuelto si se entrega de más en una que lo acepta)
        $cuentaPago = 0.0;
        $fp = trim((string) ($in['forma_pago'] ?? ''));
        if ($necesitaPago && !$pagoPendiente && $fp !== '' && !in_array($fp, ['1', '2'], true) && !$this->hayLineasDePago($in['pagos'] ?? null)) {
            $err['forma_pago'] = 'Elegí contado o cuenta corriente.';
        } elseif ($necesitaPago && !$pagoPendiente && $calc !== null && !isset($err['items'])) {
            $pagos = $this->resolverPagos($in, $calc['total'], $err);
            if ($pagos !== null) {
                $cuentaPago = $pagos['cuenta'];
                $formaPago = $cuentaPago > 0.004 ? 2 : 1; // 2 si algo queda en cuenta corriente
            }
        }

        // DECISIÓN D-01 (Claude, a confirmar por Hernán): una nota de crédito no puede superar lo que queda por acreditar de la
        // factura. El VFP lo permitía. Se cambia en Configuración (comprobantes.nc_limite_factura) sin tocar el código.
        if ($calc !== null && Tipos::credito($tipo) && is_array($asoc) && !isset($err['items']) && Configuracion::activo('comprobantes.nc_limite_factura')) {
            $libre = Calculadora::r2((float) $asoc['neto'] - $this->acreditado((int) $asoc['fe_clave']));
            if ($calc['total'] > $libre + 0.004) {
                $err['items'] = 'La nota de crédito (' . plata($calc['total']) . ') supera lo que queda por acreditar de ' . ($tipo === 'NX' ? 'el presupuesto' : 'la factura') . ' (' . plata($libre) . ').';
            }
        }

        // DECISIÓN D-02 (Claude, a confirmar por Hernán): un cliente con límite de crédito 0 no tiene control de crédito. En el VFP
        // cada comprobante a cuenta corriente de un cliente con límite 0 pedía autorización de un supervisor. Se cambia en
        // Configuración (comprobantes.credito_cero_sin_limite): destildado, el límite 0 significa "no se le puede fiar".
        $excede = false;
        if ($calc !== null && $cliente !== null && $necesitaPago && $cuentaPago > 0.004 && ($ex = $this->creditoExcedido($cliente, $cuentaPago)) !== null) {
            $excede = true;
            if (!($puedeExcederCredito && ($in['excede_ok'] ?? '') === '1')) {
                $err['credito'] = 'El comprobante excede el crédito del cliente (límite ' . plata($ex['limite']) . ', deuda actual ' . plata($ex['deuda'])
                    . '). ' . ($puedeExcederCredito ? 'Tildá "Autorizo exceder el crédito" para emitirlo.' : 'Pedile a un administrador que lo autorice.');
            }
        }

        // Stock (D-51): lo que no alcanza (stock menos lo reservado por otros puestos) solo se vende con la autorización de un supervisor, y se exige al emitir,
        // no al revisar (la revisión muestra qué falta y pide la autorización). Los servicios y lo que no controla stock no cuentan.
        $reserva = ReservasService::tokenValido($in['reserva'] ?? null);
        $faltantes = [];
        $autorizo = null;
        $auto = null;
        if (in_array($tipo, ['FC', 'PR'], true) && !$solo && $items !== [] && !isset($err['items'])) {
            $rs = new ReservasService($this->pdo);
            $faltantes = $rs->faltantes($items, $reserva);
            $auto = $rs->autorizaPorPermiso($in, $usuarioId, $tipo); // D-55: roles que venden sin stock sin pedir autorización
            if ($faltantes !== [] && $autorizoPrevio !== null) {
                $autorizo = $autorizoPrevio;
            } elseif ($faltantes !== [] && $auto !== null) {
                $autorizo = $auto;
            } elseif ($faltantes !== [] && !$pagoPendiente) {
                $a = $rs->autorizar($in, $usuarioId);
                if ($a['ok']) {
                    $autorizo = ['usuario_id' => $a['usuario_id'], 'nombre' => $a['nombre']];
                } else {
                    $err['stock'] = ($a['error'] !== null ? $a['error'] . "\n" : '') . 'No alcanza el stock disponible: hace falta la autorización de un supervisor para venderlo igual.';
                }
            }
        }

        $mails = null;
        if (trim((string) ($in['email'] ?? '')) !== '') {
            $mails = Valida::mails((string) $in['email']);
            if ($mails === null || mb_strlen($mails) > 255) {
                $err['email'] = 'Algún mail no es válido (separalos con punto y coma).';
            }
        }
        $obs = trim((string) ($in['observaciones'] ?? ''));
        if (mb_strlen($obs) > 255) {
            $err['observaciones'] = 'Las observaciones admiten hasta 255 letras.';
        }
        $oc = trim((string) ($in['orden_compra'] ?? ''));
        if (mb_strlen($oc) > 20) {
            $err['orden_compra'] = 'La orden de compra admite hasta 20 letras.';
        }

        if ($err !== [] || $calc === null || $cliente === null || $suc === null) {
            return ['ok' => false, 'errores' => $err, 'cbte' => null];
        }
        return ['ok' => true, 'errores' => [], 'cbte' => [
            'tipo' => $tipo, 'letra' => $letra, 'fecha' => $fecha, 'sucursal' => $codSuc, 'prefijo' => (int) $suc['prefijo'],
            'cliente' => $cliente, 'cond_arca' => $condArca, 'forma_pago' => $pagoPendiente ? null : $formaPago, 'pagos' => $pagos['lineas'] ?? [], 'pago_contado' => $pagos['contado'] ?? 0.0, 'pago_cuenta' => $pagos['cuenta'] ?? 0.0,
            'vuelto' => $pagos['vuelto'] ?? 0.0, 'pago_pendiente' => $pagoPendiente,
            'vendedor' => $vendedor, 'cond_pago' => $condPago, 'descuento' => $desc, 'observaciones' => $obs, 'orden_compra' => $oc,
            'email' => $mails, 'asociado' => is_array($asoc) ? $asoc : null, 'calculo' => $calc, 'excede_credito' => $excede,
            'tipo_afip' => Tipos::tipoAfip($tipo, $letra), 'emisor' => $emisor, 'sucursal_datos' => $suc, 'electronica' => $electronica,
            'concepto' => $concepto, 'serv_desde' => $servDesde, 'serv_hasta' => $servHasta, 'vto_pago' => $vtoPago,
            'interno' => $interno, 'sin_iva' => $sinIva, 'solo_subdiario' => $solo, 'presupuesto' => $presupuesto !== null ? (int) $presupuesto['fe_clave'] : null,
            'stock_faltantes' => $faltantes, 'stock_autorizado' => $autorizo, 'stock_automatico' => $auto ?? $autorizoPrevio, 'reserva_token' => $reserva,
            'advertencias' => $solo || in_array($tipo, ['FC', 'PR'], true) ? [] : (new \App\Modules\Stock\StockService($this->pdo))->advertencias($tipo, $items),
        ]];
    }

    /**
     * Presupuesto listo para convertirse en factura (con sus renglones), o el motivo por el que no se puede: solo una factura por presupuesto
     * y ninguno con contrapartida (nota de crédito X).
     * @return string|array<string,mixed>
     */
    public function presupuestoParaFacturar(int $id): string|array
    {
        $st = $this->pdo->prepare("SELECT * FROM subdiario WHERE fe_clave = ? AND tipo = 'PR'");
        $st->execute([$id]);
        $p = $st->fetch();
        if ($p === false) {
            return 'El presupuesto no existe.';
        }
        if ($p['estado'] !== '') {
            return 'El presupuesto está anulado.';
        }
        $st = $this->pdo->prepare('SELECT comprobante, prefijo, fe_nrocbte, fe_ccae FROM subdiario WHERE presupuesto_id = ?');
        $st->execute([$id]);
        if (($f = $st->fetch()) !== false) {
            return 'Este presupuesto ya generó la factura ' . numero_visible($f) . ': un presupuesto se factura una sola vez.';
        }
        $st = $this->pdo->prepare("SELECT COUNT(*) FROM subdiario WHERE tipo = 'NX' AND estado = '' AND fe_tipocbte_asoc = 'PR' AND fe_nrocbte_asoc = ? AND sucursal = ? AND (fe_fecha_asoc IS NULL OR fe_fecha_asoc = ?)");
        $st->execute([$p['comprobante'], $p['sucursal'], $p['fecha']]);
        if ((int) $st->fetchColumn() > 0) {
            return 'Este presupuesto tiene una nota de crédito X: no se puede facturar.';
        }
        $st = $this->pdo->prepare('SELECT * FROM estadistica WHERE subdiario_id = ? ORDER BY orden, fe_clave');
        $st->execute([$id]);
        $p['items'] = $st->fetchAll();
        return $p;
    }

    /** Lo que sería el formulario de una factura con los datos de un presupuesto (precios con IVA; la factura A los pasa a neto). @return array<string,mixed> */
    public function entradaDesdePresupuesto(array $p): array
    {
        $porCodigo = [];
        foreach ($this->alicuotas() as $cod => $pct) {
            $porCodigo[(string) $pct] = $cod;
        }
        $items = [];
        foreach ($p['items'] as $it) {
            $items[] = ['articulo' => (string) ($it['articulo'] ?? ''), 'descripcion' => (string) $it['observaciones'], 'texto' => (string) ($it['texto_impresion'] ?? ''),
                'cantidad' => number_format((float) $it['unidades'], 3, ',', ''), 'precio' => number_format((float) $it['precio'], 4, ',', ''),
                'alicuota' => $porCodigo[(string) (float) $it['por_iva']] ?? ''];
        }
        return ['tipo' => 'FC', 'presupuesto' => (string) $p['fe_clave'], 'cliente' => (string) $p['cliente'], 'sucursal' => (string) $p['sucursal'],
            'forma_pago' => (string) $p['forma_pago'], 'descuento' => (float) $p['porcentaje_descuento'] > 0 ? number_format((float) $p['porcentaje_descuento'], 2, ',', '') : '',
            'vendedor' => (string) ($p['vendedor'] ?? ''), 'cond_pago' => (string) ($p['cond_pago'] ?? ''), 'email' => (string) ($p['fe_cemailenvio'] ?? ''),
            'observaciones' => (string) ($p['observaciones'] ?? ''), 'orden_compra' => (string) ($p['orden_compra'] ?? ''), 'items' => $items];
    }

    /** @return string|array<string,mixed> el comprobante asociado (con sucursal, letra, neto...) o el motivo por el que no sirve */
    private function asociado(int $feClave, int $cliente, string $tipo = 'NC'): string|array
    {
        if ($feClave <= 0) {
            return $tipo === 'NX' ? 'Elegí el presupuesto que se corrige.' : 'Elegí el comprobante que se corrige (factura o nota de débito del cliente).';
        }
        $st = $this->pdo->prepare('SELECT fe_clave, tipo, letra, sucursal, prefijo, comprobante, fe_nrocbte, fe_ccae, fecha, cliente, neto, estado, solo_subdiario, sin_iva FROM subdiario WHERE fe_clave = ?');
        $st->execute([$feClave]);
        $a = $st->fetch();
        if ($a === false) {
            return 'El comprobante asociado no existe.';
        }
        if ((int) $a['cliente'] !== $cliente) {
            return 'El comprobante asociado es de otro cliente.';
        }
        if ($tipo === 'NX' ? $a['tipo'] !== 'PR' : !in_array($a['tipo'], ['FC', 'ND'], true)) {
            return $tipo === 'NX' ? 'La nota de crédito X solo corrige presupuestos.' : 'Solo se asocian facturas o notas de débito.';
        }
        if ($a['estado'] !== '') {
            return 'El comprobante asociado está anulado.';
        }
        return $a;
    }

    /** Total de notas de crédito ya emitidas contra un comprobante. */
    private function acreditado(int $feClaveAsociado): float
    {
        $st = $this->pdo->prepare(
            "SELECT COALESCE(SUM(n.neto), 0) FROM subdiario n JOIN subdiario a ON a.fe_clave = ?
              WHERE n.tipo = IF(a.tipo = 'PR', 'NX', 'NC') AND n.estado = '' AND n.sucursal = a.sucursal AND n.letra = a.letra
                AND n.fe_tipocbte_asoc = a.tipo AND n.fe_nrocbte_asoc = " . Tipos::sqlNumeroVisible('a') . "
                AND (n.fe_fecha_asoc IS NULL OR n.fe_fecha_asoc = a.fecha)"
        );
        $st->execute([$feClaveAsociado]);
        return Calculadora::r2((float) $st->fetchColumn());
    }

    /**
     * Lo que entrega el cliente y el vuelto (D-44): sin importe recibido es el total justo; de menos no es un pago; de más solo si la forma acepta vuelto.
     * @param array<string,mixed> $pago el de validarPago() @param array<string,string> $err @return array<string,mixed> el pago con 'recibido' y 'vuelto'
     */
    public function aplicarVuelto(array $pago, float $total, array $in, array &$err): array
    {
        $recibidoTxt = trim((string) ($in['recibido'] ?? ''));
        $recibido = $total;
        if ($recibidoTxt !== '') {
            $v = Valida::decimal($recibidoTxt);
            if ($v === null || $v <= 0 || $v > Calculadora::MAX_IMPORTE) {
                $err['recibido'] = 'El importe recibido no es válido.';
            } else {
                $recibido = Calculadora::r2($v);
            }
        }
        if (!isset($err['recibido'])) {
            if ($recibido < $total - 0.004) {
                $err['recibido'] = 'El cliente entregó menos que el total (' . plata($total) . '): cobrá el total o elegí cuenta corriente.';
            } elseif ($recibido > $total + 0.004 && !$pago['acepta_vuelto']) {
                $err['recibido'] = $pago['descripcion'] . ' no acepta vuelto: cobrá exactamente ' . plata($total) . '.';
            }
        }
        $pago['recibido'] = $recibido;
        $pago['vuelto'] = max(0.0, Calculadora::r2($recibido - $total));
        return $pago;
    }

    /**
     * DECISIÓN D-02: un cliente con límite 0 no tiene control de crédito (interruptor comprobantes.credito_cero_sin_limite). Si pasarse del límite,
     * devuelve [limite, deuda]; si no, null.
     * @param array<string,mixed> $cliente @return array{limite:float,deuda:float}|null
     */
    public function creditoExcedido(array $cliente, float $total): ?array
    {
        if (!((float) $cliente['credito'] > 0 || !Configuracion::activo('comprobantes.credito_cero_sin_limite'))) {
            return null;
        }
        $deuda = $this->deudaCliente((int) $cliente['codigo']);
        return $deuda + $total > (float) $cliente['credito'] + 0.004 ? ['limite' => (float) $cliente['credito'], 'deuda' => $deuda] : null;
    }

    /** ¿Vino al menos un renglón de pago cargado (forma de pago o importe)? */
    public function hayLineasDePago(mixed $pagos): bool
    {
        foreach ((array) $pagos as $p) {
            if (is_array($p) && (trim((string) ($p['fpago'] ?? '')) !== '' || trim((string) ($p['importe'] ?? '')) !== '')) {
                return true;
            }
        }
        return false;
    }

    /**
     * Cómo se paga, con el total ya calculado (D-49, antes un solo medio por D-04): una o varias formas de pago que tienen que sumar el total.
     *  - forma_pago = 2: todo a cuenta corriente. Si no, pagos[][fpago, importe, recibido, nro, banco, vto] (o, de una sola forma, fpago/recibido/pago_nro/pago_banco/pago_vto).
     *  - Con una sola forma de pago el importe puede quedar vacío (es el total). Una forma de tipo cuenta corriente deja ese importe como deuda del cliente.
     *  - En efectivo (o la que acepte vuelto) se puede anotar lo recibido: si es más que el importe, la diferencia es el vuelto (D-44).
     *
     * @param array<string,string> $err
     * @return array{lineas:array<int,array<string,mixed>>,contado:float,cuenta:float,vuelto:float}|null
     */
    public function resolverPagos(array $in, float $total, array &$err): ?array
    {
        $vacia = ['fpago' => '', 'importe' => '', 'recibido' => '', 'nro' => '', 'banco' => '', 'vto' => ''];
        $entrada = [];
        $simple = false; // de una sola forma de pago (los errores se marcan en sus campos)
        $legacy = false; // forma de pago única con los campos fpago/recibido/pago_*: la cuenta corriente no es una forma válida (se elige forma_pago = 2)
        $todoCuenta = trim((string) ($in['forma_pago'] ?? '')) === '2';
        if ($todoCuenta) {
            $entrada[] = ['fpago' => $this->codigoCuentaCorriente()] + $vacia;
            $simple = true;
        } else {
            foreach ((array) ($in['pagos'] ?? []) as $p) {
                if (is_array($p) && (trim((string) ($p['fpago'] ?? '')) !== '' || trim((string) ($p['importe'] ?? '')) !== '')) {
                    $entrada[] = array_map(static fn ($v) => trim((string) $v), array_intersect_key($p, $vacia)) + $vacia;
                }
            }
            if ($entrada === []) {
                $entrada[] = ['fpago' => trim((string) ($in['fpago'] ?? '')), 'recibido' => trim((string) ($in['recibido'] ?? '')), 'nro' => trim((string) ($in['pago_nro'] ?? '')),
                    'banco' => trim((string) ($in['pago_banco'] ?? '')), 'vto' => trim((string) ($in['pago_vto'] ?? ''))] + $vacia;
                $simple = $legacy = true;
            }
        }
        if (count($entrada) > 8) {
            $err['pagos'] = 'Hasta 8 formas de pago por venta.';
            return null;
        }
        $varias = count($entrada) > 1;
        $lineas = [];
        $msgs = [];
        $suma = $contado = $cuenta = $vuelto = 0.0;
        $f = $this->pdo->prepare('SELECT codigo, descripcion, tipo, acepta_vuelto FROM forma_de_pagos WHERE codigo = ?');
        foreach ($entrada as $n => $p) {
            $pre = $varias ? 'Forma de pago ' . ($n + 1) . ': ' : '';
            $e = [];
            if ($todoCuenta) { // todo a cuenta corriente: no depende de que exista una forma de pago de ese tipo cargada
                $fp = ['codigo' => $p['fpago'], 'descripcion' => 'CUENTA CORRIENTE', 'tipo' => 6, 'acepta_vuelto' => 0];
            } else {
                $f->execute([$p['fpago']]);
                $fp = $f->fetch();
            }
            if ($fp === false || !in_array((int) $fp['tipo'], $legacy ? self::TIPOS_PAGO_CONTADO : [...self::TIPOS_PAGO_CONTADO, 6], true)) {
                $e['fpago'] = 'Elegí cómo se paga (efectivo, tarjeta, cheque...).';
                $this->volcarErrorPago($e, $pre, $simple, $err, $msgs);
                continue;
            }
            $tipo = (int) $fp['tipo'];
            if ($p['importe'] === '') {
                $imp = $varias ? null : $total;
                if ($imp === null) {
                    $e['pagos'] = 'Cargá el importe de cada forma de pago.';
                }
            } else {
                $imp = Valida::decimal($p['importe']);
                if ($imp === null || $imp <= 0 || $imp > Calculadora::MAX_IMPORTE) {
                    $e['pagos'] = 'El importe no es válido.';
                    $imp = null;
                }
            }
            $linea = ['codigo' => (string) $fp['codigo'], 'descripcion' => (string) $fp['descripcion'], 'tipo' => $tipo, 'acepta_vuelto' => (int) $fp['acepta_vuelto'] === 1,
                'importe' => $imp !== null ? Calculadora::r2($imp) : 0.0, 'recibido' => null, 'vuelto' => 0.0, 'nro' => null, 'banco' => 0, 'vencimiento' => null];
            if ($tipo === 5) { // cheque: número, banco y vencimiento
                $vto = Valida::fecha($p['vto']);
                if ($p['nro'] === '' || mb_strlen($p['nro']) > 20) {
                    $e['pago_nro'] = 'Completá el número del cheque.';
                }
                if (!($p['banco'] !== '' && (int) $p['banco'] > 0 && $this->existe('bancos', 'codigo', (string) (int) $p['banco']))) {
                    $e['pago_banco'] = 'Elegí el banco del cheque.';
                }
                if ($vto === null) {
                    $e['pago_vto'] = 'Completá el vencimiento del cheque.';
                }
                $linea['nro'] = $p['nro'];
                $linea['banco'] = (int) $p['banco'];
                $linea['vencimiento'] = $vto;
            }
            // lo recibido (pantalla anterior a D-137): si vino y es más que el importe, eso es lo que entregó el cliente con esta forma
            if ($tipo !== 6 && $p['recibido'] !== '') {
                $rec = Valida::decimal($p['recibido']);
                if ($rec === null || $rec <= 0 || $rec > Calculadora::MAX_IMPORTE) {
                    $e['recibido'] = 'El importe recibido no es válido.';
                } elseif ($imp !== null && $p['importe'] === '' && !$varias) {
                    $linea['importe'] = Calculadora::r2($rec); // de una sola forma sin importe, lo recibido es lo que paga (si es menos, falta; si es más, vuelto)
                } elseif ($imp !== null && $rec > $linea['importe']) {
                    $linea['recibido'] = Calculadora::r2($rec); // importe y lo recibido aparte: la diferencia es el vuelto de esta forma
                    $linea['vuelto'] = Calculadora::r2($rec - $linea['importe']);
                }
            }
            if ($e !== []) {
                $this->volcarErrorPago($e, $pre, $simple, $err, $msgs);
            }
            $suma += $linea['importe'];
            $lineas[] = $linea;
        }
        if ($msgs !== []) {
            $err['pagos'] = implode("\n", array_unique($msgs));
        }
        $suma = Calculadora::r2($suma);
        $conError = static function () use (&$err): bool {
            return isset($err['pagos']) || isset($err['fpago']) || isset($err['recibido']) || isset($err['pago_nro']) || isset($err['pago_banco']) || isset($err['pago_vto']);
        };
        if (!$conError() && $suma < $total - 0.004) {
            $falta = 'Faltan ' . plata_dec($total - $suma) . ' para llegar al total (' . ($varias ? 'las formas de pago suman ' : 'el cliente entregó ') . plata_dec($suma) . ' y la venta es de ' . plata_dec($total) . '). Sumá otra forma de pago o la cuenta corriente.';
            $simple && !$todoCuenta ? $err['recibido'] = $falta : $err['pagos'] = $falta;
        }
        if ($conError()) {
            return null;
        }
        // DECISIÓN D-137: lo que se paga de más es vuelto y es solo informativo (no frena la venta: puede ser el recargo de una tarjeta).
        // En caja queda el total de la venta: lo de más se descuenta primero de la cuenta corriente (no es plata), después de las formas que
        // aceptan vuelto y por último de las demás (de la última a la primera); en cada fila queda lo que entregó (p_recibido) y la diferencia (p_vuelto).
        $sobra = Calculadora::r2($suma - $total);
        foreach ($lineas as $i => $l) {
            $lineas[$i]['recibido'] = $l['tipo'] === 6 ? null : ($l['recibido'] ?? $l['importe']);
        }
        if ($sobra > 0.004) {
            $orden = array_keys($lineas);
            $prioridad = static fn (array $l): int => $l['tipo'] === 6 ? 0 : ($l['acepta_vuelto'] ? 1 : 2);
            usort($orden, static fn (int $a, int $b): int => [$prioridad($lineas[$a]), -$a] <=> [$prioridad($lineas[$b]), -$b]);
            foreach ($orden as $i) {
                if ($sobra <= 0.004) {
                    break;
                }
                $saca = min($sobra, $lineas[$i]['importe']);
                $lineas[$i]['importe'] = Calculadora::r2($lineas[$i]['importe'] - $saca);
                if ($lineas[$i]['tipo'] !== 6) {
                    $lineas[$i]['vuelto'] = Calculadora::r2($lineas[$i]['vuelto'] + $saca);
                }
                $sobra = Calculadora::r2($sobra - $saca);
            }
        }
        $contado = $cuenta = $vuelto = 0.0;
        foreach ($lineas as $l) {
            $l['tipo'] === 6 ? $cuenta += $l['importe'] : $contado += $l['importe'];
            $vuelto += $l['vuelto'];
        }
        // una forma que quedó en cero (todo lo que dio fue vuelto) no va a la caja; la cuenta corriente en cero tampoco
        $lineas = array_values(array_filter($lineas, static fn (array $l): bool => $l['importe'] > 0.004));
        return ['lineas' => $lineas, 'contado' => Calculadora::r2($contado), 'cuenta' => Calculadora::r2($cuenta), 'vuelto' => Calculadora::r2($vuelto)];
    }

    /** Pasa los errores de un renglón de pago: en el campo (una sola forma de pago) o juntos en «pagos» (varias). @param array<string,string> $e @param array<string,string> $err @param string[] $msgs */
    private function volcarErrorPago(array $e, string $pre, bool $simple, array &$err, array &$msgs): void
    {
        foreach ($e as $k => $m) {
            if ($simple) {
                $err[$k === 'pagos' ? 'fpago' : $k] = $m;
            } else {
                $msgs[] = $pre . $m;
            }
        }
    }

    private function existe(string $tabla, string $col, string $valor): bool
    {
        if (!in_array($tabla, ['bancos', 'vendedores', 'condicionpago'], true) || $col !== 'codigo') {
            return false;
        }
        $st = $this->pdo->prepare("SELECT 1 FROM `{$tabla}` WHERE codigo = ?");
        $st->execute([$valor]);
        return $st->fetchColumn() !== false;
    }

    /** @param array<string,string> $err */
    private function codigoExistente(string $tabla, string $codigo, string $campo, array &$err): ?string
    {
        $codigo = trim($codigo);
        if ($codigo === '') {
            return null;
        }
        if (!$this->existe($tabla, 'codigo', $codigo)) {
            $err[$campo] = 'El código elegido no existe.';
            return null;
        }
        return $codigo;
    }

    // ------------------------------------------------------------------ emitir

    // DECISIÓN D-05 (Claude, a confirmar por Hernán): no hay anulación de comprobantes. Uno con CAE no se anula (se cancela con una
    // nota de crédito, regla de ARCA) y los de prueba se borran juntos desde Sistema → ARCA. Si hace falta anular uno sin CAE hay
    // que agregar acá una acción que lo marque (subdiario.estado = 'A') y ponga en cero su ctact.

    /**
     * Emite el comprobante. Si la clave de la operación ya se usó, devuelve el comprobante ya emitido (no emite otro).
     *
     *  - Sucursal electrónica (modo ≠ interno): registra la operación, pide el CAE a ARCA y graba con el número y el CAE de ARCA. Si ARCA
     *    rechaza no se graba nada (AutorizacionRechazada). Si ARCA aprueba y no se puede grabar, queda todo registrado para recuperarlo
     *    desde Sistema → ARCA (GrabacionFallida) y NUNCA se pide otro CAE para la misma operación.
     *  - Si no: numera con la sucursal (FOR UPDATE) y graba como comprobante interno, sin CAE.
     *
     * @param array<string,mixed> $cbte el 'cbte' de armar()
     * @return array{id:int,numero:int,repetido:bool}
     * @throws AutorizacionRechazada|ArcaSinRespuesta|GrabacionFallida
     */
    public function emitir(array $cbte, string $operacion, int $usuarioId): array
    {
        if (preg_match('/^[0-9a-f]{32}$/', $operacion) !== 1) {
            throw new \InvalidArgumentException('Falta la clave de la operación.');
        }
        $previo = $this->porOperacion($operacion);
        if ($previo !== null) {
            return $previo + ['repetido' => true];
        }
        if (!empty($cbte['pago_pendiente'])) {
            throw new \InvalidArgumentException('Falta indicar cómo se paga.');
        }
        \App\Modules\Licencia\Licencia::exigirParaEmitir(); // suscripción vigente (solo si la instalación la exige)
        if (!empty($cbte['presupuesto'])) { // antes de pedir un CAE: el presupuesto solo genera una factura
            $ya = $this->presupuestoParaFacturar((int) $cbte['presupuesto']);
            if (is_string($ya)) {
                throw new PresupuestoYaFacturado($ya);
            }
        }
        if (!empty($cbte['electronica'])) {
            return $this->emitirConArca($cbte, $operacion, $usuarioId);
        }
        return $this->grabar($cbte, $operacion, $usuarioId, null);
    }

    // DECISIÓN D-13 (Claude, a confirmar por Hernán): si ARCA no contesta (corte, timeout) NO se reintenta sola ni se vuelve a pedir CAE con la
    // misma operación: queda 'pendiente' y se revisa en Sistema → ARCA, para no duplicar un comprobante que ARCA pudo haber aprobado.
    // DECISIÓN D-12 (Claude, a confirmar por Hernán): todo comprobante que no sea de producción (interno, simulado, homologación) se marca
    // es_prueba = 1; el Master los borra juntos desde Sistema → ARCA (ArcaController::borrarPruebas) y el numerador vuelve atrás.
    /** @return array{id:int,numero:int,repetido:bool} */
    private function emitirConArca(array $cbte, string $operacion, int $usuarioId): array
    {
        $pdo = $this->pdo;
        $entorno = Arca::entorno();
        $json = static fn (mixed $v): string => (string) json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
        $c = $cbte['calculo'];
        try { // 1) queda registrado ANTES de hablar con ARCA
            $pdo->prepare('INSERT INTO afip_operaciones (operacion, estado, entorno, tipo_afip, pto_vta, total, usuario_id, solicitud) VALUES (?, ?, ?, ?, ?, ?, ?, ?)')
                ->execute([$operacion, 'pendiente', $entorno, $cbte['tipo_afip'], $cbte['prefijo'], $c['total'], $usuarioId > 0 ? $usuarioId : null, $json($cbte)]);
        } catch (\PDOException $e) {
            if ($e->getCode() !== '23000') {
                throw $e;
            }
            $op = $pdo->prepare('SELECT * FROM afip_operaciones WHERE operacion = ?');
            $op->execute([$operacion]);
            $f = $op->fetch();
            if ($f === false) {
                throw $e;
            }
            if ($f['estado'] === 'grabada' && $f['subdiario_id'] !== null) {
                return ['id' => (int) $f['subdiario_id'], 'numero' => (int) $f['numero'], 'repetido' => true];
            }
            if (in_array($f['estado'], ['aprobada', 'error'], true)) { // ARCA ya lo aprobó: no se pide otro CAE, se graba
                return $this->recuperar((int) $f['id']) + ['repetido' => true];
            }
            if ($f['estado'] === 'pendiente') {
                throw new ArcaSinRespuesta('Esta operación ya se mandó a ARCA y no hay respuesta registrada. No se vuelve a pedir para no duplicar el comprobante: '
                    . 'revisá Sistema → ARCA (comprobantes pendientes).');
            }
            $pdo->prepare("UPDATE afip_operaciones SET estado = 'pendiente', detalle = NULL WHERE id = ?")->execute([$f['id']]); // rechazada: se puede reintentar
        }
        $id = (int) $pdo->query('SELECT id FROM afip_operaciones WHERE operacion = ' . $pdo->quote($operacion))->fetchColumn();
        $marcar = function (string $estado, array $campos = []) use ($pdo, $id): void {
            $set = ['estado = ?'];
            $par = [$estado];
            foreach ($campos as $k => $v) {
                $set[] = "{$k} = ?";
                $par[] = $v;
            }
            $par[] = $id;
            $pdo->prepare('UPDATE afip_operaciones SET ' . implode(', ', $set) . ' WHERE id = ?')->execute($par);
        };
        try { // 2) ARCA
            $r = Arca::autorizar($cbte);
        } catch (AutorizacionRechazada $e) {
            $marcar('rechazada', ['detalle' => mb_substr($e->getMessage(), 0, 600)]);
            throw $e;
        } catch (ArcaSinRespuesta $e) {
            $marcar('pendiente', ['detalle' => mb_substr($e->getMessage(), 0, 600)]);
            throw $e;
        }
        // 3) aprobada: queda anotado el CAE antes de tocar los datos del comprobante
        $marcar('aprobada', ['numero' => $r['numero'], 'cae' => $r['cae'], 'vence' => $r['vence'], 'respuesta' => $json($r['respuesta'])]);
        Arca::registrar('operacion', 'aprobada', $json(['operacion' => $operacion, 'numero' => $r['numero'], 'cae' => $r['cae'], 'cbte' => $cbte]), '', 0.0);
        try { // 4) grabar
            $g = $this->grabar($cbte, $operacion, $usuarioId, $r);
        } catch (\Throwable $e) {
            $marcar('error', ['detalle' => mb_substr('Aprobado por ARCA y no se pudo grabar: ' . $e->getMessage(), 0, 600)]);
            \App\App::registrar($e);
            throw new GrabacionFallida('ARCA aprobó el comprobante (número ' . $r['numero'] . ', CAE ' . $r['cae'] . ') pero no se pudo grabar. Quedó registrado: '
                . 'recuperalo desde Sistema → ARCA (no hace falta volver a emitirlo).');
        }
        $marcar('grabada', ['subdiario_id' => $g['id']]);
        return $g;
    }

    /**
     * Graba un comprobante que ARCA ya aprobó y no se había podido grabar (afip_operaciones en estado aprobada o error).
     *
     * @return array{id:int,numero:int,repetido:bool}
     */
    public function recuperar(int $operacionId): array
    {
        $st = $this->pdo->prepare("SELECT * FROM afip_operaciones WHERE id = ? AND estado IN ('aprobada', 'error')");
        $st->execute([$operacionId]);
        $f = $st->fetch();
        if ($f === false || (string) $f['cae'] === '') {
            throw new \RuntimeException('Esa operación no está pendiente de grabar.');
        }
        $previo = $this->porOperacion((string) $f['operacion']);
        if ($previo !== null) { // ya estaba grabado
            $this->pdo->prepare("UPDATE afip_operaciones SET estado = 'grabada', subdiario_id = ? WHERE id = ?")->execute([$previo['id'], $operacionId]);
            return $previo + ['repetido' => true];
        }
        $cbte = json_decode((string) $f['solicitud'], true);
        $resp = json_decode((string) $f['respuesta'], true) ?: [];
        if (!is_array($cbte)) {
            throw new \RuntimeException('La solicitud guardada no se puede leer.');
        }
        $r = ['numero' => (int) $f['numero'], 'cae' => (string) $f['cae'], 'vence' => (string) $f['vence'], 'fecha' => (string) $cbte['fecha'],
            'observaciones' => (array) ($resp['observaciones'] ?? []), 'respuesta' => $resp];
        $g = $this->grabar($cbte, (string) $f['operacion'], (int) $f['usuario_id'], $r);
        $this->pdo->prepare("UPDATE afip_operaciones SET estado = 'grabada', subdiario_id = ?, detalle = NULL WHERE id = ?")->execute([$g['id'], $operacionId]);
        return $g + ['repetido' => false];
    }

    /**
     * Numera (con ARCA, o con la sucursal si $arca es null) y graba subdiario, iva, estadistica, caja y ctact en UNA transacción.
     *
     * @param array<string,mixed> $cbte
     * @param array{numero:int,cae:string,vence:string,fecha:string,observaciones:string[]}|null $arca
     * @return array{id:int,numero:int,repetido:bool}
     */
    private function grabar(array $cbte, string $operacion, int $usuarioId, ?array $arca): array
    {
        $tipo = $cbte['tipo'];
        $letra = $cbte['letra'];
        $col = Tipos::columnaNumerador($tipo, $letra);
        if ($col === null) {
            throw new \InvalidArgumentException('Comprobante no soportado.');
        }
        $c = $cbte['calculo'];
        $cli = $cbte['cliente'];
        $asoc = $cbte['asociado'];
        $ahora = date('Y-m-d H:i:s');
        $solo = !empty($cbte['solo_subdiario']); // factura (o nota) de un presupuesto: solo al subdiario
        $presupuestoId = !empty($cbte['presupuesto']) ? (int) $cbte['presupuesto'] : null;

        $this->pdo->beginTransaction();
        try {
            if ($presupuestoId !== null) { // el presupuesto queda bloqueado: dos usuarios no lo facturan a la vez
                $st = $this->pdo->prepare("SELECT 1 FROM subdiario WHERE fe_clave = ? AND tipo = 'PR' FOR UPDATE");
                $st->execute([$presupuestoId]);
                $libre = $this->pdo->prepare('SELECT 1 FROM subdiario WHERE presupuesto_id = ?');
                $libre->execute([$presupuestoId]);
                if ($st->fetchColumn() === false || $libre->fetchColumn() !== false) {
                    throw new PresupuestoYaFacturado('El presupuesto ya generó su factura.');
                }
            }
            // Numeración: la fila de la sucursal queda bloqueada hasta el commit (dos usuarios no toman el mismo número).
            $st = $this->pdo->prepare("SELECT `{$col}` FROM sucursales WHERE codigo = ? FOR UPDATE");
            $st->execute([$cbte['sucursal']]);
            $ultimo = $st->fetchColumn();
            if ($ultimo === false) {
                throw new \RuntimeException('La sucursal ya no existe.');
            }
            if ($arca !== null) { // con ARCA el número es el que dio ARCA; el último de la sucursal solo acompaña
                $numero = (int) $arca['numero'];
            } else {
                $numero = (int) $ultimo + 1;
                $existe = $this->pdo->prepare('SELECT 1 FROM subdiario WHERE tipo = ? AND letra = ? AND sucursal = ? AND comprobante = ?');
                while (true) { // como el VFP: si el número ya está usado en el subdiario, sigue con el próximo
                    $existe->execute([$tipo, $letra, $cbte['sucursal'], $numero]);
                    if ($existe->fetchColumn() === false) {
                        break;
                    }
                    $numero++;
                }
            }
            $this->pdo->prepare("UPDATE sucursales SET `{$col}` = GREATEST(`{$col}`, ?), actualizacion = ? WHERE codigo = ?")->execute([$numero, $ahora, $cbte['sucursal']]);

            $this->insertar('subdiario', [
                'fecha' => $cbte['fecha'], 'tipo' => $tipo, 'letra' => $letra, 'sucursal' => $cbte['sucursal'], 'prefijo' => $cbte['prefijo'],
                'comprobante' => $numero, 'cliente' => $cli['codigo'], 'bruto' => $c['bruto'], 'neto_grabado' => $c['neto_grabado'],
                'neto_exento' => $c['neto_exento'], 'iva_1' => $c['iva_total'], 'bonificacion' => $c['bonificacion'], 'neto' => $c['total'],
                'porcentaje_descuento' => $c['descuento'], 'observaciones' => $cbte['observaciones'] !== '' ? $cbte['observaciones'] : null,
                'canal' => self::CANAL, 'terminal' => 1, 'iva_cliente' => (int) $cli['iva'], 'cuit_cliente' => $cli['cuit'],
                'vendedor' => $cbte['vendedor'] ?? ($cli['vendedor'] ?: null), 'forma_pago' => $cbte['forma_pago'], 'cond_pago' => $cbte['cond_pago'],
                'orden_compra' => $cbte['orden_compra'] !== '' ? $cbte['orden_compra'] : null, 'actualizacion' => $ahora, 'nrointerno' => $numero,
                'tipoafipoys' => $cbte['tipo_afip'], 'fe_cemailenvio' => $cbte['email'],
                'fe_tipocbte_asoc' => $asoc !== null ? $asoc['tipo'] : '', 'fe_nrocbte_asoc' => $asoc !== null ? Tipos::numeroVisible($asoc) : 0,
                'fe_fecha_asoc' => $asoc !== null ? $asoc['fecha'] : null, 'cliente_razon_social' => $cli['razon_social'],
                'cliente_domicilio' => $cli['domicilio'], 'cliente_localidad' => $cli['localidad'],
                'creado_por' => $usuarioId > 0 ? $usuarioId : null, 'operacion' => $operacion,
                'fe_concepto' => (int) ($cbte['concepto'] ?? 1), 'fe_serv_desde' => $cbte['serv_desde'] ?? null, 'fe_serv_hasta' => $cbte['serv_hasta'] ?? null,
                'fe_vencimiento' => $cbte['vto_pago'] ?? null,
                'presupuesto_id' => $presupuestoId, 'solo_subdiario' => $solo ? 1 : 0, 'sin_iva' => !empty($cbte['sin_iva']) ? 1 : 0,
            ] + ($arca === null
                ? ['es_prueba' => Tipos::interno($tipo) ? 0 : 1, 'fe_entorno' => '']
                : ['electronica' => 1, 'fe_nrocbte' => $numero, 'fe_ccae' => $arca['cae'], 'fe_dfecha' => $arca['fecha'], 'fe_dvtocae' => $arca['vence'],
                   'fe_ntipoafip' => $cbte['tipo_afip'], 'fe_mobservaciones' => $arca['observaciones'] !== [] ? implode("\n", $arca['observaciones']) : null,
                   'es_prueba' => Arca::entorno() === 'P' ? 0 : 1, 'fe_entorno' => Arca::entorno()]));
            $id = (int) $this->pdo->lastInsertId();

            foreach ($c['iva'] as $g) {
                $this->insertar('iva', ['subdiario_id' => $id, 'tipo' => $tipo, 'letra' => $letra, 'sucursal' => $cbte['sucursal'], 'comprobante' => $numero,
                    'alicuota' => $g['alicuota'], 'monto' => $g['base'], 'importe' => $g['importe'], 'actualizacion' => $ahora]);
            }

            $costos = \App\Modules\Listados\Costos::unitarios($this->pdo, array_map(static fn (array $it) => (string) ($it['articulo'] ?? ''), $c['items']), $cbte['fecha']); // D-62
            foreach ($c['items'] as $i => $it) {
                $this->insertar('estadistica', [
                    'subdiario_id' => $id, 'fecha' => $cbte['fecha'], 'tipo' => $tipo, 'letra' => $letra, 'sucursal' => $cbte['sucursal'], 'prefijo' => $cbte['prefijo'],
                    'comprobante' => $numero, 'orden' => $i + 1, 'articulo' => $it['articulo'], 'codigo_de_barras' => $it['codigo_de_barras'],
                    'unidades' => $it['cantidad'], 'unidades_ori' => $it['cantidad'], 'lista' => 'E', 'precio' => $it['precio'],
                    'precio_siniva' => $it['precio_siniva'], 'porcentaje_descuento' => $c['descuento'], 'observaciones' => $it['descripcion'], 'texto_impresion' => ($it['texto'] ?? '') !== '' ? $it['texto'] : null,
                    'total_item' => $it['importe'], 'total_siniva' => $it['neto'], 'cliente' => $cli['codigo'], 'grabado' => $it['exento'] ? 0 : 1,
                    'por_iva' => $it['alicuota'], 'actualizacion' => $ahora, 'costo' => $costos[(string) ($it['articulo'] ?? '')] ?? 0,
                ]);
            }

            // Stock (si está activado): la factura descuenta y la nota de crédito repone, en esta misma transacción (D-19)
            // (el presupuesto y su contrapartida también mueven stock; la factura de un presupuesto no: ya lo movió el presupuesto)
            if (!$solo && in_array($tipo, ['FC', 'PR'], true)) {
                // Se vuelve a controlar adentro de la transacción con los saldos bloqueados: dos puestos que emiten a la vez lo último que queda no lo venden los dos
                // sin autorización. Si ARCA ya autorizó el comprobante no se puede abortar: se graba y queda asentado que no había autorización.
                $rs = new ReservasService($this->pdo);
                $falta = $rs->faltantes($c['items'], $cbte['reserva_token'] ?? null, true);
                if ($falta !== []) {
                    $autoriz = $cbte['stock_autorizado'] ?: ($cbte['stock_automatico'] ?? null); // un supervisor, o el permiso del que vende (D-55)
                    if ($arca === null && empty($autoriz)) {
                        throw new StockNoDisponible(implode("\n", array_map(static fn (array $f) => $f['mensaje'], $falta)));
                    }
                    $rs->registrar($falta, $usuarioId, $arca === null ? $autoriz : null, $id);
                }
                if (!empty($cbte['reserva_token'])) {
                    $rs->liberar((string) $cbte['reserva_token']);
                }
            }
            if (!$solo) {
                (new \App\Modules\Stock\StockService($this->pdo))->porComprobante($tipo, $id, $c['items'], $usuarioId > 0 ? $usuarioId : null);
            }

            // Cómo se paga: una fila de caja por cada forma de pago (la nota de crédito no mueve caja, como el VFP)
            $cuenta = 0.0;
            if (!Tipos::credito($tipo) && !$solo) {
                $ccCodigo = $this->codigoCuentaCorriente();
                foreach ($cbte['pagos'] as $pago) {
                    $esCuenta = (int) $pago['tipo'] === 6;
                    $cuenta += $esCuenta ? (float) $pago['importe'] : 0.0;
                    $this->insertar('caja', [
                        'subdiario_id' => $id, 'fecha' => $cbte['fecha'], 'tipo' => $tipo, 'letra' => $letra, 'canal' => self::CANAL, 'terminal' => 1, 'sucursal' => $cbte['sucursal'],
                        'prefijo' => $cbte['prefijo'], 'comprobante' => $numero, 'cliente' => $cli['codigo'],
                        'p_fpago' => $esCuenta ? $ccCodigo : $pago['codigo'], 'p_nro' => $pago['nro'] ?? null,
                        'p_fecvto' => $esCuenta ? $cbte['fecha'] : ($pago['vencimiento'] ?? null), 'p_banco' => (int) ($pago['banco'] ?? 0),
                        'p_importe' => $pago['importe'], 'a_pagar' => $pago['importe'], 'actualizacion' => $ahora,
                        'p_recibido' => $esCuenta ? null : ($pago['recibido'] ?? $pago['importe']), 'p_vuelto' => $pago['vuelto'] ?? 0,
                    ] + \App\Modules\Caja\CajaService::contexto());
                }
            }
            // Cuenta corriente: la factura, la nota de débito y el presupuesto generan deuda (solo la parte que quedó a cuenta); la nota de crédito, saldo a favor
            $aCuenta = Tipos::credito($tipo) ? ($cbte['forma_pago'] === 2 ? $c['total'] : 0.0) : $cuenta;
            if ($aCuenta > 0.004 && !$solo) {
                $this->insertar('ctact', [
                    'subdiario_id' => $id, 'cliente' => $cli['codigo'], 'fecha' => $cbte['fecha'], 'tipo' => $tipo, 'tico' => Tipos::tico($tipo), 'letra' => $letra,
                    'sucursal' => $cbte['sucursal'], 'prefijo' => $cbte['prefijo'], 'numero' => $numero, 'neto' => $aCuenta, 'descuento' => 0,
                    'saldo' => $aCuenta, 'vencimiento' => $cbte['fecha'], 'valor_dolar' => $this->cotizacionDolar($cbte['fecha']), 'actualizacion' => $ahora,
                ]);
            }
            $this->pdo->prepare('UPDATE clientes SET ultima_compra = ? WHERE codigo = ?')->execute([$cbte['fecha'], $cli['codigo']]);
            $this->pdo->commit();
        } catch (PresupuestoYaFacturado $e) {
            $this->pdo->rollBack();
            throw $e;
        } catch (\PDOException $e) {
            $this->pdo->rollBack();
            if ($e->getCode() === '23000' && str_contains($e->getMessage(), 'uq_subdiario_presupuesto')) {
                throw new PresupuestoYaFacturado('El presupuesto ya generó su factura.');
            }
            if ($e->getCode() === '23000' && str_contains($e->getMessage(), 'uq_subdiario_operacion')) {
                $previo = $this->porOperacion($operacion);
                if ($previo !== null) {
                    return $previo + ['repetido' => true];
                }
            }
            throw $e;
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
        return ['id' => $id, 'numero' => $numero, 'repetido' => false, 'vuelto' => (float) ($cbte['vuelto'] ?? 0)];
    }

    /** INSERT de una fila a partir de [columna => valor]; las columnas salen siempre del código. @param array<string,mixed> $fila */
    private function insertar(string $tabla, array $fila): void
    {
        $cols = array_keys($fila);
        $this->pdo->prepare("INSERT INTO `{$tabla}` (`" . implode('`, `', $cols) . '`) VALUES (' . implode(', ', array_fill(0, count($cols), '?')) . ')')
            ->execute(array_values($fila));
    }

    /** @return array{id:int,numero:int}|null */
    private function porOperacion(string $operacion): ?array
    {
        $st = $this->pdo->prepare('SELECT fe_clave, comprobante FROM subdiario WHERE operacion = ?');
        $st->execute([$operacion]);
        $r = $st->fetch();
        return $r === false ? null : ['id' => (int) $r['fe_clave'], 'numero' => (int) $r['comprobante']];
    }

    /** Código de la forma de pago "cuenta corriente" (tipo 6); 'CC' si no hay. */
    private function codigoCuentaCorriente(): string
    {
        $c = $this->pdo->query('SELECT codigo FROM forma_de_pagos WHERE tipo = 6 ORDER BY id LIMIT 1')->fetchColumn();
        return $c === false ? 'CC' : (string) $c;
    }

    /** Última cotización del dólar hasta la fecha (1 si no hay). */
    public function cotizacionDolar(string $fecha): float
    {
        $st = $this->pdo->prepare('SELECT importe FROM cotizacion_dolar WHERE fecha <= ? ORDER BY fecha DESC LIMIT 1');
        $st->execute([$fecha]);
        $v = $st->fetchColumn();
        return $v === false ? 1.0 : (float) $v;
    }

    // ------------------------------------------------------------------ consulta

    /** @return array<string,mixed>|null cabecera + renglones + IVA + pago + datos del cliente y de la sucursal */
    public function obtener(int $feClave): ?array
    {
        $st = $this->pdo->prepare('SELECT * FROM subdiario WHERE fe_clave = ?');
        $st->execute([$feClave]);
        $h = $st->fetch();
        if ($h === false) {
            return null;
        }
        $st = $this->pdo->prepare('SELECT * FROM estadistica WHERE subdiario_id = ? ORDER BY orden, fe_clave');
        $st->execute([$feClave]);
        $h['items'] = $st->fetchAll();
        $st = $this->pdo->prepare('SELECT * FROM iva WHERE subdiario_id = ? ORDER BY alicuota');
        $st->execute([$feClave]);
        $h['iva'] = $st->fetchAll();
        $st = $this->pdo->prepare('SELECT c.*, f.descripcion AS fpago_descripcion, f.tipo AS fpago_tipo FROM caja c LEFT JOIN forma_de_pagos f ON f.codigo = c.p_fpago
                                    WHERE c.subdiario_id = ? ORDER BY c.fe_clave');
        $st->execute([$feClave]);
        $h['pagos'] = $st->fetchAll();
        $st = $this->pdo->prepare('SELECT * FROM clientes WHERE codigo = ?');
        $st->execute([$h['cliente']]);
        $h['cliente_actual'] = $st->fetch() ?: null;
        $st = $this->pdo->prepare('SELECT * FROM sucursales WHERE codigo = ?');
        $st->execute([$h['sucursal']]);
        $h['sucursal_datos'] = $st->fetch() ?: null;
        $h['asociado_clave'] = null;
        if ($h['fe_nrocbte_asoc'] > 0) {
            $st = $this->pdo->prepare('SELECT fe_clave FROM subdiario WHERE tipo = ? AND letra = ? AND sucursal = ? AND ' . Tipos::sqlNumeroVisible() . ' = ? AND (? IS NULL OR fecha = ?) LIMIT 1');
            $st->execute([$h['fe_tipocbte_asoc'], $h['letra'], $h['sucursal'], $h['fe_nrocbte_asoc'], $h['fe_fecha_asoc'], $h['fe_fecha_asoc']]);
            $a = $st->fetchColumn();
            $h['asociado_clave'] = $a === false ? null : (int) $a;
        }
        $h['notas'] = Tipos::credito($h['tipo']) ? [] : $this->notasDe($h);
        // Presupuesto ↔ factura: de qué presupuesto salió la factura, o qué factura salió del presupuesto
        $h['presupuesto_origen'] = $h['factura_generada'] = null;
        $cols = 'fe_clave, tipo, letra, prefijo, comprobante, fe_nrocbte, fe_ccae, fecha, neto';
        if ((int) ($h['presupuesto_id'] ?? 0) > 0) {
            $st = $this->pdo->prepare("SELECT {$cols} FROM subdiario WHERE fe_clave = ?");
            $st->execute([$h['presupuesto_id']]);
            $h['presupuesto_origen'] = $st->fetch() ?: null;
        } elseif ($h['tipo'] === 'PR') {
            $st = $this->pdo->prepare("SELECT {$cols} FROM subdiario WHERE presupuesto_id = ?");
            $st->execute([$feClave]);
            $h['factura_generada'] = $st->fetch() ?: null;
        }
        // Cambios de mercadería: de qué comprobante es un cambio (CM) y qué cambios tiene un presupuesto o una factura
        $h['cambio_origen'] = null;
        $h['cambios'] = [];
        if ($h['tipo'] === 'CM' && (int) ($h['cambio_de'] ?? 0) > 0) {
            $st = $this->pdo->prepare("SELECT {$cols} FROM subdiario WHERE fe_clave = ?");
            $st->execute([$h['cambio_de']]);
            $h['cambio_origen'] = $st->fetch() ?: null;
        } elseif (in_array($h['tipo'], ['PR', 'FC'], true)) {
            $st = $this->pdo->prepare("SELECT {$cols} FROM subdiario WHERE cambio_de = ? AND estado = '' ORDER BY fecha, fe_clave");
            $st->execute([$feClave]);
            $h['cambios'] = $st->fetchAll();
        }
        return $h;
    }

    /** Notas de crédito y débito asociadas a un comprobante. @return array<int,array<string,mixed>> */
    private function notasDe(array $h): array
    {
        $st = $this->pdo->prepare(
            "SELECT fe_clave, tipo, letra, prefijo, comprobante, fe_nrocbte, fe_ccae, fecha, neto FROM subdiario
              WHERE tipo IN ('NC', 'ND', 'NX') AND estado = '' AND sucursal = ? AND letra = ? AND fe_tipocbte_asoc = ? AND fe_nrocbte_asoc = ?
                AND (fe_fecha_asoc IS NULL OR fe_fecha_asoc = ?) ORDER BY fecha, comprobante"
        );
        $st->execute([$h['sucursal'], $h['letra'], $h['tipo'], Tipos::numeroVisible($h), $h['fecha']]);
        return $st->fetchAll();
    }

    /** Cuánto queda por acreditar de una factura o nota de débito. */
    public function porAcreditar(array $h): float
    {
        return Calculadora::r2((float) $h['neto'] - $this->acreditado((int) $h['fe_clave']));
    }

    /**
     * @param array<string,mixed> $f desde, hasta (dd/mm/aaaa o aaaa-mm-dd), tipo, cliente (texto), numero, estado
     * @return array{filas:array<int,array<string,mixed>>,total:int,suma:float}
     */
    /** Columnas ordenables del listado de comprobantes (clave => SQL fijo). */
    public const ORDEN_LISTA = ['fecha' => 's.fecha', 'numero' => 's.tipo, s.letra, s.prefijo, s.comprobante', 'cliente' => 'razon_social',
        'pago' => 's.forma_pago', 'total' => 's.neto', 'estado' => 's.estado, s.es_prueba'];

    public function listar(array $f, int $pagina, int $porPagina): array
    {
        $w = [];
        $p = [];
        if (($d = Valida::fecha((string) ($f['desde'] ?? ''))) !== null) {
            $w[] = 's.fecha >= ?';
            $p[] = $d;
        }
        if (($d = Valida::fecha((string) ($f['hasta'] ?? ''))) !== null) {
            $w[] = 's.fecha <= ?';
            $p[] = $d;
        }
        if (Tipos::valido((string) ($f['tipo'] ?? ''))) {
            $w[] = 's.tipo = ?';
            $p[] = $f['tipo'];
        }
        $cli = trim((string) ($f['cliente'] ?? ''));
        if ($cli !== '') {
            $dig = preg_replace('/\D/', '', $cli) ?? '';
            $conds = ['COALESCE(s.cliente_razon_social, c.razon_social) LIKE ?'];
            $p[] = '%' . addcslashes($cli, '%_\\') . '%';
            if ($dig !== '') {
                $conds[] = 's.cliente = ?';
                $conds[] = 'CAST(s.cuit_cliente AS CHAR) LIKE ?';
                array_push($p, (int) $dig, '%' . $dig . '%');
            }
            $w[] = '(' . implode(' OR ', $conds) . ')';
        }
        $nro = preg_replace('/\D/', '', (string) ($f['numero'] ?? ''));
        if ($nro !== '') {
            $w[] = '(s.comprobante = ? OR s.fe_nrocbte = ?)';
            array_push($p, (int) $nro, (int) $nro);
        }
        if (($f['estado'] ?? '') === 'anulados') {
            $w[] = "s.estado <> ''";
        } elseif (($f['estado'] ?? '') !== 'todos') {
            $w[] = "s.estado = ''";
        }
        $where = $w === [] ? '' : ' WHERE ' . implode(' AND ', $w);
        $base = 'FROM subdiario s LEFT JOIN clientes c ON c.codigo = s.cliente' . $where;
        $st = $this->pdo->prepare("SELECT COUNT(*), COALESCE(SUM(IF(s.tipo IN ('NC', 'NX'), -s.neto, s.neto)), 0) {$base}");
        $st->execute($p);
        [$total, $suma] = $st->fetch(PDO::FETCH_NUM);
        $desde = max(0, ($pagina - 1) * $porPagina);
        $st = $this->pdo->prepare(
            "SELECT s.fe_clave, s.fecha, s.tipo, s.letra, s.prefijo, s.comprobante, s.fe_nrocbte, s.fe_ccae, s.cliente, s.neto, s.forma_pago, s.estado, s.electronica, s.fe_entorno, s.es_prueba,
                    COALESCE(s.cliente_razon_social, c.razon_social) AS razon_social {$base}
              ORDER BY " . \App\Core\Orden::sql(self::ORDEN_LISTA, (string) ($f['orden'] ?? ''), 's.fecha DESC, s.fe_clave DESC') . " LIMIT {$porPagina} OFFSET {$desde}"
        );
        $st->execute($p);
        return ['filas' => $st->fetchAll(), 'total' => (int) $total, 'suma' => Calculadora::r2((float) $suma)];
    }

    /**
     * Facturas y notas de débito del cliente a las que se les puede hacer una NC/ND (o, para la nota de crédito X, sus presupuestos).
     * @return array<int,array<string,mixed>>
     */
    public function asociables(int $cliente, string $para = 'NC'): array
    {
        $tipos = $para === 'NX' ? "'PR'" : "'FC', 'ND'";
        $st = $this->pdo->prepare(
            "SELECT fe_clave, tipo, letra, prefijo, comprobante, fe_nrocbte, fe_ccae, fecha, neto, sin_iva FROM subdiario
              WHERE cliente = ? AND tipo IN ({$tipos}) AND estado = '' ORDER BY fecha DESC, comprobante DESC LIMIT 200"
        );
        $st->execute([$cliente]);
        return $st->fetchAll();
    }
}
