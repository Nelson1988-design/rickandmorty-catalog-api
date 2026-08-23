# Rick and Morty Catalog API

API en Laravel que sincroniza el catálogo de la [API pública de Rick and Morty](https://rickandmortyapi.com) en una base de datos propia y expone sus propios endpoints de consulta y de gestión de favoritos por usuario.

---

## Stack

| | |
|---|---|
| Framework | Laravel 13.17 |
| PHP | 8.4 |
| Base de datos | MySQL 8.4 |
| Entorno | Laravel Sail (Docker Compose) |
| Tests | PHPUnit 12.5 |

---

## Requisitos previos

**Solo Docker.** No necesitas PHP ni Composer instalados en tu máquina: las dependencias se instalan desde un contenedor.

---

## Puesta en marcha

```bash
git clone https://github.com/Nelson1988-design/rickandmorty-catalog-api.git
cd rickandmorty-catalog-api
```

```bash
cp .env.example .env
```

Un repositorio recién clonado no tiene `vendor/`, así que `vendor/bin/sail` todavía no existe. Las dependencias se instalan desde la imagen oficial de Composer de Sail:

```bash
docker run --rm -v "$(pwd)":/var/www/html -w /var/www/html \
  laravelsail/php84-composer:latest composer install
```

A partir de aquí ya se puede usar Sail:

```bash
./vendor/bin/sail up -d
```

La primera ejecución construye la imagen de PHP 8.4 y tarda unos minutos.

```bash
./vendor/bin/sail artisan key:generate
```

```bash
./vendor/bin/sail artisan migrate
```

La aplicación queda disponible en **http://localhost:8080**.

Para detenerlo todo:

```bash
./vendor/bin/sail down
```

---

## Comprobación

```bash
curl -s -o /dev/null -w "%{http_code}\n" http://localhost:8080
```

Debe responder `200`.

---

## Tests

```bash
./vendor/bin/sail artisan test
```

La suite se ejecuta contra **MySQL real**, no contra SQLite, en una base de datos `testing` independiente que el propio servicio de MySQL crea al inicializarse.

---

## Decisiones de diseño

### PHP 8.4, no 8.5

Sail incluye runtimes hasta PHP 8.5, pero la instalación de dependencias en un clon limpio depende de una imagen de Composer contenedorizada, y la más reciente publicada por Laravel es `laravelsail/php84-composer` — no existe equivalente para 8.5.

Fijar 8.4 hace que **la imagen que instala las dependencias y la que ejecuta la aplicación sean la misma versión de PHP**. Se ha preferido la versión más reciente con cadena de herramientas oficial completa antes que la más reciente a secas: un entorno donde el instalador y el runtime no coinciden no puede garantizarse reproducible en la máquina de otra persona.

La versión se fija directamente en las dos líneas correspondientes de `compose.yaml`. `sail:install` expone una opción `--php=`, pero en `laravel/sail v1.67.0` está declarada en la firma del comando y nunca se lee, de modo que no surte efecto.

### Puertos 8080 y 33061

La aplicación se publica en el **8080** y MySQL en el **33061**, en lugar de los 80 y 3306 por defecto.

El puerto 80 lo ocupa cualquier servidor web local, y el 3306 cualquier MySQL local. Ambos son colisiones habituales en la máquina de un desarrollador, y ninguna de las dos aporta nada: el 8080 no requiere privilegios y, dentro de la red de Docker, los servicios siguen comunicándose por sus puertos estándar. Solo cambia el acceso desde el anfitrión.

### `.env.example` refleja el servicio realmente instalado

El esqueleto de Laravel 13 deja `.env.example` con `DB_CONNECTION=sqlite` y el bloque `DB_` comentado, aunque `sail:install --with=mysql` sí actualiza `.env`. Un `cp .env.example .env` sobre el esqueleto sin corregir no levanta el proyecto.

`.env.example` se ha alineado con el servicio MySQL de `compose.yaml`, de forma que los pasos de puesta en marcha de este README funcionen sobre un clon limpio sin edición manual.

### Los tests corren contra MySQL, no contra SQLite

El servicio de MySQL de Sail monta un script de inicialización que crea una base `testing` independiente, y la configuración de PHPUnit ya apunta a ella.

Usar SQLite para las pruebas habría significado peor fidelidad —tipos, restricciones y comportamiento de las operaciones de inserción masiva difieren— sin ganar nada a cambio, ya que el aislamiento entre la base de desarrollo y la de pruebas ya está resuelto.
