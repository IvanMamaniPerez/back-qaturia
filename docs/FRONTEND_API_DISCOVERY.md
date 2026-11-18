# Guía para conectar el proyecto frontend con el API backend Qaturia

Este documento se puede copiar al repositorio consumidor. El backend es un servicio Laravel de endpoints HTTP JSON. La integración se realiza mediante contratos OpenAPI y consultas HTTP de documentación; no requiere acceso a sus controladores, carpetas ni comandos Artisan.

**Regla para el agente del proyecto consumidor: antes de implementar una llamada o inferir una ruta, consultar descubrimiento, buscar la operación y recuperar su contrato completo con sus esquemas relacionados.**

## 1. Datos necesarios para conectarse

| Dato | Cómo obtenerlo |
|---|---|
| Origen del backend | Lo proporciona el responsable del despliegue. Ejemplo de desarrollo: `http://localhost:8000`. No es el origen del proyecto frontend |
| Entrada de descubrimiento | `GET /.well-known/api-contracts` en ese origen |
| Acceso a documentación | Libre únicamente cuando el backend está en `local`. Fuera de local requiere sesión de un usuario autorizado por `viewApiDocs` |
| Usuario lector autorizado | El responsable del backend configura su UUID en `API_DOCS_USER_IDS`, o lo autoriza mediante un gate personalizado |
| Prefijo de autenticación | Por defecto `/crm/api/v2`; confirmar con el responsable del backend si el despliegue cambia ese prefijo |

No es necesario conocer ni fijar `/docs/api/contracts` en la aplicación: descubrimiento devuelve sus enlaces y respeta la ruta configurada por el backend.

Fuera de local, un bearer de la API comercial **no basta para consultar la documentación**, porque estas rutas usan sesión y `viewApiDocs`. La autenticación de documentación y la de operaciones comerciales deben tratarse según sus contratos respectivos. El acceso de lectura a documentación no otorga permisos comerciales.

La exploración corresponde al trabajo de integración de desarrolladores y agentes. No conceder acceso a documentación a todos los usuarios finales ni consultar el índice en cada interacción del producto.

## 2. Abrir una sesión autorizada cuando sea necesario

Si ya existe una sesión válida del backend, pasar al descubrimiento. Para iniciar sesión desde un cliente web autorizado:

1. Solicitar `GET /sanctum/csrf-cookie` con `credentials: 'include'`.
2. Leer la cookie `XSRF-TOKEN`, decodificarla y enviarla como `X-XSRF-TOKEN`.
3. Ejecutar `POST /crm/api/v2/auth/login` con JSON `{email, password}` y `credentials: 'include'`.
4. Conservar las cookies en las siguientes consultas. La respuesta de login devuelve el usuario; esta operación no emite un bearer.

El usuario debe poder autenticarse en el API: la implementación actual exige acceso CRM y, cuando está habilitado el modo multiempresa, una empresa válida asociada. Ser lector de documentación no omite estos requisitos del login.

El backend debe permitir el origen exacto mediante `CORS_ALLOWED_ORIGINS`, reconocer su host/puerto en `SANCTUM_STATEFUL_DOMAINS` y tener cookies compatibles con los dominios y HTTPS del despliegue. CORS por sí solo no configura el dominio ni la política de cookies.

Ejemplo independiente de cualquier framework; las credenciales se reciben durante la autenticación y no se escriben en el código:

```js
const BACKEND_ORIGIN = 'http://localhost:8000'; // Sustituir por el origen configurado.
const AUTH_BASE_PATH = '/crm/api/v2'; // Confirmar si el despliegue lo personaliza.

async function openDocumentationSession(email, password) {
  const csrfResponse = await fetch(`${BACKEND_ORIGIN}/sanctum/csrf-cookie`, {
    credentials: 'include',
    headers: { Accept: 'application/json' },
  });
  if (!csrfResponse.ok) throw new Error(`CSRF: HTTP ${csrfResponse.status}`);

  const cookie = document.cookie.split('; ').find(value => value.startsWith('XSRF-TOKEN='));
  if (!cookie) throw new Error('La cookie XSRF-TOKEN no es legible en este origen; revisar la configuración de cookies con el backend.');

  const response = await fetch(`${BACKEND_ORIGIN}${AUTH_BASE_PATH}/auth/login`, {
    method: 'POST',
    credentials: 'include',
    headers: {
      Accept: 'application/json',
      'Content-Type': 'application/json',
      'X-XSRF-TOKEN': decodeURIComponent(cookie.slice('XSRF-TOKEN='.length)),
    },
    body: JSON.stringify({ email, password }),
  });
  if (!response.ok) throw new Error(`Login: HTTP ${response.status}`);
  return response.json();
}
```

Este ejemplo usa el almacén de cookies del navegador. Un proceso Node, cURL o una herramienta de agente debe mantener explícitamente su cookie jar; `credentials: 'include'` en Node no crea por sí solo ese almacén. No guardar contraseñas, tokens ni cookies en Git o en archivos públicos.

## 3. Descubrir y explorar operaciones

Conocer únicamente el origen permite comenzar por:

```http
GET /.well-known/api-contracts
Accept: application/json
```

La respuesta contiene `contract_sha256`, `status`, `operation_count`, `schema_count`, `links`, `parameters` y `limits`. Seguir `links.search`, `links.operation`, `links.schema` y `links.changes`; usar los nombres de parámetros indicados en el descriptor.

Cliente mínimo para una sesión web ya autorizada, o para desarrollo local sin login:

```js
async function getDocumentation(url) {
  const response = await fetch(url, {
    credentials: 'include',
    headers: { Accept: 'application/json' },
  });
  const payload = await response.json();
  if (!response.ok) {
    const error = new Error(payload.message || `HTTP ${response.status}`);
    error.status = response.status;
    error.details = payload;
    throw error;
  }
  return payload;
}

const descriptor = await getDocumentation(new URL('/.well-known/api-contracts', BACKEND_ORIGIN));

async function queryDocumentation(action, parameters = {}) {
  if (!descriptor.links[action]) throw new Error(`Consulta de documentación desconocida: ${action}`);
  const url = new URL(descriptor.links[action]);
  for (const [key, value] of Object.entries(parameters)) {
    url.searchParams.set(key, String(value));
  }
  const result = await getDocumentation(url);
  if (result.contract_sha256 !== descriptor.contract_sha256) {
    throw new Error('El contrato cambió durante la exploración; volver a obtener descubrimiento y repetir las consultas.');
  }
  return result;
}

const matches = await queryDocumentation('search', { q: 'products index', limit: 5 });
if (matches.operations.length > 0) {
  const operation = await queryDocumentation('operation', {
    operationId: matches.operations[0].operationId,
  });
  // Revisar operation.contract y operation.components antes de implementar la llamada.
}

const productSchema = await queryDocumentation('schema', { schemaName: 'ProductResource' });
```

Para un agente con una sesión ya guardada, se pueden consultar los mismos enlaces con cURL:

```bash
BACKEND_ORIGIN='http://localhost:8000'
COOKIE_JAR='/ruta/privada/sesion-autorizada.cookies'
curl --fail --silent --show-error --cookie "$COOKIE_JAR" \
  -H 'Accept: application/json' \
  "$BACKEND_ORIGIN/.well-known/api-contracts"
```

En `local`, omitir `--cookie` si no hay sesión. Para las siguientes peticiones usar las URLs devueltas por el descriptor; no hace falta un checkout del backend.

### Parámetros y datos devueltos

| Consulta | Parámetros | Datos principales |
|---|---|---|
| Búsqueda | `q`, `limit` opcional | `total` y `operations[]`, con identificador, método, ruta, tags, resumen, revisión y limitaciones |
| Operación | `operationId` | `contract.method`, `contract.path`, `contract.operation` y `components` necesarios |
| Esquema | `schemaName` | Referencia al esquema y definiciones transitivas en `components.schemas` |
| Cambios | `revision` | Revisiones `from`/`to`, operaciones y esquemas añadidos/modificados/eliminados y `requires_review` |

La búsqueda combina todos los términos sin distinguir mayúsculas y minúsculas. Buscar nombres presentes en el contrato, por ejemplo `products`, `people`, `organizations`, `leads`, `deals`, `quotes`, `orders`, `invoices` o `auth`. No hay búsqueda semántica ni traducción automática de términos.

Las respuestas de operación conservan los `$ref` y adjuntan sus definiciones transitivas una vez, incluso si existen ciclos. No son una especificación OpenAPI global con `paths`; el objeto `contract` es la operación seleccionada.

## 4. Interpretar el contrato antes de implementar una llamada

- Combinar el servidor de `contract.operation.servers` con `contract.path`. Por ejemplo, `/crm/api/v2` + `/products` produce `/crm/api/v2/products`; no perder el prefijo al construir la URL.
- Sustituir parámetros de ruta y construir filtros, headers y body según sus esquemas. No deducir endpoints a partir de nombres de componentes de la aplicación.
- Revisar `security`, campos obligatorios, opcionales y nullable, formatos, enums, relaciones condicionales y paginación. Usar los identificadores y unidades que declara cada operación.
- Las operaciones comerciales aceptan Sanctum según el contrato. Para un flujo bearer, consultar `auth.token.issue`, `auth.refresh` y las operaciones de revocación mediante búsqueda; no confundir ese bearer con la sesión que protege la documentación.
- La empresa se deriva del usuario. `X-Team-ID`, cuando aparece, solo comprueba la misma empresa; no permite seleccionar otra.
- Revisar `status` de la operación en el índice y `x-contract-status`, `x-contract-limitations` y `x-contract-diagnostics` en su contrato. Si es `partial`, no tratar los tipos inferidos como definitivos: coordinar la aclaración con backend.
- El catálogo declara disponibilidad `unknown`; no interpretarla como existencia de stock. No inventar APIs de inventario, POS, reservas o pagos si la búsqueda no las devuelve.

La línea inicial contiene 77 operaciones y 41 esquemas, con 22 operaciones parciales. Estas cifras sirven como referencia histórica: obtener las actuales desde descubrimiento. Que el descriptor tenga `status: draft` no acredita que el contrato esté aprobado para una versión estable.

## 5. Revisiones y errores que debe manejar el consumidor

Guardar `contract_sha256` con la integración revisada. También aparece como `X-Contract-Revision`, aunque para un cliente entre orígenes es suficiente leer el campo JSON.

```js
const changes = await queryDocumentation('changes', { revision: PREVIOUS_CONTRACT_SHA256 });
```

`PREVIOUS_CONTRACT_SHA256` debe ser un hash guardado por el consumidor. Si esa revisión ya no está disponible, el backend devuelve 404: volver a explorar las operaciones utilizadas y revisar sus contratos. No asumir compatibilidad ni ausencia de cambios.

| Estado | Qué revisar |
|---|---|
| 401 en una operación comercial | Credencial o sesión de token inválida/expirada; seguir el contrato de autenticación |
| 403 en documentación | Sesión ausente/expirada o usuario no autorizado por `viewApiDocs`; pedir al responsable del backend que revise el lector permitido |
| 404 | Identificador de operación, esquema o revisión inexistente; en una operación comercial también puede representar scoping/binding |
| 413 | Aumentar `max_bytes` según `required_bytes`, sin superar 1048576; el backend no truncó el contrato |
| 419 durante login | Cookies, origen reconocido para sesiones o header CSRF incorrectos; verificar la configuración con backend |
| 422 | Revisar `errors`: parámetros inválidos/desconocidos, `limit` o identificadores; el servicio de documentación rechaza acciones de escritura |
| 429 | Respetar `Retry-After` y reducir frecuencia; la documentación admite 60 consultas/minuto por usuario o IP local |
| 503 en documentación | Artefactos ausentes o inconsistentes; el backend debe regenerarlos/publicarlos, sin inventar contratos desde el cliente |

`limit` admite 1–100 resultados; `q`, hasta 200 caracteres. Las respuestas tienen un límite predeterminado de 128 KiB. Para una operación grande:

```js
const operation = await queryDocumentation('operation', {
  operationId: 'crm.v2.products.options.apply.post',
  max_bytes: 1048576,
});
```

Para datos que superen ese límite, solicitar el artefacto validado. No recortar esquemas ni eliminar referencias para hacerlos caber.

## 6. Cliente TypeScript opcional mediante Orval

Para generación de clientes, solicitar al backend el artefacto del workflow validado o la copia de contrato acordada: `openapi.json`, `operations-index.json`, `contract-version.json` y `diagnostics.json`. Verificar que su hash y revisión coincidan. La consulta selectiva no sustituye al documento global requerido por Orval; `/docs/api.json` no se publica en producción.

La versión de Orval y su configuración pertenecen al repositorio frontend y deben fijarse en su lockfile. Este patrón se comprobó con Orval 8.48.0 y Node 22.19.0:

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

Configurar el origen real, prefijo y mecanismo de autenticación del cliente según el despliegue; el `baseUrl` relativo del ejemplo presupone que las llamadas llegan al backend bajo ese prefijo. No copiarlo sin adaptar si el consumidor usa otro origen. No usar las respuestas envueltas de `action=operation` como entrada directa de Orval.

## Instrucción para el agente que recibe esta guía

Trabaja en el proyecto consumidor. Primero consulta `/.well-known/api-contracts`; después busca por dominio o intención, recupera la operación elegida y revisa sus esquemas y limitaciones. Conserva el hash revisado y construye la integración con su contrato real. Si falta una operación o el resultado es parcial, documenta la necesidad y coordina con backend antes de inventar una ruta, un payload o un tipo. No solicites acceso a controladores ni una copia completa del repositorio backend para descubrir endpoints que el servicio ya documenta.
