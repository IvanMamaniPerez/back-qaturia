# Validación de catálogo de variantes — 2026-11-16

Rama: `feature/product-option-variant-matrix`, base `main@693138d`, árbol inicialmente limpio.
PHP/dependencias mediante `docker compose exec -T workspace`. Host sin PHP; no se instalaron
nuevas dependencias. Motor PostgreSQL **16.16** en Docker. Base aislada: **qaturia_variants_test**.
SQLite: exclusivamente `:memory:`. Los tests PostgreSQL de procesos rechazan otra base/entorno.

## Comandos ejecutados y resultados

| Comando (prefijo `docker compose exec -T workspace`) | Resultado |
|---|---|
| `php artisan test --compact` | 127 pruebas / 1215 aserciones, sin fallos, 118.12 s |
| `php artisan test -c phpunit.pgsql.xml --compact` | 131 pruebas / 1254 aserciones, sin fallos, 338.99 s |
| `php artisan test --compact --filter='ProductVariantMatrixTest\|ProductVariantBusinessTest'` | Verificación posterior de catálogo/UI: 26 pruebas / 204 aserciones, sin fallos |
| `php artisan test -c phpunit.pgsql.xml --compact --filter='ProductVariantMatrixTest\|ProductVariantBusinessTest\|ProductMatrixConcurrencyTest'` | Verificación posterior de catálogo/UI/tenancy/constraints/concurrencia: 30 pruebas / 244 aserciones, sin fallos, 54.39 s |
| `php artisan test --compact --filter='legacy V2 variation CRUD'` | Regresión final adicional: 1 prueba / 12 aserciones, sin fallos |
| `php artisan test -c phpunit.pgsql.xml --compact --filter='legacy V2 variation CRUD'` | Regresión final adicional en PostgreSQL: 1 prueba / 12 aserciones, sin fallos |
| `vendor/bin/pint --test --parallel` | 1006 archivos PHP, PASS |
| `vendor/bin/phpstan analyse --memory-limit=1G --no-progress` | Sin errores en el alcance configurado del repositorio (`phpstan.neon`) |
| `git diff --check` | Sin errores de whitespace |

Las suites generales se ejecutaron antes de añadir los últimos casos de cola/paginación de
preview, fotografía, edición legacy y regresión CRUD. Los comandos filtrados posteriores
verificaron esos cambios; los conteos anteriores no se suman como pruebas diferentes.
No hubo skips en estas ejecuciones. El análisis inicial con 128 MB no completó por memoria;
se repitió correctamente con 1 GB. Pint no pudo usar `--dirty` en Docker, por lo que se
formatearon rutas explícitas y luego se comprobó el repositorio completo.

## Evidencia PostgreSQL

Inspección real con `psql -U back_qaturia -d qaturia_variants_test`:

- `matrix_combination_unique` y `matrix_team_combination_unique` sin filtro por deleted_at:
  archivadas/inactivas conservan la identidad de combinación.
- 16 índices `matrix_*`, incluidos nombres/valores normalizados y sus índices de etiquetas.
- Tabla de selecciones: PK, UNIQUE `(product_variation_id,product_option_id)`, cuatro FKs
  compuestas por producto y empresa y constraint trigger `matrix_complete` diferible,
  inicialmente diferido. Se comprobó rechazo efectivo al COMMIT al quitar un valor usado.
- Dos procesos PHP independientes compiten contra un bloqueo del padre. Se verificó:
  misma clave -> dos respuestas 200 con resultado idéntico y cuatro identidades totales;
  claves diferentes/versión anterior -> un 200 y un 409; reordenación contra generación ->
  un 200 y un 409, mismas claves; 2047 archivadas + dos altas simultáneas -> 2048 totales,
  una alta rechazada; insert SQL adicional también rechazado.
- SQL directo con valor de otra opción/producto, combinación duplicada, etiqueta con
  normalized falsificado o propietario NULL ajeno falla por constraints/índices/triggers.
- `ProductVariantBusinessTest` verifica además scopes, rutas anidadas, imágenes ajenas y
  selección de venta de otra empresa. No se confía en SQLite para demostrar locks.

## Cobertura funcional

Simple sin variante ficticia; cuatro combinaciones o selección parcial; tres opciones y
2048 identidades incluyendo archivadas; Material con consentimiento explícito y ambas
estrategias; valores adicionales sobre matriz incompleta; renombrar/reordenar conservando
IDs/claves; reutilizar etiquetas después de renombrar sin reciclar IDs; mapeo manual legacy
con conflictos sin merge; idempotencia, versión/hash obsoletos, escritura parcial y rollback.

Referencias intactas en deal, cotización, pedido, factura, compra y entrega. Snapshots
prospectivos y best-effort para documentos previos. API documental conserva etiquetas
capturadas mientras el catálogo muestra nombres actuales. Precio solo en la misma moneda,
unidades mayores -> enteros menores una sola vez, herencia sin crear filas. Inventario
unknown y precios/costos del padre intactos al generar. Imágenes del propio producto,
fotografía privada y archivo fuente intacto. CRUD V2 y filas legacy siguen operativos.

Con 120 variantes persistidas: endpoint de 25 filas y menos de 40 consultas totales;
ficha embebe solo 100 y anuncia truncamiento. Livewire renderiza/edita 25 por página y el
preview muestra 100 por página; se probaron cola de selecciones, conflictos y controles de
versión. No se ejecutó navegador completo ni se generaron capturas; el render y las
acciones se verificaron mediante tests Livewire reales.

## Base operativa y entrega

Lecturas finales en `back_qaturia`: **2 variantes** originales; **0** ejecuciones de la nueva
migración. No se migró ni se vació esta base. No se accedió a producción.

El manual de adopción/rollback está en [PLN-VARIANTES-QAT-01.md](PLN-VARIANTES-QAT-01.md);
el contrato para React en [VARIANTES_V2.md](../API/VARIANTES_V2.md). Se entrega código local,
con commits; no se publican cambios ni se abre PR remoto desde esta sesión.

Para publicar la rama y abrir la revisión desde una sesión autenticada:

```bash
git push -u origin feature/product-option-variant-matrix
gh pr create --base main --head feature/product-option-variant-matrix \
  --title 'Catálogo de opciones y variantes con preservación de identidad CRM' \
  --body-file docs/PLANIFICACION/PR-VARIANTES-QAT-01.md
```
