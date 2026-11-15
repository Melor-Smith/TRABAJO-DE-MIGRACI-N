<?php

use App\Http\Controllers\LocationController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Rutas de la API
|--------------------------------------------------------------------------
|
| Se cargan con el prefijo /api y el límite de 60 peticiones por minuto
| (ver bootstrap/app.php).
|
*/

Route::get('/regiones', [LocationController::class, 'getRegions']);
Route::get('/provincias/{idRegion}', [LocationController::class, 'getProvinces']);
Route::get('/distritos/{idProvince}', [LocationController::class, 'getDistricts']);
Route::get('/colegios/{ubigeo}', [LocationController::class, 'getSchools']);
