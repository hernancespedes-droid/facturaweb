<?php
declare(strict_types=1);

namespace App\Modules\Soporte;

use App\Http\Request;
use App\Http\Response;
use App\Modules\Instancias\InstanciasService;

/**
 * API de soporte del sitio master, para las instalaciones de clientes (sin sesión ni CSRF: ruta /api/).
 * Cada pedido trae X-FW-Instancia (la dirección del cliente) y X-FW-Clave (la de su config, que escribió el master).
 *   GET  /api/v1/soporte/tickets                 lista (?estado=abiertos|cerrados|todos)
 *   POST /api/v1/soporte/tickets                 ticket nuevo
 *   GET  /api/v1/soporte/tickets/{id}            ticket con la conversación (sin notas internas)
 *   POST /api/v1/soporte/tickets/{id}/responder  respuesta del cliente
 *   POST /api/v1/soporte/tickets/{id}/estado     accion=cerrar|reabrir
 *   GET  /api/v1/soporte/adjuntos/{id}           un adjunto (base64 en JSON)
 * Solo responde en la instalación principal. Un cliente solo ve sus tickets.
 */
final class SoporteApi
{
    private static function json(array $d, int $status = 200): Response
    {
        return Response::json($d, $status);
    }

    private static function instancia(Request $req): ?string
    {
        if (!InstanciasService::disponible()) {
            return null;
        }
        $host = strtolower(trim((string) ($req->server['HTTP_X_FW_INSTANCIA'] ?? '')));
        $clave = (string) ($req->server['HTTP_X_FW_CLAVE'] ?? '');
        if ($host === '' || $clave === '' || !InstanciasService::hostValido($host)) {
            return null;
        }
        $esperada = InstanciasService::claveSoporte($host);
        return $esperada !== null && hash_equals($esperada, $clave) ? $host : null;
    }

    private static function rechazo(): Response
    {
        usleep(300000); // frena los intentos a ciegas
        return self::json(['ok' => false, 'error' => 'Instalación o clave inválida.'], 401);
    }

    /** @return array{nombre:string,datos:string}|null */
    private static function adjunto(Request $req): ?array
    {
        $b64 = (string) ($req->post['adjunto_b64'] ?? '');
        if ($b64 === '') {
            return null;
        }
        $datos = base64_decode($b64, true);
        return $datos === false ? null : ['nombre' => (string) ($req->post['adjunto_nombre'] ?? ''), 'datos' => $datos];
    }

    public static function listar(Request $req, array $params): Response
    {
        $i = self::instancia($req);
        if ($i === null) {
            return self::rechazo();
        }
        return self::json(['ok' => true, 'tickets' => (new SoporteService())->listarDe($i, ['estado' => (string) ($req->query['estado'] ?? 'abiertos'),
            'orden' => (string) ($req->query['orden'] ?? '')])]);
    }

    public static function crear(Request $req, array $params): Response
    {
        $i = self::instancia($req);
        if ($i === null) {
            return self::rechazo();
        }
        $d = array_intersect_key($req->post, array_flip(['asunto', 'tipo', 'prioridad', 'descripcion', 'usuario', 'usuario_mail', 'version', 'pantalla', 'operacion']));
        $d['instancia_nombre'] = InstanciasService::nombreDe($i);
        $d['adjunto'] = self::adjunto($req);
        $r = (new SoporteService())->crear($i, $d);
        return self::json($r, $r['ok'] ? 200 : 422);
    }

    public static function ver(Request $req, array $params): Response
    {
        $i = self::instancia($req);
        if ($i === null) {
            return self::rechazo();
        }
        $t = (new SoporteService())->ver((int) $params['id'], $i);
        return $t === null ? self::json(['ok' => false, 'error' => 'El ticket no existe.'], 404) : self::json(['ok' => true, 'ticket' => self::publico($t)]);
    }

    public static function responder(Request $req, array $params): Response
    {
        $i = self::instancia($req);
        if ($i === null) {
            return self::rechazo();
        }
        $r = (new SoporteService())->responderCliente((int) $params['id'], $i, ['texto' => (string) ($req->post['texto'] ?? ''),
            'usuario' => (string) ($req->post['usuario'] ?? ''), 'adjunto' => self::adjunto($req)]);
        return self::json($r, $r['ok'] ? 200 : 422);
    }

    public static function estado(Request $req, array $params): Response
    {
        $i = self::instancia($req);
        if ($i === null) {
            return self::rechazo();
        }
        $r = (new SoporteService())->cambiarEstadoCliente((int) $params['id'], $i, (string) ($req->post['accion'] ?? ''), (string) ($req->post['usuario'] ?? ''));
        return self::json($r, $r['ok'] ? 200 : 422);
    }

    public static function adjuntoVer(Request $req, array $params): Response
    {
        $i = self::instancia($req);
        if ($i === null) {
            return self::rechazo();
        }
        $a = (new SoporteService())->adjunto((int) $params['id'], $i);
        return $a === null ? self::json(['ok' => false, 'error' => 'No existe.'], 404)
            : self::json(['ok' => true, 'nombre' => $a['nombre'], 'tipo' => $a['tipo'], 'datos' => base64_encode((string) $a['datos'])]);
    }

    /** Lo que ve el cliente de su ticket (sin el error de aviso ni datos internos). @param array<string,mixed> $t */
    public static function publico(array $t): array
    {
        unset($t['aviso_error']);
        return $t;
    }
}
