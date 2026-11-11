<?php
declare(strict_types=1);

namespace App\Core;

use InvalidArgumentException;

/**
 * Código QR propio, sin librerías (no hay Composer en el sandbox): modo byte, corrección de errores M (~15 %),
 * versiones 1 a 10 (hasta 213 bytes: alcanza de sobra para una URL). Sigue ISO/IEC 18004: Reed-Solomon sobre
 * GF(256), bloques intercalados, patrones de posición, alineación y sincronismo, información de formato y de
 * versión, y la máscara con menor penalidad. Se dibuja en SVG (nítido al imprimir).
 */
final class Qr
{
    /** Por versión (1..10), nivel M: códigos de corrección por bloque y cantidad de bloques. */
    private const EC_POR_BLOQUE = [1 => 10, 16, 26, 18, 24, 16, 18, 22, 22, 26];
    private const BLOQUES = [1 => 1, 1, 1, 2, 2, 4, 4, 4, 5, 5];
    private const NIVEL_M = 0; // bits del nivel M en la información de formato

    /** @var array<int,array<int,bool>> */
    private array $m = [];
    /** @var array<int,array<int,bool>> */
    private array $fijo = [];
    private int $tam;

    private function __construct(private int $version)
    {
        $this->tam = $version * 4 + 17;
        for ($y = 0; $y < $this->tam; $y++) {
            $this->m[$y] = array_fill(0, $this->tam, false);
            $this->fijo[$y] = array_fill(0, $this->tam, false);
        }
    }

    /** Matriz de módulos (true = negro), sin el margen. @return array<int,array<int,bool>> */
    public static function matriz(string $texto): array
    {
        $bytes = array_values(unpack('C*', $texto) ?: []);
        $version = null;
        for ($v = 1; $v <= 10; $v++) {
            $bitsCuenta = $v <= 9 ? 8 : 16;
            if (4 + $bitsCuenta + 8 * count($bytes) <= self::datosPorVersion($v) * 8) {
                $version = $v;
                break;
            }
        }
        if ($version === null) {
            throw new InvalidArgumentException('Texto demasiado largo para el QR (máximo 213 bytes).');
        }
        $q = new self($version);
        $q->patrones();
        $q->ubicarDatos($q->codigos($bytes));
        $mejor = null;
        $menor = PHP_INT_MAX;
        for ($mascara = 0; $mascara < 8; $mascara++) {
            $copia = clone $q;
            $copia->aplicarMascara($mascara);
            $copia->formato($mascara);
            $p = $copia->penalidad();
            if ($p < $menor) {
                [$menor, $mejor] = [$p, $copia];
            }
        }
        return $mejor->m;
    }

    /** SVG del QR con 4 módulos de margen. $px = tamaño de cada módulo en la pantalla. */
    public static function svg(string $texto, int $px = 4, string $titulo = ''): string
    {
        $m = self::matriz($texto);
        $n = count($m) + 8;
        $d = '';
        foreach ($m as $y => $fila) {
            foreach ($fila as $x => $negro) {
                if ($negro) {
                    $d .= 'M' . ($x + 4) . ' ' . ($y + 4) . 'h1v1h-1z';
                }
            }
        }
        $lado = $n * $px;
        return '<svg xmlns="http://www.w3.org/2000/svg" class="qr" viewBox="0 0 ' . $n . ' ' . $n . '" width="' . $lado . '" height="' . $lado
            . '" shape-rendering="crispEdges" role="img"' . ($titulo !== '' ? ' aria-label="' . htmlspecialchars($titulo, ENT_QUOTES) . '"' : '') . '>'
            . '<rect width="100%" height="100%" fill="#fff"/><path fill="#000" d="' . $d . '"/></svg>';
    }

    // ------------------------------------------------------------------ datos

    private static function modulosDatos(int $v): int
    {
        $r = (16 * $v + 128) * $v + 64;
        if ($v >= 2) {
            $alin = intdiv($v, 7) + 2;
            $r -= (25 * $alin - 10) * $alin - 55;
            if ($v >= 7) {
                $r -= 36;
            }
        }
        return $r;
    }

    private static function datosPorVersion(int $v): int
    {
        return intdiv(self::modulosDatos($v), 8) - self::EC_POR_BLOQUE[$v] * self::BLOQUES[$v];
    }

    /** Codewords finales: datos (con encabezado, terminador y relleno) en bloques, más su corrección, intercalados. @return int[] */
    private function codigos(array $bytes): array
    {
        $v = $this->version;
        $bits = [];
        $poner = static function (int $valor, int $n) use (&$bits): void {
            for ($i = $n - 1; $i >= 0; $i--) {
                $bits[] = ($valor >> $i) & 1;
            }
        };
        $poner(0b0100, 4); // modo byte
        $poner(count($bytes), $v <= 9 ? 8 : 16);
        foreach ($bytes as $b) {
            $poner($b, 8);
        }
        $capacidad = self::datosPorVersion($v) * 8;
        $poner(0, min(4, $capacidad - count($bits)));
        $poner(0, (8 - count($bits) % 8) % 8);
        for ($relleno = 0xEC; count($bits) < $capacidad; $relleno ^= 0xEC ^ 0x11) {
            $poner($relleno, 8);
        }
        $datos = [];
        foreach (array_chunk($bits, 8) as $byte) {
            $datos[] = (int) bindec(implode('', $byte));
        }

        $nBloques = self::BLOQUES[$v];
        $ec = self::EC_POR_BLOQUE[$v];
        $crudos = intdiv(self::modulosDatos($v), 8);
        $cortos = $nBloques - $crudos % $nBloques;
        $largoCorto = intdiv($crudos, $nBloques);
        $divisor = self::divisorRs($ec);
        $bloques = [];
        for ($i = 0, $k = 0; $i < $nBloques; $i++) {
            $n = $largoCorto - $ec + ($i < $cortos ? 0 : 1);
            $dat = array_slice($datos, $k, $n);
            $k += $n;
            $bloques[] = [$dat, self::restoRs($dat, $divisor)];
        }
        $salida = [];
        $maxDatos = $largoCorto - $ec + 1;
        for ($i = 0; $i < $maxDatos; $i++) {
            foreach ($bloques as [$dat]) {
                if (isset($dat[$i])) {
                    $salida[] = $dat[$i];
                }
            }
        }
        for ($i = 0; $i < $ec; $i++) {
            foreach ($bloques as [, $cor]) {
                $salida[] = $cor[$i];
            }
        }
        return $salida;
    }

    private static function mul(int $x, int $y): int
    {
        $z = 0;
        for ($i = 7; $i >= 0; $i--) {
            $z = (($z << 1) ^ (($z >> 7) * 0x11D)) & 0xFF;
            $z ^= (($y >> $i) & 1) * $x;
        }
        return $z;
    }

    /** @return int[] */
    private static function divisorRs(int $grado): array
    {
        $r = array_fill(0, $grado, 0);
        $r[$grado - 1] = 1;
        $raiz = 1;
        for ($i = 0; $i < $grado; $i++) {
            for ($j = 0; $j < $grado; $j++) {
                $r[$j] = self::mul($r[$j], $raiz);
                if ($j + 1 < $grado) {
                    $r[$j] ^= $r[$j + 1];
                }
            }
            $raiz = self::mul($raiz, 0x02);
        }
        return $r;
    }

    /** @param int[] $datos @param int[] $divisor @return int[] */
    private static function restoRs(array $datos, array $divisor): array
    {
        $r = array_fill(0, count($divisor), 0);
        foreach ($datos as $b) {
            $f = $b ^ array_shift($r);
            $r[] = 0;
            foreach ($divisor as $i => $coef) {
                $r[$i] ^= self::mul($coef, $f);
            }
        }
        return $r;
    }

    // ------------------------------------------------------------------ dibujo

    private function fijar(int $x, int $y, bool $negro): void
    {
        $this->m[$y][$x] = $negro;
        $this->fijo[$y][$x] = true;
    }

    private function patrones(): void
    {
        $t = $this->tam;
        for ($i = 0; $i < $t; $i++) { // sincronismo
            $this->fijar(6, $i, $i % 2 === 0);
            $this->fijar($i, 6, $i % 2 === 0);
        }
        foreach ([[3, 3], [$t - 4, 3], [3, $t - 4]] as [$cx, $cy]) { // posición (con su separador)
            for ($dy = -4; $dy <= 4; $dy++) {
                for ($dx = -4; $dx <= 4; $dx++) {
                    $x = $cx + $dx;
                    $y = $cy + $dy;
                    if ($x >= 0 && $x < $t && $y >= 0 && $y < $t) {
                        $dist = max(abs($dx), abs($dy));
                        $this->fijar($x, $y, $dist !== 2 && $dist !== 4);
                    }
                }
            }
        }
        $pos = $this->posAlineacion();
        $n = count($pos);
        for ($i = 0; $i < $n; $i++) { // alineación (menos donde están los de posición)
            for ($j = 0; $j < $n; $j++) {
                if (($i === 0 && $j === 0) || ($i === 0 && $j === $n - 1) || ($i === $n - 1 && $j === 0)) {
                    continue;
                }
                for ($dy = -2; $dy <= 2; $dy++) {
                    for ($dx = -2; $dx <= 2; $dx++) {
                        $this->fijar($pos[$i] + $dx, $pos[$j] + $dy, max(abs($dx), abs($dy)) !== 1);
                    }
                }
            }
        }
        $this->formato(0); // reserva el lugar (se reescribe con la máscara elegida)
        if ($this->version >= 7) { // información de versión
            $rem = $this->version;
            for ($i = 0; $i < 12; $i++) {
                $rem = ($rem << 1) ^ (($rem >> 11) * 0x1F25);
            }
            $bits = ($this->version << 12) | $rem;
            for ($i = 0; $i < 18; $i++) {
                $b = (($bits >> $i) & 1) === 1;
                $a = $t - 11 + $i % 3;
                $c = intdiv($i, 3);
                $this->fijar($a, $c, $b);
                $this->fijar($c, $a, $b);
            }
        }
    }

    /** @return int[] */
    private function posAlineacion(): array
    {
        if ($this->version === 1) {
            return [];
        }
        $n = intdiv($this->version, 7) + 2;
        $paso = (int) ceil(($this->version * 4 + 4) / ($n * 2 - 2)) * 2;
        $r = [6];
        for ($p = $this->tam - 7; count($r) < $n; $p -= $paso) {
            array_splice($r, 1, 0, [$p]);
        }
        return $r;
    }

    private function formato(int $mascara): void
    {
        $dato = (self::NIVEL_M << 3) | $mascara;
        $rem = $dato;
        for ($i = 0; $i < 10; $i++) {
            $rem = ($rem << 1) ^ (($rem >> 9) * 0x537);
        }
        $bits = (($dato << 10) | $rem) ^ 0x5412;
        $b = static fn (int $i) => (($bits >> $i) & 1) === 1;
        $t = $this->tam;
        for ($i = 0; $i <= 5; $i++) {
            $this->fijar(8, $i, $b($i));
        }
        $this->fijar(8, 7, $b(6));
        $this->fijar(8, 8, $b(7));
        $this->fijar(7, 8, $b(8));
        for ($i = 9; $i < 15; $i++) {
            $this->fijar(14 - $i, 8, $b($i));
        }
        for ($i = 0; $i < 8; $i++) {
            $this->fijar($t - 1 - $i, 8, $b($i));
        }
        for ($i = 8; $i < 15; $i++) {
            $this->fijar(8, $t - 15 + $i, $b($i));
        }
        $this->fijar(8, $t - 8, true); // módulo oscuro
    }

    /** @param int[] $codigos */
    private function ubicarDatos(array $codigos): void
    {
        $t = $this->tam;
        $i = 0;
        $total = count($codigos) * 8;
        for ($der = $t - 1; $der >= 1; $der -= 2) {
            if ($der === 6) {
                $der = 5;
            }
            for ($vert = 0; $vert < $t; $vert++) {
                for ($j = 0; $j < 2; $j++) {
                    $x = $der - $j;
                    $y = (($der + 1) & 2) === 0 ? $t - 1 - $vert : $vert;
                    if (!$this->fijo[$y][$x] && $i < $total) {
                        $this->m[$y][$x] = (($codigos[$i >> 3] >> (7 - ($i & 7))) & 1) === 1;
                        $i++;
                    }
                }
            }
        }
    }

    private function aplicarMascara(int $k): void
    {
        for ($y = 0; $y < $this->tam; $y++) {
            for ($x = 0; $x < $this->tam; $x++) {
                if ($this->fijo[$y][$x]) {
                    continue;
                }
                $inv = match ($k) {
                    0 => ($x + $y) % 2 === 0,
                    1 => $y % 2 === 0,
                    2 => $x % 3 === 0,
                    3 => ($x + $y) % 3 === 0,
                    4 => (intdiv($x, 3) + intdiv($y, 2)) % 2 === 0,
                    5 => $x * $y % 2 + $x * $y % 3 === 0,
                    6 => ($x * $y % 2 + $x * $y % 3) % 2 === 0,
                    default => (($x + $y) % 2 + $x * $y % 3) % 2 === 0,
                };
                if ($inv) {
                    $this->m[$y][$x] = !$this->m[$y][$x];
                }
            }
        }
    }

    /** Penalidad de la norma (corridas, bloques 2x2, patrones parecidos al de posición y balance de negro). */
    private function penalidad(): int
    {
        $t = $this->tam;
        $p = 0;
        $lineas = [];
        for ($i = 0; $i < $t; $i++) {
            $lineas[] = $this->m[$i];
            $lineas[] = array_column($this->m, $i);
        }
        $patron = [true, false, true, true, true, false, true];
        foreach ($lineas as $l) {
            $corrida = 1;
            for ($i = 1; $i <= $t; $i++) {
                if ($i < $t && $l[$i] === $l[$i - 1]) {
                    $corrida++;
                    continue;
                }
                if ($corrida >= 5) {
                    $p += 3 + ($corrida - 5);
                }
                $corrida = 1;
            }
            for ($i = 0; $i + 7 <= $t; $i++) {
                if (array_slice($l, $i, 7) !== $patron) {
                    continue;
                }
                $antes = $i >= 4 && array_slice($l, $i - 4, 4) === [false, false, false, false];
                $despues = $i + 11 <= $t && array_slice($l, $i + 7, 4) === [false, false, false, false];
                if ($antes || $despues) {
                    $p += 40;
                }
            }
        }
        $negros = 0;
        for ($y = 0; $y < $t; $y++) {
            for ($x = 0; $x < $t; $x++) {
                $negros += $this->m[$y][$x] ? 1 : 0;
                if ($x + 1 < $t && $y + 1 < $t) {
                    $c = $this->m[$y][$x];
                    if ($c === $this->m[$y][$x + 1] && $c === $this->m[$y + 1][$x] && $c === $this->m[$y + 1][$x + 1]) {
                        $p += 3;
                    }
                }
            }
        }
        $p += intdiv(abs($negros * 20 - $t * $t * 10), $t * $t) * 10;
        return $p;
    }
}
