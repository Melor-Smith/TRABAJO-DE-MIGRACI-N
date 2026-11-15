<?php

use App\Http\Controllers\InicioController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Rutas web
|--------------------------------------------------------------------------
|
| El proyecto es una API: la dirección principal solo describe las
| consultas disponibles (ver InicioController).
|
*/

Route::get('/', InicioController::class);
