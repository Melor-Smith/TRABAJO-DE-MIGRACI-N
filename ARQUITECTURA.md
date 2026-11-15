# Cambios en la arquitectura: PHP 7 / Laravel 7 → PHP 8.3 / Laravel 13

**Autor:** Melor Smith Yanac
**Fecha:** 15/11/2026
**Proyecto:** Bridges Note (API de colegios y ubigeos del MINEDU/ESCALE)

Caso de estudio: qué cambia en la arquitectura de una aplicación Laravel al migrarla de PHP 7 a PHP 8.3.

---

## 1. Por qué cambió la arquitectura

Para migrar a PHP 8.3 no bastaba con cambiar la versión de PHP. **Laravel 7 no funciona desde PHP 8.1**, porque sus clases no cumplen las nuevas reglas de tipos de PHP, por ejemplo en `ArrayAccess`. La versión vigente que soporta PHP 8.3 es **Laravel 13**. Entre Laravel 7 y 13, el framework reorganizó su estructura (Laravel 11 introdujo la "estructura simplificada"), así que el proyecto tuvo que adaptarse a ella.

| | Antes | Después |
|---|---|---|
| PHP | 7.2.5 – 8.0 | 8.3 |
| Laravel | 7.30.7 (sin soporte desde 2021) | 13.42.0 |
| Archivos PHP en `app/` | 25 | 9 |

---

## 2. Recorrido de una petición

Ejemplo: `GET /api/provincias/10` (provincias de Huánuco).

### Antes (Laravel 7)

```
Navegador
   │
   ▼
.htaccess (raíz)  ──►  index.php (raíz del proyecto, junto al .env)
                          │
                          ▼
                    bootstrap/app.php
                    registra 3 piezas propias del proyecto:
                    ├─ App\Http\Kernel           (middleware)
                    ├─ App\Console\Kernel        (comandos)
                    └─ App\Exceptions\Handler    (errores)
                          │
                          ▼
                    App\Http\Kernel
                    ├─ middleware globales (TrustProxies, HandleCors,
                    │  CheckForMaintenanceMode, TrimStrings…)
                    └─ grupo 'api': 'throttle:60,1'
                          │
                          ▼
                    RouteServiceProvider::mapApiRoutes()
                    (agrega el prefijo /api y carga routes/api.php)
                          │
                          ▼
                    LocationController@getProvinces
                    whereRaw('LEFT(idProvincia, 2) = ?')   ← solo MySQL
                          │
                          ▼
                       MySQL
```

### Después (Laravel 13)

```
Navegador
   │
   ▼
public/.htaccess  ──►  public/index.php   (el .env queda fuera del alcance web)
                          │
                          ▼
                    bootstrap/app.php  ── un solo archivo de configuración:
                    Application::configure()
                    ├─ withRouting(api: routes/api.php)   → prefijo /api
                    ├─ withMiddleware(throttleApi())       → límite 60/min
                    └─ withExceptions(JSON en /api/*)      → errores en JSON
                          │
                          ▼
                    Middleware incluidos en el framework
                    (TrustProxies, HandleCors, mantenimiento, TrimStrings…)
                          │
                          ▼
                    LocationController@getProvinces
                    valida '^\d{2}$' + where('idProvincia', 'like', '10%')
                          │
                          ▼
                 MySQL / MariaDB  (o SQLite en las pruebas)
```

**Idea principal:** antes la configuración del arranque estaba **repartida en 15 clases** dentro de `app/` (2 Kernels, 1 Handler, 8 middleware, 4 providers que había que mantener). Ahora está **concentrada en `bootstrap/app.php`** y el resto lo trae el framework. `app/` contiene solo el código propio del negocio.

---

## 3. Cambios por capa

### 3.1 Punto de entrada y seguridad

| Antes | Después | Motivo |
|---|---|---|
| `index.php` y `server.php` en la raíz del proyecto | `public/index.php` | En el proyecto original la raíz era pública: `/.env` (con la contraseña de la base de datos y el `APP_KEY`) podía descargarse desde el navegador |
| `.htaccess` de la raíz enviaba todo a `index.php` | `public/.htaccess` (oficial de Laravel 13) + `.htaccess` de respaldo en la raíz, que bloquea archivos ocultos y redirige a `public/` | Defensa en profundidad si el hosting publica la raíz |
| `$kernel->handle()` + `send()` + `terminate()` | `$app->handleRequest(Request::capture())` | El framework encapsula el ciclo completo |
| `favicon.ico`, `robots.txt`, `web.config` en la raíz | Dentro de `public/` | Solo `public/` es visible desde la web |

### 3.2 Arranque (bootstrap)

| Antes | Después |
|---|---|
| `bootstrap/app.php` creaba la aplicación y registraba 3 singletons apuntando a clases del proyecto | `bootstrap/app.php` usa `Application::configure()` con métodos encadenados (`withRouting`, `withMiddleware`, `withExceptions`) |
| `app/Http/Kernel.php` | **Eliminado** → `withMiddleware()` |
| `app/Console/Kernel.php` | **Eliminado** → los comandos de `app/Console/Commands` se registran solos; `routes/console.php` |
| `app/Exceptions/Handler.php` | **Eliminado** → `withExceptions()`; se agregó que toda ruta `/api/*` responda errores en JSON |
| Los providers se listaban en `config/app.php` | `bootstrap/providers.php` |

### 3.3 Middleware

| Middleware (Laravel 7) | Situación en Laravel 13 |
|---|---|
| `TrustProxies` (requería el paquete `fideloper/proxy`) | Incluido en el framework |
| `HandleCors` (requería `fruitcake/laravel-cors`) | Incluido en el framework |
| `CheckForMaintenanceMode` | Incluido (`PreventRequestsDuringMaintenance`) |
| `TrimStrings`, `EncryptCookies`, `VerifyCsrfToken`, `TrustHosts` | Incluidos en el framework |
| `Authenticate`, `RedirectIfAuthenticated` | Incluidos en el framework |
| `'throttle:60,1'` en el grupo `api` | `RateLimiter::for('api')` en `AppServiceProvider` + `throttleApi()`. Mismo límite: 60 peticiones/minuto por IP |

Resultado: **la carpeta `app/Http/Middleware` desaparece** (8 archivos menos) y el proyecto deja de depender de 2 paquetes externos.

### 3.4 Service providers

| Antes | Después |
|---|---|
| `AppServiceProvider` | Se mantiene y ahora define el límite de peticiones de la API |
| `RouteServiceProvider` (prefijo `/api`, grupo `api`, límite) | **Eliminado** → `withRouting()` |
| `AuthServiceProvider`, `EventServiceProvider`, `BroadcastServiceProvider` | **Eliminados**: estaban vacíos o con valores por defecto; Laravel 13 descubre eventos y políticas automáticamente |

### 3.5 Rutas

| Antes | Después |
|---|---|
| `routes/api.php` con las 4 rutas + `/api/user` protegida con `auth:api` | Solo las 4 rutas del negocio |
| El guard `api` por token existía en `config/auth.php` | La configuración de Laravel 13 ya no trae el guard `api` por token (se usa Sanctum si se necesita). `/api/user` no se usaba, así que se eliminó |
| — | Nueva ruta `/up` para monitoreo (health check) |

### 3.6 Capa de datos

| Antes | Después | Motivo |
|---|---|---|
| `app/User.php` | `app/Models/User.php` | Todos los modelos en `app/Models` |
| `app/Region.php` vacío y duplicado | **Eliminado** | Ya existía `app/Models/Region.php` |
| Migraciones como clases con nombre (`class CreateSchoolsTable`), una protegida con `if (!class_exists(...))` | Migraciones como **clases anónimas** (`return new class extends Migration`) | Evita choques de nombres; el `class_exists` ya no es necesario |
| `database/seeds/` sin namespace | `database/seeders/` con namespace `Database\Seeders` | Carga automática con PSR-4 |
| `UserFactory` como función `$factory->define()` | Clase `UserFactory extends Factory` | Formato de factories desde Laravel 8 |
| `whereRaw('LEFT(idProvincia, 2) = ?')` | `where('idProvincia', 'like', '10%')` con validación previa del formato | `LEFT()` solo existe en MySQL; `LIKE` funciona en cualquier motor, aprovecha el índice de la llave primaria y permite pruebas con SQLite |

Los modelos `Region`, `Province`, `District` y `School` **no cambiaron**.

### 3.7 Integración con el MINEDU (comando `sync:locations`)

La migración no cambiaba esta parte, pero al probarla con datos reales aparecieron dos fallas del diseño original:

| Antes | Después |
|---|---|
| Una sola consulta por distrito: ESCALE devuelve **máximo 50 colegios** si no se indica `max`, por lo que los distritos grandes quedaban incompletos | Paginación con `start`/`max` (páginas de 500) |
| Un corte de conexión del MINEDU (`cURL error 56`) lanzaba una excepción y **detenía toda la sincronización** | Método `escale()` centralizado: tiempo máximo de 60 s, 3 reintentos con 2 s de espera y, si aún falla, se registra el error y continúa con el siguiente distrito |
| La URL de ESCALE se repetía en 4 métodos | Una sola función (`escale()`) arma todas las consultas |

Resultado verificado: sincronización completa del país sin errores: **25 regiones, 196 provincias, 1,892 distritos y 115,086 colegios**.

### 3.8 Frontend (herramientas de compilación)

| Antes | Después |
|---|---|
| Laravel Mix (webpack) con `webpack.mix.js`, Sass, axios y lodash | **Vite** (`vite.config.js`) |
| `resources/sass`, `resources/js/bootstrap.js` | `resources/css/app.css`, `resources/js/app.js` |

La API no usa vistas: solo `welcome.blade.php` en `/`. Este cambio sigue el estándar de Laravel 13 y no afecta los endpoints.

### 3.9 Pruebas

| Antes | Después |
|---|---|
| Solo los ejemplos de Laravel (`ExampleTest`) | **17 pruebas, 78 verificaciones** |
| Sin forma de comprobar los endpoints sin MySQL ni internet | Base SQLite en memoria; el servidor del MINEDU se simula con `Http::fake()` |
| `tests/CreatesApplication.php` | Integrado en el framework (eliminado) |
| PHPUnit 8/9 | PHPUnit 12 |

---

## 4. Dependencias

| Paquete | Antes | Después |
|---|---|---|
| `php` | `^7.2.5\|^8.0` | `^8.3` |
| `laravel/framework` | `^7.29` | `^13.17` |
| `laravel/tinker` | `^2.5` | `^3.0` |
| `guzzlehttp/guzzle` | Declarado | Lo incluye Laravel |
| `fideloper/proxy` | `^4.4` | **Eliminado** (incluido en el framework) |
| `fruitcake/laravel-cors` | `^2.0` | **Eliminado** (incluido en el framework) |
| `facade/ignition` | `^2.0` | **Eliminado** (abandonado) |
| `phpunit/phpunit` | `^8.5.8\|^9.3.3` | `^12.5` |
| `nunomaduro/collision` | `^4.3` | `^8.6` |
| `laravel/pint` | — | `^1.27` (formateador de código) |

---

## 5. Características de PHP 8 aprovechadas en el código

| Característica | Versión | Dónde se usa |
|---|---|---|
| Argumentos con nombre | 8.0 | `retry(3, 2000, throw: false)` en `SyncLocations`; `Application::configure(basePath: ...)` |
| Operador nullsafe `?->` | 8.0 | `$request->user()?->id` en `AppServiceProvider` |
| Tipos de retorno `void` y `?Response` | 7.1 / 8.0 | `escale(): ?Response`, `boot(): void` |
| Propiedades tipadas | 7.4 | `private array $intentos` en las pruebas |
| Funciones flecha `fn` | 7.4 | `withExceptions()` en `bootstrap/app.php` |
| Constantes de clase privadas | 7.1 | `private const SCHOOLS_PAGE_SIZE` |

---

## 6. Lo que NO cambió (contrato de la API)

La migración es transparente para los clientes que consumen la API:

- Las mismas 4 rutas: `/api/regiones`, `/api/provincias/{idRegion}`, `/api/distritos/{idProvincia}`, `/api/colegios/{ubigeo}`.
- El mismo formato JSON: `{"status": "success", "data": [...]}` y `{"status": "error", "message": "..."}` con código 404.
- El mismo límite de 60 peticiones por minuto.
- Las mismas tablas: `regions`, `provinces`, `districts`, `schools`.
- El mismo comando: `php artisan sync:locations`.

Las pruebas de `tests/Feature/LocationApiTest.php` se escribieron **antes** de migrar (commit `test: pruebas de contrato de la API`), a partir del contrato documentado en el README, y pasan con la versión migrada. Eso comprueba que el comportamiento se conservó.

---

## 7. Estructura de carpetas

```
ANTES (Laravel 7)                       DESPUÉS (Laravel 13)
─────────────────                       ────────────────────
app/                                    app/
├─ Console/                             ├─ Console/
│  ├─ Commands/SyncLocations.php        │  └─ Commands/SyncLocations.php
│  └─ Kernel.php            ✗           │
├─ Exceptions/Handler.php   ✗           │
├─ Http/                                ├─ Http/
│  ├─ Controllers/                      │  └─ Controllers/
│  ├─ Kernel.php            ✗           │
│  └─ Middleware/ (8)       ✗           │
├─ Models/ (4)                          ├─ Models/ (4 + User)
├─ Providers/ (5)                       ├─ Providers/AppServiceProvider.php
├─ Region.php               ✗           │
└─ User.php                 → Models/   │
bootstrap/app.php                       bootstrap/
                                        ├─ app.php        (configuración central)
                                        └─ providers.php  ★
database/seeds/             → seeders/  database/seeders/
index.php  server.php       ✗           public/            ★
webpack.mix.js              ✗             ├─ index.php
favicon.ico robots.txt      → public/     └─ .htaccess
                                        vite.config.js     ★
tests/ (solo ejemplos)                  tests/ (17 pruebas: API + sync:locations)

✗ eliminado   → movido   ★ nuevo
```

---

## 8. Limitación pendiente del diseño original

Un colegio con **anexo** comparte el mismo código modular (`codMod`) que el local principal, con `anexo = 1`. Como la llave primaria de `schools` es solo `codMod`, el anexo sobrescribe al principal al sincronizar. Corregirlo requiere una llave compuesta `codMod + anexo`, lo que **cambiaría el formato de la API**. Por eso queda fuera del alcance de esta migración, cuyo objetivo es conservar el contrato.
