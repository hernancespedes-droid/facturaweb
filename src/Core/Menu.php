<?php
declare(strict_types=1);

namespace App\Core;

use App\Modules\Auth\Auth;
use App\Modules\Tablas\Definiciones;

/**
 * Menú del sistema, agrupado como el del VFP (Maestros, Facturación, Tablas) más Sistema. Cada opción se muestra solo si el rol
 * tiene su permiso; las rutas lo vuelven a verificar en el servidor.
 */
final class Menu
{
    /** @return array<string,array{texto:string,ruta:string,permiso:?string,grupo:string}> */
    public static function opciones(): array
    {
        $o = [
            'clientes' => ['texto' => 'Clientes', 'ruta' => '/clientes', 'permiso' => 'clientes.ver', 'grupo' => 'Maestros'],
            'articulos' => ['texto' => 'Artículos', 'ruta' => '/articulos', 'permiso' => 'articulos.ver', 'grupo' => 'Maestros'],
        ];
        $o['comprobantes_fc'] = ['texto' => 'Nueva factura', 'ruta' => '/comprobantes/nuevo?tipo=FC', 'permiso' => 'comprobantes.emitir', 'grupo' => 'Facturación'];
        $o['comprobantes_nc'] = ['texto' => 'Nueva nota de crédito', 'ruta' => '/comprobantes/nuevo?tipo=NC', 'permiso' => 'comprobantes.emitir', 'grupo' => 'Facturación'];
        $o['comprobantes_nd'] = ['texto' => 'Nueva nota de débito', 'ruta' => '/comprobantes/nuevo?tipo=ND', 'permiso' => 'comprobantes.emitir', 'grupo' => 'Facturación'];
        $o['comprobantes'] = ['texto' => 'Comprobantes', 'ruta' => '/comprobantes', 'permiso' => 'comprobantes.ver', 'grupo' => 'Facturación'];
        $o['iva_ventas'] = ['texto' => 'Subdiario IVA ventas', 'ruta' => '/listados/iva-ventas', 'permiso' => 'listados.ver', 'grupo' => 'Listados'];
        foreach (Definiciones::todas() as $t => $d) {
            $o[$t] = ['texto' => $d['titulo'], 'ruta' => '/tablas/' . $t, 'permiso' => 'tablas.' . $t, 'grupo' => 'Tablas'];
        }
        $o['usuarios'] = ['texto' => 'Usuarios', 'ruta' => '/usuarios', 'permiso' => 'usuarios.administrar', 'grupo' => 'Sistema'];
        $o['roles'] = ['texto' => 'Roles', 'ruta' => '/roles', 'permiso' => 'usuarios.administrar', 'grupo' => 'Sistema'];
        $o['configuracion'] = ['texto' => 'Configuración', 'ruta' => '/configuracion', 'permiso' => 'configuracion.administrar', 'grupo' => 'Sistema'];
        $o['arca'] = ['texto' => 'ARCA', 'ruta' => '/arca', 'permiso' => 'arca.administrar', 'grupo' => 'Sistema'];
        $o['sistema'] = ['texto' => 'Sistema', 'ruta' => '/sistema', 'permiso' => null, 'grupo' => 'Sistema'];
        return $o;
    }

    public static function texto(string $clave): string
    {
        return self::opciones()[$clave]['texto'] ?? $clave;
    }

    public static function visible(string $clave): bool
    {
        $o = self::opciones()[$clave] ?? null;
        if ($o === null) {
            return false;
        }
        if ($clave === 'sistema') {
            return Auth::esMaster() || Auth::esAdmin();
        }
        return $o['permiso'] !== null && Auth::puede($o['permiso']);
    }

    /** Grupos con las opciones que ve el usuario. @return array<string,array<string,array<string,mixed>>> */
    public static function grupos(): array
    {
        $out = [];
        foreach (self::opciones() as $k => $o) {
            if (self::visible($k)) {
                $out[$o['grupo']][$k] = $o;
            }
        }
        return $out;
    }
}
