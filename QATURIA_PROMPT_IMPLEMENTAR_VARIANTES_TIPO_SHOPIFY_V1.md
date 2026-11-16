# PROMPT DE IMPLEMENTACIÓN — QaturIA: Motor de variantes de productos tipo Shopify (V1)

**Rol:** actúa como ingeniero principal de Laravel/PostgreSQL especializado en catálogo ecommerce, integridad multiempresa y migraciones de datos comerciales. **Ejecuta cambios reales de código y pruebas** en `IvanMamaniPerez/back-qaturia`; no entregues únicamente otra propuesta. En esta iteración trabajaremos **exclusivamente en productos, opciones y variantes**, dejando preparadas sus relaciones para POS, órdenes, inventario y análisis de TikTok LIVE. No implementes caja, pagos, PurchaseOrder workflow ni un kardex completo dentro de esta tarea.

## 1. Fuentes de verdad y orden de prioridad

1. Solicitud actual y este prompt, para la implementación específica del catálogo de variantes.
2. Código REAL, esquema de BD y tests actuales de `back-qaturia`, en la rama de trabajo.
3. `PLN-POS-QAT-01.md` y especificación POS posterior como contexto; sus módulos de caja e inventario no son entregables de esta fase.
4. Documentación Shopify **2026-10** citada al final como referencia de comportamiento, no como librería/servicio a instalar.

**Definición de éxito:** un comerciante crea un producto con opciones como `Color` y `Talla`, obtiene combinaciones de variantes elegidas, asigna identificadores/precios/imágenes/estado, las edita, desactiva o amplía sin perder ninguna variante histórica ni alterar inadvertidamente sus movimientos o documentos CRM. Backoffice actual y API V2 expresan el mismo comportamiento. El catálogo público puede resolver una variante real por IDs/valores, sin inventar precio, stock o SKU.

## 2. Hallazgos de código que debes RECONFIRMAR antes de tocar nada

En `main` del repositorio consultado el 16/11/2026:

- `modules/crm/src/Models/Product.php`: producto padre, relación `productVariations()`, relación de precios de padre y `unitPriceFor()`; investigar su fallback de moneda, que podría usar el precio por defecto aunque se solicitó otra moneda.
- `modules/crm/src/Models/ProductVariation.php`: identidad UUID + `external_id`, nombre, código, barcode, `active`, `order`, soft delete y precios propios. Esta es la **entidad canónica que permanecerá**: NO crear un segundo `ProductVariant` paralelo.
- `modules/crm/src/Models/ProductPrice.php`: precio por moneda; su setter transforma unidades mayores a menores con `Money::toInteger()`. No introducir doble conversión.
- `modules/crm/src/Services/ProductService.php`: `createVariation`, `updateVariation`, `deleteVariation` y `syncVariations`; **`syncVariations` hace soft-delete de filas omitidas**. Esa sincronización de lista completa es peligrosa durante generación/edición de matriz.
- `modules/crm/src/Livewire/Products/Traits/HasProductCommon.php` y `ProductCreate.php`/`ProductEdit.php`: edición manual de filas `variations` con `addVariation/removeVariation` y guardado por `syncVariations`. Debe adaptarse para opciones sin destruir variantes.
- `modules/crm/src/Http/Controllers/Api/V2/ProductVariationController.php`: CRUD anidado existente de variantes, con operaciones PATCH que respetan campos omitidos. Mantener rutas/formatos compatibles.
- `modules/crm/src/Http/Controllers/Api/V2/ProductController.php`, `ProductResource`, `ProductVariationResource`, Requests y `modules/crm/src/Http/api-routes.php`: API V2 externa utiliza `external_id`, no exponer arbitrariamente UUID internos.
- `modules/crm/src/Traits/ResolvesProductVariation.php`: comprueba pertenencia de variante a producto, admite histórico `withTrashed()` bajo condiciones. Revisar si una variante inválida se transforma silenciosamente en `null`: en nuevas operaciones de venta esa selección debe causar error, **no** vender otra cosa.
- `OrderProduct`, `QuoteProduct`, `DealProduct`, `InvoiceLine`, `PurchaseOrderLine`, `DeliveryProduct`: referencias existentes a `product_variation_id`. No romperlas ni cambiar IDs para regenerar combinaciones.
- `BusinessContext`, `BelongsToTeams`, `BusinessIntegrity`, policies: `team_id` representa `businesses` y todas las relaciones nuevas deben conservar el negocio de origen.
- Migración original `database/migrations/2024_01_01_000011_create_laravel_crm_product_variations_table.php` y actualización `modules/crm/database/updates/2026_10_22_000001_add_code_and_audit_columns_to_laravel_crm_product_variations_table.php`: NO modificar retroactivamente migraciones ya ejecutadas.

**Paso obligatorio:** comprobar el HEAD/branch actual, `git status`, diff local, migraciones efectivamente ejecutadas y restricciones en PostgreSQL. Documentar discrepancias entre el código actual y los hallazgos anteriores. No sobrescribir trabajos sin confirmar. Crear rama nueva adecuada (por ejemplo `feature/product-option-variant-matrix`) a partir de la base definida en el repositorio y conservar cambios locales ajenos. No tocar la DB de producción, ni `migrate:fresh` sobre bases con datos.

## 3. Paridad funcional de Shopify que implementaremos

- Producto **simple**, sin opciones. Admitir su existencia histórica sin forzar migración de IDs.
- Entre **1 y 3 opciones comprables por producto**: por ejemplo `Color`, `Talla`, `Material`. No confundir con atributos descriptivos no seleccionables (por ejemplo «100 % algodón»); no todo atributo produce variación de SKU.
- Cada opción tiene valores explícitos, identificados por IDs estables, etiqueta visible y orden. Ejemplo: `Color=[Negro,Blanco]`, `Talla=[S,M]` -> hasta 4 combinaciones distintas. No exigir que todas estén creadas o publicadas.
- Tope de **2.048 variantes persistidas por producto**, contando activas, inactivas y archivadas hasta resolver su identidad, con **advertencia de UX** y paginación cuando haya muchas (p. ej. >100). Validar límite en el backend antes de aplicar y bajo bloqueo.
- Alta de opciones para producto con variantes: soportar `LEAVE_AS_IS` (**por defecto**) y `CREATE`, con las semánticas documentadas de `productOptionsCreate`: asignar el **primer valor de cada opción nueva** a variantes existentes (sin cambiar su ID); `CREATE` agrega combinaciones faltantes para otros valores; `LEAVE_AS_IS` no las agrega.
- Al **agregar valores a una opción existente**, soportar `LEAVE_AS_IS` (solo registrar el valor) y `MANAGE_NON_DESTRUCTIVE` (generar las combinaciones nuevas aplicables). Esto es una política segura de QaturIA inspirada por `productOptionUpdate` (`MANAGE` en Shopify puede **eliminar** variantes al eliminar valores; **no reproducir esa eliminación automática**).
- Crear una o varias variantes concretas manualmente (selección de valores por opción), además de generar todas las combinaciones faltantes con vista previa.
- Editar orden/nombre de opciones y valores sin cambiar la identidad por renombrar/reordenar; editar SKU, barcode, precio por moneda, publicación y estado de cada variante de forma individual o masiva segura.
- Disponer de imagen representativa opcional por variante y, si procede, indicador de color (`swatch`) respaldado por datos del catálogo. Reutilizar infraestructura de archivos/media existente si puede hacerlo sin exponer datos ni inventar nuevas imágenes. La falta de foto no bloquea variante.
- Para compradores, filtrar valores y mostrar qué combinaciones existen, cuáles no están publicadas y cuáles no tienen disponibilidad acreditada; no crear una variante implícitamente cuando un comprador seleccione un par no persistido.
- **No implementar metafields globales, Shopify Markets, GraphQL Shopify ni sincronización con Shopify**. La comparación es funcional; se usa arquitectura Laravel ya existente.

## 4. Modelo relacional (adaptar nombres/prefijo a base real)

Proponer y **crear solamente si no existen equivalentes** las siguientes entidades normalizadas:

- `ProductOption`: id UUID, external_id, team_id, product_id, `name`, `normalized_name`, `position`, estado, auditoría/timestamps.
- `ProductOptionValue`: id UUID, external_id, team_id, product_option_id, `value`, `normalized_value`, `position`, estado, opcional `swatch_hex`/`swatch_media_id` si corresponde.
- `ProductVariationOptionValue`: id/PK según patrón, team_id, product_id, product_variation_id, product_option_id, product_option_value_id. Cada variante estructurada tiene **exactamente un valor por cada opción comprable vigente**; opción y valor siempre deben corresponderse.
- En `ProductVariation` existente, añadir si hace falta `combination_key` (huella determinística), `configuration_status` (`legacy_unmapped` / `configured` o equivalente), `published` separado de `active` solo si se justifica, `catalog_version` o versión en `Product`, y referencia a medio/imagen si existe mecanismo seguro. No reescribir UUID, external_id ni precios.
- En `Product`, `options_version`/`catalog_version` entero monotónico para detección de conflictos entre preview y aplicación. Alinear el estado simple/variable con datos existentes, sin desdoblar producto.

**Restricciones PostgreSQL:**

1. Claves/índices únicos por `team_id`/producto para nombre normalizado de opción y valor; nunca permitir una opción o valor duplicado que difiera solo por mayúsculas/espacios. Escoger normalización Unicode/NFC, trim, espacios repetidos, casefold y documentar tildes; no fusionar automáticamente valores comerciales distintos por semejanza fonética.
2. **Único `(team_id, product_id, combination_key)` para TODAS las variantes estructuradas incluso archivadas**. La clave se deriva de IDs ordenados canónicamente de opción/valor, no de etiquetas, SKU ni posición visual. La misma combinación desactivada debe **reactivarse**, no recrearse con otro ID. Proteger colisiones de hash mediante representación canónica/equivalencia verificada.
3. Una opción activa de producto no puede tener dos valores seleccionados en la misma variante; no se acepta un valor de otra opción, producto o empresa. Garantías en DB mediante FKs compuestas/unique y en servicio; comprobar efectivamente los constraints en PostgreSQL de pruebas.
4. Nunca superar 3 opciones ni 2.048 variantes contando estados relevantes; tener en cuenta race conditions y transacciones.
5. Respetar convenciones CRM de UUID y `external_id`; los recursos externos siguen usando external_id, no claves internas.
6. No inventar ni duplicar stock, cambiar contabilidad, historial de costo o ventas durante una operación de opciones. Las nuevas variantes arrancan con **cero stock físico**, o «sin inventario gestionado» si el almacén todavía no existe; no registrar saldo ficticio.

## 5. Motor de diferencias y aplicación segura: PREVIEW -> APPLY

Crear un servicio reutilizable, por ejemplo `ProductVariantMatrixService`, independiente de Livewire/HTTP y consumido por todos los escritores, con comandos explícitos. Diseño sugerido:

- `previewCreateOptions(product, options, strategy)` / `applyCreateOptions(...)`.
- `previewAddOptionValues(product, option, values, strategy)` / `applyAddOptionValues(...)`.
- `createSelectedVariants(product, selectedOptionValueIds, metadata)`.
- `updateVariantDetails(variation, patch)`.
- `renameOption`, `renameValue`, `reorderOptions`, `reorderValues`.
- `unpublishVariant`, `archiveVariant`, `restoreVariant` y `disableOptionValueSafely`.
- `previewLegacyMapping(product)` / `applyLegacyMapping(...)` para migración asistida.

Cada preview debe devolver por combinación existente/nueva:

- `existing_variant_id`/`external_id`, su condición (`preserved`, `created`, `reactivated`, `requires_mapping`, `blocked_conflict`, `unaffected`), valores anteriores/nuevos, SKU, barcode, disponibilidad conocida o `unknown`, impacto esperado sobre referencias y advertencias.
- Conteo anterior, nuevas, no alteradas, en conflicto, límite máximo, combinaciones nuevas sin stock, valores por defecto a asignar; paginar el detalle si se excede un umbral.
- `options_version` y `plan_hash` que el APPLY verifica; no confiar en un listado de acciones calculado por el navegador.

**APPLY transaccional con bloqueo del producto**, validación de versión, idempotencia por clave de operación y verificación de los datos nuevamente bajo lock. Si el plan cambió entre preview y aplicación, responder `409 CONFLICT` y exigir un nuevo preview. Usar `DB::transaction`, constraints e índices para controlar simultaneidad, no confiar exclusivamente en Redis ni en chequeos `exists()` previos. Si falla una de 500 combinaciones, no dejar una matriz parcial accidental. Considerar operaciones por lotes atómicos con contador y reintentos seguros, dentro de límites razonables.

### Casos de edición estrictos

1. `Color=[Negro,Blanco]`, `Talla=[S,M]`: generar cuatro variantes; repetir APPLY no genera otras cuatro.
2. Si existen `Negro-S` con 10 ventas y `Negro-M` con saldo registrado, añadir `Material=[Algodón,Poliéster]` con `LEAVE_AS_IS` -> IDs antiguos intactos, ambos reciben `Algodón` como opción nueva **solo tras consentimiento explícito sobre ese significado**; se crean cero variantes.
3. Mismo escenario con `CREATE` -> IDs antiguos intactos y las combinaciones `Poliéster` nacen con IDs nuevos y cero stock.
4. Si el valor predeterminado `Algodón` es **desconocido para el stock antiguo**, el sistema **no debe inventarlo**: exigir mapeo/confirmación del negocio, admitir valor `Sin especificar` como primer valor temporal cuando sea válido o bloquear la operación hasta clasificar, sin reinterpretar ventas históricas.
5. Renombrar `Negro` a `Grafito`, reordenar `Talla/Color` o cambiar etiquetas visuales **no crea nuevas variantes** ni cambia sus claves/IDs. Los documentos confirmados deben conservar snapshots del contenido comercial al momento de venta; revisar el estado de los documentos legados y no afirmar que puede reconstruirse exactamente lo desconocido.
6. Eliminar una opción que fusionaría `Negro-S` y `Negro-M` en `Negro` debe **bloquearse**: no fusionar precios, saldos, códigos ni referencias. Opciones/valores históricos usados se despublican/archivan en lugar de eliminarse. No borrar en cascada.
7. Una combinación archivada que el usuario intenta crear otra vez se **restaura/republica explícitamente** manteniendo el mismo ID, nunca se duplica.
8. Una variante de otra empresa o producto no puede utilizarse para configurar la matriz ni pasar a una venta.
9. Operación parcial/update de producto no significa «borra lo que no llegó en el request».

## 6. Migración segura desde el catálogo existente (NO opcional)

**El sistema YA TIENE `ProductVariation` de nombre libre y documentos relacionados.** No reemplazar esas filas por una matriz nueva de golpe.

### A. Producto simple sin variantes

Mantener el `Product.id` y documentos históricos con `product_variation_id=null`. Si se agregan opciones, crear variantes nuevas según plan. Si existe stock genérico, **no** asignarlo ni copiarlo a nuevas variantes sin reclasificación física aprobada; hasta resolver, marcar el saldo como no clasificado/pendiente de intervención en la futura integración de inventario. Evitar que el catálogo público venda simultáneamente una unidad del padre y la misma unidad de una variante.

### B. Producto con variantes históricas de texto libre

- No deducir `Color=Negro, Talla=M` confiando solo en que la variante se llama `Negro M`.
- Proveer **preview de mapeo manual**: fila histórica -> valores de opción seleccionados, con los IDs originales, precio, código, barcode y número conocido de documentos relacionados. Las sugerencias de parseo son solo sugerencias, nunca aplicación automática.
- Mientras haya variantes pendientes de mapeo, conservarlas como `legacy_unmapped` accesibles para consultas/historial. No hacer `soft delete` por omisión; definir si pueden seguir vendiéndose por el flujo antiguo mientras la migración está incompleta, con UX que evite vender dos identidades equivalentes.
- Al validar el mapeo, actualizar la **misma fila** `ProductVariation`, no insertar nueva ni eliminar la antigua. Si dos variantes antiguas se mapearían a la misma combinación, **bloquear y requerir resolución manual**: nunca fusionar stock, ventas, SKU o barcodes automáticamente.
- Hacer el proceso idempotente y reversible donde sea seguro. Registrar versión anterior/actor/fecha y advertencias.

### C. Historial documental y precios

- Preservar todas las FKs `product_variation_id`, `external_id`, `ProductPrice` (por moneda), códigos, barcodes, archivos e identificadores externos.
- Revisar `HasProductLineName`: hoy puede leer el nombre actual de la variante. Evitar que una edición de opción cambie silenciosamente lo que decía un ticket/orden confirmado. Introducir snapshots futuros de producto/variante/opciones/SKU para líneas confirmadas y diseñar backfill **best-effort etiquetado** para líneas históricas, sin fingir conocer su valor exacto pasado.
- Mantener coherencia de precios por moneda: si se pide PEN, jamás aplicar silenciosamente un precio USD como PEN. Corregir el fallback `Product::unitPriceFor` si la prueba confirma el error. Nuevas variantes pueden **heredar virtualmente** el precio del padre de la misma moneda hasta configurar precio propio; la herencia no debe crear otra fila `ProductPrice` por accidente.
- Reutilizar el setter existente de `ProductPrice` con un contrato claro: entrada de formulario/API en unidades mayores; persistencia en enteros menores. No enviar centavos a un setter que los multiplicará otra vez.

### D. Integración futura con stock

No crear un kardex entero en esta tarea. Definir claramente las entradas y salidas del futuro inventario para distinguir `(product_id, product_variation_id|null)` y verificar no duplicación cuando exista stock. Si ya existe implementación de stock en la rama, revisar y ejecutar tests de conservación de saldo. Si **no existe**, no inventar cantidades; entregar puntos de integración explícitos y tests sobre ausencia de modificación a campos existentes. No registrar movimientos comerciales al regenerar opciones.

## 7. UI de construcción de variantes y experiencia de usuario

**Implementar funcionalidad real en el backoffice existente (Livewire/Mary)**, utilizando el servicio del backend; no crear un motor separado en JavaScript/React.

En creación/edición de producto:

1. Sección «Variantes»: `Sin variantes` / `Con opciones`.
2. Agregar hasta 3 opciones; nombre, valores editables, orden drag/drop o botones; usar «Color», «Talla», «Material» como sugerencias, no como campos fijos.
3. Al cambiar opciones/valores, botón **«Previsualizar variantes»** con conteos e impacto, y elección `Conservar existentes` o `Generar combinaciones faltantes`.
4. Tabla de variantes resultantes: combinación legible, SKU, barcode, precio propio/heredado por moneda, activo, publicado, imagen, advertencia de stock `sin control / 0 / pendiente de asignación` según datos reales.
5. Posibilidad de generar todas las faltantes **o seleccionar combinaciones concretas**; editar variantes individualmente y en bloque solo campos explícitos.
6. Advertencia irreversible clara antes de reinterpretar una variante vieja con un valor nuevo: conservar la identidad no significa que conocemos su atributo histórico.
7. «Despublicar» antes que «eliminar», con opción de reactivar; botón de eliminar físico inexistente si hay referencias/historia.
8. Bloquear guardados inesperados si otra sesión editó la matriz. Mostrar error de versión y pedir refrescar preview.
9. Evitar renderizar 2.048 formularios Livewire completos simultáneamente: tabla paginada/virtualizada y edición por lote.
10. Cualquier API o formulario legado para crear `ProductVariation` a mano deberá pasar por el motor **si el producto tiene opciones**. No dejar dos sistemas que generen identidades incompatibles. Las actualizaciones menores de SKU/precio/activo pueden seguir pasando por servicio compartido.

**Integración React:** el entregable ahora es una API con contrato usable por `front-qaturia` (web, escritorio y móvil); si no se está trabajando ese repositorio en esta iteración, documentar ejemplos JSON y el contrato para implementarlo después. El backend es la única autoridad de generación y validación.

## 8. API V2 (conservar las rutas previas)

Agregar/adaptar rutas versionadas conforme a patrones del repositorio (los paths son orientativos; confirmar paths de `api-routes.php`):

- `GET /products/{product}/options` — opciones, valores, orden, versión.
- `POST /products/{product}/options/preview` — propuesta de cambio de opciones/matriz sin mutación.
- `POST /products/{product}/options/apply` — aplicación explícita con versión + llave idempotente + plan hash.
- `PATCH /products/{product}/options/{option}` — nombre, orden, estado (sin borrar variantes).
- `PATCH /products/{product}/options/{option}/values/{value}` — nombre, orden, swatch/estado.
- `POST /products/{product}/variations/generate/preview` — combinaciones faltantes para generar o elegir.
- `POST /products/{product}/variations/generate` — crear seleccionadas/que faltan de forma idempotente.
- `PATCH /products/{product}/variations/{variation}` — preservar semántica PATCH existente y extensiones necesarias.
- `POST /products/{product}/variations/{variation}/archive` y `/restore` — despublicar/archivar/restaurar sin recreación.
- `POST /products/{product}/legacy-variations/preview` y `/map` — mapeo autorizado de texto libre.

Preservar `ProductController`, `ProductVariationController`, recursos y rutas actuales; decidir si conviene añadir operaciones a sus controladores o servicios específicos sin duplicar validaciones. Campos de salida: `external_id`, opciones seleccionadas (IDs + etiquetas), `combination_key` solo si no expone detalles sensibles, precio efectivo por moneda y procedencia (`explicit`/`parent_inherited`/`not_available`), publicación/estado, flags de mapeo. Consumidor con tenant correcto no debe poder cambiar `team_id` ni referenciar opciones ajenas.

Para requests inválidas: `422` con códigos semánticos (`DUPLICATE_VARIANT_COMBINATION`, `OPTION_LIMIT_EXCEEDED`, `VARIANT_LIMIT_EXCEEDED`, `LEGACY_MAPPING_REQUIRED`, `VALUE_NOT_IN_OPTION`, `CANNOT_REMOVE_USED_OPTION`); `409` para versiones obsoletas e idempotencia conflictiva. Autorización según permisos de producto ya vigentes y permisos adicionales si existe riesgo (reclasificación/archivado). Mantener docs de OpenAPI o equivalente.

## 9. Pruebas obligatorias antes de cerrar

Ejecutar pruebas automáticas de catálogo y regresión de CRM, con PostgreSQL **separado** para unicidad/locks/concurrencia:

1. Producto nuevo sin opciones: sigue funcionando, no se crea variante ficticia por requerimiento de API.
2. Producto con `Color=[Negro,Blanco]`, `Talla=[S,M]`: cuatro combinaciones únicas cuando se piden; opcionalmente solo dos seleccionadas.
3. Máximo 3 opciones, máximo 2.048 variantes; rechazo claro al exceder, incluyendo reactivación y concurrencia.
4. Agregar opción `Material=[Algodón,Poliéster]` a un producto con cuatro variantes; `LEAVE_AS_IS` no crea nuevas y mantiene todos los UUID/external_id; `CREATE` agrega las combinaciones faltantes, sin duplicar existentes.
5. Agregar un valor a opción existente con modo no destructivo: solo crea las nuevas combinaciones faltantes y no borra antiguas.
6. Reordenar/renombrar opción/valor: conserva variant ID y combination_key; nombre mostrado puede actualizarse, histórico confirmado no.
7. Variante con cotizaciones, pedidos, facturas o compra mantiene FK en edición y archivado; se bloquea hard delete y restauración conserva identidad.
8. Variante legacy `Negro M`: no se auto-convierte sin mapeo; mapeo manual conserva UUID, precio y referencias. Conflicto por dos mapeos iguales se rechaza.
9. Repetir apply con misma llave/payload no duplica; misma llave con payload distinto da conflicto; dos transacciones concurrentes no crean combinación duplicada.
10. Cambios de versión entre preview/apply => 409, BD intacta.
11. Request de otro tenant/producto/valor inválido => rechazo sin fuga de datos; pruebas explícitas de FKs e índices.
12. Actualización simple de `Product` vía API omitiendo variantes las deja intactas; `ProductEdit` Livewire también.
13. Precio de variante hereda del padre **solo misma moneda**; no convierte USD en PEN, no duplica centavos.
14. Archivo/reactivación correcta incluso con historial; nuevas variantes nacen sin movimientos ni stock inventado.
15. API V2 existentes (`products`, `products.variations`) siguen respondiendo y actualizando igual para clientes legados; pruebas de regresión.
16. Invariantes de medio: fotografía de variante no cruza `team_id`, no permite archivo inválido ni altera foto fuente.
17. Reordenación en paralelo con generación se resuelve transaccionalmente; el orden de opciones no altera identidad.
18. No N+1 masivo: medición de acceso a producto con 100+ variantes y paginación. No depender de que frontend envíe array completo.

Registrar en informe los comandos reales, sus resultados, skips y la base PostgreSQL usada. SQLite por sí solo no prueba locks o índices parciales.

## 10. Orden concreto de implementación y entrega

**Fase A — Auditoría breve y plan verificable:** tabla de clases/rutas/columnas existentes, estado de variantes legacy, relación con documentos, tests actuales, decisiones de migración y plan de constraints. Documentar en `docs/PLANIFICACION/PLN-VARIANTES-QAT-01.md` o ubicación equivalente. Evitar detenerse ahí.

**Fase B — Persistencia:** migraciones aditivas de opciones/valores/asociaciones, versiones, unicidad y tenancy; modelos/relaciones; normalización y primer conjunto de tests. Aplicar migraciones solo en entorno de pruebas.

**Fase C — Motor de variantes:** servicios preview/apply, `LEAVE_AS_IS`, `CREATE`, valores adicionales, creación individual, desactivación/reactivación, locks, planes stale y pruebas de idempotencia/concurrencia.

**Fase D — Legacy y compatibilidad:** preview de migración, mapeo asistido, reemplazo de `syncVariations` destructivo, actualización de CRUD V2/Livewire, precio por moneda y snapshots históricos (con alcance documentado); regresión de relaciones.

**Fase E — Experiencia de usuario y contrato:** editor de opciones/valores y tabla de variantes en Livewire, preview de cambios, errores claros, recursos API y documentación de consumo para `front-qaturia`.

**Fase F — Validación final:** ejecutar suite + pruebas de tenant/concurrencia, registrar diff/archivos, estado de branch, migraciones, capturas o evidencia de UI cuando sea posible. Corregir fallos encontrados antes de solicitar PR.

**Entrega:** rama nueva, commits enfocados, PR solo cuando los flujos acordados estén implementados y probados (si cuentas con permisos/autorización para crearlo); de otro modo entregar instrucciones exactas para abrirlo. Reportar explícitamente limitaciones, riesgos, manual de migración y roll-back seguro. No afirmar «paridad Shopify» si se omitió una función comprometida. No añadir dependencias externas innecesarias ni duplicar el catálogo.

## 11. Referencias oficiales (usar edición estable 2026-10, reconfirmar si cambió)

1. Opciones, hasta 3 y 2.048 variantes: https://help.shopify.com/en/manual/products/variants/add-variants
2. Estrategias para nuevas opciones `CREATE`, `LEAVE_AS_IS`: https://shopify.dev/docs/api/admin-graphql/2026-10/enums/ProductOptionCreateVariantStrategy
3. Crear opciones: https://shopify.dev/docs/api/admin-graphql/2026-10/mutations/productOptionsCreate
4. Actualizar opciones/valores (y distinguir `LEAVE_AS_IS`/`MANAGE`): https://shopify.dev/docs/api/admin-graphql/2026-10/mutations/productOptionUpdate
5. Crear variantes y manejo de variante única: https://shopify.dev/docs/api/admin-graphql/2026-10/mutations/productVariantsBulkCreate
6. Eliminar opciones: evaluar riesgos y estrategia `NON_DESTRUCTIVE`: https://shopify.dev/docs/api/admin-graphql/2026-10/mutations/productOptionsDelete
7. `productSet` sincroniza listas y puede borrar elementos omitidos, NO imitar esa semántica en PATCH: https://shopify.dev/docs/api/admin-graphql/2026-10/mutations/productSet
8. Shopify recomienda despublicar, no borrar, si se necesita historia: https://help.shopify.com/es/manual/products/variants/edit-variants

**INSTRUCCIÓN FINAL:** Empieza por inspeccionar el código y crear la rama, después implementa de extremo a extremo catálogo/variantes en backend + editor administrativo + API. **El objetivo no es otra propuesta, sino código validado que no pierda información del CRM actual.** Si descubres una incompatibilidad real, documéntala y resuélvela sin eliminar silenciosamente datos ni exigir reconstruir todo el POS. No empieces por pagos, caja, inventario completo ni frontend multiplataforma.
