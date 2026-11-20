<?php
declare(strict_types=1);

namespace App\Modules\Instancias;

use App\Core\Config;

/**
 * Pasar a los clientes la versión que se probó en el master (dos carpetas en el hosting, cada una un clon de GIT):
 *   master   → rama main (lo nuevo llega primero acá)
 *   clientes → rama estable (panel.rama_clientes)
 * «Pasar a clientes» mueve la rama estable en GitHub EXACTAMENTE al commit que está corriendo el master (solo avanza: si estable
 * tuviera algo que main no tiene, se niega). Después se actualiza la carpeta de clientes con GIT en el hosting y se tocan
 * «Aplicar cambios en todas». Necesita en config/local.php del master: 'panel' => ['github_token' => '…'] (token de GitHub con
 * permiso Contents: write solo sobre el repositorio). Sin token, el panel muestra qué commit pasar y se hace a mano.
 */
final class Promocion
{
    /** Para las pruebas: function (string $metodo, string $url, array $cabeceras, ?string $cuerpo): array{status:int,body:string} */
    public static ?\Closure $transporte = null;

    public static function repo(): string
    {
        return (string) Config::get('panel.github_repo', 'hernancespedes-droid/facturaweb');
    }

    public static function rama(): string
    {
        return (string) Config::get('panel.rama_clientes', 'estable');
    }

    public static function hayToken(): bool
    {
        return (string) Config::get('panel.github_token', '') !== '';
    }

    /** Commit que tiene sacado el clon de GIT de esa carpeta (lee .git sin ejecutar git), o null. */
    public static function commit(string $carpeta): ?string
    {
        $git = rtrim($carpeta, '/') . '/.git';
        $head = trim((string) @file_get_contents($git . '/HEAD'));
        if (preg_match('/^[0-9a-f]{40}$/', $head) === 1) {
            return $head;
        }
        if (!str_starts_with($head, 'ref: ')) {
            return null;
        }
        $ref = substr($head, 5);
        if (preg_match('#^refs/[A-Za-z0-9._/-]+$#', $ref) !== 1) {
            return null;
        }
        $sha = trim((string) @file_get_contents($git . '/' . $ref));
        if (preg_match('/^[0-9a-f]{40}$/', $sha) === 1) {
            return $sha;
        }
        foreach (preg_split('/\R/', (string) @file_get_contents($git . '/packed-refs')) ?: [] as $linea) {
            if (preg_match('/^([0-9a-f]{40}) (\S+)$/', $linea, $m) === 1 && $m[2] === $ref) {
                return $m[1];
            }
        }
        return null;
    }

    /** Rama que tiene sacada la carpeta ("main", "estable"), o null. */
    public static function ramaDe(string $carpeta): ?string
    {
        $head = trim((string) @file_get_contents(rtrim($carpeta, '/') . '/.git/HEAD'));
        return str_starts_with($head, 'ref: refs/heads/') ? substr($head, 16) : null;
    }

    /**
     * Mueve la rama de los clientes al commit dado. @return array{ok:bool,mensaje:string}
     */
    public static function pasarAClientes(string $sha): array
    {
        if (preg_match('/^[0-9a-f]{40}$/', $sha) !== 1) {
            return ['ok' => false, 'mensaje' => 'No se pudo leer el commit del master (¿la carpeta del master es un clon de GIT?).'];
        }
        if (!self::hayToken()) {
            return ['ok' => false, 'mensaje' => 'Falta el token de GitHub en config/local.php del master (panel → github_token).'];
        }
        $base = 'https://api.github.com/repos/' . self::repo() . '/git/refs';
        $r = self::pedir('PATCH', $base . '/heads/' . rawurlencode(self::rama()), ['sha' => $sha, 'force' => false]);
        if ($r['status'] === 422 && str_contains($r['body'], 'Reference does not exist')) {
            $r = self::pedir('POST', $base, ['ref' => 'refs/heads/' . self::rama(), 'sha' => $sha]);
        }
        if ($r['status'] >= 200 && $r['status'] < 300) {
            return ['ok' => true, 'mensaje' => 'La rama ' . self::rama() . ' quedó en ' . substr($sha, 0, 7) . '.'];
        }
        $msg = (string) (json_decode($r['body'], true)['message'] ?? ('error ' . $r['status']));
        if (str_contains($msg, 'fast forward')) {
            $msg = 'La rama ' . self::rama() . ' tiene cambios que el master no tiene (no se pisa nada). Revisalo con Code.';
        }
        return ['ok' => false, 'mensaje' => 'GitHub no aceptó el cambio: ' . $msg];
    }

    /** @param array<string,mixed> $cuerpo @return array{status:int,body:string} */
    private static function pedir(string $metodo, string $url, array $cuerpo): array
    {
        $cabeceras = ['Authorization: Bearer ' . Config::get('panel.github_token'), 'Accept: application/vnd.github+json',
            'X-GitHub-Api-Version: 2022-11-28', 'User-Agent: FacturaWeb-panel', 'Content-Type: application/json'];
        $json = (string) json_encode($cuerpo);
        if (self::$transporte !== null) {
            return (self::$transporte)($metodo, $url, $cabeceras, $json);
        }
        if (!function_exists('curl_init')) {
            return ['status' => 0, 'body' => '{"message":"el servidor no tiene curl"}'];
        }
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_CUSTOMREQUEST => $metodo, CURLOPT_HTTPHEADER => $cabeceras, CURLOPT_POSTFIELDS => $json,
            CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 8, CURLOPT_TIMEOUT => 20]);
        $body = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        return $body === false ? ['status' => 0, 'body' => (string) json_encode(['message' => 'sin conexión con GitHub (' . $err . ')'])] : ['status' => $status, 'body' => (string) $body];
    }
}
