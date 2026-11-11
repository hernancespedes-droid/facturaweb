<?php
declare(strict_types=1);

use App\Http\PagesController;
use App\Http\Router;
use App\Modules\Auth\Guard;

return function (Router $r): void {
    $r->get('/', Guard::auth([PagesController::class, 'home']));
    $r->get('/salud', [PagesController::class, 'salud']);
    $r->get('/diagnostico', [PagesController::class, 'diagnostico']);
};
