<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Routing\Route as Ruta;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Portada del servicio y ruta de monitoreo.
 */
class InicioTest extends TestCase
{
    public function test_la_portada_documenta_exactamente_las_rutas_reales_de_la_api(): void
    {
        $documentadas = collect($this->getJson('/')->assertOk()->json('consultas'))
            ->map(fn (array $consulta) => ltrim($consulta['ruta'], '/'))
            ->sort()
            ->values()
            ->all();

        $reales = collect(Route::getRoutes()->getRoutes())
            ->map(fn (Ruta $ruta) => $ruta->uri())
            ->filter(fn (string $uri) => str_starts_with($uri, 'api/'))
            ->sort()
            ->values()
            ->all();

        // Si alguien agrega o cambia una ruta de la API, la portada debe actualizarse
        $this->assertSame($reales, $documentadas);
    }

    public function test_cada_consulta_de_la_portada_trae_un_ejemplo_listo_para_abrir(): void
    {
        $this->getJson('/')
            ->assertOk()
            ->assertJsonPath('servicio', 'Bridges Note')
            ->assertJsonPath('consultas.1.ejemplo', url('/api/provincias/10'))
            ->assertJsonPath('consultas.3.ejemplo', url('/api/colegios/100101'))
            ->assertJsonPath('estado', url('/up'));
    }

    public function test_la_portada_no_revela_versiones_del_servidor(): void
    {
        $contenido = $this->get('/')->assertOk()->getContent();

        $this->assertStringNotContainsString(PHP_VERSION, $contenido);
        $this->assertStringNotContainsString(app()->version(), $contenido);
        $this->assertStringNotContainsStringIgnoringCase('laravel', $contenido);
    }

    public function test_la_ruta_de_monitoreo_up_confirma_que_el_servicio_funciona(): void
    {
        $this->get('/up')->assertOk();
    }
}
