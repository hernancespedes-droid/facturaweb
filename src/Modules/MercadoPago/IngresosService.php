<?php
declare(strict_types=1);

namespace App\Modules\MercadoPago;

use App\Core\Db;
use App\Core\Mailer;
use App\Core\Orden;
use App\Modules\Configuracion\Configuracion;
use PDO;

/**
 * Ingresos de dinero a la cuenta de Mercado Pago (0.41.0), independientes de las formas de pago del sistema: todo pago aprobado que entra a la cuenta
 * (una venta cobrada desde la web, un QR fijo, un link, una transferencia…) queda registrado una sola vez y se avisa a quienes tienen mercadopago.ingresos.
 *
 * Se entera por dos caminos que se complementan:
 *  - la notificación firmada de Mercado Pago (MercadoPagoController::notificacion), al instante;
 *  - la consulta periódica (consultarSiToca): como mucho una por minuto, la disparan las pantallas abiertas (campanita) o bin/mp-ingresos.php por cron.
 *    Recupera lo que no llegó por notificación. Pide los pagos actualizados desde la consulta anterior menos 10 minutos de margen.
 * Un mismo pago puede llegar por los dos caminos: mp_pago_id es único y el segundo solo actualiza el estado (por ejemplo, si se devolvió).
 *
 * DECISIÓN D-85 (Claude, a confirmar por Hernán): se registran solo los pagos aprobados; uno que después se devuelve o se desconoce queda con su estado nuevo
 * (no se borra). La primera consulta mira las últimas 24 horas. El mail se manda solo por ingresos nuevos de las últimas 2 horas (no por lo atrasado).
 */
final class IngresosService
{
    public const CLAVE_CONSULTA = 'interno.mp_ingresos_consulta';
    public const CLAVE_MAILS = 'mercadopago.ingresos_mails';
    public const MEDIOS = ['account_money' => 'Dinero en cuenta', 'credit_card' => 'Tarjeta de crédito', 'debit_card' => 'Tarjeta de débito', 'prepaid_card' => 'Tarjeta prepaga',
        'bank_transfer' => 'Transferencia', 'ticket' => 'Efectivo (cupón)', 'atm' => 'Cajero', 'digital_currency' => 'Mercado Crédito', 'digital_wallet' => 'Billetera'];
    public const ESTADOS = ['approved' => 'Acreditado', 'refunded' => 'Devuelto', 'charged_back' => 'Contracargo', 'cancelled' => 'Cancelado', 'in_mediation' => 'En reclamo'];
    private const ORDEN = ['fecha' => 'i.fecha', 'monto' => 'i.monto', 'neto' => 'i.neto', 'medio' => 'i.medio', 'pagador' => 'i.pagador', 'descripcion' => 'i.descripcion'];

    /** Para las pruebas: la hora "ahora" de la consulta periódica. */
    public static ?int $ahora = null;

    private PDO $pdo;

    public function __construct()
    {
        $this->pdo = Db::pdo();
    }

    public static function medio(string $m): string
    {
        return self::MEDIOS[$m] ?? ($m !== '' ? $m : '—');
    }

    public static function estado(string $e): string
    {
        return self::ESTADOS[$e] ?? $e;
    }

    // ---------------------------------------------------------------- registrar

    /**
     * Registra un pago como ingreso (idempotente). Devuelve el id del ingreso si es nuevo, o null si ya estaba (se le actualiza el estado) o no corresponde.
     *
     * @param array<string,mixed>|null $i lo que arma MercadoPago::ingreso()
     */
    public function registrar(?array $i, string $origen, ?string $modo = null): ?int
    {
        if ($i === null || $i['mp_pago_id'] === '') {
            return null;
        }
        $st = $this->pdo->prepare('SELECT id, estado FROM mp_ingresos WHERE mp_pago_id = ?');
        $st->execute([$i['mp_pago_id']]);
        $ya = $st->fetch();
        if ($ya) {
            if ($ya['estado'] !== $i['estado'] && $i['estado'] !== '') {
                $this->pdo->prepare('UPDATE mp_ingresos SET estado = ? WHERE id = ?')->execute([$i['estado'], $ya['id']]);
            }
            return null;
        }
        if ($i['estado'] !== 'approved') {
            return null; // pendiente, rechazado…: no es plata que entró
        }
        $ins = $this->pdo->prepare('INSERT IGNORE INTO mp_ingresos (mp_pago_id, modo, estado, fecha, monto, neto, moneda, medio, medio_detalle, pagador, pagador_mail, pagador_doc,
            descripcion, referencia, origen, registrado_en) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())');
        $ins->execute([$i['mp_pago_id'], $modo ?? MercadoPago::letraModo(), $i['estado'], $i['fecha'], $i['monto'], $i['neto'], $i['moneda'], $i['medio'], $i['medio_detalle'],
            $i['pagador'], $i['pagador_mail'], $i['pagador_doc'], $i['descripcion'], $i['referencia'], mb_substr($origen, 0, 10)]);
        if ($ins->rowCount() === 0) {
            return null; // lo registró otro pedido al mismo tiempo
        }
        $id = (int) $this->pdo->lastInsertId();
        if (strtotime($i['fecha']) >= time() - 7200) {
            $this->avisarPorMail($id);
        }
        return $id;
    }

    /** Un ingreso de prueba en modo simulado (botón «Simular ingreso» o el simulador de cobros). */
    public function simular(float $monto, string $pagador = '', string $descripcion = '', string $referencia = ''): ?int
    {
        if (MercadoPago::modo() !== 'simulado' || $monto <= 0) {
            return null;
        }
        return $this->registrar([
            'mp_pago_id' => 'SIM-' . bin2hex(random_bytes(6)), 'estado' => 'approved', 'fecha' => date('Y-m-d H:i:s'), 'monto' => round($monto, 2), 'neto' => round($monto * 0.95, 2),
            'moneda' => 'ARS', 'medio' => 'account_money', 'medio_detalle' => 'account_money', 'pagador' => mb_substr($pagador !== '' ? $pagador : 'Cliente de prueba', 0, 150),
            'pagador_mail' => '', 'pagador_doc' => '', 'descripcion' => mb_substr($descripcion, 0, 255), 'referencia' => mb_substr($referencia, 0, 64),
        ], 'simulado', 'S');
    }

    // ---------------------------------------------------------------- consulta periódica

    /**
     * Pregunta a Mercado Pago por los pagos nuevos si pasó al menos un minuto desde la consulta anterior (de cualquier usuario o del cron). Solo en producción.
     * Nunca tira error: sin conexión, se reintenta en la próxima. @return int ingresos nuevos registrados (-1 si no tocaba consultar)
     */
    public function consultarSiToca(int $cadaSegundos = 60): int
    {
        if (MercadoPago::modo() !== 'produccion' || MercadoPago::faltantes() !== []) {
            return -1;
        }
        $ahora = self::$ahora ?? time();
        $anterior = (string) (Configuracion::valor(self::CLAVE_CONSULTA) ?? '');
        $antTs = $anterior !== '' ? (int) strtotime($anterior) : 0;
        if ($antTs > 0 && $ahora - $antTs < $cadaSegundos) {
            return -1;
        }
        // se toma el turno con un UPDATE condicionado: si dos pantallas preguntan a la vez, consulta una sola
        $this->pdo->prepare('INSERT IGNORE INTO configuracion (clave, valor, tipo, descripcion) VALUES (?, \'\', \'texto\', \'Última consulta de ingresos a Mercado Pago (uso interno)\')')
            ->execute([self::CLAVE_CONSULTA]);
        $tomar = $this->pdo->prepare('UPDATE configuracion SET valor = ? WHERE clave = ? AND valor = ?');
        $tomar->execute([date('Y-m-d H:i:s', $ahora), self::CLAVE_CONSULTA, $anterior]);
        Configuracion::reset();
        if ($tomar->rowCount() === 0) {
            return -1;
        }
        $desde = $antTs > 0 ? $antTs - 600 : $ahora - 86400;
        try {
            $pagos = MercadoPago::pagosDesde($desde);
        } catch (MercadoPagoError $ex) {
            error_log('[mercadopago] consulta de ingresos: ' . $ex->getMessage());
            // se deja la hora anterior para no perder el tramo: la próxima consulta vuelve a pedirlo
            $this->pdo->prepare('UPDATE configuracion SET valor = ? WHERE clave = ?')->execute([$anterior, self::CLAVE_CONSULTA]);
            Configuracion::reset();
            return 0;
        }
        $nuevos = 0;
        foreach ($pagos as $p) {
            if ($this->registrar(MercadoPago::ingreso($p), 'consulta', 'P') !== null) {
                $nuevos++;
            }
        }
        return $nuevos;
    }

    // ---------------------------------------------------------------- avisos

    /**
     * Para la campanita: cuántos ingresos no vio este usuario, el último id y los ingresos posteriores a $desde (los que hay que anunciar en pantalla).
     * @return array{sin_ver:int,ultimo_id:int,nuevos:array<int,array<string,mixed>>}
     */
    public function novedades(int $usuarioId, ?int $desde = null): array
    {
        $visto = $this->ultimoVisto($usuarioId);
        $st = $this->pdo->prepare('SELECT COUNT(*) FROM mp_ingresos WHERE id > ?');
        $st->execute([$visto]);
        $sinVer = (int) $st->fetchColumn();
        $ultimo = (int) $this->pdo->query('SELECT COALESCE(MAX(id), 0) FROM mp_ingresos')->fetchColumn();
        $nuevos = [];
        if ($desde !== null && $desde < $ultimo) {
            $st = $this->pdo->prepare('SELECT id, fecha, monto, medio, pagador, descripcion, modo FROM mp_ingresos WHERE id > ? AND id > ? ORDER BY id LIMIT 10');
            $st->execute([$desde, $visto]);
            foreach ($st->fetchAll() as $x) {
                $nuevos[] = ['id' => (int) $x['id'], 'texto' => self::textoAviso($x)];
            }
        }
        return ['sin_ver' => $sinVer, 'ultimo_id' => $ultimo, 'nuevos' => $nuevos];
    }

    /** «Ingresó $ 12.500,00 de Juan Pérez (Dinero en cuenta)». @param array<string,mixed> $x */
    public static function textoAviso(array $x): string
    {
        $de = trim((string) $x['pagador']) !== '' ? ' de ' . $x['pagador'] : '';
        return 'Ingresó $ ' . number_format((float) $x['monto'], 2, ',', '.') . $de . ' (' . self::medio((string) $x['medio']) . ')' . ($x['modo'] === 'S' ? ' [simulado]' : '');
    }

    public function ultimoVisto(int $usuarioId): int
    {
        $st = $this->pdo->prepare('SELECT ultimo_id FROM mp_ingresos_vistos WHERE usuario_id = ?');
        $st->execute([$usuarioId]);
        return (int) ($st->fetchColumn() ?: 0);
    }

    /** Marca como vistos todos los ingresos hasta $hasta (el último que tenía la pantalla). */
    public function marcarVistos(int $usuarioId, int $hasta): void
    {
        $max = (int) $this->pdo->query('SELECT COALESCE(MAX(id), 0) FROM mp_ingresos')->fetchColumn();
        $hasta = max(0, min($hasta, $max));
        $this->pdo->prepare('INSERT INTO mp_ingresos_vistos (usuario_id, ultimo_id, visto_en) VALUES (?, ?, NOW())
            ON DUPLICATE KEY UPDATE ultimo_id = GREATEST(ultimo_id, VALUES(ultimo_id)), visto_en = NOW()')->execute([$usuarioId, $hasta]);
    }

    /** @return string[] los mails de Configuración que son válidos */
    public static function mails(): array
    {
        $out = [];
        foreach (preg_split('/[,;\s]+/', (string) (Configuracion::valor(self::CLAVE_MAILS) ?? '')) ?: [] as $m) {
            if ($m !== '' && filter_var($m, FILTER_VALIDATE_EMAIL) !== false) {
                $out[] = $m;
            }
        }
        return array_values(array_unique($out));
    }

    /** Manda el aviso por mail de un ingreso, si hay mails cargados y el envío está configurado. El resultado queda en el ingreso. */
    public function avisarPorMail(int $id): void
    {
        $para = self::mails();
        if ($para === [] || !Mailer::configurado()) {
            return;
        }
        $st = $this->pdo->prepare('SELECT * FROM mp_ingresos WHERE id = ?');
        $st->execute([$id]);
        $x = $st->fetch();
        if (!$x) {
            return;
        }
        $empresa = \App\Modules\Configuracion\Apariencia::nombreSistema();
        $lineas = [
            self::textoAviso($x) . '.', '',
            'Fecha: ' . date('d/m/Y H:i', (int) strtotime((string) $x['fecha'])),
            'Importe: $ ' . number_format((float) $x['monto'], 2, ',', '.') . ($x['neto'] !== null ? ' (acredita $ ' . number_format((float) $x['neto'], 2, ',', '.') . ')' : ''),
            'Medio: ' . self::medio((string) $x['medio']),
        ];
        if ($x['pagador'] !== '' || $x['pagador_mail'] !== '' || $x['pagador_doc'] !== '') {
            $lineas[] = 'Pagó: ' . trim($x['pagador'] . ' ' . $x['pagador_mail'] . ' ' . $x['pagador_doc']);
        }
        if ($x['descripcion'] !== '') {
            $lineas[] = 'Descripción: ' . $x['descripcion'];
        }
        $lineas[] = 'Operación de Mercado Pago: ' . $x['mp_pago_id'];
        $lineas[] = '';
        $lineas[] = 'Ver los ingresos: ' . url_absoluta(url('/mercadopago/ingresos'));
        $asunto = ($x['modo'] === 'S' ? '[SIMULADO] ' : '') . 'Ingreso de $ ' . number_format((float) $x['monto'], 2, ',', '.') . ' en Mercado Pago - ' . $empresa;
        $error = Mailer::enviar($para, $asunto, implode("\n", $lineas));
        $this->pdo->prepare('UPDATE mp_ingresos SET mail_enviado_en = IF(? IS NULL, NOW(), NULL), mail_error = ? WHERE id = ?')
            ->execute([$error, $error === null ? null : mb_substr($error, 0, 255), $id]);
    }

    // ---------------------------------------------------------------- listado

    /**
     * @param array<string,string> $f desde, hasta (AAAA-MM-DD), medio, texto, orden
     * @return array{filas:array<int,array<string,mixed>>,total:int,suma:float,suma_neto:float}
     */
    public function listar(array $f, int $pagina, int $porPagina): array
    {
        $where = ['1 = 1'];
        $args = [];
        foreach (['desde' => '>=', 'hasta' => '<='] as $k => $op) {
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $f[$k] ?? '')) {
                $where[] = "DATE(i.fecha) {$op} ?";
                $args[] = $f[$k];
            }
        }
        if (($f['medio'] ?? '') !== '') {
            $where[] = 'i.medio = ?';
            $args[] = $f['medio'];
        }
        if (trim($f['texto'] ?? '') !== '') {
            $where[] = '(i.pagador LIKE ? OR i.pagador_mail LIKE ? OR i.pagador_doc LIKE ? OR i.descripcion LIKE ? OR i.mp_pago_id = ?)';
            $like = '%' . trim($f['texto']) . '%';
            array_push($args, $like, $like, $like, $like, trim($f['texto']));
        }
        $w = implode(' AND ', $where);
        $st = $this->pdo->prepare("SELECT COUNT(*), COALESCE(SUM(CASE WHEN i.estado = 'approved' THEN i.monto END), 0), COALESCE(SUM(CASE WHEN i.estado = 'approved' THEN i.neto END), 0) FROM mp_ingresos i WHERE {$w}");
        $st->execute($args);
        [$total, $suma, $sumaNeto] = $st->fetch(PDO::FETCH_NUM);
        $desde = max(0, ($pagina - 1) * $porPagina);
        $st = $this->pdo->prepare('SELECT i.*, c.comprobante_id, c.id AS cobro_id, c.token AS cobro_token
            FROM mp_ingresos i LEFT JOIN mp_cobros c ON i.referencia <> \'\' AND c.token = i.referencia
            WHERE ' . $w . ' ORDER BY ' . Orden::sql(self::ORDEN, (string) ($f['orden'] ?? ''), 'i.fecha DESC, i.id DESC') . " LIMIT {$porPagina} OFFSET {$desde}");
        $st->execute($args);
        return ['filas' => $st->fetchAll(), 'total' => (int) $total, 'suma' => (float) $suma, 'suma_neto' => (float) $sumaNeto];
    }
}
