# Contratos HTTP y OpenAPI

**Antes de analizar controladores para descubrir cómo consumir un endpoint, consulta el índice OpenAPI y su operación correspondiente.**

La fuente contractual es `contracts/openapi.json`, generada con Scramble desde las rutas, validaciones y Resources. `operations-index.json` permite descubrir operaciones sin cargar la especificación completa. Los metadatos manuales describen comportamiento existente; no cambian validaciones, permisos ni lógica comercial.

## Auditoría inicial — 17 de noviembre de 2026

| Área | Resultado verificado |
|---|---|
| Runtime | PHP CLI 8.5.12, Composer 2.8.12, Laravel 13.40.0 |
| Autenticación | Sanctum 4.3.3: bearer con sesión de tokens/refresh propia y cookies de SPA con CSRF |
| Registro de rutas | `bootstrap/app.php` registra web, consola, broadcasting y salud. `bootstrap/providers.php` carga el proveedor CRM, que monta `modules/crm/src/Http/api-routes.php` bajo el prefijo configurable `crm/api/v2` |
| Superficie API | 68 rutas registradas, 77 operaciones al separar PUT y PATCH. HEAD implícito de GET no se duplica |
| Dominios | Productos, variantes/opciones, leads, deals, personas, organizaciones, cotizaciones, pedidos y facturas; registro, login, logout, refresh y recuperación de contraseña |
| Inventario/POS | Sin API REST de stock, reservas, movimientos, POS o pagos. La disponibilidad del catálogo sigue siendo `unknown`; no equivale a stock disponible |
| Contratos existentes | No había Scramble, L5-Swagger ni otro generador OpenAPI. Había documentación narrativa de variantes en `docs/API/VARIANTES_V2.md` |
| Arquitectura | Controladores v2 y FormRequests en el módulo CRM, controladores de cuenta en `app/`. Resources JSON v2 con relaciones condicionales. Payloads internos construidos como objetos/arrays; sin una capa DTO pública uniforme |
| Multiempresa | Empresa derivada del usuario y scopes/policies. IDs de recursos públicos generalmente `external_id`; los campos de propietario y algunas referencias usan sus UUID internos según la validación concreta |
| Boost | 2.10.4, habilitado con configuración local `boost.json`, MCP y skills Laravel. No se reemplazó su configuración ni se ejecutaron hooks de actualización del CRM |
| Pruebas y CI previos | Pest 5.3.5, PHPUnit con SQLite `:memory:`, pruebas HTTP y de aislamiento. Workflow `tests.yml`: formato, PHPStan y pruebas. Pruebas PostgreSQL separadas |
| Seguridad de Scramble | 0.13.55 acepta Laravel 13 y PHP 8.5. Se incorporó también `spatie/laravel-package-tools` 1.93.3. Composer no actualizó otras dependencias y su auditoría no encontró avisos conocidos |

Scramble queda en `require-dev`: la generación corresponde al desarrollo y CI. La vulnerabilidad [CVE-2026-44262](https://github.com/advisories/GHSA-4rm2-28vj-fj39) afectaba 0.13.2–0.13.21 y fue corregida en 0.13.22; el constraint nuevo comienza en 0.13.55. La auditoría de dependencias es una comprobación puntual, no una garantía futura.

## Descubrimiento selectivo para agentes

Desde el backend:

```bash
php artisan api:contracts search 'products index' --limit=5
php artisan api:contracts search '/crm/api/v2/products' --limit=5
php artisan api:contracts operation crm.v2.products.index.get
php artisan api:contracts schema ProductResource
php artisan api:contracts check
```

`search` busca todos los términos, sin distinguir mayúsculas, en identificador, método, ruta, URL, tags, resumen y descripción. El límite admite 1–100 resultados. Cada fila incluye revisión SHA-256 de la operación y sus dependencias transitivas, además de estado y limitaciones.

`operation` devuelve método, path, parámetros heredados y propios, servidores, seguridad efectiva, body, respuestas y todos los componentes alcanzables. Conserva los `$ref` y adjunta sus definiciones una vez: así representa ciclos sin expandirlos indefinidamente. Incluye también los esquemas de seguridad, que OpenAPI referencia por nombre. `schema` resuelve un esquema y sus dependencias del mismo modo. No descarga referencias remotas: una referencia externa o inválida hace fallar la validación.

La salida por defecto está limitada a 128 KiB. Si se supera, el comando falla **sin truncar el contrato**. Solicitar un archivo completo o aumentar explícitamente el límite:

```bash
php artisan api:contracts operation crm.v2.products.options.apply.post --output=/tmp/operation.json
php artisan api:contracts operation crm.v2.products.options.apply.post --max-bytes=524288
```

Desde otra sesión o directorio, usar `php /ruta/al/backend/artisan ...`. `--directory=/ruta/a/contratos` permite consultar una copia externa sin inspeccionar controladores. `check` comprueba además el código del backend que ejecuta el comando; usarlo en el checkout productor, no para comparar una copia contra otro backend deliberadamente diferente.

## Generación reproducible y revisión

Usar el lockfile y una base **temporal dedicada** con el esquema migrado. Scramble utiliza los tipos de columnas; una base sin migraciones produce contratos incompletos. No hace falta sembrar datos comerciales. Este bloque mantiene los ajustes dentro de un subshell:

```bash
composer install --no-scripts --no-interaction --prefer-dist
php artisan package:discover --no-interaction
(
    contract_workspace=$(mktemp -d /tmp/qaturia-contracts.XXXXXX)
    export APP_ENV=testing APP_DEBUG=false APP_NAME=Laravel
    export SESSION_COOKIE=laravel-session LARAVEL_CRM_TEAMS=true
    export DB_CONNECTION=sqlite DB_DATABASE="$contract_workspace/schema.sqlite" DB_URL=
    export CACHE_STORE=array SESSION_DRIVER=array BROADCAST_CONNECTION=log
    touch "$DB_DATABASE"
    php artisan migrate --force --no-interaction
    php artisan api:contracts generate --allow-partial
)
php artisan api:contracts check
python3 -m jsonschema -i contracts/openapi.json tests/Fixtures/openapi-3.1-schema.json
```

Las pruebas reservan 512 MiB de memoria en `phpunit.xml` porque la inferencia completa de Scramble y las pruebas HTTP comparten proceso. Esto no cambia el límite de producción.

El validador Python requiere `jsonschema` (CI fija 4.25.1). El esquema oficial OpenAPI 3.1 está guardado, con su `$id` de origen, en `tests/Fixtures/openapi-3.1-schema.json`. Valida la estructura OpenAPI; las referencias y los identificadores se verifican en PHP. No acredita el comportamiento del endpoint ni valida datos de negocio contra cada JSON Schema.

El perfil de generación fija el nombre de cookie y el prefijo predeterminado. Si se documenta otra configuración de despliegue, generar un artefacto separado y conservar sus metadatos. La URL del servidor es relativa y no contiene hosts privados ni credenciales. El frontend debe configurar el origen del backend y conservar el prefijo `/crm/api/v2`.

Comandos oficiales disponibles en la versión instalada, útiles para diagnosticar o exportar una selección:

```bash
php artisan scramble:analyze --fail-on-empty --fail-on-unknown
php artisan scramble:export --routes=laravel-crm.api.v2.products.index --stdout --fail-on-unknown
php artisan scramble:export --path=/tmp/openapi-raw.json
```

Estos comandos también requieren la base migrada. La exportación directa no crea el índice, las revisiones ni los diagnósticos vinculados de `api:contracts generate`. `scramble:analyze --fail-on-unknown` sigue fallando mientras existan las limitaciones conocidas; no se presenta ese resultado como una comprobación exitosa. Sin `--allow-partial`, nuestro generador rechaza cualquier diagnóstico y conserva los artefactos anteriores.

## Versionado, cambios y compatibilidad

```text
contracts/
  openapi.json
  operations-index.json
  contract-version.json
  diagnostics.json
  revisions/<SHA-256-anterior>/openapi.json  # aparece al regenerar una versión existente
```

El hash del contrato corresponde al JSON canónico ordenado y guardado en `openapi.json`; se puede verificar con `sha256sum`. Las listas conservan su orden y los objetos vacíos siguen siendo objetos. El índice y los diagnósticos llevan el mismo hash. El manifest añade versión del generador, commit productor, indicación de árbol sucio y un hash de los archivos fuente relevantes. El hash de fuentes permite asociar exactamente el borrador aunque se genere antes del commit que lo incorpora; no se pretende que un commit contenga su propio SHA.

No incluir `.env`, datos de clientes, tokens, credenciales o ejemplos reales. Las revisiones previas se archivan por hash antes de reemplazar la actual. Los lectores rechazan un conjunto mezclado y un lock evita dos escritores simultáneos. Ante una interrupción durante el reemplazo de archivos, restaurar el conjunto desde Git o regenerar en un directorio nuevo; no publicar un conjunto que falle `check`.

```bash
php artisan api:contracts changes SHA256_ANTERIOR
php artisan api:contracts changes --against=/tmp/contrato-anterior/openapi.json
php artisan api:contracts changes --against=/tmp/contrato-anterior/openapi.json --fail-on-breaking
```

La política de compatibilidad es **conservadora**: cualquier operación o esquema existente modificado o eliminado requiere revisión y devuelve código distinto de cero con `--fail-on-breaking`. Detecta modificaciones en esquemas compartidos, seguridad, parámetros y respuestas, incluso transitivas. Puede señalar cambios compatibles; no sustituye un análisis semántico de todos los casos OpenAPI. Las adiciones independientes no bloquean. CI compara contra el contrato de la rama base; el primer PR establece la línea inicial. Para cambios intencionales, coordinar una nueva versión API o revisar expresamente el cambio y el gate de compatibilidad, sin desactivarlo silenciosamente.

## Sincronización con frontend y Orval

CI genera otra copia, compara bytes con el contrato versionado, ejecuta validaciones y pruebas HTTP y sube un artefacto **draft** ligado al SHA de Git. No crea releases estables ni publica contratos durante una petición de producción. La publicación estable requiere revisión de compatibilidad y de las operaciones parciales utilizadas por el consumidor.

Para copiar un borrador deliberadamente a un directorio nuevo:

```bash
php artisan api:contracts sync --destination=/tmp/frontend-contract-revision --allow-partial
php artisan api:contracts search products --directory=/tmp/frontend-contract-revision
```

`sync` valida hashes antes y después, conserva estado y diagnósticos, y rechaza sobrescribir un destino existente. Copiar siempre los cuatro archivos juntos. Otra opción es descargar el artefacto del workflow exitoso. El frontend debe fijar la revisión deseada, comprobar `contract-version.json` y revisar `status: partial` antes de usar sus tipos.

Ejemplo de `orval.config.ts` en el repositorio frontend, usando su versión instalada de Orval y su lockfile:

```ts
import { defineConfig } from 'orval';

export default defineConfig({
  qaturia: {
    input: { target: './contracts/openapi.json' },
    output: {
      mode: 'tags-split',
      target: './src/api/generated/qaturia.ts',
      schemas: './src/api/generated/models',
      client: 'fetch',
      baseUrl: '/crm/api/v2',
    },
  },
});
```

Ejecutar `npx orval --config orval.config.ts` en ese frontend. Orval 8 requiere Node >=22.18; el smoke test fija Node 22.19.0 para cubrir también sus dependencias transitivas. No se añadieron dependencias Node al backend. Configurar el origen/prefijo del servidor y un fetch/mutator que aplique el mecanismo de autenticación elegido. La generación de TypeScript no demuestra que un tipo inferido sea correcto: mantener pruebas de integración del consumidor y revisar las limitaciones siguientes. Referencia: [cliente Fetch de Orval](https://orval.dev/docs/guides/fetch/).

## Autenticación, empresa y errores

- Bearer: `POST /auth/token` recibe email, password y device_name opcional. Devuelve token/access_token, tipo Bearer, vencimientos, refresh_token y usuario. Enviar `Authorization: Bearer ...` y `Accept: application/json`. Usar `/auth/refresh` para rotación y `/auth/token/logout` para revocar la familia. La implementación comprueba la sesión del token además de Sanctum.
- SPA: obtener primero `GET /sanctum/csrf-cookie`, enviar cookies con credentials, Origin configurado y `X-XSRF-TOKEN`; luego `POST /auth/login`. La ruta CSRF de infraestructura no se incluye como operación comercial, pero está descrita en el esquema de seguridad de sesión. Un origen no configurado o CSRF ausente puede devolver 419.
- El usuario determina la empresa. `X-Team-ID` es una comprobación opcional de esa misma empresa, no permite cambiar de tenant. Hay condiciones adicionales de acceso CRM y permisos por operación.
- Se documentan 401 de autenticación, 403 de acceso/policy, 404 de binding/scoping, 422 de validación y 409 donde el catálogo comprueba versión, plan o idempotencia. El throttling puede devolver 429. No todos los endpoints tienen todos los estados.
- Los Resources conservan relaciones opcionales mediante `whenLoaded`, importes en unidades mayores y cantidades decimales. Productos incluyen como máximo 100 variantes y su indicador de truncamiento; usar la lista paginada para obtenerlas todas.

## Limitaciones verificadas y correcciones propuestas

El detalle exacto está en `contracts/diagnostics.json`; `get_operation` (acción `operation`) adjunta los diagnósticos aplicables y el índice marca `partial`. Ningún contrato se promueve automáticamente a estable. Los grupos pendientes son:

| Operaciones o campos | Problema y siguiente corrección concreta |
|---|---|
| `products.options.*`, `products.variations.generate*`, `products.legacy.*` | Comandos, planes y resultados construidos mediante arrays dinámicos. Scramble puede inferir `command` como lista en lugar de objeto y dejar campos desconocidos. Añadir tipos de forma reutilizables o un transformer específico a partir de las validaciones de `ProductVariantMatrixService::plan`, con pruebas por acción. Consultar también `docs/API/VARIANTES_V2.md` antes de consumir estas operaciones parciales |
| PATCH de opción y valor | `swatch_hex` depende del argumento `$value`: prohibido al editar una opción, nullable/regex al editar un valor. La inferencia genera VR002. Añadir metadatos condicionados a la ruta, conservando ambos comportamientos y pruebas de validación |
| Escrituras de quotes/orders/invoices | `subtotal` y `total` están prohibidos y se calculan en el servidor. Scramble no representa fielmente esa regla y genera tipos desconocidos. No enviarlos. Añadir un transformador para los valores vacíos permitidos por `prohibited`, o documentar su ausencia con pruebas sin debilitar la validación |
| Recursos y planes con helpers dinámicos | Algunos IDs/colecciones, defaults y valores de planes no tienen tipo completo en inferencia. Añadir PHPDoc de forma precisa en los helpers identificados por los diagnósticos y comprobar los Resources serializados |
| Campos aceptados frente a comportamiento | En productos, `active` se valida en el FormRequest pero no se traslada en `buildPayload`. No asumir que ese campo cambia el producto; requiere una corrección de negocio explícita y sus pruebas |

Las APIs de stock, reservas, POS y pagos no se inventaron. Backoffice Blade/Livewire, usuarios/roles administrativos, portales firmados, tracking, chat embed, broadcasting, salud y herramientas de desarrollo quedan fuera de la allowlist de documentación. Toda ruta pública nueva debe pasar revisión explícita antes de añadirse a `config/api-contracts.php`.

## Pedir o modificar endpoints

1. Buscar una operación existente y revisar contrato y estado; comprobar si cumple el caso de uso.
2. Si falta, describir actor/permisos, empresa, entrada, salida, errores, paginación y criterios de aceptación. No inventar una URL en frontend.
3. Implementar el cambio autorizado con pruebas HTTP reales, incluyendo aislamiento y permisos cuando corresponda.
4. Añadir el nombre de ruta a la allowlist tras revisar su exposición; mantener el `operationId` estable mientras el contrato siga siendo el mismo.
5. Regenerar con esquema temporal, revisar diagnósticos y diff, ejecutar `check`, validación estructural y pruebas; coordinar cambios incompatibles con frontend.

## Evidencia local de esta implementación

- 67 pruebas focalizadas: 421 aserciones, todas correctas; incluye repositorio de contratos, generación real, autenticación y matriz de variantes.
- 4 pruebas adicionales de aislamiento y registro: 26 aserciones, todas correctas.
- PHPStan completo: cero errores usando `--debug --memory-limit=1G` (el PHP estático local no puede cargar la extensión turbo y el sandbox restringe el socket del modo paralelo).
- Pint `--dirty --format agent`, `composer validate --strict` y esquema oficial OpenAPI: correctos.
- `composer audit --locked`: sin avisos ni paquetes abandonados en la consulta realizada.
- Orval 8.48.0 con Node 22.19.0: 332 archivos TypeScript generados y comprobados con `tsc --noEmit` en un directorio temporal. No se instaló Node ni Orval en las dependencias del backend.
- Perfil exportado: 77 operaciones, 41 esquemas, 55 operaciones inferidas y 22 parciales. Se conservan 44 diagnósticos; son limitaciones conocidas y la comprobación estricta de Scramble sigue fallando.
- Descubrimiento de dos operaciones: 846 bytes; contrato completo de productos con dependencias: aproximadamente 26 KiB frente a la especificación global completa.
- La ejecución HTTP en entorno de producción no registra `/docs/api` ni `/docs/api.json`.
- La suite general inicial sin APP_KEY tuvo errores de cifrado de entorno. Repetida con una clave exclusiva de pruebas: 210 pruebas, 208 correctas, 1757 aserciones y los dos fallos de traducciones/moneda reproducidos por separado también en el commit base.

## Evidencia automatizada

- `ApiContractRepositoryTest`: referencias compartidas/cíclicas, metadata heredada, búsquedas, hash canónico, referencias inválidas, duplicados y cambios de contratos transitivos.
- `ApiContractsTest`: generación real desde el módulo, allowlist, PUT/PATCH, esquemas, respuestas, recuperación selectiva, límite de salida, integridad y ausencia HTTP fuera de local.
- Pruebas funcionales existentes de autenticación, catálogo y empresa: acreditan comportamiento real de endpoints; el contrato generado no las sustituye.
- `.github/workflows/api-contracts.yml`: auditoría Composer, pruebas funcionales, integridad/frescura, reproducibilidad, esquema oficial, generación real con Orval, revisión conservadora y artefacto draft.

Para actualizar solo la documentación no ejecutar migraciones contra una base compartida. Para verificar que está al día: `api:contracts check` y reproducir la generación en el perfil anterior. Los resultados locales y límites de herramientas se registran en el PR de esta implementación. La suite previa tiene dos fallos reproducidos también en un checkout temporal del commit base: desajuste de claves de traducción española y una expectativa de moneda distinta de PEN, aunque PEN es ahora el valor predeterminado. El workflow específico de contratos selecciona las pruebas de aislamiento API relacionadas; el workflow general conserva toda su cobertura y esos fallos no se ocultaron ni modificaron.
