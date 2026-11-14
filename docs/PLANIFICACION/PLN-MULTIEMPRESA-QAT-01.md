# PLN-MULTIEMPRESA-QAT-01 — Negocios SaaS, configuración regional y aislamiento de datos

**Fecha:** 2026-11-14, America/Lima.  
**Estado:** multiempresa permanece en análisis; conversión regional de fechas implementada según el apartado 24.  
**Alcance regional vigente:** mantener la estructura de `LaravelCrmSettingsSeeder` y desplegar una instancia por país. No trasladar ahora moneda/país/zona/idioma a columnas de usuario o negocio. La verificación complementaria del apartado 22 prevalece sobre las propuestas regionales anteriores de este documento.  
**Última comprobación de flags:** los defaults locales de CRM y Spatie están ahora en `true` por cambios del usuario. Eso no completa el contrato de teams ni adapta su esquema; las pruebas regionales del apartado 24 aíslan esa funcionalidad con `LARAVEL_CRM_TEAMS=false`. El apartado 23 conserva la instantánea previa.  
**Decisión de pertenencia confirmada:** cada cuenta colaboradora pertenece inicialmente a un solo negocio; un negocio admite uno o varios usuarios.  
**Recomendación revisada de menor impacto:** reutilizar `team_id` como columna física de pertenencia empresarial donde ya existe; `crm_teams` conserva los equipos internos. Ver apartados 3.1 y 21 antes de generar migraciones.  
**Repositorio:** `back-qaturia`, rama `main`, HEAD auditado `4bc158f73d3349e818f7a31401f466662bb179cd`.  
**Alcance autorizado:** el análisis inicial fue de solo lectura. Posteriormente se autorizó implementar la conversión de fechas: instantes UTC y presentación/entrada en la zona del setting regional. No se autorizó reinterpretar fechas históricas ni modificar datos de la base habitual.

## 1. Decisión principal y límites

La configuración comercial y regional debe pertenecer al **negocio que contrata el SaaS**, no al usuario que inicia sesión ni a una instalación CRM compartida. Cada negocio tendrá su país, moneda predeterminada, monedas habilitadas, zona horaria, idioma y configuración documental. Sus usuarios pueden conectarse desde otros países sin modificar esos valores.

El código existente proporciona una base multimoneda por documento, pero **todavía no garantiza operación multimoneda integral ni aislamiento multiempresa**. Cambiar el lugar donde se guarda `currency` no corrige por sí mismo los precios, las sumatorias, las autorizaciones ni los procesos sin sesión.

Propuesta de separación:

| Nivel | Responsabilidad |
| --- | --- |
| Plataforma SaaS | Catálogos regionales compartidos, versión de esquema, salud, despliegue, proveedor de geolocalización y valores iniciales para altas. |
| Negocio, `Business` | País confirmado, moneda predeterminada, monedas autorizadas, zona horaria, idioma, identidad fiscal/comercial, marca, numeración e integraciones propias. |
| Equipo, `Team` | Agrupación interna de colaboradores de un negocio; no representa otra empresa ni define su región. |
| Usuario | Identidad autenticable, pertenencia y autorización. Las preferencias personales futuras no reemplazan la moneda o zona comercial del negocio. |
| Documento | Moneda e importes propios; conserva las condiciones históricas aunque cambie el perfil del negocio. |

Se preservan los UUID existentes, los nombres convencionales `id` y `*_id`, el namespace de compatibilidad `VentureDrake\LaravelCrm`, los tres modelos canónicos `App\Models\User/Role/Permission` y el código CRM embebido. No se plantea React, POS, reemplazo del CRM ni recuperación del antiguo paquete privado.

La restricción previa de **no modificar la estructura interna de Spatie ni las tablas de colas** sigue vigente. La pertenencia de cada cuenta a un único negocio permite reutilizar las asignaciones normales de Spatie; el apartado 5 define sus garantías y límites.

## 2. Evidencia del estado actual

### 2.1. Versiones y entorno inspeccionados

`composer show --direct` confirma Laravel 13.40.0, Octane 2.23.0, Livewire 4.4.8, Spatie Permission 6.25.0, Sanctum 4.3.3, Laravel Money 8.5.0 y Rinvex Countries 9.1.0. El driver Octane predeterminado es FrankenPHP (`config/octane.php` y `compose.yaml`).

La conexión de Laravel Boost mostró SQLite con un esquema mínimo distinto del backend de Docker. Por ello, **no se usó ese esquema como autoridad**. Se consultó PostgreSQL del servicio `postgres`, en modo lectura, verificando `current_database() = back_qaturia` y PostgreSQL 16.15.

Inventario físico observado: **97 tablas, 81 con prefijo `crm_`**. No existe `businesses` ni ninguna columna `business_id`. Hay 419 zonas en `crm_timezones`. La instantánea local contiene 1 usuario, 0 equipos, 4 roles y 43 settings; estos conteos son evidencia puntual, no supuestos para una migración futura.

Inventario de archivos del módulo: 78 archivos de modelos, 70 controladores, 61 requests, 42 policies, 25 servicios, 14 repositorios, 64 observers y 209 archivos Livewire. Son tamaños del área auditada, **no cantidades de archivos que deban modificarse**. Las búsquedas literales localizaron 97 archivos PHP consumidores de settings y 132 con referencias a equipo/contexto; ambos conjuntos se solapan.

### 2.2. Hallazgos que condicionan la implementación

| ID | Evidencia actual | Impacto |
| --- | --- | --- |
| H01 | `LaravelCrmSettingsSeeder` inserta un único conjunto global con `team_id = null`, `user_id = null`, `global = true`. | Los negocios compartirían moneda, país, marca y formatos si se conserva esa autoridad. |
| H02 | `SettingService::all()` hace `pluck('value', 'name')`; `set()` busca por nombre. La tabla no tiene unicidad por alcance. | Duplicar `currency` o `country` no crea perfiles: produce colisiones o escrituras sobre el alcance equivocado. |
| H03 | `BelongsToTeamsScope::apply()` solo filtra con teams activado, usuario y `currentTeam`; se desactiva en determinados contextos. `model_with_global` contiene `settings`, pero la comparación usa el nombre físico, normalmente `crm_settings`. | La ausencia de equipo no es una barrera de seguridad. El alcance y los defaults globales necesitan rediseño explícito, no activar una variable de entorno. |
| H04 | `User` usa `HasCrmTeams::crmTeams()`, pero no define el contrato Jetstream `currentTeam()/allTeams()/switchTeam()`. Existe `current_crm_team_id`; algunos flujos buscan `current_team_id`. | La compatibilidad con el modo teams histórico es parcial. No usarlo como aislamiento empresarial definitivo. |
| H05 | `SetApiTeamContext` acepta `X-Team-ID` contra `allTeams()` si existe; `OwnerInCurrentTeam` depende del mismo contrato. | API y web necesitan un resolvedor empresarial común y validación de pertenencia real. |
| H06 | `Product::unitPriceFor()` puede usar el precio de la moneda predeterminada cuando falta el de la moneda solicitada. | Un importe de una moneda puede acabar en un documento de otra sin conversión. |
| H07 | `Support/Money::toInteger()` multiplica por 100; servicios y Livewire dividen por 100 y redondean a 2 decimales. | PEN/USD no demuestran soporte general para monedas con 0 o 3 decimales. El parser también elimina caracteres sin conocer el separador decimal local. |
| H08 | `Dashboard` suma importes de leads, deals, quotes, orders e invoices y los presenta con una moneda de settings. | Puede sumar monedas distintas y etiquetar el resultado como una sola. Debe agrupar por negocio y moneda. |
| H09 | `NumberGeneratorService` usa una clave Redis por clase de modelo, sin empresa. | Contadores y locks compartidos; faltan restricciones empresariales de numeración. |
| H10 | `LaravelCrmReminders` utiliza `get('timezone')->value`, aunque `get()` devuelve el valor escalar. Las campañas leen correctamente el escalar, pero del contexto de settings actual. | Los recordatorios recurren a UTC; las campañas tampoco tienen un negocio explícito como autoridad regional. |
| H11 | Los modelos de roles son globales; los pivotes Spatie no tienen contexto empresarial. Las policies se basan normalmente en `hasPermissionTo()`. | El permiso funcional no demuestra pertenencia al negocio de un registro. |
| H12 | El formulario de ajustes solo ofrece `english`; hay traducciones `en`, `en_au`, `en_gb`, `fa`, sin `es`. Laravel usa `APP_LOCALE`; no se encontró conexión general desde el setting `language`. | Cambiar el país o escribir `spanish` no traduce la aplicación. |
| H13 | `PortalTeam`, portales de documentos y `SettingService::forTeam()` resuelven marca/contexto mediante `team_id`, incluso sin autenticación. | Deben migrar a la empresa del recurso verificado, conservando enlaces y autorización pública. |
| H14 | `routes/channels.php` convierte los UUID a `int` para compararlos. `ChatMessageSent` emite el cuerpo del mensaje por un canal público basado en UUID. | Bloqueo de seguridad previo a exposición multiempresa: comparar UUID como cadenas y autorizar cada suscripción sensible; un identificador difícil de adivinar no sustituye autorización. |
| H15 | Logos sin teams se guardan en una ruta compartida y con el nombre original. Archivos se organizan por modelo/ID. | Deben separarse rutas, permisos de descarga y claves de cache por negocio. |
| H16 | `Api\V2\AuthController` y `IssueApiToken` llaman a `createToken()`, pero el `User` actual no incorpora `HasApiTokens`; tampoco se observó `personal_access_tokens` en el esquema o migraciones inspeccionados. | Prerrequisito independiente para prometer una API SaaS por tokens. No confundir dependencia Composer instalada con autenticación por token lista. |
| H17 | `Support/TeamMembership::inCurrentTeam()` devuelve verdadero si teams está desactivado o si el usuario no expone `allTeams()`. | Sustituir esa compatibilidad permisiva por pertenencia empresarial comprobada antes de gestionar colaboradores. |
| H18 | El middleware de settings conserva una consulta remota de versión con metadatos de instalación e IPs. | Separarla de cualquier setup de negocio o GeoIP y decidir su retirada antes de publicar el SaaS. |

Los hallazgos anteriores proceden de inspección estática y consultas del esquema. No se ejecutaron ataques, envíos reales de campañas ni pruebas de explotación en esta revisión.

## 3. Qué significa negocio en este repositorio

Crear `App\Models\Business` sobre `businesses`. **No reutilizar `crm_organizations`**: representa organizaciones/contactos comerciales atendidos por el CRM, no el dueño de los datos. Tampoco convertir `Customer` en el tenant.

Relaciones de dominio previstas:

- Un negocio tiene uno o más colaboradores y al menos un administrador activo cuando está activo.
- Un negocio puede tener cero o varios equipos internos. Un negocio con un solo colaborador debe funcionar sin crear equipos artificiales.
- Un equipo pertenece exactamente a un negocio; sus integrantes deben pertenecer a ese mismo negocio.
- Un registro comercial pertenece a un negocio. En el esquema legado se propone conservar `team_id` como FK física hacia `businesses`; no representa al equipo interno `crm_teams`. Una asignación interna de registros a equipos, cuando sea necesaria, utilizará una relación distinta y explícita.
- Un documento puede estar en una moneda habilitada distinta de la predeterminada del negocio.
- Un administrador de negocio no adquiere acceso administrativo a la plataforma ni a otros negocios.

No se reinterpretará automáticamente todo `team_id` histórico como equipo interno. En una instalación con tenants Jetstream históricos, cada identificador puede haber significado otra empresa: será necesario un mapeo revisado `legacy_team_id → business_id` y conservar el dato original hasta completar la conciliación.

### 3.1. Reutilización del esquema: negocio lógico y columna física

La inspección adicional de PostgreSQL confirma `team_id` UUID en **75 tablas**, incluida `xero_tokens` y la propia `crm_teams`. No se encontraron FKs sobre esas columnas hacia una raíz de tenant. Las FKs comprobadas de agrupación interna son `crm_team_user.crm_team_id` e invitaciones hacia `crm_teams.id`, y `users.current_crm_team_id` hacia esa misma tabla.

Por tanto, `team_id` y `crm_team_id` **no son actualmente la misma relación**. El primero fue preparado para la cuenta externa del host Jetstream/Spark; el segundo vincula equipos internos del CRM. La configuración del módulo hace esa distinción expresamente.

Para reducir cambios de esquema, la recomendación revisada es crear `businesses` como raíz explícita y conectar el discriminador ya existente a esa raíz. No hace falta agregar un segundo discriminador empresarial a las 75 tablas ni renombrar todas sus columnas para que el modelo de dominio se llame Business.

| Entidad de ejemplo | Identificador propio | Pertenencia propuesta |
| --- | --- | --- |
| Negocio que contrata el SaaS | `businesses.id = B` | Perfil, región y administrador de B. |
| Colaborador | `users.id = U` | `users.business_id = B`; cada cuenta colaboradora tiene un solo negocio. |
| Equipo interno Ventas | `crm_teams.id = T` | `crm_teams.team_id = B`. |
| Miembro de Ventas | Pivote existente | `crm_team_user.crm_team_id = T`, `user_id = U`; comprobar que ambos pertenecen a B. |
| Organización cliente Acme | `crm_organizations.id = O` | `crm_organizations.team_id = B`. Acme no es el negocio contratante. |
| Documento comercial | UUID del documento | `team_id = B`, relación comercial con O cuando corresponda. |

En PHP, la relación se podrá llamar `business()` aunque utilice la FK heredada `team_id`. El contexto y las autorizaciones se expresan en términos de negocio. Las tablas de dominio nuevas y `users` podrán utilizar `business_id`; cada modelo debe tener un único mapeo explícito de su columna empresarial. No mantener dos campos de pertenencia que puedan divergir en una misma fila.

**Convención para el resto del plan:** las menciones conceptuales de pertenencia empresarial se resuelven con ese mapeo. Donde ya exista `team_id` se reutiliza; las nuevas columnas solo se añaden cuando falte pertenencia suficiente. Los equipos internos siguen utilizando `crm_teams.id`/`crm_team_id`, no el discriminador legado del tenant. Esta recomendación sustituye la adición generalizada de un `business_id` paralelo a todos los modelos CRM.

No se reutiliza automáticamente `currentTeam` para ambos conceptos. El contexto comercial procede de `users.business_id`, mientras que `current_crm_team_id` solo identifica un equipo interno si ese flujo se necesita. Se revisarán los consumidores del modo Jetstream histórico sin activarlo como atajo.

## 4. Catálogos globales y perfil regional propuesto

Todos los nuevos modelos de dominio tendrán PK UUID llamada `id`. Los códigos internacionales son atributos únicos de referencia, no nuevos nombres para las PK.

| Tabla / cambio propuesto | Datos y restricciones principales | Alcance |
| --- | --- | --- |
| `countries` | `id uuid`, `code char(2)` único, nombre canónico, activo. Etiquetas traducidas separadas del código. | Global, lectura para negocios. |
| `currencies` | `id uuid`, `code char(3)` único, nombre, símbolo de presentación, `minor_units`, activo, habilitado para operar. | Global. No representa tipos de cambio. |
| `crm_timezones`, existente | Conservar IDs y nombre IANA; conciliar duplicados antes de añadir unicidad de `name`. `offset`/`diff_from_gtm` dejan de ser autoridad temporal. | Global, reutilizar. No crear una segunda tabla de zonas equivalente. |
| `country_currencies` | `id uuid`, `country_id`, `currency_id`, indicador de sugerencia predeterminada y unicidad del par. | Catálogo global; admite varias monedas por país. |
| `country_timezones` | `id uuid`, `country_id`, `timezone_id` hacia `crm_timezones`, unicidad del par. | Global; admite varias zonas por país. |
| `businesses` | `id uuid`, nombre, estado, `owner_user_id`, `country_code`, `default_currency_code`, `timezone_id`, `locale`, formatos, versión de configuración y fechas. | Autoridad del perfil empresarial. |
| `business_currencies` | `id uuid`, `business_id`, `currency_id`, activo; unicidad del par. | Monedas habilitadas para documentos del negocio. |
| `crm_settings` | Reutilizar `team_id` como pertenencia para ajustes empresariales no tipados en `businesses`; conservar ID y alcance de preferencias de usuario existente. | Extensión del negocio, no reemplazo de columnas regionales. |
| `crm_teams`, `crm_team_user`, invitaciones | Añadir pertenencia empresarial; integridad contra empresa de usuarios y equipo. | Equipo interno. |
| `business_roles` | `id uuid`, `business_id`, `role_id` UUID hacia el modelo Spatie canónico, nombre visible y clasificación del rol. | Roles efectivos por negocio sin alterar la estructura de Spatie; ver apartado 5. |
| `business_document_sequences` | `id uuid`, `business_id`, tipo/serie, próximo valor, unicidad del alcance. | Reserva atómica de numeración empresarial. |

`country_code` y `default_currency_code` podrán ser FKs a los códigos únicos de sus catálogos. Las columnas `currency` ya existentes en documentos conservan sus valores ISO y su tipo compatible: **no convertir masivamente los códigos de moneda históricos a UUID**. Los nuevos catálogos siguen teniendo IDs UUID.

La moneda de `businesses` se llama **predeterminada**, no se declara una moneda funcional contable sin implementar esa lógica. La moneda del documento sigue siendo obligatoria e independiente. La moneda predeterminada debe figurar entre las habilitadas del negocio.

El perfil regional no debe ser una única fila JSON sin restricciones para país/moneda/zona. Usar columnas y FKs para los atributos nucleares; reservar settings para extensiones con claves permitidas y validación tipada.

Los datos ya disponibles en Rinvex incluyen códigos ISO y unidades menores; `Country::getTimezones()` usa los identificadores por país de PHP. Es posible construir estos catálogos sin agregar una nueva dependencia solo para listar países, monedas o zonas. El proveedor de geolocalización es una capacidad distinta.

## 5. Pertenencia de usuarios y autorización: decisión confirmada

Cada cuenta colaboradora pertenecerá inicialmente a **un solo negocio**, conforme a la decisión confirmada del producto. Un negocio puede tener varios usuarios y debe conservar al menos un administrador activo. La relación de colaboradores es `Business hasMany User` y `User belongsTo Business`.

No crear una membresía N:M `business_user`, un selector de empresas ni permisos diferentes por empresa para la misma cuenta en esta entrega. `users.business_id` es una FK ordinaria e indexada, **no única**: muchas cuentas pueden pertenecer a la misma empresa.

### 5.1. Diseño adoptado — Una cuenta colaboradora pertenece a un negocio

Reutilizar las asignaciones normales de Spatie sin cambiar sus tablas:

1. Añadir `users.business_id` UUID. Para colaboradores CRM será obligatorio; una identidad externa de portal o de plataforma deberá tener un flujo explícito distinto. No conceder acceso CRM empresarial a una cuenta sin negocio.
2. Mantener `App\Models\User` como único autenticable. Su autenticación por email no debe depender de un global scope que presuponga un negocio antes del login.
3. Mantener global el catálogo de permisos. Crear roles efectivos propios del negocio y vincularlos en `business_roles`, con `role_id` único para impedir que un rol mutable sea compartido entre empresas.
4. Debido al índice existente `(name, guard_name)`, los roles efectivos necesitarán un nombre técnico globalmente único. Su nombre visible, por ejemplo Admin o Vendedor, se conserva en `business_roles`. Las referencias literales `hasRole('Owner')`, restricciones por nombre y formularios deben pasar por la clasificación empresarial del rol.
5. Validar que el rol asignado al colaborador pertenezca a su negocio en controladores, Livewire, CLI, importaciones y seeders. Las plantillas globales no son roles editables por tenants.
6. Las policies comprobarán permiso **y** negocio del recurso; roles estándar sin contexto no permiten cruzar empresas. No habrá un bypass de pertenencia para Owner.
7. No permitir trasladar una cuenta a otro negocio mediante un simple `UPDATE`: existen asignaciones y referencias históricas. Ese traslado requiere un procedimiento específico o una identidad separada.

Este diseño soporta varias empresas en la misma base, cada una con sus usuarios, roles personalizados y equipos. **No soporta que la misma cuenta sea Owner en A y Employee en B.** Es un límite confirmado de la primera entrega, no una pertenencia múltiple pendiente de implementar.

### 5.2. Evolución futura fuera de alcance — Una cuenta en varios negocios

Si el producto incorporase pertenencia múltiple más adelante, requeriría una membresía `business_user` con UUID, estado y unicidad `(business_id, user_id)`, un negocio activo validado por request y asignaciones de rol también delimitadas por membresía/negocio. No crear esas estructuras preventivamente en esta entrega.

**No sirve** añadir solamente `business_user` y conservar sin cambios `HasRoles`: las asignaciones actuales pertenecen al usuario y podrían combinar permisos de todos sus negocios.

Spatie v6 ofrece aislamiento mediante teams, pero habilitarlo en una instalación existente exige cambios de esquema en sus roles/pivotes y manejo del contexto y relaciones al cambiar de tenant. Esa vía contradice la restricción vigente de no alterar esas tablas; requiere una decisión explícita posterior. Su identificador de autorización representaría al **negocio**, no al equipo interno. [Referencia oficial de Spatie v6](https://spatie.be/docs/laravel-permission/v6/basic-usage/teams-permissions).

La alternativa que respeta dicha restricción es introducir asignaciones empresariales en tablas propias y un resolvedor de autorización por membresía, adaptando todos los consumidores directos de `hasRole`/`hasPermissionTo`/`can`, formularios, políticas y caches. Es una intervención mayor; no se recomienda introducir esa segunda autoridad de permisos de manera parcial.

**Contrato de esta entrega:** implementar la pertenencia única del apartado 5.1. Mantener `permission.teams` desactivado, sin reinterpretar su `team_id` como empresa ni añadir una membresía N:M. Se conserva un único modelo autenticable y los modelos canónicos de autorización. Cualquier pertenencia múltiple futura requiere otro análisis y alcance.

### 5.3. Garantía del administrador mínimo

Crear negocio, primer usuario vinculado por `users.business_id` y su rol administrativo dentro de una transacción. El negocio pasa de `provisioning` a `active` solo al completar el perfil y verificar un administrador activo.

`owner_user_id` debe referenciar una identidad de ese negocio, con borrado restringido. Reforzar su pertenencia mediante FK compuesta hacia `users(business_id, id)`. La condición de administrador activo requiere además una operación de dominio: una FK no verifica roles ni soft deletes.

Toda baja, suspensión, soft delete, cambio de rol o transferencia de propiedad debe bloquear la fila del negocio, comprobar los administradores restantes y rechazar dejarlo sin ninguno. Incluir operaciones simultáneas de dos administradores en las pruebas. El bloqueo no se limita al controlador de eliminación de usuarios.

## 6. Geolocalización de alta: propuesta y límites

La detección se ejecutará durante **el alta del negocio**, aunque el primer usuario se cree en esa misma operación. Invitar a otro colaborador desde otro país no recalcula el perfil del negocio.

Flujo propuesto:

1. Obtener la IP mediante el request de Laravel después de configurar proxies de confianza según la topología real. No confiar directamente en `X-Forwarded-For` ni aceptar headers de país de cualquier origen. [Laravel 13: proxies](https://laravel.com/docs/13.x/requests#configuring-trusted-proxies).
2. Si es una IP privada, loopback, reservada, desconocida o una ejecución CLI, no intentar localizarla como si fuese pública. En Docker local este caso es normal.
3. Consultar un adaptador de geolocalización intercambiable, con timeout corto y resultado opcional. Elegir proveedor, licencia y modalidad local/remota antes de agregar dependencias o credenciales.
4. Cruzar el país sugerido con los catálogos globales. Una tabla de monedas y zonas no transforma una IP en ubicación: se necesita una base GeoIP o servicio equivalente.
5. Si el proveedor entrega una zona IANA válida y compatible, sugerirla. En países con varias zonas, permitir escoger; puede usarse la zona reportada por el navegador como señal adicional, nunca como identidad empresarial.
6. Sugerir la moneda principal del catálogo del país y permitir corrección. Una moneda por país no es una regla universal.
7. Mostrar país, moneda y zona para confirmación del administrador. Conservar procedencia de la sugerencia y fecha de confirmación; el administrador puede elegir valores diferentes.
8. Sin geolocalización, mostrar selección manual y valores iniciales identificados como sugeridos. Antes de activar el negocio, exigir confirmación regional; una caída del proveedor no bloquea el alta.

VPN, redes móviles, proxies y ubicaciones ausentes impiden usar la IP como certeza de residencia o domicilio de una empresa. No derivar impuestos, razón social ni régimen fiscal desde esa señal. [Limitaciones documentadas por MaxMind](https://support.maxmind.com/knowledge-base/articles/maxmind-geolocation-accuracy).

No reconfigurar una empresa cuando un usuario viaja, se conecta por VPN o cambia de dispositivo. La IP tampoco selecciona el negocio ni sustituye la pertenencia autenticada. Evitar almacenar la IP cruda como atributo permanente del perfil si basta guardar país/zona sugeridos y su procedencia.

## 7. Impacto monetario: compatibilidad existente y trabajo requerido

### 7.1. Qué se puede conservar

`Lead`, `Deal`, `Quote`, `Order`, `Invoice`, `PurchaseOrder` y sus líneas ya guardan moneda. Los formularios de documentos ofrecen selección de moneda. `crm_product_prices` contiene moneda y puede representar precios por producto/variación. Las bibliotecas monetarias y los datos ISO están instalados.

Esa base se reutiliza. No es necesario mover importes a usuarios ni crear columnas `amount_pen`, `amount_usd`, etc. El mismo producto de un negocio puede tener precios explícitos en varias monedas habilitadas.

### 7.2. Correcciones previas a declarar multimoneda completa

- Reemplazar el factor fijo 100 por las unidades menores de la moneda en todos los caminos de entrada, persistencia, cálculo y presentación. Usar aritmética decimal/entera apropiada, con redondeo explícito, evitando que floats sean autoridad del importe.
- Distinguir dinero, cantidades y porcentajes: un porcentaje fiscal no cambia de escala por moneda y una cantidad fraccionaria no es un importe monetario.
- El API recibirá importes decimales canónicos y código ISO validado; el frontend normalizará su formato local. `1.234,56` no puede procesarse eliminando caracteres y suponiendo que el punto es siempre decimal.
- `Product::unitPriceFor()` debe buscar el precio del producto o variación en la moneda **del documento**. Si falta, exigir precio explícito o rechazar la línea. No trasladar el mismo número de otra moneda ni reemplazar silenciosamente por cero.
- Revisar `ProductService::update()` y el editor de variaciones: actualmente pueden conservar la moneda de la fila de precio original en vez de la seleccionada. El CRUD multimoneda debe identificar inequívocamente la fila que se edita.
- Validar que moneda del documento, líneas y precios seleccionados coincidan; propagar moneda al convertir lead → deal → quote → order → invoice y a compras. Un cambio de moneda requiere recalcular desde precios apropiados, nunca solo cambiar el código.
- Corregir dashboard y reportes para agrupar por negocio y moneda, utilizando `(team_id, currency)` en las tablas CRM legadas. No sumar PEN + USD y formatear en la moneda predeterminada.
- Añadir unicidad de precio activo: `(team_id, product_id, currency)` para precio base y `(team_id, product_variation_id, currency)` para variaciones, mediante índices parciales apropiados con soft deletes. Aquí `team_id` es el negocio; conciliar duplicados antes de crear restricciones.
- Verificar rangos: existen columnas monetarias `integer`; estudiar `bigint` o precisión decimal según máximos del dominio mediante migraciones nuevas, sin alterar los UUID.
- Revisar adaptadores Xero, PDF, exportaciones, máscaras y redondeos; no basta corregir `Support/Money` si hay divisiones locales por 100.

Pruebas mínimas de escala: PEN/USD con dos decimales, JPY con cero y KWD con tres. Los datos de unidades menores ya pueden inspeccionarse en los catálogos instalados; el control de códigos vigentes debe contrastarse con la autoridad de mantenimiento ISO 4217. [SIX: estándares de datos](https://www.six-group.com/en/products-services/financial-information/market-reference-data/data-standards.html).

Hasta completar esa corrección, un catálogo internacional puede estar disponible para selección regional, pero solo se habilitarán para operar monedas cuya escala esté cubierta. La aceptación del objetivo internacional exige superar las pruebas de escalas diferentes, no dejar esa limitación escondida.

### 7.3. Tipos de cambio y cambios de perfil

No se encontró un servicio de tipos de cambio monetarios o una estructura de conversión histórica. La propiedad calculada `conversionRate` del dashboard mide conversión comercial de leads, no FX.

Para una primera entrega independiente por moneda, mantener totales separados y no implementar conversión implícita. Si posteriormente se requiere consolidación en una moneda funcional, abrir otro alcance con par origen/destino, fecha, tasa decimal, fuente, reglas de redondeo y snapshot en el documento. No prometer consolidación por crear `currencies`.

Cambiar la moneda predeterminada de un negocio solo afecta nuevos valores sugeridos. No modifica moneda, unidades menores, importes ni precios históricos. No deshabilitar para lectura una moneda usada por documentos anteriores; restringir únicamente nuevas operaciones según la política del negocio.

## 8. Zona horaria, fechas e idioma por negocio

Guardar una zona IANA, por ejemplo `America/Lima`, mediante la referencia a `crm_timezones`. Un país o un offset como `UTC-05:00` no reemplaza sus reglas. IANA mantiene cambios de límites, offsets y horario estacional; el cálculo debe usar las reglas para la fecha concreta. [Base oficial de zonas IANA](https://www.iana.org/time-zones).

Mantener servidor y persistencia de instantes en UTC. El esquema existente utiliza en muchos campos `timestamp without time zone`: auditar si cada campo representa un instante normalizado o una fecha comercial antes del backfill. No ejecutar una conversión masiva de fechas sin determinar su semántica histórica.

| Tipo de dato | Contrato previsto |
| --- | --- |
| Instante: creación, envío, evento | Guardar UTC y convertir al presentar con zona del negocio. API con ISO 8601 y offset explícito. |
| Fecha comercial: vencimiento o emisión cuando el dominio usa solo día | Conservar fecha civil; no desplazarla por una conversión UTC automática. |
| Cita o campaña programada localmente | Validar hora y zona del negocio, resolver ambigüedad DST y persistir instante UTC más contexto necesario. |
| Filtro “hoy”, mes o vencido | Construir intervalo en la zona del negocio y convertir sus límites a UTC antes del query. |
| Offset de catálogo | Informativo y calculado para una fecha; no autoridad permanente de conversión. |

Corregir recordatorios para consumir el valor regional empresarial y consultar ventanas temporales coherentes. Las campañas ya guardan información de programación: una fecha UTC confirmada no debe desplazarse porque después cambia la zona del negocio. Para recurrencias futuras se debe definir qué calendario local gobierna las nuevas ejecuciones.

No usar `date_default_timezone_set()` por tenant ni mutaciones globales de timezone/config en un worker persistente. Resolver la zona desde el negocio explícito de la operación. En Octane se requieren servicios por ciclo y limpieza del estado; el contexto empresarial debe sobrevivir solo lo que corresponde al request/job. [Laravel: bindings scoped](https://laravel.com/docs/13.x/container#binding-scoped).

El idioma empresarial utilizará locales compatibles con Laravel, por ejemplo `es` o `en`, con traducciones completas y fallback definido. Migrar el valor legado `english` a `en` de forma explícita. Agregar `es` sin archivos de traducción y sin aplicar el locale por ciclo no satisface el requisito. Los nombres técnicos `currency`, `timezone` y códigos internacionales no se traducen; sus etiquetas visibles sí.

## 9. Separación de los settings existentes

| Grupo | Claves / información actual | Destino y comportamiento |
| --- | --- | --- |
| Regional nuclear | `currency`, `country`, `timezone`, `language`, `date_format`, `time_format` | Columnas del perfil de negocio. El adaptador legado solo las lee desde el negocio; no mantiene otra copia activa en settings. |
| Identidad y presentación | `organization_name`, VAT/datos fiscales, direcciones, logo, contactos de emisor | Datos del negocio y sus relaciones propias, no de un cliente de `crm_organizations`. |
| Operación comercial | Prefijos, términos de cotización/pedido/factura/compra, instrucciones, `dynamic_products`, `show_related_activity` | Settings por negocio con claves admitidas y tipos. |
| Impuestos | `tax_name`, `tax_rate`, catálogo `crm_tax_rates` | Configuración revisada por el administrador del negocio; la IP no configura obligaciones fiscales. Mantener snapshots en documentos. |
| Integraciones | Credenciales/remitentes ClickSend, configuración Xero u otras | Por negocio, cifrado cuando corresponda, autorización y exclusión de respuestas/logs. Valores de infraestructura permanecen en config de plataforma. |
| Plataforma | `app_name`, `app_env`, `app_url`, `version`, `db_version`, `db_seeded_*`, `db_update_*`, estado técnico de instalación | Globales. No replicar el historial de migraciones por negocio ni permitir su edición a un administrador empresarial. |
| Preferencias de usuario | Settings con `user_id`, como avisos descartados | Mantener la semántica personal; no usarlos para alterar el perfil regional comercial. Incluir negocio si el aviso pertenece a uno. |

`SettingService` se convierte gradualmente en adaptador de compatibilidad. Una nueva autoridad de perfil empresarial resolverá los campos regionales; el acceso a flags de plataforma será explícito. Propuesta de precedencia: valor confirmado del negocio → default de alta durante `provisioning`; nunca perfil de otra empresa ni fallback global silencioso en operaciones comerciales activas.

Añadir unicidad por alcance después de conciliar filas: setting empresarial `(team_id, name)` cuando `user_id IS NULL`; preferencia `(team_id, user_id, name)` cuando aplique; setting de plataforma por nombre en el alcance nulo definido. Aquí `team_id` referencia al negocio según el mapeo del apartado 3.1. PostgreSQL trata los NULL de forma especial: usar índices parciales o la alternativa equivalente que se pruebe para PostgreSQL 16, no un índice compuesto ingenuo que siga admitiendo duplicados.

`global = true` significará configuración de plataforma o plantilla, no permiso para compartir marca y región de un negocio. No depender de una coincidencia entre el nombre lógico `settings` y `crm_settings` para determinar esa seguridad.

La UI separará “Mi negocio” y administración técnica de plataforma. Un tenant no recibirá un editor genérico que permita crear cualquier clave global. Guardar cambios empresariales requiere autorización específica y auditoría de actor, negocio y valores cambiados, excluyendo secretos.

## 10. Inventario de tablas y propiedad empresarial

Las siguientes familias cubren las **81 tablas `crm_` observadas**. Se reutilizan las columnas de pertenencia existentes y se añaden las faltantes mediante migraciones nuevas; tener `team_id` por sí solo no resuelve el aislamiento. En esta tabla `business_id` expresa la pertenencia lógica y se materializa como `team_id` en las tablas legadas que ya lo contienen, según el apartado 3.1.

| Familia | Tablas físicas actuales | Tratamiento previsto |
| --- | --- | --- |
| Documentos comerciales (7) | `crm_leads`, `crm_deals`, `crm_quotes`, `crm_orders`, `crm_invoices`, `crm_deliveries`, `crm_purchase_orders` | `team_id` empresarial obligatorio al terminar backfill. La asignación a un equipo interno, si se requiere, usa una relación adicional distinta. |
| Líneas (6) | `crm_deal_products`, `crm_quote_products`, `crm_order_products`, `crm_invoice_lines`, `crm_delivery_products`, `crm_purchase_order_lines` | Negocio coherente con documento, producto y variación; no confiar solo en filtro de cabecera. |
| Productos (3) | `crm_products`, `crm_product_variations`, `crm_product_prices` | Catálogo y precios privados del negocio; unicidades y monedas por empresa. |
| Clientes y contacto (7) | `crm_people`, `crm_organizations`, `crm_customers`, `crm_contacts`, `crm_addresses`, `crm_emails`, `crm_phones` | Privados del negocio; validar las dos puntas de cada relación y polimorfismo. |
| Actividad y adjuntos (7) | `crm_activities`, `crm_calls`, `crm_meetings`, `crm_lunches`, `crm_tasks`, `crm_notes`, `crm_files` | Negocio del padre; políticas de lectura, descarga, restauración y eventos. |
| Catálogos CRM editables (13) | `crm_labels`, `crm_lead_sources`, `crm_lead_statuses`, `crm_organization_types`, `crm_address_types`, `crm_contact_types`, `crm_industries`, `crm_tax_rates`, `crm_pipelines`, `crm_pipeline_stages`, `crm_pipeline_stage_probabilities`, `crm_product_categories`, `crm_feature_statuses` | Instancias por negocio. Mantener plantillas de provisión separadas y no editables por tenants. |
| Campos configurables (5) | `crm_field_groups`, `crm_field_models`, `crm_fields`, `crm_field_options`, `crm_field_values` | Definiciones y valores empresariales; revisar validación y claves dinámicas. |
| Marketing (8) | `crm_email_campaigns`, `crm_email_campaign_recipients`, `crm_email_campaign_clicks`, `crm_email_templates`, `crm_sms_campaigns`, `crm_sms_campaign_recipients`, `crm_sms_campaign_clicks`, `crm_sms_templates` | Empresa en campañas, destinatarios, plantillas y tracking; clicks también deben quedar delimitados aunque hoy no tengan `team_id`. |
| Chat (5) | `crm_chat_widgets`, `crm_chat_visitors`, `crm_chat_conversations`, `crm_chat_messages`, `crm_chat_visitor_page_views` | Widget y conversación determinan negocio; visitante y agente con capacidades distintas. |
| Portal de funcionalidades (4) | `crm_features`, `crm_feature_comments`, `crm_feature_votes`, `crm_feature_views` | Propiedad empresarial; acceso público por recurso publicado y reglas explícitas. |
| Monitores (2) | `crm_monitors`, `crm_monitor_checks` | Contexto empresarial en programación, ejecución, resultados y avisos. |
| Equipos (3) | `crm_teams`, `crm_team_user`, `crm_team_invitations` | Equipos internos del negocio; membresías y destinatarios validados. |
| Settings (1) | `crm_settings` | Clasificación de plataforma/negocio/usuario del apartado 9. |
| Zonas (1) | `crm_timezones` | Catálogo global existente; conservar IDs. |
| Uso (1) | `crm_usage_requests` | Registrar negocio cuando exista; tráfico anónimo de plataforma con alcance explícito. |
| Invitaciones de colaboradores (1) | `crm_user_invitations` | Negocio, rol válido, invitador, estado y expiración; aceptación sin escalación. |
| Mapeos Xero (5) | `crm_xero_contacts`, `crm_xero_invoices`, `crm_xero_items`, `crm_xero_people`, `crm_xero_purchase_orders` | Empresa e integración propietaria; nunca resolver tokens ajenos por un alcance de equipo ausente. |
| Pivotes (2) | `crm_contact_contact_type`, `crm_labelables` | Incorporar/reforzar negocio y consistencia de extremos; el pivote polimórfico requiere comprobación de dominio. |

Fuera de esas 81 tablas: `users` requiere la FK empresarial del diseño confirmado; `xero_tokens` requiere separación de integración por negocio; `devices` conserva identidad de usuario y acceso autorizado desde negocio. `sessions` y `password_reset_tokens` conservan su función de autenticación y no son almacenes de perfil regional.

**Sin cambios estructurales propuestos bajo las restricciones actuales:** `roles`, `permissions`, `model_has_roles`, `model_has_permissions`, `role_has_permissions`, `jobs`, `failed_jobs`, `job_batches`, `cache`, `cache_locks`, `migrations`. La evolución futura del apartado 5.2 no forma parte del contrato de implementación actual.

No basta actualizar modelos con `BelongsToTeams`: hay tablas sin trait o sin `team_id`, queries con `DB::table`, pivotes y accesos anónimos. El inventario físico es el punto de control para no omitirlas.

## 11. Contexto empresarial y garantías de integridad

### 11.1. Resolver el negocio antes de leer o escribir recursos

Introducir un `BusinessContext` registrado como `scoped`. La identidad y el contexto deben resolverse antes del binding de recursos y antes de cualquier lectura de settings empresariales. Las rutas de login, recuperación de contraseña y catálogos públicos tendrán contratos separados; no se les inyecta un negocio ficticio para poder iniciar sesión.

| Entrada | Resolución del contexto |
| --- | --- |
| Web autenticada | Negocio asociado a `users.business_id`, verificando estado empresarial y acceso del colaborador. No hay selector de empresa; un ID en sesión no sustituye esa relación. |
| API | Autenticación primero; derivar el negocio de la cuenta. Si se admite `X-Business-ID` o compatibilidad con el header de tenant legado `X-Team-ID`, debe coincidir con `users.business_id` y nunca permite cambiar de empresa. No reinterpretar ese header como equipo interno silenciosamente: usar una relación/parámetro explícito `crm_team_id` para los grupos y versionar el contrato si cambia. |
| Livewire | Mantener validación empresarial en cada acción, binding e hidratación. Un ID enviado como propiedad pública no establece contexto ni autoriza un cambio de empresa. |
| Documento público firmado | Resolver el propietario del recurso y verificar firma, expiración y acción permitida antes de presentar datos. El negocio del visitante o una sesión previa no decide la marca del documento. |
| Portal, tracking o widget | Recurso publicado, token o capacidad específica determina negocio y operación. La IP no participa en esa autorización. |
| CLI, scheduler y cola | Negocio explícito o iteración administrativa deliberada de negocios. Sin contexto no se consulta el CRM completo. |

Los modelos empresariales tendrán un alcance de negocio que falle de forma cerrada cuando falte contexto, y una asignación de su discriminador empresarial del lado servidor: `team_id` en el CRM legado o `business_id` en el dominio nuevo según el mapeo explícito. No se aplicará ciegamente al modelo base común porque `Timezone` y otros catálogos globales no son privados de un tenant. `User` necesita una consulta de pertenencia explícita para gestión de colaboradores, conservando su autenticación global.

Las excepciones para portales y operaciones de plataforma serán específicas, auditables y mínimas; no un `withoutGlobalScopes()` reutilizable por cualquier controlador. Quitar el scope de equipo nunca debe quitar el de negocio. Una operación global debe expresar su autoridad de plataforma, no heredarla del rol Owner de un negocio.

### 11.2. Relaciones y FKs

- Para relaciones empresariales directas, añadir la unicidad de referencia de discriminador e ID que PostgreSQL necesite y FKs compuestas. En el CRM legado, por ejemplo `(team_id, person_id)` → `crm_people(team_id, id)`. Una asignación interna opcional usaría `(team_id, crm_team_id)` → `crm_teams(team_id, id)`. Aquí el primer campo identifica al negocio y el segundo al recurso o equipo interno. Una FK solo sobre `person_id` permite apuntar a otra empresa aunque el UUID exista.
- Aplicar la misma integridad a documento/línea/producto/variación, pipeline/etapa, campaña/destinatario, monitor/check, invitación/rol y equipo/miembro. No asumir que una validación en el formulario protege importaciones, CLI o código de backend.
- No duplicar el discriminador empresarial en un pivote sin garantizar que concuerde con sus dos extremos. Para relaciones polimórficas no existe una FK normal a múltiples tablas: usar lista de tipos admitidos, validación de empresa del padre y pruebas negativas de cada operación. Mantener sus tipos y namespaces históricos.
- Las referencias a usuarios necesitan distinguir colaborador del negocio de actor externo autorizado del portal. Un voto público puede tener identidad externa; eso no le concede pertenencia como empleado. No convertir todas las relaciones `user_id` en membresías administrativas.
- Validar también `restore`, soft delete, clonación, conversión documental y sincronizaciones de etiquetas/relaciones. La integridad no termina en `store`.
- Índices empresariales según consultas reales: `(team_id, created_at, id)` en CRM legado o `(business_id, created_at, id)` en tablas nuevas, propietario/estado y relaciones consultadas. Revisar planes y evitar multiplicar índices sin necesidad.

Las garantías prioritarias son pertenencia obligatoria, integridad relacional y políticas. PostgreSQL RLS puede evaluarse como defensa adicional, pero requiere diseño del rol DB, cobertura de todas las tablas y limpieza de contexto por transacción/conexión en workers; no se presenta como un interruptor inmediato ni sustituye esas garantías.

## 12. Matriz de impacto en archivos PHP

Las rutas son relativas a la raíz del repositorio. Los archivos indicados existen en el checkout auditado salvo los marcados **nuevos/propuestos**. Una familia no implica reemplazo masivo de sus archivos; cada camino tendrá pruebas antes del cambio.

| Área y archivos | Cambio o revisión necesaria | Riesgo |
| --- | --- | --- |
| `app/Models/User.php`, `Role.php`, `Permission.php` | `User belongsTo Business`, clasificación y consulta de roles propios, protección de Owner y UUID. Mantener herencia directa de Spatie y autenticable único. | Crítico |
| **Nuevos/propuestos:** `app/Models/Business.php`, `Country.php`, `Currency.php` y relaciones propias | Perfil regional tipado, catálogos, administración mínima, factories y seeders útiles. Reutilizar `Models/Timezone.php` del módulo. | Alto |
| `bootstrap/app.php`, `app/Providers/AppServiceProvider.php`, `modules/crm/src/LaravelCrmServiceProvider.php` | Registro scoped del contexto/servicio; orden de middleware anterior al binding; política de proxies, locale y ciclo Octane; rutas de alta y perfiles. | Crítico |
| `config/laravel-crm.php`, `config/permission.php`, `config/app.php`, `config/octane.php` | Defaults de plataforma y transición; timezone del servidor UTC; mantener Spatie sin activar teams accidentalmente. No usar `.env` como configuración de cada negocio. | Alto |
| `Services/SettingService.php`, `Http/Middleware/Settings.php`, `Observers/SettingObserver.php`, `View/Composers/SettingsComposer.php` del módulo | Separar lecturas globales/empresariales, escritura inequívoca, cache por negocio, adaptador de claves antiguas y eliminación de setup empresarial durante navegación. | Crítico |
| `Http/Controllers/SettingController.php`, `Livewire/Settings/SettingEdit.php`, `Policies/SettingPolicy.php` | “Mi negocio”, validación contra catálogos, autorizaciones, formularios y rutas de logo. Cambiar labels traducidos sin renombrar claves técnicas. | Alto |
| `Traits/BelongsToTeams.php`, `Scopes/BelongsToTeamsScope.php`, **nuevos** trait/scope de negocio | Negocio siempre obligatorio para datos privados; equipo opcional subordinado. Eliminar dependencias de un `currentTeam` inexistente para establecer aislamiento. | Crítico |
| `Traits/HasCrmTeams.php`, `Models/Team.php`, `Observers/TeamObserver.php`, `Livewire/Teams/*`, `Support/TeamMembership.php` | Equipos internos y miembros de la misma empresa; no clonar settings regionales ni roles empresariales por crear un equipo; acceso cerrado si falta contrato de pertenencia. | Crítico |
| `Http/Middleware/SetApiTeamContext.php`, `TeamsPermission.php`, `HasCrmAccess.php` | Contextos separados de negocio y equipo; retirar elevación por email global del CRM como sustituto de Owner empresarial. | Crítico |
| `Http/Rules/Api/V2/ScopedExists.php`, `OwnerInCurrentTeam.php`, `Http/Requests/Api/V2/*` | Reglas de UUID/existencia/propietario por negocio y, cuando corresponda, equipo; moneda habilitada y zona válida. Rechazar discriminadores empresariales arbitrarios, tanto `team_id` legado como `business_id`. | Crítico |
| `Http/Controllers/Api/V2/*`, `Http/Resources/Api/V2/*`, `Http/api-routes.php` | Listados/bindings/acciones y serialización por empresa; contrato de región empresarial; importes por moneda y respuestas sin secretos de settings. | Crítico |
| `Http/Controllers/Api/V2/AuthController.php`, `Console/Commands/IssueApiToken.php` | Cerrar el prerrequisito Sanctum detectado y luego validar pertenencia/estado del negocio. Token válido no permite elegir una empresa ajena. | Alto |
| `Http/Controllers/RoleController.php`, `Policies/RolePolicy.php`, `Livewire/Settings/Permissions/*`, requests de roles | Catálogo de permisos global de lectura y roles mutables propios; nombres visibles, asignación, edición y revocación empresarial. | Crítico |
| `Livewire/Users/UserIndex.php`, `UserShow.php`, `UserInvite.php`, `UserImport.php`, `Models/UserInvitation.php` y sus consumidores | Buscar y gestionar colaboradores solo del negocio; invitaciones y aceptación transaccionales; impedir eliminar/suspender/degradar al último administrador. | Crítico |
| `Console/LaravelCrmAddUser.php`, `LaravelCrmPermissions.php`, seeders de permisos | Negocio explícito para altas/asignaciones; plantillas y roles efectivos separados; no repartir permisos globales al tenant. Preservar cambios locales existentes. | Alto |
| `Models/Product.php`, `ProductVariation.php`, `ProductPrice.php`, `Services/ProductService.php` | Resolver precios por negocio/moneda/variación; CRUD multimoneda e integridad de filas; quitar fallback entre monedas. | Crítico |
| `Support/Money.php`, `Livewire/ModelProducts.php`, `Livewire/Products/Traits/HasProductCommon.php` | Escala por moneda, parser decimal localizado/canónico, redondeo, cantidades fraccionarias y ausencia de precio explícita. | Crítico |
| `Services/LeadService.php`, `DealService.php`, `QuoteService.php`, `OrderService.php`, `InvoiceService.php`, `PurchaseOrderService.php`, `DeliveryService.php` | Contexto y referencias de negocio, moneda explícita, conversiones documentales, cálculo y snapshots. Revisar traits Livewire y mutators/observers correspondientes. | Crítico |
| `Services/NumberGeneratorService.php` y observers de documentos | Numeración por negocio/tipo/serie, reserva atómica y unicidad en DB; cache y reset delimitados. | Alto |
| `Livewire/Dashboard.php`, repositorios, filtros y exportaciones | Queries empresariales, rangos temporales de negocio y agrupación de importes por moneda. Auditar `DB::table`, joins y sumatorias. | Crítico |
| `Services/EmailCampaignService.php`, `SmsCampaignService.php`, `ClickSendService.php`, `Console/LaravelCrmReminders.php` | Región, destinatarios, remitentes y credenciales del negocio; programación en UTC; corrección del acceso escalar a timezone. | Alto |
| `Console/LaravelCrmEmailCampaignsDispatch.php`, `LaravelCrmSmsCampaignsDispatch.php`, `LaravelCrmMonitorCheck.php`, `LaravelCrmArchive.php` | Iteración explícita de negocios y aislamiento de lotes. No hacer queries globales por ausencia de sesión. | Crítico |
| `Jobs/SendEmailCampaignRecipient.php`, `SendSmsCampaignRecipient.php`, `MaterialiseSmsCampaignRecipients.php`, `RunMonitorCheck.php`, `SendImportPasswordReset.php` | Impacto de contexto, rehidratación de modelos, callbacks de fallo y credenciales. Conservar clases/FQCN y restricciones; ver apartado 13 antes de decidir modificaciones. | Crítico |
| `Support/PortalTeam.php`, `Services/PortalAuthService.php`, `Http/Controllers/Portal/*`, `Http/portal-routes.php` | Contexto de negocio del recurso, marca/PDF, firmas, tokens de tracking y registro de clientes externos separado del alta de negocio. | Crítico |
| `Support/PdfContactDetails.php`, `PdfLogo.php`, `PdfSampleData.php` | Emisor y moneda del documento/negocio correctos; muestras traducidas sin contaminar datos reales; reproducción histórica definida. | Alto |
| `Livewire/Files/FileRelated.php`, `FileItem.php`, `Livewire/Settings/SettingEdit.php`, modelo/policy de archivos | Rutas por negocio, nombres controlados, descargas privadas autorizadas y transición de archivos históricos. | Alto |
| `Events/ChatMessageSent.php`, `Services/ChatService.php`, `routes/channels.php`, widget y consumidores Reverb | Identidad UUID sin cast a entero, canales autorizados por negocio/recurso y capacidad del visitante. No publicar conversación privada solo por conocer el UUID. | Crítico |
| `Models/*`, `Policies/*`, `Observers/*`, `Repositories/*`, `Support/FormOptions.php` | Cobertura transversal de consultas, stamping empresarial, relaciones, selects, búsqueda, borrados/restauraciones y acciones masivas. | Crítico |
| `database/seeders/DatabaseSeeder.php`, `modules/crm/database/seeders/*`, `Console/LaravelCrmInstall.php`, `LaravelCrmUpdate.php` | Bootstrap de plataforma separado de provisión de negocio; migrar solo una vez, seed por negocio idempotente y sin sobrescribir configuración confirmada. | Alto |

Además de los cambios PHP habrá ajustes puntuales de vistas Blade/Livewire y traducciones. No requieren migrar la interfaz a React ni incorporar un framework nuevo.

## 13. Workers, cache, integraciones, archivos y acceso público

### 13.1. Octane y colas

Aplicar las guías de Octane porque el proceso sirve múltiples requests. `BusinessContext` y perfil resuelto serán por ciclo; no capturar usuario, negocio o request en singleton/static. Separar las claves de cache por negocio y versión de perfil, y comprobar su limpieza también tras excepciones. El actual binding scoped de settings se conserva como fundamento, no se reemplaza por un singleton.

Los callbacks del scheduler no disponen de usuario autenticado. Deben enumerar negocios autorizados para la tarea y abrir/cerrar contexto para cada uno. Los jobs necesitan una procedencia empresarial fiable aunque se ejecuten mucho después o el usuario original cambie de estado.

La restricción previa incluía no modificar jobs. Por tanto, **este plan no da por autorizada una edición de `Jobs/*`**. Evaluar primero propagación de contexto mediante la infraestructura de despacho/middleware y el ciclo de cola de Laravel, manteniendo sus clases y payloads compatibles. Verificar el orden real de restauración de contexto y deserialización de modelos: `SerializesModels` no sustituye autorización empresarial y la primera consulta puede ocurrir antes de `handle()`.

Si ese aislamiento no puede garantizarse sin adaptar una clase job, registrar la necesidad y solicitar una ampliación concreta del alcance antes de implementarla. Mientras tanto, no habilitar esa funcionalidad para múltiples negocios. No resolverlo añadiendo columnas empresariales a `jobs`, `failed_jobs` o `job_batches`, ni cambiando sus PK.

Las operaciones de fallo/reintento también deben recuperar contexto. Programar envíos después del commit del alta/documento/campaña y comprobar idempotencia por negocio y destinatario.

### 13.2. Cache, numeración y secretos

- Settings, formularios y reportes: clave con negocio y versión de configuración; no particionar únicamente por equipo o usuario.
- Numeración: migrar al contador empresarial de DB con bloqueo transaccional y restricción de unicidad documental. Los números de documentos eliminados lógicamente no se reutilizan. La cache no es la única garantía.
- Spatie: invalidar las plantillas/asignaciones afectadas y limpiar relaciones cargadas. Mantener las asignaciones normales del usuario y validar su pertenencia empresarial antes de asignar cada rol. Una cache global válida no elimina la obligación de validar negocio del rol y recurso.
- Xero/ClickSend/remitentes: resolver desde la empresa dueña del documento/campaña, incluso sin sesión; cifrar secretos de tenant y evitar serializarlos en respuestas o payloads innecesarios.
- `SystemCheckService`, versión y flags de migración: siguen siendo estado técnico de plataforma, separados de la cache de cada perfil empresarial.

### 13.3. Portales, chat y archivos

Las URLs firmadas y tokens de tracking existentes deben seguir encontrando el documento; conservar `external_id` y firmas cuando sea posible. El localizador público puede obtener el mínimo necesario para verificar capacidad y determinar empresa, pero no debe renderizar relaciones o settings antes de esa verificación.

El registro de `PortalAuthController`/`PortalAuthService` crea identidades externas con acceso CRM deshabilitado. No convertir esa ruta en alta de empresa ni otorgarle automáticamente Admin por detectar un país. El alta SaaS será una operación de dominio distinta.

Los agentes y visitantes de chat tienen autorizaciones diferentes. Migrar conversaciones sensibles a canales cuya suscripción compruebe empresa/pertenencia del agente o capacidad específica del visitante, manteniendo funcional el widget anónimo autorizado. El nombre de canal no es la autorización. [Laravel: autorización de canales](https://laravel.com/docs/13.x/broadcasting#authorizing-channels).

Prefijar nuevos archivos con `businesses/{business_id}/...`; usar nombres generados, acceso privado a documentos y descarga autorizada. El logo público, si se mantiene público deliberadamente, tendrá ruta propia del negocio. Conservar la resolución de rutas históricas mientras se copian/verifican archivos; no mover/borrar masivamente el almacenamiento de trabajo.

### 13.4. Comunicación externa heredada

`Http/Middleware/Settings.php` aún realiza un POST de versión a `api.laravelcrm.com` con datos de instalación e IPs. Es una llamada runtime heredada, distinta de una dependencia Composer y distinta de geolocalización. No reutilizarla para detectar región ni replicarla por negocio. Antes del despliegue SaaS, retirar/desactivar esa comunicación heredada o definir expresamente su necesidad y datos permitidos como capacidad de plataforma; el setup de una empresa no dependerá de ella.

## 14. Migraciones propuestas y estrategia de datos

No se editarán migraciones ya publicadas ni se repetirá la conversión UUID. Crear nuevas migraciones con Artisan al implementar; las siguientes son **unidades de trabajo propuestas**, no archivos ya generados.

| Orden | Unidad | Condición y efecto |
| --- | --- | --- |
| M01 | Catálogos regionales y relaciones por país | Crear tablas UUID, códigos únicos, normalizar/reutilizar zonas sin cambiar IDs y cargar datos versionados e idempotentes. |
| M02 | Negocios y perfil regional | Crear `businesses`, monedas habilitadas y estado de provisión; FKs a catálogos. Resolver la referencia circular con el primer usuario mediante estado inicial y constraints posteriores. |
| M03 | Pertenencia y roles empresariales | Añadir `users.business_id` nullable durante backfill y tablas propias de mapeo de roles. Cerrar pertenencia obligatoria para colaboradores al finalizar; no crear membresía N:M ni alterar tablas internas Spatie. |
| M04 | Integrar pertenencia en familias CRM | Reutilizar `team_id`, conciliar su contenido y añadir FKs/índices; agregar discriminador solo en tablas privadas sin pertenencia suficiente. Trabajar por grupos manejables con tiempos de bloqueo evaluados. |
| M05 | Separación de settings | Reutilizar su `team_id` como alcance empresarial y crear adapter de compatibilidad; clasificación y mapeo de datos mediante comando independiente observable. |
| M06 | Numeración y moneda | Contadores por negocio; unicidades y escala/rango monetario según auditoría. Preservar importes existentes sin reinterpretar sus unidades. |
| M07 | Integridad relacional | Conciliar datos y añadir unicidades de referencia/FKs compuestas. En tablas grandes evaluar validación por etapas para no bloquear operaciones innecesariamente. |
| M08 | Cierre de transición | Hacer obligatoria pertenencia en tablas privadas, checks y unicidades de settings/precios; impedir el camino legado sin negocio. Retirar lectura global comercial. |

La definición de cada migración debe contemplar también instalación limpia. El proveedor ya registra las actualizaciones del módulo: cada migración tendrá una única ubicación de registro, sin copiar el mismo cambio a host y módulo.

### 14.1. Backfill incremental

1. Capturar commit, checksums de migraciones históricas, respaldo probado, conteos por tabla y estadísticas de NULL/huérfanos/duplicados/monedas; identificar la base con `current_database()` antes de cualquier escritura.
2. Determinar propietarios reales. En una instalación mononegocio revisada, crear el negocio legado y asignar sus filas explícitamente. Con varios tenants históricos, preparar un mapeo verificable por equipo/relaciones; no asignar todos los NULL a la primera empresa.
3. Resolver manualmente casos ambiguos y cuentas externas de portal. El administrador inicial será una cuenta elegida por el operador, no `test@example.com`, un email de entorno o una credencial predeterminada.
4. Copiar país/moneda/zona/formato/identidad desde la configuración efectiva existente del negocio. Normalizar país mostrado como texto a código ISO con un mapeo revisado; no confiar en traducción aproximada o IP del operador que ejecuta CLI.
5. Migrar raíces y luego hijos/pivotes por relaciones verificadas, manteniendo IDs, `external_id`, FKs, tipos polimórficos, números y timestamps. Registrar anomalías; no repararlas inventando datos.
6. Instanciar catálogos editables por negocio. Los UUID deterministas del seeder legado son adecuados para su conjunto histórico global, pero no pueden reutilizarse como PK idéntica para dos empresas dentro de la misma tabla. Para nuevos negocios usar UUID nuevos con clave estable de plantilla o una generación determinista que incluya negocio. Mantener un mapa y reescribir solo las referencias de la copia empresarial nueva.
7. Reconciliar conteos, pertenencia de cada relación y totales agrupados por moneda antes/después. No multiplicar ni dividir todos los históricos durante la corrección monetaria: respetar la escala con la que ya están guardados y aislar cualquier excepción documentada.
8. Procesar por lotes reanudables con reporte, modo de diagnóstico y opción de negocio explícita. El backfill no correrá en middleware web, al instalar Composer ni dentro de un `docker compose up` de forma opaca.
9. Activar constraints y lectura empresarial solo tras cobertura completa. La nulabilidad temporal durante la migración no autoriza a mostrar filas sin negocio a todos los tenants.

Durante transición, la compatibilidad comercial global solo podrá servir al **único negocio legado identificado**. No usar `team_id IS NULL OR team_id = actual` en CRM legado, ni su equivalente con `business_id`, como filtro general de todos los negocios: compartiría datos históricos. Abrir el segundo negocio después del corte seguro, no durante esa compatibilidad global.

### 14.2. Reversión

Antes de admitir un segundo negocio, el modo legado controlado puede utilizarse como transición si se valida su compatibilidad. Después de crear datos de varias empresas, **volver al código anterior con consultas globales sería una fuga de datos**. El rollback de aplicación debe conservar la barrera empresarial o poner el servicio en mantenimiento.

No proponer `down()` que elimine empresas, membresías, columnas pobladas o precios como rollback normal de producción. Mantener esquema aditivo y corregir hacia delante; restaurar un respaldo solo con procedimiento operativo revisado que contemple datos posteriores, archivos y colas. Probar restauración en un entorno desechable antes del despliegue real.

## 15. Seeders y alta de negocio

Separar los dos procesos:

- **Bootstrap de plataforma:** países, monedas, zonas, relaciones regionales, permisos y plantillas CRM, flags técnicos de migración. No crea empresas activas ni usuarios ficticios y no modifica perfiles existentes.
- **Provisión de negocio:** perfil confirmado, primer administrador, roles propios, monedas habilitadas, settings y catálogos CRM editables. Genera IDs propios y valida relaciones. Crear un equipo interno es opcional.

La provisión debe usar una operación de dominio reutilizable desde HTTP y CLI, con transacción, validación y clave de idempotencia para evitar dos empresas ante un reintento. Las consultas a GeoIP se realizan antes o fuera de la transacción larga; el resultado es una sugerencia validada, no una escritura externa dentro del alta.

Un reintento no crea otro negocio/administrador, no duplica roles, precios o etapas y no revierte una configuración confirmada. Los envíos de verificación/invitación se disparan después del commit y se prueban con fakes.

`laravelcrm:install` y `laravelcrm:update` siguen aplicando esquema global una vez y bootstrap de plataforma idempotente. No deben resetear perfiles de todos los clientes en cada arranque. Una actualización de plantillas se aplica a nuevos negocios o mediante una operación explícita que preserve personalizaciones de los existentes.

## 16. Fases de implementación y puertas de salida

| Fase / ticket propuesto | Resultado revisable | Dependencias / puerta |
| --- | --- | --- |
| QAT-01.0 — Contratos y caracterización | Aplicar la pertenencia única confirmada; definir staff frente a portal, catálogo de monedas operables, capacidad de GeoIP y restricciones sobre jobs/Spatie. Añadir pruebas del comportamiento que debe conservarse. | Antes de implementar pertenencia y permisos. |
| QAT-01.1 — Riesgos previos | Casos negativos de UUID/canales, precio de moneda incorrecta, agregaciones y contexto ausente; resolver prerrequisito de token API si forma parte de la entrega. | No exponer varios negocios con esos caminos abiertos. |
| QAT-01.2 — Catálogos y Business | Migraciones aditivas, modelos/factories/seeders, perfil y provisión transaccional del primer admin. | Pruebas de FK, repetición y fallo de alta. |
| QAT-01.3 — Datos y autorización | Discriminador empresarial reutilizado o añadido donde falte, mapeos/backfill, roles empresariales, equipos internos y consultas cerradas. | Cero relaciones cruzadas y barrera web/API/Livewire. |
| QAT-01.4 — Perfil regional | Adaptador settings, UI “Mi negocio”, traducciones, cache por negocio y GeoIP como sugerencia confirmable. | Cambiar A no cambia B; funcionar sin proveedor GeoIP. |
| QAT-01.5 — Monedas y calendario | Escalas monetarias, precios exactos, agregaciones separadas, documentos, intervalos temporales y recordatorios/campañas. | PEN/USD/JPY/KWD y casos DST correctos. |
| QAT-01.6 — Superficies externas y procesos | Portales, tracking, archivos, chat, integraciones, CLI, scheduler y cola en contexto empresarial. | Despacho/reintento/fallo y worker reutilizado no cruzan empresas. Resolver antes cualquier ampliación necesaria sobre jobs. |
| QAT-01.7 — Corte y despliegue | Backfill ensayado, constraints finales, clonación/build/instalación limpia, restauración probada y monitoreo. | Abrir segundo negocio solo con todo el aislamiento validado. |

El impacto es **alto y transversal**. “Mover settings” es una parte de la entrega; separar esos cambios del aislamiento completo permite revisarlos, pero no vender cada fase intermedia como SaaS multiempresa terminado. La pertenencia única ya está confirmada; una estimación de entrega aún requiere medir datos/archivos/colas reales y cerrar los demás contratos operativos.

## 17. Pruebas y criterios de aceptación

Ejecutar pruebas funcionales en PostgreSQL 16 desechable. Verificar host, base y conexión efectiva antes de usar traits de recreación; la base `back_qaturia` de trabajo no es la base de pruebas. Las variables heredadas por Docker deben quedar sobrescritas tanto en el entorno como en los valores `server` de PHPUnit cuando corresponda.

La suite existente (`CrmBootstrapSeederTest`, `UuidPrimaryKeysTest`, `CrmUuidFlowsTest`, `CrmFrontendAssetsTest`, `CrmTrackingRoutesTest`) es un punto de partida, no evidencia de que estas funcionalidades nuevas ya existan.

| Área | Prueba de aceptación necesaria |
| --- | --- |
| UUID / esquema | Instalación limpia y actualización de una copia con datos existentes; IDs y namespaces intactos; nuevas PK UUID; job tables y estructura Spatie sin cambios bajo el contrato confirmado. |
| Propiedad | Negocios A/B con datos similares; CRUD, búsqueda, selects, exports, restauración, pivotes y polimorfismos nunca muestran/aceptan datos ajenos. Incluir queries directos/importaciones. |
| DB | INSERT/UPDATE que relacionen negocio A con persona, equipo, producto, variación, etapa o documento de B fallan en las relaciones protegidas por FK. Polimorfismos rechazados por dominio. |
| Contexto ausente | Usuario sin pertenencia, negocio suspendido, header alterado, sesión de otro negocio y job sin contexto no obtienen acceso global. |
| Roles | Admin A no administra usuarios/settings/roles de B. Personalizar un rol de A no modifica B. Una cuenta no puede elegir otra empresa mediante header, sesión o propiedad Livewire ni recibir roles efectivos ajenos. |
| Administrador mínimo | No se puede quitar el último administrador por borrado, suspensión, cambio de rol o concurrencia. Falla de provisión no deja empresa activa sin admin ni identidades huérfanas. |
| Settings | Actualización A no altera B; preferencias personales y flags globales no se pisan; bootstrap/update dos veces conserva perfiles, IDs y personalizaciones. |
| Geolocalización | IP pública, privada, VPN, timeout, respuesta inválida, país multizona, múltiples monedas y headers falsificados. Invitación desde otro país no cambia negocio. No hay llamadas reales en tests. |
| Monedas | PEN/USD/JPY/KWD, entrada decimal canónica y localizada, redondeo y máximos. Precio ausente no toma otra moneda; una línea no mezcla moneda con cabecera. |
| Historia | Cambio de moneda/zona/idioma del negocio no cambia importes, moneda ni programación UTC ya confirmados. Verificar PDF/documento histórico según su snapshot. |
| Reportes | Valores de monedas distintas quedan separados y cada total incluye únicamente su negocio. La conversión de leads no se trata como tipo de cambio. |
| Tiempo | Límites de “hoy” diferentes entre negocios, fechas civiles, horarios inexistentes/ambiguos por DST, campañas y recordatorios en la zona correcta. |
| Workers | Mismo worker atiende A → B → sin contexto, incluso tras excepción, sin conservar perfil ni permisos. Rehidratación y reintentos de jobs con empresa correcta. |
| Portales / archivos | Firma vencida o token ajeno rechazados; un visitante autenticado en A abre documento autorizado de B con marca de B y sin acceso adicional. Archivos/logos no colisionan. |
| Broadcasting | UUID distintos nunca se igualan por cast numérico; agente ajeno y visitante sin capacidad no se suscriben a conversación privada. |
| API | Token funcional y negocio válido; todas las entradas de IDs, monedas, propietarios y relaciones se validan. Recursos no filtran secretos ni IDs de negocios ajenos. |
| Navegador real | Dos sesiones independientes de empresas distintas, moneda/zona/idioma correctos, ajustes, formulario de producto/documento y navegación Livewire sin estilos/estado cruzados. |
| Operación | Build Docker, health, queue/scheduler/Reverb, instalación desde clon limpio y actualización por duplicado. Ensayo de restauración y rechazo de rollback inseguro al código global. |

Durante implementación: usar Pest/factories/fakes disponibles, ejecutar primero pruebas focales, después regresiones relevantes y Pint/análisis estático para PHP modificado. No añadir dependencias de testing ni ejecutar pruebas destructivas en una base real por comodidad.

## 18. Decisión confirmada y contratos restantes antes de codificar

1. **Pertenencia confirmada:** cada cuenta colaboradora pertenece inicialmente a un solo negocio mediante `users.business_id`; un negocio admite varios usuarios. No se implementan membresía N:M ni cambio de negocio. La configuración regional sigue perteneciendo al negocio. Para el CRM legado se recomienda reutilizar `team_id` como FK física hacia el negocio, con el mapeo del apartado 3.1 y sin duplicar columnas.
2. **Spatie y jobs:** conservar las restricciones vigentes. Si se elige una solución que requiere excepciones, especificar archivos/tablas, motivación y pruebas antes de ampliar alcance.
3. **País del negocio:** confirmación comercial/fiscal del administrador; GeoIP como sugerencia de alta. La residencia de un colaborador no redefine la empresa.
4. **Monedas:** entregar multimoneda documental con totales separados; la consolidación FX requiere otro alcance. Habilitación internacional sujeta a escala monetaria correcta, no únicamente a tener filas en el catálogo.
5. **Catálogos editables:** plantillas globales e instancias propias por negocio. No compartir impuestos, etiquetas o pipelines mutables sin una regla expresa.
6. **Altas:** distinguir registro SaaS del primer administrador, invitación de colaborador y registro externo del portal. Definir si el email permanece único globalmente; se propone conservarlo mientras no se diseñe autenticación por dominio/negocio.
7. **Históricos:** autoridad de cada fila legada, semántica de timestamps y formato reproducible de documentos emitidos. Los casos ambiguos se resuelven antes del corte.
8. **GeoIP:** proveedor, credenciales/licencia y actualización de datos si se necesita. No se instala ni contrata un proveedor en esta revisión.

## 19. Qué se validó en esta revisión

- Lectura del plan previo de internalización y del checkout actual; confirmación de versiones instaladas.
- Inspección del esquema PostgreSQL real, tipos UUID, índices, restricciones y conteos agregados, sin leer contraseñas ni publicar datos personales.
- Recorrido estático de modelos, services, controllers, middleware, requests, policies, observers, seeders, Livewire, portales, jobs, eventos y configuración de workers.
- Consulta de documentación oficial de Laravel 13, Octane, Spatie v6, IANA y limitaciones GeoIP para fundamentar las decisiones.
- Creación únicamente de este documento. El cambio local preexistente en `modules/crm/src/Console/LaravelCrmPermissions.php` se conserva.

No se ejecutaron migraciones, seeders, altas, envíos reales ni pruebas funcionales nuevas para este análisis documental. No se declara implementado el aislamiento, la API por tokens, la geolocalización ni la corrección multimoneda. La lista de pruebas del apartado 17 es una puerta de aceptación futura.

## 20. Referencias de código para iniciar la implementación

- [Plan previo de internalización](../../PLAN_CODEX_INTEGRACION_CRM_UUID_V2.md).
- [Modelo User](../../app/Models/User.php), [Role](../../app/Models/Role.php), [Permission](../../app/Models/Permission.php).
- [Servicio actual de settings](../../modules/crm/src/Services/SettingService.php), [seeder de defaults](../../modules/crm/database/seeders/LaravelCrmSettingsSeeder.php), [formulario de ajustes](../../modules/crm/src/Livewire/Settings/SettingEdit.php).
- [Scope de equipos](../../modules/crm/src/Scopes/BelongsToTeamsScope.php), [contrato de equipos del usuario](../../modules/crm/src/Traits/HasCrmTeams.php), [comprobación de membresía](../../modules/crm/src/Support/TeamMembership.php).
- [Validación API por equipo](../../modules/crm/src/Http/Rules/Api/V2/ScopedExists.php), [propietario de registro](../../modules/crm/src/Http/Rules/Api/V2/OwnerInCurrentTeam.php), [contexto API](../../modules/crm/src/Http/Middleware/SetApiTeamContext.php).
- [Precios de producto](../../modules/crm/src/Models/Product.php), [normalización monetaria](../../modules/crm/src/Support/Money.php), [líneas Livewire](../../modules/crm/src/Livewire/ModelProducts.php), [dashboard](../../modules/crm/src/Livewire/Dashboard.php).
- [Numeración](../../modules/crm/src/Services/NumberGeneratorService.php), [recordatorios](../../modules/crm/src/Console/LaravelCrmReminders.php), [campañas de email](../../modules/crm/src/Services/EmailCampaignService.php).
- [Portal y contexto del documento](../../modules/crm/src/Support/PortalTeam.php), [autenticación de visitantes](../../modules/crm/src/Services/PortalAuthService.php), [registro externo](../../modules/crm/src/Http/Controllers/Portal/PortalAuthController.php).
- [Canal del usuario](../../routes/channels.php), [evento de chat](../../modules/crm/src/Events/ChatMessageSent.php), [configuración Octane](../../config/octane.php), [servicios Docker](../../compose.yaml).

## 21. Comparación de reutilización y recomendación de menor impacto

| Alternativa | Qué permite reutilizar | Coste o limitación | Evaluación |
| --- | --- | --- | --- |
| Convertir `crm_organizations` en raíz SaaS | Datos corporativos y relaciones de contacto existentes. | Mezcla al contratante con las organizaciones comerciales de sus clientes; exige separar tipos, propiedad y relaciones documentales, y corregir interfaces de contactos. | No recomendada. |
| Convertir `crm_teams` en negocios | Nombre, creador, miembros e invitaciones. | Deja pendiente dónde representar los equipos internos requeridos. Una jerarquía negocio/equipo en la misma tabla exige tipos y reglas nuevas en scope, invitaciones, roles y UI. La columna `crm_teams.team_id` ya representa un contexto externo, no una jerarquía validada de equipos. | Técnicamente posible, pero no es una reutilización directa ni el contrato elegido para los equipos internos. |
| Crear `businesses` y reutilizar el `team_id` empresarial legado | Las 75 columnas UUID existentes; `crm_teams` y su pivote como grupos internos; `crm_organizations` como contactos comerciales. | Hay que declarar el mapeo físico, crear la raíz y sus FKs, cerrar los scopes y revisar contexto/roles/settings. | Recomendación revisada para minimizar cambios de esquema sin mezclar conceptos. |

No todas las tablas necesitan otro campo. En particular, `crm_teams.team_id` puede apuntar a `businesses.id`, mientras que `crm_team_user.crm_team_id` continúa apuntando a `crm_teams.id`. Las asignaciones internas de documentos a equipos, si el producto las necesita, tendrán una relación específica; la pertenencia de usuarios a grupos ya puede reutilizar el pivote existente.

La reducción es principalmente de migraciones y renombrados. Permanecen obligatorias la raíz empresarial, la pertenencia única del colaborador, las comprobaciones de administrador mínimo, los filtros que fallen cerrados, los settings/cache por negocio y la integridad de relaciones. No activar `LARAVEL_CRM_TEAMS=true` para intentar obtenerlos: esa variable también activa el modo teams de Spatie y consumidores del contrato Jetstream que el `User` actual no implementa.

Esta revisión amplía el análisis y actualiza su recomendación física; no implementa el mapeo ni afirma que el backend ya aísle empresas correctamente.

## 22. Verificación complementaria: `team_id` frente a `crm_team_id` y despliegue por país

### 22.1. Alcance regional actualizado

La decisión vigente es conservar el esquema de settings existente y desplegar instancias por país para escalarlas de forma independiente. Las propuestas anteriores de nuevos catálogos regionales, GeoIP en el alta y columnas regionales en Business/usuarios son alternativas analizadas, **no tareas autorizadas para la siguiente implementación**. En particular, se debe ajustar el alcance regional de M01/M02 y QAT-01.4 antes de ejecutarlos.

El seeder actual crea defaults de instancia en `crm_settings` con `team_id = null`, `user_id = null` y `global = true`. Se mantiene esa estructura. Para varios negocios dentro de una instancia, identidad, marca y datos privados no pueden quedar compartidos por accidente: podrán delimitarse con el alcance empresarial existente de la misma tabla, sin que esto obligue a crear columnas regionales en Business.

Una instancia por país separa despliegues/regiones. No separa entre sí los negocios que compartan esa instancia y base. También conserva un único valor regional de instancia mientras no se amplíe expresamente ese contrato.

### 22.2. Resultado del esquema y runtime efectivos

Se volvió a verificar `current_database() = back_qaturia`, PostgreSQL 16.15 y el checkout `4bc158f73d3349e818f7a31401f466662bb179cd`.

| Comprobación | Resultado observado |
| --- | --- |
| `php artisan config:show laravel-crm.teams` dentro de `app` | `false`. |
| `php artisan config:show permission.teams` dentro de `app` | `false`. |
| Columnas `team_id` del esquema público | 75, UUID y nullable. |
| FKs cuyo origen es `team_id` | 0. No hay raíz de negocio validada por esas FKs. |
| Tablas raíz `teams` o `businesses` | Ninguna de las dos existe. Sí existe `crm_teams`. |
| `crm_team_user.crm_team_id` | FK a `crm_teams.id`. |
| `crm_team_invitations.crm_team_id` | FK a `crm_teams.id`. |
| `users.current_crm_team_id` | FK a `crm_teams.id`. |
| Settings de esta instantánea | 43 filas globales sin equipo ni usuario. Valores regionales leídos: USD, United States, UTC, english. |

El servicio `workspace` estaba detenido; la configuración efectiva se leyó desde el contenedor `app`, que estaba activo y saludable. No se inició ni recreó ningún servicio para esta verificación.

### 22.3. Semántica demostrada en PHP

`HasCrmTeams::crmTeams()` y `Team::users()` vinculan usuarios con `crm_teams` mediante `crm_team_user.crm_team_id`. Eso representa agrupación interna. El modo de cuentas externas está documentado separadamente en `config/laravel-crm.php` y usa `auth()->user()->currentTeam->id` para comparar con `team_id`.

El `User` actual no incorpora el contrato Jetstream `allTeams()`/`switchTeam()` ni define `currentTeam` como relación o accessor. Su anotación PHPDoc no implementa esa relación. Tener `current_crm_team_id` o pertenecer a `crmTeams()` no llena automáticamente `currentTeam`.

`BelongsToTeamsScope::apply()` añade el filtro únicamente cuando están activados teams, existe usuario autenticado y ese usuario tiene `currentTeam`. Si falta una condición, la consulta conserva el alcance normal del modelo, pero no queda delimitada por tenant. Existen además excepciones deliberadas para determinados contextos públicos/Nova, por lo que este scope no puede presentarse como una barrera universal de negocio.

Los contextos de autorización Spatie y API tampoco convierten automáticamente equipos internos en empresas. `SetApiTeamContext` consulta `allTeams()`, no `crmTeams()`; `TeamMembership` conserva un fallback permisivo cuando no existe ese contrato. El esquema Spatie observado no contiene sus columnas de teams.

### 22.4. Caracterización ejecutada sin tocar PostgreSQL

Se ejecutaron seis pruebas temporales con Pest, fuera del código del proyecto, sobre SQLite `:memory:`. Antes de crear las tablas sintéticas se verificaron conexión, nombre de base y driver PDO. Se usaron dos productos etiquetados con tenants diferentes y un ID de equipo interno distinto; no se ejecutaron migraciones del backend ni se guardaron usuarios reales.

| Caso reproducido | Resultado real del código existente |
| --- | --- |
| Teams desactivado, usuario con `currentTeam` A simulado | Consulta devuelve productos A y B. |
| Teams activado, sin usuario autenticado | Consulta devuelve productos A y B. |
| Teams activado, usuario con equipo interno y `current_crm_team_id`, sin `currentTeam` | Consulta devuelve productos A y B. |
| Teams activado, `currentTeam` externo A proporcionado explícitamente y equipo interno distinto | Consulta devuelve únicamente producto A; el filtro utiliza A, no el ID interno. |
| Comprobación de otro usuario sin `allTeams()` | `TeamMembership::inCurrentTeam()` devuelve `true` por fallback, sin demostrar pertenencia. |
| API con `X-Team-ID` del equipo interno presente en `crmTeams()` | Devuelve 403: esa pertenencia no satisface `allTeams()`. |

Resultado del runner: **6 pruebas, 30 aserciones aprobadas**. Son pruebas de caracterización que reproducen las limitaciones: no son una certificación de aislamiento correcto. No cubren toda la navegación, todas las tablas ni un despliegue SaaS terminado.

### 22.5. Conclusión validada y efecto sobre Business

- `team_id` es el discriminador diseñado para la cuenta externa del host y puede reutilizarse conceptualmente como identificador empresarial.
- `crm_team_id` referencia un equipo interno concreto en `crm_teams`; **no es sinónimo de `team_id`**.
- Hoy no existe un modelo/tabla raíz de negocio integrado ni una garantía completa de separación: ambos flags están desactivados y el scope queda abierto cuando falta contexto.
- Crear una tabla llamada Business no soluciona por sí solo los filtros, permisos, asignaciones ni relaciones. La raíz empresarial puede implementarse de forma explícita y reutilizar `team_id`; no es necesario duplicar ese discriminador en todas las tablas existentes.
- No activar `LARAVEL_CRM_TEAMS=true` como arreglo rápido: también activa el modo Spatie de teams y caminos que dependen de un contrato de usuario/esquema ausente.

Se conserva la decisión de una cuenta colaboradora por negocio y un mínimo de un administrador activo por empresa. El diseño regional queda en settings de instancia por país; la entidad propietaria y el aislamiento entre negocios siguen siendo un trabajo distinto, pendiente de implementar.

## 23. Activación parcial de teams, inicialización y fechas UTC

### 23.1. Estado después del cambio de configuración

Se comprobó que el cambio local es el default de `config/laravel-crm.php`: `env('LARAVEL_CRM_TEAMS', true)`. El default de `config/permission.php` continúa siendo `false`; la variable compartida no está definida en el `.env` inspeccionado. Por eso los flags efectivos en el contenedor `app` difieren: CRM true, Spatie false.

`migrate:status --pending` no muestra migraciones pendientes. La base `back_qaturia` conserva 4 roles, 152 permisos, 7 pipelines, 43 settings y un usuario con acceso CRM y rol Owner. No se ejecutó `migrate:fresh`, `migrate:refresh`, truncado ni recreación de la base.

Recrear el esquema con esos flags no implementaría el contrato de cuenta externa ausente. La migración de permisos decide sus columnas de teams según `permission.teams`, no por el flag CRM. Los comandos heredados, como `LaravelCrmPermissions`, también consultan una tabla host `teams` que no existe. Activar además el flag Spatie requeriría reconciliar su esquema y el contexto de autorización; no está cubierto por cambiar un default ni por repetir la instalación.

No se necesita refresh para cargar catálogos. La carga normal idempotente es:

```bash
docker compose exec app php artisan db:seed --force --no-interaction
```

Si se necesita crear otra cuenta o conceder acceso a una existente, el comando interactivo es:

```bash
docker compose exec app php artisan laravelcrm:add-user
```

Estos comandos cargan defaults o gestionan usuarios; no completan la integración multiempresa. El usuario Owner existente no necesita recrearse. `laravelcrm:install --force --no-interaction` sigue siendo el comando general de instalación/actualización, pero no debe presentarse como una solución al modo teams incompleto: con ese modo activa comandos heredados que dependen de tablas host ausentes. Primero debe resolverse esa compatibilidad, conservando las restricciones sobre tablas internas de Spatie.

### 23.2. Qué hace realmente el setting de zona horaria

La configuración efectiva de Laravel es `app.timezone = UTC` y PostgreSQL también usa UTC. El setting `crm_settings.timezone` no cambia automáticamente esas configuraciones ni convierte por sí mismo todas las fechas de los modelos o consultas.

Los campos inspeccionados de tareas y campañas son `timestamp without time zone`: no guardan el nombre de zona ni el offset. Guardar UTC es una convención de la aplicación que debe cumplirse al escribir, no una conversión automática derivada del setting. `date_format` y `time_format` controlan presentación; no son la zona horaria de persistencia.

| Flujo comprobado | Comportamiento actual |
| --- | --- |
| Timestamps automáticos de modelos | Siguen la configuración UTC de la aplicación. |
| Campaña de email/SMS programada | El servicio interpreta la entrada en la zona configurada y llama explícitamente a `setTimezone('UTC')` antes de persistir el instante. |
| Tarea creada/editada | `TaskService` pasa `start_at` y `due_at` directamente al modelo. No interpreta una cadena local con el setting timezone ni aplica conversión explícita a UTC. |
| Edición/presentación de tarea | `TaskEdit` y vistas inspeccionadas formatean la fecha sin convertirla explícitamente a la zona del setting. No existe una conversión global automática al consultar. |

Se ejecutaron **3 pruebas temporales, 25 aserciones aprobadas**, aisladas de PostgreSQL y sin guardar modelos reales:

1. Con setting simulado `America/Lima`, asignar una cadena sin offset `2026-11-14T10:00` a `Task.due_at` mantiene el valor de persistencia `2026-11-14 10:00:00`, interpretado en UTC; el setting no lo transforma a 15:00 UTC.
2. El flujo real de programación de campañas, con sus escrituras/envíos sustituidos en la prueba, convierte esa hora local de Lima a `2026-11-14 15:00:00` UTC y conserva la zona de programación.
3. Los timestamps automáticos del modelo utilizan UTC.

El contrato recomendado sigue siendo instantes en UTC y conversión explícita al entrar/presentar en la zona regional; las fechas civiles sin hora se tratan según su semántica. La implementación actual es parcial y no debe describirse como si todo el CRM ya hiciera esa conversión uniformemente. Cambiar el setting no transforma históricos ni corrige esos flujos.

## 24. Implementación del flujo regional UTC

Este apartado actualiza el diagnóstico del 23.2: se implementaron las conversiones sin migraciones ni cambio de `app.timezone`, que sigue en UTC. La zona elegida en settings es la autoridad para las entradas y la presentación del CRM; no depende de la zona del navegador.

### 24.1. Contrato aplicado

- `Support/CrmDateTime` interpreta formularios sin offset en la zona IANA configurada y devuelve un instante UTC. Los valores con offset explícito y los objetos DateTime conservan el instante. Valores vacíos permanecen nulos.
- `Support/UtcDateTime` y el modelo base CRM normalizan instantes para persistencia, lectura y JSON UTC. Las conversiones de presentación crean copias, sin mutar los modelos ni la zona global de PHP. No se guarda estado de petición en singletons o propiedades estáticas.
- Tareas, llamadas, reuniones, almuerzos y notas convierten al crear/editar e hidratan de vuelta la hora regional. Las campañas email/SMS validan que la programación sea futura en esa zona, guardan UTC y conservan la zona de programación.
- Recordatorios seleccionan ventanas UTC; su texto y las vistas de fechas absolutas convierten a la zona configurada. Los filtros y gráficos construyen días, semanas y meses regionales y convierten sus límites a UTC antes de consultar SQL. Las horas repetidas de un día de 25 horas se distinguen por offset.
- Cumpleaños y fechas civiles de documentos (emisión, vencimiento, entrega y cierre esperado) mantienen su día de calendario. No se les aplica un desplazamiento horario, aunque algunos campos históricos usen una columna timestamp.
- Se rechazan fechas inválidas y horas locales inexistentes o ambiguas por DST. Para identificar una hora repetida, una entrada con offset explícito permite escoger el instante. La validación de settings exige una zona IANA válida.

Ejemplo con `America/Lima`: `2026-11-20T12:00` en un formulario se guarda como `2026-11-20 17:00:00` UTC y vuelve al formulario como `12:00`. JSON conserva `2026-11-20T17:00:00.000000Z`. Cambiar después el setting a otra zona cambia su presentación, no el instante almacenado.

### 24.2. Límites de datos y compatibilidad

No se ejecutó refresh, reinstalación ni backfill de la base habitual. Los timestamps sin zona no permiten deducir si una fila histórica fue escrita en UTC o como hora local: no se desplazaron automáticamente. Antes de corregir históricos, habría que inventariar su origen y acordar la interpretación de cada campo.

No se cambiaron las tablas de jobs, el código interno de Spatie, los modelos canónicos de identidad, las migraciones publicadas ni las dependencias. Los cambios locales del usuario en los flags teams se conservaron. Su integración sigue pendiente por los motivos de los apartados 22 y 23; las pruebas de fechas no certifican aislamiento empresarial.

### 24.3. Validación aislada

Se añadió `tests/Feature/CrmTimezoneTest.php` con casos de formularios Livewire reales, escritura/lectura UTC, JSON, medianoche regional, fechas civiles, DST, filtros del dashboard, campañas y recordatorios. La regresión incluye las pruebas existentes de UUID, API y seeders.

La validación PostgreSQL usa un contenedor PostgreSQL 16 sin volumen persistente, host `qaturia-timezone-pg-20261114` y base `crm_timezone_test`; tanto variables server como env se fuerzan en la configuración temporal del runner. Una prueba adicional comprueba el host y `current_database()` antes de aceptar los resultados. No se ejecuta la suite contra `back_qaturia`.

Se probó en navegador el login y la edición de una tarea contra esa base desechable. Un cambio de `10:00` a `12:00` de Lima persistió `17:00 UTC`; al reabrir se mostró `12:00`. Con el navegador en New York, el redondeo de un campo local no saltó una hora por el DST del equipo. Se corrigió además un UUID sin comillas en el botón de completar tareas que disparaba un error Alpine; la navegación posterior no registró errores ni advertencias de consola.

Resultados finales del 2026-11-14:

| Comprobación | Resultado |
| --- | --- |
| Regresión completa SQLite en memoria con teams desactivado | 71 pruebas, 852 aserciones aprobadas. |
| Regresión completa PostgreSQL 16 desechable, incluida comprobación de aislamiento | 72 pruebas, 855 aserciones aprobadas. |
| Cobertura regional añadida | 24 pruebas, 64 aserciones aprobadas. Incluye mes anterior desde el 31 de marzo y separación de ambas horas repetidas por DST. |
| PHPStan del helper, cast UTC y modelo base CRM | Sin errores. No equivale a analizar todo el módulo heredado. |
| PHP lint de los archivos PHP en alcance | 62 archivos sin errores de sintaxis. |
| Blade | `view:cache` completado en la instancia de prueba. |
| Formato y diff | Pint aprobado y `git diff --check` sin errores. |

La configuración temporal del runner, la cuenta/fixture del navegador, los artefactos del navegador y ambos contenedores desechables se retiraron al terminar. La cuenta de prueba nunca se creó en la base habitual. Para repetir la suite estándar en el checkout actual, aislando el trabajo pendiente de teams:

```bash
LARAVEL_CRM_TEAMS=false php artisan test --compact
```
