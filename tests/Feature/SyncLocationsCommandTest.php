<?php

namespace Tests\Feature;

use App\Models\District;
use App\Models\Province;
use App\Models\Region;
use App\Models\School;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Pruebas del comando sync:locations con la API de ESCALE (MINEDU) simulada.
 * No se realizan peticiones reales al servidor del MINEDU.
 */
class SyncLocationsCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

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
                ],
            ];

            return Http::response(['distrito' => $distritos[$query['idProvincia']]]);
        }

        if (strpos($url, '/padron/rest/instituciones') !== false) {
            switch ($query['ubigeo']) {
                case '150101':
                    return Http::response(['items' => [
                        ['codMod' => '0000001', 'cenEdu' => 'COLEGIO UNO', 'cenPob' => 'LIMA', 'dirCen' => 'AV. UNO 100', 'director' => 'ANA RUIZ'],
                        ['codMod' => '0000002', 'cenEdu' => 'COLEGIO DOS', 'cenPob' => 'LIMA', 'dirCen' => 'AV. DOS 200'],
                    ]]);
                case '150102':
                    return Http::response(['items' => [
                        'codMod' => '0000003', 'cenEdu' => 'COLEGIO TRES', 'cenPob' => 'ANCON', 'dirCen' => 'JR. TRES 300',
                    ]]);
                case '150201':
                    // Distrito sin instituciones: ESCALE no envía la clave "items".
                    return Http::response([]);
                default:
                    // Falla del servidor del MINEDU: el comando debe continuar.
                    return Http::response('Error interno', 500);
            }
        }

        return Http::response('URL no simulada: '.$url, 404);
    }

    public function test_sincroniza_regiones_provincias_distritos_y_colegios(): void
    {
        $this->artisan('sync:locations')->assertExitCode(0);

        $this->assertSame(2, Region::count());
        $this->assertSame(3, Province::count());
        $this->assertSame(4, District::count());
        $this->assertSame(3, School::count());

        $colegio = School::find('0000001');
        $this->assertSame('150101', $colegio->idDistrito);
        $this->assertSame('COLEGIO UNO', $colegio->nombreCenEdu);
        $this->assertSame('ANA RUIZ', $colegio->director);

        $this->assertSame('150102', School::find('0000003')->idDistrito);
    }

    public function test_volver_a_sincronizar_no_duplica_registros(): void
    {
        $this->artisan('sync:locations')->assertExitCode(0);
        $this->artisan('sync:locations')->assertExitCode(0);

        $this->assertSame(2, Region::count());
        $this->assertSame(3, Province::count());
        $this->assertSame(4, District::count());
        $this->assertSame(3, School::count());
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
