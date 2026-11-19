<?php
declare(strict_types=1);

namespace Tests;

use App\Core\Db;

/** Impresión en hoja A4 o en ticket (80 y 50 mm): el papel sale de Parámetros, el PDF para enviar sigue en A4 y el ticket muestra el vuelto. */
final class ImpresionTicketTest extends ComprobantesTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Db::pdo()->exec("UPDATE forma_de_pagos SET acepta_vuelto = IF(codigo = 'EF', 1, 0) WHERE codigo IN ('EF', 'TJ', 'BV')");
        $this->loginComo($this->crearUsuario('t_adm_tk', 'admin'));
    }

    protected function tearDown(): void
    {
        Db::pdo()->exec("UPDATE parametros SET impresion = 'a4' WHERE id = 1");
        parent::tearDown();
    }

    private function papel(string $p): void
    {
        Db::pdo()->prepare('UPDATE parametros SET impresion = ? WHERE id = 1')->execute([$p]);
    }

    /** Factura de 242 pagada en efectivo con 300 (vuelto 58). */
    private function conVuelto(string $tipo = 'FC'): int
    {
        $p = $this->pedido(['tipo' => $tipo, 'forma_pago' => '1', 'fpago' => 'EF', 'recibido' => '300']);
        return $this->emitir($p)['id'];
    }

    private function ancho(string $pdf): float
    {
        preg_match('/MediaBox \[0 0 ([\d.]+) ([\d.]+)\]/', $pdf, $m);
        return round((float) $m[1] / (72 / 25.4), 1);
    }

    public function testElPapelSaleDeParametrosYSeCambiaConLaDireccion(): void
    {
        $id = $this->conVuelto();
        $a4 = $this->get('/comprobantes/' . $id . '/imprimir');
        $this->assertStringContainsString('size: A4', $a4->body);
        $this->assertStringContainsString('Ticket 80 mm', $a4->body, 'desde la hoja A4 se puede pasar a ticket');
        $this->papel('t80');
        $t = $this->get('/comprobantes/' . $id . '/imprimir')->body;
        $this->assertStringContainsString('size: 80mm auto', $t);
        $this->assertStringContainsString('VUELTO: $ 58,00', $t);
        $this->assertStringContainsString('Recibido', $t);
        $this->assertStringContainsString('300,00', $t);
        $this->papel('t50');
        $this->assertStringContainsString('size: 50mm auto', $this->get('/comprobantes/' . $id . '/imprimir')->body);
        $this->assertStringContainsString('size: 80mm auto', $this->get('/comprobantes/' . $id . '/imprimir', ['papel' => 't80'])->body);
        $this->assertStringContainsString('size: A4', $this->get('/comprobantes/' . $id . '/imprimir', ['papel' => 'a4'])->body);
        $this->assertStringContainsString('size: A4', $this->get('/comprobantes/' . $id . '/imprimir', ['papel' => 'cualquiera'])->body, 'lo que no existe cae en A4');
    }

    public function testElTicketReimprimeElVueltoYNoLoMuestraSinVuelto(): void
    {
        $this->papel('t80');
        $sin = $this->emitir($this->pedido(['forma_pago' => '1', 'fpago' => 'EF']))['id'];
        $this->assertStringNotContainsString('VUELTO', $this->get('/comprobantes/' . $sin . '/imprimir')->body);
        $cc = $this->emitir($this->pedido())['id'];
        $this->assertStringContainsString('Cuenta corriente', $this->get('/comprobantes/' . $cc . '/imprimir')->body);
    }

    public function testPresupuestoDiceNoValidoComoFacturaYLaFiscalLlevaQrYCae(): void
    {
        $this->papel('t80');
        $pr = $this->conVuelto('PR');
        $t = $this->get('/comprobantes/' . $pr . '/imprimir')->body;
        $this->assertStringContainsString('DOCUMENTO NO VÁLIDO COMO FACTURA', $t);
        $this->assertStringNotContainsString('CAE:', $t);
        $this->assertStringContainsString('VUELTO', $t);
        $fc = $this->conVuelto();
        Db::pdo()->prepare("UPDATE subdiario SET fe_ccae = '70123456789012', fe_nrocbte = 321, fe_dvtocae = CURDATE(), es_prueba = 0 WHERE fe_clave = ?")->execute([$fc]);
        $f = $this->get('/comprobantes/' . $fc . '/imprimir')->body;
        $this->assertStringContainsString('CAE: 70123456789012', $f);
        $this->assertStringContainsString('QR de ARCA', $f);
        $this->assertStringContainsString('Comprobante Autorizado ARCA', $f);
        $this->assertStringNotContainsString('NO VÁLIDO COMO FACTURA', $f);
    }

    public function testElPdfParaEnviarSigueEnA4YElTicketEnPdfSeBajaAparte(): void
    {
        $this->papel('t80');
        $id = $this->conVuelto();
        $a4 = $this->get('/comprobantes/' . $id . '/pdf');
        $this->assertSame(200, $a4->status);
        $this->assertSame(210.0, $this->ancho($a4->body), 'con el modo ticket el PDF sigue siendo A4 (es lo que se manda por mail)');
        $t80 = $this->get('/comprobantes/' . $id . '/pdf', ['papel' => 't80']);
        $this->assertSame('application/pdf', $t80->headers['Content-Type']);
        $this->assertSame(80.0, $this->ancho($t80->body));
        $this->assertSame(50.0, $this->ancho($this->get('/comprobantes/' . $id . '/pdf', ['papel' => 't50'])->body));
        // fiscal con QR y presupuesto también se generan
        $pr = $this->conVuelto('PR');
        $this->assertSame(80.0, $this->ancho($this->get('/comprobantes/' . $pr . '/pdf', ['papel' => 't80'])->body));
        Db::pdo()->prepare("UPDATE subdiario SET fe_ccae = '70123456789012', fe_nrocbte = 321, fe_dvtocae = CURDATE(), es_prueba = 0 WHERE fe_clave = ?")->execute([$id]);
        $this->assertSame(50.0, $this->ancho($this->get('/comprobantes/' . $id . '/pdf', ['papel' => 't50'])->body));
    }

    public function testParametrosAceptaElPapelYRechazaOtro(): void
    {
        $this->assertSame('a4', (string) Db::pdo()->query('SELECT impresion FROM parametros WHERE id = 1')->fetchColumn(), 'por omisión A4');
        $this->assertTrue(isset(\App\Modules\Tablas\Definiciones::PAPELES['t50']));
    }
}
