<?php
declare(strict_types=1);

namespace Tests;

/** Falla de una verificación. */
final class AssertionFailed extends \Exception
{
}

/**
 * Base mínima para las pruebas (sin PHPUnit, para no depender de Composer).
 * Cada método público cuyo nombre empieza con "test" es una prueba.
 * setUp() corre antes de cada prueba y tearDown() después, pase o falle.
 */
abstract class TestCase
{
    protected function setUp(): void
    {
    }

    protected function tearDown(): void
    {
    }

    final public function runTest(string $method): void
    {
        $this->setUp();
        try {
            $this->$method();
        } finally {
            $this->tearDown();
        }
    }

    protected function assertSame(mixed $expected, mixed $actual, string $message = ''): void
    {
        if ($expected !== $actual) {
            throw new AssertionFailed(
                ($message !== '' ? $message . ' - ' : '')
                . 'esperado ' . var_export($expected, true) . ', obtenido ' . var_export($actual, true)
            );
        }
    }

    protected function assertTrue(mixed $actual, string $message = ''): void
    {
        $this->assertSame(true, $actual, $message);
    }

    protected function assertFalse(mixed $actual, string $message = ''): void
    {
        $this->assertSame(false, $actual, $message);
    }

    /** @param array<mixed>|\Countable $items */
    protected function assertCount(int $expected, array|\Countable $items, string $message = ''): void
    {
        $this->assertSame($expected, count($items), $message);
    }

    protected function assertStringContainsString(string $needle, string $haystack, string $message = ''): void
    {
        if (!str_contains($haystack, $needle)) {
            throw new AssertionFailed(
                ($message !== '' ? $message . ' - ' : '')
                . 'no se encontró ' . var_export($needle, true) . ' en ' . var_export(mb_substr($haystack, 0, 300), true)
            );
        }
    }

    protected function assertStringNotContainsString(string $needle, string $haystack, string $message = ''): void
    {
        if (str_contains($haystack, $needle)) {
            throw new AssertionFailed(($message !== '' ? $message . ' - ' : '') . 'no tendría que aparecer ' . var_export($needle, true));
        }
    }

    /** @param array<mixed> $lista */
    protected function assertContains(mixed $valor, array $lista, string $message = ''): void
    {
        $this->assertTrue(in_array($valor, $lista, true), ($message !== '' ? $message . ' - ' : '') . 'falta ' . var_export($valor, true));
    }

    /** @param array<mixed> $lista */
    protected function assertNotContains(mixed $valor, array $lista, string $message = ''): void
    {
        $this->assertFalse(in_array($valor, $lista, true), ($message !== '' ? $message . ' - ' : '') . 'sobra ' . var_export($valor, true));
    }

    protected function assertNull(mixed $actual, string $message = ''): void
    {
        $this->assertSame(null, $actual, $message);
    }

    protected function assertNotNull(mixed $actual, string $message = ''): void
    {
        if ($actual === null) {
            throw new AssertionFailed(($message !== '' ? $message . ' - ' : '') . 'se esperaba un valor y vino null');
        }
    }

    protected function assertStringStartsWith(string $prefix, string $string, string $message = ''): void
    {
        if (!str_starts_with($string, $prefix)) {
            throw new AssertionFailed(
                ($message !== '' ? $message . ' - ' : '')
                . 'no empieza con ' . var_export($prefix, true) . ': ' . var_export(mb_substr($string, 0, 100), true)
            );
        }
    }

    protected function fail(string $message = ''): never
    {
        throw new AssertionFailed($message !== '' ? $message : 'Falla forzada');
    }
}
