<?php
declare(strict_types=1);

/*
 * Crea (o actualiza) el usuario Master de O&S, con rol Master.
 * La contraseña se pide por teclado (no se muestra) y nunca queda escrita en ningún archivo.
 *
 *   php bin/crear-master.php
 *   php bin/crear-master.php --usuario=oys --nombre="O&S Sistemas" --email=soporte@ejemplo.com
 *
 * Si el usuario ya existe, le cambia la contraseña, lo activa, lo desbloquea y le asigna Master.
 * Es el primer usuario de una instalación nueva: con él se crean los demás desde Usuarios.
 * Sin terminal (por ejemplo en un script), lee la contraseña de la primera línea de la entrada.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require dirname(__DIR__) . '/src/bootstrap.php';

use App\App;
use App\Core\Db;
use App\Modules\Auth\LoginService;

App::boot();

$opts = getopt('', ['usuario:', 'nombre:', 'email:']);
$tty = function_exists('posix_isatty') ? posix_isatty(STDIN) : stream_isatty(STDIN);

$preguntar = static function (string $texto, string $default = '') use ($tty): string {
    if (!$tty) {
        return $default;
    }
    echo $texto . ($default !== '' ? " [{$default}]" : '') . ': ';
    $v = trim((string) fgets(STDIN));
    return $v === '' ? $default : $v;
};
$leerClave = static function (string $texto) use ($tty): string {
    if (!$tty) {
        return rtrim((string) fgets(STDIN), "\r\n");
    }
    echo $texto . ': ';
    shell_exec('stty -echo');
    $v = rtrim((string) fgets(STDIN), "\r\n");
    shell_exec('stty echo');
    echo "\n";
    return $v;
};

$usuario = mb_strtolower(trim((string) ($opts['usuario'] ?? $preguntar('Usuario', 'oys'))));
$nombre = trim((string) ($opts['nombre'] ?? $preguntar('Nombre', 'O&S Sistemas')));
$email = trim((string) ($opts['email'] ?? $preguntar('Email (opcional)')));

if (!preg_match('/^[a-z0-9._-]{3,40}$/', $usuario) || $nombre === '') {
    fwrite(STDERR, "Usuario inválido (3 a 40 letras sin acentos, números, punto o guion) o nombre vacío.\n");
    exit(1);
}
if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
    fwrite(STDERR, "Email inválido.\n");
    exit(1);
}

$clave = $leerClave('Contraseña');
$confirmacion = $tty ? $leerClave('Repetila') : $clave;
$error = LoginService::validarClaveNueva($clave, $confirmacion, $usuario);
if ($error !== null) {
    fwrite(STDERR, $error . "\n");
    exit(1);
}

$pdo = Db::pdo();
$rolMaster = $pdo->query("SELECT id FROM roles WHERE codigo = 'master'")->fetchColumn();
if ($rolMaster === false) {
    fwrite(STDERR, "No existe el rol Master. ¿Corriste php bin/migrate.php?\n");
    exit(1);
}

$pdo->beginTransaction();
$st = $pdo->prepare('SELECT id FROM usuarios WHERE usuario = ?');
$st->execute([$usuario]);
$id = $st->fetchColumn();
$hash = password_hash($clave, PASSWORD_DEFAULT);
if ($id === false) {
    $pdo->prepare('INSERT INTO usuarios (usuario, nombre, email, password_hash, rol_id) VALUES (?, ?, ?, ?, ?)')
        ->execute([$usuario, $nombre, $email === '' ? null : mb_strtolower($email), $hash, (int) $rolMaster]);
    $id = (int) $pdo->lastInsertId();
    $accion = 'creado';
} else {
    $id = (int) $id;
    $pdo->prepare(
        'UPDATE usuarios SET nombre = ?, email = COALESCE(?, email), password_hash = ?, rol_id = ?, activo = 1, debe_cambiar_clave = 0,
                intentos_fallidos = 0, bloqueado_hasta = NULL WHERE id = ?'
    )->execute([$nombre, $email === '' ? null : mb_strtolower($email), $hash, (int) $rolMaster, $id]);
    $accion = 'actualizado';
}
$pdo->commit();

echo "Usuario Master \"{$usuario}\" {$accion}.\n";
