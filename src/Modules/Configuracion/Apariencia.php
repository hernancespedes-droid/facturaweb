<?php
declare(strict_types=1);

namespace App\Modules\Configuracion;

use App\Core\Config;
use App\Core\Db;

/**
 * Apariencia de la instalación (0.31.0): cada cliente ve su logo, su color y su nombre en todo el sistema
 * (barra, ingreso, pestaña del navegador y portal), con el mismo código para todos.
 *
 * Todo vive en la base del cliente: claves `apariencia.*` de la tabla configuracion y las imágenes de la tabla imagenes
 * ('logo' = JPEG de los comprobantes, 'logo_web' = su copia PNG para la pantalla, 'logo_pantalla' = uno propio para la
 * pantalla si lo suben aparte, 'logo_icono' = ícono cuadrado de la pestaña). Si no se configura nada se ve como siempre.
 * Decisión de Hernán (21/11/2026): la marca del cliente arriba y «FacturaWeb · Organización & Sistemas» al pie.
 */
final class Apariencia
{
    public const COLOR = 'apariencia.color';
    public const NOMBRE = 'apariencia.nombre';
    public const LOGO_EN_PANTALLA = 'apariencia.logo_en_pantalla';
    public const LOGO_PLACA = 'apariencia.logo_placa';
    /** Menú horizontal (arriba, con desplegables) o lateral (columna a la izquierda), 0.52.0 / D-136. */
    public const MENU = 'apariencia.menu';
    public const MENUS = ['horizontal' => 'Horizontal, arriba (con desplegables)', 'lateral' => 'Lateral, a la izquierda (columna fija)'];

    public const IMG_WEB = 'logo_web';
    public const IMG_PANTALLA = 'logo_pantalla';
    public const IMG_ICONO = 'logo_icono';

    public const PANTALLA_ANCHO = 600;
    public const PANTALLA_ALTO = 200;
    public const ICONO = 64;
    public const NOMBRE_MAX = 60;

    /** Fondos del tema (app.css) contra los que se controla el contraste del color de los enlaces. */
    private const FONDO_OSCURO = '#171b22';
    private const FONDO_CLARO = '#ffffff';

    /** @var array<string,int>|null clave de imagen => versión (marca de tiempo) */
    private static ?array $imagenes = null;

    public static function reset(): void
    {
        self::$imagenes = null;
    }

    // ---------- lo que se muestra ----------

    /** Color principal elegido ('#rrggbb') o null (el azul de FacturaWeb). */
    public static function color(): ?string
    {
        return self::normalizarColor((string) Configuracion::valor(self::COLOR));
    }

    /** Nombre a mostrar elegido por el cliente ('' si no eligió). */
    public static function nombre(): string
    {
        return trim((string) Configuracion::valor(self::NOMBRE));
    }

    /** Nombre para el título de la pestaña y los textos: el del cliente o el del sistema. */
    public static function nombreSistema(): string
    {
        $n = self::nombre();
        return $n !== '' ? $n : (string) Config::get('app.name', 'FacturaWeb');
    }

    public static function logoEnPantalla(): bool
    {
        return Configuracion::valor(self::LOGO_EN_PANTALLA) !== '0';
    }

    public static function logoPlaca(): bool
    {
        return Configuracion::valor(self::LOGO_PLACA) === '1';
    }

    /** Menú de esta instalación: 'horizontal' (por omisión) o 'lateral'. En el celular siempre es el botón ☰. */
    public static function menu(): string
    {
        $m = (string) Configuracion::valor(self::MENU);
        return isset(self::MENUS[$m]) ? $m : 'horizontal';
    }

    /** Si el cliente cambió algo (entonces se firma «FacturaWeb · O&S» en el ingreso). */
    public static function personalizada(): bool
    {
        return self::color() !== null || self::nombre() !== '' || self::claveLogo() !== null;
    }

    /** Qué imagen va en pantalla: la propia para pantalla, la copia PNG del logo o el JPEG de antes de la 0.31.0. */
    public static function claveLogo(): ?string
    {
        if (!self::logoEnPantalla()) {
            return null;
        }
        $img = self::imagenes();
        foreach ([self::IMG_PANTALLA, self::IMG_WEB, 'logo'] as $c) {
            if (isset($img[$c])) {
                return $c;
            }
        }
        return null;
    }

    public static function urlLogo(): ?string
    {
        $c = self::claveLogo();
        return $c === null ? null : url('/marca/logo') . '?v=' . self::imagenes()[$c] . substr(md5($c), 0, 4);
    }

    public static function urlIcono(): ?string
    {
        $c = self::claveLogo();
        if ($c === null) {
            return null;
        }
        $v = self::imagenes()[self::IMG_ICONO] ?? self::imagenes()[$c];
        return url('/marca/icono') . '?v=' . $v;
    }

    /** Hay logo propio para pantalla (además del de los comprobantes). */
    public static function tieneLogoPantalla(): bool
    {
        return isset(self::imagenes()[self::IMG_PANTALLA]);
    }

    /**
     * Variables de color para los dos temas (va en un <style> del layout). '' si no hay color propio.
     * --brand es el fondo de botones y resaltados; --on-brand, su texto (blanco o casi negro según contraste);
     * --brand-2, el color de enlaces y títulos, aclarado u oscurecido hasta que se lea sobre el fondo.
     */
    public static function estilo(): string
    {
        $c = self::color();
        if ($c === null) {
            return '';
        }
        [$r, $g, $b] = self::rgb($c);
        $sobre = self::contraste($c, '#ffffff') >= self::contraste($c, '#111111') ? '#ffffff' : '#111111';
        $oscuro = self::legible($c, self::FONDO_OSCURO, '#ffffff');
        $claro = self::legible($c, self::FONDO_CLARO, '#000000');
        return ":root{--brand:$c;--brand-2:$oscuro;--brand-soft:rgba($r,$g,$b,.14);--on-brand:$sobre}"
            . ":root[data-tema=\"claro\"]{--brand:$c;--brand-2:$claro;--brand-soft:rgba($r,$g,$b,.12);--on-brand:$sobre}";
    }

    // ---------- guardar ----------

    /**
     * Guarda nombre, color y opciones del logo.
     *
     * @param array<string,mixed> $post
     * @return array<string,string> errores por campo (vacío si se guardó)
     */
    public static function guardar(array $post, ?int $usuarioId): array
    {
        $err = [];
        $nombre = trim(preg_replace('/\s+/u', ' ', (string) ($post['nombre'] ?? '')) ?? '');
        if (mb_strlen($nombre) > self::NOMBRE_MAX) {
            $err['nombre'] = 'El nombre puede tener hasta ' . self::NOMBRE_MAX . ' letras.';
        }
        $color = '';
        if (!empty($post['color_propio'])) {
            $n = self::normalizarColor((string) ($post['color'] ?? ''));
            if ($n === null) {
                $err['color'] = 'Elegí un color válido (por ejemplo #c0392b).';
            } else {
                $color = $n;
            }
        }
        $menu = (string) ($post['menu'] ?? self::menu());
        if (!isset(self::MENUS[$menu])) {
            $err['menu'] = 'Elegí menú horizontal o lateral.';
        }
        if ($err !== []) {
            return $err;
        }
        $valores = [
            self::NOMBRE => [$nombre, 'texto', 'Apariencia: nombre que se ve en la barra y en la pestaña (vacío = FacturaWeb)'],
            self::COLOR => [$color, 'texto', 'Apariencia: color principal en #rrggbb (vacío = el azul de FacturaWeb)'],
            self::LOGO_EN_PANTALLA => [empty($post['logo_en_pantalla']) ? '0' : '1', 'si_no', 'Apariencia: mostrar el logo en la barra y en el ingreso'],
            self::LOGO_PLACA => [empty($post['logo_placa']) ? '0' : '1', 'si_no', 'Apariencia: poner el logo sobre una placa blanca'],
            self::MENU => [$menu, 'texto', 'Apariencia: menú horizontal (arriba) o lateral (a la izquierda)'],
        ];
        $st = Db::pdo()->prepare(
            'INSERT INTO configuracion (clave, valor, tipo, descripcion, actualizado_por) VALUES (?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE actualizado_por = IF(valor = VALUES(valor), actualizado_por, VALUES(actualizado_por)), valor = VALUES(valor)'
        );
        foreach ($valores as $clave => [$v, $tipo, $desc]) {
            $st->execute([$clave, $v, $tipo, $desc, $usuarioId]);
        }
        Configuracion::reset();
        self::reset();
        return [];
    }

    /** Logo propio para la pantalla (por ejemplo la versión en blanco). Devuelve el error o null. */
    public static function guardarLogoPantalla(string $archivo, ?int $usuarioId): ?string
    {
        $orig = Logo::leer($archivo);
        if (is_string($orig)) {
            return $orig;
        }
        $png = Logo::png($orig, self::PANTALLA_ANCHO, self::PANTALLA_ALTO);
        imagedestroy($orig);
        Logo::grabar(self::IMG_PANTALLA, 'image/png', $png['datos'], $png['ancho'], $png['alto'], $usuarioId);
        self::regenerarIcono($usuarioId);
        return null;
    }

    public static function quitarLogoPantalla(): void
    {
        Db::pdo()->prepare('DELETE FROM imagenes WHERE clave = ?')->execute([self::IMG_PANTALLA]);
        self::reset();
        self::regenerarIcono(null);
    }

    /** Rehace el ícono de la pestaña desde el logo que va en pantalla (o lo borra si no hay). */
    public static function regenerarIcono(?int $usuarioId): void
    {
        self::reset();
        $origen = null;
        $img = self::imagenes();
        foreach ([self::IMG_PANTALLA, self::IMG_WEB, 'logo'] as $c) {
            if (isset($img[$c])) {
                $origen = $c;
                break;
            }
        }
        $datos = $origen === null ? null : self::datos($origen);
        $gd = $datos === null || !function_exists('imagecreatefromstring') ? false : @imagecreatefromstring($datos['datos']);
        if ($gd === false) {
            Db::pdo()->prepare('DELETE FROM imagenes WHERE clave = ?')->execute([self::IMG_ICONO]);
            self::reset();
            return;
        }
        $png = Logo::png($gd, self::ICONO, self::ICONO, true);
        imagedestroy($gd);
        Logo::grabar(self::IMG_ICONO, 'image/png', $png['datos'], $png['ancho'], $png['alto'], $usuarioId);
    }

    /** @return array{tipo:string,datos:string}|null */
    public static function datos(string $clave): ?array
    {
        try {
            $st = Db::pdo()->prepare('SELECT tipo, datos FROM imagenes WHERE clave = ?');
            $st->execute([$clave]);
            $f = $st->fetch();
        } catch (\PDOException) {
            return null;
        }
        return $f ? ['tipo' => (string) $f['tipo'], 'datos' => (string) base64_decode((string) $f['datos'])] : null;
    }

    // ---------- color ----------

    /** '#abc', 'abc', '#AABBCC' → '#aabbcc'; cualquier otra cosa → null. */
    public static function normalizarColor(string $c): ?string
    {
        $c = strtolower(trim($c));
        if ($c !== '' && $c[0] !== '#') {
            $c = '#' . $c;
        }
        if (preg_match('/^#([0-9a-f])([0-9a-f])([0-9a-f])$/', $c, $m)) {
            $c = '#' . $m[1] . $m[1] . $m[2] . $m[2] . $m[3] . $m[3];
        }
        return preg_match('/^#[0-9a-f]{6}$/', $c) ? $c : null;
    }

    /** Contraste WCAG entre dos colores '#rrggbb' (1 a 21). */
    public static function contraste(string $a, string $b): float
    {
        $la = self::luminancia($a);
        $lb = self::luminancia($b);
        return (max($la, $lb) + 0.05) / (min($la, $lb) + 0.05);
    }

    /** El color mezclado de a poco con $hacia hasta tener contraste 4,5 con $fondo (texto legible). */
    public static function legible(string $color, string $fondo, string $hacia): string
    {
        [$r, $g, $b] = self::rgb($color);
        [$hr, $hg, $hb] = self::rgb($hacia);
        for ($i = 0; $i <= 20; $i++) {
            $t = $i / 20;
            $c = sprintf('#%02x%02x%02x', (int) round($r + ($hr - $r) * $t), (int) round($g + ($hg - $g) * $t), (int) round($b + ($hb - $b) * $t));
            if (self::contraste($c, $fondo) >= 4.5) {
                return $c;
            }
        }
        return $hacia;
    }

    /** @return array{0:int,1:int,2:int} */
    private static function rgb(string $c): array
    {
        return [hexdec(substr($c, 1, 2)), hexdec(substr($c, 3, 2)), hexdec(substr($c, 5, 2))];
    }

    private static function luminancia(string $c): float
    {
        $l = array_map(static function (int $v): float {
            $s = $v / 255;
            return $s <= 0.03928 ? $s / 12.92 : (($s + 0.055) / 1.055) ** 2.4;
        }, self::rgb($c));
        return 0.2126 * $l[0] + 0.7152 * $l[1] + 0.0722 * $l[2];
    }

    /** @return array<string,int> imágenes cargadas => versión (sin leer los datos) */
    private static function imagenes(): array
    {
        if (self::$imagenes === null) {
            try {
                self::$imagenes = array_map('intval', Db::pdo()->query(
                    "SELECT clave, UNIX_TIMESTAMP(actualizado_en) FROM imagenes WHERE clave IN ('logo', 'logo_web', 'logo_pantalla', 'logo_icono')"
                )->fetchAll(\PDO::FETCH_KEY_PAIR));
            } catch (\PDOException) {
                self::$imagenes = []; // antes de migrar o sin base
            }
        }
        return self::$imagenes;
    }
}
