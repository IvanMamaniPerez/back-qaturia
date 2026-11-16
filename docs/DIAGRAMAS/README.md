# Visualización del CRM con Plan UML

La implementación SaaS vigente se describe en
[CRM_MULTIEMPRESA.puml](CRM_MULTIEMPRESA.puml) y en [MULTIEMPRESA.md](../MULTIEMPRESA.md).
Las 22 vistas originales conservan la inspección anterior a esta implementación:
sus etiquetas PROPUESTA/BRECHA sobre Business, registro y aislamiento son históricas.

El archivo [CRM_FLUJO_COMPLETO.puml](CRM_FLUJO_COMPLETO.puml) contiene las 22 vistas.
Al abrirlo con Plan UML se muestra el panorama principal. Para ver cada módulo,
abrir el archivo correspondiente en [vistas](vistas), por ejemplo
[entidades comerciales](vistas/crm_01_entidades_comerciales.puml).

En VS Code conectado a WSL, ejecutar **Plan UML: Open Plan UML Preview** desde
la paleta de comandos. Si permanece el error anterior a instalar Java, ejecutar
**Developer: Reload Window** y volver a abrir la vista.

## Dependencias locales

La instalación de WSL incluye OpenJDK 17, Graphviz, Fontconfig, fuentes DejaVu y
el comando plantuml. El JAR es PlantUML 1.2025.10, la versión buscada por
Plan UML 0.0.8. La extensión también tiene el JAR en su almacenamiento de usuario;
no es necesario copiarlo a la raíz del repositorio.

Comprobar desde una terminal WSL:

~~~bash
java -version
dot -V
plantuml -testdot
~~~

## Actualizar los diagramas

Editar el archivo maestro y regenerar los accesos individuales desde la raíz:

~~~bash
python3 docs/DIAGRAMAS/generar_vistas.py
~~~

Las vistas incluyen el bloque correspondiente del maestro y una huella de su
contenido. Regenerarlas también invalida la caché de Plan UML cuando cambia
el diagrama, sin duplicar sus definiciones.

Para exportar todas las vistas a SVG con el comando instalado:

~~~bash
plantuml -charset UTF-8 -tsvg -o /tmp/qaturia-crm-svg docs/DIAGRAMAS/CRM_FLUJO_COMPLETO.puml
~~~

La extensión espera que el primer SVG tenga el mismo nombre que el archivo abierto.
Por eso el primer bloque del maestro y los accesos individuales usan @startuml
sin un nombre de salida alternativo.
