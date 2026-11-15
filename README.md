# 🏫 Bridges Note - MINEDU ESCALE Bridge API

Microservicio y API REST en **Laravel 13** sobre **PHP 8.3** diseñado para sincronizar, estructurar y servir el catálogo oficial de instituciones educativas (colegios) y la división política/geográfica (regiones, provincias y distritos) del Perú, consumiendo directamente la API pública oficial de **MINEDU / ESCALE** (*Estadística de la Calidad Educativa*).

---

## 📋 Tabla de Contenidos

1. [Propósito del Proyecto](#-propósito-del-proyecto)
2. [Arquitectura y Flujo de Datos](#-arquitectura-y-flujo-de-datos)
3. [Requisitos del Sistema](#-requisitos-del-sistema)
4. [Instalación y Puesta en Marcha](#-instalación-y-puesta-en-marcha)
5. [Configuración de Entorno (.env)](#-configuración-de-entorno-env)
6. [Sincronización de Datos (Consola Artisan)](#-sincronización-de-datos-consola-artisan)
7. [Documentación de la API REST](#-documentación-de-la-api-rest)
8. [Pruebas Automatizadas](#-pruebas-automatizadas)
9. [Estructura del Proyecto](#-estructura-del-proyecto)
10. [Migración a PHP 8.3](#-migración-a-php-83)
11. [Solución de Problemas Frecuentes](#-solución-de-problemas-frecuentes)

---

## 🎯 Propósito del Proyecto

Servir como un **puente de datos optimizado** para aplicaciones cliente (sistemas de admisión universitaria, plataformas de matrícula escolar, intranets, etc.), permitiéndoles autocompletar ubigeos y consultar colegios mediante consultas locales ultrarrápidas, sin depender de la latencia o disponibilidad de los servidores estatales.

---

## 🔄 Arquitectura y Flujo de Datos

```
[ API MINEDU / ESCALE ] 
 (escale.minedu.gob.pe)
          │
          ▼  (Comando: php artisan sync:locations)
[ Base de Datos Local MySQL (escalebridge) ]
  ├── regions
  ├── provinces
  ├── districts
  └── schools (con ubigeo / idDistrito)
          │
          ▼  (LocationController)
[ Endpoints API REST Local / Producción ]
  ├── GET /api/regiones
  ├── GET /api/provincias/{idRegion}
  ├── GET /api/distritos/{idProvince}
  └── GET /api/colegios/{ubigeo}
          │
          ▼
[ Clientes / Aplicaciones Externas ] (ej. admisionweb)
```

---

## 💻 Requisitos del Sistema

- **PHP:** `8.3.x` (probado con 8.3.36 Thread Safe x64).
  - Extensiones requeridas: `ctype`, `curl`, `fileinfo`, `mbstring`, `openssl`, `pdo_mysql`, `tokenizer`.
  - Para las pruebas: `pdo_sqlite`. Para Composer: `zip`.
- **Laravel Framework:** `13.x` (probado con 13.42.0)
- **Gestor de Paquetes:** Composer 2.x
- **Base de Datos:** MySQL 5.7+ / 8.0+ o MariaDB 10.3+ (probado con MariaDB 10.4.32 de XAMPP)
- **Servidor Web Local:** Laragon (con PHP 8.3 Thread Safe - TS), XAMPP o `php artisan serve`.
  > 🔒 El servidor web debe apuntar a la carpeta **`public/`** (DocumentRoot), nunca a la raíz del proyecto.

---

## 🚀 Instalación y Puesta en Marcha

### 1. Clonar el repositorio
```bash
git clone https://github.com/Melor-Smith/TRABAJO-DE-MIGRACI-N.git
cd TRABAJO-DE-MIGRACI-N
```

### 2. Instalar dependencias de Composer
```bash
composer install
```

### 3. Configurar variables de entorno
Copia el archivo de ejemplo y genera la clave de cifrado:
```bash
cp .env.example .env
php artisan key:generate
```

### 4. Crear la Base de Datos
Crea una base de datos vacía en tu gestor MySQL (ej. HeidiSQL o phpMyAdmin):
- **Nombre:** `escalebridge`
- **Cotejamiento:** `utf8mb4_unicode_ci`

### 5. Ejecutar las Migraciones
Crea las tablas en la base de datos:
```bash
php artisan migrate
```

### 6. Sincronizar el catálogo escolar
Descarga la información oficial desde MINEDU:
```bash
php artisan sync:locations
```

### 7. Iniciar el servidor
```bash
php artisan serve
```
La aplicación quedará disponible en `http://127.0.0.1:8000`.

---

## ⚙️ Configuración de Entorno (.env)

Asegúrate de tener estos valores configurados en tu archivo `.env`:

```env
APP_NAME="Bridges Note"
APP_ENV=local
APP_KEY=base64:...
APP_DEBUG=true
APP_URL=http://127.0.0.1:8000

# Conexión MySQL
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=escalebridge
DB_USERNAME=root
DB_PASSWORD=

# Sesión, caché y colas sin tablas en la BD
SESSION_DRIVER=file
CACHE_STORE=file
QUEUE_CONNECTION=sync
```

> ℹ️ Si vienes de un `.env` de Laravel 7, renombra `CACHE_DRIVER` a `CACHE_STORE` y `BROADCAST_DRIVER` a `BROADCAST_CONNECTION`. Las variables `MIX_*` ya no se usan.
>
> ⚠️ Nunca subas el archivo `.env` al repositorio: contiene la clave de la aplicación y las credenciales de la base de datos.

---

## 📥 Sincronización de Datos (Consola Artisan)

El comando central del sistema es:

```bash
php artisan sync:locations
```

### ¿Cómo opera la sincronización?
1. **Regiones (`/padron/rest/regiones`):** Descarga los 25 departamentos del país con sus nombres, coordenadas (`pointX`, `pointY`) y nivel de `zoom`.
2. **Provincias (`/padron/rest/provincias?idRegion={id}`):** Por cada región, descarga sus provincias.
3. **Distritos (`/padron/rest/distritos?idProvincia={id}`):** Por cada provincia, descarga los distritos correspondientes.
4. **Colegios (`/padron/rest/instituciones?estados=1&ubigeo={idDistrito}`):** Por cada distrito, descarga las instituciones educativas activas y las almacena en la tabla `schools` vinculando el código modular (`codMod`) como llave primaria y asociando el `idDistrito`.

> ⏱️ **Nota:** La sincronización total recorre más de 1,800 distritos y 80,000 colegios, por lo que puede tomar varios minutos según la velocidad de conexión y respuesta del servidor del MINEDU.

---

## 📡 Documentación de la API REST

Todos los endpoints retornan respuestas en formato JSON bajo el prefijo `/api/`.

### 1. Listar Regiones / Departamentos
Obtiene todas las regiones del país.

- **URL:** `/api/regiones`
- **Método:** `GET`
- **Respuesta Exitosa (200 OK):**
```json
{
  "status": "success",
  "data": [
    {
      "idRegion": "15",
      "nombreRegion": "LIMA",
      "pointX": -12.046374,
      "pointY": -77.042793,
      "zoom": 10
    }
  ]
}
```

---

### 2. Listar Provincias por Región
Obtiene las provincias pertenecientes a una región (mediante los 2 primeros dígitos del código de departamento).

- **URL:** `/api/provincias/{idRegion}`
- **Método:** `GET`
- **Ejemplo:** `/api/provincias/15` (Provincias de Lima)
- **Respuesta Exitosa (200 OK):**
```json
{
  "status": "success",
  "data": [
    {
      "idProvincia": "1501",
      "nombreProvincia": "LIMA",
      "pointX": -12.046374,
      "pointY": -77.042793
    }
  ]
}
```

---

### 3. Listar Distritos por Provincia
Obtiene los distritos de una provincia (mediante los 4 dígitos de ubigeo provincial).

- **URL:** `/api/distritos/{idProvince}`
- **Método:** `GET`
- **Ejemplo:** `/api/distritos/1501` (Distritos de la provincia de Lima)
- **Respuesta Exitosa (200 OK):**
```json
{
  "status": "success",
  "data": [
    {
      "idDistrito": "150101",
      "nombreDistrito": "LIMA"
    },
    {
      "idDistrito": "150102",
      "nombreDistrito": "ANCON"
    }
  ]
}
```

---

### 4. Listar Colegios por Distrito (Ubigeo)
Obtiene todas las instituciones educativas registradas en un distrito según su ubigeo de 6 dígitos.

- **URL:** `/api/colegios/{ubigeo}`
- **Método:** `GET`
- **Ejemplo:** `/api/colegios/150101`
- **Respuesta Exitosa (200 OK):**
```json
{
  "status": "success",
  "data": [
    {
      "codMod": "0273572",
      "idDistrito": "150101",
      "nombreCenEdu": "JUAN JIMENEZ PIMENTEL",
      "cenPob": "LIMA",
      "dirCen": "JR. LAMPA 240",
      "director": "PEDRO PEREZ",
      "dis_crecer": null,
      "dis_juntos": null
    }
  ]
}
```

---

## 🧪 Pruebas Automatizadas

```bash
php artisan test
```

Las pruebas usan SQLite en memoria (no tocan tu base MySQL) y **no** llaman al servidor del MINEDU: sus respuestas se simulan con `Http::fake()`.

| Archivo | Qué verifica |
|---|---|
| `tests/Feature/LocationApiTest.php` | Formato JSON y códigos 200/404 de los 4 endpoints, rechazo de códigos incompletos o con comodines (`%`, `_`) y límite de 60 peticiones por minuto |
| `tests/Feature/SyncLocationsCommandTest.php` | Sincronización completa, que volver a sincronizar no duplique registros, y el caso en que ESCALE devuelve un objeto en vez de una lista |

---

## 📂 Estructura del Proyecto

```
brigesnote/
├── app/
│   ├── Console/Commands/
│   │   └── SyncLocations.php       # Comando 'sync:locations' (consumo de API ESCALE)
│   ├── Http/Controllers/
│   │   └── LocationController.php  # Controlador de endpoints /api/
│   ├── Models/
│   │   ├── District.php            # Modelo Distritos
│   │   ├── Province.php            # Modelo Provincias
│   │   ├── Region.php              # Modelo Regiones
│   │   ├── School.php              # Modelo Colegios / Escuelas
│   │   └── User.php                # Modelo de usuario (base de Laravel)
│   └── Providers/
│       └── AppServiceProvider.php  # Límite de 60 peticiones/minuto de la API
├── bootstrap/
│   └── app.php                     # Configuración de rutas, middleware y excepciones
├── database/migrations/
│   ├── ..._create_regions_table.php
│   ├── ..._create_provinces_table.php
│   ├── ..._create_districts_table.php
│   └── ..._create_schools_table.php
├── public/                         # Única carpeta expuesta por el servidor web
│   ├── index.php                   # Punto de entrada de la aplicación
│   └── .htaccess
├── routes/
│   └── api.php                     # Definición de rutas REST
├── tests/Feature/                  # Pruebas de la API y de la sincronización
├── .env.example                    # Plantilla de variables de entorno
├── PLAN_MIGRACION.md               # Plan y resultado de la migración a PHP 8.3
├── ARQUITECTURA.md                 # Cambios de arquitectura (antes vs. después)
└── .gitignore                      # Reglas de exclusión para Git
```

---

## ⬆️ Migración a PHP 8.3

El proyecto original funcionaba solo con PHP 7.4 / 8.0 (Laravel 7.30.7). Se migró a **PHP 8.3 + Laravel 13**. El detalle está en [`PLAN_MIGRACION.md`](PLAN_MIGRACION.md); en resumen:

| Cambio | Motivo |
|---|---|
| Laravel 7.30.7 → 13.42.0 | Laravel 7 falla en PHP 8.1+ (`ArrayAccess`) |
| Eliminados `fideloper/proxy`, `fruitcake/laravel-cors` y `facade/ignition` | Ahora están incluidos en el framework o están abandonados |
| Estructura de Laravel 13 (`bootstrap/app.php`) | Reemplaza `Http\Kernel`, `Console\Kernel` y varios providers |
| `LEFT()` → `LIKE 'prefijo%'` | `LEFT()` solo existe en MySQL |
| Punto de entrada en `public/` | Antes el `.env` podía descargarse desde el navegador |
| Ruta `/api/user` eliminada | Usaba el guard por token `api`, que Laravel 13 ya no incluye |
| Pruebas automatizadas | Garantizan que la API responde igual que antes |

La API conserva **exactamente** las mismas rutas y el mismo formato de respuesta.

El análisis completo de cómo cambió la arquitectura (recorrido de una petición, capas, estructura de carpetas y características de PHP 8 usadas) está en [`ARQUITECTURA.md`](ARQUITECTURA.md).

---

## 🛠️ Solución de Problemas Frecuentes

### 1. Error: `Your Composer dependencies require a PHP version ">= 8.3.0"`
- **Causa:** Estás ejecutando el proyecto con una versión de PHP anterior a 8.3 (por ejemplo, la 8.2 que trae XAMPP).
- **Solución:** Instala **PHP 8.3 (Thread Safe)** desde [windows.php.net](https://windows.php.net/download) y selecciónalo en Laragon, o ejecuta los comandos con la ruta completa del `php.exe` de la versión 8.3.

### 2. Error: `Service Apache can not start ... fcgid.conf` en Laragon
- **Causa:** Descargaste la versión `nts` (Non-Thread Safe) de PHP en Windows.
- **Solución:** Descarga e instala en Laragon la versión **Thread Safe (TS)** desde [windows.php.net](https://windows.php.net/downloads/releases/archives/).

### 3. Error: `Base table or view not found: 1146 Table '...sessions' doesn't exist`
- **Causa:** En el archivo `.env` está configurado `SESSION_DRIVER=database` sin haber migrado la tabla de sesiones.
- **Solución:** Cambia en `.env` a `SESSION_DRIVER=file`.

### 4. Error: `cURL error 60: SSL certificate ... unable to get local issuer certificate` al sincronizar
- **Causa:** El PHP recién instalado no sabe dónde están los certificados raíz, así que no puede verificar el HTTPS de `escale.minedu.gob.pe`.
- **Solución:** Descarga [cacert.pem](https://curl.se/ca/cacert.pem) (o usa el que trae Git en `C:\Program Files\Git\mingw64\etc\ssl\certs\ca-bundle.crt`), guárdalo en la carpeta de PHP y configúralo en `php.ini`:
  ```ini
  curl.cainfo = "C:/laragon/bin/php/php-8.3.36-Win32-vs16-x64/extras/ssl/cacert.pem"
  openssl.cafile = "C:/laragon/bin/php/php-8.3.36-Win32-vs16-x64/extras/ssl/cacert.pem"
  ```
  > ⚠️ No desactives la verificación SSL (`withoutVerifying()`): expondría la sincronización a datos manipulados.

---

## 📄 Licencia

Este proyecto es de código abierto bajo la licencia [MIT](LICENSE).
