<?php
namespace OysAfip;

/**
 * Dónde se guarda el ticket de acceso (TA). ARCA da un TA por 12 horas y rechaza pedir otro
 * mientras el anterior sigue vigente ("El CEE ya posee un TA valido..."), así que hay que guardarlo.
 */
interface AlmacenTa
{
    /** @return array|null ['token','sign','vence' => timestamp] */
    public function leer($clave);

    public function guardar($clave, array $ta);
}

/** Guarda el TA en un archivo JSON. La carpeta tiene que tener permiso de escritura y estar fuera de public_html. */
class AlmacenTaArchivo implements AlmacenTa
{
    private $carpeta;

    public function __construct($carpeta)
    {
        $this->carpeta = rtrim($carpeta, '/\\');
    }

    private function ruta($clave)
    {
        return $this->carpeta . DIRECTORY_SEPARATOR . 'ta_' . preg_replace('/[^A-Za-z0-9_]/', '_', $clave) . '.json';
    }

    public function leer($clave)
    {
        $r = $this->ruta($clave);
        if (!is_file($r)) {
            return null;
        }
        $d = json_decode((string) @file_get_contents($r), true);
        return is_array($d) ? $d : null;
    }

    public function guardar($clave, array $ta)
    {
        if (!is_dir($this->carpeta)) {
            @mkdir($this->carpeta, 0700, true);
        }
        if (@file_put_contents($this->ruta($clave), json_encode($ta), LOCK_EX) === false) {
            throw new AfipException('No se pudo guardar el ticket de acceso en ' . $this->carpeta);
        }
    }
}

/**
 * Guarda el TA en una tabla (MySQL/MariaDB o SQL Server). Útil en hostings sin carpeta con escritura.
 * Tabla: afip_ta (clave varchar(100) PK, datos text, actualizado datetime). Se crea sola en MySQL.
 */
class AlmacenTaPdo implements AlmacenTa
{
    private $pdo;
    private $tabla;

    public function __construct(\PDO $pdo, $tabla = 'afip_ta')
    {
        $this->pdo = $pdo;
        $this->tabla = preg_replace('/[^A-Za-z0-9_]/', '', $tabla);
    }

    public function leer($clave)
    {
        try {
            $st = $this->pdo->prepare('SELECT datos FROM ' . $this->tabla . ' WHERE clave = ?');
            $st->execute(array($clave));
            $v = $st->fetchColumn();
        } catch (\PDOException $e) {
            return null;
        }
        $d = $v ? json_decode($v, true) : null;
        return is_array($d) ? $d : null;
    }

    public function guardar($clave, array $ta)
    {
        $driver = $this->pdo->getAttribute(\PDO::ATTR_DRIVER_NAME);
        $datos = json_encode($ta);
        if ($driver === 'mysql') {
            $this->pdo->exec('CREATE TABLE IF NOT EXISTS ' . $this->tabla
                . ' (clave varchar(100) NOT NULL PRIMARY KEY, datos text NOT NULL, actualizado datetime NOT NULL)');
            $st = $this->pdo->prepare('REPLACE INTO ' . $this->tabla . ' (clave, datos, actualizado) VALUES (?, ?, NOW())');
            $st->execute(array($clave, $datos));
            return;
        }
        $st = $this->pdo->prepare('UPDATE ' . $this->tabla . ' SET datos = ?, actualizado = CURRENT_TIMESTAMP WHERE clave = ?');
        $st->execute(array($datos, $clave));
        if ($st->rowCount() === 0) {
            $st = $this->pdo->prepare('INSERT INTO ' . $this->tabla . ' (clave, datos, actualizado) VALUES (?, ?, CURRENT_TIMESTAMP)');
            $st->execute(array($clave, $datos));
        }
    }
}

/**
 * WSAA: autenticación con el certificado del emisor. Devuelve token y sign.
 */
class Wsaa
{
    const URL_PRODUCCION = 'https://wsaa.afip.gov.ar/ws/services/LoginCms';
    const URL_HOMOLOGACION = 'https://wsaahomo.afip.gov.ar/ws/services/LoginCms';

    private $cuit;
    private $produccion;
    private $cert;
    private $clave;
    private $claveFrase;
    private $almacen;
    private $carpetaTemp;

    /**
     * @param array $cfg cuit, produccion (bool), y el certificado de una de dos formas:
     *   - 'certificado' (.crt/.pem) + 'clave_privada' (.key) [+ 'frase']   (recomendado)
     *   - 'pfx' (.pfx/.p12) + 'frase'   (como lo usa hoy el sistema C#)
     *   Opcional: 'carpeta_temp' para los archivos temporales de la firma.
     */
    public function __construct(array $cfg, AlmacenTa $almacen)
    {
        $this->cuit = preg_replace('/\D/', '', $cfg['cuit']);
        $this->produccion = !empty($cfg['produccion']);
        $this->almacen = $almacen;
        $this->carpetaTemp = isset($cfg['carpeta_temp']) && $cfg['carpeta_temp'] !== '' ? $cfg['carpeta_temp'] : sys_get_temp_dir();
        $frase = isset($cfg['frase']) ? $cfg['frase'] : '';

        if (!empty($cfg['pfx'])) {
            $p12 = @file_get_contents($cfg['pfx']);
            if ($p12 === false) {
                throw new AfipException('No se encuentra el certificado ' . $cfg['pfx']);
            }
            $partes = array();
            if (!openssl_pkcs12_read($p12, $partes, $frase)) {
                throw new AfipException('No se pudo leer el .pfx (frase incorrecta o cifrado viejo no soportado por OpenSSL 3; '
                    . 'convertirlo a .crt + .key). ' . openssl_error_string());
            }
            $this->cert = $partes['cert'];
            $this->clave = $partes['pkey'];
            $this->claveFrase = '';
        } else {
            $this->cert = @file_get_contents($cfg['certificado']);
            $this->clave = @file_get_contents($cfg['clave_privada']);
            $this->claveFrase = $frase;
            if ($this->cert === false || $this->clave === false) {
                throw new AfipException('No se encuentra el certificado o la clave privada');
            }
        }
    }

    public function url()
    {
        return $this->produccion ? self::URL_PRODUCCION : self::URL_HOMOLOGACION;
    }

    /** Fecha de vencimiento del certificado (timestamp), para avisar antes de que venza. */
    public function venceCertificado()
    {
        $d = openssl_x509_parse($this->cert);
        return isset($d['validTo_time_t']) ? $d['validTo_time_t'] : 0;
    }

    /**
     * Devuelve ['token','sign','vence'] para el servicio (wsfe, wsmtxca...).
     * Usa el TA guardado mientras le queden más de 10 minutos.
     */
    public function credenciales($servicio)
    {
        $clave = $this->cuit . '_' . $servicio . '_' . ($this->produccion ? 'prod' : 'homo');
        $ta = $this->almacen->leer($clave);
        if ($ta && $ta['vence'] > time() + 600) {
            return $ta;
        }
        try {
            $ta = $this->login($servicio);
        } catch (AfipException $e) {
            // Hay un TA vigente que no tenemos guardado (lo pidió otro sistema u otra instalación).
            if (stripos($e->getMessage(), 'ya posee un TA valido') !== false) {
                throw new AfipException('ARCA dice que ya hay un ticket de acceso vigente para ' . $servicio
                    . ' que este sistema no tiene guardado (lo pidió otro programa con el mismo certificado). '
                    . 'Hay que esperar a que venza (hasta 12 horas) o usar otro certificado para la web.', $e->errores);
            }
            throw $e;
        }
        $this->almacen->guardar($clave, $ta);
        return $ta;
    }

    /** Arma el TRA, lo firma (CMS) y llama a loginCms. */
    public function login($servicio)
    {
        $ahora = time();
        $tra = '<?xml version="1.0" encoding="UTF-8"?>'
            . '<loginTicketRequest version="1.0"><header>'
            . '<uniqueId>' . $ahora . '</uniqueId>'
            . '<generationTime>' . date('c', $ahora - 600) . '</generationTime>'
            . '<expirationTime>' . date('c', $ahora + 600) . '</expirationTime>'
            . '</header><service>' . Soap::x($servicio) . '</service></loginTicketRequest>';

        $cms = $this->firmar($tra);
        $doc = Soap::llamar($this->url(), '', '<ar:loginCms><ar:in0>' . $cms . '</ar:in0></ar:loginCms>',
            'http://wsaa.view.sua.dvadac.desein.afip.gov');
        $ret = Soap::texto($doc->documentElement, 'loginCmsReturn');
        $ta = new \DOMDocument();
        if ($ret === '' || !@$ta->loadXML($ret)) {
            throw new AfipException('Respuesta de WSAA inválida');
        }
        $vence = strtotime(Soap::texto($ta->documentElement, 'expirationTime'));
        return array(
            'token' => Soap::texto($ta->documentElement, 'token'),
            'sign' => Soap::texto($ta->documentElement, 'sign'),
            'vence' => $vence ? $vence : $ahora + 11 * 3600,
        );
    }

    /** Firma el TRA en formato CMS (PKCS#7) y devuelve el base64. */
    public function firmar($tra)
    {
        $entrada = tempnam($this->carpetaTemp, 'tra');
        $salida = tempnam($this->carpetaTemp, 'cms');
        if ($entrada === false || $salida === false) {
            throw new AfipException('No se pueden crear archivos temporales en ' . $this->carpetaTemp);
        }
        file_put_contents($entrada, $tra);
        $clave = $this->claveFrase !== '' ? array($this->clave, $this->claveFrase) : $this->clave;
        $ok = openssl_pkcs7_sign($entrada, $salida, $this->cert, $clave, array(), PKCS7_BINARY);
        $smime = $ok ? file_get_contents($salida) : '';
        @unlink($entrada);
        @unlink($salida);
        if (!$ok) {
            throw new AfipException('No se pudo firmar el pedido de acceso: ' . openssl_error_string());
        }
        // El resultado es S/MIME: encabezados, línea vacía y el base64.
        $partes = preg_split("/\r?\n\r?\n/", $smime, 2);
        return preg_replace('/\s+/', '', isset($partes[1]) ? $partes[1] : '');
    }
}
