# Registro de decisiones de arquitectura

**Proyecto:** Bridges Note, migración de PHP 7 / Laravel 7 a PHP 8.3 / Laravel 13
**Autor:** Melor Smith Yanac
**Fecha:** 15/11/2026

`ARQUITECTURA.md` describe **qué cambió**. Este documento explica **por qué**: qué opciones había en cada punto, cuál se eligió, cuál se descartó y qué costo se aceptó.

## Formato

Cada decisión se escribe como un **Y-statement**, el formato propuesto por Zdun, Capilla, Tran y Zimmermann (*Sustainable Architectural Design Decisions*, IEEE Software, 2013). Obliga a nombrar la alternativa descartada y el costo aceptado:

> **En el contexto de** [situación], **frente a** [problema], **se decidió** [opción elegida] **y se descartó** [alternativa], **para lograr** [beneficio], **aceptando que** [costo o riesgo].

Cada decisión indica también su **evidencia**: el commit que la aplica y la prueba automática que la verifica.

## Resumen

| N° | Decisión | Alternativa descartada | Se verifica con |
|---|---|---|---|
| DA-01 | Laravel 13 como destino | Laravel 10, más parecido al original | `composer audit`: 0 avisos de seguridad |
| DA-02 | Migrar paso a paso sobre el mismo repositorio | Esqueleto nuevo de Laravel 13 y copiar el código encima | Historial de Git con un commit por cambio |
| DA-03 | Escribir las pruebas de contrato antes de migrar | Probar a mano al terminar | `LocationApiTest` |
| DA-04 | Fijar la plataforma de Composer en PHP 8.3 | Generar `composer.lock` con el PHP del equipo | GitHub Actions en PHP 8.3, 8.4 y 8.5 |
| DA-05 | Filtrar ubigeos con `LIKE 'prefijo%'` validado por un enum | `LEFT()` original, o `SUBSTR()` | `NivelUbigeoTest` y pruebas de comodines |
| DA-06 | Reducir lo que el servidor deja ver | Raíz del proyecto pública y versiones visibles | `InicioTest`, `/.env` responde 404 |
| DA-07 | Sincronización paginada, con reintentos y protección contra repeticiones | Páginas de 50, o un tope fijo como única protección | `SyncLocationsCommandTest` |
| DA-08 | Conservar el contrato exacto de la API | Corregir los anexos o validar en la ruta, cambiando las respuestas | Prueba de las 4 rutas exactas |

---

## DA-01 · Laravel 13 como destino

**Estado:** vigente · **Evidencia:** commit `f633a61`; `composer audit` sin avisos

> **En el contexto de** una API en Laravel 7 que debe funcionar en PHP 8.3,
> **frente a** que Laravel 7 no arranca desde PHP 8.1 (error de `ArrayAccess`) y no recibe parches desde 2021,
> **se decidió** subir a **Laravel 13**, la versión vigente,
> **y se descartó** Laravel 10, que habría conservado casi intacta la estructura del original,
> **para lograr** un framework con soporte de seguridad durante los próximos años,
> **aceptando que** hay que adaptar la estructura del proyecto a la de Laravel 11 o superior (`bootstrap/app.php`, sin Kernels).

Laravel 10 dejó de recibir parches de seguridad en febrero de 2025. Elegirlo habría dejado la API migrada, pero con avisos de seguridad conocidos que nadie va a corregir.

## DA-02 · Migrar paso a paso sobre el mismo repositorio

**Estado:** vigente · **Evidencia:** commits `ebc9e4b` a `605104c` (un paso por commit)

> **En el contexto de** un caso de estudio cuyo objetivo es **ver qué cambia en la arquitectura**,
> **frente a** dos formas de llegar a Laravel 13: transformar el proyecto existente o crear uno nuevo y copiar el código propio,
> **se decidió** transformar el **mismo repositorio**, con un commit por cada tipo de cambio (dependencias, estructura, consultas, punto de entrada),
> **y se descartó** partir de un esqueleto limpio de Laravel 13,
> **para lograr** que cada cambio de arquitectura se pueda revisar por separado con `git show` (por ejemplo, el commit `a3ccd64` muestra la desaparición de los Kernels, los middleware y los providers),
> **aceptando** más trabajo manual: traer cada archivo del esqueleto nuevo y adaptarlo uno por uno.

## DA-03 · Pruebas de contrato antes de migrar

**Estado:** vigente · **Evidencia:** commit `ebc9e4b`, anterior a cualquier cambio de código

> **En el contexto de** una migración de seis versiones mayores del framework,
> **frente a** la imposibilidad de ejecutar el proyecto original (exige PHP 7.4 / 8.0) para comparar respuestas,
> **se decidió** escribir primero pruebas automáticas a partir del contrato documentado en el README (rutas, formato JSON, códigos 200/404),
> **y se descartó** probar a mano al final,
> **para lograr** una comprobación objetiva de que la API responde igual después de migrar,
> **aceptando que** las pruebas verifican el contrato documentado, no el comportamiento exacto del código antiguo.

## DA-04 · Plataforma de Composer fijada en PHP 8.3

**Estado:** vigente · **Evidencia:** `composer.json` (`config.platform.php`), commits `f633a61` y `c0f882a`

> **En el contexto de** un proyecto que declara `"php": "^8.3"`, es decir 8.3, 8.4 y 8.5,
> **frente a** que Composer elige las versiones de las librerías según el PHP del equipo donde se ejecuta (con 8.5 puede elegir librerías que exigen 8.4 o superior),
> **se decidió** fijar `config.platform.php = 8.3.0`, la versión **mínima** admitida, y comprobarlo con GitHub Actions en las tres versiones,
> **y se descartó** dejar que `composer.lock` dependa del PHP de cada programador,
> **para lograr** un único `composer.lock` que instala en todo el rango declarado,
> **aceptando que** algunas librerías quedan en la última versión compatible con 8.3 y no en la más reciente.

## DA-05 · Filtrar ubigeos con `LIKE` validado por un enum

**Estado:** vigente · **Evidencia:** commits `5fd260a` y `a25da6d`; `NivelUbigeoTest` y pruebas de comodines en `LocationApiTest`

> **En el contexto de** las consultas "provincias de una región" y "distritos de una provincia",
> **frente a** que `LEFT(idProvincia, 2)` solo existe en MySQL e impide probar con SQLite,
> **se decidió** usar `where('idProvincia', 'like', '10%')`, después de validar el código con el enum `NivelUbigeo` (2, 4 o 6 dígitos),
> **y se descartó** `SUBSTR(idProvincia, 1, 2) = ?`, que también funciona en los dos motores,
> **para lograr** que la consulta use el índice de la llave primaria (un `LIKE` con prefijo puede usarlo; una función aplicada a la columna, no), y que `%` o `_` enviados en la URL no funcionen como comodines,
> **aceptando** una pequeña clase más (el enum) que concentra la regla del ubigeo del INEI.

## DA-06 · Reducir lo que el servidor deja ver

**Estado:** vigente · **Evidencia:** commits `605104c` y `8f877b3`; `InicioTest`

> **En el contexto de** un proyecto cuya raíz era pública (`index.php` junto al `.env` con la contraseña de la base de datos),
> **frente a** tres exposiciones: el `.env` descargable, la página de bienvenida de Laravel y la cabecera `X-Powered-By: PHP/8.3.x` que PHP agrega a cada respuesta,
> **se decidió** que solo `public/` sea pública, con un `.htaccess` de respaldo en la raíz que niega los archivos ocultos y bloquea todo si falta `mod_rewrite`; una portada propia que describe la API **sin versiones**; y el middleware `OcultarVersionDePhp`,
> **y se descartó** confiar solo en la configuración del servidor, y mostrar en la portada las versiones de PHP y Laravel,
> **para lograr** que un atacante no obtenga credenciales ni sepa qué versiones atacar,
> **aceptando que** en producción todavía conviene poner `expose_php = Off` en `php.ini`, algo que la aplicación no puede controlar.

## DA-07 · Sincronización paginada, con reintentos y protección contra repeticiones

**Estado:** vigente · **Evidencia:** commits `ea14ec9`, `1398986` y `5afcd0e`; `SyncLocationsCommandTest`

> **En el contexto de** un comando que copia unos 115,000 colegios desde ESCALE (MINEDU),
> **frente a** tres fallas encontradas con datos reales: ESCALE entrega solo 50 colegios si no se indica `max`, corta conexiones de forma intermitente (`cURL error 56`) y, si alguna vez ignorara `start`, el comando podría pedir la misma página sin fin,
> **se decidió** paginar de 500 en 500, reintentar 3 veces con 2 s de espera, continuar con el siguiente distrito si aún falla, **detectar cuando una página empieza con el mismo colegio que la anterior** y, como último respaldo, no pasar de 100 páginas por distrito,
> **y se descartó** usar páginas de 50 (unas 10 veces más consultas al servidor del MINEDU) y confiar solo en un tope fijo de páginas (que dejaría repetir decenas de consultas antes de detenerse),
> **para lograr** una copia completa del padrón, con pocas consultas, que no se cae ante una falla momentánea,
> **aceptando que** un distrito que falla en todos los intentos queda incompleto hasta la siguiente sincronización (el error queda registrado en la salida).

## DA-08 · Conservar el contrato exacto de la API

**Estado:** vigente · **Evidencia:** commit `6b40adb`; pruebas de las 4 rutas exactas y de los mensajes 404

> **En el contexto de** clientes que ya consumen la API (por ejemplo, sistemas de admisión),
> **frente a** mejoras posibles que cambiarían las respuestas: una llave `codMod + anexo` para no perder los anexos, o restricciones en las rutas (`->where('idRegion', '[0-9]{2}')`) que harían responder a Laravel con otro formato de error 404,
> **se decidió** mantener las mismas 4 rutas, el mismo JSON (`status`/`data`, `status`/`message`) y los mismos mensajes, validando los códigos dentro del controlador,
> **y se descartó** cualquier cambio visible para los clientes durante la migración,
> **para lograr** una migración transparente: nadie que use la API tiene que cambiar su código,
> **aceptando que** los colegios con anexo siguen sobrescribiendo al local principal; queda documentado como pendiente en `ARQUITECTURA.md`, sección 9.
