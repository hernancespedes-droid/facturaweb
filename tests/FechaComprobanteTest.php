<?php
declare(strict_types=1);

namespace Tests;

use App\App;
use App\Core\Config;
use App\Core\Db;
use App\Modules\Arca\Arca;
use App\Modules\Comprobantes\ComprobantesService;

/** Facturas y notas con fecha distinta a la de hoy, dentro de lo que acepta ARCA (D-149), contra el ARCA simulado. */
final class FechaComprobanteTest extends ComprobantesTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $cfg = require App::root() . '/config/config.php';
        $cfg['afip'] = ['modo' => 'simulado'];
        Config::load($cfg);
        Arca::reiniciar();
        Db::pdo()->exec('DELETE FROM afip_operaciones');
        Db::pdo()->exec('UPDATE sucursales SET fac_electronica = 1 WHERE codigo = 99');
    }

    protected function tearDown(): void
    {
        Db::pdo()->exec('DELETE FROM afip_operaciones');
        Config::load(require App::root() . '/config/config.php');
        Arca::reiniciar();
        parent::tearDown();
    }

    private static function dia(int $n): string
    {
        return date('Y-m-d', strtotime(($n >= 0 ? '+' : '') . $n . ' days'));
    }

    public function testLimitesDeArca(): void
    {
        $this->assertNull(ComprobantesService::problemaFecha(self::dia(-5), 1));
        $this->assertNull(ComprobantesService::problemaFecha(self::dia(5), 1));
        $this->assertNotNull(ComprobantesService::problemaFecha(self::dia(-6), 1));
        $this->assertNotNull(ComprobantesService::problemaFecha(self::dia(6), 1));
        $this->assertNull(ComprobantesService::problemaFecha(self::dia(10), 2));
        $this->assertNull(ComprobantesService::problemaFecha(self::dia(-10), 3));
        $this->assertNotNull(ComprobantesService::problemaFecha(self::dia(11), 2));
    }

    public function testFacturaConFechaAdelantadaSeApruebaConEsaFecha(): void
    {
        $f = self::dia(3);
        $e = $this->emitir($this->pedido(['fecha' => $f]));
        $h = $this->svc->obtener($e['id']);
        $this->assertSame($f, substr((string) $h['fecha'], 0, 10));
        $this->assertSame($f, substr((string) $h['fe_dfecha'], 0, 10), 'ARCA la aprobó con esa fecha');
        $this->assertTrue((string) $h['fe_ccae'] !== '', 'tiene CAE');
        $this->assertSame($f, substr((string) $this->fila('SELECT fecha FROM ctact WHERE subdiario_id = ?', [$e['id']])['fecha'], 0, 10));
    }

    public function testFueraDeRangoNoSeArma(): void
    {
        $r = $this->svc->armar($this->pedido(['fecha' => self::dia(6)]));
        $this->assertStringContainsString('5 días', $r['errores']['fecha'] ?? '');
        $r = $this->svc->armar($this->pedido(['fecha' => self::dia(-6)]));
        $this->assertTrue(isset($r['errores']['fecha']));
        $r = $this->svc->armar($this->pedido(['fecha' => '31/02/2026']));
        $this->assertTrue(isset($r['errores']['fecha']));
    }

    public function testServiciosHastaDiezDiasYVencimientoNoAnterior(): void
    {
        $f = self::dia(8);
        $r = $this->svc->armar($this->pedido(['fecha' => $f, 'concepto' => '2']));
        $this->assertSame([], $r['errores'], 'servicios: 8 días está bien');
        $this->assertSame($f, $r['cbte']['vto_pago'], 'el vencimiento arranca en la fecha de la factura');
        $r = $this->svc->armar($this->pedido(['fecha' => $f, 'concepto' => '2', 'vto_pago' => self::dia(1)]));
        $this->assertTrue(isset($r['errores']['vto_pago']));
        $r = $this->svc->armar($this->pedido(['fecha' => $f, 'concepto' => '1']));
        $this->assertTrue(isset($r['errores']['fecha']), 'productos: 8 días no');
    }

    public function testCorrelatividadPorTipoYPuntoDeVenta(): void
    {
        $this->emitir($this->pedido(['fecha' => self::dia(2)]));
        $r = $this->svc->armar($this->pedido());
        $this->assertStringContainsString('fecha anterior al último', $r['errores']['fecha'] ?? '', 'hoy ya no se puede: hay una A con fecha adelantada');
        // otro tipo (B) en el mismo punto de venta sí
        $r = $this->svc->armar($this->pedido(['cliente' => '990002']));
        $this->assertFalse(isset($r['errores']['fecha']));
        $this->assertFalse(isset($this->svc->armar($this->pedido(['fecha' => self::dia(2)]))['errores']['fecha']), 'con la misma fecha sí');
    }

    public function testNotaNoAnteriorALaFactura(): void
    {
        $fc = $this->emitir($this->pedido(['fecha' => self::dia(1)]));
        $r = $this->svc->armar(['tipo' => 'NC', 'asociado' => (string) $fc['id'], 'forma_pago' => '2', 'fecha' => date('Y-m-d')] + $this->pedido());
        $this->assertStringContainsString('anterior al comprobante que corrige', $r['errores']['fecha'] ?? '');
    }

    public function testPresupuestoSiempreHoy(): void
    {
        $r = $this->svc->armar($this->pedido(['tipo' => 'PR', 'fecha' => self::dia(3)]));
        $this->assertSame(date('Y-m-d'), $r['cbte']['fecha'] ?? null);
    }

    public function testCobroDeFechaAnteriorEntraALaCajaDeHoy(): void
    {
        $e = $this->emitir($this->pedido(['fecha' => self::dia(-2), 'cliente' => '990002', 'forma_pago' => '1', 'fpago' => 'EF']));
        $this->assertSame(date('Y-m-d'), substr((string) $this->fila('SELECT fecha FROM caja WHERE subdiario_id = ?', [$e['id']])['fecha'], 0, 10), 'D-150');
    }

    public function testSimuladorRechazaComoArca(): void
    {
        $r = $this->svc->armar($this->pedido(['fecha' => self::dia(1)]) + ['autoriza_yo' => '1'], false, $this->supervisorId());
        $this->assertSame([], $r['errores']);
        $cbte = $r['cbte'];
        $cbte['fecha'] = self::dia(9); // salteando la validación de la pantalla
        try {
            $this->svc->emitir($cbte, bin2hex(random_bytes(16)), 0);
            $this->assertTrue(false, 'el simulador tenía que rechazarlo');
        } catch (\App\Modules\Arca\AutorizacionRechazada $e) {
            $this->assertStringContainsString('5 días', $e->getMessage());
        }
    }
}
