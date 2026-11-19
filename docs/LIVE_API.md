# QAT-04: contrato LIVE v1

LIVE es un dominio HTTP independiente del CRM. Su contrato oficial es el conjunto generado en `contracts/`; este documento explica sus decisiones semánticas y condiciones de integración. No contiene una especificación OpenAPI alternativa.

## Puesta en servicio

Aplicar las migraciones mediante el procedimiento habitual del entorno y ejecutar `php artisan db:seed --class=LivePermissionsSeeder --no-interaction`. El seeder crea `live.ingest` y `live.read` sin asignarlos automáticamente a roles. `DatabaseSeeder` también lo incluye. Los seeders `LiveSessionSeeder`, `LiveConnectorSeeder`, `LiveEventSeeder` y `LiveEventReceiptSeeder` son muestras optativas: crean empresas/datos ficticios y no forman parte de la instalación normal.

El propietario real de la empresa tiene acceso LIVE. Para colaboradores, asignar permisos mediante el mecanismo Spatie existente y dentro del contexto de su empresa. `live.ingest` permite crear sesiones, autorizar instancias, revocarlas e ingerir; también permite consultar. `live.read` solo permite consultar. Tener acceso CRM o un dispositivo marcado `is_trusted` no otorga estos permisos. Los tokens Sanctum actuales con `*` funcionan; un token restringido necesita `live.ingest` para escribir, o `live.read`/`live.ingest` para leer.

Se reutilizan Sanctum y `EnsureApiTokenSessionIsValid`, incluidas expiración, revocación y huella de contraseña. Las sesiones frontend conservan el flujo CSRF existente. La empresa siempre se obtiene del usuario autenticado. `X-Team-ID`, si se envía, solo confirma esa identidad; otra empresa devuelve 403. `businessId` y `team_id` están prohibidos en las escrituras LIVE.

En el contenedor de desarrollo auditado la cola es Redis, Octane usa FrankenPHP y existe Reverb. El CLI local resuelve `database` para colas. QAT-04 aplica las pequeñas proyecciones dentro de la transacción de ingestión y distribuye señales mediante consultas HTTP. No publica por Reverb ni agrega canales, Horizon o servicios. La autorización empresarial permanece en el contexto scoped existente; los servicios LIVE no retienen identidades entre requests.

## Operaciones publicadas

Los paths siguientes son relativos al origen del backend, sin el prefijo CRM. La raíz OpenAPI conserva el servidor CRM; los paths LIVE lo sobrescriben con `servers: [{url: "/"}]`. Los consumidores deben respetar esa sobrescritura. Orval 8.48 admite `baseUrl: { getBaseUrlFromSpecification: true }`; la fixture de generación del backend lo utiliza y el consumidor actual también tiene esa opción. No anteponer `/crm/api/v2` a los paths LIVE.

| Método y path | operationId | Resultado |
|---|---|---|
| POST `/api/v1/live/sessions` | `live.v1.sessions.store.post` | Autorizar una sala e inicializar su sesión interna |
| GET `/api/v1/live/sessions` | `live.v1.sessions.index.get` | Sesiones de la empresa, más recientes primero |
| GET `/api/v1/live/sessions/{session}` | `live.v1.sessions.show.get` | Estado y resumen de observaciones |
| POST `/api/v1/live/sessions/{session}/connectors` | `live.v1.connectors.store.post` | Autorizar usuario, dispositivo e instancia para esa sesión |
| DELETE `/api/v1/live/sessions/{session}/connectors/{connector}` | `live.v1.connectors.destroy.delete` | Revocar la autorización de esa instancia |
| POST `/api/v1/live/ingest/batches` | `live.v1.events.ingest.post` | Recepción durable por lote |
| GET `/api/v1/live/sessions/{session}/events` | `live.v1.events.index.get` | Historial canónico con payload y estado de eliminación del comentario |
| GET `/api/v1/live/sessions/{session}/coverage` | `live.v1.coverage.index.get` | Intervalos observados por instancia/época |

Los identificadores públicos LIVE son UUID. Las creaciones de sesión/instancia devuelven 201 con Resource `data`; su reintento compatible devuelve 200 y el mismo recurso. Una identidad reutilizada para otra sala/instancia devuelve 409. La revocación devuelve 200 con `revokedAt`; una instancia revocada requiere un nuevo UUID, no se reactiva silenciosamente.

Las listas aceptan `page` y `per_page` (1–100, predeterminado 25). El historial acepta `eventType` exacto y ordena por `observedAt` e ID interno. Sesiones e historial usan la paginación estándar de Resources (`data`, `links`, `meta`), conservando filtros en los enlaces. Cobertura usa `data` y `meta: {page, perPage, total}`. Una sesión de otra empresa devuelve 404, también al intentar registrar una instancia o consultar eventos/cobertura.

## Secuencia de integración

1. Autenticarse mediante el API nativo existente. La autenticación comercial y el acceso de lectura a OpenAPI son controles distintos.
2. Crear la sesión con `clientSessionId` UUID, `platform: "tiktok"`, `sourceRoomId` string y opcionalmente `creator`/`title`. Conservar el `id` interno devuelto. `clientSessionId` identifica este ciclo de seguimiento y debe compartirse al unir otro dispositivo a la misma sesión; una sala no constituye por sí sola una identidad interna. No se fusionan automáticamente sesiones creadas por separado, aunque indiquen la misma sala.
3. Registrar `connectorInstanceId` UUID y `connectorKind: "desktop" | "android"` en esa sesión. Si se envía `deviceId`, debe pertenecer al usuario autenticado. Si se omite, el backend registra un UUID propio en la tabla `devices` existente y lo devuelve. Esta autorización es de cuenta e instancia; no es una prueba criptográfica del hardware.
4. Crear un `connectionEpoch` UUID para cada conexión nueva y una secuencia entera monotónica propia de esa instancia/época. Una reconexión a la misma sala mantiene sesión e instancia y cambia la época.
5. Enviar `{batchId, contractVersion: 1, liveSessionId, events}` al endpoint de ingestión. El lote contiene eventos de una sola sesión; cada envelope repite su `clientSessionId`, sala, plataforma e instancia para comprobarlos.
6. Retirar de la cola local eventos `accepted` y `duplicate`; revisar los códigos de `rejected`. Tras timeout/503/resultado desconocido, conservar exactamente las identidades y reenviar. Cambiar `batchId` es posible: la idempotencia pertenece a los eventos, no al lote.
7. Consultar resumen, historial e intervalos para señales básicas. No hay push LIVE por Reverb en esta entrega.

El frontend debe generar los tipos HTTP a partir del OpenAPI descargado por descubrimiento, siguiendo [API_CONTRACTS.md](API_CONTRACTS.md) y sus pins. Puede inspeccionar `live.v1.events.ingest.post` con `api:contracts operation`; la entrada para Orval es el OpenAPI global descargado.

## Envelope y semántica de aceptación

La fixture [live-v1.batch.json](../tests/Fixtures/live-v1.batch.json) contiene los doce tipos normalizados. Los esquemas `Live*V1Envelope` se generan en Scramble desde las mismas reglas que usa `LiveEventSchema`. El envelope y el payload solo permiten sus campos declarados; el payload requiere al menos una propiedad. `schemaVersion` es el entero 1. El backend asigna el tiempo `receivedAt`, empresa, UUID internos y estado de procesamiento.

`observedAt` y el par opcional `occurredAt`/`occurredAtSource` usan RFC3339 con zona explícita y hasta seis decimales. La persistencia normaliza a UTC y conserva microsegundos. `occurredAtSource` distingue `platform` y `connector`: es procedencia declarada por el conector, no una atestación del servidor TikTok. No se rellena `occurredAt` a partir de un reloj local. `sourceKind` distingue `platform`, `connector` y `derived`.

Límites: 100 eventos/lote, 1 MiB de body y 16 KiB por evento serializado, además de los límites por campo del esquema. La frecuencia predeterminada es 120 requests/minuto por usuario y empresa, compartida entre las operaciones LIVE. Caption y viewer-picks se habilitan únicamente mediante configuración del backend, no con flags enviados por el cliente.

| Situación | HTTP y efecto |
|---|---|
| Lote malformado, vacío o versión de transporte incompatible | 422, sin ingestión |
| Sin credenciales válidas, token expirado/revocado o huella de contraseña distinta | 401 |
| Sin permiso, capacidad de token insuficiente, dispositivo ajeno o assertion de empresa distinta | 403 |
| Sesión ajena/inexistente | 404 |
| Body excesivo / límite de frecuencia | 413 / 429 |
| Evento inválido, tipo reservado/no soportado, versión de esquema incompatible o instancia no autorizada | 200 con resultado individual `rejected`; los hermanos válidos se guardan |
| Persistencia de la ingestión falla | 503 `PERSISTENCE_UNAVAILABLE`; la transacción completa se revierte y no hay resultados `accepted` |
| Commit correcto | 200 con resultados en el orden del lote |

Cada resultado tiene `eventId` (nullable cuando falta), `status`, `code`, `canonicalEventId` y `errors` (mapa JSON, vacío `{}` si no hay errores). Los códigos individuales son `INVALID_EVENT`, `EVENT_TOO_LARGE`, `UNSUPPORTED_SCHEMA_VERSION`, `UNSUPPORTED_EVENT_TYPE`, `FEATURE_DISABLED`, `SESSION_MISMATCH`, `CONNECTOR_NOT_AUTHORIZED` e `IDEMPOTENCY_CONFLICT`.

`accepted` confirma ingestión y proyecciones básicas comprometidas en base de datos. `duplicate` referencia el registro canónico ya durable. Un ACK no acredita inteligencia comercial, identificación de productos ni otro procesamiento futuro. No se reconoce ningún evento antes de completar el commit del lote. Los reintentos de una respuesta perdida son seguros.

## Identidad e idempotencia

Se bloquea transaccionalmente la fila de la sesión para ordenar escritores concurrentes. PostgreSQL conserva además restricciones únicas independientes del proceso:

- `(business_id, event_id)` para la identidad del evento/observación.
- `(live_connector_id, idempotency_hash)` para reintentos de la instancia.
- `(live_connector_id, connection_epoch, sequence)` para identidad de transporte.
- `(live_session_id, canonical_key)` para la representación canónica.

Una coincidencia de identidad de transporte con contenido diferente es `IDEMPOTENCY_CONFLICT`. La huella incluye todos los campos normalizados del envelope, incluso `eventId` e `idempotencyKey`, y ordena claves para ignorar solo el orden de propiedades. Cada dispositivo crea sus propios IDs de observación; no debe reutilizar el UUID de otro dispositivo.

Cuando existe un `sourceMessageId` estable, la clave canónica incluye tipo normalizado e ID de fuente dentro de la sesión. Moderación distingue también el tipo original; control y cierre distinguen la acción/estado. Para acciones sociales incluye además la acción `follow`/`share`/`unknown`. `SOCIAL` y su notificación derivada deben llevar el mismo ID del mensaje de origen y acción; así generan un solo follow/share, aunque tengan IDs de observación distintos. Una acción diferente obtiene otra identidad. La primera representación canónica se conserva y las observaciones adicionales quedan en receipts, incluido su envelope; no se sobreescribe el evento fuente.

Los estados de conector y eventos con `sourceKind: connector` siempre usan identidad de transporte. Sin ID de fuente estable, `identityQuality` es `transport_only`: se deduplican reintentos, pero dos dispositivos o secuencias distintas conservan observaciones parecidas. No se inventa una identidad a partir del texto, usuario o proximidad temporal. Esto puede conservar duplicados inciertos, especialmente acciones sociales sin ID; los contadores son observaciones canónicas, no garantía de acciones humanas únicas. Una sesión nueva aísla su espacio de identidades.

## Señales y proyecciones

- Comentarios: `CHAT`/`QUESTION_NEW`, texto e identidad observada del autor. Avisos `NOTICE`/`SYSTEM` se almacenan como otro tipo y no aparecen como comentarios espontáneos.
- Likes: se conserva por muestra `increment`, `total`, calidad y tiempo; se exige al menos uno de los dos valores. El resumen usa el último total observado y no suma totales repetidos. Las muestras de solo incremento no borran el último total conocido.
- Audiencia: separa `concurrent`, `cumulative` y `popularity`; exige algún contador. No infiere espectadores únicos ni atribución a productos.
- Social: follow/share se cuentan por identidad canónica. `unknown` se conserva aparte y no incrementa follow/share.
- Moderación: conserva `IM_DELETE`, `UNAUTHORIZED_MEMBER`, `MSG_DETECT`, `ROOM_VERIFY`, `ACCESS_CONTROL`, `ACCESS_RECALL` y `PERCEPTION`. `IM_DELETE.targetMessageId` marca la proyección del comentario con referencia exacta, incluso si la eliminación llega primero. El payload original permanece intacto. Los demás avisos no implican una suspensión confirmada.
- Control: un fin `confirmed` requiere observación de origen `platform` y `CONTROL_MESSAGE`/`STREAM_END`. Las notificaciones repetidas no cierran dos veces la sesión. La confirmación es un estado terminal; suspensión, detención manual y fin no verificado son distintos y una reconexión no los levanta automáticamente. `resume` levanta la retención administrativa cuando corresponde, salvo fin confirmado.
- Estado: `initialized`, conexión/seguimiento, `reconnecting`, `suspended`, `stopped`, `ended_unverified`, `ended_confirmed`. Se conserva además el estado observado por instancia. Si otro observador sigue activo, desconectar una instancia no detiene el seguimiento agregado.
- Título: las muestras de metadata actualizan el título según tiempo observado y conservan historial sin crear otra sesión.
- Captions: `LIVE_CAPTIONS_ENABLED=false` por defecto. Al habilitarlo acepta 1–20 segmentos con texto, `startMs` y `endMs >= startMs`; no genera transcripción adicional. `LIVE_VIEWER_PICKS_ENABLED=false` controla el diagnóstico opcional.
- `WEBSOCKET_DATA`, `DECODED_DATA` y `RAW_DATA` solo admiten un estado/resumen y contadores operativos acotados; campos binarios o payloads crudos son inválidos.

`live_events` es la fuente inmutable mediante sus modelos y el API. Los receipts preservan observaciones de transporte; `live_comment_states`, los resúmenes de sesión/instancia y `live_coverage_intervals` son reconstruibles. `php artisan live:rebuild-projections UUID --no-interaction` reconstruye una sesión mediante sus eventos y receipts, en transacción. El comando es una operación interna de mantenimiento, sin ruta HTTP pública.

Cobertura registra primera/última observación y cierre observado por instancia/época. Los huecos entre épocas y el tiempo posterior a `lastObservedAt` son no verificados; un intervalo abierto no garantiza continuidad hasta ahora. No se unen ni facturan intervalos superpuestos y no se calculan minutos comerciales. `coverageQuality: observed_only` declara esta limitación. No se implementa análisis visual, leads/pedidos automáticos ni procesamiento de los tipos reservados `live.evidence.captured.v1`, `live.product.exposure.v1` y `live.product.match.v1`; actualmente se rechazan como no soportados.

## Validación y condiciones pendientes

Pruebas HTTP focalizadas: `php artisan test --compact tests/Feature/LiveIngestionTest.php tests/Feature/LiveContractTest.php tests/Tenancy/LiveBusinessIsolationTest.php`.

Concurrencia real: `vendor/bin/pest --configuration=phpunit.live-pgsql.xml --compact`. Requiere una base vacía dedicada `qaturia_live_test`; `PostgresTestCase` rechaza cualquier base diferente a las dos bases de pruebas expresamente permitidas. Los workers usan conexiones independientes y compiten por un lock real de sesión. El job `live-postgres` reproduce el escenario en CI.

Contrato: ejecutar el perfil SQLite temporal migrado de [API_CONTRACTS.md](API_CONTRACTS.md) y `composer api:docs`. Validar fixtures con `python3 -m unittest discover -s tests/Support -p test_live_contract.py` usando `jsonschema`; CI fija la misma dependencia que ya utilizaba para OpenAPI. Los tests verifican las ocho operaciones LIVE exactas, las 77 CRM preservadas, tipos de versión, payloads cerrados, IDs/rutas y baseline de 44 diagnósticos.

La fixture normalizada es transferible al consumidor para contrastarla con sus nuevos esquemas Zod. El `@qaturia/contracts` auditado todavía define eventos internos `comment.received`, `evidence.updated`, `status.changed`, etc., y un envelope distinto con `businessId`/`receivedAt` suministrados por cliente. Su cliente anterior usa `/api/live/events`. Es necesario adaptar esa salida al contrato normalizado, incluir el tag `LIVE` o sus operationIds en la selección del consumidor y regenerar Orval; esos esquemas internos no prueban compatibilidad con QAT-04.

**QAT-04 no se considera completada extremo a extremo:** queda pendiente recibir eventos desde el conector real con el adaptador frontend actualizado y comprobar reintentos/reconexión sobre una sala autorizada. Las pruebas HTTP/fixtures y la generación de cliente no sustituyen esa prueba. No se modificó el frontend ni se migró la base comercial como parte de la validación local.

## Informe contractual de esta entrega

API contract changed frente a `main` (`90d3a8b`):

- `contract_sha256`: `21ae77e25dd009a25543222f4d1d9734ef2b3fac230ab77767f747a49e69220d`.
- Operaciones agregadas: las ocho `live.v1.*` de la tabla anterior. Modificadas/eliminadas: ninguna.
- Esquemas agregados: `IngestLiveBatchRequest`, `LiveAudienceSampledV1Envelope`, `LiveCaptionObservedV1Envelope`, `LiveCommentReceivedV1Envelope`, `LiveConnectorResource`, `LiveConnectorStatusV1Envelope`, `LiveEndedV1Envelope`, `LiveEventResource`, `LiveLikesSampledV1Envelope`, `LiveModerationObservedV1Envelope`, `LiveRoomMetadataV1Envelope`, `LiveSessionResource`, `LiveSocialActionV1Envelope`, `LiveStartedV1Envelope`, `LiveStreamControlV1Envelope`, `LiveSystemNoticeV1Envelope`, `StoreLiveConnectorRequest`, `StoreLiveSessionRequest`. Modificados/eliminados: ninguno.
- Compatibilidad conservadora: `requires_review=false`; se preservan las 77 operaciones CRM y sus dependencias.
- Diagnósticos nuevos/modificados/desaparecidos: 0/0/0; permanecen los 44 anteriores y el estado global `draft`.
- `composer api:docs` pasó en SQLite temporal migrada. Su última reproducción informa literalmente `API contract unchanged`, al no existir cambios adicionales respecto al conjunto ya generado.
- OpenAPI oficial y tres pruebas JSON Schema correctas; Orval 8.48 genera el cliente y `tsc --noEmit` lo compila.
- Prueba por HTTP real en servidor/base aislados: autenticación nativa y registro correctos; 12 aceptados, 12 duplicados al reintentar, historial de 12, comentario eliminado en proyección, cierre confirmado y cobertura consultable. Request y respuesta reales validados contra OpenAPI. Esto no representa una conexión TikTok real.

El formato de los PHP modificados pasa Pint y PHPStan pasa sin errores. El check global `composer lint:check` conserva un fallo en `modules/crm/resources/lang/es/lang.php` (`single_quote`), reproducido en una copia limpia del commit base; ese archivo no cambió. Los fallos de traducciones (1023 frente a 1029 entradas) y de la expectativa de moneda distinta de PEN también se reprodujeron en esa base y no se modificaron como parte de QAT-04.

Pruebas PostgreSQL ejecutadas: 2 de concurrencia LIVE (19 aserciones) y las 4 existentes de variantes (38 aserciones), todas correctas, cada grupo en su base dedicada. La prueba ajustada del seeder conserva los 152 permisos CRM y exige exactamente `live.ingest`/`live.read` sin grants automáticos; sus 8 casos pasan (413 aserciones). La suite general se ejecuta proporcionando `APP_KEY` temporal, porque el CLI local no tiene una clave configurada.

Resultado final de la suite general con `APP_KEY` de pruebas: **323 ejecutadas, 321 correctas y los dos fallos heredados anteriores**, sin los 29 errores ambientales de la primera ejecución. Los casos LIVE y el bootstrap ajustado pasan. La tarea conserva pendientes la integración con el conector real y los gates generales heredados; no se declara validación global completamente verde.
