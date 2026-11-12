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
    private const TIPOS_PAGO_CONTADO = [1, 2, 3, 5]; // efectivo, tickets, tarjeta, cheques
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
    public function armar(array $in, bool $puedeExcederCredito = false): array
    {
        $err = [];
        $tipo = strtoupper(trim((string) ($in['tipo'] ?? '')));
        if (!Tipos::valido($tipo)) {
            return ['ok' => false, 'errores' => ['tipo' => 'Elegí el tipo de comprobante.'], 'cbte' => null];
        }

        // Emisor
        $emisor = $this->emisor();
        if ($emisor['condicion'] === null || !Valida::cuit((string) ($emisor['cuit'] ?? ''))) {
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
            if ($condArca === null) {
                $err['cliente'] = 'No hay equivalencia de ARCA para la condición de IVA del cliente (código ' . (int) $cliente['iva']
                    . '). Corregí la condición del cliente o cargá la equivalencia.';
            }
        }

        // Asociado (notas de crédito y débito)
        $asoc = null;
        if ($tipo !== 'FC') {
            $asoc = $this->asociado((int) ($in['asociado'] ?? 0), $codCli);
            if (!is_array($asoc)) {
                $err['asociado'] = $asoc;
            }
        }

        // Sucursal: la de la factura asociada, o la elegida
        $sucs = $this->sucursales();
        $codSuc = is_array($asoc) ? (int) $asoc['sucursal'] : (int) ($in['sucursal'] ?? (count($sucs) === 1 ? array_key_first($sucs) : 0));
        $suc = $sucs[$codSuc] ?? null;
        if ($suc === null) {
            $err['sucursal'] = 'Elegí la sucursal (punto de venta). Si no hay ninguna, cargá el punto de venta en Tablas → Sucursales.';
        }
        // Con ARCA: la sucursal tiene que poder emitir electrónicamente y lo que se corrige tiene que tener CAE
        $electronica = $suc !== null && Arca::electronica($suc);
        if ($electronica && ($problema = Arca::problema($suc)) !== null) {
            $err['sucursal'] = $problema;
        }
        if ($electronica && is_array($asoc) && ((string) $asoc['fe_ccae'] === '' || (string) $asoc['fe_ccae'] === '.')) {
            $err['asociado'] = 'El comprobante que se corrige no tiene CAE (es interno o de prueba): ARCA no lo conoce, no se puede hacer una nota electrónica sobre él.';
        }

        // Letra
        $letra = '';
        if (is_array($asoc)) {
            $letra = (string) $asoc['letra'];
        } elseif ($emisor['condicion'] !== null && $condArca !== null) {
            $letra = Tipos::letra((string) $emisor['condicion'], $condArca);
        }
        if ($letra !== '' && $suc !== null && Tipos::columnaNumerador($tipo, $letra) === null) {
            $err['tipo'] = 'No se emiten ' . mb_strtolower(Tipos::NOMBRES[$tipo]) . ' ' . $letra . '.';
        }
        if (is_array($asoc) && $emisor['condicion'] !== null && $emisor['condicion'] !== 'RI' && $letra !== 'C') {
            $err['asociado'] = 'El comprobante asociado no es compatible con la condición de IVA de la empresa.';
        }

        // Concepto (ARCA): productos, servicios o ambos. Con servicios hace falta el período y el vencimiento del pago.
        // DECISIÓN D-11 (Claude, a confirmar por Hernán): el concepto por omisión sale de Configuración (el VFP mandaba siempre 1, productos).
        $concepto = (int) ($in['concepto'] ?? Configuracion::entero('comprobantes.concepto_defecto', 1));
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
        $formaPago = (int) ($in['forma_pago'] ?? 0);
        if (!in_array($formaPago, [1, 2], true)) {
            $err['forma_pago'] = 'Elegí contado o cuenta corriente.';
        }
        $pago = null;
        if ($formaPago === 1 && $tipo !== 'NC') {
            $pago = $this->validarPago($in, $err);
        }

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
            $calc = Calculadora::calcular($letra, $items, $desc);
            if ($calc['total'] <= 0) {
                $err['items'] = trim(($err['items'] ?? '') . "\nEl total del comprobante tiene que ser mayor que cero.");
            } elseif ($calc['total'] > Calculadora::MAX_IMPORTE) {
                $err['items'] = trim(($err['items'] ?? '') . "\nEl total es demasiado grande.");
            }
        }

        // DECISIÓN D-01 (Claude, a confirmar por Hernán): una nota de crédito no puede superar lo que queda por acreditar de la
        // factura. El VFP lo permitía. Se cambia en Configuración (comprobantes.nc_limite_factura) sin tocar el código.
        if ($calc !== null && $tipo === 'NC' && is_array($asoc) && !isset($err['items']) && Configuracion::activo('comprobantes.nc_limite_factura')) {
            $libre = Calculadora::r2((float) $asoc['neto'] - $this->acreditado((int) $asoc['fe_clave']));
            if ($calc['total'] > $libre + 0.004) {
                $err['items'] = 'La nota de crédito (' . plata($calc['total']) . ') supera lo que queda por acreditar de la factura (' . plata($libre) . ').';
            }
        }

        // DECISIÓN D-02 (Claude, a confirmar por Hernán): un cliente con límite de crédito 0 no tiene control de crédito. En el VFP
        // cada comprobante a cuenta corriente de un cliente con límite 0 pedía autorización de un supervisor. Se cambia en
        // Configuración (comprobantes.credito_cero_sin_limite): destildado, el límite 0 significa "no se le puede fiar".
        $excede = false;
        $controlaCredito = $cliente !== null && ((float) $cliente['credito'] > 0 || !Configuracion::activo('comprobantes.credito_cero_sin_limite'));
        if ($calc !== null && $cliente !== null && $formaPago === 2 && $tipo !== 'NC' && $controlaCredito) {
            $deuda = $this->deudaCliente($codCli);
            $excede = $deuda + $calc['total'] > (float) $cliente['credito'] + 0.004;
            if ($excede && !($puedeExcederCredito && ($in['excede_ok'] ?? '') === '1')) {
                $err['credito'] = 'El comprobante excede el crédito del cliente (límite ' . plata((float) $cliente['credito']) . ', deuda actual ' . plata($deuda)
                    . '). ' . ($puedeExcederCredito ? 'Tildá "Autorizo exceder el crédito" para emitirlo.' : 'Pedile a un administrador que lo autorice.');
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
            'cliente' => $cliente, 'cond_arca' => $condArca, 'forma_pago' => $formaPago, 'pago' => $pago,
            'vendedor' => $vendedor, 'cond_pago' => $condPago, 'descuento' => $desc, 'observaciones' => $obs, 'orden_compra' => $oc,
            'email' => $mails, 'asociado' => is_array($asoc) ? $asoc : null, 'calculo' => $calc, 'excede_credito' => $excede,
            'tipo_afip' => Tipos::tipoAfip($tipo, $letra), 'emisor' => $emisor, 'sucursal_datos' => $suc, 'electronica' => $electronica,
            'concepto' => $concepto, 'serv_desde' => $servDesde, 'serv_hasta' => $servHasta, 'vto_pago' => $vtoPago,
            'advertencias' => (new \App\Modules\Stock\StockService($this->pdo))->advertencias($tipo, $items),
        ]];
    }

    /** @return string|array<string,mixed> el comprobante asociado (con sucursal, letra, neto...) o el motivo por el que no sirve */
    private function asociado(int $feClave, int $cliente): string|array
    {
        if ($feClave <= 0) {
            return 'Elegí el comprobante que se corrige (factura o nota de débito del cliente).';
        }
        $st = $this->pdo->prepare('SELECT fe_clave, tipo, letra, sucursal, prefijo, comprobante, fe_nrocbte, fe_ccae, fecha, cliente, neto, estado FROM subdiario WHERE fe_clave = ?');
        $st->execute([$feClave]);
        $a = $st->fetch();
        if ($a === false) {
            return 'El comprobante asociado no existe.';
        }
        if ((int) $a['cliente'] !== $cliente) {
            return 'El comprobante asociado es de otro cliente.';
        }
        if (!in_array($a['tipo'], ['FC', 'ND'], true)) {
            return 'Solo se asocian facturas o notas de débito.';
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
              WHERE n.tipo = 'NC' AND n.estado = '' AND n.sucursal = a.sucursal AND n.letra = a.letra
                AND n.fe_tipocbte_asoc = a.tipo AND n.fe_nrocbte_asoc = " . Tipos::sqlNumeroVisible('a') . "
                AND (n.fe_fecha_asoc IS NULL OR n.fe_fecha_asoc = a.fecha)"
        );
        $st->execute([$feClaveAsociado]);
        return Calculadora::r2((float) $st->fetchColumn());
    }

    /**
     * DECISIÓN D-04 (Claude, a confirmar por Hernán): un solo medio de pago por comprobante (efectivo, tickets, tarjeta o cheque).
     * El VFP tenía una pantalla de pagos mixtos (pagos_factu_ped) y cheques de terceros: eso va con las cobranzas (etapa 4).
     *
     * @param array<string,string> $err @return array<string,mixed>|null
     */
    private function validarPago(array $in, array &$err): ?array
    {
        $cod = trim((string) ($in['fpago'] ?? ''));
        $st = $this->pdo->prepare('SELECT codigo, descripcion, tipo FROM forma_de_pagos WHERE codigo = ?');
        $st->execute([$cod]);
        $f = $st->fetch();
        if ($f === false || !in_array((int) $f['tipo'], self::TIPOS_PAGO_CONTADO, true)) {
            $err['fpago'] = 'Elegí cómo se paga (efectivo, tarjeta, cheque...).';
            return null;
        }
        $pago = ['codigo' => (string) $f['codigo'], 'descripcion' => (string) $f['descripcion'], 'tipo' => (int) $f['tipo'], 'nro' => null, 'banco' => 0, 'vencimiento' => null];
        if ((int) $f['tipo'] === 5) {
            $nro = trim((string) ($in['pago_nro'] ?? ''));
            $banco = (int) ($in['pago_banco'] ?? 0);
            $vto = Valida::fecha((string) ($in['pago_vto'] ?? ''));
            $existeBanco = $banco > 0 && $this->existe('bancos', 'codigo', (string) $banco);
            if ($nro === '' || mb_strlen($nro) > 20) {
                $err['pago_nro'] = 'Completá el número del cheque.';
            }
            if (!$existeBanco) {
                $err['pago_banco'] = 'Elegí el banco del cheque.';
            }
            if ($vto === null) {
                $err['pago_vto'] = 'Completá el vencimiento del cheque.';
            }
            $pago['nro'] = $nro;
            $pago['banco'] = $banco;
            $pago['vencimiento'] = $vto;
        }
        return $pago;
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
        \App\Modules\Licencia\Licencia::exigirParaEmitir(); // suscripción vigente (solo si la instalación la exige)
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

        $this->pdo->beginTransaction();
        try {
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
            ] + ($arca === null
                ? ['es_prueba' => 1, 'fe_entorno' => '']
                : ['electronica' => 1, 'fe_nrocbte' => $numero, 'fe_ccae' => $arca['cae'], 'fe_dfecha' => $arca['fecha'], 'fe_dvtocae' => $arca['vence'],
                   'fe_ntipoafip' => $cbte['tipo_afip'], 'fe_mobservaciones' => $arca['observaciones'] !== [] ? implode("\n", $arca['observaciones']) : null,
                   'es_prueba' => Arca::entorno() === 'P' ? 0 : 1, 'fe_entorno' => Arca::entorno()]));
            $id = (int) $this->pdo->lastInsertId();

            foreach ($c['iva'] as $g) {
                $this->insertar('iva', ['subdiario_id' => $id, 'tipo' => $tipo, 'letra' => $letra, 'sucursal' => $cbte['sucursal'], 'comprobante' => $numero,
                    'alicuota' => $g['alicuota'], 'monto' => $g['base'], 'importe' => $g['importe'], 'actualizacion' => $ahora]);
            }

            foreach ($c['items'] as $i => $it) {
                $this->insertar('estadistica', [
                    'subdiario_id' => $id, 'fecha' => $cbte['fecha'], 'tipo' => $tipo, 'letra' => $letra, 'sucursal' => $cbte['sucursal'], 'prefijo' => $cbte['prefijo'],
                    'comprobante' => $numero, 'orden' => $i + 1, 'articulo' => $it['articulo'], 'codigo_de_barras' => $it['codigo_de_barras'],
                    'unidades' => $it['cantidad'], 'unidades_ori' => $it['cantidad'], 'lista' => 'E', 'precio' => $it['precio'],
                    'precio_siniva' => $it['precio_siniva'], 'porcentaje_descuento' => $c['descuento'], 'observaciones' => $it['descripcion'], 'texto_impresion' => ($it['texto'] ?? '') !== '' ? $it['texto'] : null,
                    'total_item' => $it['importe'], 'total_siniva' => $it['neto'], 'cliente' => $cli['codigo'], 'grabado' => $it['exento'] ? 0 : 1,
                    'por_iva' => $it['alicuota'], 'actualizacion' => $ahora,
                ]);
            }

            // Stock (si está activado): la factura descuenta y la nota de crédito repone, en esta misma transacción (D-19)
            (new \App\Modules\Stock\StockService($this->pdo))->porComprobante($tipo, $id, $c['items'], $usuarioId > 0 ? $usuarioId : null);

            // Cómo se paga: la nota de crédito no mueve caja (como el VFP)
            if ($tipo !== 'NC') {
                $pago = $cbte['pago'];
                $this->insertar('caja', [
                    'subdiario_id' => $id, 'fecha' => $cbte['fecha'], 'tipo' => $tipo, 'letra' => $letra, 'canal' => self::CANAL, 'terminal' => 1, 'sucursal' => $cbte['sucursal'],
                    'prefijo' => $cbte['prefijo'], 'comprobante' => $numero, 'cliente' => $cli['codigo'],
                    'p_fpago' => $cbte['forma_pago'] === 2 ? $this->codigoCuentaCorriente() : $pago['codigo'], 'p_nro' => $pago['nro'] ?? null,
                    'p_fecvto' => $cbte['forma_pago'] === 2 ? $cbte['fecha'] : ($pago['vencimiento'] ?? null), 'p_banco' => (int) ($pago['banco'] ?? 0),
                    'p_importe' => $c['total'], 'a_pagar' => $c['total'], 'actualizacion' => $ahora,
                ]);
            }
            // Cuenta corriente: la factura y la nota de débito generan deuda; la nota de crédito, saldo a favor
            if ($cbte['forma_pago'] === 2) {
                $this->insertar('ctact', [
                    'subdiario_id' => $id, 'cliente' => $cli['codigo'], 'fecha' => $cbte['fecha'], 'tipo' => $tipo, 'tico' => Tipos::tico($tipo), 'letra' => $letra,
                    'sucursal' => $cbte['sucursal'], 'prefijo' => $cbte['prefijo'], 'numero' => $numero, 'neto' => $c['total'], 'descuento' => 0,
                    'saldo' => $c['total'], 'vencimiento' => $cbte['fecha'], 'valor_dolar' => $this->cotizacionDolar($cbte['fecha']), 'actualizacion' => $ahora,
                ]);
            }
            $this->pdo->prepare('UPDATE clientes SET ultima_compra = ? WHERE codigo = ?')->execute([$cbte['fecha'], $cli['codigo']]);
            $this->pdo->commit();
        } catch (\PDOException $e) {
            $this->pdo->rollBack();
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
        return ['id' => $id, 'numero' => $numero, 'repetido' => false];
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
        $st = $this->pdo->prepare('SELECT c.*, f.descripcion AS fpago_descripcion FROM caja c LEFT JOIN forma_de_pagos f ON f.codigo = c.p_fpago
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
        $h['notas'] = $h['tipo'] === 'NC' ? [] : $this->notasDe($h);
        return $h;
    }

    /** Notas de crédito y débito asociadas a un comprobante. @return array<int,array<string,mixed>> */
    private function notasDe(array $h): array
    {
        $st = $this->pdo->prepare(
            "SELECT fe_clave, tipo, letra, prefijo, comprobante, fe_nrocbte, fe_ccae, fecha, neto FROM subdiario
              WHERE tipo IN ('NC', 'ND') AND estado = '' AND sucursal = ? AND letra = ? AND fe_tipocbte_asoc = ? AND fe_nrocbte_asoc = ?
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
        $st = $this->pdo->prepare("SELECT COUNT(*), COALESCE(SUM(IF(s.tipo = 'NC', -s.neto, s.neto)), 0) {$base}");
        $st->execute($p);
        [$total, $suma] = $st->fetch(PDO::FETCH_NUM);
        $desde = max(0, ($pagina - 1) * $porPagina);
        $st = $this->pdo->prepare(
            "SELECT s.fe_clave, s.fecha, s.tipo, s.letra, s.prefijo, s.comprobante, s.fe_nrocbte, s.fe_ccae, s.cliente, s.neto, s.forma_pago, s.estado, s.electronica, s.fe_entorno, s.es_prueba,
                    COALESCE(s.cliente_razon_social, c.razon_social) AS razon_social {$base}
              ORDER BY s.fecha DESC, s.fe_clave DESC LIMIT {$porPagina} OFFSET {$desde}"
        );
        $st->execute($p);
        return ['filas' => $st->fetchAll(), 'total' => (int) $total, 'suma' => Calculadora::r2((float) $suma)];
    }

    /** Facturas y notas de débito del cliente a las que se les puede hacer una NC/ND. @return array<int,array<string,mixed>> */
    public function asociables(int $cliente): array
    {
        $st = $this->pdo->prepare(
            "SELECT fe_clave, tipo, letra, prefijo, comprobante, fe_nrocbte, fe_ccae, fecha, neto FROM subdiario
              WHERE cliente = ? AND tipo IN ('FC', 'ND') AND estado = '' ORDER BY fecha DESC, comprobante DESC LIMIT 200"
        );
        $st->execute([$cliente]);
        return $st->fetchAll();
    }
}
