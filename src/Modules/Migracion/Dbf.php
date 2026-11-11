<?php
declare(strict_types=1);

namespace App\Modules\Migracion;

/**
 * Lector de tablas DBF de Visual FoxPro (y dBase), sin extensiones de PHP: lee el encabezado, los campos y los
 * registros uno por uno (sirve para tablas grandes), con memos (.fpt / .dct), campos nulos (_NullFlags),
 * varchar, fechas (D), fecha-hora (T), lógicos, enteros, dobles y moneda. Los registros borrados se saltean.
 * El texto se pasa a UTF-8 según la página de códigos del encabezado (en O&S, Windows-1252).
 */
final class Dbf
{
    /** Página de códigos del byte 29 del encabezado => codificación de mbstring. */
    private const CODEPAGES = [0x01 => 'CP437', 0x02 => 'CP850', 0x03 => 'Windows-1252', 0x57 => 'Windows-1252',
        0x64 => 'CP852', 0x65 => 'CP866', 0xC8 => 'Windows-1250', 0xC9 => 'Windows-1251'];

    /** @var resource */
    private $fh;
    /** @var resource|null */
    private $memo = null;
    private int $memoBloque = 64;
    public readonly int $registros;
    private int $largoEncabezado;
    private int $largoRegistro;
    public readonly string $codificacion;
    /** @var array<int,array{nombre:string,tipo:string,largo:int,dec:int,desde:int,nulo:?int,varlen:?int}> */
    public readonly array $campos;
    private ?array $nullFlags = null;

    public function __construct(public readonly string $archivo)
    {
        $fh = @fopen($archivo, 'rb');
        if ($fh === false) {
            throw new \RuntimeException('No se pudo abrir ' . $archivo);
        }
        $this->fh = $fh;
        $h = (string) fread($fh, 32);
        if (strlen($h) < 32) {
            throw new \RuntimeException('No es una tabla DBF: ' . basename($archivo));
        }
        $this->registros = unpack('V', substr($h, 4, 4))[1];
        $this->largoEncabezado = unpack('v', substr($h, 8, 2))[1];
        $this->largoRegistro = unpack('v', substr($h, 10, 2))[1];
        $this->codificacion = self::CODEPAGES[ord($h[29])] ?? 'Windows-1252';

        $campos = [];
        $desde = 1; // byte 0: marca de borrado
        $bit = 0;
        while (true) {
            $d = (string) fread($fh, 32);
            if ($d === '' || $d[0] === "\r" || strlen($d) < 32) {
                break;
            }
            $nombre = rtrim(substr($d, 0, 11), "\0 ");
            $tipo = strtoupper($d[11]);
            $largo = ord($d[16]);
            $flags = ord($d[18]);
            $campo = ['nombre' => $nombre, 'tipo' => $tipo, 'largo' => $largo, 'dec' => ord($d[17]), 'desde' => $desde, 'nulo' => null, 'varlen' => null];
            if ($tipo !== '0') {
                // _NullFlags: un bit por cada varchar/varbinary (largo variable) y uno por cada campo que acepta nulos.
                if ($tipo === 'V' || $tipo === 'Q') {
                    $campo['varlen'] = $bit++;
                }
                if (($flags & 0x02) !== 0) {
                    $campo['nulo'] = $bit++;
                }
            }
            $campos[] = $campo;
            $desde += $largo;
        }
        $this->campos = $campos;
        foreach ($campos as $c) {
            if ($c['tipo'] === '0') {
                $this->nullFlags = $c;
            }
        }
        if (array_filter($campos, static fn ($c) => in_array($c['tipo'], ['M', 'G', 'W'], true)) !== []) {
            $this->abrirMemo();
        }
    }

    public function __destruct()
    {
        if (is_resource($this->fh)) {
            fclose($this->fh);
        }
        if (is_resource($this->memo)) {
            fclose($this->memo);
        }
    }

    /** Nombres de los campos de datos (sin los de sistema como _NullFlags). @return string[] */
    public function nombres(): array
    {
        return array_values(array_map(static fn ($c) => $c['nombre'], array_filter($this->campos, static fn ($c) => $c['tipo'] !== '0')));
    }

    /**
     * Registros no borrados, como [nombre del campo => valor]. Texto: string sin los espacios del final;
     * N/F/I/B/Y: int o float (null si está en blanco); D: 'Y-m-d' o null; T: 'Y-m-d H:i:s' o null; L: bool o null.
     *
     * @return \Generator<int,array<string,mixed>>
     */
    public function filas(): \Generator
    {
        fseek($this->fh, $this->largoEncabezado);
        for ($i = 0; $i < $this->registros; $i++) {
            $r = fread($this->fh, $this->largoRegistro);
            if ($r === false || strlen($r) < $this->largoRegistro) {
                break;
            }
            if ($r[0] === '*') {
                continue;
            }
            $flags = $this->nullFlags === null ? '' : substr($r, $this->nullFlags['desde'], $this->nullFlags['largo']);
            $fila = [];
            foreach ($this->campos as $c) {
                if ($c['tipo'] === '0') {
                    continue;
                }
                if ($c['nulo'] !== null && self::bit($flags, $c['nulo'])) {
                    $fila[$c['nombre']] = null;
                    continue;
                }
                $fila[$c['nombre']] = $this->valor($c, substr($r, $c['desde'], $c['largo']), $flags);
            }
            yield $fila;
        }
    }

    private static function bit(string $bytes, int $n): bool
    {
        $b = intdiv($n, 8);
        return $b < strlen($bytes) && (ord($bytes[$b]) >> ($n % 8) & 1) === 1;
    }

    private function texto(string $s): string
    {
        $s = rtrim($s, "\0 ");
        return $this->codificacion === 'UTF-8' ? $s : (string) mb_convert_encoding($s, 'UTF-8', $this->codificacion);
    }

    /** @param array<string,mixed> $c */
    private function valor(array $c, string $raw, string $flags): mixed
    {
        switch ($c['tipo']) {
            case 'C':
                return $this->texto($raw);
            case 'V':
                if ($c['varlen'] !== null && self::bit($flags, $c['varlen'])) {
                    $raw = substr($raw, 0, ord($raw[strlen($raw) - 1]));
                }
                return $this->texto($raw);
            case 'N':
            case 'F':
                $t = trim($raw);
                if ($t === '' || $t === '.' || str_contains($t, '*')) {
                    return null;
                }
                return ($c['dec'] > 0 || str_contains($t, '.')) ? (float) $t : (int) $t;
            case 'I':
                return unpack('l', $raw)[1];
            case 'B':
                return unpack('e', $raw)[1];
            case 'Y':
                return unpack('q', $raw)[1] / 10000;
            case 'L':
                $t = strtoupper(trim($raw));
                return $t === '' || $t === '?' ? null : in_array($t, ['T', 'Y', 'S'], true);
            case 'D':
                $t = trim($raw);
                return preg_match('/^\d{8}$/', $t) === 1 && $t !== '00000000' && checkdate((int) substr($t, 4, 2), (int) substr($t, 6, 2), (int) substr($t, 0, 4))
                    ? substr($t, 0, 4) . '-' . substr($t, 4, 2) . '-' . substr($t, 6, 2) : null;
            case 'T':
                [$dia, $ms] = array_values(unpack('l2', $raw));
                if ($dia === 0) {
                    return null;
                }
                // Día juliano: 2440588 = 01/01/1970. Los milisegundos se redondean al segundo.
                $seg = (int) round($ms / 1000);
                return gmdate('Y-m-d H:i:s', ($dia - 2440588) * 86400 + $seg);
            case 'M':
            case 'G':
            case 'W':
                $bloque = $c['largo'] === 4 ? unpack('V', $raw)[1] : (int) trim($raw);
                return $c['tipo'] === 'M' ? $this->leerMemo($bloque) : null;
            default:
                return $this->texto($raw);
        }
    }

    private function abrirMemo(): void
    {
        $base = substr($this->archivo, 0, (int) strrpos($this->archivo, '.'));
        $ext = strtolower(pathinfo($this->archivo, PATHINFO_EXTENSION)) === 'dbc' ? 'dct' : 'fpt';
        $memo = self::buscar(dirname($this->archivo), basename($base) . '.' . $ext);
        if ($memo === null) {
            return; // sin memo: esos campos quedan vacíos
        }
        $fh = fopen($memo, 'rb');
        if ($fh === false) {
            return;
        }
        $h = (string) fread($fh, 8);
        $this->memoBloque = max(1, unpack('n', substr($h, 6, 2))[1] ?: 64);
        $this->memo = $fh;
    }

    private function leerMemo(int $bloque): ?string
    {
        if ($bloque <= 0 || $this->memo === null) {
            return null;
        }
        fseek($this->memo, $bloque * $this->memoBloque);
        $h = (string) fread($this->memo, 8);
        if (strlen($h) < 8) {
            return null;
        }
        $largo = unpack('N', substr($h, 4, 4))[1];
        if ($largo <= 0 || $largo > 16 * 1048576) {
            return null;
        }
        return $this->texto((string) fread($this->memo, $largo));
    }

    /** Busca un archivo en la carpeta sin importar mayúsculas (los DBF vienen como BANCOS.DBF o forma_de_pagos.dbf). */
    public static function buscar(string $dir, string $nombre): ?string
    {
        if (is_file($dir . '/' . $nombre)) {
            return $dir . '/' . $nombre;
        }
        foreach (scandir($dir) ?: [] as $f) {
            if (strcasecmp($f, $nombre) === 0) {
                return $dir . '/' . $f;
            }
        }
        return null;
    }
}
