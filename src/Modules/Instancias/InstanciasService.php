<?php
declare(strict_types=1);

namespace App\Modules\Instancias;

use App\App;
use App\Core\Config;
use App\Core\Db;
use App\Core\Migrator;
use App\Core\Modulos;
use App\Core\Valida;
use App\Modules\Auth\LoginService;
use PDO;

/**
 * Instalaciones de clientes con el mismo código (producto por suscripción).
 *
 * Cada cliente es una instalación propia —su dirección, su base y su carpeta storage/instancias/<dirección>/ con config.php—,
 * pero todas usan la MISMA carpeta de código: el subdominio del cliente apunta a la misma carpeta public/ que la principal y
 * config/config.php elige la configuración por la dirección del pedido. Así un cliente nuevo no necesita otro clon de GIT y una
 * actualización (GIT + «Aplicar cambios en todas») llega a todos juntos.
 *
 * Alta: el Master llena un formulario en la principal; esto conecta a la base vacía (creada en el panel del hosting), crea las
 * tablas, el Master y un Administrador del cliente, carga razón social y CUIT y deja config.php. Lo único que queda a mano es
 * crear el subdominio en el hosting apuntando a la misma carpeta.
 */
final class InstanciasService
{
    /** Condiciones de IVA de la empresa (códigos del VFP) que se ofrecen en el alta. */
    public const IVA = [1 => 'Responsable Inscripto', 7 => 'Monotributo', 4 => 'Exento'];
    /** La misma condición, como cliente del master (códigos de clientes.iva del VFP). */
    private const IVA_CLIENTE = [1 => 1, 7 => 7, 4 => 4];

    /**
     * Carpeta del código que usan los clientes. Con dos carpetas en el hosting (master con la versión nueva y clientes con la
     * estable) va en config/local.php del master: 'panel' => ['carpeta_clientes' => '/ruta/…/facturador_clientes']. Sin eso, es la misma.
     */
    public static function carpetaClientes(): string
    {
        $c = rtrim((string) Config::get('panel.carpeta_clientes', ''), '/');
        return $c !== '' ? $c : App::root();
    }

    public static function dosCarpetas(): bool
    {
        return (realpath(self::carpetaClientes()) ?: self::carpetaClientes()) !== (realpath(App::root()) ?: App::root());
    }

    /** Donde viven las instalaciones de los clientes (storage/instancias de la carpeta de clientes). */
    public static function raiz(): string
    {
        return self::carpetaClientes() . '/storage/instancias';
    }

    private static function raizPrincipal(): string
    {
        return App::root() . '/storage/instancias';
    }

    /** @return array{version:string,commit:?string,rama:?string} */
    public static function codigo(string $carpeta): array
    {
        return ['version' => trim((string) @file_get_contents($carpeta . '/VERSION')), 'commit' => Promocion::commit($carpeta), 'rama' => Promocion::ramaDe($carpeta)];
    }

    private static function migraciones(): string
    {
        return self::carpetaClientes() . '/database/migrations';
    }

    /** El panel existe solo en la instalación principal (nunca dentro de un cliente) y se puede apagar con 'panel' => ['activo' => false]. */
    public static function disponible(): bool
    {
        return (string) Config::get('instancia.host', '') === '' && Config::get('panel.activo', true) !== false;
    }

    public static function hostValido(string $host): bool
    {
        return strlen($host) <= 190 && preg_match('/^[a-z0-9]([a-z0-9-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)+$/', $host) === 1;
    }

    /** "oys.orgysis.site" → "orgysis.site" (el dominio donde se cuelgan los subdominios de los clientes). */
    public static function dominioSugerido(string $hostActual): string
    {
        $dominio = (string) Config::get('panel.dominio', '');
        if ($dominio !== '') {
            return $dominio;
        }
        $partes = explode('.', $hostActual);
        return count($partes) > 2 ? implode('.', array_slice($partes, 1)) : $hostActual;
    }

    /** Direcciones de la instalación principal (las únicas donde responde config/local.php una vez que hay clientes). @return string[] */
    public static function principal(): array
    {
        $f = self::raizPrincipal() . '/_principal.php';
        return is_file($f) ? array_values(array_filter((array) require $f, 'is_string')) : [];
    }

    /** @param string[] $hosts */
    public static function guardarPrincipal(array $hosts): void
    {
        $hosts = array_values(array_unique(array_filter(array_map(static fn ($h) => strtolower(trim((string) $h)), $hosts), [self::class, 'hostValido'])));
        if (!is_dir(self::raizPrincipal())) {
            @mkdir(self::raizPrincipal(), 0775, true);
        }
        self::escribirPhp(self::raizPrincipal() . '/_principal.php', $hosts);
    }

    /**
     * Instalaciones creadas, con su estado (si conecta, cambios de base pendientes, licencia).
     *
     * @return array<int,array<string,mixed>>
     */
    public static function listar(bool $conEstado = true): array
    {
        $out = [];
        foreach (glob(self::raiz() . '/*/config.php') ?: [] as $archivo) {
            $dir = dirname($archivo);
            $host = basename($dir);
            $info = json_decode((string) @file_get_contents($dir . '/info.json'), true) ?: [];
            $lic = json_decode((string) @file_get_contents($dir . '/licencia.json'), true) ?: [];
            $cfg = self::config($host);
            $fila = ['host' => $host, 'nombre' => (string) ($info['nombre'] ?? $host), 'cuit' => (string) ($info['cuit'] ?? ''),
                'alta' => (string) ($info['alta'] ?? ''), 'licencia_vence' => (string) ($lic['vence'] ?? ''), 'cliente' => (int) ($info['cliente'] ?? 0),
                'suscripcion' => Suscripcion::estado((array) ($cfg['suscripcion'] ?? [])), 'conecta' => null, 'pendientes' => null, 'error' => null];
            if ($conEstado) {
                try {
                    $estado = (new Migrator(self::conectar($cfg['db'] ?? []), self::migraciones()))->status();
                    $fila['conecta'] = true;
                    $fila['pendientes'] = count(array_filter($estado, static fn ($a) => !$a));
                } catch (\Throwable $e) {
                    $fila['conecta'] = false;
                    $fila['error'] = $e->getMessage();
                }
            }
            $out[] = $fila;
        }
        usort($out, static fn ($a, $b) => strcmp($a['nombre'], $b['nombre']));
        return $out;
    }

    /** @return array<string,mixed> */
    public static function config(string $host): array
    {
        if (!self::hostValido($host) || !is_file(self::raiz() . '/' . $host . '/config.php')) {
            throw new \RuntimeException('No existe la instalación ' . $host . '.');
        }
        $c = require self::raiz() . '/' . $host . '/config.php';
        return is_array($c) ? $c : [];
    }

    /** @param array<string,mixed> $db */
    public static function conectar(array $db): PDO
    {
        $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', (string) ($db['host'] ?? 'localhost'), (int) ($db['port'] ?? 3306), (string) ($db['name'] ?? ''));
        $pdo = new PDO($dsn, (string) ($db['user'] ?? ''), (string) ($db['pass'] ?? ''), [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => true, // igual que Db::pdo() (error 1615 del hosting compartido)
            PDO::ATTR_TIMEOUT => 5,
        ]);
        $pdo->exec("SET time_zone = '-03:00'");
        return $pdo;
    }

    /**
     * Crea la instalación de un cliente. Devuelve ['ok' => true, 'host', 'admin_usuario', 'admin_clave', 'cambios'] o
     * ['ok' => false, 'errores' => [campo => mensaje]]. La clave del Administrador se muestra una sola vez.
     *
     * @param array<string,mixed> $d datos del formulario
     * @return array<string,mixed>
     */
    public static function crear(array $d, string $hostActual, string $creadoPor): array
    {
        $v = self::normalizar($d, $hostActual);
        $errores = self::validar($v, $d);
        if ($errores !== []) {
            return ['ok' => false, 'errores' => $errores];
        }
        $host = $v['host'];
        $dir = self::raiz() . '/' . $host;
        self::asegurarRaiz();
        // La carpeta se crea primero: si dos personas mandan el mismo alta a la vez, la segunda falla acá.
        if (!@mkdir($dir, 0775)) {
            return ['ok' => false, 'errores' => ['subdominio' => 'Ya existe una instalación para ' . $host . '.']];
        }
        try {
            try {
                $pdo = self::conectar($v['db']);
            } catch (\PDOException $e) {
                throw new ErrorDeAlta('db_name', 'No se pudo conectar a la base con esos datos (' . $e->getMessage() . '). Revisá nombre, usuario y contraseña tal como los creaste en el panel del hosting.');
            }
            $tablas = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
            if ($tablas !== []) {
                throw new ErrorDeAlta('db_name', 'La base ' . $v['db']['name'] . ' ya tiene tablas (' . count($tablas) . '). Para un cliente nuevo usá una base vacía: nunca se pisa una base con datos.');
            }
            $cambios = (new Migrator($pdo, self::migraciones()))->run();
            $pdo->beginTransaction();
            $pdo->prepare('UPDATE parametros SET empresa = ?, cuit = ?, iva = ?, tk_iva = ? WHERE id = 1')
                ->execute([$v['nombre'], $v['cuit'], $v['iva'], self::IVA[$v['iva']]]);
            $alta = $pdo->prepare('INSERT INTO usuarios (usuario, nombre, password_hash, rol_id, debe_cambiar_clave) SELECT ?, ?, ?, id, ? FROM roles WHERE codigo = ?');
            $alta->execute([$v['master_usuario'], $v['master_nombre'], password_hash((string) $d['master_clave'], PASSWORD_DEFAULT), 0, 'master']);
            $claveAdmin = self::claveAlAzar();
            $alta->execute([$v['admin_usuario'], $v['admin_nombre'], password_hash($claveAdmin, PASSWORD_DEFAULT), 1, 'admin']);
            $pdo->commit();

            foreach (['logs', 'sessions', 'migracion'] as $sub) {
                @mkdir($dir . '/' . $sub, 0775, true);
            }
            self::escribirPhp($dir . '/config.php', self::armarConfig($v));
            file_put_contents($dir . '/info.json', json_encode(['nombre' => $v['nombre'], 'cuit' => $v['cuit'], 'iva' => $v['iva'], 'alta' => date('Y-m-d H:i'),
                'creado_por' => $creadoPor], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            // A partir de ahora la configuración principal solo responde en sus direcciones (ver config/config.php); en la carpeta
            // de clientes ninguna dirección sin instalación usa su local.php.
            self::guardarPrincipal(array_merge(self::principal(), [$hostActual]));
            if (self::dosCarpetas() && !is_file(self::raiz() . '/_principal.php')) {
                self::escribirPhp(self::raiz() . '/_principal.php', []);
            }
            $cliente = self::crearCliente($host, $v);
            return ['ok' => true, 'host' => $host, 'admin_usuario' => $v['admin_usuario'], 'admin_clave' => $claveAdmin, 'cambios' => count($cambios)] + $cliente;
        } catch (\Throwable $e) {
            if (isset($pdo) && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            self::borrarCarpeta($dir);
            if ($e instanceof ErrorDeAlta) {
                return ['ok' => false, 'errores' => [$e->campo => $e->getMessage()]];
            }
            throw $e;
        }
    }

    /**
     * Cambia lo que el cliente necesita sin entrar al hosting: mail de salida, modo de ARCA y licencia.
     *
     * @param array<string,mixed> $d
     * @return array<string,string> errores (vacío = guardado)
     */
    public static function editar(string $host, array $d): array
    {
        $c = self::config($host);
        $modo = (string) ($d['afip_modo'] ?? 'interno');
        if (!in_array($modo, ['interno', 'simulado', 'homologacion', 'produccion'], true)) {
            return ['afip_modo' => 'Modo de ARCA inválido.'];
        }
        $rutaAfip = trim((string) ($d['afip_config'] ?? ''));
        if (in_array($modo, ['homologacion', 'produccion'], true) && $rutaAfip === '') {
            return ['afip_config' => 'Para homologación o producción hace falta la ruta al config_afip.php de este cliente.'];
        }
        $sus = (string) ($d['suscripcion'] ?? 'activa');
        if (!isset(Suscripcion::ESTADOS[$sus])) {
            return ['suscripcion' => 'Estado de suscripción inválido.'];
        }
        $fechas = [];
        foreach (['sin_emision_desde', 'bloqueo_desde'] as $campo) {
            $f = trim((string) ($d[$campo] ?? ''));
            if ($f !== '' && Valida::fecha($f) === null) {
                return [$campo => 'Fecha inválida.'];
            }
            $fechas[$campo] = $f === '' ? '' : (string) Valida::fecha($f);
        }
        $c['suscripcion'] = ['estado' => $sus, 'mensaje' => mb_substr(trim((string) ($d['suscripcion_mensaje'] ?? '')), 0, 300), 'desde' => date('Y-m-d')] + $fechas;
        $c['modulos'] = self::modulosDe($d);
        $c['afip'] = ['modo' => $modo, 'config' => $rutaAfip];
        $c['licencia'] = ['exigir' => !empty($d['licencia'])];
        $mail = self::mailDe($d);
        // La contraseña del mail no se muestra: vacía en el formulario = se conserva la que había.
        if ($mail['pass'] === '' && $mail['host'] === (string) ($c['mail']['host'] ?? '') && $mail['user'] === (string) ($c['mail']['user'] ?? '')) {
            $mail['pass'] = (string) ($c['mail']['pass'] ?? '');
        }
        $c['mail'] = $mail;
        self::escribirPhp(self::raiz() . '/' . $host . '/config.php', $c);
        return [];
    }

    /**
     * Soporte (0.42.0): cada cliente necesita la dirección del master y su clave para mandar tickets ('soporte' => ['url', 'clave'] en su config).
     * Se completa sola al entrar al panel o a la bandeja de soporte; la clave no cambia una vez creada. Devuelve cuántos configs se escribieron.
     */
    public static function asegurarSoporte(string $urlMaster): int
    {
        $urlMaster = rtrim($urlMaster, '/');
        if (!self::disponible() || $urlMaster === '') {
            return 0;
        }
        $n = 0;
        foreach (glob(self::raiz() . '/*/config.php') ?: [] as $archivo) {
            $host = basename(dirname($archivo));
            try {
                $c = self::config($host);
            } catch (\Throwable) {
                continue;
            }
            $s = (array) ($c['soporte'] ?? []);
            if ((string) ($s['clave'] ?? '') !== '' && (string) ($s['url'] ?? '') === $urlMaster) {
                continue;
            }
            $c['soporte'] = ['url' => $urlMaster, 'clave' => (string) ($s['clave'] ?? '') !== '' ? (string) $s['clave'] : 'fws_' . bin2hex(random_bytes(24))];
            try {
                self::escribirPhp($archivo, $c);
                $n++;
            } catch (\Throwable $e) {
                App::registrar($e);
            }
        }
        return $n;
    }

    /** Clave de soporte de un cliente (la que tiene en su config), o null si no existe la instalación o no tiene. */
    public static function claveSoporte(string $host): ?string
    {
        try {
            $c = self::config($host);
        } catch (\Throwable) {
            return null;
        }
        $k = (string) ($c['soporte']['clave'] ?? '');
        return $k !== '' ? $k : null;
    }

    /** Nombre del cliente (info.json) o la dirección. */
    public static function nombreDe(string $host): string
    {
        $info = self::hostValido($host) ? json_decode((string) @file_get_contents(self::raiz() . '/' . $host . '/info.json'), true) : null;
        return is_array($info) && (string) ($info['nombre'] ?? '') !== '' ? (string) $info['nombre'] : $host;
    }

    /**
     * Aplica los cambios de base pendientes en la principal y en cada cliente (después de actualizar el código con GIT).
     *
     * @return array<string,string> dirección → resultado
     */
    public static function actualizarTodas(): array
    {
        $out = [];
        $base = App::root() . '/database/migrations';
        try {
            $hechos = (new Migrator(Db::pdo(), $base))->run();
            $out['Principal'] = $hechos === [] ? 'al día' : 'aplicados: ' . implode(', ', $hechos);
        } catch (\Throwable $e) {
            $out['Principal'] = 'ERROR: ' . $e->getMessage();
        }
        foreach (self::listar(false) as $i) {
            try {
                $hechos = (new Migrator(self::conectar(self::config($i['host'])['db'] ?? []), self::migraciones()))->run();
                $out[$i['host']] = $hechos === [] ? 'al día' : 'aplicados: ' . implode(', ', $hechos);
            } catch (\Throwable $e) {
                $out[$i['host']] = 'ERROR: ' . $e->getMessage();
            }
        }
        return $out;
    }

    /**
     * Da de alta el cliente en la base del master (para facturarle la suscripción). Si ya hay un cliente activo con ese CUIT, se usa ese.
     *
     * @param array<string,mixed>|null $v datos del alta (null = tomarlos de info.json de una instalación ya creada)
     * @return array{cliente:?int,aviso_cliente:?string}
     */
    public static function crearCliente(string $host, ?array $v = null): array
    {
        $dir = self::raiz() . '/' . $host;
        $info = json_decode((string) @file_get_contents($dir . '/info.json'), true) ?: [];
        $v ??= ['nombre' => (string) ($info['nombre'] ?? ''), 'cuit' => (string) ($info['cuit'] ?? ''), 'iva' => (int) ($info['iva'] ?? 1),
            'domicilio' => '', 'localidad' => '', 'telefono' => '', 'email' => ''];
        try {
            $st = Db::pdo()->prepare('SELECT codigo FROM clientes WHERE cuit = ? AND activo = 1 ORDER BY codigo LIMIT 1');
            $st->execute([$v['cuit']]);
            $codigo = (int) $st->fetchColumn();
            $aviso = null;
            if ($codigo === 0) {
                $r = (new \App\Modules\Clientes\ClientesService())->guardar(null, ['razon_social' => mb_substr((string) $v['nombre'], 0, 100),
                    'iva' => (string) self::IVA_CLIENTE[(int) $v['iva']], 'cuit' => (string) $v['cuit'], 'forma_pago' => '2',
                    'domicilio' => $v['domicilio'], 'localidad' => $v['localidad'], 'telefono' => $v['telefono'], 'emailfc' => $v['email'],
                    'observaciones' => 'Suscripción FacturaWeb: ' . $host]);
                if (!$r['ok']) {
                    return ['cliente' => null, 'aviso_cliente' => 'No se pudo crear el cliente en el master: ' . implode(' ', $r['errores']) . ' Crealo desde el panel.'];
                }
                $codigo = (int) $r['codigo'];
            } else {
                $aviso = 'Ya existía el cliente ' . $codigo . ' con ese CUIT en el master: quedó vinculado a ese.';
            }
            $info['cliente'] = $codigo;
            $info['iva'] = (int) $v['iva'];
            @file_put_contents($dir . '/info.json', json_encode($info, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            return ['cliente' => $codigo, 'aviso_cliente' => $aviso];
        } catch (\Throwable $e) {
            \App\App::registrar($e);
            return ['cliente' => null, 'aviso_cliente' => 'No se pudo crear el cliente en el master (' . $e->getMessage() . '). Crealo desde el panel.'];
        }
    }

    /** Copia completa de la base del cliente (.sql comprimido) para entregarle o guardar antes de la baja. @return array{nombre:string,contenido:string} */
    public static function copia(string $host): array
    {
        $pdo = self::conectar(self::config($host)['db'] ?? []);
        $sql = "-- FacturaWeb: copia de {$host} del " . date('d/m/Y H:i') . "\nSET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS = 0;\n\n";
        foreach ($pdo->query('SHOW FULL TABLES WHERE Table_type = \'BASE TABLE\'')->fetchAll(PDO::FETCH_NUM) as [$tabla]) {
            $t = '`' . str_replace('`', '``', (string) $tabla) . '`';
            $sql .= "DROP TABLE IF EXISTS {$t};\n" . $pdo->query('SHOW CREATE TABLE ' . $t)->fetch(PDO::FETCH_NUM)[1] . ";\n";
            $filas = [];
            foreach ($pdo->query('SELECT * FROM ' . $t, PDO::FETCH_NUM) as $f) {
                $filas[] = '(' . implode(',', array_map(static fn ($x) => $x === null ? 'NULL' : $pdo->quote((string) $x), $f)) . ')';
                if (count($filas) === 200) {
                    $sql .= "INSERT INTO {$t} VALUES\n" . implode(",\n", $filas) . ";\n";
                    $filas = [];
                }
            }
            if ($filas !== []) {
                $sql .= "INSERT INTO {$t} VALUES\n" . implode(",\n", $filas) . ";\n";
            }
            $sql .= "\n";
        }
        $sql .= "SET FOREIGN_KEY_CHECKS = 1;\n";
        return ['nombre' => 'copia-' . $host . '-' . date('Ymd-His') . '.sql.gz', 'contenido' => (string) gzencode($sql, 6)];
    }

    /**
     * Baja definitiva: borra todas las tablas de la base del cliente y su carpeta (config, logs, licencia). No se puede deshacer:
     * se pide escribir la dirección. Queda un renglón en storage/instancias/_bajas.json (sin datos del cliente más que nombre y CUIT).
     */
    public static function baja(string $host, string $confirmacion, string $por): ?string
    {
        $c = self::config($host);
        if (trim(mb_strtolower($confirmacion)) !== $host) {
            return 'Para dar de baja hay que escribir la dirección exacta (' . $host . ').';
        }
        $dir = self::raiz() . '/' . $host;
        $info = json_decode((string) @file_get_contents($dir . '/info.json'), true) ?: [];
        try {
            $pdo = self::conectar($c['db'] ?? []);
            $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
            foreach ($pdo->query('SHOW FULL TABLES')->fetchAll(PDO::FETCH_NUM) as [$tabla, $tipo]) {
                $pdo->exec(($tipo === 'VIEW' ? 'DROP VIEW ' : 'DROP TABLE ') . '`' . str_replace('`', '``', (string) $tabla) . '`');
            }
            $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
        } catch (\PDOException $e) {
            return 'No se pudo vaciar la base del cliente (' . $e->getMessage() . '). No se borró nada de la carpeta.';
        }
        self::borrarCarpeta($dir);
        $bajas = json_decode((string) @file_get_contents(self::raiz() . '/_bajas.json'), true) ?: [];
        $bajas[] = ['host' => $host, 'nombre' => $info['nombre'] ?? '', 'cuit' => $info['cuit'] ?? '', 'cliente' => $info['cliente'] ?? null,
            'alta' => $info['alta'] ?? '', 'baja' => date('Y-m-d H:i'), 'por' => $por, 'base' => (string) ($c['db']['name'] ?? '')];
        @file_put_contents(self::raiz() . '/_bajas.json', json_encode($bajas, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        return null;
    }

    /** @return array<int,array<string,mixed>> */
    public static function bajas(): array
    {
        return json_decode((string) @file_get_contents(self::raiz() . '/_bajas.json'), true) ?: [];
    }

    /** Instalaciones creadas en la carpeta del master antes de separar en dos carpetas. @return string[] */
    public static function enCarpetaMaster(): array
    {
        if (!self::dosCarpetas()) {
            return [];
        }
        return array_map(static fn ($f) => basename(dirname($f)), glob(self::raizPrincipal() . '/*/config.php') ?: []);
    }

    /** Las pasa a la carpeta de clientes (después hay que apuntar su subdominio a esa carpeta). @return string[] movidas */
    public static function moverACarpetaClientes(): array
    {
        self::asegurarRaiz();
        $hechas = [];
        foreach (self::enCarpetaMaster() as $host) {
            if (!is_dir(self::raiz() . '/' . $host) && @rename(self::raizPrincipal() . '/' . $host, self::raiz() . '/' . $host)) {
                $hechas[] = $host;
            }
        }
        if ($hechas !== [] && !is_file(self::raiz() . '/_principal.php')) {
            self::escribirPhp(self::raiz() . '/_principal.php', []);
        }
        return $hechas;
    }

    /** @param array<string,mixed> $d @return array<string,bool> */
    private static function modulosDe(array $d): array
    {
        if (empty($d['modulos_enviados'])) { // formulario sin la sección de módulos: lo que viene por omisión
            return array_map(static fn (array $m) => $m['por_omision'], Modulos::LISTA);
        }
        $elegidos = (array) ($d['modulos'] ?? []);
        $out = [];
        foreach (array_keys(Modulos::LISTA) as $m) {
            $out[$m] = !empty($elegidos[$m]);
        }
        return $out;
    }

    // ------------------------------------------------------------------ internos

    /** @param array<string,mixed> $d @return array<string,mixed> */
    private static function normalizar(array $d, string $hostActual): array
    {
        $sub = strtolower(trim((string) ($d['subdominio'] ?? '')));
        $dominio = strtolower(trim((string) ($d['dominio'] ?? ''))) ?: self::dominioSugerido($hostActual);
        $nombre = trim((string) ($d['nombre'] ?? ''));
        return [
            'nombre' => $nombre,
            'cuit' => preg_replace('/\D/', '', (string) ($d['cuit'] ?? '')) ?? '',
            'iva' => (int) ($d['iva'] ?? 1),
            'subdominio' => $sub,
            'dominio' => $dominio,
            'host' => $sub . '.' . $dominio,
            'db' => ['host' => trim((string) ($d['db_host'] ?? '')) ?: (string) Config::get('db.host', 'localhost'), 'port' => (int) ($d['db_port'] ?? 0) ?: 3306,
                'name' => trim((string) ($d['db_name'] ?? '')), 'user' => trim((string) ($d['db_user'] ?? '')), 'pass' => (string) ($d['db_pass'] ?? '')],
            'master_usuario' => mb_strtolower(trim((string) ($d['master_usuario'] ?? ''))),
            'master_nombre' => trim((string) ($d['master_nombre'] ?? '')) ?: 'O&S Sistemas',
            'admin_usuario' => mb_strtolower(trim((string) ($d['admin_usuario'] ?? ''))) ?: 'admin',
            'admin_nombre' => trim((string) ($d['admin_nombre'] ?? '')) ?: mb_substr($nombre, 0, 80),
            'licencia' => !empty($d['licencia']),
            'mail' => self::mailDe($d),
            'modulos' => self::modulosDe($d),
            'domicilio' => mb_substr(trim((string) ($d['domicilio'] ?? '')), 0, 80),
            'localidad' => mb_substr(trim((string) ($d['localidad'] ?? '')), 0, 40),
            'telefono' => mb_substr(trim((string) ($d['telefono'] ?? '')), 0, 60),
            'email' => trim((string) ($d['email'] ?? '')),
        ];
    }

    /** @param array<string,mixed> $v @param array<string,mixed> $d @return array<string,string> */
    private static function validar(array $v, array $d): array
    {
        $e = [];
        if ($v['nombre'] === '' || mb_strlen($v['nombre']) > 120) {
            $e['nombre'] = 'La razón social es obligatoria (hasta 120 caracteres).';
        }
        if (!Valida::cuit($v['cuit'])) {
            $e['cuit'] = 'CUIT inválido (11 dígitos con el verificador correcto).';
        }
        if (!isset(self::IVA[$v['iva']])) {
            $e['iva'] = 'Elegí la condición de IVA.';
        }
        if (!preg_match('/^[a-z0-9]([a-z0-9-]{0,38}[a-z0-9])?$/', $v['subdominio'])) {
            $e['subdominio'] = 'Subdominio de letras sin acentos, números y guiones (ej. gogolinas).';
        } elseif (!self::hostValido($v['host'])) {
            $e['dominio'] = 'Dominio inválido.';
        } elseif (is_dir(self::raiz() . '/' . $v['host']) || in_array($v['host'], self::principal(), true)) {
            $e['subdominio'] = 'Ya existe una instalación para ' . $v['host'] . '.';
        }
        if ($v['db']['name'] === '' || $v['db']['user'] === '') {
            $e['db_name'] = 'Completá la base y el usuario (creados en el panel del hosting).';
        } elseif ($v['db']['name'] === (string) Config::get('db.name') && $v['db']['host'] === (string) Config::get('db.host')) {
            $e['db_name'] = 'Esa es la base de la instalación principal: cada cliente lleva su propia base.';
        }
        foreach (['master_usuario', 'admin_usuario'] as $campo) {
            if (!preg_match('/^[a-z0-9._-]{3,40}$/', $v[$campo])) {
                $e[$campo] = 'Usuario de 3 a 40 letras sin acentos, números, punto o guion.';
            }
        }
        if (!isset($e['admin_usuario']) && $v['admin_usuario'] === $v['master_usuario']) {
            $e['admin_usuario'] = 'Tiene que ser distinto del usuario Master.';
        }
        $clave = LoginService::validarClaveNueva((string) ($d['master_clave'] ?? ''), (string) ($d['master_confirmacion'] ?? ''), $v['master_usuario']);
        if ($clave !== null) {
            $e['master_clave'] = $clave;
        }
        if ($v['email'] !== '' && filter_var($v['email'], FILTER_VALIDATE_EMAIL) === false) {
            $e['email'] = 'Mail inválido.';
        }
        if ($v['mail']['host'] !== '' && ($v['mail']['user'] === '' || $v['mail']['from'] === '')) {
            $e['mail_host'] = 'Si cargás el mail, completá también usuario y remitente (o dejalo vacío y se carga después).';
        }
        return $e;
    }

    /** @param array<string,mixed> $d @return array<string,mixed> */
    private static function mailDe(array $d): array
    {
        $secure = (string) ($d['mail_secure'] ?? 'tls');
        return ['host' => trim((string) ($d['mail_host'] ?? '')), 'port' => (int) ($d['mail_port'] ?? 0) ?: 587,
            'secure' => in_array($secure, ['tls', 'ssl', ''], true) ? $secure : 'tls', 'user' => trim((string) ($d['mail_user'] ?? '')),
            'pass' => (string) ($d['mail_pass'] ?? ''), 'from' => trim((string) ($d['mail_from'] ?? '')), 'from_name' => trim((string) ($d['mail_from_name'] ?? ''))];
    }

    /** @param array<string,mixed> $v @return array<string,mixed> */
    private static function armarConfig(array $v): array
    {
        return [
            'app' => ['url' => 'https://' . $v['host'], 'instalar_token' => '', 'diag_token' => ''],
            // Suscripción mensual: sin licencia vigente (firmada por O&S, Sistema → Licencia) no se emiten comprobantes.
            'licencia' => ['exigir' => $v['licencia']],
            'suscripcion' => ['estado' => 'activa'],
            'modulos' => $v['modulos'],
            'afip' => ['modo' => 'interno', 'config' => ''],
            'mail' => $v['mail'],
            'db' => $v['db'] + ['charset' => 'utf8mb4'],
        ];
    }

    /** Clave inicial del Administrador del cliente: se la cambia en el primer ingreso. */
    private static function claveAlAzar(): string
    {
        $letras = 'abcdefghjkmnpqrstuvwxyz';
        $c = '';
        for ($i = 0; $i < 6; $i++) {
            $c .= $letras[random_int(0, strlen($letras) - 1)];
        }
        return ucfirst($c) . '-' . random_int(1000, 9999);
    }

    private static function asegurarRaiz(): void
    {
        $raiz = self::raiz();
        if (!is_dir($raiz) && !@mkdir($raiz, 0775, true)) {
            throw new \RuntimeException('No se pudo crear storage/instancias (falta permiso de escritura en storage/).');
        }
    }

    /** Escribe un archivo PHP que devuelve el valor (config.php de cada cliente). Primero a un temporal y después se renombra. */
    private static function escribirPhp(string $archivo, mixed $valor): void
    {
        $tmp = $archivo . '.tmp' . bin2hex(random_bytes(3));
        $php = "<?php\n// Generado por Sistema → Instalaciones de clientes. Tiene claves: NO va al repositorio.\nreturn " . var_export($valor, true) . ";\n";
        if (@file_put_contents($tmp, $php) === false || !@rename($tmp, $archivo)) {
            @unlink($tmp);
            throw new \RuntimeException('No se pudo escribir ' . basename($archivo) . ' en storage/instancias (permisos).');
        }
        @chmod($archivo, 0640);
        if (function_exists('opcache_invalidate')) {
            @opcache_invalidate($archivo, true);
        }
    }

    private static function borrarCarpeta(string $dir): void
    {
        if (!is_dir($dir) || !str_starts_with($dir, self::raiz() . '/')) {
            return;
        }
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $f) {
            $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
        }
        @rmdir($dir);
    }
}
