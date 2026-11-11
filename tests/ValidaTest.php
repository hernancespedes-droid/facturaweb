<?php
declare(strict_types=1);

namespace Tests;

use App\Core\Valida;

final class ValidaTest extends TestCase
{
    public function testCuit(): void
    {
        $this->assertTrue(Valida::cuit('30-71435753-7'));
        $this->assertTrue(Valida::cuit('20 11499319 1'));
        $this->assertFalse(Valida::cuit('30-71435753-8'), 'dígito verificador');
        $this->assertFalse(Valida::cuit('3071435753'), '10 dígitos');
    }

    public function testDecimalConComaYPuntos(): void
    {
        $this->assertSame(1234.56, Valida::decimal('1.234,56'));
        $this->assertSame(1234.56, Valida::decimal('1234.56'));
        $this->assertSame(1234.5, Valida::decimal('$ 1234,5'));
        $this->assertSame(1234567.0, Valida::decimal('1.234.567'));
        $this->assertSame(1000.0, Valida::decimal('1.000'), 'la máscara de los importes agrega puntos de miles');
        $this->assertSame(8500.0, Valida::decimal('8.500'));
        $this->assertSame(12.5, Valida::decimal('12.5'));
        $this->assertSame(0.5, Valida::decimal('0.500'), 'con cero adelante el punto es decimal');
        $this->assertSame(1234.5678, Valida::decimal('1.234,5678'));
        $this->assertSame(12.3456, Valida::decimal('12,3456'));
        $this->assertNull(Valida::decimal('12a'));
        $this->assertNull(Valida::decimal(''));
    }

    public function testFecha(): void
    {
        $this->assertSame('2026-11-11', Valida::fecha('2026-11-11'));
        $this->assertSame('2026-02-01', Valida::fecha('1/2/2026'));
        $this->assertNull(Valida::fecha('31/02/2026'));
        $this->assertNull(Valida::fecha('ayer'));
    }

    public function testMails(): void
    {
        $this->assertSame('a@x.com; b@y.com', Valida::mails(' A@x.com, b@y.com ;'));
        $this->assertSame('', Valida::mails(''));
        $this->assertNull(Valida::mails('a@x.com; nada'));
    }
}
