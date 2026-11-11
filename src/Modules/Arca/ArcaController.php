<?php
declare(strict_types=1);

namespace App\Modules\Arca;

use App\Core\Db;
use App\Core\Session;
use App\Core\View;
use App\Http\Request;
use App\Http\Response;
use App\Modules\Auth\Auth;
use App\Modules\Comprobantes\ComprobantesService;
use App\Modules\Comprobantes\Tipos;

/**
 * Sistema → ARCA (permiso arca.administrar): estado del modo, diagnóstico de conexión, operaciones aprobadas por ARCA que no
 * quedaron grabadas (recuperarlas) y limpieza de los comprobantes de prueba (solo Master, con frase de confirmación).
 */
final class ArcaController
{
    public const FRASE = 'BORRAR PRUEBAS';

    public static function index(Request $req, array $params): Response
    {
        $pdo = Db::pdo();
        $pendientes = $pdo->query("SELECT id, operacion, estado, entorno, tipo_afip, pto_vta, numero, cae, total, detalle, creado_en
            FROM afip_operaciones WHERE estado IN ('aprobada','error','pendiente') ORDER BY id DESC LIMIT 50")->fetchAll();
        $pruebas = (int) $pdo->query('SELECT COUNT(*) FROM subdiario WHERE es_prueba = 1')->fetchColumn();
        return Response::html(View::render('arca/index', [
            'title' => 'ARCA', 'nav' => 'arca',
            'modo' => Arca::modo(), 'etiqueta' => Arca::etiqueta(), 'archivo' => Arca::archivoConfig(),
            'pendientes' => $pendientes, 'pruebas' => $pruebas, 'esMaster' => Auth::esMaster(), 'frase' => self::FRASE,
            'diagnostico' => Session::get('_diagnostico_arca'),
        ]));
    }

    public static function diagnostico(Request $req, array $params): Response
    {
        Session::set('_diagnostico_arca', Arca::diagnostico());
        return Response::redirect(url('/arca'));
    }

    public static function recuperar(Request $req, array $params): Response
    {
        try {
            $r = (new ComprobantesService())->recuperar((int) $params['id']);
            Session::flash('ok', 'Comprobante grabado (N° ' . $r['numero'] . ').');
            return Response::redirect(url('/comprobantes/' . $r['id']));
        } catch (\Throwable $e) {
            Session::flash('error', 'No se pudo grabar: ' . $e->getMessage());
            return Response::redirect(url('/arca'));
        }
    }

    /** Borra los comprobantes con es_prueba = 1 (y sus renglones, IVA, caja y cuenta corriente) y reacomoda los numeradores. */
    public static function borrarPruebas(Request $req, array $params): Response
    {
        if (!Auth::esMaster()) {
            return new Response(403);
        }
        if (trim((string) ($req->post['frase'] ?? '')) !== self::FRASE) {
            Session::flash('error', 'Para borrar escribí exactamente: ' . self::FRASE);
            return Response::redirect(url('/arca'));
        }
        $pdo = Db::pdo();
        $pdo->beginTransaction();
        try {
            $filas = $pdo->query('SELECT fe_clave AS id, tipo, letra, sucursal, comprobante FROM subdiario WHERE es_prueba = 1 FOR UPDATE')->fetchAll();
            $ids = array_map(static fn($f) => (int) $f['id'], $filas);
            $maxBorrado = [];
            foreach ($filas as $f) {
                $k = $f['tipo'] . '|' . $f['letra'] . '|' . $f['sucursal'];
                $maxBorrado[$k] = max($maxBorrado[$k] ?? 0, (int) $f['comprobante']);
            }
            foreach (array_chunk($ids, 200) as $lote) {
                $in = implode(',', array_fill(0, count($lote), '?'));
                foreach (['iva', 'estadistica', 'caja', 'ctact'] as $t) {
                    $pdo->prepare("DELETE FROM {$t} WHERE subdiario_id IN ({$in})")->execute($lote);
                }
                $pdo->prepare("DELETE FROM subdiario WHERE fe_clave IN ({$in})")->execute($lote);
            }
            $pdo->exec("UPDATE afip_operaciones SET subdiario_id = NULL WHERE subdiario_id IS NOT NULL AND subdiario_id NOT IN (SELECT fe_clave FROM subdiario)");
            // El numerador baja al último que quedó, solo si estaba justo en el último borrado (no pisa números de uso real).
            foreach ($maxBorrado as $k => $max) {
                [$tipo, $letra, $suc] = explode('|', $k);
                $col = Tipos::columnaNumerador($tipo, $letra);
                if ($col === null) {
                    continue;
                }
                $q = $pdo->prepare('SELECT COALESCE(MAX(comprobante), 0) FROM subdiario WHERE tipo = ? AND letra = ? AND sucursal = ?');
                $q->execute([$tipo, $letra, $suc]);
                $quedo = (int) $q->fetchColumn();
                $pdo->prepare("UPDATE sucursales SET {$col} = ? WHERE codigo = ? AND {$col} = ?")->execute([$quedo, $suc, $max]);
            }
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
        Session::flash('ok', count($ids) . ' comprobante(s) de prueba borrado(s).');
        return Response::redirect(url('/arca'));
    }
}
