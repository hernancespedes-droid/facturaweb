<?php
declare(strict_types=1);

namespace Tests;

use App\Core\Db;
use App\Modules\Agente\ApiClaves;
use App\Modules\Migracion\MigradorVfp;

/** Agente: sincronización incremental de comprobantes del VFP, claves de API y permisos. */
final class AgenteTest extends DbTestCase
{
    private string $dir = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir() . '/fw_ag_' . bin2hex(random_bytes(4));
        mkdir($this->dir);
        $this->limpiar();
    }

    protected function tearDown(): void
    {
        $this->limpiar();
        foreach (glob($this->dir . '/*') ?: [] as $f) {
            unlink($f);
        }
        @rmdir($this->dir);
        parent::tearDown();
    }

    private function limpiar(): void
    {
        $pdo = Db::pdo();
        foreach (['subdiario', 'iva', 'estadistica', 'caja', 'ctact'] as $t) {
            $pdo->exec("DELETE FROM `{$t}`");
        }
        $pdo->exec('DELETE FROM migracion_vfp');
        $pdo->exec("DELETE FROM api_claves WHERE nombre LIKE 'T %'");
    }

    /** DBF de comprobantes FC A del PV 4 con los números dados (cada uno con un renglón y su IVA). */
    private function dbfs(array $numeros): void
    {
        $d = $this->dir;
        DbfFalso::dbc($d . '/datos.dbc', [
            'subdiario' => ['fecha', 'tipo', 'letra', 'sucursal', 'prefijo', 'comprobante', 'cliente', 'neto_grabado', 'iva_1', 'neto', 'fe_clave'],
            'estadistica' => ['fecha', 'tipo', 'letra', 'sucursal', 'comprobante', 'total_item', 'observaciones', 'fe_clave'],
            'iva' => ['tipo', 'letra', 'sucursal', 'comprobante', 'alicuota', 'monto', 'importe', 'fe_clave'],
            'caja' => ['fecha', 'tipo', 'letra', 'sucursal', 'comprobante', 'p_fpago', 'p_importe', 'fe_clave'],
            'ctact' => ['cliente', 'fecha', 'tipo', 'tico', 'letra', 'sucursal', 'numero', 'neto', 'saldo', 'fe_clave'],
        ]);
        $sub = $est = $iva = $caja = $ct = [];
        foreach ($numeros as $n) {
            $f = '2026-11-' . str_pad((string) (1 + $n % 28), 2, '0', STR_PAD_LEFT);
            $sub[] = ['FECHA' => $f, 'TIPO' => 'FC', 'LETRA' => 'A', 'SUCURSAL' => 1, 'PREFIJO' => 4, 'COMPROBANT' => $n, 'CLIENTE' => 5, 'NETO_GRABA' => 100, 'IVA_1' => 21, 'NETO' => 121, 'FE_CLAVE' => $n];
            $est[] = ['FECHA' => $f, 'TIPO' => 'FC', 'LETRA' => 'A', 'SUCURSAL' => 1, 'COMPROBANT' => $n, 'TOTAL_ITEM' => 100, 'OBSERVACIO' => "Renglón {$n}", 'FE_CLAVE' => $n];
            $iva[] = ['TIPO' => 'FC', 'LETRA' => 'A', 'SUCURSAL' => 1, 'COMPROBANT' => $n, 'ALICUOTA' => 21, 'MONTO' => 100, 'IMPORTE' => 21, 'FE_CLAVE' => $n];
            $caja[] = ['FECHA' => $f, 'TIPO' => 'FC', 'LETRA' => 'A', 'SUCURSAL' => 1, 'COMPROBANT' => $n, 'P_FPAGO' => 'CC', 'P_IMPORTE' => 121, 'FE_CLAVE' => $n];
            $ct[] = ['CLIENTE' => 5, 'FECHA' => $f, 'TIPO' => 'FC', 'TICO' => 1, 'LETRA' => 'A', 'SUCURSAL' => 1, 'NUMERO' => $n, 'NETO' => 121, 'SALDO' => 121, 'FE_CLAVE' => $n];
        }
        DbfFalso::escribir($d . '/subdiario.dbf', [['FECHA', 'D'], ['TIPO', 'C', 2], ['LETRA', 'C', 1], ['SUCURSAL', 'N', 2], ['PREFIJO', 'N', 4], ['COMPROBANT', 'N', 7], ['CLIENTE', 'N', 5],
            ['NETO_GRABA', 'N', 11, 2], ['IVA_1', 'N', 11, 2], ['NETO', 'N', 11, 2], ['FE_CLAVE', 'I']], $sub);
        DbfFalso::escribir($d . '/estadistica.dbf', [['FECHA', 'D'], ['TIPO', 'C', 2], ['LETRA', 'C', 1], ['SUCURSAL', 'N', 2], ['COMPROBANT', 'N', 7], ['TOTAL_ITEM', 'N', 11, 2], ['OBSERVACIO', 'M'], ['FE_CLAVE', 'I']], $est);
        DbfFalso::escribir($d . '/iva.dbf', [['TIPO', 'C', 2], ['LETRA', 'C', 1], ['SUCURSAL', 'N', 2], ['COMPROBANT', 'N', 7], ['ALICUOTA', 'N', 5, 2], ['MONTO', 'N', 10, 2], ['IMPORTE', 'N', 10, 2], ['FE_CLAVE', 'N', 14]], $iva);
        DbfFalso::escribir($d . '/caja.dbf', [['FECHA', 'D'], ['TIPO', 'C', 2], ['LETRA', 'C', 1], ['SUCURSAL', 'N', 2], ['COMPROBANT', 'N', 7], ['P_FPAGO', 'C', 2], ['P_IMPORTE', 'N', 11, 2], ['FE_CLAVE', 'I']], $caja);
        DbfFalso::escribir($d . '/ctact.dbf', [['CLIENTE', 'N', 5], ['FECHA', 'D'], ['TIPO', 'C', 2], ['TICO', 'N', 1], ['LETRA', 'C', 1], ['SUCURSAL', 'N', 2], ['NUMERO', 'N', 8], ['NETO', 'N', 10, 2], ['SALDO', 'N', 10, 2], ['FE_CLAVE', 'I']], $ct);
    }

    private function cuenta(string $t): int
    {
        return (int) Db::pdo()->query("SELECT COUNT(*) FROM `{$t}`")->fetchColumn();
    }

    public function testSoloSumaLosComprobantesNuevosYNoDuplicaAlRepetir(): void
    {
        $this->dbfs([1, 2, 3]);
        $r = (new MigradorVfp(Db::pdo(), $this->dir))->sumarComprobantes('agente:T 1');
        $this->assertSame([3, 3, 3, 3, 3], [$this->cuenta('subdiario'), $this->cuenta('iva'), $this->cuenta('estadistica'), $this->cuenta('caja'), $this->cuenta('ctact')]);
        $this->assertSame(3, $r['subdiario']['grabadas']);
        $this->assertSame(3, (int) Db::pdo()->query('SELECT COUNT(*) FROM estadistica WHERE subdiario_id IS NOT NULL')->fetchColumn(), 'quedan vinculados');

        // segundo envío con los mismos + 2 nuevos
        $this->dbfs([1, 2, 3, 4, 5]);
        $r = (new MigradorVfp(Db::pdo(), $this->dir))->sumarComprobantes('agente:T 1');
        $this->assertSame(2, $r['subdiario']['grabadas'], 'solo los 2 nuevos');
        $this->assertSame([5, 5, 5, 5, 5], [$this->cuenta('subdiario'), $this->cuenta('iva'), $this->cuenta('estadistica'), $this->cuenta('caja'), $this->cuenta('ctact')], 'nada se duplicó');

        // tercer envío idéntico: nada nuevo
        $r = (new MigradorVfp(Db::pdo(), $this->dir))->sumarComprobantes('agente:T 1');
        $this->assertSame(0, $r['subdiario']['grabadas']);
        $this->assertSame(5, $this->cuenta('subdiario'));
        $this->assertSame(3, $this->cuenta('migracion_vfp'), 'cada envío queda registrado');
    }

    public function testNoTocaLosComprobantesEmitidosEnLaWeb(): void
    {
        Db::pdo()->exec("INSERT INTO subdiario (fecha, tipo, letra, sucursal, prefijo, comprobante, cliente, canal, es_prueba) VALUES ('2026-11-02', 'FC', 'A', 99, 7, 1, 5, 'W', 1)");
        $this->dbfs([1]);
        (new MigradorVfp(Db::pdo(), $this->dir))->sumarComprobantes('agente:T 1');
        $this->assertSame(2, $this->cuenta('subdiario'));
        $this->assertSame(1, (int) Db::pdo()->query("SELECT COUNT(*) FROM subdiario WHERE canal = 'W'")->fetchColumn());
    }

    public function testClaveDeApi(): void
    {
        $clave = ApiClaves::crear('T PC', null);
        $this->assertSame(52, strlen($clave));
        $this->assertNotNull(ApiClaves::validar('Bearer ' . $clave, '1.2.3.4'));
        $this->assertNull(ApiClaves::validar('Bearer fwk_' . str_repeat('a', 48)), 'otra clave');
        $this->assertNull(ApiClaves::validar(null));
        $this->assertNull(ApiClaves::validar($clave), 'sin la palabra Bearer');
        $this->assertSame(1, (int) Db::pdo()->query("SELECT COUNT(*) FROM api_claves WHERE nombre = 'T PC' AND clave_hash <> '' AND ultimo_ip = '1.2.3.4'")->fetchColumn(), 'guarda el hash y el último uso');
        $this->assertSame(0, (int) Db::pdo()->query("SELECT COUNT(*) FROM api_claves WHERE clave_hash = " . Db::pdo()->quote($clave))->fetchColumn(), 'la clave no se guarda en claro');
        $id = (int) Db::pdo()->query("SELECT id FROM api_claves WHERE nombre = 'T PC'")->fetchColumn();
        ApiClaves::revocar($id);
        $this->assertNull(ApiClaves::validar('Bearer ' . $clave), 'revocada');
    }

    public function testApiPingYComprobantesPorHttp(): void
    {
        $this->assertSame(401, $this->get('/api/v1/ping')->status, 'sin clave');
        $clave = ApiClaves::crear('T PC', null);
        $r = $this->conClave($clave, 'GET', '/api/v1/ping');
        $this->assertSame(200, $r->status);
        $this->assertStringContainsString('"ok":true', $r->body);

        $this->dbfs([1, 2]);
        $zip = $this->dir . '.zip';
        $z = new \ZipArchive();
        $z->open($zip, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
        foreach (glob($this->dir . '/*') as $f) {
            $z->addFile($f, basename($f));
        }
        $z->close();
        $r = $this->conClave($clave, 'POST', '/api/v1/vfp/comprobantes', ['zip' => ['error' => UPLOAD_ERR_OK, 'tmp_name' => $zip, 'name' => 'x.zip']]);
        @unlink($zip);
        $this->assertSame(200, $r->status, $r->body);
        $this->assertSame(2, json_decode($r->body, true)['nuevos']);
        $this->assertSame(2, $this->cuenta('subdiario'));
        $mal = $this->conClave('fwk_' . str_repeat('0', 48), 'POST', '/api/v1/vfp/comprobantes');
        $this->assertSame(401, $mal->status);
    }

    private function conClave(string $clave, string $metodo, string $ruta, array $files = []): \App\Http\Response
    {
        $req = new \App\Http\Request($metodo, $ruta, [], [], ['HTTP_AUTHORIZATION' => 'Bearer ' . $clave, 'REMOTE_ADDR' => '10.0.0.1'], $files);
        return $this->app->handle($req);
    }

    public function testPantallaDeClavesSoloConPermiso(): void
    {
        $this->loginComo($this->crearUsuario('t_op', 'operador'));
        $this->assertSame(403, $this->get('/agente')->status);
        $this->assertSame(403, $this->post('/agente', ['nombre' => 'T x'])->status);
        $this->loginComo($this->crearUsuario('t_adm', 'admin'));
        $this->assertSame(200, $this->get('/agente')->status);
        $this->assertRedirect('/agente', $this->post('/agente', ['nombre' => 'T desde pantalla']));
        $this->assertStringContainsString('fwk_', $this->get('/agente')->body, 'la clave nueva se muestra una vez');
        $this->assertSame(1, (int) Db::pdo()->query("SELECT COUNT(*) FROM api_claves WHERE nombre = 'T desde pantalla'")->fetchColumn());
    }
}
