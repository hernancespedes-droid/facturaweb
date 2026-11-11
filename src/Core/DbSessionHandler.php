<?php
declare(strict_types=1);

namespace App\Core;

use PDO;

/**
 * Guarda las sesiones de PHP en la tabla `sesiones`. En hostings compartidos (DonWeb)
 * PHP a veces no puede escribir sesiones en disco; la base siempre está disponible.
 */
final class DbSessionHandler implements \SessionHandlerInterface, \SessionUpdateTimestampHandlerInterface
{
    public function __construct(private PDO $pdo)
    {
    }

    public function open(string $path, string $name): bool
    {
        return true;
    }

    public function close(): bool
    {
        return true;
    }

    public function read(string $id): string|false
    {
        $st = $this->pdo->prepare('SELECT datos FROM sesiones WHERE id = ?');
        $st->execute([$id]);
        $datos = $st->fetchColumn();
        return $datos === false ? '' : (string) $datos;
    }

    public function write(string $id, string $data): bool
    {
        $this->pdo->prepare(
            'INSERT INTO sesiones (id, datos, actualizado) VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE datos = ?, actualizado = ?'
        )->execute([$id, $data, time(), $data, time()]);
        return true;
    }

    public function destroy(string $id): bool
    {
        $this->pdo->prepare('DELETE FROM sesiones WHERE id = ?')->execute([$id]);
        return true;
    }

    public function gc(int $max_lifetime): int|false
    {
        $st = $this->pdo->prepare('DELETE FROM sesiones WHERE actualizado < ?');
        $st->execute([time() - $max_lifetime]);
        return $st->rowCount();
    }

    /** Con session.use_strict_mode: solo se aceptan ids que existen (contra fijación de sesión). */
    public function validateId(string $id): bool
    {
        $st = $this->pdo->prepare('SELECT 1 FROM sesiones WHERE id = ?');
        $st->execute([$id]);
        return $st->fetchColumn() !== false;
    }

    public function updateTimestamp(string $id, string $data): bool
    {
        $this->pdo->prepare('UPDATE sesiones SET actualizado = ? WHERE id = ?')->execute([time(), $id]);
        return true;
    }
}
