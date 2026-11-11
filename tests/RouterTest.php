<?php
declare(strict_types=1);

namespace Tests;

use App\Http\Request;
use App\Http\Response;
use App\Http\Router;

final class RouterTest extends TestCase
{
    private function router(): Router
    {
        $r = new Router();
        $r->get('/', fn (Request $q, array $p): Response => Response::html('inicio'));
        $r->get('/vehiculos/{id}', fn (Request $q, array $p): Response => Response::html('vehiculo ' . $p['id']));
        $r->post('/vehiculos', fn (Request $q, array $p): Response => Response::html('creado', 201));
        return $r;
    }

    public function testRutaEstatica(): void
    {
        $res = $this->router()->dispatch(Request::make('GET', '/'));
        $this->assertSame(200, $res->status);
        $this->assertSame('inicio', $res->body);
    }

    public function testParametrosDeRuta(): void
    {
        $res = $this->router()->dispatch(Request::make('GET', '/vehiculos/BP-AH1234'));
        $this->assertSame('vehiculo BP-AH1234', $res->body);
    }

    public function testBarraFinalSeIgnora(): void
    {
        $res = $this->router()->dispatch(Request::make('GET', '/vehiculos/7/'));
        $this->assertSame('vehiculo 7', $res->body);
    }

    public function testRutaInexistenteDa404(): void
    {
        $this->assertSame(404, $this->router()->dispatch(Request::make('GET', '/nada'))->status);
    }

    public function testMetodoEquivocadoDa405(): void
    {
        $this->assertSame(405, $this->router()->dispatch(Request::make('GET', '/vehiculos'))->status);
        $this->assertSame(201, $this->router()->dispatch(Request::make('POST', '/vehiculos'))->status);
    }
}
