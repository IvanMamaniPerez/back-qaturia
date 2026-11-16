# PLN-VARIANTES-QAT-01 — Catálogo de opciones y variantes

## Auditoría inicial (2026-11-16)

Base: `main`, HEAD `693138d`; árbol limpio; rama `feature/product-option-variant-matrix`.
El mensaje del HEAD menciona una matriz, pero no existen entidades de opciones ni motor de generación.

| Área | Estado observado | Decisión |
|---|---|---|
| Product / ProductVariation | UUID internos, external_id, precios propios, soft delete | Conservar identidad y relaciones |
| ProductService::syncVariations | Borra omitidas; ID inválido se convierte en alta | Escritura parcial; rechazar ID inválido |
| Product::unitPriceFor | Fallback usa moneda del negocio | Heredar exclusivamente misma moneda |
| API V2 | `/crm/api/v2/products`, variantes anidadas, external_id | Extensiones aditivas y contrato público |
| Livewire/Mary | Filas manuales completas | Editor paginado consumiendo motor común |
| ResolvesProductVariation | Selección inválida retorna null | Error explícito al seleccionar identidad inválida |
| Documentos | HasProductLineName lee catálogo actual | Snapshot prospectivo y backfill best-effort etiquetado |
| Inventario | No existe kardex por variante | Disponibilidad unknown, sin movimientos ni cantidades ficticias |
| PostgreSQL local | 2 variantes legacy; migraciones hasta 2026_11_15 ejecutadas | Solo lecturas en base local; pruebas en base separada |
| Constraints de variantes | PK UUID, UNIQUE(team_id,id), FK team_id; falta FK product_id | Añadir vínculos compuestos y restricciones restrictivas |

No se modifican migraciones anteriores. La actualización de código/barcode/auditoría del 22/10 ya está aplicada.
La infraestructura PHP y dependencias están en Docker (`workspace`), no en el host.

## Decisiones

Opciones y valores tienen UUID interno y external_id independiente. Normalización NFC, trim Unicode,
espacios repetidos y casefold Unicode; las tildes se conservan (`cafe` y `café` son distintos).
La identidad de combinación utiliza pares de external_id estables ordenados, SHA-256 y representación
canónica persistida para verificar equivalencia. No depende de etiquetas ni de posiciones.

Las operaciones de matriz bloquean el producto, recalculan el plan y comprueban versión/hash;
registran clave idempotente, actor, fecha, petición y resultado en la misma transacción.
Un producto con legacy pendiente admite definición de opciones y mapeo manual, pero no generación
ni nuevas ventas legacy mientras haya opciones: evita vender dos identidades equivalentes.
Agregar una opción a variantes configuradas exige confirmar explícitamente los valores predeterminados.
No existe eliminación física de opciones/valores usados ni variantes. Desactivar valores despublica
las variantes afectadas y mantiene su identidad. Restaurar una combinación requiere elección explícita.

Referencias reconfirmadas: Shopify 2026-10 productOptionsCreate/productOptionUpdate (redirigen a
latest mostrando 2026-10) y Help Center add-variants: 3 opciones / 2048 variantes. No se incorpora
Shopify ni su política destructiva MANAGE.

## Implementación entregada

- Persistencia: `2026_11_16_000001_create_product_option_matrix`, cuatro tablas nuevas
  (opciones, valores, selección y operaciones), versión, firma canónica y publicación en variante.
- FKs compuestas restrictivas preservan empresa/producto/opción/valor. PostgreSQL verifica
  propietario incluyendo NULL, identidad inmutable, límite bajo bloqueo y completitud diferida
  al COMMIT. Los índices incluyen archivadas. Los índices de etiqueta protegen también
  mayúsculas/espacios/NFC frente a SQL con normalized falsificado. El casefold Unicode completo
  se aplica en `CatalogIdentity` y sus campos únicos: cualquier escritor SQL debe respetar
  esa normalización (no escribir nombres normalizados arbitrarios).
- Motor único `ProductVariantMatrixService`: preview/apply, estrategias, selección concreta,
  mapeo manual, cambios de etiqueta/orden/estado, lotes, archivo/restauración, reintentos y
  controles de versión. No hay `ProductVariant` paralelo ni sincronización destructiva.
- Los IDs propuestos incluyen la versión al dar de alta: reutilizar una etiqueta tras
  renombrarla crea otra identidad, sin reasignar el antiguo valor silenciosamente.
- Livewire/Mary: alta con preview, editor de 25 filas, preview de 100 con navegación,
  cola de selecciones concretas, mapeo por página, edición por moneda, lotes explícitos,
  foto privada opcional del producto, swatch, publicación y restauración.
- API V2: extensiones de rutas/recursos existentes. Contrato y ejemplos en
  [VARIANTES_V2.md](../API/VARIANTES_V2.md). La lista y ficha embeben máximo 100 variantes;
  las restantes se consultan paginadas. No se añade un portal de tienda anónimo.
- Compatibilidad: `syncVariations` conserva omitidas y rechaza IDs inválidos. Los CRUD
  legacy no crean variantes de texto libre en productos estructurados. La venta nueva
  exige una selección válida, activa y publicada; las referencias históricas pueden
  conservarse. No se permite vender el padre de un producto con opciones.
- Snapshots de nombre/opciones/SKU en líneas nuevas; backfill antes de cambiar catálogo,
  etiquetado `legacy_best_effort`, sin sobrescribir snapshots presentes. Se conservan
  UUID, precios, cantidades, importes y FKs de cotización/pedido/factura/compra/deal.
  Entregas reutilizan la línea de pedido. No existe ticket POS en esta fase.
- `CatalogAvailabilityService` define la futura consulta por identidad, sin movimientos,
  stock físico, saldos ficticios ni transferencias del padre.

## Manual de adopción y rollback

1. Respaldar la base objetivo y comprobar versión del código/migraciones. Esta entrega
   ejecutó la migración solo en pruebas, nunca en `back_qaturia` ni producción.
2. Preflight de solo lectura: comprobar variantes sin padre, padres de otra empresa,
   imágenes de otra empresa y productos con más de 2048 variantes contando soft delete.
   Un registro inválido bloquea la FK nueva; resolverlo manualmente, nunca borrarlo.
3. En un despliegue autorizado ejecutar `php artisan migrate --force`. No ejecutar
   `migrate:fresh` ni rollback de esquema sobre datos comerciales.
4. Los productos simples siguen simples; las variantes existentes quedan `legacy_unmapped`.
   Antes de registrar opciones acordar la clasificación comercial y el corte de nuevas
   ventas legacy: al tener opciones el flujo exige variante configurada/publicada.
5. Registrar definiciones con `LEAVE_AS_IS`, inspeccionar legacy, mapear por lotes y revisar
   los conflictos. Se puede usar un primer valor explícito `Sin especificar` si el negocio
   lo acepta. Nunca interpretar el nombre libre ni repartir saldos del padre.
6. Configurar precios/imágenes, publicar explícitamente las identidades clasificadas;
   generar las faltantes solo después de terminar el mapeo. Las nuevas no tienen stock
   acreditado. Resolver inventario físico con el futuro adaptador, fuera de esta entrega.
7. Ante un error de clasificación detener publicación y revisar operaciones auditadas.
   No borrar selecciones ni liberar claves para fusionar identidades. No se automatiza
   un desmapeo/merge después de uso comercial; requiere resolución administrativa.
8. Rollback: despublicar las nuevas identidades y revertir código conservando columnas,
   tablas, precios, snapshots e IDs. `down()` bloquea la eliminación de esta estructura.
   Volver al código anterior desactiva las garantías nuevas de escritores: suspender
   escrituras de catálogo/ventas hasta reponer la versión corregida. Restaurar un backup
   solo mediante un procedimiento explícito que contemple operaciones posteriores.

## Límites operativos

No se implementan caja, pagos, kardex, workflow de compras, Shopify/GraphQL, Markets ni
metafields. No se modifica `front-qaturia`; el contrato sirve para su implementación posterior.
La disponibilidad es desconocida, no un cero físico comprobado. El pasado desconocido queda
identificado como best-effort. El editor se verifica mediante render y acciones Livewire;
no se capturaron pantallas ni se ejecutó un navegador completo. No se afirma paridad total
con Shopify.

Resultados, comandos, comprobaciones PostgreSQL y evidencia de UI:
[VALIDACION-VARIANTES-QAT-01.md](VALIDACION-VARIANTES-QAT-01.md).
