<?php
declare(strict_types=1);

namespace Tests;

use App\Core\Db;

/** Pantallas de cuenta corriente: qué puede y qué NO puede cada rol, y el recorrido de un recibo por HTTP. */
final class CuentaCorrienteHttpTest extends ComprobantesTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Db::pdo()->exec("INSERT IGNORE INTO bancos (codigo, descripcion) VALUES (990, 'BANCO DE PRUEBA')");
    }

    private function ctact(): int
    {
        $fc = $this->emitir($this->pedido());
        return (int) $this->fila('SELECT fe_clave FROM ctact WHERE subdiario_id = ?', [$fc['id']])['fe_clave'];
    }

    public function testElOperadorConsultaYCobraPeroNoImputaNiAjustaNiAnula(): void
    {
        $ct = $this->ctact();
        $this->loginComo($this->crearUsuario('t_op', 'operador'));
        foreach (['/cuenta-corriente', '/cuenta-corriente/cliente/990001', '/recibos', '/recibos/nuevo', '/cheques'] as $ruta) {
            $this->assertSame(200, $this->get($ruta)->status, "operador entra a {$ruta}");
        }
        foreach (['/cuenta-corriente/imputar', '/cuenta-corriente/ajuste'] as $ruta) {
            $this->assertSame(403, $this->get($ruta)->status, "operador sin acceso a {$ruta}");
            $this->assertSame(403, $this->post($ruta, ['cliente' => '990001'])->status);
        }
        $r = $this->post('/recibos', ['cliente' => '990001', 'fecha' => date('d/m/Y'), 'sucursal' => '99', 'operacion' => bin2hex(random_bytes(16)),
            'imputar' => [$ct => '242'], 'pagos' => [['fpago' => 'EF', 'importe' => '242']]]);
        $id = (int) $this->fila('SELECT id FROM cobra')['id'];
        $this->assertRedirect('/recibos/' . $id, $r);
        $this->assertSame(403, $this->post('/recibos/' . $id . '/anular')->status, 'el operador no anula');
        $this->assertSame(403, $this->post('/cheques/1/estado', ['estado' => 'D'])->status, 'ni cambia cheques');
        $this->assertSame('', $this->fila('SELECT estado FROM cobra')['estado']);
    }

    public function testSinPermisoNoSeVeNada(): void
    {
        $this->loginComo($this->crearUsuario('t_sin', 'operador'));
        Db::pdo()->exec("DELETE rp FROM rol_permisos rp JOIN permisos p ON p.id = rp.permiso_id JOIN roles r ON r.id = rp.rol_id WHERE r.codigo = 'operador' AND p.modulo = 'ctacte'");
        \App\Modules\Auth\Auth::reset();
        try {
            foreach (['/cuenta-corriente', '/recibos', '/recibos/nuevo', '/cheques'] as $ruta) {
                $this->assertSame(403, $this->get($ruta)->status, "sin permiso: {$ruta}");
            }
            $this->assertSame(403, $this->post('/recibos', ['cliente' => '990001'])->status);
        } finally {
            Db::pdo()->exec("INSERT IGNORE INTO rol_permisos (rol_id, permiso_id) SELECT r.id, p.id FROM roles r JOIN permisos p ON p.codigo IN ('ctacte.ver', 'ctacte.cobrar') WHERE r.codigo = 'operador'");
        }
    }

    public function testRecorridoDelAdministrador(): void
    {
        $ct = $this->ctact();
        $this->loginComo($this->crearUsuario('t_adm', 'admin'));
        $this->assertStringContainsString('T CLIENTE RI SA', $this->get('/cuenta-corriente', ['q' => ''])->body);
        $this->assertStringContainsString('242,00', $this->get('/cuenta-corriente/cliente/990001')->body);
        $this->assertStringContainsString('990001', $this->get('/cuenta-corriente/buscar-cliente', ['q' => 'T CLIENTE'])->body);
        $this->assertRedirect('/cuenta-corriente/cliente/990001', $this->get('/cuenta-corriente/cliente', ['cliente' => '990001']));
        $form = $this->get('/recibos/nuevo', ['cliente' => '990001']);
        $this->assertStringContainsString('Qué se cancela', $form->body);
        $this->assertStringContainsString('name="imputar[' . $ct . ']"', $form->body);

        // el envío con error vuelve a mostrar el formulario con lo que se cargó
        $mal = $this->post('/recibos', ['cliente' => '990001', 'fecha' => date('d/m/Y'), 'sucursal' => '99', 'operacion' => bin2hex(random_bytes(16)),
            'imputar' => [$ct => '100'], 'pagos' => [['fpago' => 'EF', 'importe' => '90']]]);
        $this->assertSame(422, $mal->status);
        $this->assertStringContainsString('tienen que sumar', $mal->body);
        $this->assertSame(0, (int) $this->fila('SELECT COUNT(*) n FROM cobra')['n']);
        $this->assertTrue(in_array($this->post('/recibos', ['cliente' => '990001'], false)->status, [403, 419], true), 'sin CSRF no pasa');
        $this->assertSame(0, (int) $this->fila('SELECT COUNT(*) n FROM cobra')['n']);

        $op = bin2hex(random_bytes(16));
        $datos = ['cliente' => '990001', 'fecha' => date('d/m/Y'), 'sucursal' => '99', 'operacion' => $op, 'imputar' => [$ct => '100'], 'pagos' => [['fpago' => 'EF', 'importe' => '100']]];
        $this->post('/recibos', $datos);
        $this->post('/recibos', $datos); // doble clic
        $this->assertSame(1, (int) $this->fila('SELECT COUNT(*) n FROM cobra')['n'], 'el doble envío no duplica');
        $id = (int) $this->fila('SELECT id FROM cobra')['id'];
        $ver = $this->get('/recibos/' . $id);
        $this->assertStringContainsString('Recibo 0009-00000001', $ver->body);
        $this->assertStringContainsString('RECIBO X', $this->get('/recibos/' . $id . '/imprimir')->body);
        $this->assertStringContainsString('Recibo', $this->get('/recibos', ['desde' => '', 'hasta' => ''])->body);

        // imputación y ajuste por HTTP
        $aj = $this->post('/cuenta-corriente/ajuste', ['cliente' => '990001', 'tipo' => 'AC', 'fecha' => date('d/m/Y'), 'importe' => '42', 'sucursal' => '99', 'observaciones' => 'bonificación']);
        $this->assertRedirect('/cuenta-corriente/cliente/990001', $aj);
        $acId = (int) $this->fila("SELECT fe_clave FROM ctact WHERE tipo = 'AC'")['fe_clave'];
        $im = $this->post('/cuenta-corriente/imputar', ['cliente' => '990001', 'credito' => [$acId => '42'], 'debito' => [$ct => '42']]);
        $this->assertRedirect('/cuenta-corriente/cliente/990001', $im);
        $this->assertSame(100.0, (float) $this->fila('SELECT saldo FROM ctact WHERE fe_clave = ?', [$ct])['saldo']);
        $this->assertSame(422, $this->post('/cuenta-corriente/imputar', ['cliente' => '990001', 'credito' => [$acId => '1'], 'debito' => [$ct => '2']])->status);

        $anu = $this->post('/recibos/' . $id . '/anular');
        $this->assertRedirect('/recibos/' . $id, $anu);
        $this->assertSame('A', $this->fila('SELECT estado FROM cobra')['estado']);
    }

    public function testExcelDeDeudaCobranzasYCheques(): void
    {
        $this->ctact();
        $this->loginComo($this->crearUsuario('t_adm', 'admin'));
        foreach (['/cuenta-corriente', '/cuenta-corriente/cliente/990001', '/recibos', '/cheques'] as $ruta) {
            $r = $this->get($ruta, ['excel' => '1', 'desde' => '', 'hasta' => '']);
            $this->assertSame(200, $r->status, $ruta);
            $this->assertStringContainsString('spreadsheetml', $r->headers['Content-Type'] ?? '', $ruta);
        }
    }
}
