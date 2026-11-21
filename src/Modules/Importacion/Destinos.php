<?php
declare(strict_types=1);

namespace App\Modules\Importacion;

use App\Modules\Articulos\ArticulosService;
use App\Modules\Clientes\ClientesService;

/**
 * Qué se puede importar y qué campos tiene cada destino. Los campos de clientes y artículos son los mismos de sus pantallas
 * (ClientesService::campos(), ArticulosService::campos()): las reglas de validación son las mismas que al cargar a mano.
 * 'sinonimos' son los títulos de columna que se reconocen solos (en minúscula, sin acentos ni signos): el usuario siempre puede cambiar la elección.
 */
final class Destinos
{
    public const TITULOS = [
        'clientes' => 'Clientes',
        'articulos' => 'Artículos y precios',
        'stock' => 'Stock (inicial o conteo)',
        'subdiario' => 'Comprobantes históricos (subdiario de ventas)',
    ];
    public const AYUDA = [
        'clientes' => 'Alta de clientes nuevos y actualización de los que ya están (por código o, si no hay código, por CUIT). Solo se cambian las columnas que elijas.',
        'articulos' => 'Alta de artículos y actualización por código: sirve también para actualizar solo los precios desde una planilla.',
        'stock' => 'Deja el stock de cada artículo en la cantidad del archivo (stock inicial o conteo) o la suma a lo que hay.',
        'subdiario' => 'Facturas, notas de crédito y de débito ya emitidas en otro sistema (por ejemplo, la planilla «Mis comprobantes» de ARCA). Van al subdiario de IVA y, si tienen saldo, a la cuenta corriente. No se piden a ARCA de nuevo.',
    ];
    /** Permiso de cada destino además de importar.datos. */
    public const PERMISO = ['clientes' => 'clientes.editar', 'articulos' => 'articulos.editar', 'stock' => 'stock.mover', 'subdiario' => 'comprobantes.emitir'];

    /** Alícuotas de las columnas de IVA de los comprobantes (campo => %). */
    public const ALICUOTAS = ['iva_21' => 21.0, 'iva_105' => 10.5, 'iva_27' => 27.0, 'iva_5' => 5.0, 'iva_25' => 2.5];

    public static function existe(string $destino): bool
    {
        return isset(self::TITULOS[$destino]);
    }

    /**
     * @return array<string,array<string,mixed>> campo => label, tipo, requerido (para un alta), clave, ayuda, sinonimos y lo que use el Validador
     */
    public static function campos(string $destino): array
    {
        return match ($destino) {
            'clientes' => self::clientes(),
            'articulos' => self::articulos(),
            'stock' => self::stock(),
            'subdiario' => self::subdiario(),
            default => throw new \InvalidArgumentException('Destino desconocido.'),
        };
    }

    /** Normaliza un título para compararlo con los sinónimos: minúsculas, sin acentos ni signos ("Razón Social" => "razonsocial"). */
    public static function normalizar(string $t): string
    {
        $t = mb_strtolower(trim($t));
        $t = strtr($t, ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n', 'º' => '', '°' => '']);
        $t = str_replace(['%', ',5'], ['', '5'], $t); // "IVA 10,5 %" => "iva105"
        return preg_replace('/[^a-z0-9]/', '', $t) ?? '';
    }

    /**
     * Elige sola la columna de cada campo por el título (exacto con un sinónimo o con el nombre del campo). Cada columna se usa una vez.
     *
     * @param string[] $titulos
     * @return array<string,int> campo => índice de columna
     */
    public static function adivinar(string $destino, array $titulos): array
    {
        $norm = array_map([self::class, 'normalizar'], $titulos);
        $usadas = [];
        $out = [];
        foreach (self::campos($destino) as $campo => $c) {
            $buscar = array_unique([self::normalizar($campo), self::normalizar((string) $c['label']), ...($c['sinonimos'] ?? [])]);
            foreach ($buscar as $s) {
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

    // ------------------------------------------------------------------ definiciones

    private static function clientes(): array
    {
        $sin = [
            'razon_social' => ['razonsocial', 'nombre', 'nombreyapellido', 'apellidoynombre', 'denominacion', 'nombrecliente', 'cliente'],
            'nombre_fantasia' => ['fantasia', 'nombrefantasia', 'nombredefantasia', 'nombrecomercial'],
            'iva' => ['iva', 'condicioniva', 'condiva', 'condiciondeiva', 'condicionfrenteaiva', 'situacioniva', 'categoriaiva', 'tipoiva', 'responsabilidad'],
            'cuit' => ['cuit', 'cuil', 'cuitcuil', 'nrocuit', 'nrodecuit', 'nrodocumento', 'documento'],
            'domicilio' => ['domicilio', 'direccion', 'calle', 'domiciliofiscal'],
            'localidad' => ['localidad', 'ciudad', 'partido'],
            'codigo_postal' => ['cp', 'codigopostal', 'codpostal'],
            'provincia' => ['provincia', 'prov'],
            'telefono' => ['telefono', 'tel', 'telefonos', 'celular'],
            'fax' => ['fax', 'telefono2', 'otrotelefono'],
            'emailfc' => ['email', 'mail', 'correo', 'correoelectronico', 'emailfacturas', 'mailfacturas'],
            'emaillis' => ['emailresumenes', 'mailresumenes', 'emaillistados'],
            'vendedor' => ['vendedor', 'codvendedor'],
            'forma_pago' => ['formadepago', 'formapago'],
            'condicionpago' => ['condiciondepago', 'condicionpago', 'condpago'],
            'transporte' => ['transporte', 'expreso'],
            'lista_habitual' => ['lista', 'listadeprecios', 'listaprecios'],
            'descuento' => ['descuento', 'dto', 'bonificacion'],
            'credito' => ['credito', 'limitecredito', 'limitedecredito'],
            'observaciones' => ['observaciones', 'obs', 'notas', 'comentarios'],
        ];
        $out = ['codigo' => ['label' => 'Código de cliente', 'tipo' => 'entero', 'min' => 1, 'max' => 99999999, 'clave' => true,
            'ayuda' => 'Si no lo elegís (o viene vacío) el código se asigna solo. Con código, el que ya existe se actualiza.',
            'sinonimos' => ['codigo', 'cod', 'codcliente', 'codigocliente', 'codigodecliente', 'nrocliente', 'numerocliente', 'idcliente', 'id']]];
        foreach (ClientesService::campos() as $k => $c) {
            $out[$k] = $c + ['sinonimos' => $sin[$k] ?? []];
        }
        $out['iva']['ayuda'] = 'Código (1 Inscripto, 3 Consumidor final, 4 Exento, 7 Monotributo…) o el texto («Responsable inscripto», «Monotributista», «CF»…). Vacío = consumidor final.';
        $out['iva']['requerido'] = false; // vacío = consumidor final (se avisa)
        $out['forma_pago']['requerido'] = false; // vacío = cuenta corriente
        $out['provincia']['ayuda'] = 'Código o nombre de la provincia (Tablas → Provincias).';
        return $out;
    }

    private static function articulos(): array
    {
        $sin = [
            'descripcion' => ['descripcion', 'nombre', 'producto', 'detalle', 'descripciondelarticulo', 'nombredelproducto'],
            'rubro' => ['rubro', 'categoria', 'familia'],
            'subrubro' => ['subrubro', 'subcategoria', 'subfamilia'],
            'unidad_venta' => ['unidad', 'unidaddeventa', 'unidadventa', 'um'],
            'ean13' => ['ean', 'ean13', 'codigodebarras', 'codigobarras', 'codbarras', 'barcode', 'gtin'],
            'dun14' => ['dun', 'dun14'],
            'sku' => ['sku'],
            'controla_stock' => ['controlastock', 'stock'],
            'unidades_por_bulto' => ['unidadesporbulto', 'xbulto', 'uxb', 'bulto'],
            'estado' => ['estado'],
            'alicuota' => ['alicuota', 'iva', 'alicuotaiva', 'tasaiva', 'poriva'],
            'moneda' => ['moneda'],
            'costo' => ['costo', 'preciocosto', 'preciodecosto', 'costounitario'],
            'margen_l1' => ['margen', 'margen1', 'margenlista1', 'utilidad', 'markup'],
            'margen_l2' => ['margen2', 'margenlista2'],
            'margen_l3' => ['margen3', 'margenlista3'],
            'margen_oferta' => ['margenoferta'],
            'precio_automatico' => ['precioautomatico', 'automatico'],
            'precio_unitario' => ['preciounitario'],
            'precio_de_venta_1' => ['precio', 'precioventa', 'preciodeventa', 'preciolista1', 'lista1', 'pventa', 'preciopublico', 'preciofinal'],
            'precio_de_venta_2' => ['preciolista2', 'lista2', 'preciomayorista'],
            'precio_de_venta_3' => ['preciolista3', 'lista3'],
            'precio_de_oferta' => ['oferta', 'preciooferta', 'preciodeoferta'],
            'proveedor' => ['proveedor', 'codproveedor'],
            'stock_minimo_unidades' => ['stockminimo', 'minimo'],
        ];
        $out = ['codigo' => ['label' => 'Código de artículo', 'tipo' => 'texto', 'largo' => 15, 'requerido' => true, 'clave' => true,
            'ayuda' => 'Hasta 15 caracteres (se pasa a mayúsculas). Si ya existe, el artículo se actualiza.',
            'sinonimos' => ['codigo', 'cod', 'codarticulo', 'codigoarticulo', 'codigodearticulo', 'codigointerno', 'codproducto', 'codigoproducto', 'idproducto']]];
        foreach (ArticulosService::campos() as $k => $c) {
            $out[$k] = $c + ['sinonimos' => $sin[$k] ?? []];
        }
        foreach (['alicuota', 'estado', 'moneda'] as $k) {
            $out[$k]['requerido'] = false; // vacío = 21 %, activo, pesos
        }
        $out['alicuota']['ayuda'] = 'El porcentaje (21, 10,5, 27, 0…) o el código de Tablas → Tasas de IVA. Vacío = 21 %.';
        $out['rubro']['ayuda'] = 'Código o nombre del rubro (Tablas → Rubros).';
        $out['estado']['ayuda'] = 'AC o BA (también «Activo» / «Baja»). Vacío = activo.';
        return $out;
    }

    private static function stock(): array
    {
        return [
            'articulo' => ['label' => 'Artículo', 'tipo' => 'texto', 'largo' => 40, 'requerido' => true, 'ayuda' => 'Código, código de barras (EAN/DUN) o SKU.',
                'sinonimos' => ['articulo', 'codigo', 'cod', 'codarticulo', 'codigoarticulo', 'ean', 'ean13', 'sku', 'codigodebarras', 'codbarras']],
            'cantidad' => ['label' => 'Cantidad (unidades)', 'tipo' => 'decimal', 'requerido' => true,
                'sinonimos' => ['cantidad', 'stock', 'existencia', 'saldo', 'unidades', 'cant', 'stockactual']],
            'deposito' => ['label' => 'Depósito', 'tipo' => 'texto', 'largo' => 60, 'ayuda' => 'Código o nombre del depósito. Vacío = el que elijas abajo.',
                'sinonimos' => ['deposito', 'almacen', 'coddeposito']],
        ];
    }

    private static function subdiario(): array
    {
        $d = static fn (string $label, array $sin, string $ayuda = '') => ['label' => $label, 'tipo' => 'importe', 'sinonimos' => $sin] + ($ayuda !== '' ? ['ayuda' => $ayuda] : []);
        return [
            'fecha' => ['label' => 'Fecha', 'tipo' => 'fecha', 'requerido' => true, 'sinonimos' => ['fecha', 'fechaemision', 'fechadeemision', 'fechacomprobante', 'fechacbte']],
            'tipo' => ['label' => 'Tipo de comprobante', 'tipo' => 'texto', 'requerido' => true,
                'ayuda' => 'FC / NC / ND, «Factura», «Nota de crédito»… o el código de ARCA («1 - Factura A»). Si trae la letra («Factura A») no hace falta la columna Letra.',
                'sinonimos' => ['tipo', 'tipocomprobante', 'tipodecomprobante', 'tipocbte', 'comprobante']],
            'letra' => ['label' => 'Letra', 'tipo' => 'texto', 'sinonimos' => ['letra', 'clase']],
            'punto_venta' => ['label' => 'Punto de venta', 'tipo' => 'entero', 'ayuda' => 'Si el número viene como 0004-00001234 no hace falta.',
                'sinonimos' => ['puntodeventa', 'puntoventa', 'pv', 'ptovta', 'ptodevta', 'prefijo']],
            'numero' => ['label' => 'Número', 'tipo' => 'texto', 'requerido' => true,
                'sinonimos' => ['numero', 'nro', 'nrocomprobante', 'numerocomprobante', 'numerodecomprobante', 'nrocbte', 'numerodesde']],
            'cliente' => ['label' => 'Código de cliente', 'tipo' => 'entero', 'ayuda' => 'Si no hay código se busca el cliente por CUIT y después por razón social.',
                'sinonimos' => ['codcliente', 'codigocliente', 'codigodecliente', 'nrocliente']],
            'cuit' => ['label' => 'CUIT del cliente', 'tipo' => 'texto', 'sinonimos' => ['cuit', 'cuitcliente', 'nrodocreceptor', 'nrodoc', 'documento', 'cuitcuil']],
            'razon_social' => ['label' => 'Razón social del cliente', 'tipo' => 'texto', 'sinonimos' => ['razonsocial', 'denominacionreceptor', 'cliente', 'nombre', 'denominacion']],
            'neto_gravado' => $d('Neto gravado', ['netogravado', 'gravado', 'neto', 'impnetogravado', 'importenetogravado', 'subtotal']),
            'iva_total' => $d('IVA (total, sin separar por alícuota)', ['iva', 'ivatotal', 'totaliva', 'impiva'],
                'Si el archivo trae un solo IVA, la alícuota sale de dividirlo por el neto gravado (21, 10,5, 27, 5 o 2,5 %).'),
            'iva_21' => $d('IVA 21 %', ['iva21']),
            'iva_105' => $d('IVA 10,5 %', ['iva105']),
            'iva_27' => $d('IVA 27 %', ['iva27']),
            'iva_5' => $d('IVA 5 %', ['iva5']),
            'iva_25' => $d('IVA 2,5 %', ['iva25']),
            'exento' => $d('Exento', ['exento', 'impopexentas', 'importeexento', 'netoexento', 'operacionesexentas']),
            'no_gravado' => $d('No gravado', ['nogravado', 'impnetonogravado', 'netonogravado', 'importenogravado']),
            'otros_tributos' => $d('Otros tributos / percepciones', ['otrostributos', 'percepciones', 'percepcion', 'impuestosinternos']),
            'total' => $d('Total', ['total', 'imptotal', 'importetotal', 'totalcomprobante'], 'Si lo elegís, se controla que cierre con las otras columnas.'),
            'saldo' => $d('Saldo pendiente (cuenta corriente)', ['saldo', 'saldopendiente', 'pendiente', 'adeudado', 'debe', 'saldoadeudado'],
                'Lo que el cliente todavía debe de ese comprobante (o, en una nota de crédito, lo que le queda a favor). Va a su cuenta corriente.'),
            'cae' => ['label' => 'CAE', 'tipo' => 'texto', 'sinonimos' => ['cae', 'codautorizacion', 'codigodeautorizacion', 'codigoautorizacion', 'caecai']],
            'vto_cae' => ['label' => 'Vencimiento del CAE', 'tipo' => 'fecha', 'sinonimos' => ['vtocae', 'vencimientocae', 'fechavtocae', 'vencimientodelcae']],
            'observaciones' => ['label' => 'Observaciones', 'tipo' => 'texto', 'largo' => 255, 'sinonimos' => ['observaciones', 'obs', 'detalle']],
        ];
    }
}
