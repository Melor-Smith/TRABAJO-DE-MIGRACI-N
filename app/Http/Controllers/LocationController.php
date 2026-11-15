<?php

namespace App\Http\Controllers;

use App\Models\Region;
use App\Models\Province;
use App\Models\District;
use App\Models\School;
use Illuminate\Http\Request;

class LocationController extends Controller
{
    // Obtener todas las regiones desde la base de datos
    public function getRegions()
    {
        $regions = Region::all();

        return response()->json([
            'status' => 'success',
            'data' => $regions,
        ]);
    }

    // Obtener provincias de una región (los 2 primeros dígitos de idProvincia son el idRegion)
    public function getProvinces($idRegion)
    {
        // Solo se aceptan 2 dígitos, para que "%" o "_" no actúen como comodines en el LIKE
        $provinces = preg_match('/^\d{2}$/', $idRegion)
            ? Province::where('idProvincia', 'like', $idRegion.'%')->get()
            : collect();

        if ($provinces->isEmpty()) {
            return response()->json([
                'status' => 'error',
                'message' => 'No se encontraron provincias para esta región.',
            ], 404);
        }

        return response()->json([
            'status' => 'success',
            'data' => $provinces,
        ]);
    }

    // Obtener distritos de una provincia (los 4 primeros dígitos de idDistrito son el idProvincia)
    public function getDistricts($idProvince)
    {
        // Solo se aceptan 4 dígitos, para que "%" o "_" no actúen como comodines en el LIKE
        $districts = preg_match('/^\d{4}$/', $idProvince)
            ? District::where('idDistrito', 'like', $idProvince.'%')->get()
            : collect();

        if ($districts->isEmpty()) {
            return response()->json([
                'status' => 'error',
                'message' => 'No se encontraron distritos para esta provincia.',
            ], 404);
        }

        return response()->json([
            'status' => 'success',
            'data' => $districts,
        ]);
    }

    // Obtener escuelas de un distrito (según idDistrito completo)
    public function getSchools($districtId)
    {
        $schools = School::where('idDistrito', $districtId)->get();

        if ($schools->isEmpty()) {
            return response()->json([
                'status' => 'error',
                'message' => 'No se encontraron escuelas para este distrito.',
            ], 404);
        }

        return response()->json([
            'status' => 'success',
            'data' => $schools,
        ]);
    }
}
