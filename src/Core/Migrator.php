<?php
declare(strict_types=1);

namespace App\Core;

use PDO;
use RuntimeException;

/**
 * Aplica en orden los archivos database/migrations/NNNN_descripcion.sql que todavía
 * no se aplicaron y lo registra en la tabla schema_migrations.
 *
 * Reglas para escribir migraciones (MySQL hace commit automático en cada DDL, no hay
 * "deshacer"): una migración chica por archivo, idempotente cuando se pueda
 * (CREATE TABLE IF NOT EXISTS) y sin DELIMITER/triggers.
 */
final class Migrator
{
    public function __construct(
        private PDO $pdo,
        private string $dir,
        private string $table = 'schema_migrations'
    ) {
    }

    /** @return string[] nombres de los archivos aplicados en esta corrida */
    public function run(): array
    {
        $this->ensureTable();
        $done = $this->appliedNames();
        $applied = [];

        foreach ($this->files() as $file) {
            $name = basename($file);
            if (in_array($name, $done, true)) {
                continue;
            }
            foreach (self::splitStatements((string) file_get_contents($file)) as $stmt) {
                try {
                    $this->pdo->exec($stmt);
                } catch (\PDOException $e) {
                    throw new RuntimeException(
                        "Falló la migración {$name}: " . $e->getMessage() . ' | Sentencia: ' . mb_substr($stmt, 0, 200),
                        0,
                        $e
                    );
                }
            }
            $ins = $this->pdo->prepare("INSERT INTO `{$this->table}` (filename) VALUES (?)");
            $ins->execute([$name]);
            $applied[] = $name;
        }
        return $applied;
    }

    /** @return array<string,bool> nombre de archivo => ya aplicada */
    public function status(): array
    {
        $this->ensureTable();
        $done = $this->appliedNames();
        $out = [];
        foreach ($this->files() as $file) {
            $out[basename($file)] = in_array(basename($file), $done, true);
        }
        return $out;
    }

    /**
     * Separa un archivo .sql en sentencias respetando comillas y comentarios.
     *
     * @return string[]
     */
    public static function splitStatements(string $sql): array
    {
        $out = [];
        $buf = '';
        $len = strlen($sql);
        $quote = null;
        $i = 0;

        while ($i < $len) {
            $c = $sql[$i];
            $n = $sql[$i + 1] ?? '';

            if ($quote !== null) {
                $buf .= $c;
                if ($c === '\\' && $quote !== '`') {
                    $buf .= $n;
                    $i += 2;
                    continue;
                }
                if ($c === $quote) {
                    if ($n === $quote) {
                        $buf .= $n;
                        $i += 2;
                        continue;
                    }
                    $quote = null;
                }
                $i++;
                continue;
            }

            if ($c === "'" || $c === '"' || $c === '`') {
                $quote = $c;
                $buf .= $c;
                $i++;
                continue;
            }
            if (($c === '-' && $n === '-' && ctype_space($sql[$i + 2] ?? ' ')) || $c === '#') {
                while ($i < $len && $sql[$i] !== "\n") {
                    $i++;
                }
                continue;
            }
            if ($c === '/' && $n === '*') {
                $end = strpos($sql, '*/', $i + 2);
                $i = $end === false ? $len : $end + 2;
                continue;
            }
            if ($c === ';') {
                $s = trim($buf);
                if ($s !== '') {
                    $out[] = $s;
                }
                $buf = '';
                $i++;
                continue;
            }
            $buf .= $c;
            $i++;
        }

        $s = trim($buf);
        if ($s !== '') {
            $out[] = $s;
        }
        return $out;
    }

    private function ensureTable(): void
    {
        $this->pdo->exec(
            "CREATE TABLE IF NOT EXISTS `{$this->table}` (
                filename   VARCHAR(190) NOT NULL PRIMARY KEY,
                applied_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
    }

    /** @return string[] */
    private function appliedNames(): array
    {
        return $this->pdo->query("SELECT filename FROM `{$this->table}`")->fetchAll(PDO::FETCH_COLUMN);
    }

    /** @return string[] */
    private function files(): array
    {
        $files = glob(rtrim($this->dir, '/') . '/*.sql') ?: [];
        sort($files, SORT_STRING);
        return $files;
    }
}
