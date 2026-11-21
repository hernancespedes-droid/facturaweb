<?php
declare(strict_types=1);

namespace App\Modules\Soporte;

use App\Core\Config;
use App\Core\Modulos;

/**
 * Lo que usa la pantalla «Soporte» de una instalación para hablar con el master. En la instalación principal guarda directo
 * (SoporteService, instancia ''); en un cliente va por la API del master con la dirección y la clave de su config
 * ('soporte' => ['url' => 'https://oys.orgysis.site', 'clave' => 'fws_…'], la escribe el master solo).
 * Si el master no contesta, tira SoporteNoDisponible con un texto para mostrar.
 */
final class SoporteCliente
{
    /** Para las pruebas: function (string $metodo, string $url, array $cabeceras, ?array $cuerpo): array{status:int,body:string} */
    public static $transporte = null;

    public static function local(): bool
    {
        return !Modulos::esCliente();
    }

    public static function configurado(): bool
    {
        return self::local() || ((string) Config::get('soporte.url', '') !== '' && (string) Config::get('soporte.clave', '') !== '');
    }

    /** @return array<int,array<string,mixed>> */
    public static function listar(string $estado, string $orden = ''): array
    {
        if (self::local()) {
            return (new SoporteService())->listarDe('', ['estado' => $estado, 'orden' => $orden]);
        }
        return (array) (self::pedir('GET', '/tickets?' . http_build_query(['estado' => $estado, 'orden' => $orden]))['tickets'] ?? []);
    }

    /** @param array<string,mixed> $d @return array{ok:bool,errores?:array<string,string>,id?:int} */
    public static function crear(array $d): array
    {
        if (self::local()) {
            $d['instancia_nombre'] = (string) Config::get('app.name', '');
            return (new SoporteService())->crear('', $d);
        }
        $cuerpo = array_intersect_key($d, array_flip(['asunto', 'tipo', 'prioridad', 'descripcion', 'usuario', 'usuario_mail', 'version', 'pantalla', 'operacion']));
        $cuerpo += self::adjuntoCuerpo($d['adjunto'] ?? null);
        $r = self::pedir('POST', '/tickets', $cuerpo, [422]);
        return ['ok' => !empty($r['ok'])] + (isset($r['errores']) ? ['errores' => (array) $r['errores']] : []) + (isset($r['id']) ? ['id' => (int) $r['id']] : []);
    }

    /** @return array<string,mixed>|null */
    public static function ver(int $id): ?array
    {
        if (self::local()) {
            return (new SoporteService())->ver($id, '');
        }
        $r = self::pedir('GET', '/tickets/' . $id, null, [404]);
        return !empty($r['ok']) ? (array) $r['ticket'] : null;
    }

    /** @param array<string,mixed> $d texto, usuario, adjunto @return array{ok:bool,error?:string} */
    public static function responder(int $id, array $d): array
    {
        if (self::local()) {
            return (new SoporteService())->responderCliente($id, '', $d);
        }
        $r = self::pedir('POST', '/tickets/' . $id . '/responder', ['texto' => (string) ($d['texto'] ?? ''), 'usuario' => (string) ($d['usuario'] ?? '')]
            + self::adjuntoCuerpo($d['adjunto'] ?? null), [422]);
        return ['ok' => !empty($r['ok'])] + (isset($r['error']) ? ['error' => (string) $r['error']] : []);
    }

    /** @return array{ok:bool,error?:string} */
    public static function cambiarEstado(int $id, string $accion, string $usuario): array
    {
        if (self::local()) {
            return (new SoporteService())->cambiarEstadoCliente($id, '', $accion, $usuario);
        }
        $r = self::pedir('POST', '/tickets/' . $id . '/estado', ['accion' => $accion, 'usuario' => $usuario], [422]);
        return ['ok' => !empty($r['ok'])] + (isset($r['error']) ? ['error' => (string) $r['error']] : []);
    }

    /** @return array{nombre:string,tipo:string,datos:string}|null */
    public static function adjunto(int $id): ?array
    {
        if (self::local()) {
            $a = (new SoporteService())->adjunto($id, '');
            return $a === null ? null : ['nombre' => (string) $a['nombre'], 'tipo' => (string) $a['tipo'], 'datos' => (string) $a['datos']];
        }
        $r = self::pedir('GET', '/adjuntos/' . $id, null, [404]);
        if (empty($r['ok'])) {
            return null;
        }
        $datos = base64_decode((string) ($r['datos'] ?? ''), true);
        return $datos === false ? null : ['nombre' => (string) $r['nombre'], 'tipo' => (string) $r['tipo'], 'datos' => $datos];
    }

    /** @return array<string,string> */
    private static function adjuntoCuerpo(mixed $a): array
    {
        if (!is_array($a) || (string) ($a['datos'] ?? '') === '') {
            return [];
        }
        return ['adjunto_nombre' => (string) ($a['nombre'] ?? ''), 'adjunto_b64' => base64_encode((string) $a['datos'])];
    }

    /**
     * @param array<string,string>|null $cuerpo
     * @param int[] $aceptados estados de error que traen una respuesta para mostrar (validación, no existe)
     * @return array<string,mixed>
     */
    private static function pedir(string $metodo, string $ruta, ?array $cuerpo = null, array $aceptados = []): array
    {
        if (!self::configurado()) {
            throw new SoporteNoDisponible('Esta instalación todavía no está conectada con O&S para soporte. Avisanos por teléfono o mail.');
        }
        $url = rtrim((string) Config::get('soporte.url'), '/') . '/api/v1/soporte' . $ruta;
        $cab = ['X-FW-Instancia' => (string) Config::get('instancia.host', ''), 'X-FW-Clave' => (string) Config::get('soporte.clave', ''), 'Accept' => 'application/json'];
        $r = self::$transporte !== null ? (self::$transporte)($metodo, $url, $cab, $cuerpo) : self::curl($metodo, $url, $cab, $cuerpo);
        $datos = json_decode($r['body'], true);
        if (($r['status'] >= 200 && $r['status'] < 300) || (in_array($r['status'], $aceptados, true) && is_array($datos))) {
            return is_array($datos) ? $datos : [];
        }
        if ($r['status'] === 401) {
            throw new SoporteNoDisponible('El sistema de soporte de O&S no reconoció esta instalación. Avisanos por teléfono o mail.');
        }
        throw new SoporteNoDisponible('No se pudo conectar con el soporte de O&S (' . ($r['status'] ?: 'sin respuesta') . '). Probá de nuevo en unos minutos.');
    }

    /** @param array<string,string> $cab @param array<string,string>|null $cuerpo @return array{status:int,body:string} */
    private static function curl(string $metodo, string $url, array $cab, ?array $cuerpo): array
    {
        if (!function_exists('curl_init')) {
            return ['status' => 0, 'body' => ''];
        }
        $ch = curl_init($url);
        $h = [];
        foreach ($cab as $k => $v) {
            $h[] = $k . ': ' . $v;
        }
        $op = [CURLOPT_CUSTOMREQUEST => $metodo, CURLOPT_HTTPHEADER => $h, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20, CURLOPT_CONNECTTIMEOUT => 8];
        if ($cuerpo !== null) {
            $op[CURLOPT_POSTFIELDS] = http_build_query($cuerpo);
        }
        curl_setopt_array($ch, $op);
        $body = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        return ['status' => $body === false ? 0 : $status, 'body' => $body === false ? '' : (string) $body];
    }
}
