# QATUR IA — Plan definitivo para internalizar el CRM UUID en back-qaturia (V2)

**Fecha de análisis:** 2026-11-10  
**Propósito:** ejecutar con Codex una migración _mecánica y verificable_ del código ya adaptado a UUID del paquete privado hacia el repositorio del backend. El resultado no utilizará submódulos Git, Composer `path repositories` ni dependencias externas del CRM propio. Mantendrá el comportamiento funcional existente y los cambios UUID hechos. **No implementar funcionalidades nuevas de QATUR IA durante esta migración.**

## 0. Repositorios, ramas y fuentes de verdad VERIFICADAS

| Fuente                                | Ref/commit verificado                                                   | Autoridad durante la migración                                                                                                                                                                                            |
| ------------------------------------- | ----------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `IvanMamaniPerez/back-qaturia`        | `integration-pkg-crm-base` — `a9ccc65d9a9fa131b9187877734e27f00b211105` | **Fuente de verdad** para `app/Models/User.php`, `app/Models/Role.php`, `app/Models/Permission.php`, `config/permission.php`, **todas** las migraciones publicadas en `database/migrations/`, y configuración del backend |
| `IvanMamaniPerez/pkg-laravelcrm-base` | `main` — `3326c42ec0f0e85e146e8d9e1424a290d133039f`                     | **Fuente de verdad** para código CRM modificado en `src/`, `database/seeders/`, `database/updates/`, `resources/`, `public/`, y defaults de configuración                                                                 |
| `venturedrake/laravel-crm`            | `2.6.0` — `4ffc0ccc4592fb5b7b4f754cce193cfb29ea0879`                    | **Solo referencia histórica**, no copiar para reemplazar el fork adaptado                                                                                                                                                 |

**Datos de auditoría:** la copia privada presenta 134 archivos existentes modificados y un archivo nuevo (`src/Support/LegacyUuid.php`) frente al origen 2.6.0. De las modificaciones, 84 son stubs SQL del paquete, y el branch de integración tiene 107 migraciones publicadas modificadas respecto a `main`. La rama de backend ya posee las migraciones UUID, además de `App\Models\User/Role/Permission`, prueba `tests/Feature/UuidPrimaryKeysTest.php`, archivo `.gitmodules`, submódulo `packages/laravel-crm` apuntando exactamente a `3326c42`, y Composer `repositories.type=path` más dependencia `ivanmamaniperez/pkg-laravelcrm-base:^1.0`.

**Prohibiciones absolutas:** NO usar `master`/HEAD de VentureDrake como fuente principal. NO volver a convertir los UUID. NO sobreescribir migraciones de `database/migrations` con stubs. NO cambiar IDs ni nombres de migraciones ya ejecutadas. NO recrear base de datos de trabajo ni de producción. NO hacer `migrate:fresh` fuera de una base EFÍMERA. NO hacer reemplazos globales de `VentureDrake\LaravelCrm` por `App` en esta operación. NO eliminar archivos que contienen personalizaciones antes de la comprobación de checksums.

## 1. Arquitectura de destino

```text
back-qaturia/
├── app/
│   ├── Models/
│   │   ├── User.php            # Único User autenticable; conserva HasUuids/HasRoles/traits CRM
│   │   ├── Role.php            # Único Role, propiedad del backend, hereda DIRECTAMENTE de Spatie Role
│   │   └── Permission.php      # Único Permission, propiedad del backend, hereda DIRECTAMENTE de Spatie Permission
│   └── ...                    # Resto del código propio del backend
├── modules/
│   └── crm/
│       ├── src/               # Código de pkg-laravelcrm-base, mismos FQCN salvo Role/Permission
│       │   ├── Models/        # Sin Role.php y Permission.php duplicados
│       │   ├── LaravelCrmServiceProvider.php
│       │   ├── Http/, Livewire/, Console/, Services/, Observers/, Jobs/, ...
│       ├── database/
│       │   ├── seeders/       # Seeders UUID y LegacyUuid conservados
│       │   └── updates/       # Migraciones de actualización cargadas vía loadMigrationsFrom
│       ├── config/
│       │   ├── laravel-crm.php
│       │   └── package.php    # Defaults internos; configuración publicada es la del host
│       ├── resources/         # Vistas, traducciones, assets, JS, CSS
│       ├── public/            # Fuente de assets compilados/publicables
│       ├── LICENSE.md         # Licencia MIT original conservada
│       └── SOURCE.md          # SHA exacto, origen y decisiones de integración
├── config/
│   ├── auth.php
│   ├── laravel-crm.php         # Host conserva ajustes actuales
│   ├── mary.php               # Host es autoridad
│   └── permission.php         # Host referencia App\Models\Role/Permission
├── database/migrations/      # Única copia ejecutable de las ~130 migraciones base adaptadas
├── public/vendor/laravel-crm/ # Assets publicados, no copia de clases PHP
├── bootstrap/providers.php   # Registra provider CRM ahora local
├── composer.json             # PSR-4 y helpers del CRM; sin dependencia Composer del fork
├── composer.lock
└── tests/
```

**Decisión de namespaces:** se mantiene `VentureDrake\LaravelCrm\...` para la mayoría de clases **solo como namespace de compatibilidad local**. Ya no es una dependencia externa: todo vive bajo `modules/crm` y Git del backend. Es deliberado: protege serialización de jobs, policy/model bindings, claves morph, Livewire y rutas. Para `User`, `Role`, `Permission` se usan exclusivamente clases `App\Models\...` del backend. Cambiar _todos_ los FQCN a `App\...` sería otra refactorización, de mayor riesgo, y no debe bloquear esta entrega. Documentar expresamente la diferencia entre el nombre PHP heredado y la propiedad del código.

**No mover `User` a un segundo modelo CRM.** `App\Models\User` ya es el modelo del backend y el configurado por autenticación; migrarlo de FQCN aquí añade riesgo sin valor. Tampoco crear `CRM\Models\User` paralelo. `Role` y `Permission` del host serán únicos y conservarán scopes y características del paquete.

## 2. Plan por fases y puertas de salida

### Fase A — Congelar origen y medir comportamiento

1. En `back-qaturia` confirmar rama `integration-pkg-crm-base` y estado limpio antes de generar la nueva rama `refactor/internalize-crm-uuid`. Guardar el SHA del HEAD; si hay modificaciones locales sin commit, abstenerse de borrarlas y registrar decisión.
2. `git submodule update --init packages/laravel-crm` y verificar `git -C packages/laravel-crm rev-parse HEAD` == `3326c42ec0f0e85e146e8d9e1424a290d133039f`. Si no coincide, DETENER.
3. Registrar hashes SHA-256 de todos los archivos relevantes del submódulo y, por separado, de `database/migrations`, modelos del host, config del host, rutas, asset manifest. Registrar `git status`, `composer show`, `composer validate`, `php artisan route:list --json`, `migrate:status`, `schedule:list`, `artisan list`, `artisan test` y pruebas existentes de UUID. Las pruebas que fallan inicialmente deben quedar informadas; no reportarlas como regresión ni ocultarlas.
4. Crear PostgreSQL 16 **efímero** para las pruebas. Determinar si la BD de desarrollo existente contiene registros previos con IDs bigint; si los contiene, NO correr recreación ni pretender que la edición de migraciones históricas convierte sus datos. Informar que requieren migraciones de datos/ventana de conversión independientes.

### Fase B — Copia 1:1 protegida

Verificar primero que `modules/crm` no exista o esté limpio, y que el submódulo esté inicializado. Ejemplo NO destructivo:

```bash
mkdir -p modules/crm
rsync -a packages/laravel-crm/src/ modules/crm/src/
rsync -a packages/laravel-crm/resources/ modules/crm/resources/
rsync -a packages/laravel-crm/public/ modules/crm/public/
rsync -a packages/laravel-crm/database/seeders/ modules/crm/database/seeders/
rsync -a packages/laravel-crm/database/updates/ modules/crm/database/updates/
rsync -a packages/laravel-crm/config/ modules/crm/config/
cp packages/laravel-crm/LICENSE.md modules/crm/LICENSE.md
```

- Comparar hash contenido/origen de todos los archivos copiados ANTES de editarlos. El único código fuente autorizado es el privado SHA `3326c42`.
- **No copiar `database/migrations/*.stub` al destino final.** Son duplicados de migraciones base ya publicadas. El provider y los comandos se adecuarán para que no dependan de esos stubs. Conservarlos únicamente en la copia histórica congelada del repositorio privado.
- En `modules/crm/config` eliminar `mary.php` y `permission.php` únicamente al terminar de modificar el provider: sus configuraciones activas son las del host. Mantener los defaults `package.php`, `laravel-crm.php` para `mergeConfigFrom` y documentar precedencia.
- No copiar `composer.json` del paquete como un paquete instalable dentro de `modules/crm`, ni su `.git`, cachés, `node_modules` ni `vendor`.
- Conservar `src/Jobs` sin conversiones indiscriminadas: el usuario excluyó IDs internos de jobs de la transformación general; las adaptaciones existentes de jobs con FKs a modelos UUID sí se deben preservar.

### Fase C — Unificación sin duplicar identidad y permisos (CRÍTICA)

**User:** conservar `app/Models/User.php` de la rama de integración: `HasUuids`, `HasCrmAccess`, `HasCrmTeams`, `HasRoles`, `HasFactory`, `Notifiable`. Mantener `config/auth.php` apuntando a ese mismo modelo. Auditar consumidores `App\User` de legado y, cuando sea seguro, convertir sus imports a `App\Models\User`, evitando depender de `class_alias`; si otros alias siguen siendo necesarios, mantenerlos explícitamente, con test.

**Role:** `app/Models/Role.php` hoy hereda `VentureDrake\LaravelCrm\Models\Role` y usa `HasUuids`. Reescribir **solo la herencia** para que extienda directamente `Spatie\Permission\Models\Role`, copiando íntegros los métodos/especificaciones de `pkg/src/Models/Role.php`: `scopeCrm`, `scopeCrmNotOwner`, `scopeAssignable`, `scopeAssignableBy` y reglas de asignación de Owner. Conservar `HasUuids` y anotaciones. No desactivar controles de escalación.

**Permission:** equivalente: `app/Models/Permission.php` extender directamente `Spatie\Permission\Models\Permission`, con `HasUuids`, `scopeCrm` y demás lógica propia del paquete.

**Eliminar duplicidades:** una vez reescritos los consumidores, remover `modules/crm/src/Models/Role.php` y `modules/crm/src/Models/Permission.php`; no dejar clases puente que permitan usar dos instancias de modelo diferentes sobre una misma tabla. Cambiar todos los `use VentureDrake\LaravelCrm\Models\Role` y `Permission` a `App\Models\Role/Permission` en controladores, seeders, Livewire, policies, formularios, validators, comandos, rutas y cualquier referencia `::class`/string de configuración. Mantener **los nombres de las tablas y los UUID**.

**Spatie:** conservar el paquete `spatie/laravel-permission`, sus contratos y relaciones internas; no duplicar ni modificar su código vendor. `config/permission.php` debe apuntar a `App\Models\Role`, `App\Models\Permission` y seguir usando UUID en `role_id`, `permission_id` y `model_id` según las migraciones **ya existentes**. Auditar soporte `teams`, `team_id`, `guard_name`, permisos de Owner, cache y policies. Probar `assignRole`, `givePermissionTo`, `hasRole`, `can`, sync y consultas inversas.

**UUID existentes:** preservar `modules/crm/src/Models/Model.php` con `HasUuids`, excepciones para `ChatVisitorPageView` y `FeatureVote` y `Support/LegacyUuid.php`; conservar seeders con UUID deterministas y operaciones idempotentes. No convertir cantidades, counters, importes, fechas ni IDs internos de backend de colas a UUID. Auditar SQL `DB::table`, casts `(int)`, reglas `integer`, `whereIn`, URL route-model binding, validaciones, tipos `string` y pivotes, sin reescribir gratuitamente los que ya estén correctos.

### Fase D — Migraciones autoritativas y comandos seguros (CRÍTICA)

- `database/migrations/` del **backend** permanece autoritativo y sus archivos cambian **cero bytes** durante esta migración salvo anomalía identificada, justificada y testeada de forma separada. Debe cumplirse una comparación hash antes/después.
- Conservar `modules/crm/database/updates/*.php` que no estén duplicadas por nombre/efecto. El `ServiceProvider` mantiene `loadMigrationsFrom(__DIR__.'/../database/updates')` y NO registra nuevamente migraciones base.
- En `LaravelCrmServiceProvider` eliminar todo registro de `publishes(..., 'migrations')` y `getMigrationFileName()` si ya no se usa; quitar dependencias/imports sobrantes sin alterar rutas, middleware, observers, policies, Livewire, traducciones, vistas, eventos, scheduling, ni bindings `scoped` (importantes para Octane).
- En `LaravelCrmUpdate`: eliminar **`vendor:publish --tag=migrations --force`** y cualquier republish de migraciones. Mantener `migrate --force`, seeders y backfills idempotentes con manejo explícito de errores. El camino `laravelcrm:update --force` no puede editar migraciones del host.
- En `LaravelCrmInstall`: eliminar o deshabilitar flujos que modifican `composer.json`, `User.php`, config y migraciones del host. Transformar a un bootstrap explícito e idempotente o retirar el comando del registro con un reemplazo documentado. Nunca ejecutar instalación original que publique stubs o pise los modelos.
- En `LaravelCrmUpgrade`: preservar/publicar recursos estáticos y limpiar cachés cuando corresponda; **nunca tocar base de datos ni migraciones base**. Adaptar mensajes/paths del repo local.
- Ensayar `php artisan migrate:fresh --seed` **SOLO** en PostgreSQL 16 efímero. Ensayar `php artisan laravelcrm:update --force` dos veces sobre ese entorno para comprobar idempotencia y cero cambios en archivos. Comparar listado y hashes de `database/migrations` antes y después. Los cambios estructurales `database/updates` deben ejecutarse una sola vez.
- Auditar FKs `users`, `roles`, `permissions`, `crm_*`, invitations, teams, polimórficas y `deleted_at`. Detectar discrepancias en migraciones `database/updates` no modificadas por el fork; no suponer que todas son UUID seguras por haber cambiado las bases.

### Fase E — Composer, provider, assets y Docker

1. Modificar `composer.json` del host: quitar `repositories` `path=packages/laravel-crm` y `require` `ivanmamaniperez/pkg-laravelcrm-base`. Agregar directamente **solo** dependencias runtime usadas por el CRM del package `composer.json` (`spatie/laravel-permission`, `livewire/livewire`, `laravel/sanctum`, `barryvdh/laravel-dompdf`, `cknow/laravel-money`, `doctrine/dbal`, `guzzlehttp/guzzle`, `mpdf/mpdf`, `php-flasher/flasher-laravel`, `rinvex/countries`, Mary e iconos Blade, etc.). No instalar Xero opcional si no se usa. No agregar `illuminate/support` de forma redundante cuando Laravel framework lo satisfaga.
2. Añadir `autoload.psr-4`: `"VentureDrake\\LaravelCrm\\": "modules/crm/src/"`, `"VentureDrake\\LaravelCrm\\Database\\Seeders\\": "modules/crm/database/seeders/"`; y `autoload.files` de los **ocho** helpers `SelectOptions`, `PersonName`, `AddressLine`, `AutoComplete`, `CheckAmount`, `Validate`, `PublicProperties`, `ConvertCase` bajo `modules/crm/src/Http/Helpers/`. Si se añade una clase propia de provider/alias o bootstrap, configurarla sin crear un segundo registro CRM.
3. En `bootstrap/providers.php` registrar **una sola vez** `VentureDrake\LaravelCrm\LaravelCrmServiceProvider::class`. El autodescubrimiento antiguo desaparece al retirar el paquete. Revisar si los consumidores usan la fachada `LaravelCrm` sin namespace; configurar el alias una vez o convertir imports a la fachada real.
4. En `composer.lock` resolver desde Composer **después** de copiar/editar código: `composer update --no-scripts --no-interaction` o operación equivalente controlada. Debe desaparecer el paquete privado y mantenerse el resto de dependencias usadas; no lanzar un upgrade masivo de librerías sin necesidad. `composer install` desde checkout limpio debe funcionar offline respecto del repo privado, pero puede seguir descargando dependencias públicas de Packagist.
5. El Dockerfile actual copia `packages/laravel-crm` antes del primer `composer install`: eliminar esa instrucción. Para conservar caché Composer puede hacer `composer install --no-scripts --no-autoloader` antes de `COPY . .`, y después de copiar el árbol completo ejecutar la creación de autoload optimizado y `package:discover`. Alternativa: copiar `modules/crm` antes del primer Composer. Elegir y probar una. No romper `app`, `reverb`, `queue`, `scheduler`, `vite` ni `workspace` de `compose.yaml`.
6. Conservar `resources`, `views`, `lang`, assets estáticos y comportamiento de rutas existentes; no copiar la UI Blade al front React ni borrar Livewire por el simple hecho de existir `front-qaturia`. En `public/vendor/laravel-crm`, contrastar integridad de archivos publicados y respetar manifest/bundle. No sobrescribir views customizadas del host con publicación forzada.
7. Finalmente quitar **únicamente** el gitlink `packages/laravel-crm`, `.gitmodules` (si no contiene otros submódulos), y restos de `repositories.path`; hacer esto después de checksum y pruebas iniciales. Limpiar referencias no ejecutables a `git@github.com:IvanMamaniPerez/pkg-laravelcrm-base.git`, pero documentar procedencia en `modules/crm/SOURCE.md`. No borrar el repositorio privado en GitHub: archivarlo como respaldo si se decide después, fuera de esta tarea.

### Fase F — Pruebas y matriz de aceptación

**Obligatorias:**

- `git status` limpio antes; diff final limitado al CRM integrado y su infraestructura, sin cambios silenciosos a UUID históricos.
- `composer validate --strict`, `composer install`, `composer dump-autoload -o`, `php artisan about`, `php artisan list`, `php artisan route:list --json`, `php artisan schedule:list`, `php artisan migrate:status`, `php artisan test`, `composer run lint:check`, `composer run types:check` (documentar fallas preexistentes).
- `class_exists(App\Models\User/Role/Permission)` verdadero; no se carga ningún `VentureDrake\LaravelCrm\Models\Role/Permission` residual; `config('permission.models.role')/permission` apunta a App.
- Crear User, Role, Permission con UUID; asociar; validar permisos, revocación y jerarquía Owner; hacer consultas por UUID y route bindings.
- CRUD de Lead/Deal/Person/Organization/Customer/Product/ProductVariation/Order/Invoice/Task, relación por FKs UUID y pivotes y polimorfismos; validar la API CRM v2 y errores JSON; rutas públicas autorizadas sin fuga de datos.
- Seeders de catálogo/pipeline/roles/permissions: primera y segunda ejecución sin duplicidades; UUID deterministas sin desajustes de FK.
- Ejecutar migración limpia PostgreSQL, actualizaciones `database/updates`, `laravelcrm:update --force` por duplicado, y comprobar hashes inalterados en `database/migrations`.
- Comprobar componentes Blade/Livewire, rutas, assets, PDF, chat y tareas programadas; verificaciones de login en frontend y backend.
- Probar reinicio y funcionamiento de `app`, `queue`, `scheduler`, `reverb`, y contenedor Docker recién construido. No debe depender de acceso SSH al repo privado ni de `git submodule update` para hacer build.
- Auditar cola: eventos en espera con clases `VentureDrake\LaravelCrm` deben deserializarse tras la importación; no cambiar FQCN de jobs en la fase. Verificar reinicio/reciclado de workers después del despliegue.
- Ejecutar `git grep`/`rg` para detectar referencias inesperadas al paquete privado, `packages/laravel-crm`, `vendor/venturedrake/laravel-crm`, código de `vendor:publish --tag=migrations`, clases Role/Permission duplicadas, namespace no resoluble y faltantes de fuente.

**Criterios NO negociables de entrega:**

1. Mantener intactos los cambios UUID de la rama y del fork; conservar seeders, helpers, assets, jobs y features.
2. `database/migrations` sin overwrite y sin duplicación de migraciones base; `database/updates` opera una sola vez.
3. Exactamente un `User`, un `Role` y un `Permission` **canónicos** del host, sin modelos duplicados del CRM.
4. `composer.lock` no contiene `ivanmamaniperez/pkg-laravelcrm-base` ni `venturedrake/laravel-crm` y ninguna descarga del paquete privado en builds.
5. Sin `.gitmodules`, sin gitlink, sin `repositories.path`; todos los archivos de código CRM en `back-qaturia`.
6. Tests de UUID, permisos, seeders, migraciones, CRM y despliegue pasando; no declarar éxito solo porque Composer instala.
7. Documentar cualquier bloqueo real. Si falla una condición, detener la etapa, conservar la rama y NO fusionar.

### Fase G — Entrega y reversión

- Crear PR contra **`integration-pkg-crm-base`** inicialmente, con cambios agrupados por commits: (1) snapshot + manifest, (2) identidad/roles-permisos, (3) provider/migraciones/composer, (4) assets/docker/tests, (5) eliminación del submódulo y limpieza.
- Adjuntar auditoría de archivos del fork y explicación de decisiones. Mostrar verificación de aislamiento de dependencias, hashes preservados, versiones Composer, pruebas de PostgreSQL y rollback.
- Reversión de código: volver al commit anterior a la PR; respaldar datos, verificar versión de esquema y colas antes de revertir un despliegue que haya ejecutado actualizaciones. No revertir automáticamente migraciones en una base real.
- La rama `feature/docker-php84-alpine324` contiene infraestructura alternativa y diverge de la integración; no fusionar su diseño de Docker en esta PR sin conciliación deliberada.
- No implementar en este PR `business_id`, tenant isolation, modelo comercial/POS, ni cambio de UI React. Eso es trabajo posterior. El UUID actual no implica que el aislamiento multiempresa del alcance QATUR IA esté completado.
