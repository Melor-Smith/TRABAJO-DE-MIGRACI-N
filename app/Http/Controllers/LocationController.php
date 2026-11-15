<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\NivelUbigeo;
use App\Models\District;
use App\Models\Province;
use App\Models\Region;
use App\Models\School;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Collection;

/**
 * Consultas del catálogo de ubigeos y colegios.
 *
 * Todas las respuestas tienen el mismo formato:
 * - con datos:    {"status": "success", "data": [...]}
 * - sin datos:    {"status": "error", "message": "..."} con código 404
 */
class LocationController extends Controller
{
    public function getRegions(): JsonResponse
    {
        return $this->conDatos(Region::all());
    }

    public function getProvinces(string $idRegion): JsonResponse
    {
        return $this->responder(
            $this->dependientesDe(NivelUbigeo::Region, $idRegion, Province::query(), 'idProvincia'),
            'No se encontraron provincias para esta región.',
        );
    }

    public function getDistricts(string $idProvince): JsonResponse
    {
        return $this->responder(
            $this->dependientesDe(NivelUbigeo::Provincia, $idProvince, District::query(), 'idDistrito'),
            'No se encontraron distritos para esta provincia.',
        );
    }

    public function getSchools(string $ubigeo): JsonResponse
    {
        $colegios = NivelUbigeo::Distrito->admite($ubigeo)
            ? School::where('idDistrito', $ubigeo)->get()
            : collect();

        return $this->responder($colegios, 'No se encontraron escuelas para este distrito.');
    }

    /**
     * Registros que pertenecen a un código del nivel superior: su código empieza
     * con él (las provincias de la región 15 son 15xx). Un código mal formado
     * no llega a consultar la base de datos.
     */
    private function dependientesDe(NivelUbigeo $nivel, string $codigo, Builder $consulta, string $columna): Collection
    {
        if (! $nivel->admite($codigo)) {
            return collect();
        }

        return $consulta->where($columna, 'like', $codigo.'%')->get();
    }

    private function responder(Collection $datos, string $mensajeSinResultados): JsonResponse
    {
        if ($datos->isEmpty()) {
            return response()->json(['status' => 'error', 'message' => $mensajeSinResultados], 404);
        }

        return $this->conDatos($datos);
    }

    private function conDatos(Collection $datos): JsonResponse
    {
        return response()->json(['status' => 'success', 'data' => $datos]);
    }
}
