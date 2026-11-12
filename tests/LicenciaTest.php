<?php
declare(strict_types=1);

namespace Tests;

use App\Core\Config;
use App\Core\Db;
use App\Modules\Licencia\Licencia;
use App\Modules\Licencia\LicenciaVencida;

/** Licencia de la suscripción: firma, estados por fecha, CUIT, bloqueo de emisión, pantalla y permisos. */
final class LicenciaTest extends ComprobantesTestCase
{
    private array $configOriginal = [];
    private string $archivo = '';
    private string $privada = '';
    private string $publica = '';

    protected function setUp(): void
    {
        parent::setUp();
        $r = new \ReflectionProperty(Config::class, 'data');
        $this->configOriginal = $r->getValue();
        $par = sodium_crypto_sign_keypair();
        $this->privada = base64_encode(sodium_crypto_sign_secretkey($par));
        $this->publica = base64_encode(sodium_crypto_sign_publickey($par));
        $this->archivo = sys_get_temp_dir() . '/fw_lic_' . bin2hex(random_bytes(4)) . '.json';
        $this->configurar(true);
        Db::pdo()->exec("DELETE FROM usuarios WHERE usuario LIKE 't\\_lic%'");
    }

    protected function tearDown(): void
    {
        Config::load($this->configOriginal);
        @unlink($this->archivo);
        Db::pdo()->exec("DELETE FROM usuarios WHERE usuario LIKE 't\\_lic%'");
        parent::tearDown();
    }

    private function configurar(bool $exigir): void
    {
        Config::load(array_replace($this->configOriginal, ['licencia' => ['exigir' => $exigir, 'archivo' => $this->archivo, 'clave_publica' => $this->publica]]));
    }

    /** Emite una licencia (como la herramienta de O&S) y la deja guardada. */
    private function licencia(string $vence, string $cuit = '30714357537', ?string $clave = null): array
    {
        $l = Licencia::firmar(['cliente' => 'Cliente de prueba SA', 'cuit' => $cuit, 'vence' => $vence, 'emitida' => '2026-01-01'], $clave ?? $this->privada);
        file_put_contents($this->archivo, json_encode($l));
        return $l;
    }

    private function dia(string $f): \DateTimeImmutable
    {
        return new \DateTimeImmutable($f);
    }

    public function testFirmaValidaYNadaSePuedeModificar(): void
    {
        $l = $this->licencia('2026-12-31');
        $this->assertTrue(Licencia::firmaValida($l, $this->publica));
        $this->assertFalse(Licencia::firmaValida(['vence' => '2030-12-31'] + $l, $this->publica), 'alargar el vencimiento rompe la firma');
        $this->assertFalse(Licencia::firmaValida(['cuit' => '20111111112'] + $l, $this->publica), 'cambiar el CUIT rompe la firma');
        $otra = base64_encode(sodium_crypto_sign_secretkey(sodium_crypto_sign_keypair()));
        $this->assertFalse(Licencia::firmaValida(Licencia::firmar($l, $otra), $this->publica), 'firmada con otra clave');
        $this->assertFalse(Licencia::firmaValida($l, ''), 'sin clave pública');
    }

    public function testEstadosSegunLosDiasQueFaltan(): void
    {
        $this->licencia('2026-12-31');
        $caso = fn (string $hoy) => Licencia::estado($this->dia($hoy))['estado'];
        $this->assertSame('ok', $caso('2026-12-01'));
        $this->assertSame('ok', $caso('2026-12-20'), 'faltan 11 días');
        $this->assertSame('por_vencer', $caso('2026-12-21'), 'faltan 10 días');
        $this->assertSame('por_vencer', $caso('2026-12-31'), 'el día del vencimiento todavía vale');
        $this->assertSame('gracia', $caso('2027-01-01'));
        $this->assertSame('gracia', $caso('2027-01-05'));
        $this->assertSame('vencida', $caso('2027-01-06'));
        $this->assertSame(5, Licencia::estado($this->dia('2026-12-26'))['dias']);
    }

    public function testSinControlONoHayNadaQueExigir(): void
    {
        $this->configurar(false);
        $this->assertSame('sin_control', Licencia::estado()['estado']);
        Licencia::exigirParaEmitir(); // no tira
        $this->configurar(true);
        @unlink($this->archivo);
        $this->assertSame('sin_licencia', Licencia::estado()['estado']);
        try {
            Licencia::exigirParaEmitir();
            $this->fail('Sin licencia no se emite.');
        } catch (LicenciaVencida) {
            $this->assertTrue(true);
        }
    }

    public function testGuardarValidaFirmaFechaYCuit(): void
    {
        $this->assertFalse(Licencia::guardar('no es json')['ok']);
        $mala = Licencia::firmar(['cliente' => 'X', 'cuit' => '30714357537', 'vence' => '2026-12-31', 'emitida' => '2026-01-01'], base64_encode(sodium_crypto_sign_secretkey(sodium_crypto_sign_keypair())));
        $this->assertStringContainsString('firma', Licencia::guardar(json_encode($mala))['error']);
        $otroCuit = Licencia::firmar(['cliente' => 'X', 'cuit' => '20111111112', 'vence' => '2026-12-31', 'emitida' => '2026-01-01'], $this->privada);
        $r = Licencia::guardar(json_encode($otroCuit));
        $this->assertFalse($r['ok']);
        $this->assertStringContainsString('otro CUIT', $r['error']);
        $buena = Licencia::firmar(['cliente' => 'X', 'cuit' => '30714357537', 'vence' => '2099-12-31', 'emitida' => '2026-01-01'], $this->privada);
        $this->assertTrue(Licencia::guardar(json_encode($buena))['ok']);
        $this->assertSame('ok', Licencia::estado()['estado']);
    }

    public function testUnaLicenciaDeOtroClienteNoSirve(): void
    {
        $this->licencia('2099-12-31', '20111111112'); // la empresa de la prueba es 30714357537
        $e = Licencia::estado();
        $this->assertSame('invalida', $e['estado']);
        $this->assertStringContainsString('otro CUIT', $e['mensaje']);
    }

    public function testVencidaNoEmitePeroVigenteSi(): void
    {
        $this->licencia('2020-01-01');
        $r = $this->svc->armar($this->pedido(), false);
        $this->assertSame([], $r['errores']);
        try {
            $this->svc->emitir($r['cbte'], bin2hex(random_bytes(16)), 0);
            $this->fail('Una suscripción vencida no puede emitir.');
        } catch (LicenciaVencida $e) {
            $this->assertStringContainsString('venció', $e->getMessage());
        }
        $this->assertSame(0, (int) Db::pdo()->query('SELECT COUNT(*) FROM subdiario')->fetchColumn(), 'no se grabó nada');
        $this->licencia('2099-12-31');
        $this->assertTrue($this->svc->emitir($r['cbte'], bin2hex(random_bytes(16)), 0)['id'] > 0);
    }

    public function testElFormularioMuestraElMotivoSiNoSePuedeEmitir(): void
    {
        $this->licencia('2020-01-01');
        $this->loginComo($this->crearUsuario('t_lic_adm', 'admin'));
        $r = $this->get('/sistema/licencia');
        $this->assertSame(200, $r->status);
        $this->assertStringContainsString('venció', $r->body);
    }

    public function testPantallaYPermisos(): void
    {
        $this->licencia('2099-12-31');
        $buena = json_encode(Licencia::firmar(['cliente' => 'X', 'cuit' => '30714357537', 'vence' => '2099-06-30', 'emitida' => '2026-01-01'], $this->privada));
        $this->loginComo($this->crearUsuario('t_lic_op', 'operador'));
        $this->assertSame(403, $this->get('/sistema/licencia')->status, 'el operador no ve la licencia');
        $this->assertSame(403, $this->post('/sistema/licencia', ['licencia' => $buena])->status);
        $this->loginComo($this->crearUsuario('t_lic_adm', 'admin'));
        $this->assertSame(200, $this->get('/sistema/licencia')->status, 'el administrador la ve');
        $this->assertSame(403, $this->post('/sistema/licencia', ['licencia' => $buena])->status, 'pero no la carga');
        $this->loginComo($this->crearUsuario('t_lic_mas', 'master'));
        $this->assertSame(302, $this->post('/sistema/licencia', ['licencia' => $buena])->status, 'el Master sí');
        $this->assertSame(422, $this->post('/sistema/licencia', ['licencia' => '{"vence":"x","firma":"y"}'])->status);
        $this->assertSame('2099-06-30', Licencia::leer()['vence']);
    }

    public function testElAvisoLoVeQuienCorresponde(): void
    {
        $this->licencia('2026-12-31');
        // por vencer: solo lo ve el administrador; vencida: todos
        $hoy = new \DateTimeImmutable('2026-12-25');
        $this->assertSame('por_vencer', Licencia::estado($hoy)['estado']);
        $this->licencia('2020-01-01');
        $this->assertSame('error', Licencia::aviso(false)['tipo']);
        $this->licencia((new \DateTimeImmutable('+3 days'))->format('Y-m-d'));
        $this->assertNull(Licencia::aviso(false));
        $this->assertSame('info', Licencia::aviso(true)['tipo']);
    }
}
