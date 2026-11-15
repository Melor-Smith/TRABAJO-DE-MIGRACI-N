<?php

namespace Tests\Feature;

use App\Models\District;
use App\Models\Province;
use App\Models\Region;
use App\Models\School;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Tests\TestCase;

/**
 * Pruebas del comando sync:locations con la API de ESCALE (MINEDU) simulada.
 * No se realizan peticiones reales al servidor del MINEDU.
 */
class SyncLocationsCommandTest extends TestCase
{
    use RefreshDatabase;

    /** Intentos recibidos por distrito, para simular cortes de conexión. */
    private array $intentos = [];

    protected function setUp(): void
    {
        parent::setUp();

        // Los reintentos no esperan de verdad durante las pruebas.
        Sleep::fake();

        Http::fake(function (Request $request) {
            return $this->respuestaEscale($request);
        });
    }

    /**
     * Simula las respuestas de escale.minedu.gob.pe/padron/rest.
     * ESCALE devuelve un objeto (no una lista) cuando hay un solo resultado;
     * ese caso se cubre con la región 10, la provincia 1502 y el distrito 150102.
     */
    private function respuestaEscale(Request $request)
    {
        $url = $request->url();
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

        if (strpos($url, '/padron/rest/regiones') !== false) {
            return Http::response(['region' => [
                ['idRegion' => '15', 'nombreRegion' => 'LIMA', 'pointX' => -12.046374, 'pointY' => -77.042793, 'zoom' => 10],
                ['idRegion' => '10', 'nombreRegion' => 'HUANUCO', 'pointX' => -9.93, 'pointY' => -76.24, 'zoom' => 9],
            ]]);
        }

        if (strpos($url, '/padron/rest/provincias') !== false) {
            $provincias = [
                '15' => [
                    ['idProvincia' => '1501', 'nombreProvincia' => 'LIMA', 'pointX' => -12.04, 'pointY' => -77.04],
                    ['idProvincia' => '1502', 'nombreProvincia' => 'BARRANCA', 'pointX' => -10.75, 'pointY' => -77.76],
                ],
                '10' => ['idProvincia' => '1001', 'nombreProvincia' => 'HUANUCO', 'pointX' => -9.93, 'pointY' => -76.24],
            ];

            return Http::response(['provincia' => $provincias[$query['idRegion']]]);
        }

        if (strpos($url, '/padron/rest/distritos') !== false) {
            $distritos = [
                '1501' => [
                    ['idDistrito' => '150101', 'nombreDistrito' => 'LIMA'],
                    ['idDistrito' => '150102', 'nombreDistrito' => 'ANCON'],
                ],
                '1502' => ['idDistrito' => '150201', 'nombreDistrito' => 'BARRANCA'],
                '1001' => [
                    ['idDistrito' => '100101', 'nombreDistrito' => 'HUANUCO'],
                    ['idDistrito' => '100102', 'nombreDistrito' => 'AMARILIS'],
                ],
            ];

            return Http::response(['distrito' => $distritos[$query['idProvincia']]]);
        }

        if (strpos($url, '/padron/rest/instituciones') !== false) {
            $ubigeo = $query['ubigeo'];
            $this->intentos[$ubigeo] = ($this->intentos[$ubigeo] ?? 0) + 1;

            // El MINEDU corta la conexión siempre en 100101 y solo la primera vez en 100102.
            if ($ubigeo === '100101' || ($ubigeo === '100102' && $this->intentos[$ubigeo] === 1)) {
                return Http::failedConnection('cURL error 56: Recv failure: Connection was reset')($request);
            }

            $colegiosPorDistrito = [
                // 501 colegios: obliga a leer una página de 500 y otra de 1 (objeto)
                '150101' => $this->colegios(501),
                '150102' => [
                    ['codMod' => '0900001', 'cenEdu' => 'COLEGIO ANCON', 'cenPob' => 'ANCON', 'dirCen' => 'JR. TRES 300'],
                ],
                // Distrito sin instituciones
                '150201' => [],
                '100102' => [
                    ['codMod' => '0800001', 'cenEdu' => 'COLEGIO AMARILIS', 'cenPob' => 'AMARILIS', 'dirCen' => 'JR. HUALLAGA 10'],
                ],
            ];

            if (! array_key_exists($query['ubigeo'], $colegiosPorDistrito)) {
                // Falla del servidor del MINEDU: el comando debe continuar.
                return Http::response('Error interno', 500);
            }

            // Igual que ESCALE: 50 colegios por defecto, paginación con start/max
            $pagina = array_slice(
                $colegiosPorDistrito[$query['ubigeo']],
                (int) ($query['start'] ?? 0),
                (int) ($query['max'] ?? 50)
            );

            if (count($pagina) === 0) {
                // Sin resultados (o pasado el final): ESCALE no envía la clave "items".
                return Http::response(['@uri' => 'https://escale.minedu.gob.pe/padron/rest/instituciones']);
            }

            // Un solo colegio llega como objeto, no como lista.
            return Http::response(['items' => count($pagina) === 1 ? $pagina[0] : $pagina]);
        }

        return Http::response('URL no simulada: '.$url, 404);
    }

    private function colegios(int $cantidad): array
    {
        $colegios = [];
        for ($i = 1; $i <= $cantidad; $i++) {
            $colegios[] = [
                'codMod' => sprintf('%07d', $i),
                'cenEdu' => "COLEGIO $i",
                'cenPob' => 'LIMA',
                'dirCen' => "AV. LIMA $i",
                'director' => $i === 1 ? 'ANA RUIZ' : null,
            ];
        }

        return $colegios;
    }

    public function test_sincroniza_regiones_provincias_distritos_y_colegios(): void
    {
        $this->artisan('sync:locations')->assertExitCode(0);

        $this->assertSame(2, Region::count());
        $this->assertSame(3, Province::count());
        $this->assertSame(5, District::count());
        $this->assertSame(503, School::count());

        $colegio = School::find('0000001');
        $this->assertSame('150101', $colegio->idDistrito);
        $this->assertSame('COLEGIO 1', $colegio->nombreCenEdu);
        $this->assertSame('ANA RUIZ', $colegio->director);

        $this->assertSame('150102', School::find('0900001')->idDistrito);
    }

    public function test_descarga_todas_las_paginas_de_colegios_de_un_distrito(): void
    {
        $this->artisan('sync:locations')->assertExitCode(0);

        // ESCALE solo devuelve 50 por defecto: deben guardarse los 501
        $this->assertSame(501, School::where('idDistrito', '150101')->count());
        $this->assertNotNull(School::find('0000501'), 'Falta el colegio de la segunda página');

        Http::assertSent(function (Request $request) {
            return strpos($request->url(), 'ubigeo=150101') !== false
                && strpos($request->url(), 'start=500') !== false;
        });
    }

    public function test_un_corte_de_conexion_no_detiene_la_sincronizacion(): void
    {
        // 100101 falla en todos los intentos; 100102 falla una vez y luego responde.
        $this->artisan('sync:locations')
            ->expectsOutputToContain('Sin conexión con ESCALE (instituciones)')
            ->assertExitCode(0);

        // El reintento recupera el colegio de 100102...
        $this->assertSame('100102', School::find('0800001')?->idDistrito);
        $this->assertSame(2, $this->intentos['100102']);

        // ...y 100101 se intenta 3 veces antes de pasar al siguiente distrito.
        $this->assertSame(3, $this->intentos['100101']);
        $this->assertSame(0, School::where('idDistrito', '100101')->count());
    }

    public function test_volver_a_sincronizar_no_duplica_registros(): void
    {
        $this->artisan('sync:locations')->assertExitCode(0);
        $this->artisan('sync:locations')->assertExitCode(0);

        $this->assertSame(2, Region::count());
        $this->assertSame(3, Province::count());
        $this->assertSame(5, District::count());
        $this->assertSame(503, School::count());
    }

    public function test_consulta_los_endpoints_oficiales_de_escale(): void
    {
        $this->artisan('sync:locations')->assertExitCode(0);

        Http::assertSent(function (Request $request) {
            return strpos($request->url(), 'https://escale.minedu.gob.pe/padron/rest/instituciones') === 0
                && strpos($request->url(), 'estados=1') !== false
                && strpos($request->url(), 'ubigeo=150101') !== false;
        });
    }
}
