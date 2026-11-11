<?php
/**
 * Prueba del ABM de equivalencias en modo directo (sin cola), como lo usaría un sistema solo nube (p. ej. Oeste Parts).
 * Uso: php prueba_abm.php "mysql:unix_socket=/tmp/mdb/sock;dbname=fe_abm"
 */
require __DIR__ . '/../afip.php';

use OysAfip\EquivalenciasIva;
use OysAfip\EquivalenciasIvaVista;

$fallas = 0;
function ok($c, $t) { global $fallas; echo ($c ? '  OK   ' : '  FALLA') . ' ' . $t . "\n"; if (!$c) $fallas++; }

$dsn = isset($argv[1]) ? $argv[1] : 'mysql:unix_socket=/tmp/mdb/sock';
$pdo = new PDO(preg_replace('/;?dbname=[^;]*/', '', $dsn), 'root', '', array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION));
$pdo->exec('DROP DATABASE IF EXISTS fe_abm');
$pdo->exec('CREATE DATABASE fe_abm');
$pdo->exec('USE fe_abm');

$abm = new EquivalenciasIva($pdo);
ok(!$abm->existeTabla() && $abm->asegurarTabla() && $abm->existeTabla(), 'crea la tabla equivalencias_afip si no existe');
ok($abm->faltantes(array('1', '3', '5')) === array('1', '3', '5'), 'informa los códigos en uso sin equivalencia');
ok($abm->cargarSugeridas() === array(1, 3, 4, 5), 'carga las sugeridas (1→1, 3→6, 4→4, 5→5)');
ok($abm->buscar('3')['codigoafip'] === '6', 'Monotributo (3) = 6 en ARCA');
ok($abm->guardar('alta', '3', 6) !== array(), 'no deja dar de alta un código repetido');
ok($abm->guardar('alta', '12345', 1) !== array() && $abm->guardar('alta', '9', 99) !== array(), 'valida código del sistema y condición de ARCA');
ok($abm->guardar('modificacion', '4', 15) === array() && $abm->buscar('4')['codigoafip'] === '15', 'modifica');
ok($abm->guardar('baja', '5') === array() && $abm->buscar('5') === null, 'quita');
ok($abm->guardar('modificacion', '5', 5) !== array(), 'no modifica uno que no existe');

// La pantalla
list($e, $m) = EquivalenciasIvaVista::procesar($abm, array('eq_accion' => 'alta', 'codigooys' => '5', 'codigoafip' => '5'));
ok(!$e && strpos($m, 'Consumidor Final') !== false, 'procesar(): alta desde el formulario');
$html = EquivalenciasIvaVista::html($abm, array('url' => 'equivalencias.php', 'csrf' => '<input type="hidden" name="csrf" value="x">', 'en_uso' => array('1', '2')));
ok(strpos($html, 'sin equivalencia: <b>2 (Responsable No Inscripto)') !== false, 'la pantalla avisa el código 2 sin equivalencia');
ok(substr_count($html, '<tr>') === 5, 'lista las 4 equivalencias');
$html = EquivalenciasIvaVista::html($abm, array('url' => 'equivalencias.php', 'editar' => '4'));
ok(strpos($html, 'value="15" selected') !== false, 'formulario de modificación con la condición actual');

echo "\n" . ($fallas ? "$fallas FALLAS\n" : "Todas las pruebas OK\n");
exit($fallas ? 1 : 0);
