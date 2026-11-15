<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;

/**
 * Portada del servicio: explica qué ofrece la API a quien abre la dirección
 * principal, con un ejemplo listo para probar en cada consulta.
 *
 * Por seguridad no muestra versiones de PHP ni de Laravel: conocerlas le
 * ahorra a un atacante el trabajo de identificar qué fallas buscar.
 */
class InicioController extends Controller
{
    /**
     * Consultas disponibles. Cada ejemplo usa la región Huánuco (10),
     * la provincia Huánuco (1001) y el distrito Huánuco (100101).
     */
    private const array CONSULTAS = [
        ['ruta' => '/api/regiones', 'devuelve' => 'Regiones (departamentos) del Perú', 'ejemplo' => '/api/regiones'],
        ['ruta' => '/api/provincias/{idRegion}', 'devuelve' => 'Provincias de una región (código de 2 dígitos)', 'ejemplo' => '/api/provincias/10'],
        ['ruta' => '/api/distritos/{idProvince}', 'devuelve' => 'Distritos de una provincia (código de 4 dígitos)', 'ejemplo' => '/api/distritos/1001'],
        ['ruta' => '/api/colegios/{ubigeo}', 'devuelve' => 'Colegios activos de un distrito (ubigeo de 6 dígitos)', 'ejemplo' => '/api/colegios/100101'],
    ];

    public function __invoke(): JsonResponse
    {
        return response()->json([
            'servicio' => 'Bridges Note',
            'descripcion' => 'API de solo lectura con el padrón de instituciones educativas del MINEDU (ESCALE), organizado por ubigeo del INEI.',
            'consultas' => array_map(
                fn (array $consulta): array => [...$consulta, 'ejemplo' => url($consulta['ejemplo'])],
                self::CONSULTAS,
            ),
            'limite' => '60 consultas por minuto por cliente',
            'estado' => url('/up'),
        ], options: JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
