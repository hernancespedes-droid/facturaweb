<?php
declare(strict_types=1);

namespace App\Modules\MercadoPago;

use App\Core\Config;

/**
 * Mercado Pago: el único lugar que habla con su API (como Modules/Arca/Arca con ARCA). Cobros con QR dinámico (modelo atendido), link de pago (Checkout Pro)
 * y consulta de pagos. La cuenta es del cliente (una por instalación).
 *
 * Modos (config/local.php → 'mp' => ['modo' => ...]):
 *   apagado      no se ofrece Mercado Pago (por omisión).
 *   simulado     Mercado Pago simulado dentro del sistema (sin cuenta): el «pago» se aprueba con un botón. Sirve para probar todo el circuito.
 *   produccion   cuenta real: 'access_token' (credenciales de producción de la aplicación), 'webhook_secret' (clave secreta de las notificaciones),
 *                y para el QR 'user_id' (id de la cuenta vendedora) y 'pos_id' (external_id de la caja/POS creada en Mercado Pago).
 * Las claves van SOLO en config/local.php (fuera del repositorio) y nunca se muestran ni se guardan en la base.
 *
 * NOTA: la parte de producción está armada con la documentación de Mercado Pago y probada con respuestas de ejemplo; todavía no se probó contra una cuenta real.
 */
final class MercadoPago
{
    public const MODOS = ['apagado', 'simulado', 'produccion'];
    private const API = 'https://api.mercadopago.com';

    /** Para las pruebas: callable(string $metodo, string $url, array $cabeceras, ?string $cuerpo): array{status:int,body:string}. Null = curl de verdad. @var ?callable */
    public static $transporte = null;

    public static function modo(): string
    {
        $m = strtolower(trim((string) Config::get('mp.modo', 'apagado')));
        return in_array($m, self::MODOS, true) ? $m : 'apagado';
    }

    public static function activo(): bool
    {
        return self::modo() !== 'apagado';
    }

    /** 'S' simulado, 'P' producción. */
    public static function letraModo(): string
    {
        return self::modo() === 'produccion' ? 'P' : 'S';
    }

    public static function etiqueta(): string
    {
        return ['apagado' => 'Mercado Pago apagado', 'simulado' => 'MERCADO PAGO SIMULADO: los pagos son de mentira', 'produccion' => 'Mercado Pago producción'][self::modo()];
    }

    public static function expiraMinutos(): int
    {
        return max(5, min(1440, (int) Config::get('mp.expira_minutos', 30)));
    }

    /** ¿Está completa la configuración de producción? Devuelve lo que falta (sin mostrar los valores). @return string[] */
    public static function faltantes(): array
    {
        if (self::modo() !== 'produccion') {
            return [];
        }
        $f = [];
        foreach (['access_token' => 'access_token (credencial de producción)', 'webhook_secret' => 'webhook_secret (clave secreta de las notificaciones)'] as $k => $t) {
            if (trim((string) Config::get('mp.' . $k, '')) === '') {
                $f[] = $t;
            }
        }
        return $f;
    }

    /** ¿Hay caja (POS) configurada para el QR? Sin ella solo se ofrece el link de pago. */
    public static function conQr(): bool
    {
        return self::modo() === 'simulado' || (trim((string) Config::get('mp.user_id', '')) !== '' && trim((string) Config::get('mp.pos_id', '')) !== '');
    }

    public static function urlNotificaciones(): string
    {
        return url_absoluta(url('/api/v1/mercadopago/webhook'));
    }

    // ---------------------------------------------------------------- crear el cobro

    /**
     * Crea el link de pago y, si hay caja configurada, el QR dinámico.
     *
     * @param array{token:string,importe:float|string,descripcion:string,vence_en:string} $cobro
     * @return array{link:?string,qr:?string,ref:?string}
     */
    public static function crear(array $cobro): array
    {
        if (self::modo() === 'simulado') {
            $url = url_absoluta(url('/mercadopago/simulador/' . $cobro['token']));
            return ['link' => $url, 'qr' => $url, 'ref' => 'SIM-' . substr($cobro['token'], 0, 8)];
        }
        if (self::modo() !== 'produccion' || self::faltantes() !== []) {
            throw new MercadoPagoError('Mercado Pago no está configurado en este servidor.');
        }
        $monto = round((float) $cobro['importe'], 2);
        $vence = date('c', (int) strtotime($cobro['vence_en']));
        $titulo = mb_substr($cobro['descripcion'] !== '' ? $cobro['descripcion'] : 'Venta', 0, 100);
        $item = ['title' => $titulo, 'quantity' => 1, 'unit_price' => $monto, 'currency_id' => 'ARS'];
        $pref = self::llamar('POST', '/checkout/preferences', [
            'items' => [$item], 'external_reference' => $cobro['token'], 'notification_url' => self::urlNotificaciones(),
            'expires' => true, 'expiration_date_to' => $vence, 'statement_descriptor' => 'VENTA',
        ], $cobro['token'] . '-pref');
        $link = (string) ($pref['init_point'] ?? '');
        if ($link === '') {
            throw new MercadoPagoError('Mercado Pago no devolvió el link de pago.');
        }
        $qr = null;
        $ref = (string) ($pref['id'] ?? '');
        if (self::conQr()) {
            $uid = rawurlencode((string) Config::get('mp.user_id'));
            $pos = rawurlencode((string) Config::get('mp.pos_id'));
            $orden = self::llamar('POST', "/instore/orders/qr/seller/collectors/{$uid}/pos/{$pos}/qrs", [
                'external_reference' => $cobro['token'], 'title' => $titulo, 'description' => $titulo, 'total_amount' => $monto,
                'notification_url' => self::urlNotificaciones(), 'expiration_date' => $vence,
                'items' => [['sku_number' => 'VENTA', 'category' => 'marketplace', 'title' => $titulo, 'unit_price' => $monto, 'quantity' => 1, 'unit_measure' => 'unit', 'total_amount' => $monto]],
            ], $cobro['token'] . '-qr');
            $qr = (string) ($orden['qr_data'] ?? '') ?: null;
            $ref = (string) ($orden['in_store_order_id'] ?? $ref);
        }
        return ['link' => $link, 'qr' => $qr, 'ref' => $ref !== '' ? $ref : null];
    }

    // ---------------------------------------------------------------- consultar pagos

    /**
     * Un pago por su id, tal como lo ve Mercado Pago (nunca se confía en lo que dice la notificación).
     * @return array{id:string,estado:string,referencia:string,monto:float,ingreso:?array}|null
     */
    public static function pago(string $id): ?array
    {
        if (!preg_match('/^\d{1,20}$/', $id)) {
            return null;
        }
        return self::normalizar(self::llamar('GET', '/v1/payments/' . $id));
    }

    /** El pago aprobado de un cobro (por su external_reference), o null si todavía no hay. @return array{id:string,estado:string,referencia:string,monto:float}|null */
    public static function pagoDeReferencia(string $referencia): ?array
    {
        $r = self::llamar('GET', '/v1/payments/search?sort=date_created&criteria=desc&external_reference=' . rawurlencode($referencia));
        foreach ((array) ($r['results'] ?? []) as $p) {
            $n = self::normalizar((array) $p);
            if ($n !== null && $n['estado'] === 'approved') {
                return $n;
            }
        }
        return null;
    }

    /** Orden comercial (las notificaciones del QR llegan como merchant_order): el pago aprobado que trae, si la orden está paga. @return array{id:string,estado:string,referencia:string,monto:float}|null */
    public static function pagoDeOrden(string $ordenId): ?array
    {
        if (!preg_match('/^\d{1,20}$/', $ordenId)) {
            return null;
        }
        $o = self::llamar('GET', '/merchant_orders/' . $ordenId);
        foreach ((array) ($o['payments'] ?? []) as $p) {
            if (($p['status'] ?? '') === 'approved' && isset($p['id'])) {
                return self::pago((string) $p['id']);
            }
        }
        return null;
    }

    /** @param array<string,mixed> $p @return array{id:string,estado:string,referencia:string,monto:float}|null */
    private static function normalizar(array $p): ?array
    {
        if (!isset($p['id'])) {
            return null;
        }
        return ['id' => (string) $p['id'], 'estado' => (string) ($p['status'] ?? ''), 'referencia' => (string) ($p['external_reference'] ?? ''), 'monto' => round((float) ($p['transaction_amount'] ?? 0), 2),
            'ingreso' => self::ingreso($p)];
    }

    /**
     * Los datos de un pago que se guardan como ingreso de dinero (Ingresos de Mercado Pago). Null si el pago no es un cobro de esta cuenta (un pago que hizo la cuenta,
     * cuando se conoce su user_id).
     *
     * @param array<string,mixed> $p el pago como lo devuelve /v1/payments
     * @return array{mp_pago_id:string,estado:string,fecha:string,monto:float,neto:?float,moneda:string,medio:string,medio_detalle:string,pagador:string,pagador_mail:string,pagador_doc:string,descripcion:string,referencia:string}|null
     */
    public static function ingreso(array $p): ?array
    {
        if (!isset($p['id'])) {
            return null;
        }
        $yo = trim((string) Config::get('mp.user_id', ''));
        if ($yo !== '' && isset($p['collector_id']) && (string) $p['collector_id'] !== $yo) {
            return null; // la cuenta no es la que cobra: es un pago que hizo ella
        }
        $payer = is_array($p['payer'] ?? null) ? $p['payer'] : [];
        $nombre = trim((string) ($payer['first_name'] ?? '') . ' ' . (string) ($payer['last_name'] ?? ''));
        $doc = is_array($payer['identification'] ?? null) ? trim((string) ($payer['identification']['type'] ?? '') . ' ' . (string) ($payer['identification']['number'] ?? '')) : '';
        $neto = $p['transaction_details']['net_received_amount'] ?? null;
        $cuando = (string) ($p['date_approved'] ?? $p['date_created'] ?? '');
        $ts = $cuando !== '' ? strtotime($cuando) : false;
        return [
            'mp_pago_id' => (string) $p['id'], 'estado' => mb_substr((string) ($p['status'] ?? ''), 0, 20),
            'fecha' => date('Y-m-d H:i:s', $ts !== false ? $ts : time()),
            'monto' => round((float) ($p['transaction_amount'] ?? 0), 2), 'neto' => is_numeric($neto) ? round((float) $neto, 2) : null,
            'moneda' => mb_substr((string) ($p['currency_id'] ?? 'ARS'), 0, 3), 'medio' => mb_substr((string) ($p['payment_type_id'] ?? ''), 0, 30),
            'medio_detalle' => mb_substr((string) ($p['payment_method_id'] ?? ''), 0, 40), 'pagador' => mb_substr($nombre, 0, 150),
            'pagador_mail' => mb_substr((string) ($payer['email'] ?? ''), 0, 150), 'pagador_doc' => mb_substr($doc, 0, 40),
            'descripcion' => mb_substr((string) ($p['description'] ?? ''), 0, 255), 'referencia' => mb_substr((string) ($p['external_reference'] ?? ''), 0, 64),
        ];
    }

    /**
     * Pagos actualizados desde $desde (para la consulta periódica de ingresos), del más viejo al más nuevo. Trae hasta 500 por consulta.
     * @return array<int,array<string,mixed>> los pagos crudos de Mercado Pago
     */
    public static function pagosDesde(int $desde): array
    {
        $out = [];
        $inicio = date('Y-m-d\TH:i:s.000P', $desde);
        for ($offset = 0; $offset < 500; $offset += 100) {
            $r = self::llamar('GET', '/v1/payments/search?sort=date_last_updated&criteria=asc&range=date_last_updated&begin_date=' . rawurlencode($inicio)
                . '&end_date=NOW&limit=100&offset=' . $offset);
            $lote = array_values(array_filter((array) ($r['results'] ?? []), 'is_array'));
            array_push($out, ...$lote);
            if (count($lote) < 100) {
                break;
            }
        }
        return $out;
    }

    // ---------------------------------------------------------------- notificaciones firmadas

    /**
     * Verifica la firma de una notificación: el encabezado x-signature trae «ts=…,v1=…» y v1 es el HMAC-SHA256 (clave secreta del webhook) de
     * «id:<data.id>;request-id:<x-request-id>;ts:<ts>;». Sin clave configurada no se acepta ninguna.
     */
    public static function firmaValida(string $dataId, string $xSignature, string $xRequestId, ?string $secreto = null): bool
    {
        $secreto ??= (string) Config::get('mp.webhook_secret', '');
        if ($secreto === '' || $xSignature === '') {
            return false;
        }
        $ts = '';
        $v1 = '';
        foreach (explode(',', $xSignature) as $parte) {
            [$k, $v] = array_pad(explode('=', trim($parte), 2), 2, '');
            if ($k === 'ts') {
                $ts = $v;
            } elseif ($k === 'v1') {
                $v1 = $v;
            }
        }
        if ($ts === '' || $v1 === '') {
            return false;
        }
        $id = ctype_alnum($dataId) ? strtolower($dataId) : $dataId;
        $manifiesto = ($id !== '' ? 'id:' . $id . ';' : '') . ($xRequestId !== '' ? 'request-id:' . $xRequestId . ';' : '') . 'ts:' . $ts . ';';
        return hash_equals(hash_hmac('sha256', $manifiesto, $secreto), strtolower($v1));
    }

    // ---------------------------------------------------------------- HTTP

    /** @param ?array<string,mixed> $cuerpo @return array<string,mixed> */
    private static function llamar(string $metodo, string $ruta, ?array $cuerpo = null, ?string $idempotencia = null): array
    {
        if (self::modo() !== 'produccion' || self::faltantes() !== []) {
            throw new MercadoPagoError('Mercado Pago no está configurado en este servidor.');
        }
        $cab = ['Authorization: Bearer ' . (string) Config::get('mp.access_token'), 'Content-Type: application/json', 'Accept: application/json'];
        if ($idempotencia !== null) {
            $cab[] = 'X-Idempotency-Key: ' . $idempotencia;
        }
        $json = $cuerpo === null ? null : (string) json_encode($cuerpo, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $r = self::$transporte !== null ? (self::$transporte)($metodo, self::API . $ruta, $cab, $json) : self::curl($metodo, self::API . $ruta, $cab, $json);
        $datos = json_decode($r['body'], true);
        if ($r['status'] < 200 || $r['status'] >= 300) {
            $msg = is_array($datos) ? (string) ($datos['message'] ?? $datos['error'] ?? '') : '';
            throw new MercadoPagoError('Mercado Pago respondió con error ' . $r['status'] . ($msg !== '' ? ': ' . mb_substr($msg, 0, 160) : '.'));
        }
        return is_array($datos) ? $datos : [];
    }

    /** @param string[] $cabeceras @return array{status:int,body:string} */
    private static function curl(string $metodo, string $url, array $cabeceras, ?string $cuerpo): array
    {
        if (!function_exists('curl_init')) {
            throw new MercadoPagoError('Este servidor no tiene curl: no se puede hablar con Mercado Pago.');
        }
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_CUSTOMREQUEST => $metodo, CURLOPT_HTTPHEADER => $cabeceras, CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 8, CURLOPT_TIMEOUT => 20]);
        if ($cuerpo !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $cuerpo);
        }
        $body = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        if ($body === false) {
            throw new MercadoPagoError('No se pudo conectar con Mercado Pago' . ($err !== '' ? ' (' . $err . ')' : '') . '.');
        }
        return ['status' => $status, 'body' => (string) $body];
    }
}
