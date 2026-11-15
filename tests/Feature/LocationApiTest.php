<?php

namespace Tests\Feature;

use App\Models\District;
use App\Models\Province;
use App\Models\Region;
use App\Models\School;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Pruebas de contrato de los endpoints REST documentados en el README.
 * Verifican el formato JSON y los códigos HTTP que consumen los clientes.
 */
class LocationApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Region::create(['idRegion' => '15', 'nombreRegion' => 'LIMA', 'pointX' => -12.046374, 'pointY' => -77.042793, 'zoom' => 10]);
        Region::create(['idRegion' => '10', 'nombreRegion' => 'HUANUCO', 'pointX' => -9.930000, 'pointY' => -76.240000, 'zoom' => 9]);

        Province::create(['idProvincia' => '1501', 'nombreProvincia' => 'LIMA']);
        Province::create(['idProvincia' => '1502', 'nombreProvincia' => 'BARRANCA']);
        Province::create(['idProvincia' => '1001', 'nombreProvincia' => 'HUANUCO']);

        District::create(['idDistrito' => '150101', 'nombreDistrito' => 'LIMA']);
        District::create(['idDistrito' => '150102', 'nombreDistrito' => 'ANCON']);
        District::create(['idDistrito' => '100101', 'nombreDistrito' => 'HUANUCO']);

        School::create(['codMod' => '0273572', 'idDistrito' => '150101', 'nombreCenEdu' => 'JUAN JIMENEZ PIMENTEL', 'cenPob' => 'LIMA', 'dirCen' => 'JR. LAMPA 240']);
        School::create(['codMod' => '0300001', 'idDistrito' => '100101', 'nombreCenEdu' => 'LEONCIO PRADO', 'cenPob' => 'HUANUCO', 'dirCen' => 'JR. DOS DE MAYO 100']);
    }

    public function test_lista_todas_las_regiones(): void
    {
        $this->getJson('/api/regiones')
            ->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertJsonCount(2, 'data')
            ->assertJsonStructure(['status', 'data' => [['idRegion', 'nombreRegion', 'pointX', 'pointY', 'zoom']]]);
    }

    public function test_lista_provincias_de_una_region(): void
    {
        $response = $this->getJson('/api/provincias/15')
            ->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertJsonCount(2, 'data');

        $ids = collect($response->json('data'))->pluck('idProvincia')->sort()->values()->all();
        $this->assertSame(['1501', '1502'], $ids);
    }

    public function test_region_sin_provincias_devuelve_404(): void
    {
        $this->getJson('/api/provincias/99')
            ->assertNotFound()
            ->assertExactJson([
                'status' => 'error',
                'message' => 'No se encontraron provincias para esta región.',
            ]);
    }

    public function test_codigo_de_region_incompleto_o_con_comodines_devuelve_404(): void
    {
        // "1" no debe devolver las provincias de las regiones 10 a 19
        $this->getJson('/api/provincias/1')->assertNotFound();
        $this->getJson('/api/provincias/1_')->assertNotFound();
        $this->getJson('/api/provincias/'.rawurlencode('%'))->assertNotFound();
    }

    public function test_codigo_de_provincia_incompleto_o_con_comodines_devuelve_404(): void
    {
        $this->getJson('/api/distritos/15')->assertNotFound();
        $this->getJson('/api/distritos/150_')->assertNotFound();
        $this->getJson('/api/distritos/'.rawurlencode('15%'))->assertNotFound();
    }

    public function test_lista_distritos_de_una_provincia(): void
    {
        $response = $this->getJson('/api/distritos/1501')
            ->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertJsonCount(2, 'data');

        $ids = collect($response->json('data'))->pluck('idDistrito')->sort()->values()->all();
        $this->assertSame(['150101', '150102'], $ids);
    }

    public function test_provincia_sin_distritos_devuelve_404(): void
    {
        $this->getJson('/api/distritos/9999')
            ->assertNotFound()
            ->assertExactJson([
                'status' => 'error',
                'message' => 'No se encontraron distritos para esta provincia.',
            ]);
    }

    public function test_lista_colegios_de_un_distrito(): void
    {
        $this->getJson('/api/colegios/150101')
            ->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.codMod', '0273572')
            ->assertJsonPath('data.0.nombreCenEdu', 'JUAN JIMENEZ PIMENTEL')
            ->assertJsonStructure(['data' => [['codMod', 'idDistrito', 'nombreCenEdu', 'cenPob', 'dirCen', 'director', 'dis_crecer', 'dis_juntos']]]);
    }

    public function test_distrito_sin_colegios_devuelve_404(): void
    {
        $this->getJson('/api/colegios/150102')
            ->assertNotFound()
            ->assertExactJson([
                'status' => 'error',
                'message' => 'No se encontraron escuelas para este distrito.',
            ]);
    }

    public function test_la_api_mantiene_el_limite_de_60_peticiones_por_minuto(): void
    {
        $this->getJson('/api/regiones')
            ->assertOk()
            ->assertHeader('X-RateLimit-Limit', '60');
    }
}
