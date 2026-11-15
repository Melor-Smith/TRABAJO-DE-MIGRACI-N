<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\District;
use App\Models\Province;
use App\Models\Region;
use App\Models\School;
use Illuminate\Console\Command;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Copia el padrón del MINEDU (API REST de ESCALE) a la base de datos local:
 * regiones → provincias → distritos → colegios activos de cada distrito.
 */
class SyncLocations extends Command
{
    /** API REST pública del padrón de instituciones educativas. */
    private const string ESCALE_URL = 'https://escale.minedu.gob.pe/padron/rest/';

    /** Colegios por consulta. Si no se envía "max", ESCALE devuelve solo 50. */
    private const int COLEGIOS_POR_PAGINA = 500;

    /** El MINEDU corta conexiones de forma intermitente (cURL error 56). */
    private const int INTENTOS = 3;

    private const int ESPERA_ENTRE_INTENTOS_MS = 2000;

    private const int TIEMPO_MAXIMO_S = 60;

    protected $signature = 'sync:locations';

    protected $description = 'Sincroniza regiones, provincias, distritos y escuelas desde la API y las registra en la base de datos';

    public function handle(): int
    {
        $this->info('Iniciando sincronización de ubicaciones...');

        $this->syncRegions();

        $this->info('Sincronización completa.');

        return self::SUCCESS;
    }

    /**
     * Consulta un recurso de ESCALE con reintentos. Si después de todos los
     * intentos no hay conexión, devuelve null para que la sincronización
     * continúe con el siguiente elemento en lugar de detenerse.
     */
    private function escale(string $recurso, array $parametros = []): ?Response
    {
        try {
            return Http::acceptJson()
                ->timeout(self::TIEMPO_MAXIMO_S)
                ->retry(self::INTENTOS, self::ESPERA_ENTRE_INTENTOS_MS, throw: false)
                ->get(self::ESCALE_URL.$recurso, $parametros);
        } catch (ConnectionException $e) {
            $this->error("Sin conexión con ESCALE ($recurso): ".$e->getMessage());

            return null;
        }
    }

    /**
     * ESCALE devuelve una lista cuando hay varios resultados, pero un objeto
     * suelto cuando hay uno solo. Esta función siempre entrega una lista.
     *
     * @return list<array<string, mixed>>
     */
    private function comoLista(?array $datos, string $campoClave): array
    {
        if (empty($datos)) {
            return [];
        }

        return isset($datos[$campoClave]) ? [$datos] : array_values($datos);
    }

    private function syncRegions(): void
    {
        $this->info('Sincronizando regiones...');
        $response = $this->escale('regiones');

        if (! $response?->successful()) {
            $this->error('Error al obtener regiones');

            return;
        }

        foreach ($this->comoLista($response->json('region'), 'idRegion') as $region) {
            $idRegion = (string) $region['idRegion'];

            Region::updateOrCreate(['idRegion' => $idRegion], [
                'nombreRegion' => $region['nombreRegion'],
                'pointX' => $region['pointX'],
                'pointY' => $region['pointY'],
                'zoom' => $region['zoom'],
            ]);

            $this->syncProvinces($idRegion);
        }

        $this->info('Regiones sincronizadas correctamente.');
    }

    private function syncProvinces(string $idRegion): void
    {
        $this->info("Sincronizando provincias para la región $idRegion...");
        $response = $this->escale('provincias', ['idRegion' => $idRegion]);

        if (! $response?->successful()) {
            $this->error("Error al obtener provincias para la región $idRegion");

            return;
        }

        foreach ($this->comoLista($response->json('provincia'), 'idProvincia') as $provincia) {
            $idProvincia = (string) $provincia['idProvincia'];

            Province::updateOrCreate(['idProvincia' => $idProvincia], [
                'nombreProvincia' => $provincia['nombreProvincia'],
                'pointX' => $provincia['pointX'],
                'pointY' => $provincia['pointY'],
            ]);

            $this->syncDistricts($idProvincia);
        }

        $this->info("Provincias para la región $idRegion sincronizadas correctamente.");
    }

    private function syncDistricts(string $idProvincia): void
    {
        $this->info("Sincronizando distritos para la provincia $idProvincia...");
        $response = $this->escale('distritos', ['idProvincia' => $idProvincia]);

        if (! $response?->successful()) {
            $this->error("Error al obtener distritos para la provincia $idProvincia");

            return;
        }

        foreach ($this->comoLista($response->json('distrito'), 'idDistrito') as $distrito) {
            $idDistrito = (string) $distrito['idDistrito'];

            District::updateOrCreate(['idDistrito' => $idDistrito], [
                'nombreDistrito' => $distrito['nombreDistrito'],
            ]);

            $this->syncSchools($idDistrito);
        }

        $this->info("Distritos para la provincia $idProvincia sincronizados correctamente.");
    }

    /**
     * Descarga los colegios activos del distrito página por página, hasta
     * recibir una página incompleta (la última).
     */
    private function syncSchools(string $idDistrito): void
    {
        $this->info("Sincronizando escuelas para el distrito $idDistrito...");
        $guardados = 0;

        do {
            $response = $this->escale('instituciones', [
                'estados' => 1,
                'ubigeo' => $idDistrito,
                'start' => $guardados,
                'max' => self::COLEGIOS_POR_PAGINA,
            ]);

            if (! $response?->successful()) {
                $this->error("Error al obtener escuelas para el distrito $idDistrito");

                return;
            }

            // Sin "items": el distrito no tiene colegios o ya se leyó la última página
            $pagina = $this->comoLista($response->json('items'), 'codMod');

            if ($pagina === [] && $guardados === 0) {
                $this->error('No se encontraron registros de escuelas');

                return;
            }

            foreach ($pagina as $colegio) {
                $this->guardarColegio($colegio, $idDistrito);
            }

            $guardados += count($pagina);
        } while (count($pagina) === self::COLEGIOS_POR_PAGINA);

        $this->info("Escuelas para el distrito $idDistrito sincronizadas correctamente ($guardados).");
    }

    private function guardarColegio(array $colegio, string $idDistrito): void
    {
        School::updateOrCreate(['codMod' => (string) $colegio['codMod']], [
            'idDistrito' => $idDistrito,
            'nombreCenEdu' => $colegio['cenEdu'] ?? null,
            'cenPob' => $colegio['cenPob'] ?? null,
            'dirCen' => $colegio['dirCen'] ?? null,
            'director' => $colegio['director'] ?? null,
            'dis_crecer' => $colegio['dis_crecer'] ?? null,
            'dis_juntos' => $colegio['dis_juntos'] ?? null,
        ]);
    }
}
