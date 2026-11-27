<?php
declare(strict_types=1);

namespace App\Modules\FacturacionMasiva;

use App\Core\Db;
use App\Core\Valida;
use App\Modules\Comprobantes\ComprobantesService;
use App\Modules\Comprobantes\Tipos;
use App\Modules\Importacion\Destinos;
use PDO;

/**
 * Lee la planilla de la facturación masiva y arma, por cada factura, lo mismo que cargaría el formulario de la factura (la «entrada» de
 * ComprobantesService::armar()). No graba nada.
 *
 * DECISIÓN D-155 (Claude, a confirmar por Hernán): formato de la planilla.
 *  - Una fila por factura. Si una factura tiene varios renglones, las filas llevan la misma «Referencia» (cualquier texto: el número de pedido,
 *    el mes y el cliente…): se juntan en una sola factura y los datos de la factura (cliente, fecha, cobro…) se toman de la primera fila del grupo.
 *  - Las columnas se reconocen por el título (sin importar mayúsculas ni acentos) y pueden estar en cualquier orden. Obligatorias: cliente
 *    (código, CUIT o razón social exacta) y, en cada renglón, descripción o artículo. Sin cantidad va 1; sin precio, el de la ficha del artículo.
 *  - «Precio» es el precio final, con el IVA incluido (lo que cobra el cliente por unidad); quien tenga el precio sin IVA usa la columna
 *    «Precio neto». Se pasa a lo que pide la factura según la letra (la A va neta). Sin alícuota: la del artículo o 21 %.
 *  - Sin «Forma de pago» se usa la elegida al subir la planilla (por omisión, cuenta corriente). Sin fecha: la del día en que se aprueba.
 */
final class Planilla
{
    /** campo => [título para la planilla modelo, sinónimos normalizados con Destinos::normalizar] */
    public const CAMPOS = [
        'referencia' => ['Referencia', ['referencia', 'ref', 'grupo', 'pedido', 'nropedido', 'numerodepedido', 'idfactura', 'factura', 'agrupar']],
        'cliente' => ['Cliente', ['cliente', 'codigocliente', 'codcliente', 'codigodecliente', 'nrocliente', 'cuit', 'cuitcliente', 'cuitdelcliente', 'razonsocial', 'nombrecliente', 'cuitdni']],
        'fecha' => ['Fecha', ['fecha', 'fechafactura', 'fechadefactura', 'fechacomprobante', 'fechaemision']],
        'sucursal' => ['Punto de venta', ['puntodeventa', 'ptovta', 'pv', 'sucursal', 'puntoventa']],
        'concepto' => ['Concepto ARCA', ['conceptoarca', 'tipodeconcepto', 'productososervicios', 'productoservicio']],
        'serv_desde' => ['Servicio desde', ['serviciodesde', 'periododesde', 'desde']],
        'serv_hasta' => ['Servicio hasta', ['serviciohasta', 'periodohasta', 'hasta']],
        'vto_pago' => ['Vencimiento del pago', ['vencimientodelpago', 'vencimiento', 'vtopago', 'vto', 'fechavencimiento']],
        'articulo' => ['Artículo', ['articulo', 'codigoarticulo', 'codarticulo', 'codigodearticulo', 'producto', 'codigoproducto', 'sku', 'ean', 'codigodebarras']],
        'descripcion' => ['Descripción', ['descripcion', 'detalle', 'concepto', 'item', 'descripciondelitem']],
        'texto' => ['Texto adicional', ['textoadicional', 'textoextra', 'aclaracion', 'notarenglon']],
        'cantidad' => ['Cantidad', ['cantidad', 'cant', 'unidades', 'cantidades']],
        'precio' => ['Precio', ['precio', 'preciofinal', 'precioconiva', 'preciounitario', 'importe', 'monto', 'valor', 'preciounitarioconiva']],
        'precio_neto' => ['Precio neto', ['precioneto', 'preciosiniva', 'neto', 'importeneto', 'importesiniva', 'preciounitarioneto']],
        'alicuota' => ['IVA %', ['iva', 'alicuota', 'alicuotaiva', 'tasaiva', 'iva%', 'porcentajeiva']],
        'descuento' => ['Descuento %', ['descuento', 'bonificacion', 'desc', 'descuentogeneral']],
        'forma_pago' => ['Forma de pago', ['formadepago', 'formapago', 'pago', 'cobro', 'comosepaga', 'mediodepago', 'condicion']],
        'vendedor' => ['Vendedor', ['vendedor', 'codigovendedor', 'codvendedor']],
        'orden_compra' => ['Orden de compra', ['ordendecompra', 'oc', 'ordencompra']],
        'email' => ['Mail', ['mail', 'email', 'correo', 'mails', 'emails']],
        'observaciones' => ['Observaciones', ['observaciones', 'obs', 'observacion', 'comentario', 'comentarios']],
    ];

    /** Campos de la factura (los demás son del renglón): se toman de la primera fila del grupo. */
    private const CABECERA = ['cliente', 'fecha', 'sucursal', 'concepto', 'serv_desde', 'serv_hasta', 'vto_pago', 'descuento', 'forma_pago', 'vendedor', 'orden_compra', 'email', 'observaciones'];

    public const MAX_FACTURAS = 2000;

    private PDO $pdo;
    private ComprobantesService $comp;
    /** @var array<string,mixed> */
    private array $emisor;
    /** @var array<string,string> porcentaje ('21', '10.5') => código de alícuota */
    private array $alicPorPct = [];
    /** @var array<string,float> */
    private array $alic;

    public function __construct()
    {
        $this->pdo = Db::pdo();
        $this->comp = new ComprobantesService();
        $this->emisor = $this->comp->emisor();
        $this->alic = $this->comp->alicuotas();
        foreach ($this->alic as $cod => $pct) {
            $this->alicPorPct[self::pct($pct)] = $cod;
        }
    }

    /**
     * Columna de cada campo, por el título. @param string[] $titulos @return array<string,int>
     */
    public static function columnas(array $titulos): array
    {
        $norm = array_map(static fn ($t) => Destinos::normalizar((string) $t), $titulos);
        $out = [];
        $usadas = [];
        foreach (self::CAMPOS as $campo => [$titulo, $sin]) {
            foreach ([Destinos::normalizar($titulo), ...$sin] as $s) {
                $i = array_search($s, $norm, true);
                if ($i !== false && !isset($usadas[$i])) {
                    $out[$campo] = (int) $i;
                    $usadas[$i] = true;
                    break;
                }
            }
        }
        return $out;
    }

    /**
     * @param array{titulos:string[],filas:array<int,array{n:int,celdas:string[]}>} $hoja lo de Importacion\Lector::leer()
     * @param array<string,mixed> $porOmision 'cobro' => 'CC' o el código de la forma de pago; 'sucursal' => código
     * @return array{facturas:array<int,array{referencia:string,filas:int[],cliente:?int,cliente_texto:string,datos:array<string,mixed>,errores:string[]}>,columnas:array<string,int>}
     * @throws \RuntimeException si la planilla no sirve (sin columna de cliente o sin renglones)
     */
    public function leer(array $hoja, array $porOmision): array
    {
        $col = self::columnas($hoja['titulos']);
        if (!isset($col['cliente'])) {
            throw new \RuntimeException('La planilla no tiene la columna «Cliente» (código, CUIT o razón social). Bajá la planilla modelo para ver los títulos que se reconocen.');
        }
        if (!isset($col['descripcion']) && !isset($col['articulo'])) {
            throw new \RuntimeException('La planilla no tiene la columna «Descripción» ni «Artículo»: cada factura necesita al menos un renglón.');
        }
        $grupos = [];
        foreach ($hoja['filas'] as $f) {
            $v = static fn (string $c): string => isset($col[$c]) ? trim((string) ($f['celdas'][$col[$c]] ?? '')) : '';
            $ref = $v('referencia');
            $clave = $ref !== '' ? 'r:' . mb_strtolower($ref) : 'f:' . $f['n'];
            $grupos[$clave][] = ['n' => $f['n'], 'v' => array_map(static fn ($c) => $v($c), array_combine(array_keys(self::CAMPOS), array_keys(self::CAMPOS)))];
        }
        if (count($grupos) > self::MAX_FACTURAS) {
            throw new \RuntimeException('La planilla tiene ' . count($grupos) . ' facturas: el máximo por planilla es ' . self::MAX_FACTURAS . '. Partila en varias.');
        }
        $out = [];
        foreach ($grupos as $clave => $filas) {
            $out[] = $this->factura(str_starts_with($clave, 'r:') ? $filas[0]['v']['referencia'] : 'Fila ' . $filas[0]['n'], $filas, $porOmision);
        }
        return ['facturas' => $out, 'columnas' => $col];
    }

    /**
     * @param array<int,array{n:int,v:array<string,string>}> $filas @param array<string,mixed> $porOmision
     * @return array{referencia:string,filas:int[],cliente:?int,cliente_texto:string,datos:array<string,mixed>,errores:string[]}
     */
    private function factura(string $referencia, array $filas, array $porOmision): array
    {
        $err = [];
        $cab = $filas[0]['v'];
        foreach (array_slice($filas, 1) as $f) { // los datos de la factura van en la primera fila; si otra dice otra cosa, se avisa
            foreach (['cliente', 'fecha', 'forma_pago'] as $c) {
                if ($f['v'][$c] !== '' && $cab[$c] !== '' && mb_strtolower($f['v'][$c]) !== mb_strtolower($cab[$c])) {
                    $err[] = 'Fila ' . $f['n'] . ': la ' . mb_strtolower(self::CAMPOS[$c][0]) . ' («' . $f['v'][$c] . '») no es la de la primera fila de la referencia («' . $cab[$c] . '»).';
                }
            }
        }
        $datos = ['tipo' => 'FC'];
        $cliente = $this->cliente($cab['cliente'], $err);
        $datos['cliente'] = $cliente !== null ? (string) $cliente['codigo'] : '';
        $letra = '';
        if ($cliente !== null && $this->emisor['condicion'] !== null && ($cond = $this->comp->condicionArca((int) $cliente['iva'])) !== null) {
            $letra = Tipos::letra((string) $this->emisor['condicion'], $cond);
        }

        // Fecha y servicios (lo demás lo controla armar() al validar)
        foreach (['fecha', 'serv_desde', 'serv_hasta', 'vto_pago'] as $c) {
            if ($cab[$c] !== '') {
                $f = self::fecha($cab[$c]);
                if ($f === null) {
                    $err[] = self::CAMPOS[$c][0] . ': «' . $cab[$c] . '» no es una fecha (dd/mm/aaaa).';
                } else {
                    $datos[$c] = $f;
                }
            }
        }
        if ($cab['concepto'] !== '') {
            $k = Destinos::normalizar($cab['concepto']);
            $concepto = match (true) {
                in_array($k, ['1', 'productos', 'producto', 'p'], true) => '1',
                in_array($k, ['2', 'servicios', 'servicio', 's'], true) => '2',
                in_array($k, ['3', 'ambos', 'productosyservicios', 'productosservicios', 'ps'], true) => '3',
                default => null,
            };
            $concepto === null ? $err[] = 'Concepto ARCA: «' . $cab['concepto'] . '» no se entiende (productos, servicios o ambos).' : $datos['concepto'] = $concepto;
        } elseif (isset($datos['serv_desde']) || isset($datos['serv_hasta'])) {
            $datos['concepto'] = '2';
        }
        $datos['sucursal'] = $this->sucursal($cab['sucursal'], (string) ($porOmision['sucursal'] ?? ''), $err);

        // Cómo se cobra
        $cobro = $cab['forma_pago'] !== '' ? $this->cobro($cab['forma_pago']) : (string) ($porOmision['cobro'] ?? 'CC');
        if ($cobro === null) {
            $err[] = 'Forma de pago: «' . $cab['forma_pago'] . '» no existe (cuenta corriente o una forma de pago de Tablas → Formas de pago, salvo cheques).';
            $cobro = 'CC';
        }
        $datos += self::entradaCobro($cobro);

        if ($cab['descuento'] !== '') {
            $datos['descuento'] = str_replace('%', '', $cab['descuento']);
        }
        foreach (['vendedor', 'orden_compra', 'observaciones'] as $c) {
            if ($cab[$c] !== '') {
                $datos[$c] = $cab[$c];
            }
        }
        $datos['email'] = $cab['email'] !== '' ? $cab['email'] : (string) ($cliente['emailfc'] ?? '');

        // Renglones
        $items = [];
        foreach ($filas as $f) {
            $items[] = $this->renglon($f['n'], $f['v'], $letra, $err);
        }
        $datos['items'] = $items;
        return ['referencia' => mb_substr($referencia, 0, 60), 'filas' => array_map(static fn ($f) => (int) $f['n'], $filas), 'cliente' => $cliente !== null ? (int) $cliente['codigo'] : null,
            'cliente_texto' => mb_substr($cab['cliente'], 0, 120), 'datos' => $datos, 'errores' => $err];
    }

    /**
     * Lo que va en la entrada de armar() para cobrar de una forma: 'CC' (o una de tipo cuenta corriente) = todo a cuenta corriente; si no, una sola forma de pago por el total.
     * @return array<string,mixed>
     */
    public static function entradaCobro(string $cobro): array
    {
        return $cobro === 'CC' ? ['forma_pago' => '2'] : ['pagos' => [['fpago' => $cobro, 'importe' => '']]];
    }

    /** Código de la forma de pago (o 'CC' para cuenta corriente) a partir de lo que dice la planilla; null si no existe. */
    public function cobro(string $texto): ?string
    {
        $k = Destinos::normalizar($texto);
        if (in_array($k, ['cc', 'ctacte', 'cuentacorriente', 'ctacorriente', 'cuentacte', 'credito', 'acuenta', '2'], true)) {
            return 'CC';
        }
        foreach (self::formasDePago() as $f) {
            if ($k === Destinos::normalizar((string) $f['codigo']) || $k === Destinos::normalizar((string) $f['descripcion'])) {
                return (int) $f['tipo'] === 6 ? 'CC' : (string) $f['codigo'];
            }
        }
        if (in_array($k, ['contado', 'efectivo', '1'], true)) { // «contado» o «efectivo»: la primera forma de pago en efectivo
            foreach (self::formasDePago() as $f) {
                if ((int) $f['tipo'] === 1) {
                    return (string) $f['codigo'];
                }
            }
        }
        return null;
    }

    /**
     * Formas de pago que se pueden usar en la facturación masiva: efectivo, tickets, tarjeta, billeteras y cuenta corriente (los cheques piden número y banco
     * por cada uno: se cobran después con un recibo). @return array<int,array<string,mixed>>
     */
    public static function formasDePago(): array
    {
        return Db::pdo()->query('SELECT codigo, descripcion, tipo FROM forma_de_pagos WHERE tipo IN (1, 2, 3, 6, 10) ORDER BY tipo = 6 DESC, tipo, descripcion')->fetchAll();
    }

    /** Texto de cómo se cobra, para mostrar. @param array<string,mixed> $datos */
    public static function textoCobro(array $datos): string
    {
        if ((string) ($datos['forma_pago'] ?? '') === '2') {
            return 'Cuenta corriente';
        }
        $cod = (string) ($datos['pagos'][0]['fpago'] ?? '');
        foreach (self::formasDePago() as $f) {
            if ((string) $f['codigo'] === $cod) {
                return ucfirst(mb_strtolower((string) $f['descripcion']));
            }
        }
        return $cod;
    }

    /** Código de cobro guardado en la entrada ('CC' o el de la forma de pago). @param array<string,mixed> $datos */
    public static function codigoCobro(array $datos): string
    {
        return (string) ($datos['forma_pago'] ?? '') === '2' ? 'CC' : (string) ($datos['pagos'][0]['fpago'] ?? 'CC');
    }

    /** @param string[] $err @return array<string,mixed>|null */
    private function cliente(string $texto, array &$err): ?array
    {
        if ($texto === '') {
            $err[] = 'Falta el cliente.';
            return null;
        }
        $dig = preg_replace('/\D/', '', $texto) ?? '';
        $filas = [];
        if ($dig !== '' && strlen($dig) === 11 && preg_match('/^[\d\s.\-\/]+$/', $texto) === 1) { // CUIT
            $st = $this->pdo->prepare('SELECT * FROM clientes WHERE cuit = ? ORDER BY activo DESC, codigo');
            $st->execute([(int) $dig]);
            $filas = $st->fetchAll();
        } elseif ($dig !== '' && ctype_digit(str_replace(['.', ' '], '', $texto)) && strlen($dig) <= 10) { // código
            $st = $this->pdo->prepare('SELECT * FROM clientes WHERE codigo = ?');
            $st->execute([(int) $dig]);
            $filas = $st->fetchAll();
        } else { // razón social exacta
            $st = $this->pdo->prepare('SELECT * FROM clientes WHERE razon_social = ? ORDER BY activo DESC, codigo');
            $st->execute([$texto]);
            $filas = $st->fetchAll();
        }
        $activos = array_values(array_filter($filas, static fn ($c) => (int) $c['activo'] === 1));
        if ($filas === []) {
            $err[] = 'El cliente «' . $texto . '» no existe (se busca por código, CUIT o razón social exacta).';
            return null;
        }
        if (count($activos) > 1) {
            $err[] = 'Hay ' . count($activos) . ' clientes con «' . $texto . '» (códigos ' . implode(', ', array_map(static fn ($c) => $c['codigo'], $activos)) . '): elegí el que corresponde.';
            return null;
        }
        return $activos[0] ?? $filas[0]; // uno desactivado: armar() avisa
    }

    /** @param string[] $err */
    private function sucursal(string $texto, string $porOmision, array &$err): string
    {
        $sucs = $this->comp->sucursales();
        if ($texto === '') {
            return $porOmision !== '' ? $porOmision : (count($sucs) === 1 ? (string) array_key_first($sucs) : '');
        }
        $n = (int) preg_replace('/\D/', '', $texto);
        foreach ($sucs as $cod => $s) { // primero el punto de venta (lo que figura en la factura), después el código de la sucursal
            if ((int) $s['prefijo'] === $n) {
                return (string) $cod;
            }
        }
        if (isset($sucs[$n])) {
            return (string) $n;
        }
        $err[] = 'Punto de venta «' . $texto . '»: no hay ninguna sucursal con ese punto de venta.';
        return $porOmision;
    }

    /**
     * Un renglón de la planilla en el formato del formulario: el precio queda como lo pide la letra (A neto; B, C con IVA).
     * @param array<string,string> $v @param string[] $err @return array<string,string>
     */
    private function renglon(int $n, array $v, string $letra, array &$err): array
    {
        $r = ['articulo' => '', 'descripcion' => $v['descripcion'], 'texto' => $v['texto'], 'cantidad' => $v['cantidad'] !== '' ? $v['cantidad'] : '1', 'precio' => '', 'alicuota' => ''];
        $art = null;
        if ($v['articulo'] !== '') {
            $hallados = \App\Modules\Articulos\BuscadorArticulos::buscar($v['articulo'], true);
            if (count($hallados) === 1) {
                $art = $hallados[0];
                $r['articulo'] = (string) $art['codigo'];
            } else {
                $err[] = 'Fila ' . $n . ': el artículo «' . $v['articulo'] . '» ' . ($hallados === [] ? 'no existe.' : 'coincide con varios: poné el código exacto.');
                $r['articulo'] = $v['articulo'];
            }
        }
        // Alícuota: la de la planilla (21, 10,5, 21 %…), la del artículo o 21 %
        $cod = '';
        if ($v['alicuota'] !== '') {
            $pct = Valida::decimal(str_replace(['%', ' '], '', $v['alicuota']));
            if ($pct !== null && $pct > 0 && $pct < 1) {
                $pct *= 100; // 0,21 de una celda con formato de porcentaje
            }
            $cod = $pct !== null ? ($this->alicPorPct[self::pct($pct)] ?? '') : '';
            if ($cod === '') {
                $err[] = 'Fila ' . $n . ': la alícuota de IVA «' . $v['alicuota'] . '» no está en Tablas → Tasas de IVA.';
            }
        } elseif ($art !== null && isset($this->alic[(string) $art['alicuota']])) {
            $cod = (string) $art['alicuota'];
        } else {
            $cod = $this->alicPorPct['21'] ?? (string) array_key_first($this->alic);
        }
        $r['alicuota'] = $cod;
        $pctIva = $this->alic[$cod] ?? 0.0;
        // Precio: final (con IVA) o neto; se pasa a la forma de la letra. Sin precio, el del artículo (igual que al elegirlo en la factura).
        $final = $v['precio'] !== '' ? Valida::decimal($v['precio']) : null;
        $neto = $v['precio_neto'] !== '' ? Valida::decimal($v['precio_neto']) : null;
        if (($v['precio'] !== '' && $final === null) || ($v['precio_neto'] !== '' && $neto === null)) {
            $err[] = 'Fila ' . $n . ': el precio «' . ($v['precio'] !== '' ? $v['precio'] : $v['precio_neto']) . '» no es un número.';
        } elseif ($final !== null) {
            $r['precio'] = self::num($letra === 'A' ? $final / (1 + $pctIva / 100) : $final);
        } elseif ($neto !== null) {
            $r['precio'] = self::num($letra === 'A' || $letra === 'C' ? $neto : $neto * (1 + $pctIva / 100));
        } elseif ($art !== null) {
            $r['precio'] = (string) $art['precio'];
        } else {
            $err[] = 'Fila ' . $n . ': falta el precio.';
        }
        return $r;
    }

    private static function num(float $v): string
    {
        return rtrim(rtrim(number_format(round($v, 4), 4, ',', ''), '0'), ',');
    }

    private static function pct(float $p): string
    {
        return rtrim(rtrim(number_format($p, 2, '.', ''), '0'), '.');
    }

    /** dd/mm/aaaa, aaaa-mm-dd o dd-mm-aaaa (el Lector ya pasa las fechas del Excel a aaaa-mm-dd). */
    private static function fecha(string $v): ?string
    {
        $v = trim($v);
        if (preg_match('#^(\d{1,2})[-.](\d{1,2})[-.](\d{4})$#', $v, $m) === 1) {
            $v = $m[1] . '/' . $m[2] . '/' . $m[3];
        } elseif (preg_match('#^(\d{4}-\d{2}-\d{2})[ T]#', $v, $m) === 1) {
            $v = $m[1];
        } elseif (preg_match('#^(\d{1,2})/(\d{1,2})/(\d{2})$#', $v, $m) === 1) {
            $v = $m[1] . '/' . $m[2] . '/20' . $m[3];
        }
        return Valida::fecha($v);
    }
}
