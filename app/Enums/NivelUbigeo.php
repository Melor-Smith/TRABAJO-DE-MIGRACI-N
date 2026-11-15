<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Niveles del código de ubigeo del INEI.
 *
 * Cada nivel agrega 2 dígitos al código del nivel superior:
 * 15 (región Lima) → 1501 (provincia de Lima) → 150101 (distrito de Lima).
 * El valor de cada caso es la cantidad de dígitos de su código.
 */
enum NivelUbigeo: int
{
    case Region = 2;
    case Provincia = 4;
    case Distrito = 6;

    /**
     * Indica si el código tiene exactamente los dígitos de este nivel.
     * Rechaza los códigos incompletos y los comodines de SQL (% y _).
     * Se usa \z y no $, porque $ también acepta un salto de línea al final.
     */
    public function admite(string $codigo): bool
    {
        return preg_match('/^\d{'.$this->value.'}\z/', $codigo) === 1;
    }
}
