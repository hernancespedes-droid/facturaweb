<?php
declare(strict_types=1);

namespace App\Modules\Instancias;

use App\Core\Config;

/**
 * Estado de la suscripción de una instalación de cliente, que maneja el sitio master (Instalaciones de clientes → Suscripción).
 * Va en el config del cliente: 'suscripcion' => ['estado' => …, 'mensaje' => …, 'sin_emision_desde' => 'AAAA-MM-DD', 'bloqueo_desde' => 'AAAA-MM-DD'].
 *
 *   activa       normal
 *   aviso        cartel en todas las pantallas («suscripción pendiente de pago»), se trabaja normal
 *   sin_emision  cartel + no se emiten comprobantes ni recibos (se consulta e imprime)
 *   bloqueada    no entra nadie salvo el Master de O&S (ni el portal ni el agente)
 *
 * Las fechas hacen avanzar el estado solo: un aviso con «sin emitir desde el 10» pasa solo a sin_emision el día 10, y a bloqueada
 * en 'bloqueo_desde'. El estado nunca retrocede solo: para volver a activa se cambia desde el master.
 */
final class Suscripcion
{
    public const ESTADOS = ['activa' => 'Activa', 'aviso' => 'Aviso de pago pendiente', 'sin_emision' => 'Sin emitir comprobantes', 'bloqueada' => 'Bloqueada'];
    private const ORDEN = ['activa' => 0, 'aviso' => 1, 'sin_emision' => 2, 'bloqueada' => 3];

    /** Estado efectivo hoy, según el estado elegido y las fechas. */
    public static function estado(?array $s = null, ?string $hoy = null): string
    {
        $s ??= (array) Config::get('suscripcion', []);
        $hoy ??= date('Y-m-d');
        $estado = isset(self::ORDEN[$s['estado'] ?? '']) ? (string) $s['estado'] : 'activa';
        foreach (['sin_emision_desde' => 'sin_emision', 'bloqueo_desde' => 'bloqueada'] as $campo => $pasaA) {
            $f = (string) ($s[$campo] ?? '');
            if ($f !== '' && $f <= $hoy && self::ORDEN[$pasaA] > self::ORDEN[$estado]) {
                $estado = $pasaA;
            }
        }
        return $estado;
    }

    public static function puedeEmitir(): bool
    {
        return self::ORDEN[self::estado()] < self::ORDEN['sin_emision'];
    }

    public static function bloqueada(): bool
    {
        return self::estado() === 'bloqueada';
    }

    /** Texto para el cartel de todas las pantallas, o null si está activa. */
    public static function mensaje(): ?string
    {
        $s = (array) Config::get('suscripcion', []);
        $estado = self::estado($s);
        if ($estado === 'activa') {
            return null;
        }
        $propio = trim((string) ($s['mensaje'] ?? ''));
        $base = match ($estado) {
            'aviso' => 'Tu suscripción tiene un pago pendiente. Comunicate con O&S para regularizarla.',
            'sin_emision' => 'Suscripción suspendida por falta de pago: no se pueden emitir comprobantes. Podés consultar e imprimir. Comunicate con O&S.',
            default => 'El servicio está suspendido. Comunicate con O&S.',
        };
        $texto = $propio !== '' ? $propio : $base;
        foreach (['sin_emision_desde' => 'se suspende la emisión', 'bloqueo_desde' => 'se bloquea el acceso'] as $campo => $que) {
            $f = (string) ($s[$campo] ?? '');
            if ($f !== '' && $f > date('Y-m-d') && self::ORDEN[$estado] < self::ORDEN[$campo === 'bloqueo_desde' ? 'bloqueada' : 'sin_emision']) {
                $texto .= ' El ' . date('d/m/Y', (int) strtotime($f)) . ' ' . $que . '.';
            }
        }
        return $texto;
    }

    /** Página para los usuarios de una instalación bloqueada. */
    public static function paginaBloqueada(): string
    {
        return '<!doctype html><html lang="es-AR"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
            . '<title>Servicio suspendido</title><style>body{font:16px/1.5 Arial,sans-serif;background:#f3f5f9;color:#18202b;margin:0}'
            . '.c{max-width:480px;margin:12vh auto;padding:24px;background:#fff;border-radius:14px;box-shadow:0 8px 24px -14px rgba(20,30,50,.35)}</style></head>'
            . '<body><div class="c"><h1>Servicio suspendido</h1><p>' . htmlspecialchars((string) self::mensaje(), ENT_QUOTES) . '</p>'
            . '<form method="post" action="' . htmlspecialchars(url('/salir'), ENT_QUOTES) . '">' . csrf_field() . '<button type="submit">Salir</button></form></div></body></html>';
    }
}
