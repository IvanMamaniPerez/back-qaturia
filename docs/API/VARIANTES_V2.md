# Catálogo de opciones y variantes — contrato V2

Base: `/crm/api/v2`. Autenticación Sanctum y contexto de empresa del API existente.
Todos los identificadores del contrato son **external_id**, incluso `option_id`, `value_ids`,
`variant_id` e `image_file_id`. No enviar UUID internos ni `team_id`. Se conserva `id` público
como alias de `external_id` en variantes. Permisos: leer requiere `view` de producto; escribir
requiere `update`; subir imágenes en el backoffice requiere además `create` de archivos.

## Rutas

| Método y ruta relativa a `/products/{product}` | Contrato |
|---|---|
| GET `/options` | `data: [{id,name,position,active,values:[{id,value,position,active,swatch_hex}]}]`, `options_version` |
| POST `/options/preview` | `{command,page?:1}`; sin mutación; detalle paginado de 100 |
| POST `/options/apply` | `{command,options_version,plan_hash,operation_key}` |
| PATCH `/options/{option}` | `{options_version,name?,position?,active?}`; desactivar una opción se bloquea |
| PATCH `/options/{option}/values/{value}` | `{options_version,value?,position?,active?,swatch_hex?}` |
| POST `/variations/generate/preview` | Mismo envelope de preview; `command.action=generate` |
| POST `/variations/generate` | Mismo envelope de apply; `command.action=generate` |
| GET `/variations?per_page=25&page=1&currency=PEN` | Lista paginada (máximo 100), incluye archivadas para administración |
| POST `/variations` | CRUD legacy existente para productos sin opciones; un producto estructurado usa generación selectiva |
| PATCH `/variations/{variation}` | PATCH legado, solo campos presentes; añade `published`, `image_file_id`, `options_version` |
| PATCH `/variations/bulk` | `{options_version,variant_ids:[...],patch:{...}}`, máximo 100 IDs; atómico |
| DELETE `/variations/{variation}` | Compatibilidad: archivo lógico, no eliminación física |
| POST `/variations/{variation}/archive` | `{options_version?:n}`, desactiva, despublica y archiva |
| POST `/variations/{variation}/restore` | `{options_version?:n}`, restaura mismo ID, activo pero despublicado |
| POST `/variations/resolve` | `{value_ids:[...]}` → `{exists,availability:"unknown",variant:...|null}` |
| POST `/legacy-variations/preview` | Sin `command`: inspección paginada con precios/SKU/referencias; con `command`: simula mapeo |
| POST `/legacy-variations/map` | Apply normal con `command.action=map_legacy` |

Las rutas de generación y mapeo son alias del mismo motor y envelope de opciones.
`PATCH` permite `name`, `code`, `barcode`, `active`, `published`, `order`, `unit_price`,
`currency`, `image_file_id` y `options_version`. En variantes configuradas el nombre se deriva
siempre de sus valores. Un nombre de texto libre no redefine una combinación estructurada.
La actualización masiva acepta solo `code`, `barcode`, `active`, `published`, `unit_price`,
`image_file_id`; sin precio enviado no se cambia ningún precio. El lote acepta `currency` (ISO de 3 caracteres); al omitirla usa la moneda primaria del producto.

`products` y `products/{product}` incluyen como máximo 100 variantes por producto,
`variations_count` y `variations_truncated`. Para obtener todas usar el endpoint paginado.
Esto también se aplica a la lista de productos; no serializar 2048 formularios en React.

## Preview y aplicación de opciones nuevas

Primero crear el producto usando el CRUD existente. Después:

```json
{
  "command": {
    "action": "create_options",
    "strategy": "CREATE",
    "options": [
      {"name": "Color", "values": ["Negro", "Blanco"]},
      {"name": "Talla", "values": ["S", "M"]}
    ]
  }
}
```

Respuesta resumida (los IDs de opciones/valores propuestos ya son estables para este plan):

```json
{
  "options_version": 0,
  "plan_hash": "<sha256 de 64 caracteres>",
  "counts": {"before": 0, "created": 4, "requires_mapping": 0, "limit": 2048},
  "conflicts": [],
  "details": [{"existing_variant_id": null, "status": "created", "after_label": "Negro / S", "availability": "unknown"}],
  "pagination": {"page": 1, "per_page": 100, "total": 4}
}
```

Enviar a apply **el mismo command** y los controles recibidos:

```json
{
  "command": {"action": "create_options", "strategy": "CREATE", "options": [{"name": "Color", "values": ["Negro", "Blanco"]}, {"name": "Talla", "values": ["S", "M"]}]},
  "options_version": 0,
  "plan_hash": "<hash recibido>",
  "operation_key": "<UUID generado por el cliente para este intento lógico>"
}
```

Reintentar el mismo payload/clave devuelve el mismo resultado sin nuevas escrituras.
Reutilizar la clave con otro payload da 409. Una versión/hash obsoletos dan 409 y exigen
un preview nuevo con **otra clave**. El backend recalcula todas las acciones bajo bloqueo;
`details` nunca se envía como lista ejecutable. El resultado de apply conserva el detalle
del plan original, y devuelve la versión posterior; consultar variantes para sus IDs nuevos.

Las variantes nuevas nacen despublicadas, sin precios propios ni existencias inventadas.
El backoffice admite creación de producto+opciones en una transacción, previa previsualización.

## Agregar opciones o valores y generar parcialmente

Agregar una opción a variantes ya configuradas conserva sus IDs y extiende la combinación
con el primer valor nuevo. La confirmación debe expresar su significado comercial:

```json
{"action":"create_options","strategy":"LEAVE_AS_IS","confirm_defaults":true,"options":[{"name":"Material","values":["Sin especificar","Algodón"]}]}
```

`LEAVE_AS_IS` no crea variantes. `CREATE` añade combinaciones con valores nuevos distintos
del primero. No completa automáticamente huecos antiguos del valor predeterminado: para
completar toda la matriz, ejecutar `generate`. Si el negocio no conoce el material de las
identidades existentes debe clasificarlas o usar explícitamente un valor temporal válido;
la aplicación nunca deduce el material por el nombre de la variante.

Agregar valores solo registra etiquetas por defecto:

```json
{"action":"add_values","option_id":"<external_id de Color>","values":["Rojo"],"strategy":"MANAGE_NON_DESTRUCTIVE"}
```

`MANAGE_NON_DESTRUCTIVE` genera solo combinaciones que contienen uno de los valores recién
agregados. No elimina filas ni completa huecos no relacionados. `LEAVE_AS_IS` registra los
valores sin generar. No se acepta el `MANAGE` destructivo de Shopify.

Para generar todas las faltantes: `{"action":"generate"}`. Para crear exactamente dos:

```json
{"action":"generate","selections":[["<Negro-id>","<S-id>"],["<Blanco-id>","<M-id>"]]}
```

Cada selección debe contener exactamente un valor activo de cada opción. Para restaurar y
republicar una identidad archivada solicitada, agregar `"restore_archived":true`; sin ello
una selección explícita archivada da `ARCHIVED_COMBINATION_REQUIRES_RESTORE`. La generación
general la conserva archivada. Nunca se inserta una identidad duplicada.

## Mapeo legacy

1. Registrar opciones con `LEAVE_AS_IS`; las filas legacy conservan nombre, UUID, código,
   precios y referencias. Su texto nunca se analiza automáticamente.
2. Consultar `/legacy-variations/preview`; muestra IDs externos, precios y conteos conocidos
   por tipo de documento, incluso referencias archivadas.
3. Seleccionar valores explícitos para cada identidad:

```json
{"command":{"action":"map_legacy","mappings":[{"variant_id":"<id externo histórico>","values":["<Negro-id>","<M-id>"]}]}}
```

4. Previsualizar y aplicar con versión/hash/clave como cualquier otra operación. Dos filas
   hacia la misma combinación bloquean el lote completo, incluso si una está archivada.
5. Se permiten lotes de mapeo; mientras quede legacy pendiente se bloquea la generación y
   la venta legacy de productos con opciones. Las identidades configuradas y publicadas
   siguen seleccionables. El historial siempre conserva su referencia original.

No existe eliminación de opciones usadas ni fusión automática. Desactivar un valor
lo retira de la generación y despublica sus variantes; reactivarlo no las republica.

## Precio, media, disponibilidad y ventas

Los importes de entrada/salida están en **unidades mayores** (15.25 PEN). `ProductPrice`
persiste 1525 mediante su setter existente, aplicado una sola vez. PATCH sin `unit_price`
conserva el precio; `unit_price:null` retira solo el precio propio de esa moneda. La variante
hereda virtualmente del padre de la **misma moneda**. La salida `effective_price` contiene
`currency`, `unit_price` y `source=explicit|parent_inherited|not_available`; pedir PEN sin precio
PEN devuelve null, jamás USD como PEN. No se crea una fila de precio al heredar.

Las imágenes son archivos existentes del **mismo producto y empresa**, con MIME raster válido;
no se publican rutas de almacenamiento ni URLs privadas. El backoffice permite subir una foto
privada de hasta 5 MB y elegirla. `image_file_id:null` retira la asociación sin alterar el archivo.
El consumo del archivo conserva las políticas/rutas de archivos autenticadas existentes.

`resolve` nunca crea combinaciones. Devuelve estados `active`, `published`, `archived`,
`configuration_status` y precio real de la identidad encontrada. Una selección no persistida
produce `exists:false`. La resolución de catálogo conserva las rutas autenticadas existentes;
no se añade una tienda anónima ni se expone el catálogo de otras empresas.

`availability=unknown`, `inventory.managed=false`, `available_quantity=null` no significan
stock cero acreditado ni disponibilidad positiva. `CatalogAvailabilityService::forIdentity`
es el punto de integración futura para `(Product, ProductVariation|null)`. No transferir ni
copiar saldo del padre al crear opciones. Una venta nueva de un producto con opciones debe
seleccionar una variante configurada, activa y publicada; una referencia histórica actual
puede conservarse aunque esté archivada. Una variante inválida causa error, nunca se vuelve null.

## Errores

Validación: 422, envelope Laravel `errors.catalog: ["CODIGO"]` (errores de forma en su campo).
Códigos: `DUPLICATE_VARIANT_COMBINATION`, `OPTION_LIMIT_EXCEEDED`, `VARIANT_LIMIT_EXCEEDED`,
`LEGACY_MAPPING_REQUIRED`, `VALUE_NOT_IN_OPTION`, `CANNOT_REMOVE_USED_OPTION`,
`DEFAULT_ASSIGNMENT_CONFIRMATION_REQUIRED`, `EXACTLY_ONE_VALUE_PER_OPTION_REQUIRED`,
`DUPLICATE_OPTION_NAME`, `DUPLICATE_OPTION_VALUE`, `INVALID_VARIANT_IMAGE`,
`STRUCTURED_SELECTION_REQUIRED_USE_MATRIX`, `ARCHIVED_COMBINATION_REQUIRES_RESTORE`.
409: `STALE_OPTIONS_VERSION`, `STALE_PLAN`, `IDEMPOTENCY_CONFLICT`.
Referencias ajenas resueltas por rutas retornan 404; permisos insuficientes, 403.

La edición de producto omitiendo variantes no las toca. Omitir filas en un formulario
legacy tampoco las archiva; archivar requiere una acción explícita.

## Snapshots de documentos

Las líneas V2 de cotización, pedido y factura incluyen `display_name`, `display_code`,
`catalog_snapshot` y `catalog_snapshot_source`. Los nombres/códigos anidados de `product`
y `variation` usan el snapshot cuando existe. Los nuevos registros capturan el catálogo
al crear la línea; editar explícitamente la identidad comercial de una línea vuelve a
capturar esa selección. Renombrar el catálogo jamás cambia su snapshot. Entregas leen el
snapshot de la línea de pedido. Los documentos previos se congelan antes de una edición
del catálogo con origen `legacy_best_effort`: se conoce el valor al capturarlo, no su
redacción original exacta. Los snapshots no reconstruyen el pasado ni modifican precios,
importes, cantidades o referencias.
