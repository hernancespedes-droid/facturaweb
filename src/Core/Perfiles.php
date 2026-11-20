<?php
declare(strict_types=1);

namespace App\Core;

use PDO;

/**
 * Perfiles de instalación: un punto de partida para cada tipo de cliente, así un alta nueva no obliga a tildar a mano diez opciones.
 * El perfil se elige al generar el alta (`bin/alta-cliente.php --perfil=…`, queda en config/local.php como `'perfil' => '…'`) y se aplica UNA sola vez al instalar.
 * Después todo se cambia en Parámetros / Configuración como siempre; el perfil no vuelve a pisar nada. Todo cliente corre el mismo código: el perfil solo prende o apaga opciones.
 *
 * DECISIÓN D-60 (Dev Senior, a confirmar por Hernán): perfiles «basico» (facturación, cuenta corriente, caja; lo de siempre) y «mostrador» (kiosco, ticket de cambio,
 * ticket de 80 mm y control de stock). Se pueden agregar más perfiles acá sin tocar nada más.
 */
final class Perfiles
{
    /** @var array<string,array{label:string,parametros:array<string,int|string>,config:array<string,string>}> */
    public const PERFILES = [
        'basico' => ['label' => 'Básico: facturación, cuenta corriente y caja', 'parametros' => [], 'config' => []],
        'mostrador' => [
            'label' => 'Mostrador: kiosco, ticket de cambio, ticket de 80 mm y control de stock',
            'parametros' => ['kiosco' => 1, 'ticket_cambio' => 1, 'impresion' => 't80'],
            'config' => ['stock.controlar' => '1'],
        ],
    ];

    /** Los perfiles para elegir al hacer el alta. @return array<string,string> */
    public static function nombres(): array
    {
        return array_map(static fn (array $p) => $p['label'], self::PERFILES);
    }

    /**
     * Aplica el perfil indicado en config/local.php si todavía no se aplicó ninguno. Devuelve el perfil aplicado, o null si no había nada que hacer.
     */
    public static function aplicarSiCorresponde(?PDO $pdo = null): ?string
    {
        $pdo ??= Db::pdo();
        $perfil = strtolower(trim((string) Config::get('perfil', '')));
        if ($perfil === '' || !isset(self::PERFILES[$perfil])) {
            return null;
        }
        try {
            $ya = $pdo->query("SELECT valor FROM sistema_estado WHERE clave = 'perfil_aplicado'")->fetchColumn();
        } catch (\PDOException) {
            return null; // todavía no está la tabla (primera instalación sin migrar)
        }
        if ($ya !== false) {
            return null;
        }
        $p = self::PERFILES[$perfil];
        foreach ($p['parametros'] as $col => $valor) {
            if (preg_match('/^[a-z_]+$/', (string) $col) === 1) { // los nombres salen de esta constante, nunca de afuera
                $pdo->prepare("UPDATE parametros SET `{$col}` = ? WHERE id = 1")->execute([$valor]);
            }
        }
        foreach ($p['config'] as $clave => $valor) {
            $pdo->prepare('UPDATE configuracion SET valor = ? WHERE clave = ?')->execute([$valor, $clave]);
        }
        $pdo->prepare("INSERT INTO sistema_estado (clave, valor) VALUES ('perfil_aplicado', ?)")->execute([$perfil]);
        return $perfil;
    }
}
