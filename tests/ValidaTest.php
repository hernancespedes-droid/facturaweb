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
