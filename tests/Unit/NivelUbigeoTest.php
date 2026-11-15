<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Enums\NivelUbigeo;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Validación de los códigos de ubigeo que reciben los endpoints.
 */
class NivelUbigeoTest extends TestCase
{
    public static function codigosValidos(): array
    {
        return [
            'región Huánuco' => [NivelUbigeo::Region, '10'],
            'provincia Leoncio Prado' => [NivelUbigeo::Provincia, '1006'],
            'distrito Rupa-Rupa' => [NivelUbigeo::Distrito, '100601'],
        ];
    }

    public static function codigosInvalidos(): array
    {
        return [
            'región incompleta' => [NivelUbigeo::Region, '1'],
            'región con dígitos de más' => [NivelUbigeo::Region, '100'],
            'comodín de un carácter' => [NivelUbigeo::Region, '1_'],
            'comodín de varios caracteres' => [NivelUbigeo::Provincia, '10%'],
            'letras' => [NivelUbigeo::Provincia, 'ab01'],
            'distrito vacío' => [NivelUbigeo::Distrito, ''],
            'distrito con salto de línea' => [NivelUbigeo::Distrito, "100601\n"],
        ];
    }

    #[DataProvider('codigosValidos')]
    public function test_admite_codigos_con_los_digitos_del_nivel(NivelUbigeo $nivel, string $codigo): void
    {
        $this->assertTrue($nivel->admite($codigo));
    }

    #[DataProvider('codigosInvalidos')]
    public function test_rechaza_codigos_mal_formados(NivelUbigeo $nivel, string $codigo): void
    {
        $this->assertFalse($nivel->admite($codigo));
    }
}
