<?php
declare(strict_types=1);

namespace App\Modules\Portal;

use App\Core\Db;
use App\Core\Mailer;
use App\Modules\Configuracion\Configuracion;
use PDO;

/**
 * Portal de clientes, sin contraseñas: el cliente escribe su mail y le llega un link (vale 30 minutos y una sola vez). Entra y ve los
 * comprobantes de los clientes que tienen ese mail en la ficha (un contador con varios clientes los ve a todos, de a uno).
 *
 * DECISIÓN D-36 (Claude, a confirmar por Hernán): en el portal se ven solo los comprobantes vigentes y fiscales (no los de prueba) y el saldo de
 * cuenta corriente; los mails que valen son los de «Mails para las facturas» y «Mails para listados» de la ficha del cliente.
 */
final class PortalService
{
    public const MINUTOS = 30;
    private const POR_MAIL = 3;   // links por mail cada 15 minutos
    private const POR_IP = 10;    // pedidos por IP por hora

    private PDO $pdo;

    public function __construct(?PDO $pdo = null)
    {
        $this->pdo = $pdo ?? Db::pdo();
    }

    public static function activo(): bool
    {
        return Configuracion::activo('portal.activo');
    }

    public static function normalizar(string $mail): string
    {
        return mb_strtolower(trim($mail));
    }

    /** Clientes activos que tienen ese mail en la ficha. @return array<int,array<string,mixed>> */
    public function clientesDe(string $mail): array
    {
        $mail = self::normalizar($mail);
        if (filter_var($mail, FILTER_VALIDATE_EMAIL) === false) {
            return [];
        }
        $like = '%' . addcslashes($mail, '%_\\') . '%';
        $st = $this->pdo->prepare('SELECT codigo, razon_social, cuit, emailfc, emaillis FROM clientes WHERE activo = 1 AND (LOWER(emailfc) LIKE ? OR LOWER(emaillis) LIKE ?) ORDER BY razon_social');
        $st->execute([$like, $like]);
        $out = [];
        foreach ($st->fetchAll() as $c) { // el LIKE es un filtro rápido; acá se compara mail por mail
            $lista = preg_split('/[;,\s]+/', mb_strtolower((string) $c['emailfc'] . ';' . (string) $c['emaillis'])) ?: [];
            if (in_array($mail, $lista, true)) {
                $out[] = $c;
            }
        }
        return $out;
    }

    /**
     * Pide el link. Siempre contesta lo mismo hacia afuera (no dice si el mail existe). Devuelve el link si se mandó (para las pruebas) o null.
     *
     * @param callable(string $link):string $armarLink arma la URL completa a partir del token
     */
    public function pedirLink(string $mail, ?string $ip, callable $armarLink, string $empresa): ?string
    {
        $mail = self::normalizar($mail);
        if ($this->clientesDe($mail) === [] || $this->demasiados($mail, $ip)) {
            return null;
        }
        $token = bin2hex(random_bytes(32));
        $this->pdo->prepare('INSERT INTO portal_accesos (email, token_hash, vence, ip) VALUES (?, ?, ?, ?)')
            ->execute([$mail, hash('sha256', $token), date('Y-m-d H:i:s', time() + self::MINUTOS * 60), $ip]);
        $link = $armarLink($token);
        $texto = "Hola:\r\n\r\nPara ver y descargar tus comprobantes de {$empresa}, entrá con este link:\r\n\r\n{$link}\r\n\r\n"
            . 'Vale ' . self::MINUTOS . " minutos y una sola vez. Si no lo pediste, ignorá este mail.\r\n";
        $error = Mailer::enviar([$mail], 'Tu acceso a los comprobantes de ' . $empresa, $texto);
        if ($error !== null) {
            throw new \RuntimeException('No se pudo enviar el mail: ' . $error);
        }
        return $link;
    }

    private function demasiados(string $mail, ?string $ip): bool
    {
        $a = $this->pdo->prepare('SELECT COUNT(*) FROM portal_accesos WHERE email = ? AND creado_en > ?');
        $a->execute([$mail, date('Y-m-d H:i:s', time() - 15 * 60)]);
        if ((int) $a->fetchColumn() >= self::POR_MAIL) {
            return true;
        }
        if ($ip === null || $ip === '') {
            return false;
        }
        $b = $this->pdo->prepare('SELECT COUNT(*) FROM portal_accesos WHERE ip = ? AND creado_en > ?');
        $b->execute([$ip, date('Y-m-d H:i:s', time() - 3600)]);
        return (int) $b->fetchColumn() >= self::POR_IP;
    }

    /** Usa el link: si es válido lo marca usado y devuelve el mail. Una sola vez y antes de vencer. */
    public function usarLink(string $token): ?string
    {
        if (preg_match('/^[a-f0-9]{64}$/', $token) !== 1) {
            return null;
        }
        $this->pdo->beginTransaction();
        try {
            $st = $this->pdo->prepare('SELECT id, email, vence, usado_en FROM portal_accesos WHERE token_hash = ? FOR UPDATE');
            $st->execute([hash('sha256', $token)]);
            $f = $st->fetch();
            if ($f === false || $f['usado_en'] !== null || strtotime((string) $f['vence']) < time()) {
                $this->pdo->commit();
                return null;
            }
            $this->pdo->prepare('UPDATE portal_accesos SET usado_en = NOW() WHERE id = ?')->execute([$f['id']]);
            $this->pdo->commit();
            return (string) $f['email'];
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    /** Comprobantes vigentes y fiscales de un cliente. @return array<int,array<string,mixed>> */
    public function comprobantes(int $cliente, ?int $anio): array
    {
        $nro = \App\Modules\Comprobantes\Tipos::sqlNumeroVisible('s');
        $sql = "SELECT s.fe_clave, s.fecha, s.tipo, s.letra, s.prefijo, {$nro} AS numero, s.neto, s.fe_ccae
                FROM subdiario s WHERE s.cliente = ? AND s.estado = '' AND s.es_prueba = 0 AND s.tipo NOT IN (" . \App\Modules\Comprobantes\Tipos::SQL_INTERNOS . ")";
        $p = [$cliente];
        if ($anio !== null) {
            $sql .= ' AND s.fecha BETWEEN ? AND ?';
            array_push($p, "{$anio}-01-01", "{$anio}-12-31");
        }
        $st = $this->pdo->prepare($sql . ' ORDER BY s.fecha DESC, s.fe_clave DESC LIMIT 500');
        $st->execute($p);
        return $st->fetchAll();
    }

    /** @return int[] años con comprobantes del cliente (el más nuevo primero) */
    public function anios(int $cliente): array
    {
        $st = $this->pdo->prepare("SELECT DISTINCT YEAR(fecha) FROM subdiario WHERE cliente = ? AND estado = '' AND es_prueba = 0 AND tipo NOT IN (" . \App\Modules\Comprobantes\Tipos::SQL_INTERNOS . ") ORDER BY 1 DESC");
        $st->execute([$cliente]);
        return array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
    }

    /** ¿El comprobante es de ese cliente y se puede ver en el portal? */
    public function puedeVer(int $feClave, int $cliente): bool
    {
        $st = $this->pdo->prepare("SELECT 1 FROM subdiario WHERE fe_clave = ? AND cliente = ? AND estado = '' AND es_prueba = 0 AND tipo NOT IN (" . \App\Modules\Comprobantes\Tipos::SQL_INTERNOS . ")");
        $st->execute([$feClave, $cliente]);
        return $st->fetchColumn() !== false;
    }
}
