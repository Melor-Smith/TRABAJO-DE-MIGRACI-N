# Plan de trabajo: migración de Bridges Note a PHP 8.3

**Autor:** Melor Smith Yanac
**Fecha:** 14/11/2026
**Fecha de entrega:** lunes 16/11/2026
**Proyecto base:** https://github.com/hubelsolis/brigesnote
**Repositorio de entrega:** https://github.com/Melor-Smith/TRABAJO-DE-MIGRACI-N

---

## 1. Objetivo

Migrar la API "Bridges Note" (catálogo de colegios y ubigeos del MINEDU/ESCALE) para que funcione en **PHP 8.3**, manteniendo exactamente el mismo comportamiento de sus 4 endpoints y del comando `sync:locations`.

## 2. Diagnóstico inicial

| Aspecto | Estado actual | Problema |
|---|---|---|
| Framework | Laravel 7.30.7 | No funciona en PHP 8.1 o superior (error de `ArrayAccess`) |
| PHP declarado | `^7.2.5\|^8.0` | No admite 8.3 |
| Pruebas | Solo los ejemplos de Laravel | No hay forma de comprobar que la migración no rompe la API |
| Consultas SQL | `LEFT(idProvincia, 2)` | Función exclusiva de MySQL |
| Punto de entrada | `index.php` en la raíz, sin carpeta `public/` | El archivo `.env` (credenciales) puede quedar accesible desde el navegador |
| Archivos sobrantes | `app/Region.php` vacío y duplicado | Confusión con `app/Models/Region.php` |
| `.env.example` | `DB_DATABASE=laravel` | El README indica `escalebridge` |

**Conclusión:** no basta con cambiar la versión de PHP. Hay que subir Laravel 7 a **Laravel 13**, la versión vigente, que exige PHP 8.3 como mínimo.

## 3. Requisitos del entorno

| Herramienta | Versión | Estado en el equipo de trabajo |
|---|---|---|
| PHP | 8.3.36 Thread Safe x64 | Por instalar (hay PHP 8.2.12 en XAMPP y 8.1.10 en Laragon) |
| Composer | 2.x | Instalado (2.10.3) |
| MySQL / MariaDB | 5.7+ / 10.3+ | Instalado (XAMPP) |
| Git | 2.x | Instalado |

**Extensiones de PHP que se deben habilitar en `php.ini`:** `curl`, `fileinfo`, `mbstring`, `openssl`, `pdo_mysql`, `pdo_sqlite` (para las pruebas), `zip` (para Composer) e `intl`.

**Instalación de PHP 8.3 en Laragon:**
1. Descargar `php-8.3.36-1-Win32-vs16-x64.zip` desde https://windows.php.net/download
   (SHA-256: `126dd3cc8c19df5085b9420b2bc74ed591de3d98528858c52fc42739b7bba92e`).
2. Descomprimir en `C:\laragon\bin\php\php-8.3.36-Win32-vs16-x64`.
3. Copiar `php.ini-development` como `php.ini`, descomentar `extension_dir = "ext"` y las extensiones de la lista anterior.
4. En Laragon: Menú > PHP > Versión > seleccionar 8.3.36.
5. Verificar con `php -v` y `php -m`.

## 4. Inventario de dependencias

Versiones verificadas en Packagist el 14/11/2026. Algunas versiones más recientes (PHPUnit 13, Ignition 2.13) ya exigen PHP 8.4, por eso se fija la plataforma en 8.3 dentro de `composer.json` (`config.platform.php = 8.3.0`).

### Dependencias de producción

| Paquete | Actual | Nueva | Acción |
|---|---|---|---|
| `php` | `^7.2.5\|^8.0` | `^8.3` | Actualizar |
| `laravel/framework` | `^7.29` | `^13.0` | Actualizar |
| `laravel/tinker` | `^2.5` | `^3.0` | Actualizar |
| `guzzlehttp/guzzle` | `^6.3.1\|^7.0.1` | (incluido en Laravel) | Quitar del `require`; Laravel ya lo incluye para el cliente HTTP |
| `fideloper/proxy` | `^4.4` | — | **Eliminar**: Laravel trae `TrustProxies` incluido |
| `fruitcake/laravel-cors` | `^2.0` | — | **Eliminar**: Laravel trae `HandleCors` incluido |

### Dependencias de desarrollo

| Paquete | Actual | Nueva | Acción |
|---|---|---|---|
| `phpunit/phpunit` | `^8.5.8\|^9.3.3` | `^12.5` | Actualizar |
| `nunomaduro/collision` | `^4.3` | `^8.6` | Actualizar |
| `mockery/mockery` | `^1.3.1` | `^1.6` | Actualizar |
| `fakerphp/faker` | `^1.9.1` | `^1.23` | Actualizar |
| `facade/ignition` | `^2.0` | — | **Eliminar**: abandonado; Laravel 13 ya tiene su propia página de errores |
| `laravel/pint` | — | `^1.27` | **Agregar**: formateador de código (estándar PSR-12) |

## 5. Cambios de código necesarios

| Archivo | Cambio |
|---|---|
| `app/Http/Middleware/TrustProxies.php` | Dejar de usar `Fideloper\Proxy` y la constante eliminada `HEADER_X_FORWARDED_ALL` |
| `app/Http/Kernel.php` | Quitar `Fruitcake\Cors\HandleCors` y renombrar `CheckForMaintenanceMode` a `PreventRequestsDuringMaintenance` |
| `app/User.php` | Mover a `app/Models/User.php` |
| `database/seeds/` | Renombrar a `database/seeders/` con namespace `Database\Seeders` |
| `database/factories/UserFactory.php` | Pasar al formato de clase (`Factory`) |
| `app/Providers/RouteServiceProvider.php` | Quitar `$namespace` y adaptar el registro de rutas |
| `config/*.php` | Actualizar a los valores de Laravel 13 |
| Migraciones | Pasar a clases anónimas y quitar el `class_exists` de `schools` |
| `app/Http/Controllers/LocationController.php` | Cambiar `LEFT(...)` por `LIKE '15%'`, que funciona en cualquier base de datos y usa el índice |
| `app/Region.php` | Eliminar (duplicado vacío) |
| Raíz del proyecto | Crear `public/` con `index.php` y `.htaccess`, para que `.env` no quede expuesto |

## 6. Secuencia de commits

Cada paso es un commit independiente, para poder revisarlo o revertirlo por separado.

| # | Commit | Contenido |
|---|---|---|
| 0 | `docs: plan de migración a PHP 8.3` | Este documento |
| 1 | `test: pruebas de contrato de la API` | Pruebas de los 4 endpoints y del comando `sync:locations`, con el servidor del MINEDU simulado (`Http::fake()`) |
| 2 | `chore: actualizar dependencias a PHP 8.3 y Laravel 13` | `composer.json`, `composer.lock`, plataforma fijada en 8.3 |
| 3 | `refactor: adaptar estructura a Laravel 13` | Middleware, `User`, factories, seeders, providers y configuración |
| 4 | `fix: consultas compatibles con cualquier motor de base de datos` | `LEFT()` por `LIKE` |
| 5 | `security: mover el punto de entrada a public/` | `.env` fuera del alcance del navegador |
| 6 | `docs: actualizar README y .env.example` | Requisitos de PHP 8.3, base `escalebridge` |
| 7 | Push | Rama `main` del repositorio de entrega |

## 7. Criterios de aceptación

- [ ] `php -v` muestra PHP 8.3.x.
- [ ] `composer install` termina sin errores.
- [ ] `php artisan migrate` crea las tablas `regions`, `provinces`, `districts` y `schools`.
- [ ] `php artisan test` pasa todas las pruebas.
- [ ] Los 4 endpoints (`/api/regiones`, `/api/provincias/{id}`, `/api/distritos/{id}`, `/api/colegios/{ubigeo}`) devuelven el mismo formato JSON documentado en el README.
- [ ] `php artisan sync:locations` descarga datos reales del MINEDU sin errores.
- [ ] El archivo `.env` no es accesible desde el navegador.

## 8. Riesgos

| Riesgo | Mitigación |
|---|---|
| Salto grande de versión (7 a 13) | El proyecto es pequeño (1 controlador, 1 comando, 4 modelos); las pruebas del commit 1 verifican que el comportamiento no cambia |
| No se puede ejecutar el proyecto original para compararlo (requiere PHP 7.4/8.0) | Las pruebas se basan en el contrato documentado en el README |
| El servidor del MINEDU puede estar lento o caído | Las pruebas usan respuestas simuladas; la sincronización real se prueba una sola vez al final |
| Una dependencia nueva exige PHP 8.4 | Plataforma fijada en 8.3 en `composer.json` |
