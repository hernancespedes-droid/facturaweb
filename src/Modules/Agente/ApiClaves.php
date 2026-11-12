<?php
declare(strict_types=1);

namespace App\Modules\Agente;

use App\Core\Db;
use PDO;

/** Claves de API del agente. Se guarda solo el hash SHA-256 (la clave es larga y al azar: no hace falta un hash lento). */
final class ApiClaves
{
    public static function crear(string $nombre, ?int $usuarioId): string
    {
        $clave = 'fwk_' . bin2hex(random_bytes(24));
        Db::pdo()->prepare('INSERT INTO api_claves (nombre, clave_hash, prefijo, creada_por) VALUES (?, ?, ?, ?)')
            ->execute([mb_substr(trim($nombre), 0, 60), hash('sha256', $clave), substr($clave, 0, 10), $usuarioId]);
        return $clave;
    }

    /** @return array<string,mixed>|null la clave activa a la que corresponde el encabezado Authorization: Bearer ... */
    public static function validar(?string $encabezado, ?string $ip = null): ?array
    {
        if ($encabezado === null || preg_match('/^Bearer\s+(fwk_[a-f0-9]{48})$/', trim($encabezado), $m) !== 1) {
            return null;
        }
        $st = Db::pdo()->prepare('SELECT * FROM api_claves WHERE clave_hash = ? AND activa = 1');
        $st->execute([hash('sha256', $m[1])]);
        $f = $st->fetch(PDO::FETCH_ASSOC);
        if ($f === false) {
            return null;
        }
        Db::pdo()->prepare('UPDATE api_claves SET ultimo_uso = NOW(), ultimo_ip = ? WHERE id = ?')->execute([$ip !== null ? mb_substr($ip, 0, 45) : null, $f['id']]);
        return $f;
    }

    public static function revocar(int $id): void
    {
        Db::pdo()->prepare('UPDATE api_claves SET activa = 0 WHERE id = ?')->execute([$id]);
    }

    /** @return array<int,array<string,mixed>> */
    public static function listar(): array
    {
        return Db::pdo()->query('SELECT * FROM api_claves ORDER BY id DESC')->fetchAll();
    }
}
