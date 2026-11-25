<?php
declare(strict_types=1);

namespace App\Modules\Pwa;

use App\Http\Request;
use App\Http\Response;
use App\Modules\Configuracion\Apariencia;

/**
 * Lo que el navegador necesita para ofrecer «Instalar app» (Edge/Chrome en la barra de direcciones,
 * Android «Agregar a la pantalla principal»): manifiesto, íconos y un service worker. Todo sin sesión y
 * por instalación (cada subdominio con su nombre, color y logo).
 */
final class PwaController
{
    public static function manifiesto(Request $req, array $params): Response
    {
        $base = url('/');
        $v = Iconos::version();
        $icono = fn (string $tam, string $medida, string $uso): array => [
            'src' => url('/app/icono-' . $tam . '.png') . '?v=' . $v, 'sizes' => $medida, 'type' => 'image/png', 'purpose' => $uso,
        ];
        $nombre = Apariencia::nombreSistema();
        $m = [
            'id' => $base,
            'name' => $nombre,
            'short_name' => mb_substr($nombre, 0, 24),
            'description' => 'Facturación, caja, stock y cuenta corriente.',
            'lang' => 'es-AR',
            'start_url' => $base,
            'scope' => $base,
            'display' => 'standalone',
            'background_color' => '#ffffff',
            'theme_color' => Iconos::colorFondo(),
            'icons' => [
                $icono('192', '192x192', 'any'),
                $icono('512', '512x512', 'any'),
                $icono('maskable', '512x512', 'maskable'),
            ],
        ];
        return new Response(200, (string) json_encode($m, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT), [
            'Content-Type' => 'application/manifest+json; charset=utf-8',
            'Cache-Control' => 'no-cache',
        ]);
    }

    public static function icono(Request $req, array $params): Response
    {
        $png = Iconos::png((string) ($params['tam'] ?? ''));
        if ($png === null) {
            return new Response(404, '', ['Content-Type' => 'text/plain; charset=utf-8']);
        }
        $cache = isset($req->query['v']) ? 'public, max-age=31536000, immutable' : 'public, max-age=300';
        return new Response(200, $png, ['Content-Type' => 'image/png', 'Cache-Control' => $cache]);
    }

    /**
     * Service worker mínimo: no guarda pantallas con datos (son no-store); solo la página «Sin conexión»
     * para cuando no hay internet. Tener uno con `fetch` es requisito de algunos navegadores para ofrecer instalar.
     */
    public static function serviceWorker(Request $req, array $params): Response
    {
        $offline = json_encode(url('/app/sin-conexion'), JSON_UNESCAPED_SLASHES);
        $js = <<<JS
            // FacturaWeb: service worker de la app instalada (generado por PwaController).
            const CACHE = 'fw-app-1';
            const OFFLINE = {$offline};
            self.addEventListener('install', (ev) => {
              ev.waitUntil(caches.open(CACHE).then((c) => c.add(new Request(OFFLINE, { cache: 'reload' }))).then(() => self.skipWaiting()));
            });
            self.addEventListener('activate', (ev) => {
              ev.waitUntil(caches.keys().then((ks) => Promise.all(ks.filter((k) => k !== CACHE).map((k) => caches.delete(k)))).then(() => self.clients.claim()));
            });
            self.addEventListener('fetch', (ev) => {
              if (ev.request.mode !== 'navigate') return; // lo demás va directo a la red, como siempre
              ev.respondWith(fetch(ev.request).catch(() => caches.match(OFFLINE)));
            });
            JS;
        return new Response(200, $js, [
            'Content-Type' => 'application/javascript; charset=utf-8',
            'Cache-Control' => 'no-cache',
        ]);
    }

    /** Página que muestra la app instalada cuando no hay internet (la guarda el service worker). */
    public static function sinConexion(Request $req, array $params): Response
    {
        $nombre = e(Apariencia::nombreSistema());
        $color = e(Iconos::colorFondo());
        $html = '<!doctype html><html lang="es-AR"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
            . '<title>Sin conexión - ' . $nombre . '</title><style>body{font:16px/1.5 Arial,sans-serif;background:#f3f5f9;color:#18202b;margin:0}'
            . '.c{max-width:460px;margin:12vh auto;padding:24px;background:#fff;border-radius:14px;box-shadow:0 8px 24px -14px rgba(20,30,50,.35)}'
            . 'a{display:inline-block;margin-top:12px;padding:10px 18px;background:' . $color . ';color:#fff;border-radius:8px;text-decoration:none;font-weight:700}</style></head>'
            . '<body><div class="c"><h1>Sin conexión a internet</h1><p>' . $nombre . ' necesita internet para trabajar. '
            . 'Revisá la conexión y volvé a intentar: no se perdió nada de lo que ya estaba guardado.</p>'
            . '<a href="javascript:location.reload()">Reintentar</a></div></body></html>';
        return new Response(200, $html, ['Content-Type' => 'text/html; charset=utf-8', 'Cache-Control' => 'no-cache']);
    }
}
