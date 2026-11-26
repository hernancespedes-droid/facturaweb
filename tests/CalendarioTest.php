<?php
declare(strict_types=1);

namespace Tests;

/**
 * Calendario en los campos de fecha (0.64.0, D-151): todo campo de texto que pide una fecha (dd/mm/aaaa) lleva data-fecha,
 * así public/assets/calendario.js le pone el botón del calendario. Si esta prueba falla en una pantalla nueva, sumarle data-fecha al input.
 */
final class CalendarioTest extends TestCase
{
    /** @return list<array{string,int,string}> archivo, renglón, input */
    private function inputs(): array
    {
        $raiz = dirname(__DIR__) . '/views';
        $todo = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($raiz, \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) {
            if (!str_ends_with((string) $f, '.php')) {
                continue;
            }
            foreach (file((string) $f) ?: [] as $i => $linea) {
                // el PHP adentro del tag tiene «>»: se saca antes de buscar los <input>
                $limpia = (string) preg_replace('/<\?=?.*?\?>/s', 'X', $linea);
                if (preg_match_all('/<input\b[^>]*>/', $limpia, $m)) {
                    foreach ($m[0] as $tag) {
                        $todo[] = [substr((string) $f, strlen($raiz) + 1), $i + 1, $tag];
                    }
                }
            }
        }
        return $todo;
    }

    public function testTodoCampoDeFechaTieneCalendario(): void
    {
        $faltan = [];
        foreach ($this->inputs() as [$archivo, $renglon, $tag]) {
            $esFecha = str_contains($tag, 'dd/mm/aaaa')
                || (preg_match('/name="(desde|hasta|al|vence_hasta)"/', $tag) === 1 && !str_contains($tag, 'type="hidden"'));
            if ($esFecha && !str_contains($tag, 'data-fecha')) {
                $faltan[] = $archivo . ':' . $renglon;
            }
        }
        $this->assertSame([], $faltan, 'Campos de fecha sin data-fecha (calendario): ' . implode(', ', $faltan));
    }

    public function testLasConsultasConDesdeYHastaTienenCalendario(): void
    {
        $con = 0;
        foreach ($this->inputs() as [, , $tag]) {
            if (preg_match('/name="(desde|hasta)"/', $tag) === 1 && str_contains($tag, 'data-fecha')) {
                $con++;
            }
        }
        $this->assertTrue($con >= 34, 'Se esperaban al menos 17 consultas con desde/hasta y calendario; hay ' . $con . ' campos');
    }

    public function testElLayoutCargaElCalendario(): void
    {
        $layout = (string) file_get_contents(dirname(__DIR__) . '/views/layout.php');
        $this->assertStringContainsString("asset('assets/calendario.js')", $layout);
        $js = (string) file_get_contents(dirname(__DIR__) . '/public/assets/calendario.js');
        $this->assertStringContainsString('input[data-fecha]', $js);
    }
}
