<?php
namespace OysAfip;

/** Código QR de ARCA (RG 4892): devuelve la URL que va dentro del QR. */
class QrAfip
{
    /**
     * @param string $cuitEmisor
     * @param array  $d fecha (Y-m-d o Ymd), ptoVta, tipo, numero, importe, moneda, cotizacion, docTipo, docNro, cae
     */
    public static function url($cuitEmisor, array $d)
    {
        $f = (string) $d['fecha'];
        if (preg_match('/^(\d{4})(\d{2})(\d{2})$/', $f, $m)) {
            $f = $m[1] . '-' . $m[2] . '-' . $m[3];
        } else {
            $f = date('Y-m-d', strtotime($f));
        }
        $datos = array(
            'ver' => 1,
            'fecha' => $f,
            'cuit' => (int) preg_replace('/\D/', '', $cuitEmisor),
            'ptoVta' => (int) $d['ptoVta'],
            'tipoCmp' => (int) $d['tipo'],
            'nroCmp' => (int) $d['numero'],
            'importe' => round((float) $d['importe'], 2),
            'moneda' => isset($d['moneda']) ? $d['moneda'] : 'PES',
            'ctz' => isset($d['cotizacion']) ? (float) $d['cotizacion'] : 1,
            'tipoDocRec' => (int) $d['docTipo'],
            'nroDocRec' => (int) preg_replace('/\D/', '', (string) $d['docNro']),
            'tipoCodAut' => 'E',
            'codAut' => (int) $d['cae'],
        );
        return 'https://www.afip.gob.ar/fe/qr/?p=' . base64_encode(json_encode($datos));
    }
}

/**
 * Bloqueo para que dos usuarios no tomen el mismo número al mismo tiempo.
 * Con MySQL usa GET_LOCK; si no, un archivo con flock.
 */
class Bloqueo
{
    private $pdo;
    private $nombre;
    private $archivo;
    private $fp;

    public function __construct($nombre, \PDO $pdo = null, $carpeta = '')
    {
        $this->nombre = 'afip_' . preg_replace('/[^A-Za-z0-9_]/', '_', $nombre);
        if ($pdo && $pdo->getAttribute(\PDO::ATTR_DRIVER_NAME) === 'mysql') {
            $this->pdo = $pdo;
        } else {
            $this->archivo = rtrim($carpeta !== '' ? $carpeta : sys_get_temp_dir(), '/\\') . DIRECTORY_SEPARATOR . $this->nombre . '.lock';
        }
    }

    public function tomar($segundos = 60)
    {
        if ($this->pdo) {
            $st = $this->pdo->prepare('SELECT GET_LOCK(?, ?)');
            $st->execute(array($this->nombre, (int) $segundos));
            if ((int) $st->fetchColumn() !== 1) {
                throw new AfipException('Hay otra factura en proceso para el mismo punto de venta. Intente de nuevo.');
            }
            return;
        }
        $this->fp = fopen($this->archivo, 'c');
        $fin = time() + $segundos;
        while (!flock($this->fp, LOCK_EX | LOCK_NB)) {
            if (time() > $fin) {
                throw new AfipException('Hay otra factura en proceso para el mismo punto de venta. Intente de nuevo.');
            }
            usleep(250000);
        }
    }

    public function soltar()
    {
        if ($this->pdo) {
            $st = $this->pdo->prepare('SELECT RELEASE_LOCK(?)');
            $st->execute(array($this->nombre));
        } elseif ($this->fp) {
            flock($this->fp, LOCK_UN);
            fclose($this->fp);
            $this->fp = null;
        }
    }
}

/**
 * Punto de entrada. Ejemplo:
 *   $afip = new Afip(require 'config_afip.php');
 *   $c = new Comprobante(); ... llenar ...
 *   $r = $afip->autorizar($c);   // ['aprobado'=>true,'cae'=>..., 'numero'=>...]
 */
class Afip
{
    public $cfg;
    public $wsaa;
    public $wsfe;

    /**
     * cfg: cuit, produccion, certificado+clave_privada (o pfx), frase,
     *      carpeta_ta (o almacen_ta: un AlmacenTa), carpeta_temp, ca_bundle, timeout, cifrados
     */
    public function __construct(array $cfg)
    {
        $this->cfg = $cfg;
        if (isset($cfg['ca_bundle'])) {
            Soap::$caBundle = $cfg['ca_bundle'];
        }
        if (isset($cfg['timeout'])) {
            Soap::$timeout = (int) $cfg['timeout'];
        }
        if (isset($cfg['cifrados'])) {
            Soap::$cifrados = $cfg['cifrados'];
        }
        if (isset($cfg['almacen_ta']) && $cfg['almacen_ta'] instanceof AlmacenTa) {
            $almacen = $cfg['almacen_ta'];
        } else {
            $almacen = new AlmacenTaArchivo(isset($cfg['carpeta_ta']) ? $cfg['carpeta_ta'] : sys_get_temp_dir());
        }
        $this->wsaa = new Wsaa($cfg, $almacen);
        $this->wsfe = new Wsfe($this->wsaa, $cfg['cuit'], !empty($cfg['produccion']));
    }

    /**
     * Valida, toma el número dentro de un bloqueo y pide el CAE.
     * @throws AfipException si la validación falla o ARCA devuelve error
     */
    public function autorizar(Comprobante $c, \PDO $pdoBloqueo = null)
    {
        $problemas = $c->validar();
        if ($problemas) {
            throw new AfipException('El comprobante no pasa los controles: ' . implode('; ', $problemas));
        }
        $b = new Bloqueo($this->cfg['cuit'] . '_' . $c->ptoVta . '_' . $c->tipo, $pdoBloqueo,
            isset($this->cfg['carpeta_temp']) ? $this->cfg['carpeta_temp'] : '');
        $b->tomar();
        try {
            $r = $this->wsfe->autorizar($c);
        } catch (\Exception $e) {
            $b->soltar();
            throw $e;
        }
        $b->soltar();
        return $r;
    }

    public function urlQr(Comprobante $c, $cae)
    {
        return QrAfip::url($this->cfg['cuit'], array('fecha' => $c->fecha, 'ptoVta' => $c->ptoVta, 'tipo' => $c->tipo,
            'numero' => $c->numero, 'importe' => $c->impTotal, 'moneda' => $c->moneda, 'cotizacion' => $c->cotizacion,
            'docTipo' => $c->docTipo, 'docNro' => $c->docNro, 'cae' => $cae));
    }
}
