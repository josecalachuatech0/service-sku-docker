# php-excel

Herramientas para gestionar los **servicios de SKU** (armado, instalación, empaque de regalo) del catálogo VTEX de Cemaco: qué servicio se le ofrece al cliente en cada producto y a qué precio.

El proyecto tiene dos partes:

- **Scripts PHP de consola (`bin/`)**: auditan el catálogo, generan reportes CSV del estado real y aplican configuraciones masivas por lote, siempre con un previo (dry-run) y dejando registro de cada escritura.
- **Dashboard local (`dashboard/`)**: una interfaz web mínima sobre esos mismos scripts para consultar SKUs, generar el previo y aplicar lotes de hasta 300 SKUs sin usar la terminal. Los SKUs se pegan o se suben en la planilla nativa de VTEX «Valores Serviços Vinculados SKU» (.xlsx o .csv, un solo SKU de servicio por archivo, que puede repetirse en todas las filas).

Las carpetas por mes (`AGOSTO/`, `SETIEMBRE/`…) y los CSV de la raíz son el historial de corridas y reportes de cada categoría trabajada.

> ⚠️ **Escribe en producción.** No hay entorno de pruebas. Todo `apply-*` se corre primero en dry-run y con autorización explícita del alcance.

## Características

- Reportes de estado en CSV (8 o 9 columnas) por lista de SKUs o por categoría completa
- Configuración masiva idempotente: `PUT` si la relación existe, `POST` si no
- Dry-run por defecto en los scripts nuevos, con auditoría CSV/JSON de cada escritura
- Inventario de service types, que la API de VTEX no expone como listado
- Auditoría de **productos sin SKU** en todo el catálogo, agrupada por categoría
- Verificación contra la fuente de verdad (`/api/catalog/pvt/skuservice`), no contra el endpoint cacheado

## Requisitos previos

- PHP >= 8.0 (el entorno actual corre 8.5.5)
- Extensiones `curl`, `json`, `mbstring`
- Credenciales VTEX (AppKey / AppToken) con permisos de catálogo
- **No se necesita Composer.** El proyecto no tiene dependencias

## Instalación

```bash
git clone git@github.com:josecalachuatech0/cemaco-skus-servicios.git
cd cemaco-skus-servicios
cp .env.example .env
php -v && php -m | grep curl
```

No hay build ni instalación de paquetes. El dashboard es opcional y se levanta con `php -S 127.0.0.1:8080 -t dashboard` desde la raíz.

## Configuración

Edita `.env` en la raíz, basado en `.env.example`:

```
API_KEY=tu_appkey
API_TOKEN=tu_apptoken
VTEX_ACCOUNT=cemaco
VTEX_ENVIRONMENT=vtexcommercestable
VTEX_PAGE_SIZE=250
VTEX_BASE_URL=https://cemacogt.myvtex.com
```

`.env` está en `.gitignore` y sus valores no se muestran nunca en reportes ni en logs.

### Logs para rastrear fallos

El dashboard y los scripts de `bin/` anotan los fallos en un log de una línea JSON por evento (`lib/log.php`). Por defecto va a `logs/app.log`, que está en `.gitignore`; `APP_LOG_FILE` lo cambia. En el contenedor no hay que definir nada: `docker/entrypoint.sh` sigue ese archivo y lo copia a la salida del contenedor, que Azure muestra en Log stream y Log Analytics. No uses `php://stderr`, porque el dashboard lee el stderr de los scripts.

| Qué se anota | Cuándo | Evento |
|---|---|---|
| Consulta SKU, Configuración del Servicio y Actualizar Servicios | Solo si fallan: el script sale con error, VTEX no responde (HTTP 0, 429 o 5xx), una fila queda en error o un SKU no se pudo verificar | `accion` |
| Aplicar | Siempre, con los types, los SKUs y cuántas filas quedaron ok, con error y omitidas | `accion` |
| Cobertura y actualización automática de types | Siempre: inicio, fin con código y duración, y si pasan 20 minutos sin terminar | `tarea.inicio`, `tarea.fin`, `tarea.trabada` |
| Peticiones a VTEX | Cada reintento y el fallo final (sin respuesta, 429, 5xx o una escritura rechazada). Un 404 en GET no se anota | `vtex.reintento`, `vtex.fallo` |
| Errores fatales de PHP | Siempre (por ejemplo, falta de memoria) | `php.fatal` |
| Errores del dashboard | Siempre, salvo los mensajes para quien llena el formulario | `dashboard.excepcion` |
| Arranque | Si falta `API_KEY`, `API_TOKEN`, la lista oficial de types o la copia de types, o no se puede escribir en `cache/` o `runs/`. Una vez por cada cambio | `arranque.problema` |

El log nunca guarda `API_KEY` ni `API_TOKEN`: los quita enteros y recortados, porque las trazas de PHP los muestran cortados.

Todos los scripts toman la base de `VTEX_BASE_URL` a través de `lib/vtex.php`. Si falta, usan la base privada `https://cemacogt.myvtex.com`. Hasta el 2026-10-23 los `query-skus-*` caían al storefront público (`https://www.cemaco.com`), que corta el árbol de categorías; ya no.

## Uso

```bash
# Extraer los SKUs de una categoría (incluye subcategorías)
php bin/get-skus-by-category.php --category=1604 --output=input/categoria_1604_skus.txt

# Generar el reporte de estado de 9 columnas
php bin/query-skus-9-columns.php --input=input/categoria_1604_skus.txt \
    --output=reporte_ventanas.csv --concurrency=6

# Aplicar un servicio — dry-run: no escribe nada
php bin/apply-instalacion-bracket.php --input=input/brackets_skus.txt \
    --output=aplicacion.csv --servicetype=16 --servicevalue=84871 \
    --name="Servicio de Instalación"

# Verificar contra la API en vivo
php bin/check-skus-servicetype.php --skus=983865,997822 --servicetype=16

# Auditar productos sin SKU (sin --max-categorias barre los 44 departamentos)
php bin/audit-productos-sin-sku.php \
    --output-dir=AGOSTO/semana_4/catalogo_completo --concurrency=8
```

Agregar `--aplicar` a un `apply-*` es lo que lo hace escribir de verdad.

## Docker (Azure App Service)

La imagen (`Dockerfile`) corre el dashboard con Apache y PHP 8.5. Solo lleva lo que el código usa: `bin/`, `lib/`, `config/`, `dashboard/`, `docker/`, `service_types.csv` y `lista_oficial_types_instalaciones.csv` (lista blanca en `.dockerignore`). El historial, el `.env` y las pruebas quedan fuera.

En la Mac se usa [Colima](https://github.com/abiosoft/colima) (gratuito y de código abierto) en lugar de Docker Desktop, que pide licencia de pago en empresas grandes:

```bash
brew install colima docker docker-buildx
colima start --cpu 2 --memory 4 --disk 30 --vm-type vz
```

```bash
docker build -t sku-service .
docker run --rm -p 8080:80 -e API_KEY=... -e API_TOKEN=... -e VTEX_BASE_URL=https://cemacogt.myvtex.com sku-service
```

| Variable | Obligatoria | Para qué |
|---|---|---|
| `API_KEY`, `API_TOKEN` | sí | Credenciales de VTEX. En App Service, referencias a Key Vault |
| `VTEX_BASE_URL` | no | Base de VTEX; sin ella, `https://cemacogt.myvtex.com` |
| `DATOS_PERSISTENTES` | no | Carpeta que sobrevive a reinicios (en App Service, `/home/datos` con `WEBSITES_ENABLE_APP_SERVICE_STORAGE=true`). Guarda ahí `dashboard/cache/` y `dashboard/runs/`; sin ella se pierden al reiniciar |
| `APP_LOG_FILE` | no | No hace falta en el contenedor; ver «Logs para rastrear fallos» |

- Logs: `docker/entrypoint.sh` sigue `logs/app.log` con `tail -F` y lo copia a la salida del contenedor; si `tail` muere, lo vuelve a lanzar. Un `APP_LOG_FILE` en `/proc/…` o `/dev/…` (como el viejo `/proc/1/fd/1`) se avisa al arrancar y se reemplaza por `logs/app.log`. Al arrancar, un log de más de 50 MB se vacía (ya llegó a Azure).
- **Imagen para entregar** (ya construida, sin que infraestructura compile nada): construir desde una carpeta con el contenido de `main`, nunca pasando un `.tar` por la entrada estándar (`git archive | docker build -`), porque así Docker no aplica el `.dockerignore` y entran los 2,594 archivos del historial. Comprobar que la imagen tiene 37 archivos y exportarla:

  ```bash
  C=$(mktemp -d); git archive main | tar -x -C "$C"
  docker build --platform linux/amd64 -t sku-service:<fecha>-<commit> "$C"; rm -rf "$C"
  docker run --rm --entrypoint sh sku-service:<fecha>-<commit> -c 'find /var/www/app -type f | wc -l'   # 37
  docker save sku-service:<fecha>-<commit> | gzip -9 > sku-service-<fecha>-<commit>.tar.gz
  ```
- `PHP_CLI=/usr/local/bin/php` (en el `Dockerfile`): con Apache, `PHP_BINARY` viene vacío y el dashboard lanza los scripts de `bin/` con `phpCli()`.
- `docker/php.ini`: hora de Guatemala, `max_execution_time = 300` (un Aplicar de 300 SKUs tarda 2–3 min; App Service corta a los 230 s) y errores al log, no a la página.
- `docker/apache.conf`: `/cache` y `/runs` no se sirven por URL.
- Pendiente: el bucle de actualización de lunes y miércoles a las 00:00 y los values y types automáticos (bitácora, «Decisiones para Docker»). En App Service el bucle exige *Always On*.

## Pruebas

```bash
php tests/smoke.php          # sin red: estructura, sintaxis, lib/, candados de legacy y dashboard
php tests/smoke.php --live   # además, consultas de solo lectura contra VTEX
```

Correrla antes de cada commit que toque `bin/` o `lib/`. Nunca escribe en VTEX. Sale con código 1 si algo falla.

## Estructura del proyecto

```
php-excel/
├── bin/                  scripts CLI activos: consulta, auditoría y aplicación
│   └── legacy/           scripts de junio archivados: escriben sin dry-run y borran, no corren sin --ejecutar-legacy
├── lib/                  código compartido: cliente VTEX con reintentos, helpers de CLI, reglas protegidas, log
├── logs/                 log de fallos (app.log), fuera de git
├── dashboard/            interfaz web local sobre los scripts de bin/
├── tests/                prueba de humo (tests/smoke.php)
├── config/               cargador de .env
├── input/                83 listas de SKUs en TXT, un SKU por línea
├── auditoria-skus-catalogo/    registros antes/después de correcciones masivas
├── revision_de_categorias_cemaco/   una subcarpeta por categoría revisada
├── AGOSTO/               trabajo por semana (semana_4: productos sin SKU)
└── *.csv                 reportes generados (artefactos, no código fuente)
```

## Scripts principales

### `descargar-catalogo.php --salida=<carpeta>`

Baja a disco una copia completa del catálogo (productos, SKUs, fecha de creación, inventario y precios) para consultarlo en local. Solo lectura y reanudable. Opciones, formato y ejemplos en `docs/descarga-catalogo.md`.

### `get-skus-by-category.php --category=<id> --output=<archivo>`

Extrae todos los SKUs de una categoría VTEX, incluidas sus subcategorías.

| Bandera | Tipo | Requerido | Descripción |
|---|---|---|---|
| `--category` | int | sí | Id de la categoría VTEX |
| `--output` | string | sí | Ruta del TXT de salida, un SKU por línea |

### `query-skus-9-columns.php --input=<archivo> --output=<csv>`

Genera el reporte estándar de estado para una lista de SKUs. Solo lectura.

| Bandera | Tipo | Requerido | Descripción |
|---|---|---|---|
| `--input` | string | sí | TXT con un SKU por línea |
| `--output` | string | sí | Ruta del CSV de salida |
| `--concurrency` | int | no | Peticiones en paralelo (6 es lo habitual) |
| `--servicetype` | int | no | Filtra a un service type; **cambia el significado de `tiene_attachment`** |

### `apply-instalacion-bracket.php --input=<archivo> --servicetype=<id> --servicevalue=<id>`

Aplica un service type a una lista de SKUs. Es el modelo a copiar para cualquier `apply-*` nuevo: dry-run por defecto, parámetros por bandera y **nunca borra**.

| Bandera | Tipo | Requerido | Descripción |
|---|---|---|---|
| `--input` | string | sí | TXT con los SKUs a configurar |
| `--output` | string | sí | CSV/JSON de auditoría de la corrida |
| `--servicetype` | int | sí | Id del `SkuServiceType` a aplicar |
| `--servicevalue` | int | sí | Id del `SkuServiceValue` (precio). Se valida que pertenezca al type |
| `--name` | string | sí | Valor de `Name` y `Text`. El canónico es `Servicio de Instalación` |
| `--aplicar` | flag | no | Sin esta bandera es dry-run y no escribe nada |

### `fix-skuservice-canonical-armado.php --output=<csv>`

Corrige `Name` y `Text` de las relaciones `SkuService` de armado (types 3, 4, 5 y 47) al canónico `Servicio de armado`. Entra por el listado completo de `/api/catalog/pvt/skuservice`, relee cada relación en vivo antes de escribir, solo hace `PUT` con el objeto completo y **nunca crea ni borra**. Idempotente.

Corrido el 2026-09-17 sobre las 1,423 relaciones: 482 corregidas, 0 errores. Criterios de aceptación y auditorías en `SETIEMBRE/semana_3/canonico_armado/`.

| Bandera | Tipo | Requerido | Descripción |
|---|---|---|---|
| `--output` | string | sí | CSV de auditoría (el JSON se escribe al lado) |
| `--types` | lista | no | Restringir a algunos types de armado, ej. `4,5` |
| `--skus` | lista | no | Restringir a algunos SKUs, para pilotos |
| `--limit` | int | no | Procesar solo las primeras N relaciones |
| `--listado` | string | no | JSON de una descarga previa del listado, en vez de bajarlo |
| `--aplicar` | flag | no | Sin esta bandera es dry-run y no escribe nada |

### `audit-productos-sin-sku.php --output-dir=<dir>`

Encuentra los productos que no tienen ningún SKU y arma un CSV de 8 columnas por categoría afectada. Solo lectura. Barre por departamento raíz, no por categoría: `GetProductAndSkuIds` ya incluye las subcategorías, así que 44 barridos cubren las 2,240 categorías del árbol.

Último barrido completo (2026-08-28, en `AGOSTO/semana_4/catalogo_completo/`): **16,448 productos sin SKU** sobre 200,790. De ellos **16,393 nacieron vacíos** — llevan el slug `producto-vacio` en su `LinkId` y en dos días ninguno recibió un SKU; 37 son altas en tránsito y **18 cuelgan de categorías apagadas**, dentro de departamentos activos, así que el script no los alcanza.

Cobertura verificada al **99.98 %** con dos vías extra: `sku/stockkeepingunitids` (enumera SKUs sin pasar por categorías) y el sondeo de ids de categoría uno por uno.

Los CSV por categoría llevan 3 columnas (`productId`, nombre, categoría). **El script todavía escribe 8**: se recortaron a mano el 30-ago.

| Bandera | Tipo | Requerido | Descripción |
|---|---|---|---|
| `--output-dir` | string | no | Carpeta de salida (default `AGOSTO/semana_4`) |
| `--max-categorias` | int | no | Cuántos departamentos raíz barrer. `0` = los 44 |
| `--concurrency` | int | no | Peticiones en paralelo al pedir el detalle de cada producto |

## Trampas conocidas

| Trampa | Qué hacer |
|---|---|
| `stockkeepingunitbyid` sirve datos cacheados tras una escritura (hasta 9 días medidos) | Verificar siempre contra `/api/catalog/pvt/skuservice` |
| Los CSV de reporte llevan comas dentro de los campos (`Ropa, Calzado Y Accesorios`) | No agregarlos con `awk -F','` ni `cut`: usar un parser CSV real. Falla en silencio |
| Un producto sin SKU puede ser solo un alta en tránsito, no un abandono | Mirar el `LinkId`: si empieza con `producto-vacio` nació sin SKU; si termina en un número, ese es el SKU que le van a enlazar |
| Un barrido por categoría no puede medir su propia cobertura | Contrastar con `GET /api/catalog_system/pvt/sku/stockkeepingunitids`, que no pasa por el árbol |
| `category/tree` devuelve solo las categorías activas | Las que tienen `IsActive:false` quedan fuera — hay 20, y 3 tienen productos. Cuelgan de departamentos **activos**, así que el barrido del departamento parece completo. Solo aparecen sondeando ids con `GET category/{id}`; `ParentCategoryId` no sirve, viene null siempre |
| `tiene_attachment` significa "¿tiene *algo*?" sin `--servicetype`, y "¿tiene *ese* type?" con él | Anotar en el nombre del archivo cómo se corrió |
| `HTTP 429` es rate limit, no un fallo | `lib/vtex.php` ya reintenta hasta 4 veces respetando `Retry-After`. Si aun así quedan fallidos, reintentar solo esos SKUs con `--concurrency=1` |
| `DELETE /api/catalog/pvt/skuservicetype/{id}` responde 500 | Desactivar con `PUT` poniendo `IsActive` y los tres `ShowOn*` en `false` |
| El dry-run valida que el value pertenezca al type, **no** que el type corresponda al producto | Revisar la pertinencia a mano antes de `--aplicar` |

## Documentación

| Documento | Responde |
|---|---|
| `AI_CONTEXT.md` | Las reglas que no se pueden romper |
| `documentacion_proyecto_php-excel.md` | Qué es el proyecto, su arquitectura y las 13 acciones de API que ejecuta |
| `documentacion_armado_e_instalaciones.md` | Cómo se crea y configura un servicio, paso a paso |
| `bitacora_trabajo_*.md` | Qué se hizo, cuándo y por qué |
| `revision-configurar-servicios.md` | Estado de la revisión por partes de la vista «Configurar servicios» |
| `investigacion-precios-bulk.md` | Qué SKUs tienen precio por volumen, con qué APIs y qué posibles errores hay |
| `evaluacion-gentle-ai.md` | Qué cambiaría al instalar Gentle-AI, riesgos, precauciones y cómo revertir |
| `docs/descarga-catalogo.md` | Qué descarga `descargar-catalogo.php`, qué endpoints consulta, qué permisos necesita y cómo consultar la copia local |
| `reporte-instalacion-gentle-ai.md` | Qué cambió y qué no al instalar Gentle-AI, pruebas y medición de continuidad |

## Tecnologías

PHP 8 · cURL · VTEX Catalog API · CSV

## Licencia

Sin licencia. Repositorio interno de trabajo sobre datos de catálogo de Cemaco, no pensado para distribución.
