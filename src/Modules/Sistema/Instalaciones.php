<?php
declare(strict_types=1);

namespace App\Modules\Sistema;

use App\Core\Config;
use App\Core\Db;
use App\Core\Session;
use PDO;

/**
 * Panel de instalaciones (clientes): el Master anota las direcciones de las instalaciones y el sistema le pregunta a cada una su /salud (versión, cambios de base
 * pendientes, días de licencia, si falló la actualización automática). Sirve para ver de un vistazo quién está al día, sin entrar a cada cliente.
 * Solo el Master. Nunca se guardan claves: /salud es público y no lleva datos sensibles.
 */
final class Instalaciones
{
    /** Para las pruebas: callable(string $url): array{status:int,body:string}. Null = curl. @var ?callable */
    public static $transporte = null;

    /** @return array<int,array<string,mixed>> */
    public function listar(): array
    {
        return Db::pdo()->query('SELECT * FROM instalaciones ORDER BY nombre')->fetchAll();
    }

    /** Dirección base normalizada («https://dominio»), o null si no sirve. */
    public static function normalizar(string $url): ?string
    {
        $url = rtrim(trim($url), '/');
        $url = preg_replace('#/salud$#', '', $url) ?? $url;
        $p = parse_url($url);
        if ($p === false || !in_array($p['scheme'] ?? '', ['http', 'https'], true) || ($p['host'] ?? '') === '' || isset($p['user']) || isset($p['query']) || isset($p['fragment'])) {
            return null;
        }
        $host = strtolower($p['host']);
        if (Config::get('app.env', 'production') === 'production' && (in_array($host, ['localhost', '0.0.0.0'], true)
            || (filter_var($host, FILTER_VALIDATE_IP) !== false && filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false))) {
            return null; // no se consultan direcciones internas desde producción
        }
        return $p['scheme'] . '://' . $host . (isset($p['port']) ? ':' . $p['port'] : '') . ($p['path'] ?? '');
    }

    /** @return array{ok:bool,error?:string} */
    public function guardar(string $nombre, string $url, string $notas): array
    {
        $nombre = trim($nombre);
        $u = self::normalizar($url);
        if ($nombre === '' || mb_strlen($nombre) > 80) {
            return ['ok' => false, 'error' => 'Escribí el nombre de la instalación (hasta 80 letras).'];
        }
        if ($u === null || mb_strlen($u) > 200) {
            return ['ok' => false, 'error' => 'La dirección no es válida: tiene que ser algo como https://facturador.cliente.com.ar'];
        }
        try {
            Db::pdo()->prepare('INSERT INTO instalaciones (nombre, url, notas) VALUES (?, ?, ?)')->execute([$nombre, $u, mb_substr(trim($notas), 0, 200)]);
        } catch (\PDOException $e) {
            if ($e->getCode() === '23000') {
                return ['ok' => false, 'error' => 'Esa dirección ya está en la lista.'];
            }
            throw $e;
        }
        return ['ok' => true];
    }

    public function borrar(int $id): void
    {
        Db::pdo()->prepare('DELETE FROM instalaciones WHERE id = ?')->execute([$id]);
    }

    /** Le pregunta a cada instalación (o a una) su /salud y guarda lo que contestó. @return int cuántas se revisaron */
    public function revisar(?int $id = null): int
    {
        $pdo = Db::pdo();
        $filas = $id === null ? $this->listar() : array_values(array_filter($this->listar(), static fn ($f) => (int) $f['id'] === $id));
        $upd = $pdo->prepare('UPDATE instalaciones SET revisada_en = NOW(), ok = ?, version = ?, pendientes = ?, licencia_dias = ?, migracion_error = ?, error = ? WHERE id = ?');
        foreach ($filas as $f) {
            [$ok, $v, $pend, $lic, $merr, $error] = $this->preguntar((string) $f['url']);
            $upd->execute([$ok ? 1 : 0, $v, $pend, $lic, $merr === null ? null : (int) $merr, $error, $f['id']]);
        }
        return count($filas);
    }

    /** @return array{0:bool,1:?string,2:?int,3:?int,4:?bool,5:?string} ok, versión, pendientes, días de licencia, error de migración, error */
    private function preguntar(string $url): array
    {
        try {
            $r = self::$transporte !== null ? (self::$transporte)($url . '/salud') : $this->curl($url . '/salud');
        } catch (\Throwable $e) {
            return [false, null, null, null, null, mb_substr($e->getMessage(), 0, 200)];
        }
        $d = json_decode($r['body'], true);
        if (!is_array($d) || !isset($d['version'])) {
            return [false, null, null, null, null, 'No contestó como FacturaWeb (código ' . $r['status'] . ').'];
        }
        return [(bool) ($d['ok'] ?? false) && $r['status'] === 200, mb_substr((string) $d['version'], 0, 20),
            isset($d['cambios_de_base_pendientes']) ? (int) $d['cambios_de_base_pendientes'] : null, isset($d['licencia_dias']) ? (int) $d['licencia_dias'] : null,
            isset($d['migracion_error']) ? (bool) $d['migracion_error'] : null, ($d['ok'] ?? false) ? null : 'La base no responde.'];
    }

    /** @return array{status:int,body:string} */
    private function curl(string $url): array
    {
        if (!function_exists('curl_init')) {
            throw new \RuntimeException('Este servidor no tiene curl.');
        }
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_TIMEOUT => 8, CURLOPT_FOLLOWLOCATION => false, CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS]);
        $body = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        if ($body === false) {
            throw new \RuntimeException('No se pudo conectar' . ($err !== '' ? ' (' . $err . ')' : '') . '.');
        }
        return ['status' => $status, 'body' => mb_substr((string) $body, 0, 4000)];
    }

    /** La versión más nueva entre las instalaciones revisadas y esta, para marcar las desactualizadas. */
    public static function versionMasNueva(array $filas, string $propia): string
    {
        $v = [$propia];
        foreach ($filas as $f) {
            if ($f['version'] !== null && $f['version'] !== '') {
                $v[] = (string) $f['version'];
            }
        }
        usort($v, 'version_compare');
        return (string) end($v);
    }
}
