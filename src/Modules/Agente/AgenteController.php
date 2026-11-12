<?php
declare(strict_types=1);

namespace App\Modules\Agente;

use App\Core\Db;
use App\Core\Session;
use App\Core\View;
use App\Http\Request;
use App\Http\Response;
use App\Modules\Auth\Auth;

/** Sistema → Agente (permiso agente.administrar): claves de API y últimos envíos del agente. */
final class AgenteController
{
    public static function index(Request $req, array $params): Response
    {
        $envios = Db::pdo()->query("SELECT * FROM migracion_vfp WHERE origen LIKE 'agente:%' ORDER BY id DESC LIMIT 20")->fetchAll();
        return Response::html(View::render('agente/index', ['title' => 'Agente de sincronización', 'nav' => 'agente', 'claves' => ApiClaves::listar(), 'envios' => $envios, 'vfp' => ExportadorVfp::estado(),
            'nueva' => Session::get('_clave_nueva')]));
    }

    public static function crear(Request $req, array $params): Response
    {
        $nombre = trim((string) ($req->post['nombre'] ?? ''));
        if ($nombre === '') {
            Session::flash('error', 'Poné un nombre para reconocer la clave (por ejemplo, la PC donde corre el agente).');
            return Response::redirect(url('/agente'));
        }
        Session::set('_clave_nueva', ['nombre' => $nombre, 'clave' => ApiClaves::crear($nombre, Auth::id())]);
        return Response::redirect(url('/agente'));
    }

    /** Baja el próximo lote para el VFP como archivo, para probar IMPORTAR_WEB a mano. No marca nada: lo pendiente sigue pendiente. */
    public static function descargarLote(Request $req, array $params): Response
    {
        $p = ExportadorVfp::pendientes(50);
        if ($p['comprobantes'] + $p['movimientos'] === 0) {
            Session::flash('info', 'No hay nada pendiente para el VFP.');
            return Response::redirect(url('/agente'));
        }
        return new Response(200, $p['texto'], ['Content-Type' => 'text/plain; charset=utf-8', 'Content-Disposition' => 'attachment; filename="lote_' . $p['lote'] . '.txt"']);
    }

    public static function reintentarVfp(Request $req, array $params): Response
    {
        $n = ExportadorVfp::reintentar();
        Session::flash('ok', $n > 0 ? "{$n} comprobante(s) vuelven a la cola para el VFP." : 'No había comprobantes rechazados.');
        return Response::redirect(url('/agente'));
    }

    public static function revocar(Request $req, array $params): Response
    {
        ApiClaves::revocar((int) $params['id']);
        Session::flash('ok', 'Clave revocada: el agente que la usaba ya no puede enviar.');
        return Response::redirect(url('/agente'));
    }

    /** La clave nueva se muestra una sola vez: al verla se borra de la sesión. */
    public static function olvidar(): void
    {
        Session::forget('_clave_nueva');
    }
}
