<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Quita la cabecera "X-Powered-By: PHP/8.x.y" que PHP agrega a cada respuesta
 * cuando expose_php = On (valor por defecto, incluso en php.ini-production).
 *
 * Anunciar la versión exacta le indica a un atacante qué fallas conocidas
 * probar. En producción conviene además poner expose_php = Off en php.ini.
 */
class OcultarVersionDePhp
{
    public function handle(Request $request, Closure $next): Response
    {
        header_remove('X-Powered-By');

        return $next($request);
    }
}
