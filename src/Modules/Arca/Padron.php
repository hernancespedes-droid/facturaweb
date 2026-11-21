<?php
declare(strict_types=1);

namespace App\Modules\Arca;

use App\Modules\Auth\Auth;
use App\Core\Config;
use App\Core\Db;
use App\Core\Valida;

/**
 * Padrón de ARCA: datos de un CUIT (razón social, domicilio fiscal, condición de IVA, monotributo, actividades) con el servicio
 * «Constancia de inscripción» (ws_sr_constancia_inscripcion, método getPersona_v2), que reemplaza a los padrones A4/A5/A13.
 *
 * Usa el mismo certificado y el mismo ticket de acceso que la factura electrónica (Arca::afip()), así que solo funciona con ARCA en
 * modo simulado, homologación o producción. En ARCA hay que **asociar el servicio** «ws_sr_constancia_inscripcion» al certificado de la
 * web (Administrador de relaciones de clave fiscal → Nueva relación → el alias del certificado): si no, ARCA contesta «no autorizado»
 * y acá se explica eso. Se puede apagar con 'afip' => ['padron' => false] en config/local.php.
 *
 * Cada respuesta se guarda normalizada en padron_consultas (caché de afip.padron_dias, 30 días por omisión).
 *
 * DECISIÓN D-70 (Claude, a confirmar por Hernán): condición de IVA del cliente desde el padrón:
 *   monotributo vigente → 7 Monotributo · IVA (impuesto 30) activo → 1 Inscripto · IVA exento (32) → 4 Exento (B) ·
 *   IVA no alcanzado (34) → 4 Exento (B), con aviso · sin IVA ni monotributo → 3 Consumidor final, con aviso.
 */
final class Padron
{
    public const SERVICIO = 'ws_sr_constancia_inscripcion';
    public const URL_PRODUCCION = 'https://aws.afip.gov.ar/sr-padron/webservices/personaServiceA5';
    public const URL_HOMOLOGACION = 'https://awshomo.afip.gov.ar/sr-padron/webservices/personaServiceA5';
    private const NS = 'http://a5.soap.ws.server.puc.sr/';

    /** Provincias del padrón (idProvincia) — para cuando la descripción no viene. */
    public const PROVINCIAS = [
        0 => 'CIUDAD AUTONOMA BUENOS AIRES', 1 => 'BUENOS AIRES', 2 => 'CATAMARCA', 3 => 'CORDOBA', 4 => 'CORRIENTES', 5 => 'ENTRE RIOS',
        6 => 'JUJUY', 7 => 'MENDOZA', 8 => 'LA RIOJA', 9 => 'SALTA', 10 => 'SAN JUAN', 11 => 'SAN LUIS', 12 => 'SANTA FE',
        13 => 'SANTIAGO DEL ESTERO', 14 => 'TUCUMAN', 16 => 'CHACO', 17 => 'CHUBUT', 18 => 'FORMOSA', 19 => 'MISIONES', 20 => 'NEUQUEN',
        21 => 'LA PAMPA', 22 => 'RIO NEGRO', 23 => 'SANTA CRUZ', 24 => 'TIERRA DEL FUEGO',
    ];

    /** Para las pruebas: callable(string $cuit): array (la respuesta normalizada) en lugar de ARCA. */
    public static $transporte = null;

    /** Si se puede consultar el padrón en esta instalación. */
    public static function disponible(): bool
    {
        return self::$transporte !== null || (Arca::modo() !== 'interno' && Config::get('afip.padron', true) !== false);
    }

    /** Por qué no se puede consultar (para mostrarlo), o null. */
    public static function motivoNoDisponible(): ?string
    {
        if (self::disponible()) {
            return null;
        }
        return Arca::modo() === 'interno'
            ? 'La consulta a ARCA no está activa en esta instalación (modo interno): completá los datos a mano.'
            : 'La consulta del padrón de ARCA está apagada en la configuración: completá los datos a mano.';
    }

    /**
     * Datos del CUIT, normalizados (ver normalizar()). Usa la caché salvo $forzar.
     *
     * @return array<string,mixed>
     * @throws PadronError con un mensaje para el usuario
     */
    public static function consultar(string $cuit, bool $forzar = false): array
    {
        $cuit = preg_replace('/\D/', '', $cuit) ?? '';
        if (!Valida::cuit($cuit)) {
            throw new PadronError('El CUIT no es válido (revisá los 11 números).', 'cuit');
        }
        if (!self::disponible()) {
            throw new PadronError((string) self::motivoNoDisponible(), 'no_disponible');
        }
        $entorno = self::$transporte !== null ? 'T' : Arca::entorno();
        if (!$forzar && ($c = self::enCache($cuit, $entorno)) !== null) {
            return $c;
        }
        if (self::$transporte !== null) {
            $d = (self::$transporte)($cuit);
        } else {
            $d = self::normalizar(self::pedir($cuit), $cuit);
        }
        $d['consultado_en'] = date('Y-m-d H:i:s');
        $d['entorno'] = $entorno;
        Db::pdo()->prepare('REPLACE INTO padron_consultas (cuit, entorno, datos, consultado_en, usuario_id) VALUES (?, ?, ?, NOW(), ?)')
            ->execute([(int) $cuit, $entorno, json_encode($d, JSON_UNESCAPED_UNICODE), Auth::id()]);
        $d['de_cache'] = false;
        return $d;
    }

    /** Lo último que se guardó de ese CUIT (sin importar la antigüedad), o null. @return array<string,mixed>|null */
    public static function guardado(string $cuit): ?array
    {
        $cuit = preg_replace('/\D/', '', $cuit) ?? '';
        if ($cuit === '') {
            return null;
        }
        $st = Db::pdo()->prepare("SELECT datos FROM padron_consultas WHERE cuit = ? AND entorno <> 'T' ORDER BY (entorno = 'P') DESC, consultado_en DESC LIMIT 1");
        $st->execute([(int) $cuit]);
        $v = $st->fetchColumn();
        $d = $v === false ? null : json_decode((string) $v, true);
        return is_array($d) ? $d : null;
    }

    /** @return array<string,mixed>|null */
    private static function enCache(string $cuit, string $entorno): ?array
    {
        $dias = max(0, (int) Config::get('afip.padron_dias', 30));
        if ($dias === 0) {
            return null;
        }
        $st = Db::pdo()->prepare('SELECT datos FROM padron_consultas WHERE cuit = ? AND entorno = ? AND consultado_en >= NOW() - INTERVAL ? DAY');
        $st->execute([(int) $cuit, $entorno, $dias]);
        $v = $st->fetchColumn();
        $d = $v === false ? null : json_decode((string) $v, true);
        if (!is_array($d)) {
            return null;
        }
        $d['de_cache'] = true;
        return $d;
    }

    /** Llama a getPersona_v2 y devuelve personaReturn como arreglo. @return array<string,mixed> */
    private static function pedir(string $cuit): array
    {
        try {
            $afip = Arca::afip();
            $ta = $afip->wsaa->credenciales(self::SERVICIO);
        } catch (\Throwable $e) {
            throw new PadronError(self::explicar($e->getMessage()), 'acceso');
        }
        $produccion = !empty($afip->cfg['produccion']);
        $cuerpo = '<ar:getPersona_v2><token>' . \OysAfip\Soap::x($ta['token']) . '</token><sign>' . \OysAfip\Soap::x($ta['sign']) . '</sign>'
            . '<cuitRepresentada>' . \OysAfip\Soap::x(preg_replace('/\D/', '', (string) $afip->cfg['cuit'])) . '</cuitRepresentada>'
            . '<idPersona>' . $cuit . '</idPersona></ar:getPersona_v2>';
        try {
            $doc = \OysAfip\Soap::llamar($produccion ? self::URL_PRODUCCION : self::URL_HOMOLOGACION, '', $cuerpo, self::NS);
        } catch (\OysAfip\AfipConexionException $e) {
            throw new PadronError('ARCA no responde en este momento. Podés completar los datos a mano o volver a intentar en un rato.', 'conexion');
        } catch (\Throwable $e) {
            $m = $e->getMessage();
            if (stripos($m, 'No existe persona') !== false) {
                throw new PadronError('ARCA no tiene registrado el CUIT ' . cuit_texto($cuit) . '. Revisá el número.', 'no_existe');
            }
            throw new PadronError(self::explicar($m), 'arca');
        }
        $nodo = $doc->getElementsByTagName('personaReturn');
        if ($nodo->length === 0) {
            throw new PadronError('ARCA contestó algo que no se entiende (falta personaReturn).', 'arca');
        }
        $r = \OysAfip\Soap::aArreglo($nodo->item(0));
        return is_array($r) ? $r : [];
    }

    /** Mensajes de ARCA más comunes, en criollo. */
    private static function explicar(string $m): string
    {
        if (stripos($m, 'notAuthorized') !== false || stripos($m, 'no autorizado') !== false || stripos($m, 'not authorized') !== false) {
            return 'El certificado de la web todavía no está autorizado para consultar el padrón de ARCA. En ARCA → Administrador de relaciones de clave fiscal '
                . 'hay que asociar el servicio «Consulta de constancia de inscripción» (ws_sr_constancia_inscripcion) al certificado. Mientras tanto, completá los datos a mano.';
        }
        if (stripos($m, 'ya posee un TA valido') !== false || stripos($m, 'ticket de acceso vigente') !== false) {
            return 'ARCA tiene un permiso de acceso al padrón pedido desde otro programa con el mismo certificado. Probá en unos minutos o completá los datos a mano.';
        }
        return 'No se pudo consultar ARCA: ' . $m;
    }

    /**
     * personaReturn → datos para el alta.
     *
     * @param array<string,mixed> $r
     * @return array{cuit:string,encontrado:bool,razon_social:string,tipo_persona:string,estado:string,domicilio:string,localidad:string,
     *   codigo_postal:string,provincia:string,iva:int,condicion:string,monotributo:string,impuestos:array<int,array{id:int,descripcion:string,desde:string}>,
     *   actividades:array<int,array{id:string,descripcion:string,principal:bool}>,avisos:string[]}
     */
    public static function normalizar(array $r, string $cuit): array
    {
        $g = is_array($r['datosGenerales'] ?? null) ? $r['datosGenerales'] : [];
        $errores = [];
        foreach (['errorConstancia', 'errorRegimenGeneral', 'errorMonotributo'] as $k) {
            if (isset($r[$k]) && is_array($r[$k])) {
                foreach (\OysAfip\Soap::lista($r[$k]['error'] ?? null) as $e) {
                    if (is_string($e) && $e !== '') {
                        $errores[] = $e;
                    }
                }
            }
        }
        $avisos = [];
        $nombre = trim((string) ($g['razonSocial'] ?? ''));
        if ($nombre === '') {
            $ec = is_array($r['errorConstancia'] ?? null) ? $r['errorConstancia'] : [];
            $nombre = trim(trim((string) ($g['apellido'] ?? $ec['apellido'] ?? '')) . ' ' . trim((string) ($g['nombre'] ?? $ec['nombre'] ?? '')));
        }
        $dom = is_array($g['domicilioFiscal'] ?? null) ? $g['domicilioFiscal'] : [];
        $idProv = isset($dom['idProvincia']) && $dom['idProvincia'] !== '' ? (int) $dom['idProvincia'] : null;
        $provincia = trim((string) ($dom['descripcionProvincia'] ?? ''));
        if ($provincia === '' && $idProv !== null) {
            $provincia = self::PROVINCIAS[$idProv] ?? '';
        }
        $localidad = trim((string) ($dom['localidad'] ?? ''));
        if ($localidad === '' && $idProv === 0) {
            $localidad = 'CIUDAD AUTONOMA BUENOS AIRES';
        }
        $direccion = trim((string) ($dom['direccion'] ?? ''));
        if (($dom['datoAdicional'] ?? '') !== '' && is_string($dom['datoAdicional'])) {
            $direccion = trim($direccion . ' ' . trim($dom['datoAdicional']));
        }

        $impuestos = [];
        $ids = [];
        $rg = is_array($r['datosRegimenGeneral'] ?? null) ? $r['datosRegimenGeneral'] : [];
        $mt = is_array($r['datosMonotributo'] ?? null) ? $r['datosMonotributo'] : [];
        foreach ([...\OysAfip\Soap::lista($rg['impuesto'] ?? null), ...\OysAfip\Soap::lista($mt['impuesto'] ?? null)] as $i) {
            if (!is_array($i) || isset($ids[(int) ($i['idImpuesto'] ?? 0)])) {
                continue;
            }
            if (($i['estadoImpuesto'] ?? 'AC') !== 'AC' && ($i['estadoImpuesto'] ?? '') !== '') {
                continue;
            }
            $ids[(int) ($i['idImpuesto'] ?? 0)] = true;
            $impuestos[] = ['id' => (int) ($i['idImpuesto'] ?? 0), 'descripcion' => trim((string) ($i['descripcionImpuesto'] ?? '')), 'desde' => self::periodo((string) ($i['periodo'] ?? ''))];
        }
        $actividades = [];
        $lista = [...\OysAfip\Soap::lista($rg['actividad'] ?? null)];
        if ($lista === [] && isset($mt['actividadMonotributista']) && is_array($mt['actividadMonotributista'])) {
            $lista = \OysAfip\Soap::lista($mt['actividadMonotributista']);
        }
        usort($lista, static fn ($a, $b) => (int) ($a['orden'] ?? 99) <=> (int) ($b['orden'] ?? 99));
        foreach ($lista as $a) {
            if (is_array($a)) {
                $actividades[] = ['id' => (string) ($a['idActividad'] ?? ''), 'descripcion' => trim((string) ($a['descripcionActividad'] ?? '')), 'principal' => (int) ($a['orden'] ?? 0) === 1];
            }
        }

        $cat = is_array($mt['categoriaMonotributo'] ?? null) ? $mt['categoriaMonotributo'] : [];
        $monotributo = trim((string) ($cat['descripcionCategoria'] ?? ''));
        if ($monotributo !== '' || isset($ids[20]) || isset($ids[21])) {
            [$iva, $condicion] = [7, 'Monotributo' . ($monotributo !== '' ? ' (' . $monotributo . ')' : '')];
        } elseif (isset($ids[30])) {
            [$iva, $condicion] = [1, 'IVA Responsable Inscripto'];
        } elseif (isset($ids[32])) {
            [$iva, $condicion] = [4, 'IVA Exento'];
        } elseif (isset($ids[34])) {
            [$iva, $condicion] = [4, 'IVA No Alcanzado'];
            $avisos[] = 'ARCA lo tiene como «IVA no alcanzado»: se carga como Exento. Revisalo si hace falta.';
        } else {
            [$iva, $condicion] = [3, 'Sin inscripción en IVA ni monotributo'];
            $avisos[] = 'No está inscripto en IVA ni en el monotributo: se carga como Consumidor final.';
        }
        $estado = strtoupper(trim((string) ($g['estadoClave'] ?? '')));
        if ($estado !== '' && $estado !== 'ACTIVO') {
            $avisos[] = 'ARCA informa la clave fiscal en estado ' . $estado . ': confirmá con el cliente antes de facturarle.';
        }
        foreach ($errores as $e) {
            $avisos[] = 'ARCA: ' . $e;
        }
        return [
            'cuit' => $cuit,
            'encontrado' => $nombre !== '',
            'razon_social' => mb_substr(preg_replace('/\s+/', ' ', $nombre) ?? $nombre, 0, 100),
            'tipo_persona' => (string) ($g['tipoPersona'] ?? ''),
            'estado' => $estado,
            'domicilio' => mb_substr($direccion, 0, 80),
            'localidad' => mb_substr($localidad, 0, 40),
            'codigo_postal' => mb_substr(trim((string) ($dom['codPostal'] ?? '')), 0, 8),
            'provincia' => $provincia,
            'iva' => $iva,
            'condicion' => $condicion,
            'monotributo' => $monotributo,
            'impuestos' => $impuestos,
            'actividades' => array_slice($actividades, 0, 10),
            'avisos' => array_values(array_unique($avisos)),
        ];
    }

    private static function periodo(string $p): string
    {
        return preg_match('/^(\d{4})(\d{2})/', $p, $m) === 1 ? $m[2] . '/' . $m[1] : $p;
    }
}
