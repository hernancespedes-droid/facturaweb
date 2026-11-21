<?php
declare(strict_types=1);

namespace App\Modules\Importacion;

use App\App;
use App\Core\Db;
use App\Core\Session;
use App\Core\View;
use App\Http\Request;
use App\Http\Response;
use App\Modules\Auth\Auth;

/**
 * Sistema → Importar datos: 1) elegir qué y subir el archivo; 2) elegir qué columna va a cada campo (con vista previa, sugerido solo por
 * los títulos o por un modelo guardado); 3) probar (no graba) o importar; 4) resultado con los errores y avisos por fila.
 * El archivo queda en storage/importacion/ (de esta instalación) hasta que se importa o se descarta; los de más de un día se borran solos.
 */
final class ImportacionController
{
    private const SESION = 'importacion';
    private const MAX_BYTES = 20 * 1024 * 1024;

    /** Destinos que puede usar el usuario (permiso propio de cada uno y el módulo de stock prendido). @return array<string,string> */
    public static function destinosPermitidos(): array
    {
        $out = [];
        foreach (Destinos::TITULOS as $d => $t) {
            if (Auth::puede(Destinos::PERMISO[$d]) && ($d !== 'stock' || \App\Core\Modulos::activo('stock'))) {
                $out[$d] = $t;
            }
        }
        return $out;
    }

    public static function index(Request $req, array $params): Response
    {
        $hist = Db::pdo()->query('SELECT i.*, u.nombre AS usuario FROM importaciones i LEFT JOIN usuarios u ON u.id = i.usuario_id
            WHERE i.filas > 0 ORDER BY i.id DESC LIMIT 50')->fetchAll();
        return Response::html(View::render('importacion/index', ['title' => 'Importar datos', 'nav' => 'importar', 'destinos' => self::destinosPermitidos(),
            'historial' => $hist, 'maximo' => self::maximoSubida()]));
    }

    /** Planilla modelo (.xlsx) con los títulos que se reconocen solos y una fila de ejemplo. */
    public static function plantilla(Request $req, array $params): Response
    {
        $d = (string) ($req->query['destino'] ?? '');
        if (!isset(self::destinosPermitidos()[$d])) {
            return new Response(404);
        }
        $campos = Destinos::campos($d);
        $x = new \App\Core\XlsxWriter(mb_substr(Destinos::TITULOS[$d], 0, 31), array_map(static fn ($c) => (string) $c['label'], array_values($campos)),
            array_map(static fn ($c) => max(12, min(40, mb_strlen((string) $c['label']) + 4)), array_values($campos)));
        $ej = self::ejemplo($d);
        $x->fila(array_map(static fn ($k) => $ej[$k] ?? '', array_keys($campos)));
        return new Response(200, $x->contenido(), ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename="modelo-' . $d . '.xlsx"', 'Cache-Control' => 'no-store']);
    }

    /** @return array<string,string> */
    private static function ejemplo(string $d): array
    {
        return match ($d) {
            'clientes' => ['codigo' => '', 'razon_social' => 'EJEMPLO SRL', 'iva' => 'Responsable inscripto', 'cuit' => '30-71234567-8', 'domicilio' => 'Av. Siempreviva 742',
                'localidad' => 'Morón', 'codigo_postal' => '1708', 'provincia' => 'Buenos Aires', 'telefono' => '11 4444-5555', 'emailfc' => 'facturas@ejemplo.com', 'forma_pago' => 'Cuenta corriente'],
            'articulos' => ['codigo' => 'A001', 'descripcion' => 'ARTÍCULO DE EJEMPLO', 'alicuota' => '21', 'moneda' => 'Pesos', 'costo' => '1000', 'precio_de_venta_1' => '1650,50', 'estado' => 'Activo'],
            'stock' => ['articulo' => 'A001', 'cantidad' => '25', 'deposito' => ''],
            'subdiario' => ['fecha' => '15/03/2025', 'tipo' => 'Factura', 'letra' => 'A', 'punto_venta' => '3', 'numero' => '1234', 'cuit' => '30-71234567-8',
                'razon_social' => 'EJEMPLO SRL', 'neto_gravado' => '1000', 'iva_21' => '210', 'total' => '1210', 'saldo' => '1210', 'cae' => '75112345678901', 'vto_cae' => '25/03/2025'],
            default => [],
        };
    }

    public static function subir(Request $req, array $params): Response
    {
        $destino = (string) ($req->post['destino'] ?? '');
        if (!isset(self::destinosPermitidos()[$destino])) {
            Session::flash('error', 'Elegí qué querés importar.');
            return Response::redirect(url('/importar'));
        }
        $f = $req->files['archivo'] ?? null;
        if (!is_array($f) || ($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_file((string) $f['tmp_name'])) {
            Session::flash('error', 'Elegí el archivo (.xlsx, .csv o .txt; como mucho ' . self::maximoSubida() . ').');
            return Response::redirect(url('/importar'));
        }
        $nombre = basename(str_replace('\\', '/', (string) ($f['name'] ?? 'archivo')));
        $ext = strtolower(pathinfo($nombre, PATHINFO_EXTENSION));
        if (!in_array($ext, Lector::EXTENSIONES, true) || (int) ($f['size'] ?? 0) > self::MAX_BYTES) {
            Session::flash('error', $ext === 'xls' ? 'El Excel viejo (.xls) no se puede leer: guardalo como .xlsx o CSV.' : 'El archivo tiene que ser .xlsx, .csv o .txt de hasta 20 MB.');
            return Response::redirect(url('/importar'));
        }
        self::limpiarViejos();
        $token = bin2hex(random_bytes(12));
        $dir = App::storage('importacion');
        if (!is_dir($dir) && !@mkdir($dir, 0775, true)) {
            throw new \RuntimeException('No se pudo crear la carpeta storage/importacion.');
        }
        $destinoArchivo = $dir . '/' . $token . '.' . $ext;
        if (!@move_uploaded_file((string) $f['tmp_name'], $destinoArchivo) && !@copy((string) $f['tmp_name'], $destinoArchivo)) {
            throw new \RuntimeException('No se pudo guardar el archivo subido.');
        }
        $conTitulos = ($req->post['titulos'] ?? '1') === '1';
        try {
            Lector::leer($destinoArchivo, $nombre, $conTitulos);
        } catch (\RuntimeException $e) {
            @unlink($destinoArchivo);
            Session::flash('error', $e->getMessage());
            return Response::redirect(url('/importar'));
        }
        $lista = (array) Session::get(self::SESION, []);
        $lista = array_slice($lista, -4, null, true); // como mucho 5 archivos en curso por sesión
        $lista[$token] = ['archivo' => $destinoArchivo, 'nombre' => $nombre, 'destino' => $destino, 'titulos' => $conTitulos];
        Session::set(self::SESION, $lista);
        return Response::redirect(url('/importar/columnas?t=' . $token));
    }

    /** @return array{archivo:string,nombre:string,destino:string,titulos:bool}|null */
    private static function enCurso(string $token): ?array
    {
        $x = ((array) Session::get(self::SESION, []))[$token] ?? null;
        if (!is_array($x) || !is_file((string) $x['archivo']) || !isset(self::destinosPermitidos()[$x['destino']])) {
            return null;
        }
        return $x;
    }

    public static function columnas(Request $req, array $params): Response
    {
        $token = (string) ($req->query['t'] ?? '');
        $x = self::enCurso($token);
        if ($x === null) {
            Session::flash('error', 'Ese archivo ya no está: subilo de nuevo.');
            return Response::redirect(url('/importar'));
        }
        $tabla = Lector::leer($x['archivo'], $x['nombre'], $x['titulos']);
        $modelos = Modelos::listar($x['destino']);
        $modeloId = (int) ($req->query['modelo'] ?? 0);
        $previo = Session::get(self::SESION . '_form_' . $token);
        if (is_array($previo) && $modeloId === 0) {
            $mapa = $previo['mapa'];
            $op = $previo['op'];
        } elseif ($modeloId > 0 && ($m = Modelos::obtener($modeloId)) !== null && $m['destino'] === $x['destino']) {
            $mapa = Modelos::aIndices($m['mapa'], $tabla['titulos']);
            $op = $m['opciones'];
        } else {
            $mapa = Destinos::adivinar($x['destino'], $tabla['titulos']);
            $op = [];
        }
        $svc = $x['destino'] === 'stock' ? new \App\Modules\Stock\StockService() : null;
        return Response::html(View::render('importacion/columnas', ['title' => 'Importar ' . mb_strtolower(Destinos::TITULOS[$x['destino']]), 'nav' => 'importar',
            't' => $token, 'x' => $x, 'tabla' => $tabla, 'campos' => Destinos::campos($x['destino']), 'mapa' => $mapa, 'op' => $op, 'modelos' => $modelos,
            'modeloId' => $modeloId, 'depositos' => $svc?->depositos() ?? [], 'principal' => $svc?->depositoPrincipal()]));
    }

    public static function procesar(Request $req, array $params): Response
    {
        $token = (string) ($req->post['t'] ?? '');
        $x = self::enCurso($token);
        if ($x === null) {
            Session::flash('error', 'Ese archivo ya no está: subilo de nuevo.');
            return Response::redirect(url('/importar'));
        }
        if (($req->post['accion'] ?? '') === 'descartar') {
            self::olvidar($token);
            Session::flash('ok', 'Se descartó el archivo; no se importó nada.');
            return Response::redirect(url('/importar'));
        }
        $tabla = Lector::leer($x['archivo'], $x['nombre'], $x['titulos']);
        $p = $req->post;
        $previo = Session::get(self::SESION . '_form_' . $token);
        if (($p['repetir'] ?? '') === '1' && is_array($previo)) { // «Importar ahora» después de probar: lo mismo que se probó
            $mapa = $previo['mapa'];
            $op = $previo['op'];
        } else {
            $mapa = [];
            foreach ((array) ($p['col'] ?? []) as $campo => $i) {
                if (is_string($campo) && is_string($i) && $i !== '' && ctype_digit($i) && (int) $i < count($tabla['titulos'])) {
                    $mapa[$campo] = (int) $i;
                }
            }
            $op = [
                'existentes' => ($p['existentes'] ?? '') === 'saltear' ? 'saltear' : 'actualizar',
                'parcial' => ($p['parcial'] ?? '') === '1',
                'crear_faltantes' => ($p['crear_faltantes'] ?? '') === '1',
                'modo_stock' => ($p['modo_stock'] ?? '') === 'sumar' ? 'sumar' : 'dejar',
                'deposito' => (int) ($p['deposito'] ?? 0),
                'motivo' => mb_substr(trim((string) ($p['motivo'] ?? '')), 0, 120),
                'crear_clientes' => ($p['crear_clientes'] ?? '') === '1',
                'cliente_defecto' => (int) ($p['cliente_defecto'] ?? 0),
            ];
            Session::set(self::SESION . '_form_' . $token, ['mapa' => $mapa, 'op' => $op]);
            $nombreModelo = trim((string) ($p['modelo_nombre'] ?? ''));
            if ($nombreModelo !== '') {
                Modelos::guardar($x['destino'], $nombreModelo, Modelos::aTitulos($mapa, $tabla['titulos']), $op, Auth::id());
            }
        }
        $simular = ($p['accion'] ?? '') !== 'importar';
        try {
            $r = (new Importador(Db::pdo(), $x['destino'], $mapa, $op))->ejecutar($tabla, $simular, Auth::id(), $x['nombre']);
        } catch (\RuntimeException $e) {
            Session::flash('error', $e->getMessage());
            return Response::redirect(url('/importar/columnas?t=' . $token));
        }
        $r['simulado'] = $simular;
        $r['destino'] = $x['destino'];
        $r['nombre'] = $x['nombre'];
        Session::set(self::SESION . '_resultado_' . $token, $r);
        if ($r['grabado']) {
            self::olvidar($token, false);
        }
        return Response::redirect(url('/importar/resultado?t=' . $token));
    }

    public static function resultado(Request $req, array $params): Response
    {
        $token = (string) ($req->query['t'] ?? '');
        $r = Session::get(self::SESION . '_resultado_' . $token);
        if (!is_array($r)) {
            return Response::redirect(url('/importar'));
        }
        return Response::html(View::render('importacion/resultado', ['title' => 'Resultado de la importación', 'nav' => 'importar', 't' => $token, 'r' => $r,
            'sigue' => self::enCurso($token) !== null]));
    }

    public static function borrarModelo(Request $req, array $params): Response
    {
        $id = (int) ($req->post['id'] ?? 0);
        $m = Modelos::obtener($id);
        if ($m !== null && isset(self::destinosPermitidos()[$m['destino']])) {
            Modelos::borrar($id);
            Session::flash('ok', 'Se borró el modelo «' . $m['nombre'] . '».');
        }
        $t = (string) ($req->post['t'] ?? '');
        return Response::redirect(url($t !== '' ? '/importar/columnas?t=' . $t : '/importar'));
    }

    private static function olvidar(string $token, bool $resultado = true): void
    {
        $lista = (array) Session::get(self::SESION, []);
        if (isset($lista[$token])) {
            @unlink((string) $lista[$token]['archivo']);
            unset($lista[$token]);
            Session::set(self::SESION, $lista);
        }
        Session::forget(self::SESION . '_form_' . $token);
        if ($resultado) {
            Session::forget(self::SESION . '_resultado_' . $token);
        }
    }

    /** Borra los archivos subidos hace más de un día (de importaciones que no se terminaron). */
    private static function limpiarViejos(): void
    {
        foreach (glob(App::storage('importacion') . '/*') ?: [] as $f) {
            if (is_file($f) && filemtime($f) < time() - 86400) {
                @unlink($f);
            }
        }
    }

    private static function maximoSubida(): string
    {
        $a = (string) ini_get('upload_max_filesize');
        $b = (string) ini_get('post_max_size');
        $bytes = static function (string $v): int {
            $n = (int) $v;
            return match (strtoupper(substr(trim($v), -1))) { 'G' => $n << 30, 'M' => $n << 20, 'K' => $n << 10, default => $n };
        };
        $min = min(array_filter([$bytes($a), $bytes($b), self::MAX_BYTES]) ?: [self::MAX_BYTES]);
        return round($min / 1048576, 1) . ' MB';
    }
}
