# Multiempresa de Qaturia

Cada identidad interna pertenece a un negocio. El negocio tiene colaboradores y
puede tener equipos internos. Los roles funcionales son globales y sus asignaciones
se delimitan por negocio.

Flujo implementado: [CRM_MULTIEMPRESA.puml](DIAGRAMAS/CRM_MULTIEMPRESA.puml).

## Esquema y propiedad de datos

| Entidad | Autoridad |
| --- | --- |
| Negocio | App\Models\Business, tabla businesses, UUID propio. |
| Colaborador | users.team_id referencia businesses.id. |
| Datos CRM | El team_id legado identifica al negocio, conservando tablas y UUID. |
| Equipos internos | crm_teams y crm_team_user agrupan colaboradores de la misma empresa. |
| Roles | Owner, Admin, Manager y Employee tienen roles.team_id nulo y se comparten. |
| Asignaciones | model_has_roles.team_id identifica el negocio donde se otorga el rol. |
| Identidades externas | El registro del portal mantiene crm_access deshabilitado y no crea negocios. |

Los usuarios internos no tienen selector de empresas ni membresías múltiples.
Business conserva compatibilidad con el antiguo contrato currentTeam/allUsers del
CRM, sin instalar Jetstream ni crear team_user o current_team_id.

Organization sigue siendo una contraparte comercial (prospecto, cliente, proveedor);
Person representa una persona de la agenda. Ninguno sustituye a Business.

## Perfil empresarial y ajustes

| Dato | Columna en businesses | Compatibilidad con lectores de ajustes |
| --- | --- | --- |
| Nombre comercial | name | organization_name |
| Razón social | legal_name | legal_name |
| RUC / identificador fiscal | tax_id, texto | vat_number |
| Dirección fiscal | fiscal_address | fiscal_address |
| Email / teléfono | email / phone | business_email / business_phone |
| Web | website_url | website_url |
| Logo | logo_path / logo_name | logo_file / logo_file_name |

SettingService lee y escribe esas claves directamente sobre Business: no crea una
segunda copia editable en crm_settings. El formulario de ajustes permite modificarlas
y borrar valores opcionales.

Las preferencias de documentos, impuestos, formatos y opciones de formulario siguen
en crm_settings, con team_id del negocio y user_id nulo. Las preferencias personales
llevan user_id. Los valores regionales existentes mantienen su estructura; los
defaults de la instancia se heredan y los ajustes propios tienen precedencia.

Los metadatos de instalación y los indicadores de migraciones son globales.
Un negocio no hereda nombre, RUC, logo, integraciones ni términos privados de otro.
Los documentos resuelven su emisor y su bloque fiscal desde el negocio del documento.
Un bloque de contacto PDF personalizado conserva su precedencia.

## Flujos disponibles

1. Registro SaaS: /crm/register, o el prefijo CRM configurado. Crea negocio,
   primer usuario y asignación del Owner global en una transacción.
2. Colaboradores: creación desde CRM, invitación o importación. Se vinculan
   al negocio del administrador. No pueden trasladarse a otra empresa.
3. Equipos internos: agrupan colaboradores sin cambiar su negocio y sin
   duplicar roles o provisionar empresas.
4. Perfil: completar RUC, razón social, dirección y contacto en ajustes.
5. API v2: el token autentica una identidad y sus respuestas incluyen su negocio.
   X-Team-ID es opcional; si se envía, debe coincidir con su única empresa.

El propietario designado debe conservar acceso CRM y su rol Owner. No se permite
borrarlo, desactivarlo o quitarle ese rol desde los flujos ordinarios.

Los tenants pueden consultar roles compartidos; su modificación se reserva a la
operación de la plataforma. Los catálogos globales de referencia son de solo lectura;
los registros de catálogo creados por una empresa pertenecen a ella.

## Aislamiento

BusinessContext es scoped y se establece antes de los bindings de rutas.
Sin contexto, los modelos de datos empresariales no devuelven registros y rechazan
escrituras. Los catálogos de referencia admiten lectura de sus defaults globales.

Además del scope, las policies rechazan objetos de otra empresa. BusinessIntegrity
valida la pertenencia y las referencias, incluidas relaciones polimórficas y referencias
legadas sin FK. Los pivotes validan los IDs antes de sincronizar miembros y etiquetas.

Las migraciones añaden FKs a businesses y referencias compuestas entre entidades
empresariales donde hay relaciones declaradas. Las escrituras masivas tampoco pueden
vincular entre sí esos registros de diferentes negocios.

Las claves de cache de ajustes, numeración y referencias de chat se separan por negocio.
Los jobs transportan business_id y restauran contexto al finalizar. Los comandos
programados recorren negocios explícitamente.

Las URLs firmadas usan la empresa del documento, aunque otro usuario tenga sesión.
Los widgets y el tracking resuelven contexto desde su clave pública o token y luego
aplican sus comprobaciones de acceso. El chat público emite una señal de actualización:
el cuerpo del mensaje se obtiene por el endpoint con sesión o token de visitante.

## Migración de instalaciones existentes

Guardar una copia de PostgreSQL antes de desplegar. Aplicar las migraciones aditivas:

~~~bash
docker compose exec workspace php artisan migrate --force
~~~

La migración no adivina a quién pertenecen los datos anteriores. Un usuario CRM sin
negocio queda sin acceso hasta resolver su pertenencia.

Para la instalación antigua de un único negocio y un solo usuario CRM:

~~~bash
docker compose exec workspace php artisan laravelcrm:adopt-business EMAIL_O_UUID --dry-run
docker compose exec workspace php artisan laravelcrm:adopt-business EMAIL_O_UUID --name="Nombre comercial"
~~~

La adopción conserva UUIDs, vincula registros sin empresa, mueve la identidad fiscal
a Business y asigna Owner en su contexto. Preserva los metadatos globales de instalación
y vuelve a crear defaults de ajustes sin difundir preferencias de la empresa antigua.
Los catálogos globales compartidos permanecen como referencias.

El comando rechaza la adopción automática si ya existen negocios o varias cuentas CRM.
Una instalación con tenants anteriores, datos ambiguos o catálogos privados guardados
como globales necesita un mapeo explícito antes de migrar esos registros.

Crear un negocio y su propietario por consola:

~~~bash
docker compose exec workspace php artisan laravelcrm:add-user --business-name="Mi empresa"
~~~

Añadir un colaborador a una empresa existente:

~~~bash
docker compose exec workspace php artisan laravelcrm:add-user --business=UUID
~~~

Tras desplegar, reiniciar los workers de cola y recargar los procesos de Octane/Reverb
para que utilicen el código nuevo. El registro público requiere los seeders de roles
y catálogos ejecutados durante la instalación.

## Verificación

La suite Tenancy prueba empresas distintas, referencias cruzadas, transacciones,
registro web/CLI, perfiles fiscales, invitaciones, documentos firmados, colas y API.
Las pruebas de desarrollo fuerzan drivers de prueba y una base SQLite aislada.
La misma suite puede ejecutarse en una base PostgreSQL de validación separada.

~~~bash
docker compose exec workspace php artisan test
docker compose exec workspace vendor/bin/phpstan analyse --memory-limit=768M
~~~
