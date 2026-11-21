<?php
declare(strict_types=1);

use App\Http\Router;
use App\Modules\Auth\Guard;
use App\Modules\Instancias\InstanciasController as C;

// Panel de instalaciones de clientes: solo el Master y solo en la instalación principal (cada acción lo controla).
return function (Router $r): void {
    $r->get('/instancias', Guard::auth([C::class, 'index']));
    $r->get('/instancias/nueva', Guard::auth([C::class, 'nueva']));
    $r->post('/instancias/nueva', Guard::auth([C::class, 'crear']));
    $r->get('/instancias/editar', Guard::auth([C::class, 'editarForm']));
    $r->post('/instancias/editar', Guard::auth([C::class, 'editar']));
    $r->post('/instancias/actualizar', Guard::auth([C::class, 'actualizar']));
    $r->post('/instancias/principal', Guard::auth([C::class, 'principal']));
    $r->post('/instancias/cliente', Guard::auth([C::class, 'crearCliente']));
    $r->get('/instancias/usuarios', Guard::auth([C::class, 'usuarios']));
    $r->post('/instancias/usuarios/resetear', Guard::auth([C::class, 'resetear']));
    $r->get('/instancias/copia', Guard::auth([C::class, 'copia']));
    $r->post('/instancias/baja', Guard::auth([C::class, 'baja']));
    $r->post('/instancias/pasar-a-clientes', Guard::auth([C::class, 'pasarAClientes']));
    $r->post('/instancias/mover', Guard::auth([C::class, 'mover']));
};
