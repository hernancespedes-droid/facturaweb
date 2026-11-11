<?php
declare(strict_types=1);

namespace Tests;

/**
 * Escribe tablas DBF de Visual FoxPro chiquitas para las pruebas del migrador (así no hace falta subir datos
 * reales de clientes al repositorio). Tipos: C, N, D, T, L, I, M (memo en .fpt) y V (varchar con _NullFlags).
 */
final class DbfFalso
{
    /**
     * @param array<int,array{0:string,1:string,2?:int,3?:int,4?:bool}> $campos nombre, tipo, largo, decimales, acepta nulos
     * @param array<int,array<string,mixed>> $filas valores por nombre de campo ('_borrado' => true para marcarla borrada)
     */
    public static function escribir(string $archivo, array $campos, array $filas): void
    {
        $defs = [];
        $desde = 1;
        $bit = 0;
        $hayNulos = false;
        foreach ($campos as $c) {
            [$nombre, $tipo] = $c;
            $largo = match ($tipo) { 'D', 'T' => 8, 'L' => 1, 'I', 'M' => 4, default => $c[2] ?? 10 };
            $d = ['nombre' => $nombre, 'tipo' => $tipo, 'largo' => $largo, 'dec' => $c[3] ?? 0, 'desde' => $desde, 'nulo' => null, 'varlen' => null];
            if ($tipo === 'V') {
                $d['varlen'] = $bit++;
            }
            if (!empty($c[4])) {
                $d['nulo'] = $bit++;
                $hayNulos = true;
            }
            $hayNulos = $hayNulos || $tipo === 'V';
            $defs[] = $d;
            $desde += $largo;
        }
        if ($hayNulos) {
            $defs[] = ['nombre' => '_NullFlags', 'tipo' => '0', 'largo' => max(1, (int) ceil($bit / 8)), 'dec' => 0, 'desde' => $desde, 'nulo' => null, 'varlen' => null];
            $desde += $defs[count($defs) - 1]['largo'];
        }
        $largoRegistro = $desde;
        $largoEncabezado = 32 + 32 * count($defs) + 1 + 263;

        $memo = '';
        $bloques = 8; // el encabezado del .fpt ocupa 512 bytes = 8 bloques de 64
        $cuerpo = '';
        foreach ($filas as $f) {
            $reg = !empty($f['_borrado']) ? '*' : ' ';
            $flags = 0;
            foreach ($defs as $d) {
                if ($d['tipo'] === '0') {
                    continue;
                }
                $v = $f[$d['nombre']] ?? null;
                if ($v === null && $d['nulo'] !== null) {
                    $flags |= 1 << $d['nulo'];
                    $reg .= str_repeat("\0", $d['largo']);
                    continue;
                }
                $reg .= match ($d['tipo']) {
                    'C' => str_pad(substr((string) mb_convert_encoding((string) $v, 'Windows-1252', 'UTF-8'), 0, $d['largo']), $d['largo']),
                    'N' => str_pad($v === null ? '' : number_format((float) $v, $d['dec'], '.', ''), $d['largo'], ' ', STR_PAD_LEFT),
                    'D' => $v === null ? '        ' : str_replace('-', '', (string) $v),
                    'T' => $v === null ? str_repeat("\0", 8) : pack('l2', intdiv(strtotime($v . ' UTC'), 86400) + 2440588, (strtotime($v . ' UTC') % 86400) * 1000),
                    'L' => $v === null ? ' ' : ($v ? 'T' : 'F'),
                    'I' => pack('l', (int) $v),
                    'V' => (static function () use ($v, $d, &$flags): string {
                        $s = (string) $v;
                        if (strlen($s) < $d['largo']) {
                            $flags |= 1 << $d['varlen'];
                            return str_pad($s, $d['largo'] - 1, "\0") . chr(strlen($s));
                        }
                        return substr($s, 0, $d['largo']);
                    })(),
                    'M' => (static function () use ($v, &$memo, &$bloques): string {
                        if ($v === null || $v === '') {
                            return pack('V', 0);
                        }
                        $datos = pack('N', 1) . pack('N', strlen((string) $v)) . $v;
                        $n = (int) ceil(strlen($datos) / 64);
                        $memo .= str_pad($datos, $n * 64, "\0");
                        $bloque = $bloques;
                        $bloques += $n;
                        return pack('V', $bloque);
                    })(),
                };
            }
            if ($hayNulos) {
                $reg .= str_pad(pack('V', $flags), $defs[count($defs) - 1]['largo'], "\0") === '' ? '' : substr(pack('V', $flags), 0, $defs[count($defs) - 1]['largo']);
            }
            $cuerpo .= $reg;
        }

        $h = chr(0x30) . chr(126) . chr(11) . chr(11) . pack('V', count($filas)) . pack('v', $largoEncabezado) . pack('v', $largoRegistro)
            . str_repeat("\0", 16) . chr(array_filter($defs, static fn ($d) => $d['tipo'] === 'M') !== [] ? 0x02 : 0) . chr(0x03) . "\0\0";
        foreach ($defs as $d) {
            $flags = ($d['tipo'] === '0' ? 0x05 : 0) | ($d['nulo'] !== null ? 0x02 : 0);
            $h .= str_pad($d['nombre'], 11, "\0") . $d['tipo'] . pack('V', $d['desde']) . chr($d['largo']) . chr($d['dec']) . chr($flags) . str_repeat("\0", 13);
        }
        $h .= "\r" . str_repeat("\0", 263);
        file_put_contents($archivo, $h . $cuerpo . "\x1A");
        if ($memo !== '') {
            $base = substr($archivo, 0, (int) strrpos($archivo, '.'));
            $ext = str_ends_with(strtolower($archivo), '.dbc') ? '.dct' : '.fpt';
            file_put_contents($base . $ext, pack('N', $bloques) . "\0\0" . pack('n', 64) . str_repeat("\0", 504) . $memo);
        }
    }

    /** datos.dbc con los nombres largos de cada tabla. @param array<string,string[]> $tablas */
    public static function dbc(string $archivo, array $tablas): void
    {
        $filas = [];
        $id = 1;
        foreach ($tablas as $tabla => $campos) {
            $padre = $id++;
            $filas[] = ['OBJECTID' => $padre, 'PARENTID' => 1, 'OBJECTTYPE' => 'Table', 'OBJECTNAME' => $tabla];
            foreach ($campos as $c) {
                $filas[] = ['OBJECTID' => $id++, 'PARENTID' => $padre, 'OBJECTTYPE' => 'Field', 'OBJECTNAME' => $c];
            }
        }
        self::escribir($archivo, [['OBJECTID', 'I'], ['PARENTID', 'I'], ['OBJECTTYPE', 'C', 10], ['OBJECTNAME', 'C', 128]], $filas);
    }
}
