<?php
declare(strict_types=1);

namespace App\Modules\Soporte;

use App\App;
use App\Core\Db;
use App\Core\Mailer;
use App\Core\Orden;
use App\Modules\Configuracion\Configuracion;
use PDO;

/**
 * Tickets de soporte, del lado del sitio master (donde se guardan). Lo usan la API (/api/v1/soporte, para las instalaciones de clientes),
 * la propia instalación principal (sin API) y la bandeja de O&S (Sistema → Tickets de soporte).
 * $instancia = dirección del cliente ('' = la instalación principal). Un cliente solo ve y toca los tickets de su instancia.
 */
final class SoporteService
{
    public const TIPOS = ['error' => 'Error del sistema', 'consulta' => 'Consulta', 'mejora' => 'Pedido de mejora', 'arca' => 'ARCA / facturación'];
    public const PRIORIDADES = ['normal' => 'Normal', 'urgente' => 'Urgente'];
    public const ESTADOS = ['nuevo' => 'Nuevo', 'en_curso' => 'En curso', 'esperando' => 'Esperando tu respuesta', 'resuelto' => 'Resuelto', 'cerrado' => 'Cerrado'];
    /** Cómo lo ve O&S (en la bandeja «esperando» es «esperando al cliente»). */
    public const ESTADOS_MASTER = ['nuevo' => 'Nuevo', 'en_curso' => 'En curso', 'esperando' => 'Esperando al cliente', 'resuelto' => 'Resuelto', 'cerrado' => 'Cerrado'];
    /** DECISIÓN D-77: un ticket resuelto se cierra solo a los 7 días si el cliente no contesta. */
    public const DIAS_CIERRE = 7;
    public const MAX_ADJUNTO = 3 * 1024 * 1024;
    public const TIPOS_ADJUNTO = ['image/png' => 'png', 'image/jpeg' => 'jpg', 'image/webp' => 'webp', 'image/gif' => 'gif', 'application/pdf' => 'pdf'];

    /** Para las pruebas: function (array $para, string $asunto, string $texto): ?string (en vez de mandar el mail). */
    public static $enviador = null;

    private PDO $db;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? Db::pdo();
    }

    // ---------------------------------------------------------------- del lado del cliente

    /**
     * Ticket nuevo. $d: asunto, tipo, prioridad, descripcion, usuario, usuario_mail, version, pantalla, instancia_nombre
     * y opcionalmente adjunto ['nombre', 'datos' (binario)].
     *
     * @param array<string,mixed> $d
     * @return array{ok:bool,errores?:array<string,string>,id?:int}
     */
    public function crear(string $instancia, array $d): array
    {
        $v = [
            'asunto' => mb_substr(trim((string) ($d['asunto'] ?? '')), 0, 150),
            'tipo' => (string) ($d['tipo'] ?? ''),
            'prioridad' => (string) ($d['prioridad'] ?? 'normal'),
            'descripcion' => trim((string) ($d['descripcion'] ?? '')),
        ];
        $errores = [];
        if ($v['asunto'] === '') {
            $errores['asunto'] = 'Escribí un asunto.';
        }
        if (!isset(self::TIPOS[$v['tipo']])) {
            $errores['tipo'] = 'Elegí el tipo de pedido.';
        }
        if (!isset(self::PRIORIDADES[$v['prioridad']])) {
            $errores['prioridad'] = 'Prioridad inválida.';
        }
        if (mb_strlen($v['descripcion']) < 5) {
            $errores['descripcion'] = 'Contanos qué pasó o qué necesitás.';
        } elseif (mb_strlen($v['descripcion']) > 20000) {
            $errores['descripcion'] = 'El texto es demasiado largo (hasta 20.000 caracteres).';
        }
        $mail = trim((string) ($d['usuario_mail'] ?? ''));
        if ($mail !== '' && filter_var($mail, FILTER_VALIDATE_EMAIL) === false) {
            $errores['usuario_mail'] = 'El mail no es válido.';
        }
        $adjunto = $this->validarAdjunto($d['adjunto'] ?? null, $errores);
        if ($errores !== []) {
            return ['ok' => false, 'errores' => $errores];
        }
        // El mismo formulario enviado dos veces (el master tardó y el cliente reintentó) devuelve el ticket que ya se creó.
        $operacion = preg_match('/^[A-Za-z0-9_-]{8,40}$/', (string) ($d['operacion'] ?? '')) ? (string) $d['operacion'] : null;
        if ($operacion !== null) {
            $st = $this->db->prepare('SELECT id FROM soporte_tickets WHERE instancia = ? AND operacion = ?');
            $st->execute([$instancia, $operacion]);
            if (($ya = $st->fetchColumn()) !== false) {
                return ['ok' => true, 'id' => (int) $ya];
            }
        }
        $ahora = date('Y-m-d H:i:s');
        $this->db->beginTransaction();
        try {
            $this->db->prepare('INSERT INTO soporte_tickets (instancia, instancia_nombre, asunto, tipo, prioridad, estado, pendiente, usuario, usuario_mail,
                    version, pantalla, creado_en, actualizado_en, operacion) VALUES (?, ?, ?, ?, ?, \'nuevo\', 1, ?, ?, ?, ?, ?, ?, ?)')
                ->execute([$instancia, mb_substr((string) ($d['instancia_nombre'] ?? ''), 0, 150), $v['asunto'], $v['tipo'], $v['prioridad'],
                    mb_substr((string) ($d['usuario'] ?? ''), 0, 100), mb_substr($mail, 0, 190), mb_substr((string) ($d['version'] ?? ''), 0, 20),
                    mb_substr((string) ($d['pantalla'] ?? ''), 0, 255), $ahora, $ahora, $operacion]);
            $id = (int) $this->db->lastInsertId();
            $this->agregarMensaje($id, 'C', (string) ($d['usuario'] ?? ''), $v['descripcion'], false, $adjunto, $ahora);
            $this->db->commit();
        } catch (\PDOException $e) {
            $this->db->rollBack();
            if ($operacion !== null && $e->getCode() === '23000') { // dos envíos a la vez del mismo formulario
                return $this->crear($instancia, $d);
            }
            throw $e;
        }
        $t = $this->ticket($id);
        $this->avisarAOys($t, 'Ticket nuevo', $v['descripcion']);
        return ['ok' => true, 'id' => $id];
    }

    /**
     * Tickets de una instancia (lo que ve el cliente). @param array<string,string> $f estado (abiertos|cerrados|''), orden
     * @return array<int,array<string,mixed>>
     */
    public function listarDe(string $instancia, array $f = []): array
    {
        $this->cerrarVencidos();
        $where = ['instancia = ?'];
        $p = [$instancia];
        $estado = (string) ($f['estado'] ?? 'abiertos');
        if ($estado === 'abiertos') {
            $where[] = "estado <> 'cerrado'";
        } elseif ($estado === 'cerrados') {
            $where[] = "estado = 'cerrado'";
        }
        $orden = Orden::sql(['numero' => 'id', 'asunto' => 'asunto', 'estado' => 'estado', 'actualizado' => 'actualizado_en', 'creado' => 'creado_en'],
            (string) ($f['orden'] ?? ''), 'actualizado_en DESC');
        $st = $this->db->prepare('SELECT id, asunto, tipo, prioridad, estado, usuario, creado_en, actualizado_en, pendiente,
                (SELECT COUNT(*) FROM soporte_mensajes m WHERE m.ticket_id = t.id AND m.interno = 0) AS mensajes
            FROM soporte_tickets t WHERE ' . implode(' AND ', $where) . ' ORDER BY ' . $orden . ' LIMIT 500');
        $st->execute($p);
        return $st->fetchAll();
    }

    /**
     * Ticket con su conversación. El cliente ($instancia no null) no ve las notas internas ni los tickets de otro.
     * @return array<string,mixed>|null
     */
    public function ver(int $id, ?string $instancia): ?array
    {
        $this->cerrarVencidos();
        $t = $this->ticket($id);
        if ($t === null || ($instancia !== null && $t['instancia'] !== $instancia)) {
            return null;
        }
        $st = $this->db->prepare('SELECT id, autor_tipo, autor, texto, interno, creado_en FROM soporte_mensajes WHERE ticket_id = ?'
            . ($instancia !== null ? ' AND interno = 0' : '') . ' ORDER BY id');
        $st->execute([$id]);
        $mensajes = $st->fetchAll();
        $st = $this->db->prepare('SELECT id, mensaje_id, nombre, tipo, tamano FROM soporte_adjuntos WHERE ticket_id = ? ORDER BY id');
        $st->execute([$id]);
        $porMensaje = [];
        foreach ($st->fetchAll() as $a) {
            $porMensaje[(int) $a['mensaje_id']][] = ['id' => (int) $a['id'], 'nombre' => $a['nombre'], 'tipo' => $a['tipo'], 'tamano' => (int) $a['tamano']];
        }
        foreach ($mensajes as &$m) {
            $m['adjuntos'] = $porMensaje[(int) $m['id']] ?? [];
        }
        unset($m);
        $t['mensajes'] = $mensajes;
        return $t;
    }

    /**
     * Respuesta del cliente: si estaba resuelto, esperando o cerrado, vuelve a «en curso» y queda pendiente para O&S.
     * @param array<string,mixed> $d texto, usuario, adjunto
     * @return array{ok:bool,error?:string}
     */
    public function responderCliente(int $id, string $instancia, array $d): array
    {
        $t = $this->ticket($id);
        if ($t === null || $t['instancia'] !== $instancia) {
            return ['ok' => false, 'error' => 'El ticket no existe.'];
        }
        $texto = trim((string) ($d['texto'] ?? ''));
        $errores = [];
        $adjunto = $this->validarAdjunto($d['adjunto'] ?? null, $errores);
        if ($texto === '' && $adjunto === null) {
            return ['ok' => false, 'error' => 'Escribí la respuesta.'];
        }
        if ($errores !== []) {
            return ['ok' => false, 'error' => implode(' ', $errores)];
        }
        $ahora = date('Y-m-d H:i:s');
        $this->db->beginTransaction();
        try {
            $this->agregarMensaje($id, 'C', (string) ($d['usuario'] ?? ''), $texto, false, $adjunto, $ahora);
            $estado = $t['estado'] === 'nuevo' ? 'nuevo' : 'en_curso';
            $this->db->prepare('UPDATE soporte_tickets SET estado = ?, pendiente = 1, actualizado_en = ?, resuelto_en = NULL, cerrado_en = NULL WHERE id = ?')
                ->execute([$estado, $ahora, $id]);
            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
        $this->avisarAOys($this->ticket($id), 'Respuesta del cliente', $texto);
        return ['ok' => true];
    }

    /** El cliente cierra (resuelto para él) o reabre su ticket. @return array{ok:bool,error?:string} */
    public function cambiarEstadoCliente(int $id, string $instancia, string $accion, string $usuario): array
    {
        $t = $this->ticket($id);
        if ($t === null || $t['instancia'] !== $instancia) {
            return ['ok' => false, 'error' => 'El ticket no existe.'];
        }
        $ahora = date('Y-m-d H:i:s');
        if ($accion === 'cerrar') {
            if ($t['estado'] === 'cerrado') {
                return ['ok' => true];
            }
            $this->db->prepare("UPDATE soporte_tickets SET estado = 'cerrado', pendiente = 0, cerrado_en = ?, actualizado_en = ? WHERE id = ?")->execute([$ahora, $ahora, $id]);
            $this->agregarMensaje($id, 'C', $usuario, 'Cerró el ticket.', false, null, $ahora);
            return ['ok' => true];
        }
        if ($accion === 'reabrir') {
            if (!in_array($t['estado'], ['resuelto', 'cerrado'], true)) {
                return ['ok' => true];
            }
            $this->db->prepare("UPDATE soporte_tickets SET estado = 'en_curso', pendiente = 1, cerrado_en = NULL, resuelto_en = NULL, actualizado_en = ? WHERE id = ?")->execute([$ahora, $id]);
            $this->agregarMensaje($id, 'C', $usuario, 'Reabrió el ticket.', false, null, $ahora);
            $this->avisarAOys($this->ticket($id), 'Ticket reabierto', 'El cliente reabrió el ticket.');
            return ['ok' => true];
        }
        return ['ok' => false, 'error' => 'Acción inválida.'];
    }

    /** Un adjunto (con los datos) si es de la instancia (null = O&S, ve todos). @return array<string,mixed>|null */
    public function adjunto(int $id, ?string $instancia): ?array
    {
        $st = $this->db->prepare('SELECT a.id, a.nombre, a.tipo, a.datos, t.instancia FROM soporte_adjuntos a JOIN soporte_tickets t ON t.id = a.ticket_id
            JOIN soporte_mensajes m ON m.id = a.mensaje_id WHERE a.id = ?' . ($instancia !== null ? ' AND m.interno = 0' : ''));
        $st->execute([$id]);
        $a = $st->fetch();
        if ($a === false || ($instancia !== null && $a['instancia'] !== $instancia)) {
            return null;
        }
        return $a;
    }

    // ---------------------------------------------------------------- del lado de O&S (bandeja del master)

    /**
     * @param array<string,string> $f instancia, estado (abiertos|pendientes|<estado>|todos), prioridad, tipo, q, orden
     * @return array{filas:array<int,array<string,mixed>>,total:int}
     */
    public function bandeja(array $f, int $pagina, int $porPagina): array
    {
        $this->cerrarVencidos();
        $where = ['1 = 1'];
        $p = [];
        if (($f['instancia'] ?? '') !== '') {
            $where[] = 'instancia = ?';
            $p[] = $f['instancia'] === '_principal' ? '' : $f['instancia'];
        }
        $estado = (string) ($f['estado'] ?? 'abiertos');
        if ($estado === 'abiertos' || $estado === '') {
            $where[] = "estado <> 'cerrado'";
        } elseif ($estado === 'pendientes') {
            $where[] = "pendiente = 1 AND estado <> 'cerrado'";
        } elseif (isset(self::ESTADOS[$estado])) {
            $where[] = 'estado = ?';
            $p[] = $estado;
        }
        if (isset(self::PRIORIDADES[$f['prioridad'] ?? ''])) {
            $where[] = 'prioridad = ?';
            $p[] = $f['prioridad'];
        }
        if (isset(self::TIPOS[$f['tipo'] ?? ''])) {
            $where[] = 'tipo = ?';
            $p[] = $f['tipo'];
        }
        $q = trim((string) ($f['q'] ?? ''));
        if ($q !== '') {
            if (preg_match('/^#?(\d+)$/', $q, $mm)) {
                $where[] = 'id = ?';
                $p[] = (int) $mm[1];
            } else {
                $where[] = '(asunto LIKE ? OR usuario LIKE ? OR instancia_nombre LIKE ? OR instancia LIKE ?)';
                array_push($p, "%$q%", "%$q%", "%$q%", "%$q%");
            }
        }
        $w = implode(' AND ', $where);
        $st = $this->db->prepare("SELECT COUNT(*) FROM soporte_tickets WHERE $w");
        $st->execute($p);
        $total = (int) $st->fetchColumn();
        $orden = Orden::sql(['numero' => 'id', 'cliente' => 'instancia_nombre', 'asunto' => 'asunto', 'tipo' => 'tipo', 'prioridad' => 'prioridad',
            'estado' => 'estado', 'actualizado' => 'actualizado_en', 'creado' => 'creado_en'], (string) ($f['orden'] ?? ''),
            "pendiente DESC, (prioridad = 'urgente') DESC, actualizado_en DESC");
        $st = $this->db->prepare("SELECT id, instancia, instancia_nombre, asunto, tipo, prioridad, estado, pendiente, usuario, version, creado_en, actualizado_en
            FROM soporte_tickets WHERE $w ORDER BY $orden LIMIT " . max(1, $porPagina) . ' OFFSET ' . max(0, ($pagina - 1) * $porPagina));
        $st->execute($p);
        return ['filas' => $st->fetchAll(), 'total' => $total];
    }

    /** Cuántos esperan respuesta de O&S (nuevos o con respuesta del cliente). */
    public function pendientes(): int
    {
        try {
            return (int) $this->db->query("SELECT COUNT(*) FROM soporte_tickets WHERE pendiente = 1 AND estado <> 'cerrado'")->fetchColumn();
        } catch (\PDOException) {
            return 0; // antes de migrar
        }
    }

    /** @return array<string,string> instancia => nombre (las que tienen tickets) */
    public function instancias(): array
    {
        $out = [];
        foreach ($this->db->query("SELECT instancia, MAX(instancia_nombre) AS nombre FROM soporte_tickets GROUP BY instancia ORDER BY 2")->fetchAll() as $r) {
            $out[$r['instancia'] === '' ? '_principal' : $r['instancia']] = $r['nombre'] !== '' ? $r['nombre'] : ($r['instancia'] !== '' ? $r['instancia'] : 'Instalación principal');
        }
        return $out;
    }

    /**
     * Respuesta o nota de O&S. Si es respuesta (no interna) se avisa por mail al que abrió el ticket.
     * @param array<string,mixed> $d texto, interno (bool), estado (nuevo estado; vacío = esperando si es respuesta, el mismo si es nota), adjunto
     * @return array{ok:bool,error?:string,aviso?:?string}
     */
    public function responderOys(int $id, string $autor, array $d): array
    {
        $t = $this->ticket($id);
        if ($t === null) {
            return ['ok' => false, 'error' => 'El ticket no existe.'];
        }
        $texto = trim((string) ($d['texto'] ?? ''));
        $interno = !empty($d['interno']);
        $errores = [];
        $adjunto = $this->validarAdjunto($d['adjunto'] ?? null, $errores);
        if ($errores !== []) {
            return ['ok' => false, 'error' => implode(' ', $errores)];
        }
        $estado = (string) ($d['estado'] ?? '');
        if ($estado !== '' && !isset(self::ESTADOS[$estado])) {
            return ['ok' => false, 'error' => 'Estado inválido.'];
        }
        if ($texto === '' && $adjunto === null) {
            if ($estado === '' || $estado === $t['estado']) {
                return ['ok' => false, 'error' => 'Escribí la respuesta o elegí otro estado.'];
            }
            $this->cambiarEstado($id, $estado, $t);
            return ['ok' => true, 'aviso' => null];
        }
        if ($estado === '') {
            $estado = $interno ? (string) $t['estado'] : 'esperando';
            if ($estado === 'nuevo' && $interno) {
                $estado = 'en_curso';
            }
        }
        $ahora = date('Y-m-d H:i:s');
        $this->db->beginTransaction();
        try {
            $this->agregarMensaje($id, 'O', $autor, $texto !== '' ? $texto : '(adjunto)', $interno, $adjunto, $ahora);
            $this->db->prepare('UPDATE soporte_tickets SET pendiente = ?, actualizado_en = ? WHERE id = ?')
                ->execute([$interno ? (int) $t['pendiente'] : 0, $ahora, $id]);
            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
        $this->cambiarEstado($id, $estado, $this->ticket($id));
        $aviso = null;
        if (!$interno) {
            $aviso = $this->avisarAlCliente($this->ticket($id), $texto);
        }
        return ['ok' => true, 'aviso' => $aviso];
    }

    /** @param array<string,mixed> $t */
    private function cambiarEstado(int $id, string $estado, array $t): void
    {
        if ($estado === $t['estado']) {
            return;
        }
        $ahora = date('Y-m-d H:i:s');
        $this->db->prepare('UPDATE soporte_tickets SET estado = ?, actualizado_en = ?, resuelto_en = ?, cerrado_en = ?, pendiente = IF(? IN (\'resuelto\', \'cerrado\'), 0, pendiente) WHERE id = ?')
            ->execute([$estado, $ahora, $estado === 'resuelto' ? $ahora : null, $estado === 'cerrado' ? $ahora : null, $estado, $id]);
    }

    /** Resueltos sin respuesta del cliente en DIAS_CIERRE días → cerrados (se hace al listar; no hace falta tarea programada). */
    public function cerrarVencidos(): int
    {
        $limite = date('Y-m-d H:i:s', time() - self::DIAS_CIERRE * 86400);
        $st = $this->db->prepare("UPDATE soporte_tickets SET estado = 'cerrado', cerrado_en = NOW(), pendiente = 0 WHERE estado = 'resuelto' AND resuelto_en IS NOT NULL AND resuelto_en < ?");
        $st->execute([$limite]);
        return $st->rowCount();
    }

    // ---------------------------------------------------------------- mails de aviso

    /** @return string[] */
    public static function mailsAviso(): array
    {
        $v = (string) (Configuracion::valor('soporte.mail_aviso') ?? '');
        return array_values(array_filter(array_map('trim', preg_split('/[,;\s]+/', $v) ?: []), static fn ($m) => filter_var($m, FILTER_VALIDATE_EMAIL) !== false));
    }

    /** @return array{ok:bool,error?:string} */
    public function guardarMailsAviso(string $valor, ?int $usuarioId): array
    {
        $lista = array_values(array_filter(array_map('trim', preg_split('/[,;\s]+/', $valor) ?: [])));
        foreach ($lista as $m) {
            if (filter_var($m, FILTER_VALIDATE_EMAIL) === false) {
                return ['ok' => false, 'error' => '«' . $m . '» no es un mail válido.'];
            }
        }
        if (count($lista) > 10) {
            return ['ok' => false, 'error' => 'Hasta 10 mails.'];
        }
        $this->db->prepare("INSERT INTO configuracion (clave, valor, tipo, descripcion) VALUES ('soporte.mail_aviso', ?, 'texto', 'Soporte: mails que reciben los avisos')
            ON DUPLICATE KEY UPDATE valor = VALUES(valor)")->execute([implode(', ', $lista)]);
        Configuracion::reset();
        return ['ok' => true];
    }

    /** @param array<string,mixed>|null $t */
    private function avisarAOys(?array $t, string $que, string $texto): void
    {
        if ($t === null) {
            return;
        }
        $para = self::mailsAviso();
        if ($para === []) {
            return; // sin mails configurados: queda en la bandeja
        }
        $cliente = $t['instancia_nombre'] !== '' ? $t['instancia_nombre'] : ($t['instancia'] !== '' ? $t['instancia'] : 'Instalación principal');
        $asunto = '[Soporte #' . $t['id'] . '] ' . ($t['prioridad'] === 'urgente' ? 'URGENTE · ' : '') . $cliente . ': ' . $t['asunto'];
        $cuerpo = $que . ' de ' . $cliente . ($t['instancia'] !== '' ? ' (' . $t['instancia'] . ')' : '') . "\n"
            . 'Tipo: ' . (self::TIPOS[$t['tipo']] ?? $t['tipo']) . ' · Prioridad: ' . (self::PRIORIDADES[$t['prioridad']] ?? $t['prioridad']) . "\n"
            . 'Usuario: ' . $t['usuario'] . ($t['usuario_mail'] !== '' ? ' <' . $t['usuario_mail'] . '>' : '') . ' · Versión: ' . $t['version'] . "\n"
            . ($t['pantalla'] !== '' ? 'Pantalla: ' . $t['pantalla'] . "\n" : '')
            . "\n" . $texto . "\n\n" . 'Ver el ticket: ' . url_absoluta(url('/soporte/bandeja/' . $t['id'])) . "\n";
        $this->registrarAviso((int) $t['id'], $this->mandar($para, $asunto, $cuerpo));
    }

    /** @param array<string,mixed>|null $t */
    private function avisarAlCliente(?array $t, string $texto): ?string
    {
        if ($t === null || $t['usuario_mail'] === '') {
            return null;
        }
        $base = $t['instancia'] !== '' ? 'https://' . $t['instancia'] . url('/soporte/' . $t['id']) : url_absoluta(url('/soporte/' . $t['id']));
        $cuerpo = 'Hola ' . $t['usuario'] . ",\n\nO&S respondió tu ticket #" . $t['id'] . ' «' . $t['asunto'] . "»:\n\n" . $texto . "\n\n"
            . 'Estado: ' . (self::ESTADOS[$t['estado']] ?? $t['estado']) . "\n" . 'Para contestar entrá al sistema: ' . $base . "\n";
        $error = $this->mandar([$t['usuario_mail']], '[Soporte #' . $t['id'] . '] Respuesta de O&S: ' . $t['asunto'], $cuerpo);
        $this->registrarAviso((int) $t['id'], $error);
        return $error;
    }

    /** @param string[] $para */
    private function mandar(array $para, string $asunto, string $texto): ?string
    {
        try {
            return self::$enviador !== null ? (self::$enviador)($para, $asunto, $texto) : Mailer::enviar($para, $asunto, $texto);
        } catch (\Throwable $e) {
            App::registrar($e);
            return $e->getMessage();
        }
    }

    private function registrarAviso(int $id, ?string $error): void
    {
        $this->db->prepare('UPDATE soporte_tickets SET aviso_error = ? WHERE id = ?')->execute([$error !== null ? mb_substr($error, 0, 255) : null, $id]);
    }

    // ---------------------------------------------------------------- comunes

    /** @return array<string,mixed>|null */
    public function ticket(int $id): ?array
    {
        $st = $this->db->prepare('SELECT * FROM soporte_tickets WHERE id = ?');
        $st->execute([$id]);
        $t = $st->fetch();
        return $t === false ? null : $t;
    }

    /** @param array{nombre:string,datos:string,tipo:string}|null $adjunto */
    private function agregarMensaje(int $ticket, string $autorTipo, string $autor, string $texto, bool $interno, ?array $adjunto, string $ahora): int
    {
        $this->db->prepare('INSERT INTO soporte_mensajes (ticket_id, autor_tipo, autor, texto, interno, creado_en) VALUES (?, ?, ?, ?, ?, ?)')
            ->execute([$ticket, $autorTipo, mb_substr($autor, 0, 100), $texto, $interno ? 1 : 0, $ahora]);
        $id = (int) $this->db->lastInsertId();
        if ($adjunto !== null) {
            $st = $this->db->prepare('INSERT INTO soporte_adjuntos (ticket_id, mensaje_id, nombre, tipo, tamano, datos, creado_en) VALUES (?, ?, ?, ?, ?, ?, ?)');
            $st->bindValue(1, $ticket, PDO::PARAM_INT);
            $st->bindValue(2, $id, PDO::PARAM_INT);
            $st->bindValue(3, $adjunto['nombre']);
            $st->bindValue(4, $adjunto['tipo']);
            $st->bindValue(5, strlen($adjunto['datos']), PDO::PARAM_INT);
            $st->bindValue(6, $adjunto['datos'], PDO::PARAM_LOB);
            $st->bindValue(7, $ahora);
            $st->execute();
        }
        return $id;
    }

    /**
     * Adjunto opcional (captura o PDF). El tipo se toma del contenido, no del nombre.
     * @param array<string,string> $errores
     * @return array{nombre:string,datos:string,tipo:string}|null
     */
    private function validarAdjunto(mixed $a, array &$errores): ?array
    {
        if (!is_array($a) || (string) ($a['datos'] ?? '') === '') {
            return null;
        }
        $datos = (string) $a['datos'];
        if (strlen($datos) > self::MAX_ADJUNTO) {
            $errores['adjunto'] = 'El archivo es demasiado grande (hasta 3 MB).';
            return null;
        }
        $tipo = self::tipoDe($datos);
        if ($tipo === null) {
            $errores['adjunto'] = 'Solo se pueden adjuntar imágenes (PNG, JPG, WEBP, GIF) o PDF.';
            return null;
        }
        $nombre = trim((string) preg_replace('/[^\w.\- ()áéíóúñÁÉÍÓÚÑ]+/u', '_', basename((string) ($a['nombre'] ?? ''))));
        if ($nombre === '' || $nombre === '.') {
            $nombre = 'adjunto';
        }
        if (!str_contains($nombre, '.')) {
            $nombre .= '.' . self::TIPOS_ADJUNTO[$tipo];
        }
        return ['nombre' => mb_substr($nombre, 0, 150), 'datos' => $datos, 'tipo' => $tipo];
    }

    public static function tipoDe(string $datos): ?string
    {
        return match (true) {
            str_starts_with($datos, "\x89PNG\r\n\x1a\n") => 'image/png',
            str_starts_with($datos, "\xFF\xD8\xFF") => 'image/jpeg',
            str_starts_with($datos, 'RIFF') && substr($datos, 8, 4) === 'WEBP' => 'image/webp',
            str_starts_with($datos, 'GIF87a') || str_starts_with($datos, 'GIF89a') => 'image/gif',
            str_starts_with($datos, '%PDF-') => 'application/pdf',
            default => null,
        };
    }
}
