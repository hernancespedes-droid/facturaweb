<?php
declare(strict_types=1);

namespace App\Modules\Licencia;

use App\App;
use App\Core\Config;
use App\Core\Db;

/**
 * Licencia de la suscripción (una por instalación). Es un archivo JSON firmado por O&S con una clave privada que NUNCA está en el
 * código ni en el servidor del cliente (Ed25519, libsodium): el cliente solo tiene la clave pública, así que puede leer su licencia pero no
 * fabricar ni alargar una.   storage/licencia.json = {"cliente","cuit","vence":"AAAA-MM-DD","emitida":"AAAA-MM-DD","firma":"…"}
 *
 * Solo se controla si config/local.php tiene 'licencia' => ['exigir' => true]. Sin eso (por ejemplo OYS, que no es suscripción) no cambia nada.
 *
 * DECISIÓN D-26 (Claude, a confirmar por Hernán): estados según los días que faltan para el vencimiento:
 *   más de 10 días = ok · de 10 a 0 = por vencer (aviso) · hasta 5 días vencida = gracia (aviso fuerte, se sigue emitiendo)
 *   · después = vencida: no se emite (se puede consultar, imprimir y cargar la licencia nueva). Sin licencia o firma mala = no se emite.
 * DECISIÓN D-27: la licencia lleva el CUIT y, si la empresa ya lo cargó en Parámetros, tiene que coincidir (una licencia no sirve para otro cliente).
 */
final class Licencia
{
    public const AVISO_DIAS = 10;
    public const GRACIA_DIAS = 5;

    public static function exige(): bool
    {
        return (bool) Config::get('licencia.exigir', false);
    }

    public static function archivo(): string
    {
        return (string) Config::get('licencia.archivo', App::root() . '/storage/licencia.json');
    }

    /** Clave pública de O&S (base64): config/local.php → licencia.clave_publica, o config/licencia_publica.txt del código. */
    public static function clavePublica(): string
    {
        $c = trim((string) Config::get('licencia.clave_publica', ''));
        if ($c !== '') {
            return $c;
        }
        $f = App::root() . '/config/licencia_publica.txt';
        return is_file($f) ? trim((string) file_get_contents($f)) : '';
    }

    /** Lo que se firma: se arma igual al emitir y al verificar. @param array<string,mixed> $l */
    public static function mensaje(array $l): string
    {
        return implode('|', ['FW1', (string) ($l['cliente'] ?? ''), preg_replace('/\D/', '', (string) ($l['cuit'] ?? '')), (string) ($l['vence'] ?? ''), (string) ($l['emitida'] ?? '')]);
    }

    /** Para la herramienta de O&S y las pruebas. @param array<string,mixed> $datos @return array<string,mixed> */
    public static function firmar(array $datos, string $clavePrivadaB64): array
    {
        unset($datos['firma']);
        $datos['cuit'] = preg_replace('/\D/', '', (string) $datos['cuit']);
        $firma = sodium_crypto_sign_detached(self::mensaje($datos), (string) base64_decode($clavePrivadaB64, true));
        return $datos + ['firma' => base64_encode($firma)];
    }

    /** @param array<string,mixed> $l */
    public static function firmaValida(array $l, string $clavePublicaB64): bool
    {
        $pub = base64_decode($clavePublicaB64, true);
        $firma = base64_decode((string) ($l['firma'] ?? ''), true);
        if ($pub === false || strlen($pub) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES || $firma === false || strlen($firma) !== SODIUM_CRYPTO_SIGN_BYTES) {
            return false;
        }
        return sodium_crypto_sign_verify_detached($firma, self::mensaje($l), $pub);
    }

    /** @return array<string,mixed>|null */
    public static function leer(): ?array
    {
        $f = self::archivo();
        if (!is_file($f)) {
            return null;
        }
        $d = json_decode((string) file_get_contents($f), true);
        return is_array($d) ? $d : null;
    }

    /**
     * Valida y guarda una licencia nueva (la carga el Master desde Sistema → Licencia).
     * @return array{ok:bool,error:?string}
     */
    public static function guardar(string $json): array
    {
        $l = json_decode($json, true);
        if (!is_array($l) || !isset($l['vence'], $l['firma'])) {
            return ['ok' => false, 'error' => 'El texto no es una licencia (tiene que ser el archivo licencia.json completo).'];
        }
        $problema = self::problema($l);
        if ($problema !== null) {
            return ['ok' => false, 'error' => $problema];
        }
        $f = self::archivo();
        if (!is_dir(dirname($f)) || file_put_contents($f, json_encode($l, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)) === false) {
            return ['ok' => false, 'error' => 'No se pudo guardar el archivo de licencia (permisos de storage/).'];
        }
        return ['ok' => true, 'error' => null];
    }

    /** Por qué una licencia no sirve (firma o CUIT), o null si está bien. @param array<string,mixed> $l */
    private static function problema(array $l): ?string
    {
        $pub = self::clavePublica();
        if ($pub === '') {
            return 'Esta instalación no tiene la clave pública de O&S para verificar licencias.';
        }
        if (!self::firmaValida($l, $pub)) {
            return 'La firma de la licencia no es válida: el archivo está incompleto o fue modificado.';
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $l['vence']) !== 1) {
            return 'La fecha de vencimiento de la licencia no es válida.';
        }
        $cuit = (string) Db::pdo()->query('SELECT COALESCE(cuit, \'\') FROM parametros WHERE id = 1')->fetchColumn();
        $cuit = preg_replace('/\D/', '', $cuit);
        if ($cuit !== '' && $cuit !== '0' && $cuit !== preg_replace('/\D/', '', (string) ($l['cuit'] ?? ''))) {
            return 'La licencia es de otro CUIT (' . ($l['cuit'] ?? '?') . '): no corresponde a esta empresa.';
        }
        return null;
    }

    /**
     * @return array{exigida:bool,estado:string,vence:?string,dias:?int,cliente:?string,mensaje:string}
     *   estado: sin_control | ok | por_vencer | gracia | vencida | sin_licencia | invalida
     */
    public static function estado(?\DateTimeImmutable $hoy = null): array
    {
        $base = ['exigida' => self::exige(), 'estado' => 'sin_control', 'vence' => null, 'dias' => null, 'cliente' => null, 'mensaje' => 'Esta instalación no usa licencia de suscripción.'];
        if (!$base['exigida']) {
            return $base;
        }
        $l = self::leer();
        if ($l === null) {
            return ['estado' => 'sin_licencia', 'mensaje' => 'Falta la licencia de la suscripción. Pedísela a O&S y cargala en Sistema → Licencia.'] + $base;
        }
        $problema = self::problema($l);
        if ($problema !== null) {
            return ['estado' => 'invalida', 'mensaje' => $problema, 'cliente' => $l['cliente'] ?? null] + $base;
        }
        $hoy ??= new \DateTimeImmutable('today');
        $dias = (int) $hoy->diff(new \DateTimeImmutable((string) $l['vence']))->format('%r%a');
        $d = ['vence' => (string) $l['vence'], 'dias' => $dias, 'cliente' => (string) ($l['cliente'] ?? '')] + $base;
        $fecha = (new \DateTimeImmutable((string) $l['vence']))->format('d/m/Y');
        return match (true) {
            $dias > self::AVISO_DIAS => ['estado' => 'ok', 'mensaje' => "Suscripción vigente hasta el {$fecha}."] + $d,
            $dias >= 0 => ['estado' => 'por_vencer', 'mensaje' => "La suscripción vence el {$fecha}" . ($dias === 0 ? ' (hoy).' : " (en {$dias} día" . ($dias === 1 ? '' : 's') . ').') . ' Pedí la renovación a O&S.'] + $d,
            $dias >= -self::GRACIA_DIAS => ['estado' => 'gracia', 'mensaje' => "La suscripción venció el {$fecha}. Se puede seguir emitiendo hasta " . (self::GRACIA_DIAS + $dias) . ' día(s) más: renovala ya.'] + $d,
            default => ['estado' => 'vencida', 'mensaje' => "La suscripción venció el {$fecha}: no se pueden emitir comprobantes hasta cargar la licencia renovada (Sistema → Licencia)."] + $d,
        };
    }

    /** @throws LicenciaVencida si hay control de licencia y no está vigente */
    public static function exigirParaEmitir(?\DateTimeImmutable $hoy = null): void
    {
        $e = self::estado($hoy);
        if ($e['exigida'] && in_array($e['estado'], ['vencida', 'sin_licencia', 'invalida'], true)) {
            throw new LicenciaVencida($e['mensaje']);
        }
    }

    /** Aviso para el encabezado de todas las pantallas, o null. @return array{tipo:string,texto:string}|null */
    public static function aviso(bool $esAdmin): ?array
    {
        $e = self::estado();
        return match ($e['estado']) {
            'vencida', 'sin_licencia', 'invalida' => ['tipo' => 'error', 'texto' => $e['mensaje']],
            'gracia' => ['tipo' => 'error', 'texto' => $e['mensaje']],
            'por_vencer' => $esAdmin ? ['tipo' => 'info', 'texto' => $e['mensaje']] : null,
            default => null,
        };
    }
}
