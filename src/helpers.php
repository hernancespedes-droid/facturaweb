<?php
declare(strict_types=1);

if (!function_exists('url')) {
    /** Arma una URL interna respetando app.base_path (por si el sistema queda en una subcarpeta). */
    function url(string $path = '/'): string
    {
        return (string) \App\Core\Config::get('app.base_path', '') . '/' . ltrim($path, '/');
    }
}

if (!function_exists('env')) {
    /** Lee una variable de entorno (sirve tanto en web como en CLI y en las pruebas). */
    function env(string $key, ?string $default = null): ?string
    {
        $v = $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key);
        return ($v === false || $v === null || $v === '') ? $default : (string) $v;
    }
}

if (!function_exists('e')) {
    /** Escapa texto para mostrarlo en HTML. Usar SIEMPRE al imprimir datos en las vistas. */
    function e(mixed $value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

if (!function_exists('puede')) {
    /** Permiso del usuario en la empresa activa. En pantalla solo decide qué se muestra: las rutas lo verifican de nuevo. */
    function puede(string $codigo): bool
    {
        return \App\Modules\Auth\Auth::puede($codigo);
    }
}

if (!function_exists('csrf_field')) {
    /** Campo oculto con el token CSRF. Va en todo formulario POST. */
    function csrf_field(): string
    {
        return '<input type="hidden" name="' . \App\Core\Csrf::CAMPO . '" value="' . e(\App\Core\Csrf::token()) . '">';
    }
}

if (!function_exists('asset')) {
    /** URL de un archivo de public/ con su fecha de modificación, para que el navegador no use una versión vieja. */
    function asset(string $path): string
    {
        $file = \App\App::root() . '/public/' . ltrim($path, '/');
        return url($path) . (is_file($file) ? '?v=' . filemtime($file) : '');
    }
}

if (!function_exists('plata')) {
    /** "$ 15.000" o "$ 15.000,50" (importe en pesos). */
    function plata(mixed $v): string
    {
        $f = (float) $v;
        return ($f < 0 ? '−' : '') . '$ ' . number_format(abs($f), fmod($f, 1.0) == 0.0 ? 0 : 2, ',', '.');
    }
}

if (!function_exists('th_orden')) {
    /**
     * Título de columna que ordena la grilla al tocarlo (▲ ascendente, ▼ descendente; un segundo toque invierte).
     * $primeroDesc: fechas, importes y números arrancan de mayor a menor.
     *
     * @param array<string,mixed> $f filtros actuales (se conservan; vuelve a la página 1)
     */
    function th_orden(string $titulo, string $clave, array $f, string $url, bool $primeroDesc = false, string $ancla = ''): string
    {
        [$actual, $desc] = \App\Core\Orden::partes((string) ($f['orden'] ?? ''));
        $activa = $actual === $clave;
        $sig = $activa ? ($desc ? $clave : $clave . '_desc') : ($primeroDesc ? $clave . '_desc' : $clave);
        $q = http_build_query(['orden' => $sig] + array_diff_key($f, ['orden' => 1, 'pagina' => 1]));
        $flecha = $activa ? ($desc ? ' ▼' : ' ▲') : '';
        return '<th' . ($activa ? ' aria-sort="' . ($desc ? 'descending' : 'ascending') . '"' : '') . '><a class="th-orden' . ($activa ? ' activa' : '')
            . '" href="' . e($url . '?' . $q . ($ancla !== '' ? '#' . $ancla : '')) . '" title="Ordenar por ' . e(mb_strtolower($titulo)) . '">'
            . e($titulo) . $flecha . '</a></th>';
    }
}

if (!function_exists('opciones_orden')) {
    /** <option> del "Ordenar por" de los filtros. @param array<string,string> $opciones clave => texto ('' = por omisión) */
    function opciones_orden(array $opciones, string $actual): string
    {
        $html = '';
        foreach ($opciones as $k => $t) {
            $html .= '<option value="' . e($k) . '"' . ($k === $actual ? ' selected' : '') . '>' . e($t) . '</option>';
        }
        return $html;
    }
}

if (!function_exists('paginador')) {
    /**
     * 0.9.38: paginador de las grillas. Muestra "51–100 de 3.420", primera / anterior, los números cercanos con "…",
     * siguiente / última y "Ir a página [__] de N" (funciona sin JavaScript: es un formulario GET con los mismos
     * filtros). Con las flechas ← → del teclado se pasa de página (app.js, si no se está escribiendo).
     * $link(n) arma la dirección de la página n con los filtros actuales.
     */
    function paginador(int $pagina, int $paginas, int $total, int $porPagina, callable $link, bool $arriba = false): string
    {
        if ($paginas <= 1) {
            return '';
        }
        $pagina = max(1, min($pagina, $paginas));
        $n = static fn (int $x) => number_format($x, 0, ',', '.');
        $desde = ($pagina - 1) * $porPagina + 1;
        $hasta = min($total, $pagina * $porPagina);
        $btn = static function (int $p, string $texto, string $titulo, string $rel = '') use ($link, $pagina, $paginas): string {
            if ($p < 1 || $p > $paginas || $p === $pagina) {
                return '<span class="pag-btn pag-off" aria-hidden="true">' . $texto . '</span>';
            }
            return '<a class="pag-btn" href="' . e((string) $link($p)) . '" title="' . e($titulo) . '" aria-label="' . e($titulo) . '"' . ($rel !== '' ? ' rel="' . $rel . '"' : '') . '>' . $texto . '</a>';
        };
        // Números: la primera, la última y dos a cada lado de la actual; "…" en los saltos.
        $nums = array_unique(array_filter([1, $paginas, $pagina - 2, $pagina - 1, $pagina, $pagina + 1, $pagina + 2], static fn ($x) => $x >= 1 && $x <= $paginas));
        sort($nums);
        $numeros = '';
        $previo = 0;
        foreach ($nums as $x) {
            if ($x - $previo > 1) {
                $numeros .= '<span class="pag-salto" aria-hidden="true">…</span>';
            }
            $numeros .= $x === $pagina
                ? '<span class="pag-num pag-actual" aria-current="page">' . $n($x) . '</span>'
                : '<a class="pag-num" href="' . e((string) $link($x)) . '" aria-label="Página ' . $x . '">' . $n($x) . '</a>';
            $previo = $x;
        }
        // "Ir a página": formulario GET a la misma dirección, con los filtros como campos ocultos.
        $url = (string) $link($pagina);
        $accion = (string) strtok($url, '?');
        parse_str((string) parse_url($url, PHP_URL_QUERY), $q);
        unset($q['pagina']);
        $ocultos = '';
        $plano = static function (string $k, mixed $v) use (&$plano, &$ocultos): void {
            if (is_array($v)) {
                foreach ($v as $kk => $vv) {
                    $plano($k . '[' . $kk . ']', $vv);
                }
                return;
            }
            $ocultos .= '<input type="hidden" name="' . e($k) . '" value="' . e((string) $v) . '">';
        };
        foreach ($q as $k => $v) {
            $plano((string) $k, $v);
        }
        $id = 'pag-ir-' . ($arriba ? 'a' : 'b');
        return '<nav class="paginador' . ($arriba ? ' paginador-arriba' : '') . '" aria-label="Páginas' . ($arriba ? ' (arriba)' : '') . '"' . ($arriba ? '' : ' data-paginador') . '>'
            . '<span class="pag-rango">' . $n($desde) . '–' . $n($hasta) . ' de ' . $n($total) . '</span>'
            . '<span class="pag-botones">'
            . ($arriba ? '' : $btn(1, '«', 'Primera página'))
            . $btn($pagina - 1, '‹', 'Página anterior (←)', 'prev')
            . ($arriba ? '' : '<span class="pag-numeros">' . $numeros . '</span>')
            . $btn($pagina + 1, '›', 'Página siguiente (→)', 'next')
            . ($arriba ? '' : $btn($paginas, '»', 'Última página'))
            . '</span>'
            . '<form method="get" action="' . e($accion) . '" class="pag-ir" data-pag-ir novalidate>' . $ocultos
            . '<label for="' . $id . '">Ir a</label>'
            . '<input id="' . $id . '" name="pagina" type="number" inputmode="numeric" min="1" max="' . $paginas . '" value="' . $pagina . '" aria-label="Ir a la página (de 1 a ' . $paginas . ')">'
            . '<span>de ' . $n($paginas) . '</span><button type="submit" class="btn btn-sm">Ir</button></form>'
            . '</nav>';
    }
}

if (!function_exists('url_absoluta')) {
    /**
     * URL completa (https://dominio/…) de una ruta interna, para lo que sale del sistema (QR impreso). Usa
     * app.url de la configuración si está; si no, el host del pedido (solo si tiene forma de host).
     */
    function url_absoluta(string $ruta): string
    {
        $base = rtrim((string) \App\Core\Config::get('app.url', ''), '/');
        if ($base === '') {
            $host = (string) ($_SERVER['HTTP_HOST'] ?? '');
            if (!preg_match('/^[A-Za-z0-9.-]+(:\d+)?$/', $host)) {
                $host = 'localhost';
            }
            $https = ($_SERVER['HTTPS'] ?? '') !== '' && ($_SERVER['HTTPS'] ?? '') !== 'off'
                || strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
            $base = ($https ? 'https' : 'http') . '://' . $host;
        }
        $bp = (string) \App\Core\Config::get('app.base_path', '');
        if ($bp !== '' && str_ends_with($base, $bp)) { // app.url con la subcarpeta incluida: no repetirla
            $base = substr($base, 0, -strlen($bp));
        }
        return $base . $ruta;
    }
}

if (!function_exists('cuit_texto')) {
    /** CUIT con guiones (30-12345678-9); vacío si no hay. */
    function cuit_texto(mixed $cuit): string
    {
        $c = preg_replace('/\D/', '', (string) $cuit);
        return strlen($c) === 11 ? substr($c, 0, 2) . '-' . substr($c, 2, 8) . '-' . substr($c, 10) : (string) $c;
    }
}

if (!function_exists('numero')) {
    /** Número con coma decimal y punto de miles (sin ceros de más a la derecha, hasta $dec decimales). */
    function numero(mixed $v, int $dec = 2): string
    {
        $f = (float) $v;
        $s = number_format($f, $dec, ',', '.');
        return $dec > 0 ? rtrim(rtrim($s, '0'), ',') : $s;
    }
}

if (!function_exists('fecha')) {
    /** Fecha Y-m-d de la base como dd/mm/aaaa (vacío si no hay). */
    function fecha(?string $f): string
    {
        if ($f === null || $f === '' || str_starts_with($f, '0000')) {
            return '';
        }
        $t = strtotime($f);
        return $t === false ? $f : date('d/m/Y', $t);
    }
}

if (!function_exists('numero_cbte')) {
    /** Número de comprobante como se imprime: 0004-00000123 (punto de venta y número). */
    function numero_cbte(mixed $prefijo, mixed $numero): string
    {
        return sprintf('%04d-%08d', (int) $prefijo, (int) $numero);
    }
}

if (!function_exists('plata_dec')) {
    /** "$ 15.000,00": importe en pesos siempre con dos decimales (comprobantes). */
    function plata_dec(mixed $v): string
    {
        $f = (float) $v;
        return ($f < 0 ? '−' : '') . '$ ' . number_format(abs($f), 2, ',', '.');
    }
}

if (!function_exists('numero_visible')) {
    /**
     * Número con el que se conoce un comprobante: el que dio ARCA si tiene CAE (en los comprobantes del VFP el número interno
     * puede ser otro), y si no el interno. $c necesita prefijo, comprobante y, si los tiene, fe_nrocbte y fe_ccae.
     */
    function numero_visible(array $c): string
    {
        $cae = (string) ($c['fe_ccae'] ?? '');
        $arca = (int) ($c['fe_nrocbte'] ?? 0);
        return numero_cbte($c['prefijo'], $cae !== '' && $cae !== '.' && $arca > 0 ? $arca : $c['comprobante']);
    }
}

if (!function_exists('estado_comprobante')) {
    /**
     * Cómo se rotula un comprobante: [texto, clase de badge]. CAE real, CAE de prueba (simulado u homologación), interno (sin CAE) o anulado.
     *
     * @param array<string,mixed> $c fila del subdiario (fe_ccae, es_prueba, fe_entorno, estado)
     * @return array{0:string,1:string}
     */
    function estado_comprobante(array $c): array
    {
        if (($c['estado'] ?? '') !== '') {
            return ['Anulado', 'badge-off'];
        }
        $cae = (string) ($c['fe_ccae'] ?? '');
        if ($cae === '' || $cae === '.') {
            return ['Interno', 'badge-warn'];
        }
        return match ((string) ($c['fe_entorno'] ?? '')) {
            'S' => ['CAE simulado', 'badge-warn'],
            'H' => ['CAE homologación', 'badge-warn'],
            default => ['CAE', 'badge-ok'],
        };
    }
}
