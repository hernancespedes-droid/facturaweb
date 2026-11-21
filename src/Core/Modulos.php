<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Módulos que se prenden o apagan por cliente desde el sitio master (Instalaciones de clientes → Módulos).
 * El master (la instalación principal) tiene siempre todo. En un cliente, cada módulo toma lo que diga su config
 * ('modulos' => ['stock' => false, ...]) y, si no dice nada, 'por_omision'.
 *
 * Al agregar una función nueva que NO tienen que tener todos los clientes: declararla acá con 'por_omision' => false, con sus
 * opciones de menú (claves de Core\Menu) y sus rutas (prefijos). Así llega apagada a todos y se prende por cliente.
 */
final class Modulos
{
    /** @var array<string,array{nombre:string,menu:string[],rutas:string[],por_omision:bool}> */
    public const LISTA = [
        'cuenta_corriente' => ['nombre' => 'Cuenta corriente, recibos y cheques', 'menu' => ['ctacte', 'recibos_nuevo', 'recibos', 'imputar', 'ctacte_ajuste', 'cheques'],
            'rutas' => ['/cuenta-corriente', '/recibos', '/cheques'], 'por_omision' => true],
        'stock' => ['nombre' => 'Stock', 'menu' => ['stock', 'stock_movimientos', 'stock_existencias', 'stock_ventas_sin_stock', 'stock_ajuste', 'stock_transferir', 'stock_depositos'],
            'rutas' => ['/stock'], 'por_omision' => true],
        'caja' => ['nombre' => 'Caja (turnos, arqueos, caja diaria)', 'menu' => ['caja', 'caja_turnos', 'caja_auditoria', 'caja_diaria'],
            'rutas' => ['/caja', '/listados/caja-diaria'], 'por_omision' => true],
        'listados' => ['nombre' => 'Listados (ventas, IVA ventas, Libro IVA Digital, estadística, rentabilidad y costos)', 'menu' => ['ventas_diarias', 'iva_ventas', 'libro_iva', 'estadistica', 'rentabilidad', 'lista_costos'],
            'rutas' => ['/listados'], 'por_omision' => true],
        'kiosco' => ['nombre' => 'Modo Kiosco', 'menu' => ['kiosco'], 'rutas' => ['/kiosco'], 'por_omision' => true],
        'cambios' => ['nombre' => 'Cambio de mercadería', 'menu' => ['cambios'], 'rutas' => ['/cambios'], 'por_omision' => true],
        'mercadopago' => ['nombre' => 'Mercado Pago (cobros e ingresos a la cuenta)', 'menu' => ['mp', 'mp_ingresos'], 'rutas' => ['/mercadopago', '/api/v1/mercadopago'], 'por_omision' => true],
        'agente' => ['nombre' => 'Agente de sincronización con el VFP', 'menu' => ['agente'], 'rutas' => ['/agente', '/api/v1/vfp', '/api/v1/ping'], 'por_omision' => false],
        'portal' => ['nombre' => 'Portal de clientes (bajan sus facturas)', 'menu' => [], 'rutas' => ['/portal'], 'por_omision' => true],
    ];

    /** ¿Es una instalación de cliente? (el master no tiene 'instancia.host'). */
    public static function esCliente(): bool
    {
        return (string) Config::get('instancia.host', '') !== '';
    }

    public static function activo(string $modulo): bool
    {
        if (!self::esCliente() || !isset(self::LISTA[$modulo])) {
            return true;
        }
        $v = Config::get('modulos.' . $modulo);
        return $v === null ? self::LISTA[$modulo]['por_omision'] : (bool) $v;
    }

    /** Módulo de una opción del menú, o null si es del núcleo. */
    public static function deMenu(string $clave): ?string
    {
        foreach (self::LISTA as $m => $d) {
            if (in_array($clave, $d['menu'], true)) {
                return $m;
            }
        }
        return null;
    }

    /** Módulo apagado al que pertenece la ruta, o null si se puede entrar. Gana el prefijo más largo (/listados/caja-diaria es de caja). */
    public static function apagadoPorRuta(string $path): ?string
    {
        $mejor = null;
        $largo = 0;
        foreach (self::LISTA as $m => $d) {
            foreach ($d['rutas'] as $r) {
                if (($path === $r || str_starts_with($path, $r . '/')) && strlen($r) > $largo) {
                    $mejor = $m;
                    $largo = strlen($r);
                }
            }
        }
        return $mejor !== null && !self::activo($mejor) ? $mejor : null;
    }
}
