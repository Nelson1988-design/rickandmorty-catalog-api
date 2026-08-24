# Rick and Morty Catalog API

API en Laravel que sincroniza el catálogo de la [API pública de Rick and Morty](https://rickandmortyapi.com) en una base de datos propia y expone sus propios endpoints de consulta y de gestión de favoritos por usuario.

---

## Stack

| | |
|---|---|
| Framework | Laravel 13.26 |
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

Y para poblar la base de datos con el catálogo completo:

```bash
./vendor/bin/sail artisan rickmorty:sync
```

Tarda unos 40 segundos y trae 826 personajes, 51 episodios y 126 localizaciones. El detalle está en [Sincronización](#sincronización).

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

## Arquitectura

Tres capas, cada una con una regla:

| Capa | Contiene | Regla |
|---|---|---|
| `app/Domain/` | Contratos, objetos de datos, enums y excepciones del catálogo | No conoce a nadie: ni HTTP, ni Eloquent, ni Laravel |
| `app/Infrastructure/` | El cliente de la API externa, sus mappers y su validador | Implementa los contratos del dominio |
| `app/Providers/` | El enlace entre ambos | Una línea |

```
app/
├── Domain/Catalog/
│   ├── Contracts/CatalogProvider.php      ← el puerto
│   ├── Data/                              ← objetos propios del dominio
│   ├── Enums/
│   └── Exceptions/
├── Infrastructure/RickAndMorty/
│   ├── RickAndMortyProvider.php           ← el adaptador; único sitio con Http::
│   ├── Mappers/                           ← JSON del proveedor a objeto de dominio
│   └── PayloadValidator.php
└── Providers/CatalogServiceProvider.php   ← el enlace
```

La frontera cabe en una línea:

```php
$this->app->bind(CatalogProvider::class, RickAndMortyProvider::class);
```

A partir de ahí, cualquier consumidor pide un `CatalogProvider` y **no sabe que existe Rick and Morty**. Sustituir la fuente significa escribir otro adaptador y cambiar esa línea.

La regla que lo sostiene: **el JSON del proveedor solo existe dentro de `Infrastructure/RickAndMorty/`**. En cuanto sale de ahí ya es un objeto propio, y nada por encima de esa frontera —tampoco las excepciones— habla el idioma del proveedor ni el del framework.

---

## Modelo de datos

| Tabla | Columnas | Relaciones |
|---|---|---|
| `locations` | `external_id` (único), `name`, `type?`, `dimension?` | `residents()` |
| `episodes` | `external_id` (único), `name`, `code?` (indexado), `air_date?`, `air_date_raw?` | `characters()` |
| `characters` | `external_id` (único), `name`, `status`, `gender`, `species?`, `type?`, `image?` | `origin()`, `currentLocation()`, `episodes()` |
| `character_episode` | `character_id` + `episode_id` como clave primaria compuesta | — |

Tres relaciones:

- **Un personaje referencia dos localizaciones distintas.** `origin_location_id` es de dónde viene y `current_location_id` dónde está ahora. Ambas nullable y con `nullOnDelete`, porque el proveedor indica un lugar desconocido con una URL vacía: **300 de los 826 personajes no tienen origen y 21 no tienen ubicación actual**.
- **Los residentes de una localización se derivan de la ubicación actual**, nunca del origen. Haber nacido en un sitio no te convierte en residente de él, así que la relación existe en un solo lado y no puede contradecirse consigo misma.
- **Personajes y episodios son muchos a muchos.** El par es la clave primaria del pivote, con claves foráneas reales: sin `id` autoincremental que nadie usaría y con la unicidad garantizada por la base de datos en vez de meramente supuesta.

---

## Sincronización

```bash
./vendor/bin/sail artisan rickmorty:sync
```

El comando no acepta opciones. Sincroniza **localizaciones, después episodios, después personajes**, siempre en ese orden y siempre los tres.

```
+-----------+-------+---------+----------+
| Resource  | Pages | Records | Outcome  |
+-----------+-------+---------+----------+
| location  | 7     | 126     | complete |
| episode   | 3     | 51      | complete |
| character | 42    | 826     | complete |
+-----------+-------+---------+----------+
```

### Es idempotente

Ejecutarlo varias veces deja la base de datos **exactamente igual**. No es una afirmación de diseño: se verificó sincronizando el catálogo real cuatro veces seguidas y comparando el `CHECKSUM TABLE` de las cuatro tablas antes y después de la última. Los cuatro checksums salieron idénticos sobre 826 personajes, 51 episodios, 126 localizaciones y 1267 relaciones.

Se apoya en tres cosas: `upsert` sobre `external_id` con las columnas a actualizar listadas explícitamente, `sync()` en el pivote —que escribe solo la diferencia— y la ausencia de timestamps, que evita la reescritura silenciosa que los haría cambiar en cada pasada.

### Qué pasa cuando algo falla

| Situación | Comportamiento |
|---|---|
| Una página no se descarga o no se puede leer | Ese recurso se detiene. Lo ya escrito queda confirmado y la ejecución se marca como `partial` |
| Un personaje referencia algo no sincronizado | La ejecución **aborta**: significa que la pasada anterior quedó incompleta |
| Todo termina | La ejecución se marca `completed` |

**Los códigos de salida distinguen los tres casos:** `0` solo cuando la sincronización fue completa, `1` en cualquier otro caso. Una ejecución parcial no es un éxito, y un planificador que solo lea el código tiene que poder notarlo.

Cada ejecución queda registrada en la tabla `sync_runs` con su estado, sus marcas de tiempo, el motivo si algo se detuvo y cuántas páginas y registros escribió cada recurso. El comportamiento ante fallos parciales no es solo una promesa de este README: es una fila que se puede consultar.

### Es aditiva

Si un registro desaparece de la API externa, **su fila permanece en la base de datos**. La sincronización no borra ni marca por ausencia. Está explicado en las decisiones de diseño.

### El ritmo

La API externa aplica un límite de peticiones, así que el cliente espera 600 ms entre páginas. Es un valor medido, no estimado: sin espera la sincronización se corta en la petición 31, con 250 ms llega a la 44, y con 600 ms completa las 52. Se ajusta con `RICKANDMORTY_PAGE_DELAY`.

---

## La API

Todo cuelga de `http://localhost:8080/api/v1`.

El contrato completo está en **[`openapi.yaml`](openapi.yaml)**, en la raíz del repositorio, escrito a mano y sin ningún paquete que lo genere ni visor servido desde la aplicación. Se importa tal cual en Swagger Editor, Postman o Insomnia.

Que siga siendo cierto no depende de que alguien se acuerde: hay tests que comparan el documento con las rutas registradas y fallan si aparece un endpoint sin documentar, uno documentado que no existe, o uno cuya protección no coincide con la declarada.

Lo que sigue es el resumen; el detalle está en el contrato.

### Autenticación

Registrarse devuelve la cuenta y un token. **Es el único momento en que el token es legible**: en la base de datos solo queda su hash.

```bash
curl -X POST http://localhost:8080/api/v1/register \
  -H 'Content-Type: application/json' -H 'Accept: application/json' \
  -d '{"name":"Nelson","email":"nelson@example.test","password":"a-password-nobody-guesses"}'
```

```json
{ "token": "bCeqdrYUwr7d…", "user": { "id": 1, "name": "Nelson", "email": "nelson@example.test" } }
```

A partir de ahí, en la cabecera:

```bash
curl http://localhost:8080/api/v1/favorites -H 'Authorization: Bearer TU-TOKEN'
```

Los tokens caducan a las **24 horas**, ajustable con `API_TOKEN_LIFETIME_HOURS`. Iniciar sesión desde otro dispositivo emite un token distinto sin invalidar el anterior, y cerrar sesión revoca **solo el token que se usó** para hacerlo.

### Endpoints

| Método | Ruta | Token | |
|---|---|:---:|---|
| `POST` | `/register` | — | Crea la cuenta y devuelve un token |
| `POST` | `/login` | — | Devuelve un token nuevo |
| `POST` | `/logout` | ✓ | Revoca el token con el que se llamó |
| `GET` | `/characters` | — | Listado con filtros |
| `GET` | `/characters/{id}` | — | Detalle con origen, ubicación actual y episodios |
| `GET` | `/episodes` | — | Listado |
| `GET` | `/episodes/{id}` | — | Detalle con sus personajes |
| `GET` | `/locations` | — | Listado |
| `GET` | `/locations/{id}` | — | Detalle con sus residentes |
| `GET` | `/favorites` | ✓ | Los favoritos del usuario |
| `POST` | `/favorites/{character}` | ✓ | Marca un personaje |
| `DELETE` | `/favorites/{character}` | ✓ | Lo desmarca |

El catálogo se lee sin token: es un espejo de una fuente pública y una puerta delante no protegería nada. Lo que exige identidad es lo que pertenece a alguien.

**Los identificadores de las URLs son los nuestros**, no los del proveedor. El suyo viaja en cada respuesta como `external_id`, para quien quiera alinear ambos catálogos.

### Filtros

Solo el listado de personajes los tiene, y se combinan entre sí:

```bash
curl 'http://localhost:8080/api/v1/characters?name=rick&status=dead'
```

| Filtro | Comportamiento |
|---|---|
| `name` | Coincidencia parcial: `rick` encuentra también *Toxic Rick* |
| `status` | Exacto, uno de `alive`, `dead` o `unknown`. No distingue mayúsculas |
| `species` | Exacto |

Un valor no reconocido —`?status=undead`— responde `422`. Devolver el catálogo entero a quien pidió otra cosa sería peor que decírselo.

### Paginación

Los listados devuelven 20 elementos con la forma nativa del paginador de Laravel: `data`, `links` y `meta`. La página se elige con `?page=N`. El tamaño es fijo.

### Errores

Toda respuesta de error lleva `message` y `code`; `errors` aparece solo cuando hay errores de campo.

```json
{ "message": "The provided credentials are incorrect.", "code": "validation_failed",
  "errors": { "email": ["The provided credentials are incorrect."] } }
```

| Estado | `code` |
|---|---|
| 401 | `unauthenticated` |
| 403 | `forbidden` |
| 404 | `not_found` |
| 405 | `method_not_allowed` |
| 422 | `validation_failed` |
| 429 | `too_many_requests` |
| 5xx | `server_error` |

---

## Tests

```bash
./vendor/bin/sail artisan test
```

La suite se ejecuta contra **MySQL real**, no contra SQLite, en una base de datos `testing` independiente que el propio servicio de MySQL crea al inicializarse.

Las pruebas del cliente externo **no pueden alcanzar la red**: además de `Http::fake()` usan `Http::preventStrayRequests()`, que convierte en fallo cualquier petición no falseada. Una URL mal construida no se escapa a internet y pasa por casualidad: falla.

Los mappers y los objetos de dominio se prueban sin arrancar siquiera el framework. Esa diferencia es deliberada y hace visible dónde están los acoplamientos: el único componente de esta capa cuyo test necesita el contenedor es el validador, porque lee configuración.

La suite está organizada en capas: cada pieza se prueba sustituyendo por un doble la frontera que le toca —la red para el cliente HTTP, el puerto entero para los sincronizadores y el caso de uso— y **un único test recorre la cadena completa** con el adaptador real dentro, falseando solo la red. Ese último cubre la costura que los demás dejan libre: que las piezas encajen de verdad.

---

## Decisiones de diseño

### El consumo de la API externa vive tras un puerto

El dominio define la interfaz `CatalogProvider` con tres métodos y ni una palabra de HTTP. El adaptador la implementa y es la única clase del proyecto que usa el cliente HTTP.

La paginación se expone como un **cursor opaco**: cada página devuelve un testigo que el llamador entrega tal cual en la siguiente petición, sin interpretarlo nunca. Por debajo es la URL del `next` que envía el proveedor, pero el dominio no lo sabe ni necesita saberlo — es lo que permite cambiar de esquema de paginación sin tocar nada por encima de la frontera.

### Campos ausentes e inconsistentes

Solo `id` y `name` son obligatorios. Sin identificador no se puede reconciliar el registro y sin nombre no sirve de nada; **todo lo demás degrada a `null` en lugar de abortar la página**.

El proveedor escribe `""` o `"unknown"` donde no tiene dato, y ambos se normalizan a ausencia. Con un matiz que viene de mirar los datos y no de suponerlos: el centinela se compara **entero**, porque dos localizaciones se llaman literalmente *Unknown dimension* y una regla por subcadena destruiría un valor legítimo.

En `status` y `gender` esa misma palabra recibe el tratamiento contrario: es un caso del enum, no una ausencia. La regla que los separa es si el campo tiene un conjunto cerrado de valores — 100 personajes sin estado establecido no son un hueco, son un dato.

### Estricto en lo que gobierna el flujo, tolerante en lo informativo

`info.count` ausente degrada a `0`, porque solo sirve para informar del progreso. La clave `info.next` ausente **aborta**, porque decide si se sigue paginando: tratarla como fin de colección dejaría la base con una fracción del catálogo reportando éxito.

La comprobación usa `array_key_exists` y no `isset`, que no distingue una clave ausente de una que vale `null` — y `next: null` es precisamente la última página.

Además, el `next` se valida contra el host configurado antes de seguirlo, y las referencias entre recursos se validan contra el recurso esperado. Sin lo segundo, una referencia a un personaje colocada en el campo `origin` se convertiría en una clave foránea a `locations` perfectamente válida a la vista.

### Tolerancia a fallos del servicio remoto

Tres intentos con backoff exponencial, 5 s de conexión y 15 s totales. Se reintentan los errores de red y los 5xx; **los 4xx no**, con una excepción: el `429`, que es el servidor pidiendo explícitamente que se le llame más tarde. No es teoría — la API limita de verdad a partir de unas treinta peticiones seguidas.

Un `404` se trata como fallo, nunca como página vacía: el adaptador solo pide la URL base o las que el propio proveedor le ha dado, así que un 404 significa que el proveedor se contradice.

### Fechas deterministas

`air_date` llega como `"December 2, 2013"` y se parsea con el formato explícito `!F j, Y`. El `!` inicial es imprescindible: sin él PHP rellena la hora con el reloj y el mismo registro se mapea a un valor distinto en cada ejecución, lo que impediría de raíz que la sincronización sea idempotente.

Si el parseo falla, o si produce un desbordamiento —`createFromFormat` convierte *February 30* en *March 2* y solo lo reporta como aviso—, la fecha queda a `null` y se conserva el string original. Guardar una fecha que el proveedor nunca envió sería peor que no guardar ninguna.

### El identificador de la fuente externa

Cada tabla del catálogo tiene su propia clave primaria autoincremental y guarda el identificador del proveedor en una columna `external_id` con índice único. **Ninguna clave foránea del esquema apunta a `external_id`.**

El motivo es no depender del identificador de un tercero que no se controla. Si el proveedor reasigna identificadores, fusiona catálogos o se sustituye por otro, la reconciliación se rehace tocando una columna, en lugar de reconstruir todas las relaciones y los favoritos que los usuarios ya hayan guardado.

De ahí sale también la regla que gobierna el resto de restricciones: **la unicidad protege lo que controlamos, no hace de policía de los datos ajenos.** Por eso `episodes.code` va indexado pero **no** es único: un especial en dos partes compartiendo código sería un dato perfectamente legítimo del proveedor, y una restricción de unicidad lo convertiría en una sincronización abortada.

### El esquema respalda las garantías del dominio, no sus permisos

`status` y `gender` son `NOT NULL` porque el mapper **garantiza** un valor: lo desconocido se convierte en el caso `Unknown` del enum, nunca en `null`. La restricción respalda esa promesa, de modo que un `null` llegando a la base significa que el mapper incumplió su contrato y la escritura debe fallar.

`image`, `species` y `type` son nullable porque el mapper tiene **permiso** para producir `null` en ellos — la mitad del catálogo trae el tipo vacío. Una columna más estricta que el contrato que la alimenta convertiría una degradación documentada en un error de integridad.

Los enums se persisten como `string` y no como `enum` de MySQL: el conjunto ya lo valida el enum de PHP, y un `enum` nativo convertiría añadir un valor en un `ALTER TABLE`.

### La sincronización es aditiva

Si un registro deja de aparecer en la API externa, **su fila permanece en la base de datos**. No hay borrado ni marcado por ausencia.

Es una decisión, no un descuido. Un borrado por omisión asume que cada ejecución ve el catálogo completo, y una degradación temporal del proveedor que devolviese la mitad marcaría la otra mitad como eliminada. El requisito es idempotencia —no duplicar registros ni corromper relaciones— y el comportamiento aditivo la cumple sin abrir un camino destructivo.

### Las tablas del catálogo no llevan timestamps

`Eloquent\Builder::upsert()` añade `updated_at` a las columnas que actualiza, siempre. Eso deja dos comportamientos y ninguno bueno: mantener la columna significa reescribirla en cada ejecución aunque no cambie nada, y excluirla del `upsert` significa que conserva la fecha del insert original incluso cuando el registro sí cambia.

Estas tres tablas son una proyección de un catálogo ajeno, no entidades con ciclo de vida propio en esta aplicación. Se han quitado los timestamps en vez de arrastrar una columna que iba a mentir en cualquiera de los dos escenarios.

### Índices

Solo sobre `characters.status` y `characters.species`, que son los filtros del listado y se consultan por igualdad exacta sobre campos de baja cardinalidad.

**No hay índice sobre `characters.name`** a propósito. La búsqueda por nombre que espera un usuario encuentra «Toxic Rick» al escribir «Rick», es decir `LIKE '%rick%'`, y un índice B-tree no optimiza comodines por la izquierda: el motor recorre la tabla igualmente. Un índice que nunca se usa ocupa espacio y ralentiza cada escritura de la sincronización.

### El orden de sincronización es una restricción, no una preferencia

Los personajes referencian localizaciones y episodios, y llegan con los identificadores del proveedor en lugar de con los nuestros. Traducirlos exige que las otras dos tablas ya estén pobladas, así que el orden es lo que hace posible la traducción.

Y la hace **barata**: los dos diccionarios `external_id → id` se cargan una vez antes de la primera página, con dos consultas en lugar de las 1652 que costaría resolver cada referencia por separado.

El orden está escrito como una lista literal en el caso de uso, no como un comentario, para que no pueda desviarse por descuido.

### Qué se detiene y qué aborta

Una página que no se puede descargar **detiene su recurso** y nada más: lo ya escrito queda confirmado y la ejecución se marca como parcial. No es una elección, además — con paginación por cursor, la única ruta hacia la página siguiente es el testigo que venía dentro de la anterior, así que una página perdida rompe la cadena.

Un registro ilegible detiene la página entera en lugar de saltarse solo ese registro. La unidad atómica de escritura ya es la página; tratarla como divisible para leer rompería esa simetría. Y en una API REST un campo malformado casi nunca es un error aislado: es el síntoma de un cambio de esquema, y salvar los registros «sanos» de una página corrupta enmascararía el problema en vez de señalarlo.

Una referencia a algo que no se sincronizó **aborta la ejecución completa**, porque solo puede significar que la pasada anterior quedó incompleta. Continuar sería seguir construyendo sobre terreno que ya se sabe incompleto.

### La orquestación vive fuera del comando

El comando de consola es un adaptador de entrada, igual que el cliente HTTP es uno de salida: recibe la invocación, llama al caso de uso y presenta el resultado. Con la lógica fuera, la sincronización completa se prueba inyectando un doble del puerto, sin ejecutar Artisan y sin red.

Tampoco acepta opciones. Un `--resource=character` permitiría saltarse una pasada de la que depende la siguiente, que es justo lo que las claves foráneas convierten en un aborto: un botón que rompe una restricción de corrección no es una funcionalidad.

### Autenticación propia, sin paquetes de terceros

Los tokens viven en su propia tabla, no en una columna de `users`: una columna limitaría a una sesión por persona, y entrar desde el móvil cerraría la del portátil.

Se guarda **el hash `sha256`**, nunca el token. Quien comprometiera la base solo encontraría cadenas irreversibles. Que el hash sea rápido y sin sal es deliberado y es lo contrario de lo que se hace con contraseñas: la entrada ya tiene 40 caracteres de entropía aleatoria, así que no hay nada que romper por fuerza bruta, y la búsqueda ocurre en cada petición.

**La pieza que hace esto viable sin un paquete es un `Guard` nativo.** Implementa el contrato `Illuminate\Contracts\Auth\Guard` y se registra como driver, de modo que el resto de la aplicación no se entera de que la autenticación es propia: `auth:api`, `$request->user()`, los form requests y las policies funcionan sin una línea de adaptación. Un middleware que metiera el usuario en el contenedor parecería equivalente y se rompería en cuanto alguien escribiera una policy.

Un detalle que no es opcional: Laravel cachea las instancias de guard y no las olvida dentro de un mismo proceso, así que la resolución se indexa por **la petición** y no por un booleano. Sin eso, el usuario resuelto en una petición se le entregaría a la siguiente.

### El logout revoca un token, no todos

Cerrar sesión en el móvil no debe cerrarla en el portátil. Pero `$request->user()` devuelve un usuario, no el token con el que llegó, así que es el guard —que fue quien lo encontró— el que lo conserva y lo expone. El controlador se lo pasa al caso de uso, que revoca ese y solo ese.

La alternativa habitual es colgar el token del propio modelo `User` mediante un trait. Se descartó: mutar un modelo para transportar estado de una petición HTTP es acoplamiento en la dirección equivocada.

### Caducidad de 24 horas, y por qué no menos

Los tokens caducan, y la caducidad se comprueba al leerlos. **No hay comando de limpieza**, y es una decisión: un token vencido no sirve para entrar aunque su fila siga ahí, así que lo que queda sin limpiar es espacio, no seguridad.

Tampoco son 4 horas. Sin un mecanismo de refresco, una caducidad corta empuja al cliente a **guardar la contraseña** para poder re-autenticarse, que es peor que la ventana más larga que sustituye. Los sistemas con tokens de una hora traen siempre un refresh token; añadirlo aquí duplicaría la superficie de autenticación sin un caso de uso que lo pida.

### Añadir y quitar favoritos son idempotentes

Marcar un personaje que ya era favorito, o quitar uno que no lo era, responden `204` sin cambiar nada. El cliente no está pidiendo una transición sino **un estado**, y ese estado se cumple igual haya habido que escribir algo o no. El caso real que lo justifica es mundano: un reintento tras una conexión caída, o un doble toque.

La clave primaria compuesta del pivote es la garantía final: aunque dos peticiones llegaran a la vez, la base de datos rechazaría la segunda fila.

### El formato de error extiende el del framework

Laravel ya devuelve `message` en toda respuesta de error, y `errors` cuando falla una validación. Lo único que le falta es algo estable contra lo que un cliente pueda ramificar sin leer prosa, y eso es `code`.

Se añade **decorando la respuesta que construye el framework**, no rendirizando una propia. Así cada estado que Laravel sabe producir hereda el formato sin que nadie tenga que enumerarlos, y ninguno puede quedarse fuera porque ninguno se está reimplementando. Las claves que añade el framework se conservan, de modo que la traza sigue apareciendo mientras se depura.

### La documentación es un fichero, no un paquete

El contrato vive en `openapi.yaml`, escrito a mano. No se instaló ningún generador ni ningún visor: una API REST no necesita dependencias de vistas para documentarse a sí misma, y cualquier consumidor abre ese fichero en la herramienta que ya usa.

Lo que evita que envejezca no es la disciplina, son tres tests: uno comprueba que toda ruta registrada está documentada, otro que todo endpoint documentado existe de verdad, y el tercero —el que más importa— que lo que el documento llama protegido es exactamente lo que el router protege. Documentar como abierto un endpoint que pide token es peor que no documentarlo: invita a construir contra una respuesta que nunca llegará.

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
