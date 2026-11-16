# PLN-POS-QAT-01 — Punto de venta integrado con Qaturia CRM

**Fecha:** 2026-11-16, America/Lima.  
**Estado:** giro comercial y ticket interno confirmados; diseño técnico propuesto, implementación pendiente.  
**Base auditada:** código local sobre HEAD `36b34e2` y esquema PostgreSQL de `back_qaturia`, consultado dentro de una transacción de solo lectura.  
**Alcance confirmado por el usuario:** tienda / venta de productos; primera versión con venta, caja, inventario y ticket interno. Facturación electrónica para una fase posterior.  
**Supuestos técnicos por revisar:** operación en línea y una moneda de dos decimales por caja y venta. La moneda y las reglas de precios/impuestos se resolverán a partir de la configuración vigente del negocio y sus requisitos.

## 1. Decisión principal

Construir un módulo POS dentro del CRM y conservar `Order` / `OrderProduct` como documento de venta y detalle. Reutilizar el catálogo, variantes, clientes, impuestos, documentos, identidad y aislamiento empresarial existentes. Agregar las capacidades de caja, pagos, inventario y devoluciones que hoy faltan.

No crear otro catálogo ni un segundo modelo `Sale` con copias de las mismas líneas. `Invoice` seguirá representando el documento de facturación vinculado al pedido; registrar un pago y emitir un documento serán operaciones distintas. Un ticket interno tampoco demuestra emisión fiscal electrónica.

En el alcance confirmado, el ticket se genera desde `Order` y sus líneas históricas. No será obligatorio crear una `Invoice` para completar una venta POS. Los pagos podrán vincularse posteriormente a la factura correspondiente sin registrar nuevamente el cobro.

El POS deberá usar servicios de dominio compartidos con los otros canales del CRM. Un pedido creado desde el CRM, una entrega y una venta de mostrador no pueden alterar existencias o cobros mediante reglas incompatibles.

## 2. Qué existe y qué se reutiliza

| Área | Evidencia en el repositorio | Aprovechamiento y límite actual |
| --- | --- | --- |
| Catálogo | `Product`, `ProductCategory` | Nombre, código, código de barras, unidad, estado activo y tasa de impuesto. Falta distinguir artículos con stock de servicios y definir reglas de venta fraccionada. |
| Variantes | `ProductVariation`, `ProductService`, `ResolvesProductVariation` | Variantes activas, código, barcode y precio propio con respaldo en precio del producto. Falta identidad de inventario por variante y búsqueda inequívoca por scanner. |
| Precios y costes | `ProductPrice` | Precio, coste por unidad y coste directo en unidades monetarias menores; moneda por fila. No equivale a un sistema de valoración de existencias. |
| Venta | `Order`, `OrderProduct`, `OrderService` | Pedidos, cantidades decimales, importes, descuento de cabecera y relaciones comerciales. Faltan canal, estado operativo, caja, turno, cobros y cierre inmutable. |
| Facturación | `Invoice`, `InvoiceLine`, `InvoiceService`, `InvoicePay` | Documentos y líneas vinculables al pedido; saldo y total pagado agregados. No existe un historial de transacciones de pago. |
| Entregas | `Delivery`, `DeliveryProduct`, `DeliveryService` | Entregas parciales referidas a líneas del pedido. No registran movimientos de inventario. |
| Compras | `PurchaseOrder`, `PurchaseOrderLine`, `PurchaseOrderService` | Pedido al proveedor, incluso relacionado con una venta. No existe recepción de mercadería con ingreso de stock. |
| Clientes | `Person`, `Organization`, `Customer` | Reutilizar personas, organizaciones y la relación cliente. No introducir otro maestro de clientes exclusivo del POS. |
| Impuestos | `TaxRate`, tasas guardadas en líneas | Base para impuestos; falta una política única de cálculo, descuentos e impuestos incluidos en precio. |
| Empresa y acceso | `Business`, `BusinessContext`, `BusinessIntegrity`, `Gate::before`, policies y Spatie | Aislamiento y autorización empresarial ya implementados en el código actual, condicionados por su configuración. Extenderlos al POS. |
| Interfaz y documentos | Livewire, Mary, vistas PDF y `PdfTemplateRegistry` | Construir la pantalla POS con el stack existente. Los PDF actuales no aportan por sí solos un ticket térmico ni control de impresión. |
| Infraestructura | PostgreSQL, Redis, workers, Octane y Reverb en `compose.yaml` | Base disponible para transacciones, colas y eventos. No hace falta incorporar un servicio nuevo para iniciar el POS. |

La consulta del esquema no encontró tablas de pagos, cajas, turnos, almacenes, sucursales, devoluciones o movimientos locales de stock. `crm_xero_items` sí tiene `inventory_tracked` y `quantity_on_hand`: son datos de integración, sin un kardex local ni ubicación/variante, y no sustituyen el inventario propuesto.

El documento anterior `PLN-MULTIEMPRESA-QAT-01.md` contiene instantáneas históricas. Para este plan se comprobó el código actual, `docs/MULTIEMPRESA.md` y la presencia real de `businesses` y restricciones empresariales. No se considera pendiente reconstruir toda la multiempresa.

## 3. Hallazgos previos a la implementación

Son hallazgos de inspección estática y de metadatos del esquema; no se ejecutó un cobro ni se modificaron datos para reproducirlos.

| ID | Hallazgo y referencia | Trabajo necesario |
| --- | --- | --- |
| P01 | `OrderService::create/update` guarda totales, precios y montos provenientes del request. `InvoiceService` también recibe esos valores; la API suma `line_items.amount`. La UI calcula importes, pero eso no constituye una autoridad común del dominio. | Recalcular en backend a partir de cantidades, precios autorizados y condiciones vigentes. Rechazar manipulación del total o cambios de precio sin permiso. |
| P02 | `InvoicePay::rules()` exige `size:saldo`, de modo que solo permite pagar el saldo completo. `pay()` resta al saldo tanto el nuevo importe como lo previamente pagado. La clase histórica `Http/Livewire/PayInvoice` repite el patrón. | Servicio único de cobros con registro de cada pago y bloqueo del saldo. Ejemplo: total 100, pagado 30, saldo 70, nuevo pago 70; la fórmula actual produce -30. La nueva liquidación debe dar saldo 0. |
| P03 | `Invoice` tiene `amount_paid`, `amount_due` y `fully_paid_at`; no hay entidad `Payment`. | Registrar importe, moneda, método, referencia, estado, operador, fecha y devolución asociada. Derivar agregados desde movimientos confirmados. |
| P04 | No hay una transacción global en los flujos revisados de creación de pedido/factura que abarque venta, pago e inventario. | Checkout atómico, idempotente y con bloqueos de base de datos. Una transacción tampoco puede volver atómica una llamada a un proveedor externo: ese caso necesita estados y conciliación. |
| P05 | `Product::unitPriceFor()` puede usar `getDefaultPrice()` aunque se haya solicitado otra moneda sin precio propio. | Resolver el precio exactamente en la moneda de la venta, incluido el respaldo de variante a producto; bloquear si no existe. No reinterpretar USD como PEN ni hacer conversión implícita. |
| P06 | `Money::toInteger()` multiplica por 100 y el cálculo actual usa floats. Los impuestos se resuelven en varios servicios y en `ModelProducts`. | Definir importes en unidades menores y cálculo decimal determinista; política explícita de redondeo y de descuentos. Primera versión limitada a monedas de dos decimales. Evitar volver a multiplicar importes al atravesar mutators existentes. |
| P07 | La resolución de la tasa legada usa `if ($product->tax_rate)`: un cero explícito cae al impuesto por defecto cuando no existe `taxRate`. | Distinguir ausencia de tasa de tasa cero; verificar productos exentos y precio con impuesto incluido/excluido. |
| P08 | `HasProductLineName` obtiene nombre y SKU de las relaciones actuales, incluso con `withTrashed()`. El soft delete preserva la relación, pero una edición cambia el texto histórico. | Guardar snapshots de nombre, SKU, variante, unidad, precio, descuentos e impuesto al confirmar una venta. El ticket debe usar esos snapshots. |
| P09 | El esquema inspeccionado no tiene unicidad comercial de barcode ni de numeración documental; las restricciones únicas observadas son las identidades `(team_id, id)`. | Resolver duplicados antes de añadir unicidad por empresa. Un barcode no debe identificar ambiguamente un producto y una variante. Mantener numeración interna y fiscal como contratos separados. |
| P10 | `NumberGeneratorService` ya separa cache por negocio cuando teams está activo. Depende de cache y no ofrece un correlativo fiscal por serie/tipo. | Reutilizarlo para numeración interna tras reforzar unicidad en DB; para series fiscales usar reserva persistente por empresa/serie/tipo. No prometer continuidad fiscal a partir del contador Redis. |
| P11 | Pedidos, facturas y líneas pueden editarse/eliminarse mediante los flujos CRM actuales. Algunas búsquedas de líneas en servicios usan el ID sin restringirlo al documento padre, por ejemplo `OrderProduct::find()` en `OrderService::update()`. | Proteger documentos confirmados en servicios compartidos y todos los puntos de escritura. Resolver líneas desde el documento autorizado, además de comprobar empresa. |
| P12 | `Order` calcula cumplimiento de facturación y entrega mediante relaciones y cantidades. La API de facturas revisada no propaga `order_product_id` en `buildProducts()`. | La proyección POS a factura debe conservar el vínculo de cada línea; verificar que factura, entrega y devolución no excedan cantidades vendidas. No asumir que reutilizar el endpoint actual resuelve todo. |
| P13 | `InvoiceService` contiene envío síncrono a Xero si la integración está conectada. | Separar persistencia y efectos externos. Checkout, factura y caja se confirman localmente; sincronización por outbox/job reintentable después del commit. |

## 4. Primera versión: tienda con ticket interno

Incluye:

- Una empresa con una o varias cajas y almacenes; comenzar configurando una caja y un almacén, sin exigir sucursales.
- Abrir turno con fondo inicial, vender, registrar ingresos/retiros manuales justificados y cerrar con arqueo.
- Catálogo rápido por nombre, código y barcode; selección explícita de variante, carrito y cliente opcional.
- Productos simples y con variantes, con control de inventario. Cantidades enteras o fraccionadas según el producto, usando la escala existente de tres decimales. Mantener compatibilidad con artículos sin inventario existentes, sin ampliar el lanzamiento a un POS de servicios.
- Una moneda configurada por caja. Sin cambio de moneda ni conversión durante una venta.
- Cobro en efectivo y registro manual de tarjeta/transferencia/billetera, con pagos mixtos y vuelto solo en efectivo. Registrar un pago manual no confirma una transacción con una pasarela.
- Existencias iniciales por conteo/importación controlada, recepciones, ajustes y salidas por venta. Bloqueo de stock negativo por defecto.
- Ticket interno imprimible desde el pedido y reimpresión; vínculo con entregas y compatibilidad con facturas del CRM cuando se emitan.
- Devoluciones parciales/totales, reembolsos, motivos y permisos; reposición de stock solo si corresponde.
- Reportes de ventas, cobros por método, arqueo/diferencias, devoluciones y movimientos de inventario.

Se planifican después, salvo que el giro o los requisitos de salida los vuelvan indispensables: operación offline, pasarelas o terminales integradas, emisión fiscal electrónica, varias monedas en un mismo cobro, promociones complejas, fidelización, lotes/series/vencimientos, conversiones de unidades, restaurantes/mesas/comandas y transferencias entre almacenes.

La emisión fiscal electrónica queda expresamente fuera de esta primera versión por elección del usuario. El ticket interno no requiere elegir ahora un proveedor fiscal ni implementar series fiscales, callbacks o envío electrónico.

## 5. Modelo de dominio propuesto

Los nombres son propuestas. Las nuevas tablas del módulo usarán el prefijo configurado, UUID y pertenencia empresarial consistente con el esquema vigente: `team_id` como FK a `businesses`, no a los equipos internos. No añadir un segundo discriminador `business_id` a las tablas CRM que ya usan `team_id`.

| Entidad | Responsabilidad y relaciones |
| --- | --- |
| `Order` / `OrderProduct` existentes | Venta y líneas canónicas. Añadir canal `crm/pos`, estado operativo, caja/turno, operador y momentos de confirmación/anulación. Las líneas conservan snapshots e importes históricos. |
| `CashRegister` | Caja lógica de una empresa, moneda, almacén y configuración de ticket. Puede tener turnos sucesivos. |
| `CashSession` | Turno: caja, usuario responsable, apertura, fondo, cierre, efectivo contado, esperado y diferencia. Un turno abierto por caja. |
| `CashMovement` | Libro de efectivo del turno: fondo, cobros, devoluciones, ingresos/retiros y retiros de cierre. Fuente y motivo; no contabiliza tarjeta ni transferencia como efectivo. |
| `Payment` | Movimiento de cobro del pedido, con vínculo a factura cuando corresponda, método, moneda, importe aplicado, recibido/vuelto de efectivo, estado y referencia. Los reembolsos referencian el pago original y la devolución; no borran el cobro. |
| `Warehouse` | Ubicación de existencias de una empresa. No representa otra empresa ni obliga a crear sucursales. |
| `InventoryBalance` | Saldo materializado por almacén y artículo vendible: producto simple o variante. Optimiza consulta y bloqueo. |
| `InventoryMovement` | Kardex inmutable: cantidad firmada, ubicación, producto/variante, tipo, fuente, usuario, fecha y coste cuando esté disponible. Es la autoridad del inventario. |
| `GoodsReceipt` / `GoodsReceiptLine` | Recepción física, opcionalmente ligada a `PurchaseOrder` y sus líneas; permite recepción parcial y trazabilidad del ingreso. Un pedido de compra no incrementa stock por sí solo. |
| `SaleReturn` / `SaleReturnLine` | Devolución referida a la venta y líneas originales, cantidades acumuladas, condición del artículo, importes y reposición. Se vincula a uno o varios reembolsos de `Payment`. |

Extensiones del catálogo: `track_inventory`, regla de cantidad fraccionada y, si se necesita para la primera versión, stock mínimo. La variante seleccionada identifica el stock; no crear otra ficha de producto para representarla.

Para barcodes, la búsqueda debe normalizar el valor y resolver una sola identidad vendible dentro de la empresa. Si se conserva el barcode en dos tablas, dos índices separados no impiden colisiones entre producto y variante. Propuesta: registro común `ProductIdentifier` con unicidad `(team_id, normalized_barcode)`, referencias al catálogo y escritura transaccional desde formularios/API existentes. Las columnas actuales se preservan para compatibilidad durante la transición. SKU y barcode tienen contratos separados; no exigir unicidad de SKU entre tablas sin definir primero su alcance.

La vinculación de pagos no debe crear doble contabilidad: cada cobro se registra una vez para la venta y, en el alcance inicial, se aplica a su factura vinculada. Cobros de facturas CRM sin pedido pueden referenciar directamente la factura. Si luego se requiere repartir un cobro entre varias facturas, introducir `PaymentAllocation`; no duplicar el `Payment`. Esta decisión exige definir también el tratamiento de pedidos con facturación parcial antes de habilitarlos en POS.

## 6. Reglas que deben quedar cerradas antes de programar la pantalla

### Estados y consistencia

- Separar estado comercial, situación de cobro y cumplimiento de entrega. Un pedido confirmado puede estar pendiente de pago o de entrega; los reembolsos no cambian el hecho histórico de que hubo una venta.
- El POS inicial confirma ventas totalmente pagadas y entregadas al momento. Suspender un carrito no cobra ni descuenta stock. Ventas a crédito/entrega posterior necesitan un flujo explícito posterior.
- Venta confirmada, pago, devolución y movimiento contabilizado son inmutables. Corregir mediante ajustes o reversos trazables, con motivo y permiso. Las restricciones alcanzan formularios CRM, API, jobs y acciones de líneas.
- El backend obtiene precio/tasa desde fuentes autorizadas; cualquier override deja usuario, motivo y permiso. Persistir descuentos de línea y cabecera con su distribución para facturar y devolver correctamente.
- Base imponible, impuestos incluidos/excluidos y redondeo por línea/documento se acuerdan antes de migrar. Usar aritmética decimal o escalada determinista, sin confiar en floats como autoridad final.
- Para efectivo: separar recibido, aplicado a deuda y vuelto. Si el saldo es 100 y se entregan 120, el cobro neto y aumento neto de caja son 100; el vuelto es 20.
- Reportar ventas y cobros como magnitudes diferentes: cobrar una deuda anterior aumenta cobros del día, sin crear otra venta del día.

### Inventario

- La cantidad inicial debe proceder de un conteo aprobado; los documentos históricos no prueban existencias actuales.
- Definir un solo evento de salida física compartido por POS y entregas CRM. Propuesta: confirmar cumplimiento/entrega genera la salida; el checkout POS confirma su entrega inmediata dentro de la misma transacción. Facturar o cobrar no vuelve a descontar.
- En la primera versión, crear la `Delivery` inmediata vinculada a las líneas POS para conservar los indicadores existentes de cumplimiento. No admitir cambios posteriores que vuelvan a contabilizarla; las entregas históricas no generan movimientos retroactivos al activar inventario.
- Crear una orden de compra no ingresa stock; confirmar una recepción sí. Las recepciones parciales no pueden superar lo autorizado sin permiso específico.
- Un producto con variantes controla existencias por variante. Evitar mantener simultáneamente un saldo genérico del padre y saldos de variantes que representen la misma mercadería.
- Devolver no implica siempre reponer: artículo dañado o no recibido físicamente requiere una decisión explícita.
- En la primera versión se informa cantidad física; si no hay reservas, no llamarla stock disponible para prometer pedidos futuros. Reservas y su liberación se incorporan cuando se habiliten pedidos pendientes con compromiso de stock.
- Los costes existentes son referencias; para reportar margen fiable se necesita coste histórico de salida y una política de valoración. No presentar precio de venta menos coste actual como utilidad histórica.

### Caja, seguridad y datos históricos

- Apertura, checkout, retiro, devolución y cierre bloquean el mismo turno. Cerrar una caja compite con vender y debe resolverse en DB, no con un botón deshabilitado.
- Efectivo esperado = fondo + cobros netos de efectivo + ingresos manuales - reembolsos en efectivo - retiros. Definir el arqueo antes del retiro final y registrar explícitamente cualquier fondo que quede para el siguiente turno. No contar dos veces movimientos derivados de pagos.
- Cada método se reporta por separado. Tarjeta, transferencia y billetera necesitan conciliación con su referencia; no aumentan efectivo físico.
- Permisos específicos: usar POS, abrir/cerrar caja, ver caja ajena, retirar efectivo, ajustar precio, conceder descuento, devolver, reembolsar y ajustar stock. Reutilizar los roles globales y asignaciones existentes, sin duplicarlos por empresa.
- Todas las referencias de caja, almacén, variante, cliente, pago y documento deben ser de la misma empresa y consistentes con el documento padre.
- Los saldos agregados de facturas antiguas requieren conciliación antes de activar el nuevo libro de pagos. Si se registran como saldo/movimiento de apertura, identificar que no se conocen sus métodos ni fechas; nunca introducirlos como efectivo de un turno actual ni sumarlos dos veces.

## 7. Checkout y restricciones de concurrencia

Flujo local propuesto:

1. Recibir una clave idempotente y un payload con artículos, cantidades, cliente y métodos de pago; calcular un hash estable del intento.
2. Validar empresa, permisos, caja, moneda y catálogo activo. Volver a validar el turno bajo bloqueo.
3. Reservar/reconocer el intento mediante unicidad persistente por empresa y clave. Misma clave y mismo payload devuelven la venta ya creada; misma clave y otro payload producen conflicto.
4. Recalcular importes en servidor. Bloquear saldos de inventario en orden estable y validar stock suficiente. Resolver también la concurrencia al crear un saldo inicialmente inexistente.
5. En una transacción PostgreSQL, guardar pedido/líneas, factura interna si aplica, entrega inmediata, pagos, movimientos de efectivo, salidas de inventario y resultado del intento. Verificar sumas y vínculos de línea.
6. Confirmar el commit y devolver el ticket. Publicar eventos, renderizar/enviar documentos y sincronizar integraciones fuera de la transacción mediante outbox/reintentos.

Un error de impresión no revierte una venta cobrada; el operador puede reimprimir. Un timeout del navegador se resuelve consultando el mismo intento, sin crear otra venta. Un error de validación deja el intento recuperable o fallido de forma explícita.

Restricciones mínimas en DB:

- Un turno abierto por caja, mediante índice único parcial.
- Una clave idempotente por empresa y operación, con payload y resultado persistentes.
- Un saldo por empresa, almacén, producto y variante. Como la variante puede ser nula, usar índices parciales para producto simple y variante; un `UNIQUE` nullable ordinario no basta en PostgreSQL.
- Relaciones compuestas de pertenencia y referencias al documento original. Las nuevas tablas requieren sus propias FKs: la migración empresarial que ya se ejecutó no cubrirá tablas futuras automáticamente.
- Cantidades y pagos con signo/dirección definidos, límites de devolución y referencias únicas de movimientos para evitar doble contabilización.
- Numeración comercial única por empresa y ámbito definido; las futuras series fiscales tendrán su propia reserva persistente.

Los locks Redis pueden ayudar, pero la consistencia del saldo, del turno y de la idempotencia debe quedar garantizada en PostgreSQL.

Si se integra un proveedor de pagos, el intento pasa por estados pendiente/confirmado/fallido/desconocido, con idempotencia del proveedor, autenticación y deduplicación de callbacks, y conciliación. No mantener locks de inventario mientras responde la red ni marcar pagado por una respuesta del navegador. Definir expiración/reserva de stock y recuperación de cobros confirmados cuando la persistencia local falla.

## 8. Implementación por fases y entregables

| Fase | Entregable | Condición de terminación |
| --- | --- | --- |
| 0. Contrato funcional | Giro confirmado: tienda de productos. Documento inicial confirmado: ticket interno. Resolver moneda, impuesto incluido/excluido, cantidades, medios de pago, caja y política de devoluciones; aprovechar ajustes vigentes del negocio. Diagramar estados y acordar cálculos con ejemplos. | Escenarios de cobro, devolución y cierre definidos, sin dependencia de emisión fiscal electrónica. |
| 1. Base comercial | Calculador compartido, precio por moneda, snapshots, validación del documento padre, protección de confirmados y barcode inequívoco. Migraciones aditivas y servicio de pagos con plan de conciliación del legado. | Los mismos artículos generan los mismos importes desde POS, CRM y API; tasa cero y descuentos tienen casos de prueba. |
| 2. Inventario | Almacenes, saldos, kardex, stock inicial, ajustes y recepciones; integración de entregas actuales con una sola salida. | Dos ventas concurrentes no venden la última unidad dos veces; no hay doble salida al entregar/facturar; recepciones y devoluciones trazables. |
| 3. Caja y checkout | Cajas, turnos, libro de efectivo, pagos mixtos, vuelto, cierre y servicio idempotente de venta. | Un checkout confirma todo o nada; reintentos no duplican; turno cerrado impide nuevas operaciones. |
| 4. Interfaz POS | Livewire/Mary: catálogo/scanner, variantes, carrito, cliente, cobro, carritos suspendidos, ticket y reimpresión. | Venta completa con teclado/scanner y validación de stock/precio actualizada al cobrar; recuperación de timeouts. |
| 5. Operación completa | Devoluciones/reembolsos, permisos de supervisor, arqueo, reportes y conciliación de métodos manuales. | Devoluciones parciales acumuladas no exceden lo vendido/pagado; cierre cuadra con movimientos. Esta fase forma parte del POS inicial operativo. |
| 6. Integraciones posteriores | Emisión fiscal, terminales/pasarelas y hardware específico. | Fase posterior al lanzamiento con ticket interno; requerirá definir proveedores y validar confirmación externa, reintentos, errores y conciliación. |

Se puede desarrollar la interfaz en paralelo conceptual a los servicios, pero no habilitar ventas reales antes de completar inventario, pagos, caja y las pruebas de consistencia. No se propone instalar un POS externo para reemplazar lo existente.

## 9. Archivos y áreas de integración

- Modelos y servicios: `modules/crm/src/Models/`, `modules/crm/src/Services/`, `modules/crm/src/Support/` y observers relacionados. Mantener el namespace de compatibilidad `VentureDrake\LaravelCrm`.
- UI: `modules/crm/src/Livewire/Pos/`, vistas de POS y administración de cajas/inventario bajo `modules/crm/resources/views/`.
- Rutas: `modules/crm/src/Http/routes.php`; API POS específica solo si se necesita, bajo `api-routes.php`, invocando los mismos servicios.
- Configuración: añadir la opción de módulo POS, sus dependencias y permisos en `laravel-crm.php`, policies, provider, navegación y seeder de permisos. Si se deshabilita una dependencia necesaria, rechazar la configuración o establecer un contrato explícito; no conceder CRUD amplio como sustituto del permiso de cobrar.
- Base de datos: las migraciones nuevas del módulo pueden alojarse en `modules/crm/database/updates/`, ya cargado por el provider. Las migraciones publicadas actuales de `database/migrations/` siguen siendo la base autoritativa del backend; no editar migraciones históricas para actualizar una instalación existente.
- Tenancy: extender `BusinessIntegrity` cuando haya referencias nuevas no declaradas y crear FKs explícitas para las relaciones nuevas. Preservar contexto scoped en Octane, jobs y reportes.
- Pruebas: escenarios POS en `tests/Feature/` y aislamiento empresarial en `tests/Tenancy/`; concurrencia real en una base PostgreSQL de pruebas separada.

## 10. Pruebas de aceptación antes de usarlo en una caja real

1. Venta simple y con variante; cantidades fraccionadas permitidas y rechazadas según producto; servicio sin movimiento de stock.
2. Artículo inactivo/eliminado no vendible; historial y reimpresión conservan nombre, SKU, precio y tasa originales tras editar el catálogo.
3. Barcode sin coincidencia o ambiguo no selecciona un artículo arbitrario; no hay cruce entre empresas.
4. Precio inexistente para la moneda bloquea el cobro; un total o descuento manipulado es rechazado o recalculado según contrato.
5. Impuesto cero explícito, precio con impuesto incluido, descuento global distribuido y devolución parcial conservan los importes acordados.
6. Pago exacto, efectivo con vuelto, mixto, importe insuficiente y referencia duplicada. Los agregados de la factura coinciden con el libro de pagos.
7. Dos cajas compitiendo por la última unidad: solo una confirma. Dos aperturas de la misma caja: solo una queda abierta.
8. Doble clic, timeout y reenvío del checkout: una sola venta, cobro, entrega y salida; misma clave con payload distinto da conflicto.
9. Fallo antes del commit: no queda venta parcialmente cobrada ni stock descontado. Fallo de impresión después: permite reimprimir sin otro cobro.
10. Cierre concurrente con venta/retiro/devolución: el turno y su arqueo quedan consistentes y cerrado impide nuevos movimientos.
11. Entrega parcial CRM y entrega inmediata POS descuentan una vez; factura y pedido de compra no generan movimientos físicos; recepción parcial ingresa solo lo recibido.
12. Reembolso parcial y repetido no excede pagos confirmados; retorno acumulado no supera cantidades vendidas; artículo dañado no se repone automáticamente.
13. Usuario sin permiso no cambia precio, devuelve, retira, ajusta inventario ni consulta otra caja restringida; empresas no comparten pagos ni existencias.
14. Editar/borrar un pedido o factura POS confirmado desde API, interfaz CRM o acciones de líneas respeta la misma protección.
15. Stock inicial y saldos de pagos históricos conciliados no generan ventas ni efectivo ficticio del día. Reportes separan ventas, cobros y devoluciones por moneda y fecha regional.

SQLite puede cubrir lógica y flujos generales, pero no demuestra la concurrencia, índices parciales y locks de PostgreSQL. Ejecutar los escenarios concurrentes en PostgreSQL aislado y aplicar los controles de formato, análisis estático y suite apropiados al implementar.

## 11. Próximo paso propuesto

Con giro y tipo de ticket confirmados, el siguiente paso de implementación propuesto es completar las reglas restantes de fase 0 desde los ajustes del negocio y comenzar la fase 1 con ejemplos numéricos, contratos de estado y migraciones aditivas revisables. La respuesta de alcance actualiza este plan; no inicia por sí sola la implementación. Este análisis no autoriza reinterpretar documentos históricos como ventas POS ni reconstruir stock a partir de ellos.

Esta entrega solo agrega este plan. No cambia lógica, configuración, esquema ni registros de la aplicación; no ejecuta pruebas que alteren la base habitual. Se preservaron los cambios locales preexistentes en `FileItem.php` y `BusinessIsolationTest.php`.
