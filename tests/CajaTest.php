<?php
declare(strict_types=1);

namespace Tests;

use App\Core\Db;
use App\Modules\Caja\CajaService;
use App\Modules\Comprobantes\Calculadora as Calc;

/** Caja: turnos con arqueo, ingresos y egresos manuales con tope y anulación por contra-asiento, auditoría encadenada, hora y usuario en cada cobro y permisos. */
final class CajaTest extends ComprobantesTestCase
{
    private CajaService $caja;
    private int $ana;
    private int $beto;

    protected function setUp(): void
    {
        parent::setUp();
        Db::pdo()->exec("UPDATE parametros SET caja_tope = 10000000 WHERE id = 1");
        $this->caja = new CajaService();
        $this->ana = $this->crearUsuario('t_caja_ana', 'operador');
        $this->beto = $this->crearUsuario('t_caja_beto', 'admin');
    }

    protected function tearDown(): void
    {
        Db::pdo()->exec("UPDATE parametros SET caja_tope = 10000000 WHERE id = 1");
        parent::tearDown();
    }

    public function testTurnoAbreConFondoUnoPorCajeroYCierraConArqueo(): void
    {
        $r = $this->caja->abrir($this->ana, '1.000,50');
        $this->assertTrue($r['ok']);
        $this->assertSame(1000.5, (float) $this->caja->turno($r['id'])['fondo_inicial']);
        $this->assertFalse($this->caja->abrir($this->ana, '0')['ok'], 'un solo turno abierto por cajero');
        $this->assertTrue($this->caja->abrir($this->beto, '0')['ok'], 'otro cajero abre el suyo');
        $this->assertFalse($this->caja->abrir($this->crearUsuario('t_caja_x', 'operador'), 'abc')['ok'], 'fondo inválido');
        // venta en efectivo con vuelto durante el turno de Ana: en el arqueo entra el neto (el vuelto salió del cajón)
        $this->loginComo($this->ana);
        $e = $this->emitir($this->pedido(['forma_pago' => '1', 'fpago' => 'EF', 'recibido' => '300']));
        $c = $this->fila('SELECT usuario_id, turno_id, creado_en FROM caja WHERE subdiario_id = ?', [$e['id']]);
        $this->assertSame([$this->ana, $r['id']], [(int) $c['usuario_id'], (int) $c['turno_id']], 'el cobro queda con el cajero y su turno');
        $this->assertNotNull($c['creado_en'], 'y con la hora');
        $res = $this->caja->resumenTurno($r['id']);
        $this->assertSame([1000.5, 242.0, 1242.5], [$res['fondo'], $res['efectivo'], $res['esperado']]);
        // cierre: sin diferencia no pide observación; con diferencia, sí
        $mal = $this->caja->cerrar($r['id'], $this->ana, false, '1200', '');
        $this->assertFalse($mal['ok']);
        $this->assertStringContainsString('diferencia', $mal['error']);
        $ok = $this->caja->cerrar($r['id'], $this->ana, false, '1.200', 'Faltaron 42,50 de cambio');
        $this->assertTrue($ok['ok']);
        $this->assertSame(-42.5, $ok['diferencia']);
        $t = $this->caja->turno($r['id']);
        $this->assertSame(['C', '1242.50', '1200.00', '-42.50'], [$t['estado'], $t['esperado'], $t['contado'], $t['diferencia']]);
        $this->assertFalse($this->caja->cerrar($r['id'], $this->ana, false, '0', 'x')['ok'], 'no se cierra dos veces');
        // el que sigue ve lo contado como fondo sugerido; sin sesión no hay turno
        $this->assertSame(1200.0, $this->caja->fondoSugerido());
        $this->loginComo($this->beto);
        $e2 = $this->emitir($this->pedido(['forma_pago' => '1', 'fpago' => 'EF']));
        $this->assertSame((int) $this->caja->turnoAbierto($this->beto)['id'], (int) $this->fila('SELECT turno_id FROM caja WHERE subdiario_id = ?', [$e2['id']])['turno_id']);
    }

    public function testLoCobradoAntesDeAbrirLaCajaSeSumaAlTurno(): void
    {
        // Ana cobra dos presupuestos en efectivo sin la caja abierta: quedan sin turno
        $this->loginComo($this->ana);
        $pr = ['tipo' => 'PR', 'items' => [['descripcion' => 'x', 'cantidad' => '1', 'precio' => '100', 'alicuota' => '21']]];
        $a = $this->emitir($this->pedido(['forma_pago' => '1', 'fpago' => 'EF'] + $pr));
        $b = $this->emitir($this->pedido(['forma_pago' => '1', 'fpago' => 'EF'] + $pr));
        $this->assertNull($this->fila('SELECT turno_id FROM caja WHERE subdiario_id = ?', [$a['id']])['turno_id']);
        $sin = $this->caja->sinTurno($this->ana, date('Y-m-d'));
        $this->assertSame(2, $sin['cantidad']);
        $this->assertSame(0, $this->caja->sinTurno($this->beto, date('Y-m-d'))['cantidad'], 'los de otro cajero no se cuentan');
        // al abrir, se suman al turno y el arqueo los espera
        $t = $this->caja->abrir($this->ana, '10.000')['id'];
        $this->assertSame([$t, $t], [(int) $this->fila('SELECT turno_id FROM caja WHERE subdiario_id = ?', [$a['id']])['turno_id'], (int) $this->fila('SELECT turno_id FROM caja WHERE subdiario_id = ?', [$b['id']])['turno_id']]);
        $res = $this->caja->resumenTurno($t);
        $this->assertSame([10000.0, $sin['efectivo'], Calc::r2(10000 + $sin['efectivo'])], [$res['fondo'], $res['efectivo'], $res['esperado']]);
        $this->assertSame(0, $this->caja->sinTurno($this->ana, date('Y-m-d'))['cantidad']);
        $this->assertStringContainsString('2 cobro(s)', $this->fila("SELECT detalle FROM caja_auditoria WHERE accion = 'asignacion' ORDER BY id DESC LIMIT 1")['detalle']);
        // un cobro que haya quedado sin turno con la caja ya abierta (datos de antes del arreglo) se suma con «Sumar a mi turno»
        $c = $this->emitir($this->pedido(['forma_pago' => '1', 'fpago' => 'EF'] + $pr));
        Db::pdo()->prepare('UPDATE caja SET turno_id = NULL WHERE subdiario_id = ?')->execute([$c['id']]);
        $r = $this->caja->sumarSinTurno($this->ana);
        $this->assertSame([true, 1], [$r['ok'], $r['cantidad']]);
        $this->assertSame($t, (int) $this->fila('SELECT turno_id FROM caja WHERE subdiario_id = ?', [$c['id']])['turno_id']);
        $this->assertFalse($this->caja->sumarSinTurno($this->beto)['ok'], 'sin turno abierto no hay a dónde sumar');
    }

    public function testSoloElCajeroOQuienAuditaCierraUnTurno(): void
    {
        $t = $this->caja->abrir($this->ana, '0')['id'];
        $otro = $this->crearUsuario('t_caja_otro', 'operador');
        $this->assertFalse($this->caja->cerrar($t, $otro, false, '0', '')['ok']);
        $this->assertTrue($this->caja->cerrar($t, $this->beto, true, '0', '')['ok'], 'quien audita puede cerrarlo');
        $this->assertStringContainsString('cerrado por otro usuario', $this->fila("SELECT detalle FROM caja_auditoria WHERE accion = 'cierre' ORDER BY id DESC LIMIT 1")['detalle']);
    }

    public function testIngresosEgresosTopeYAnulacionConContraAsiento(): void
    {
        $this->assertStringContainsString('turno', $this->caja->mover($this->beto, 'I', '100', 'x')['error'], 'sin turno no se mueve dinero');
        $t = $this->caja->abrir($this->beto, '500')['id'];
        $this->assertFalse($this->caja->mover($this->beto, 'X', '100', 'x')['ok']);
        $this->assertFalse($this->caja->mover($this->beto, 'I', '0', 'x')['ok']);
        $this->assertFalse($this->caja->mover($this->beto, 'I', '100', ' ')['ok'], 'el motivo es obligatorio');
        $i = $this->caja->mover($this->beto, 'I', '1.000', 'Cambio para el cajón');
        $e = $this->caja->mover($this->beto, 'E', '250,50', 'Pago de flete');
        $this->assertTrue($i['ok'] && $e['ok']);
        $this->assertSame([500.0, 1000.0, 250.5, 1249.5], array_values(array_intersect_key($this->caja->resumenTurno($t), array_flip(['fondo', 'ingresos', 'egresos', 'esperado']))) );
        // tope: lo que lo supera se rechaza y queda anotado
        Db::pdo()->exec('UPDATE parametros SET caja_tope = 5000 WHERE id = 1');
        $r = $this->caja->mover($this->beto, 'I', '5.000,01', 'Demasiado');
        $this->assertFalse($r['ok']);
        $this->assertStringContainsString('tope', $r['error']);
        $this->assertTrue($this->caja->mover($this->beto, 'I', '5000', 'Justo el tope')['ok']);
        $this->assertSame(1, (int) $this->fila("SELECT COUNT(*) AS n FROM caja_auditoria WHERE accion = 'rechazo'")['n']);
        Db::pdo()->exec('UPDATE parametros SET caja_tope = 0 WHERE id = 1');
        $this->assertTrue($this->caja->mover($this->beto, 'E', '99999999', 'Sin tope')['ok'], 'tope 0 = sin tope');
        // anulación: contra-asiento, una sola vez, y el contra-asiento no se anula
        $this->assertFalse($this->caja->anular($this->beto, $e['id'], '')['ok'], 'pide motivo');
        $this->assertTrue($this->caja->anular($this->beto, $e['id'], 'Se cargó dos veces')['ok']);
        $this->assertFalse($this->caja->anular($this->beto, $e['id'], 'otra vez')['ok']);
        $contra = $this->fila('SELECT * FROM caja_movimientos WHERE anula_id = ?', [$e['id']]);
        $this->assertSame(['I', '250.50'], [$contra['tipo'], $contra['importe']], 'el contra-asiento es del tipo contrario');
        $this->assertFalse($this->caja->anular($this->beto, (int) $contra['id'], 'x')['ok'], 'un contra-asiento no se anula');
        $this->assertSame(1, (int) $this->fila('SELECT COUNT(*) AS n FROM caja_movimientos WHERE id = ?', [$e['id']])['n'], 'el original sigue ahí');
    }

    public function testAuditoriaEncadenadaDetectaCualquierCambio(): void
    {
        $this->caja->abrir($this->beto, '0');
        $this->caja->mover($this->beto, 'I', '10', 'Uno');
        $this->caja->mover($this->beto, 'I', '20', 'Dos');
        $v = $this->caja->verificar();
        $this->assertTrue($v['ok']);
        $this->assertSame(3, $v['total']);
        // alguien cambia el detalle de la segunda acción en la base
        $fila = $this->fila("SELECT id, detalle FROM caja_auditoria WHERE accion = 'ingreso' ORDER BY id LIMIT 1");
        $id = (int) $fila['id'];
        Db::pdo()->prepare("UPDATE caja_auditoria SET detalle = 'Otra cosa' WHERE id = ?")->execute([$id]);
        $v = $this->caja->verificar();
        $this->assertFalse($v['ok']);
        $this->assertSame($id, $v['primera_mala']);
        // y borrar una fila del medio también rompe la cadena
        Db::pdo()->prepare('UPDATE caja_auditoria SET detalle = ? WHERE id = ?')->execute([$fila['detalle'], $id]);
        $this->assertTrue($this->caja->verificar()['ok']);
        Db::pdo()->prepare('DELETE FROM caja_auditoria WHERE id = ?')->execute([$id]);
        $this->assertFalse($this->caja->verificar()['ok']);
    }

    public function testListadoConHoraYUsuarioYCajaDiariaConManuales(): void
    {
        $this->loginComo($this->beto);
        $this->caja->abrir($this->beto, '0');
        $this->emitir($this->pedido(['forma_pago' => '1', 'fpago' => 'EF']));
        $this->caja->mover($this->beto, 'E', '42', 'Retiro');
        $m = $this->caja->movimientos(['desde' => date('Y-m-d'), 'hasta' => date('Y-m-d')], 1, 50);
        $this->assertSame(2, $m['total']);
        $por = [];
        foreach ($m['filas'] as $f) {
            $por[$f['origen']] = $f;
        }
        $this->assertSame('t_caja_beto', $this->usuarioDe($por['venta']['usuario']));
        $this->assertSame(-42.0, (float) $por['manual']['importe']);
        $this->assertNotNull($por['venta']['cuando']);
        $this->assertSame(1, $this->caja->movimientos(['desde' => date('Y-m-d'), 'origen' => 'manual'], 1, 50)['total']);
        $this->assertSame(0, $this->caja->movimientos(['desde' => date('Y-m-d'), 'usuario' => (string) $this->ana], 1, 50)['total']);
        $res = \App\Modules\Listados\CajaDiaria::resumen([]);
        $this->assertSame([242.0, 0.0, 42.0], [$res['efectivo']['neto'], $res['efectivo']['ingresos'], $res['efectivo']['egresos']]);
        $this->assertSame(200.0, $res['efectivo']['en_caja']);
        $cd = \App\Modules\Listados\CajaDiaria::movimientos([], 1, 50);
        $this->assertSame('t_caja_beto', $this->usuarioDe($cd['filas'][0]['usuario']));
    }

    public function testLosIngresosYEgresosNoSeMezclanConLasVentas(): void
    {
        $this->loginComo($this->beto);
        $this->caja->abrir($this->beto, '500');                                     // apertura: fondo de 500 en el cajón
        $this->emitir($this->pedido(['forma_pago' => '1', 'fpago' => 'EF']));      // venta en efectivo: 242
        $this->caja->mover($this->beto, 'I', '1000', 'Cambio para el cajón');      // no es una venta
        $this->caja->mover($this->beto, 'E', '300', 'Pago de flete');              // no es una venta
        $hoy = date('d/m/Y');
        $r = \App\Modules\Listados\CajaDiaria::resumen(['desde' => $hoy, 'hasta' => $hoy]);
        $this->assertSame(242.0, $r['total_cobrado'], 'lo cobrado son solo ventas y cobranzas');
        $this->assertSame(242.0, $r['ventas']['ventas_netas'], 'lo vendido no cambia con ingresos ni egresos');
        $this->assertSame([500.0, 242.0, 1000.0, 300.0, 1442.0], [$r['efectivo']['fondo'], $r['efectivo']['neto'], $r['efectivo']['ingresos'], $r['efectivo']['egresos'], $r['efectivo']['en_caja']], 'el efectivo en caja incluye el fondo de apertura');
        $cobros = \App\Modules\Listados\CajaDiaria::movimientos(['desde' => $hoy, 'hasta' => $hoy], 1, 50);
        $this->assertSame(1, $cobros['total'], 'el listado de cobros no trae ingresos ni egresos');
        $pag = $this->get('/listados/caja-diaria', ['desde' => $hoy, 'hasta' => $hoy])->body;
        $this->assertStringContainsString('Efectivo total en caja', $pag);
        $this->assertStringContainsString('no son ventas', $pag);
        $this->assertStringContainsString('Cambio para el cajón', $pag);
        $this->assertStringContainsString('Pago de flete', $pag);
        $this->assertStringContainsString('Apertura de caja', $pag, 'la apertura aparece en el listado');
        $this->assertStringContainsString('Fondo de apertura de caja', $pag);
        // en la pantalla de caja cada cosa tiene su concepto y va en Entra o en Sale
        $aud = $this->get('/caja/auditoria', ['desde' => $hoy, 'hasta' => $hoy])->body;
        $this->assertStringContainsString('Venta', $aud);
        $this->assertStringContainsString('Ingreso de dinero', $aud);
        $this->assertStringContainsString('Egreso de dinero', $aud);
        $this->assertStringContainsString('<th class="num">Sale</th>', $aud);
    }

    private function usuarioDe(?string $nombre): string
    {
        return str_replace('Nombre ', '', (string) $nombre);
    }

    public function testPantallasPermisosYFlujoPorHttp(): void
    {
        // Operador: tiene su turno (caja.turno) pero no mueve dinero ni audita
        $this->loginComo($this->ana);
        $this->assertSame(200, $this->get('/caja')->status);
        $this->assertSame(200, $this->get('/caja/turnos')->status);
        $this->assertSame(403, $this->get('/caja/auditoria')->status);
        $this->assertSame(403, $this->post('/caja/mover', ['tipo' => 'I', 'importe' => '10', 'motivo' => 'x'])->status);
        $this->assertSame(403, $this->post('/caja/anular', ['movimiento' => '1', 'motivo' => 'x'])->status);
        $this->assertStringNotContainsString('Ingresar o sacar dinero', $this->get('/caja')->body);
        $this->assertSame(302, $this->post('/caja/abrir', ['fondo' => '100'])->status);
        $this->assertStringContainsString('Cerrar mi turno', $this->get('/caja')->body);
        $this->assertSame(422, $this->post('/caja/cerrar', ['turno' => (string) $this->caja->turnoAbierto($this->ana)['id'], 'contado' => '90', 'observaciones' => ''])->status, 'diferencia sin observación');
        $this->assertSame(302, $this->post('/caja/cerrar', ['turno' => (string) $this->caja->turnoAbierto($this->ana)['id'], 'contado' => '90', 'observaciones' => 'Faltan 10'])->status);
        // Administrador: mueve dinero, anula y audita
        $this->loginComo($this->beto);
        $this->post('/caja/abrir', ['fondo' => '0']);
        $this->assertStringContainsString('Ingresar o sacar dinero', $this->get('/caja')->body);
        $this->assertSame(302, $this->post('/caja/mover', ['tipo' => 'I', 'importe' => '500', 'motivo' => 'Cambio'])->status);
        $this->assertStringContainsString('Ingreso de dinero', $this->get('/caja')->body);
        $this->assertStringContainsString('Cambio', $this->get('/caja')->body);
        $mov = (int) $this->fila('SELECT id FROM caja_movimientos ORDER BY id DESC LIMIT 1')['id'];
        $this->assertSame(302, $this->post('/caja/anular', ['movimiento' => (string) $mov, 'motivo' => 'Error'])->status);
        $aud = $this->get('/caja/auditoria', ['desde' => date('d/m/Y')]);
        $this->assertSame(200, $aud->status);
        $this->assertStringContainsString('Registro de acciones íntegro', $aud->body);
        $this->assertStringContainsString('anulacion', $aud->body);
        $this->assertStringContainsString('t_caja_beto', $aud->body);
        $this->assertStringContainsString('Faltan 10', $this->get('/caja/turnos')->body);
        // el cajero solo ve sus turnos; el auditor, todos
        $this->loginComo($this->ana);
        $this->assertStringNotContainsString('Nombre t_caja_beto', $this->get('/caja/turnos')->body);
        $this->loginComo($this->beto);
        $this->assertStringContainsString('Nombre t_caja_ana', $this->get('/caja/turnos')->body);
        // sin sesión, al login
        \App\Core\Session::clear();
        $this->assertSame(302, $this->get('/caja')->status);
    }

    public function testElParametroDeTopeSeGuardaEnParametrosYElPermisoViveEnRoles(): void
    {
        $this->assertSame('10000000.00', (string) Db::pdo()->query('SELECT caja_tope FROM parametros WHERE id = 1')->fetchColumn(), 'tope por omisión: 10 millones');
        $q = Db::pdo()->query("SELECT r.codigo, p.codigo AS permiso FROM rol_permisos rp JOIN roles r ON r.id = rp.rol_id JOIN permisos p ON p.id = rp.permiso_id WHERE p.codigo LIKE 'caja.%'")->fetchAll();
        $tiene = [];
        foreach ($q as $f) {
            $tiene[$f['codigo']][] = $f['permiso'];
        }
        $this->assertContains('caja.mover', $tiene['admin']);
        $this->assertContains('caja.auditar', $tiene['master']);
        $this->assertSame(['caja.turno'], $tiene['operador'], 'el Operador solo maneja su turno');
        $this->assertArrayHasKeyCompat('caja', \App\Modules\Roles\RolesService::MENU);
    }

    private function assertArrayHasKeyCompat(string $k, array $a): void
    {
        $this->assertTrue(isset($a[$k]), 'Roles tiene la opción ' . $k);
    }
}
