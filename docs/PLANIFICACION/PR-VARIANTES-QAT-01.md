Los productos admiten hasta tres opciones y 2048 variantes sin reemplazar las identidades
históricas del CRM. El motor compartido ofrece preview/apply con versión, hash e idempotencia;
las combinaciones archivadas conservan su clave y solo se restauran explícitamente.

La API V2 y el editor Livewire paginado permiten definir valores, generar selecciones,
mapear legacy, editar precios por moneda, imágenes y estados. Las escrituras parciales
conservan filas omitidas. Los documentos guardan snapshots y el precio heredado respeta
la moneda solicitada. No se añaden movimientos ni cantidades de inventario.

Validación: suites generales de SQLite/PostgreSQL, pruebas adicionales de catálogo/tenancy,
procesos PostgreSQL concurrentes, constraints reales, Pint y PHPStan. Ver resultados y
comandos en `docs/PLANIFICACION/VALIDACION-VARIANTES-QAT-01.md`.

Requiere la migración aditiva `2026_11_16_000001_create_product_option_matrix`. Solo se aplicó
a pruebas. Adoptar legacy con mapeo explícito antes de generar/publicar; el rollback conserva
el esquema y las identidades. Manual: `docs/PLANIFICACION/PLN-VARIANTES-QAT-01.md`.
