<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use App\Models\Region;
use App\Models\Province;
use App\Models\District;
use App\Models\School;

class SyncLocations extends Command
{
    protected $signature = 'sync:locations';
    protected $description = 'Sincroniza regiones, provincias, distritos y escuelas desde la API y las registra en la base de datos';

    public function __construct()
    {
        parent::__construct();
    }

    public function handle()
    {
        $this->info('Iniciando sincronización de ubicaciones...');

        // Sincronizar regiones
        $this->syncRegions();

        $this->info('Sincronización completa.');
        return 0;
    }

    /**
     * Consulta la API REST de ESCALE (MINEDU).
     *
     * El servidor del MINEDU a veces corta la conexión (cURL error 56) o tarda en
     * responder. Cada consulta se reintenta 3 veces, esperando 2 s entre intentos;
     * si aún falla, devuelve null para que la sincronización continúe con el
     * siguiente elemento en lugar de detenerse.
     */
    private function escale(string $recurso, array $parametros = []): ?Response
    {
        try {
            return Http::withHeaders([
                'Accept' => 'application/json', // Especifica que la respuesta esperada es JSON
            ])
                ->timeout(60)
                ->retry(3, 2000, throw: false)
                ->get('https://escale.minedu.gob.pe/padron/rest/'.$recurso, $parametros);
        } catch (ConnectionException $e) {
            $this->error("Sin conexión con ESCALE ($recurso): ".$e->getMessage());

            return null;
        }
    }

    private function syncRegions()
    {
        $this->info('Sincronizando regiones...');
        $response = $this->escale('regiones');

        if (! $response || $response->failed()) {
            $this->error('Error al obtener regiones');
            return;
        }
        $regions = $response->json()['region'];

        for ($i=0;$i<count($regions);$i++) {
            $region=$regions[$i];
            Region::updateOrCreate(
                ['idRegion' => $region['idRegion']],
                [
                    'nombreRegion' => $region['nombreRegion'],
                    'pointX' => $region['pointX'],
                    'pointY' => $region['pointY'],
                    'zoom' => $region['zoom'],
                ]
            );

            $this->syncProvinces($region['idRegion']);
        }

        $this->info('Regiones sincronizadas correctamente.');
    }

    private function syncProvinces($regionId)
    {
        $this->info("Sincronizando provincias para la región $regionId...");
        $response = $this->escale('provincias', [
            'idRegion' => $regionId,
        ]);

        if (! $response || $response->failed()) {
            $this->error("Error al obtener provincias para la región $regionId");
            return;
        }
        $provinces = $response->json()['provincia'];
        if(isset($provinces['idProvincia'])){
            Province::updateOrCreate(
                ['idProvincia' => $provinces['idProvincia']],
                [
                    'nombreProvincia' => $provinces['nombreProvincia'],
                    'pointX' => $provinces['pointX'],
                    'pointY' => $provinces['pointY'],
                ]
            );
            $this->syncDistricts($provinces['idProvincia']);
        }else{
            foreach ($provinces as $province) {
                Province::updateOrCreate(
                    ['idProvincia' => $province['idProvincia']],
                    [
                        'nombreProvincia' => $province['nombreProvincia'],
                        'pointX' => $province['pointX'],
                        'pointY' => $province['pointY'],
                    ]
                );

                $this->syncDistricts($province['idProvincia']);
            }
        }


        $this->info("Provincias para la región $regionId sincronizadas correctamente.");
    }

    private function syncDistricts($provinceId)
    {
        $this->info("Sincronizando distritos para la provincia $provinceId...");
        $response = $this->escale('distritos', [
            'idProvincia' => $provinceId,
        ]);

        if (! $response || $response->failed()) {
            $this->error("Error al obtener distritos para la provincia $provinceId");
            return;
        }

        $districts = $response->json()['distrito'];
        if(isset($districts['idDistrito'])){
                District::updateOrCreate(
                    ['idDistrito' => $districts['idDistrito']],
                    [
                        'nombreDistrito' => $districts['nombreDistrito'],
                    ]
                );

                $this->syncSchools($districts['idDistrito']);
        }else{
            for ($j=0;$j<count($districts);$j++) {
                $district=$districts[$j];
                District::updateOrCreate(
                    ['idDistrito' => $district['idDistrito']],
                    [
                        'nombreDistrito' => $district['nombreDistrito'],
                    ]
                );

                $this->syncSchools($district['idDistrito']);
            }
        }

        $this->info("Distritos para la provincia $provinceId sincronizados correctamente.");
    }

    /**
     * Colegios por página. ESCALE devuelve solo 50 si no se envía "max",
     * por lo que los distritos con más colegios quedaban incompletos.
     */
    private const SCHOOLS_PAGE_SIZE = 500;

    private function syncSchools($districtId)
    {
        $this->info("Sincronizando escuelas para el distrito $districtId...");
        $start = 0;

        do {
            $response = $this->escale('instituciones', [
                'estados' => 1,
                'ubigeo' => $districtId,
                'start' => $start,
                'max' => self::SCHOOLS_PAGE_SIZE,
            ]);

            if (! $response || $response->failed()) {
                $this->error("Error al obtener escuelas para el distrito $districtId");
                return;
            }

            // Sin "items": el distrito no tiene colegios o ya se leyó la última página
            $schools = $response->json('items');
            if ($schools === null) {
                if ($start === 0) {
                    $this->error("No se encontraron registros de escuelas");
                    return;
                }
                break;
            }

            // ESCALE devuelve un objeto (no una lista) cuando la página tiene un solo colegio
            if (isset($schools['codMod'])) {
                $schools = [$schools];
            }

            foreach ($schools as $school) {
                School::updateOrCreate(
                    ['codMod' => $school['codMod']],
                    [
                        'idDistrito' => $districtId,
                        'nombreCenEdu' => $school['cenEdu'] ?? null,
                        'cenPob' => $school['cenPob'] ?? null,
                        'dirCen' => $school['dirCen'] ?? null,
                        'director' => $school['director'] ?? null,
                        'dis_crecer' => $school['dis_crecer'] ?? null,
                        'dis_juntos' => $school['dis_juntos'] ?? null,
                    ]
                );
            }

            $start += count($schools);
        } while (count($schools) === self::SCHOOLS_PAGE_SIZE);

        $this->info("Escuelas para el distrito $districtId sincronizadas correctamente ($start).");
    }
}
