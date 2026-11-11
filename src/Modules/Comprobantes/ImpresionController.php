<?php
declare(strict_types=1);

namespace App\Modules\Comprobantes;

use App\Core\Db;
use App\Core\Mailer;
use App\Core\Session;
use App\Core\Valida;
use App\Core\View;
use App\Http\Request;
use App\Http\Response;

/** Impresión (página A4 y PDF) y envío por mail de un comprobante. Rutas en routes.php. */
final class ImpresionController
{
    private const COPIAS = ['ORIGINAL', 'DUPLICADO', 'TRIPLICADO'];

    /** Página imprimible (el navegador la imprime o la guarda como PDF). ?copia=DUPLICADO para otra copia. */
    public static function imprimir(Request $req, array $params): Response
    {
        $svc = new ComprobantesService();
        $h = $svc->obtener((int) $params['id']);
        if ($h === null) {
            return new Response(404);
        }
        $copia = self::copia($req);
        $d = ComprobanteImpreso::datos($h, $svc->emisor(), $copia);
        $res = Response::html(View::render('comprobantes/imprimir', ['d' => $d, 'id' => (int) $h['fe_clave'], 'copias' => self::COPIAS], null));
        $res->headers['X-Frame-Options'] = 'SAMEORIGIN';
        return $res;
    }

    public static function pdf(Request $req, array $params): Response
    {
        $svc = new ComprobantesService();
        $h = $svc->obtener((int) $params['id']);
        if ($h === null) {
            return new Response(404);
        }
        $d = ComprobanteImpreso::datos($h, $svc->emisor(), self::copia($req));
        return new Response(200, ComprobantePdf::generar($d), ['Content-Type' => 'application/pdf', 'Content-Disposition' => 'inline; filename="' . $d['nombre_archivo'] . '"']);
    }

    public static function mail(Request $req, array $params): Response
    {
        $svc = new ComprobantesService();
        $id = (int) $params['id'];
        $h = $svc->obtener($id);
        if ($h === null) {
            return new Response(404);
        }
        $volver = Response::redirect(url('/comprobantes/' . $id . '#mail'));
        $mails = Valida::mails((string) ($req->post['email'] ?? ''));
        if ($mails === null || $mails === '') {
            Session::flash('error', 'Escribí uno o más mails válidos (separados por punto y coma).');
            return $volver;
        }
        if (!Mailer::configurado()) {
            Session::flash('error', 'El envío de mails no está configurado en este servidor.');
            return $volver;
        }
        $emisor = $svc->emisor();
        $d = ComprobanteImpreso::datos($h, $emisor);
        $titulo = Tipos::titulo($h['tipo'], $h['letra']) . ' ' . $d['numero'];
        $texto = "Estimado cliente:\r\n\r\nAdjuntamos la {$titulo} del " . $d['fecha'] . " por " . plata_dec($h['neto']) . ".\r\n\r\nSaludos,\r\n" . ($emisor['empresa'] ?? '') . "\r\n";
        $error = Mailer::enviar(explode('; ', $mails), $titulo . ' - ' . ($emisor['empresa'] ?? ''), $texto, [$d['nombre_archivo'] => ComprobantePdf::generar($d)]);
        if ($error !== null) {
            Session::flash('error', 'No se pudo enviar: ' . $error);
            return $volver;
        }
        Db::pdo()->prepare('UPDATE subdiario SET fe_cemailenvio = ?, fe_dfechaenvio = CURDATE(), fe_limprimio = 1, actualizacion = NOW() WHERE fe_clave = ?')->execute([$mails, $id]);
        Session::flash('ok', 'Enviado a ' . $mails . '.');
        return $volver;
    }

    private static function copia(Request $req): string
    {
        $c = strtoupper((string) ($req->query['copia'] ?? 'ORIGINAL'));
        return in_array($c, self::COPIAS, true) ? $c : 'ORIGINAL';
    }
}
