<?php
declare(strict_types=1);

namespace App\Modules\FacturacionMasiva;

use App\Core\Db;
use App\Modules\Arca\ArcaSinRespuesta;
use App\Modules\Arca\AutorizacionRechazada;
use App\Modules\Arca\GrabacionFallida;
use App\Modules\Comprobantes\ComprobantesService;
use App\Modules\Comprobantes\ImpresionController;
use App\Modules\Comprobantes\Tipos;
use App\Modules\Stock\StockNoDisponible;
use PDO;

/**
 * Pre-facturas de la facturación masiva (tabla prefacturas).
 *
 * Una pre-factura es una factura cargada y todavía no aprobada: guarda lo mismo que carga el formulario de la factura («datos») y no toca stock, caja,
 * cuenta corriente ni ARCA. Se puede modificar (con el mismo formulario de la factura), eliminar o aprobar. Al aprobarla, procesar() hace exactamente
 * lo de una factura cargada a mano: ComprobantesService::armar() valida y calcula y emitir() pide el CAE y graba subdiario, iva, estadística, stock y
 * caja o cuenta corriente según cómo se cobra.
 *
 * DECISIÓN D-156 (Claude, a confirmar por Hernán): al aprobar, quien aprueba autoriza lo que pediría autorización en una factura a mano si su rol lo puede
 * autorizar (stock que no alcanza con stock.autorizar o stock.vender_sin_stock; exceder el crédito con comprobantes.credito). Si no, esa pre-factura queda
 * pendiente con el motivo y las demás se facturan.
 * DECISIÓN D-157 (Claude, a confirmar por Hernán): se facturan de a una, en orden de fecha (ARCA exige fechas en orden por punto de venta). Cada una tiene su
 * clave de operación desde que se carga: reintentar (o un corte en el medio) nunca factura dos veces. Una pre-factura que ARCA pudo haber aprobado
 * (operación sin respuesta o aprobada sin grabar) no se modifica ni se elimina hasta revisarla en Sistema → ARCA.
 */
final class PreFacturasService
{
    public const ESTADOS = ['pendiente' => 'Pendiente', 'procesando' => 'Facturando', 'emitida' => 'Facturada', 'eliminada' => 'Eliminada'];
    /** Minutos después de los cuales una pre-factura que quedó «facturando» (se cortó el pedido) se puede volver a procesar. */
    private const MINUTOS_TRABADA = 5;

    private PDO $pdo;
    private ComprobantesService $comp;

    public function __construct()
    {
        $this->pdo = Db::pdo();
        $this->comp = new ComprobantesService();
    }

    // ------------------------------------------------------------------ carga desde la planilla

    /**
     * Arma las pre-facturas de una planilla (todas, con o sin errores: las que tienen errores se corrigen con «Modificar»).
     * @param array{titulos:string[],filas:array<int,array{n:int,celdas:string[]}>} $hoja lo de Importacion\Lector::leer()
     * @param array<string,mixed> $porOmision 'cobro', 'sucursal' @param array{credito:bool,stock:bool} $permisos
     * @return array{lote:int,prefacturas:int,con_error:int}
     */
    public function cargarPlanilla(array $hoja, string $archivo, array $porOmision, int $usuarioId, array $permisos): array
    {
        $leido = (new Planilla())->leer($hoja, $porOmision);
        $this->pdo->beginTransaction();
        try {
            $this->pdo->prepare('INSERT INTO prefacturas_lotes (creado_en, usuario_id, archivo, filas, prefacturas) VALUES (NOW(), ?, ?, ?, ?)')
                ->execute([$usuarioId > 0 ? $usuarioId : null, mb_substr($archivo, 0, 255), count($hoja['filas']), count($leido['facturas'])]);
            $lote = (int) $this->pdo->lastInsertId();
            $conError = 0;
            foreach ($leido['facturas'] as $f) {
                $id = $this->insertar($lote, $f['referencia'], implode(', ', $f['filas']), $f['cliente'], $f['cliente_texto'], $f['datos'], $usuarioId);
                $conError += $this->revalidar($id, $usuarioId, $permisos, $f['errores']) ? 0 : 1;
            }
            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
        return ['lote' => $lote, 'prefacturas' => count($leido['facturas']), 'con_error' => $conError];
    }

    /** @param array<string,mixed> $datos */
    private function insertar(?int $lote, string $referencia, string $filas, ?int $cliente, string $clienteTexto, array $datos, int $usuarioId): int
    {
        $this->pdo->prepare('INSERT INTO prefacturas (lote_id, referencia, filas_planilla, cliente, cliente_texto, fecha, cobro, datos, estado, operacion, creado_en, creado_por)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), ?)')
            ->execute([$lote, $referencia, mb_substr($filas, 0, 255), $cliente, $clienteTexto, $datos['fecha'] ?? null, Planilla::textoCobro($datos), self::json($datos), 'pendiente',
                bin2hex(random_bytes(16)), $usuarioId > 0 ? $usuarioId : null]);
        return (int) $this->pdo->lastInsertId();
    }

    // ------------------------------------------------------------------ validar

    /**
     * La entrada para armar(): lo guardado + lo que autoriza quien aprueba (D-156).
     * @param array<string,mixed> $datos @param array{credito:bool,stock:bool} $permisos @return array<string,mixed>
     */
    public static function entrada(array $datos, array $permisos): array
    {
        $datos['tipo'] = 'FC';
        unset($datos['reserva'], $datos['autoriza_usuario'], $datos['autoriza_clave'], $datos['presupuesto'], $datos['asociado']);
        $datos['excede_ok'] = !empty($permisos['credito']) ? '1' : '';
        $datos['autoriza_yo'] = !empty($permisos['stock']) ? '1' : '';
        return $datos;
    }

    /**
     * Vuelve a validar una pre-factura pendiente con armar() y guarda el total, la letra y lo que haya que corregir. true si está lista para facturar.
     * @param array{credito:bool,stock:bool} $permisos @param string[] $previos errores de la planilla (cliente que no existe…)
     */
    public function revalidar(int $id, int $usuarioId, array $permisos, array $previos = []): bool
    {
        $p = $this->obtener($id);
        if ($p === null || $p['estado'] !== 'pendiente') {
            return false;
        }
        $r = $this->comp->armar(self::entrada($p['datos'], $permisos), $permisos['credito'], $usuarioId);
        $errores = array_values(array_unique([...$previos, ...array_map('strval', array_values($r['errores']))]));
        if (!$r['ok'] && $r['errores'] !== [] && array_diff(array_keys($r['errores']), ['stock', 'credito']) === []) {
            // solo falta autorizar stock o crédito (se resuelve al aprobar): el total se calcula igual, solo para mostrarlo (esto nunca se emite)
            $sin = $this->comp->armar(['excede_ok' => '1'] + self::entrada($p['datos'], $permisos), true, $usuarioId, ['usuario_id' => 0, 'nombre' => '']);
            $r = $sin['ok'] ? ['ok' => true, 'cbte' => $sin['cbte']] + $r : $r;
        }
        // la fecha vacía es «la del día que se factura»: el total y la letra salen igual
        $this->pdo->prepare('UPDATE prefacturas SET total = ?, letra = ?, errores = ? WHERE id = ?')->execute([
            $r['ok'] ? $r['cbte']['calculo']['total'] : null, $r['ok'] ? $r['cbte']['letra'] : $this->letraPrevista($p['datos']),
            $errores === [] ? null : implode("\n", $errores), $id,
        ]);
        return $errores === [];
    }

    /** @param array<string,mixed> $datos */
    private function letraPrevista(array $datos): string
    {
        $st = $this->pdo->prepare('SELECT iva FROM clientes WHERE codigo = ?');
        $st->execute([(int) ($datos['cliente'] ?? 0)]);
        $iva = $st->fetchColumn();
        $emisor = $this->comp->emisor();
        $cond = $iva !== false ? $this->comp->condicionArca((int) $iva) : null;
        return $cond !== null && $emisor['condicion'] !== null ? Tipos::letra((string) $emisor['condicion'], $cond) : '';
    }

    // ------------------------------------------------------------------ consulta

    /** @return array<string,mixed>|null la fila con 'datos' ya decodificado */
    public function obtener(int $id): ?array
    {
        $st = $this->pdo->prepare('SELECT p.*, c.razon_social FROM prefacturas p LEFT JOIN clientes c ON c.codigo = p.cliente WHERE p.id = ?');
        $st->execute([$id]);
        $p = $st->fetch();
        if ($p === false) {
            return null;
        }
        $p['datos'] = json_decode((string) $p['datos'], true) ?: [];
        return $p;
    }

    /**
     * @param array<string,string> $f estado (pendientes|emitidas|todas), lote
     * @return array<int,array<string,mixed>>
     */
    public function listar(array $f): array
    {
        $w = [];
        $par = [];
        $estado = $f['estado'] ?? '';
        if ($estado === 'emitidas') {
            $w[] = "p.estado = 'emitida'";
        } elseif ($estado === 'todas') {
            $w[] = "p.estado <> 'eliminada'";
        } else {
            $w[] = "p.estado IN ('pendiente', 'procesando')";
        }
        if (ctype_digit((string) ($f['lote'] ?? '')) && (int) $f['lote'] > 0) {
            $w[] = 'p.lote_id = ?';
            $par[] = (int) $f['lote'];
        }
        $st = $this->pdo->prepare('SELECT p.id, p.lote_id, p.referencia, p.filas_planilla, p.cliente, p.cliente_texto, p.fecha, p.letra, p.cobro, p.total, p.errores, p.estado,
                p.subdiario_id, p.aviso, p.procesado_en, c.razon_social, s.prefijo, s.comprobante, s.fe_nrocbte, s.fe_ccae, s.tipo AS s_tipo, s.letra AS s_letra
            FROM prefacturas p LEFT JOIN clientes c ON c.codigo = p.cliente LEFT JOIN subdiario s ON s.fe_clave = p.subdiario_id
            WHERE ' . implode(' AND ', $w) . ' ORDER BY COALESCE(p.fecha, CURDATE()), p.id LIMIT 3000');
        $st->execute($par);
        return $st->fetchAll();
    }

    /** Planillas subidas que todavía tienen algo pendiente (para el filtro). @return array<int,array<string,mixed>> */
    public function lotes(): array
    {
        return $this->pdo->query("SELECT l.id, l.creado_en, l.archivo, l.prefacturas, SUM(p.estado IN ('pendiente', 'procesando')) AS pendientes
            FROM prefacturas_lotes l JOIN prefacturas p ON p.lote_id = l.id GROUP BY l.id ORDER BY l.id DESC LIMIT 50")->fetchAll();
    }

    // ------------------------------------------------------------------ modificar y eliminar

    /**
     * Motivo por el que no se puede tocar (modificar o eliminar), o null: solo las pendientes, y nunca una que ARCA pudo haber aprobado (D-157).
     * @param array<string,mixed> $p
     */
    public function bloqueo(array $p): ?string
    {
        if ($p['estado'] === 'emitida') {
            return 'Esa pre-factura ya se facturó.';
        }
        if ($p['estado'] !== 'pendiente') {
            return $p['estado'] === 'procesando' ? 'Esa pre-factura se está facturando en este momento.' : 'Esa pre-factura ya no está.';
        }
        $st = $this->pdo->prepare("SELECT estado FROM afip_operaciones WHERE operacion = ? AND estado <> 'rechazada'");
        $st->execute([$p['operacion']]);
        if ($st->fetchColumn() !== false) {
            return 'Esa pre-factura ya se mandó a ARCA y no hay una respuesta final: revisala en Sistema → ARCA y después volvé a «Aprobar y facturar» (no se factura dos veces).';
        }
        return null;
    }

    /**
     * Guarda lo modificado con el formulario de la factura. El cobro llega aparte ('CC' o el código de la forma de pago).
     * @param array<string,mixed> $datos lo del formulario @param array{credito:bool,stock:bool} $permisos
     * @return array{ok:bool,errores:array<string,string>}
     */
    public function guardar(int $id, array $datos, string $cobro, int $usuarioId, array $permisos): array
    {
        $p = $this->obtener($id);
        if ($p === null) {
            return ['ok' => false, 'errores' => ['prefactura' => 'La pre-factura no existe.']];
        }
        if (($b = $this->bloqueo($p)) !== null) {
            return ['ok' => false, 'errores' => ['prefactura' => $b]];
        }
        $cobro = (new Planilla())->cobro($cobro) ?? '';
        if ($cobro === '') {
            return ['ok' => false, 'errores' => ['cobro' => 'Elegí cómo se cobra.']];
        }
        $datos = array_diff_key($datos, array_flip(['_csrf', 'operacion', 'datos', 'cobro', 'reserva', 'forma_pago', 'fpago', 'pagos', 'recibido', 'excede_ok', 'autoriza_yo', 'autoriza_usuario', 'autoriza_clave']));
        $datos['tipo'] = 'FC';
        $datos['items'] = array_values(array_filter((array) ($datos['items'] ?? []), static fn ($r) => is_array($r)
            && implode('', array_map(static fn ($v) => trim((string) $v), array_intersect_key($r, array_flip(['articulo', 'descripcion', 'cantidad', 'precio'])))) !== ''));
        $datos += Planilla::entradaCobro($cobro);
        $r = $this->comp->armar(self::entrada($datos, $permisos), $permisos['credito'], $usuarioId);
        // DECISIÓN D-158 (Claude, a confirmar por Hernán): lo que se resuelve recién al facturar (stock que no alcanza, crédito del cliente) no impide guardar:
        // queda anotado. Todo lo demás se corrige antes de guardar, como en una factura a mano. Lo que vino mal de la planilla igual se guarda (para corregirlo acá).
        $bloquean = array_diff_key($r['errores'], array_flip(['stock', 'credito']));
        if ($bloquean !== []) {
            return ['ok' => false, 'errores' => $r['errores']];
        }
        $fecha = trim((string) ($datos['fecha'] ?? ''));
        $datos['fecha'] = $fecha === '' ? '' : (\App\Core\Valida::fecha($fecha) ?? '');
        if ($datos['fecha'] === date('Y-m-d') && $p['fecha'] === null) {
            $datos['fecha'] = ''; // el formulario pone hoy: si no tenía fecha, sigue siendo «la del día que se factura»
        }
        $this->pdo->prepare('UPDATE prefacturas SET datos = ?, cliente = ?, cliente_texto = ?, fecha = ?, cobro = ?, modificado_en = NOW(), modificado_por = ?, operacion = ? WHERE id = ? AND estado = ?')
            ->execute([self::json($datos), (int) $datos['cliente'], '', $datos['fecha'] !== '' ? $datos['fecha'] : null, Planilla::textoCobro($datos), $usuarioId > 0 ? $usuarioId : null,
                bin2hex(random_bytes(16)), $id, 'pendiente']); // operación nueva: lo que ARCA rechazó antes era otro comprobante
        $this->revalidar($id, $usuarioId, $permisos);
        return ['ok' => true, 'errores' => []];
    }

    /** Elimina las pre-facturas pendientes (quedan marcadas, no se borran). @param int[] $ids @return array{eliminadas:int,no:string[]} */
    public function eliminar(array $ids, int $usuarioId): array
    {
        $n = 0;
        $no = [];
        foreach (array_unique(array_map('intval', $ids)) as $id) {
            $p = $this->obtener($id);
            if ($p === null) {
                continue;
            }
            if (($b = $this->bloqueo($p)) !== null) {
                $no[] = ($p['referencia'] !== '' ? $p['referencia'] : '#' . $id) . ': ' . $b;
                continue;
            }
            $st = $this->pdo->prepare("UPDATE prefacturas SET estado = 'eliminada', modificado_en = NOW(), modificado_por = ? WHERE id = ? AND estado = 'pendiente'");
            $st->execute([$usuarioId > 0 ? $usuarioId : null, $id]);
            $n += $st->rowCount();
        }
        return ['eliminadas' => $n, 'no' => $no];
    }

    // ------------------------------------------------------------------ facturar

    /** Las elegidas en el orden en que se facturan: por fecha (sin fecha = hoy) y por orden de carga (D-157). @param int[] $ids @return int[] */
    public function ordenar(array $ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn ($i) => $i > 0)));
        if ($ids === []) {
            return [];
        }
        $st = $this->pdo->prepare('SELECT id FROM prefacturas WHERE id IN (' . implode(',', array_fill(0, count($ids), '?')) . ') ORDER BY COALESCE(fecha, CURDATE()), id');
        $st->execute($ids);
        return array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
    }

    /**
     * Aprueba y factura UNA pre-factura: armar() + emitir() como una factura a mano (ARCA, stock, caja o cuenta corriente). Si algo falla queda pendiente
     * con el motivo. $mail: además se manda por mail al cliente (si tiene mail).
     * @param array{credito:bool,stock:bool} $permisos
     * @return array{ok:bool,estado:string,mensaje:string,comprobante_id:?int,comprobante:?string,total:?float,aviso:?string}
     */
    public function procesar(int $id, int $usuarioId, array $permisos, bool $mail = false): array
    {
        $res = static fn (bool $ok, string $estado, string $msg, ?int $cid = null, ?string $cbte = null, ?float $total = null, ?string $aviso = null): array
            => ['ok' => $ok, 'estado' => $estado, 'mensaje' => $msg, 'comprobante_id' => $cid, 'comprobante' => $cbte, 'total' => $total, 'aviso' => $aviso];
        // 1) se toma (dos usuarios o dos pestañas no la facturan a la vez); una que quedó «facturando» por un corte se puede retomar
        $st = $this->pdo->prepare("UPDATE prefacturas SET estado = 'procesando', procesado_en = NOW(), procesado_por = ?
            WHERE id = ? AND (estado = 'pendiente' OR (estado = 'procesando' AND procesado_en < NOW() - INTERVAL " . self::MINUTOS_TRABADA . ' MINUTE))');
        $st->execute([$usuarioId > 0 ? $usuarioId : null, $id]);
        $p = $this->obtener($id);
        if ($p === null) {
            return $res(false, 'eliminada', 'La pre-factura no existe.');
        }
        if ($st->rowCount() === 0) {
            if ($p['estado'] === 'emitida') {
                return $res(true, 'emitida', 'Ya estaba facturada.', (int) $p['subdiario_id'], $this->textoComprobante((int) $p['subdiario_id']), (float) $p['total']);
            }
            return $res(false, (string) $p['estado'], $p['estado'] === 'procesando' ? 'Se está facturando en otra pantalla.' : 'Ya no está pendiente.');
        }
        $volver = function (string $motivo) use ($id, $res): array {
            $this->pdo->prepare("UPDATE prefacturas SET estado = 'pendiente', errores = ? WHERE id = ?")->execute([mb_substr($motivo, 0, 60000), $id]);
            return $res(false, 'pendiente', $motivo);
        };
        // 2) se valida y calcula de nuevo con los datos de hoy (precios ya cargados, stock, crédito, fecha)
        $r = $this->comp->armar(self::entrada($p['datos'], $permisos), $permisos['credito'], $usuarioId);
        if (!$r['ok']) {
            return $volver(implode("\n", array_map('strval', array_unique(array_values($r['errores'])))));
        }
        // 3) se emite con la clave de la pre-factura
        try {
            $e = $this->comp->emitir($r['cbte'], (string) $p['operacion'], $usuarioId);
        } catch (AutorizacionRechazada $ex) {
            return $volver('ARCA no la aprobó: ' . $ex->getMessage());
        } catch (ArcaSinRespuesta | GrabacionFallida $ex) {
            return $volver($ex->getMessage());
        } catch (StockNoDisponible | \App\Modules\Licencia\LicenciaVencida $ex) {
            return $volver($ex->getMessage());
        } catch (\Throwable $ex) {
            \App\App::registrar($ex);
            return $volver('No se pudo facturar: ' . $ex->getMessage());
        }
        $this->pdo->prepare("UPDATE prefacturas SET estado = 'emitida', subdiario_id = ?, errores = NULL, total = ?, letra = ? WHERE id = ?")
            ->execute([$e['id'], $r['cbte']['calculo']['total'], $r['cbte']['letra'], $id]);
        // 4) mail (si se pidió y el cliente tiene): si no sale, la factura igual quedó hecha
        $aviso = null;
        if ($mail && !$e['repetido']) {
            $mails = \App\Core\Valida::mails((string) ($r['cbte']['email'] ?? ''));
            $h = $this->comp->obtener($e['id']);
            if ($mails === null || $mails === '') {
                $aviso = 'Sin mail del cliente: no se envió.';
            } elseif ($h !== null && ($err = ImpresionController::enviarPorMail($h, $mails)) !== null) {
                $aviso = $err;
            } else {
                $aviso = 'Enviada a ' . $mails . '.';
            }
            $this->pdo->prepare('UPDATE prefacturas SET aviso = ? WHERE id = ?')->execute([mb_substr($aviso, 0, 255), $id]);
        }
        return $res(true, 'emitida', $e['repetido'] ? 'Ya estaba facturada.' : 'Facturada.', $e['id'], $this->textoComprobante($e['id']), $r['cbte']['calculo']['total'], $aviso);
    }

    private function textoComprobante(int $id): ?string
    {
        $h = $this->comp->obtener($id);
        return $h === null ? null : Tipos::titulo((string) $h['tipo'], (string) $h['letra']) . ' ' . numero_visible($h);
    }

    private static function json(mixed $v): string
    {
        return (string) json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
