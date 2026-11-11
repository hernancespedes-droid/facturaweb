<?php
declare(strict_types=1);

namespace App\Modules\Arca;

use App\App;
use App\Core\Config;
use App\Core\Db;

/**
 * Factura electrónica de ARCA con el módulo lib/afip_fe (WSAA + WSFEv1). Este es el único lugar que lo toca.
 *
 * Modos (config/local.php → 'afip' => ['modo' => ...]):
 *   interno       no se habla con ARCA: los comprobantes son internos, sin CAE (etapa 2). Es el modo por omisión.
 *   simulado      ARCA simulado dentro del sistema (sin certificado): sirve para probar todo el circuito. Sin validez fiscal.
 *   homologacion  ARCA de pruebas (certificado de homologación). Sin validez fiscal.
 *   produccion    ARCA real. Lo activa Hernán.
 * Aunque el modo no sea "interno", solo se pide CAE en las sucursales con "Factura electrónica" tildada (Tablas → Sucursales).
 *
 * El certificado, la clave y config_afip.php van FUERA de public_html: 'afip' => ['config' => '/ruta/afip_cliente/config_afip.php'].
 * La configuración dice cuit, produccion (tiene que coincidir con el modo), certificado, clave_privada y puntos_venta.
 */
final class Arca
{
    public const MODOS = ['interno', 'simulado', 'homologacion', 'produccion'];
    private const ENTORNO = ['interno' => '', 'simulado' => 'S', 'homologacion' => 'H', 'produccion' => 'P'];
    private const ETIQUETA = [
        'interno' => 'Modo interno: sin CAE de ARCA, sin validez fiscal',
        'simulado' => 'ARCA SIMULADO: el CAE es de mentira, sin validez fiscal',
        'homologacion' => 'ARCA HOMOLOGACIÓN (pruebas): el CAE no tiene validez fiscal',
        'produccion' => 'ARCA producción',
    ];

    private static ?\OysAfip\Afip $afip = null;

    public static function modo(): string
    {
        $m = strtolower(trim((string) Config::get('afip.modo', 'interno')));
        return in_array($m, self::MODOS, true) ? $m : 'interno';
    }

    /** '' interno, S simulado, H homologación, P producción. */
    public static function entorno(): string
    {
        return self::ENTORNO[self::modo()];
    }

    public static function etiqueta(): string
    {
        return self::ETIQUETA[self::modo()];
    }

    /** Si los comprobantes de la sucursal se mandan a ARCA. @param array<string,mixed> $sucursal */
    public static function electronica(array $sucursal): bool
    {
        return self::modo() !== 'interno' && (int) ($sucursal['fac_electronica'] ?? 0) === 1;
    }

    public static function archivoConfig(): string
    {
        return trim((string) Config::get('afip.config', ''));
    }

    /** Cuál es el problema que impide emitir con ARCA en esta sucursal, o null si se puede. @param array<string,mixed> $sucursal */
    public static function problema(array $sucursal): ?string
    {
        if (!self::electronica($sucursal)) {
            return null;
        }
        try {
            $cfg = self::configuracion();
        } catch (\RuntimeException $e) {
            return $e->getMessage();
        }
        $pv = (int) $sucursal['prefijo'];
        if (self::modo() !== 'simulado' && $pv > 0 && !in_array($pv, array_map('intval', (array) ($cfg['puntos_venta'] ?? [])), true)) {
            return "El punto de venta {$pv} no está habilitado en la configuración de ARCA de esta instalación (puntos_venta de config_afip.php).";
        }
        return null;
    }

    /** Configuración del módulo según el modo. @return array<string,mixed> */
    public static function configuracion(): array
    {
        require_once App::root() . '/lib/afip_fe/afip.php';
        $cuit = preg_replace('/\D/', '', (string) Db::pdo()->query('SELECT cuit FROM parametros WHERE id = 1')->fetchColumn()) ?? '';
        if (self::modo() === 'simulado') {
            return ArcaSimulador::configuracion($cuit);
        }
        $archivo = self::archivoConfig();
        if ($archivo === '' || !is_file($archivo)) {
            throw new \RuntimeException('Falta la configuración de ARCA: en config/local.php, afip → config tiene que apuntar al config_afip.php de este cliente (fuera de public_html).');
        }
        $cfg = (static fn () => require $archivo)();
        if (!is_array($cfg) || empty($cfg['cuit'])) {
            throw new \RuntimeException('El config_afip.php no devuelve una configuración válida (falta el CUIT).');
        }
        if (preg_replace('/\D/', '', (string) $cfg['cuit']) !== $cuit) {
            throw new \RuntimeException('El CUIT del config_afip.php (' . $cfg['cuit'] . ') no es el de la empresa cargada en Tablas → Parámetros.');
        }
        if (!empty($cfg['produccion']) !== (self::modo() === 'produccion')) {
            throw new \RuntimeException('El modo es "' . self::modo() . '" pero config_afip.php tiene produccion = ' . (!empty($cfg['produccion']) ? 'true' : 'false')
                . '. Tienen que coincidir (así no se mezclan pruebas con comprobantes reales).');
        }
        return $cfg;
    }

    /** El cliente del módulo, listo para usar (con el ticket de acceso guardado en la base). */
    public static function afip(): \OysAfip\Afip
    {
        if (self::$afip === null) {
            $cfg = self::configuracion();
            if (!isset($cfg['almacen_ta']) && empty($cfg['carpeta_ta'])) {
                $cfg['almacen_ta'] = new \OysAfip\AlmacenTaPdo(Db::pdo());
            }
            if (self::modo() === 'simulado') {
                ArcaSimulador::instalar(Db::pdo());
            }
            \OysAfip\Soap::$registro = static function ($url, $accion, $enviado, $recibido, $segundos): void {
                self::registrar((string) $url, (string) $accion, (string) $enviado, (string) $recibido, (float) $segundos);
            };
            try {
                self::$afip = new \OysAfip\Afip($cfg);
            } catch (\OysAfip\AfipException $e) {
                throw new \RuntimeException('No se pudo preparar la conexión con ARCA: ' . $e->getMessage());
            }
        }
        return self::$afip;
    }

    /** Para las pruebas: olvida el cliente. */
    public static function reiniciar(): void
    {
        self::$afip = null;
    }

    /**
     * Arma el comprobante del módulo a partir de lo que calculó Calculadora (que es la fuente oficial de los importes).
     *
     * @param array<string,mixed> $cbte el 'cbte' de ComprobantesService::armar()
     */
    public static function comprobante(array $cbte): \OysAfip\Comprobante
    {
        require_once App::root() . '/lib/afip_fe/afip.php';
        $k = $cbte['calculo'];
        $c = new \OysAfip\Comprobante();
        $c->tipo = (int) $cbte['tipo_afip'];
        $c->ptoVta = (int) $cbte['prefijo'];
        $c->fecha = (string) $cbte['fecha'];
        $c->concepto = (int) ($cbte['concepto'] ?? 1);
        if ($c->concepto > 1) {
            $c->servDesde = (string) $cbte['serv_desde'];
            $c->servHasta = (string) $cbte['serv_hasta'];
            $c->vtoPago = (string) $cbte['vto_pago'];
        }
        $c->receptor((string) ($cbte['cliente']['cuit'] ?? ''));
        $c->condicionIvaReceptor = (int) $cbte['cond_arca'];
        if (\OysAfip\Comprobante::esC($c->tipo)) {
            $c->impNeto = (float) $k['neto_grabado'];
        } else {
            foreach ($k['iva'] as $g) {
                $c->agregarIva((float) $g['alicuota'], (float) $g['base'], (float) $g['importe']);
            }
            $c->impNeto = (float) $k['neto_grabado'];
            $c->impOpEx = (float) $k['neto_exento'];
        }
        $c->totalizar();
        $c->impTotal = (float) $k['total'];
        if ($cbte['asociado'] !== null) {
            $a = $cbte['asociado'];
            $c->agregarAsociado(\App\Modules\Comprobantes\Tipos::tipoAfip((string) $a['tipo'], (string) $a['letra']), (int) $c->ptoVta,
                \App\Modules\Comprobantes\Tipos::numeroVisible($a), (string) $a['fecha'], (string) ($cbte['emisor']['cuit'] ?? ''));
        }
        return $c;
    }

    /**
     * Pide el CAE (toma el número de ARCA con un bloqueo por punto de venta y tipo, así dos usuarios no usan el mismo).
     *
     * @param array<string,mixed> $cbte
     * @return array{numero:int,cae:string,vence:string,fecha:string,observaciones:string[],respuesta:array<string,mixed>}
     * @throws AutorizacionRechazada ARCA contestó que no
     * @throws ArcaSinRespuesta no se pudo hablar con ARCA
     */
    public static function autorizar(array $cbte): array
    {
        @set_time_limit(180);
        try {
            $afip = self::afip();
            $c = self::comprobante($cbte);
            $r = $afip->autorizar($c, Db::pdo());
        } catch (\OysAfip\AfipConexionException $e) {
            throw new ArcaSinRespuesta('No hubo respuesta de ARCA: ' . $e->getMessage());
        } catch (\OysAfip\AfipException $e) {
            if ($e->errores !== []) { // ARCA contestó con un error (no con una observación del comprobante)
                $det = array_map(static fn ($x) => '(' . $x['codigo'] . ') ' . $x['mensaje'], $e->errores);
                throw new AutorizacionRechazada('ARCA no autorizó el comprobante: ' . implode(' | ', $det), $det);
            }
            throw new ArcaSinRespuesta('No se pudo pedir el CAE a ARCA: ' . $e->getMessage());
        } catch (\RuntimeException $e) {
            throw new ArcaSinRespuesta($e->getMessage());
        }
        if (empty($r['aprobado'])) {
            $det = $r['observaciones'];
            foreach ((array) $r['errores'] as $x) {
                $det[] = '(' . $x['codigo'] . ') ' . $x['mensaje'];
            }
            throw new AutorizacionRechazada('ARCA no autorizó el comprobante' . ($det !== [] ? ': ' . implode(' | ', $det) : '.'), $det);
        }
        $vence = (string) $r['vence'];
        return [
            'numero' => (int) $r['numero'], 'cae' => (string) $r['cae'],
            'vence' => preg_match('/^(\d{4})(\d{2})(\d{2})$/', $vence, $m) === 1 ? "{$m[1]}-{$m[2]}-{$m[3]}" : $vence,
            'fecha' => preg_match('/^(\d{4})(\d{2})(\d{2})$/', (string) $r['fecha'], $m) === 1 ? "{$m[1]}-{$m[2]}-{$m[3]}" : (string) $cbte['fecha'],
            'observaciones' => array_map('strval', (array) $r['observaciones']), 'respuesta' => $r,
        ];
    }

    // ------------------------------------------------------------------ registro

    /** Una línea JSON por cada intercambio con ARCA, en storage/logs/afip-AAAA-MM.log (sin token ni firma). */
    public static function registrar(string $url, string $accion, string $enviado, string $recibido, float $segundos): void
    {
        $dir = App::root() . '/storage/logs';
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        $linea = json_encode(['t' => date('c'), 'url' => $url, 'accion' => $accion, 'seg' => round($segundos, 2),
            'enviado' => self::sanear($enviado), 'recibido' => self::sanear($recibido)], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        @file_put_contents($dir . '/afip-' . date('Y-m') . '.log', $linea . "\n", FILE_APPEND);
    }

    /** Saca de un XML lo que no tiene que quedar escrito: token, firma y el pedido de acceso firmado. */
    public static function sanear(string $xml): string
    {
        $xml = preg_replace('#<(\w+:)?(Token|Sign|token|sign|in0|loginCmsReturn)>.*?</(\w+:)?\2>#s', '<$1$2>***</$1$2>', $xml) ?? $xml;
        return mb_substr($xml, 0, 6000);
    }

    // ------------------------------------------------------------------ diagnóstico

    /**
     * Revisa la conexión sin emitir nada (lo mismo que herramientas/probar_conexion.php), para mostrarlo en Sistema → ARCA.
     *
     * @return array<int,array{paso:string,ok:bool,detalle:string}>
     */
    public static function diagnostico(): array
    {
        $out = [];
        $paso = static function (string $titulo, callable $f) use (&$out): bool {
            try {
                $out[] = ['paso' => $titulo, 'ok' => true, 'detalle' => (string) $f()];
                return true;
            } catch (\Throwable $e) {
                $out[] = ['paso' => $titulo, 'ok' => false, 'detalle' => $e->getMessage()];
                return false;
            }
        };
        @set_time_limit(180);
        if (self::modo() === 'interno') {
            return [['paso' => 'Modo', 'ok' => false, 'detalle' => 'El modo es "interno": no se habla con ARCA. Cambiarlo en config/local.php (afip → modo).']];
        }
        $afip = null;
        if (!$paso('Configuración y certificado', static function () use (&$afip) {
            $afip = self::afip();
            $v = $afip->wsaa->venceCertificado();
            $dias = (int) floor(($v - time()) / 86400);
            if ($dias < 0) {
                throw new \RuntimeException('El certificado venció el ' . date('d/m/Y', $v) . '.');
            }
            return 'Vence el ' . date('d/m/Y', $v) . " ({$dias} días)" . ($dias < 30 ? ' — RENOVARLO PRONTO' : '');
        })) {
            return $out;
        }
        $paso('Servidores de ARCA (FEDummy)', static function () use ($afip) {
            $d = $afip->wsfe->dummy();
            return "app {$d['AppServer']} · db {$d['DbServer']} · auth {$d['AuthServer']}";
        });
        if (!$paso('Ticket de acceso (WSAA)', static function () use ($afip) {
            return 'Vence ' . date('d/m/Y H:i', (int) $afip->wsaa->credenciales('wsfe')['vence']);
        })) {
            return $out;
        }
        $pvs = [];
        $paso('Puntos de venta autorizados', static function () use ($afip, &$pvs) {
            $l = [];
            foreach ($afip->wsfe->parametros('PtosVenta') as $p) {
                $pvs[(int) $p['Nro']] = $p;
                $l[] = $p['Nro'] . ' (' . $p['EmisionTipo'] . ($p['Bloqueado'] === 'S' ? ', BLOQUEADO' : '') . ')';
            }
            return $l === [] ? 'ARCA no devolvió puntos de venta: hay que dar de alta uno "RECE para aplicativo y web services".' : implode(' · ', $l);
        });
        foreach (Db::pdo()->query('SELECT codigo, descripcion, prefijo, fac_electronica FROM sucursales WHERE prefijo > 0 AND fac_electronica = 1 ORDER BY codigo')->fetchAll() as $s) {
            $paso('Sucursal ' . $s['codigo'] . ' (' . $s['descripcion'] . ') · PV ' . sprintf('%04d', $s['prefijo']), static function () use ($afip, $s, $pvs) {
                if (self::modo() !== 'simulado' && !isset($pvs[(int) $s['prefijo']])) {
                    throw new \RuntimeException('Ese punto de venta no está entre los de ARCA.');
                }
                $txt = [];
                foreach ([1 => 'FC A', 6 => 'FC B', 11 => 'FC C', 3 => 'NC A', 8 => 'NC B', 13 => 'NC C'] as $tipo => $n) {
                    $txt[] = $n . ' ' . $afip->wsfe->ultimoAutorizado((int) $s['prefijo'], $tipo);
                }
                return 'Último autorizado: ' . implode(' · ', $txt);
            });
        }
        $paso('Condiciones de IVA del receptor', static function () use ($afip) {
            $ids = [];
            foreach ($afip->wsfe->condicionesIvaReceptor() as $p) {
                $ids[(int) $p['Id']] = $p['Id'] . ' ' . $p['Desc'];
            }
            $faltan = [];
            foreach (Db::pdo()->query("SELECT DISTINCT codigoafip FROM equivalencias_afip WHERE tabla = 'Equivaliencia'")->fetchAll(\PDO::FETCH_COLUMN) as $id) {
                if ($ids !== [] && !isset($ids[(int) $id])) {
                    $faltan[] = $id;
                }
            }
            if ($faltan !== []) {
                throw new \RuntimeException('Las equivalencias usan Ids que ARCA no tiene: ' . implode(', ', $faltan));
            }
            return count($ids) . ' condiciones; las equivalencias cargadas son válidas.';
        });
        return $out;
    }
}
