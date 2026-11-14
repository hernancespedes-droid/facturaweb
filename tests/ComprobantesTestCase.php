<?php
declare(strict_types=1);

namespace Tests;

use App\Core\Db;
use App\Modules\Comprobantes\ComprobantesService;

/**
 * Base de las pruebas de facturación: una empresa Responsable Inscripto con una sucursal (punto de venta 9), un cliente
 * Responsable Inscripto, uno Consumidor Final y un artículo. Todo lo que cargan estas pruebas se borra al terminar
 * (la base *_test es solo de pruebas).
 */
abstract class ComprobantesTestCase extends DbTestCase
{
    protected ComprobantesService $svc;
    /** @var array<string,mixed> */
    private array $empresaOriginal = [];

    protected function setUp(): void
    {
        parent::setUp();
        $pdo = Db::pdo();
        $this->limpiarFacturacion();
        $this->empresaOriginal = $pdo->query('SELECT empresa, cuit, iva, ultimo_cliente FROM parametros WHERE id = 1')->fetch() ?: [];
        $pdo->exec("UPDATE parametros SET empresa = 'EMPRESA DE PRUEBA SA', cuit = 30714357537, iva = 1 WHERE id = 1");
        $pdo->exec("INSERT INTO sucursales (codigo, descripcion, prefijo) VALUES (99, 'SUCURSAL PRUEBA', 9)");
        $pdo->exec("INSERT INTO clientes (codigo, razon_social, domicilio, localidad, iva, cuit, forma_pago, vendedor) VALUES
            (990001, 'T CLIENTE RI SA', 'CALLE 1', 'CABA', 1, 30708805587, 2, NULL),
            (990002, 'T CONSUMIDOR FINAL', 'CALLE 2', 'CABA', 3, NULL, 1, NULL),
            (990003, 'T MONOTRIBUTISTA', NULL, NULL, 7, 20111111112, 2, NULL)");
        $pdo->exec("INSERT INTO articulos (codigo, descripcion, alicuota, precio_unitario) VALUES ('T-ART', 'ARTICULO DE PRUEBA', '21', 100)");
        $pdo->exec("INSERT IGNORE INTO forma_de_pagos (codigo, descripcion, tipo) VALUES ('EF', 'EFECTIVO', 1), ('CH', 'CHEQUE', 5), ('CC', 'CUENTA CORRIENTE', 6)");
        $this->svc = new ComprobantesService();
    }

    protected function tearDown(): void
    {
        $this->limpiarFacturacion();
        $pdo = Db::pdo();
        $pdo->exec("DELETE FROM sucursales WHERE codigo = 99");
        $pdo->exec("DELETE FROM clientes WHERE codigo BETWEEN 990001 AND 990099");
        $pdo->exec("DELETE FROM articulos WHERE codigo LIKE 'T-%'");
        $pdo->exec("DELETE FROM bancos WHERE codigo = 990");
        if ($this->empresaOriginal !== []) {
            $pdo->prepare('UPDATE parametros SET empresa = ?, cuit = ?, iva = ?, ultimo_cliente = ? WHERE id = 1')
                ->execute([$this->empresaOriginal['empresa'], $this->empresaOriginal['cuit'], $this->empresaOriginal['iva'], $this->empresaOriginal['ultimo_cliente']]);
        }
        parent::tearDown();
    }

    private function limpiarFacturacion(): void
    {
        foreach (['subdiario', 'iva', 'estadistica', 'caja', 'ctact', 'cobra', 'imputaciones'] as $t) {
            Db::pdo()->exec("DELETE FROM `{$t}`");
        }
    }

    /** @return array<string,mixed> */
    protected function pedido(array $extra = []): array
    {
        return $extra + [
            'tipo' => 'FC', 'cliente' => '990001', 'sucursal' => '99', 'forma_pago' => '2', 'descuento' => '',
            'items' => [['articulo' => 'T-ART', 'descripcion' => '', 'cantidad' => '2', 'precio' => '100', 'alicuota' => '21']],
        ];
    }

    /** Valida y emite; falla la prueba si hay errores. @return array{id:int,numero:int,repetido:bool} */
    protected function emitir(array $pedido, ?string $operacion = null, bool $credito = false): array
    {
        $r = $this->svc->armar($pedido, $credito);
        $this->assertSame([], $r['errores'], 'sin errores de validación');
        return $this->svc->emitir($r['cbte'], $operacion ?? bin2hex(random_bytes(16)), 0);
    }

    /** @return array<string,mixed> */
    protected function fila(string $sql, array $params = []): array
    {
        $st = Db::pdo()->prepare($sql);
        $st->execute($params);
        return $st->fetch() ?: [];
    }
}
