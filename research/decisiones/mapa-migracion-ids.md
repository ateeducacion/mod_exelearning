# Mapa de migración de identificadores de decisión

Esta página mapea cada identificador retirado a su identificador actual.

Los ADRs pasaron de un contador global (`DEC-NNNN`) a identificadores basados en el
**número de seguimiento de GitHub** del cambio que los motiva. La convención, sus
alternativas y su evidencia están recogidas en
[`exelearning/exelearning#2232`](https://github.com/exelearning/exelearning/issues/2232);
la política local está en [`README.md`](./README.md).

**Los identificadores retirados no deben usarse en contenido nuevo.**
`make architecture-check` falla cuando aparece uno fuera de esta página, del campo
`legacy_id` y de los pocos documentos históricos listados abajo. Usa las tablas
siguientes para encontrar el identificador actual.

## Cómo se estableció cada número de seguimiento

Por orden de autoridad:

1. Una referencia explícita a un issue en el propio registro (título, frontmatter o
   cuerpo).
2. El asunto `(#N)` del commit de squash que añadió el archivo
   (`git log --diff-filter=A --follow -- <ruta>`).
3. El título del pull request, cuando el archivo entró por push directo a `main` y ese
   PR lo nombra explícitamente.

Los issues locales #13 y #29 se transfirieron después a
`exelearning/exelearning` (#2058 y #2056 respectivamente), pero se usan **los números
locales**: siguen consumidos de forma permanente en la secuencia issue/PR de este
repositorio, `https://github.com/exelearning/moodle-mod_exelearning/issues/13` sigue
redirigiendo (302), y toda la documentación existente ya dice «issue #13». Usar 2058
importaría la numeración de otro repositorio y acabaría colisionando con el PR #2058 de
éste.

## Architecture Decision Records

| Identificador retirado | Identificador actual | Número de seguimiento | Ruta actual |
|---|---|---|---|
| `DEC-0016` | `DEC-4-01` | [#4](https://github.com/exelearning/moodle-mod_exelearning/issues/4) (PR) | [`adr/DEC-4-01-auditoria-seguridad-correccion.md`](./adr/DEC-4-01-auditoria-seguridad-correccion.md) |
| `DEC-0017` | `DEC-5-01` | [#5](https://github.com/exelearning/moodle-mod_exelearning/issues/5) (PR) | [`adr/DEC-5-01-mapeo-objectid-calificacion.md`](./adr/DEC-5-01-mapeo-objectid-calificacion.md) |
| `DEC-0018` | `DEC-6-01` | [#6](https://github.com/exelearning/moodle-mod_exelearning/issues/6) (PR) | [`adr/DEC-6-01-recalculo-overall-y-hardening.md`](./adr/DEC-6-01-recalculo-overall-y-hardening.md) |
| `DEC-0020` | `DEC-11-01` | [#11](https://github.com/exelearning/moodle-mod_exelearning/issues/11) (PR) | [`adr/DEC-11-01-traducciones-marca-tilde.md`](./adr/DEC-11-01-traducciones-marca-tilde.md) |
| `DEC-0021` | `DEC-12-01` | [#12](https://github.com/exelearning/moodle-mod_exelearning/issues/12) (PR) | [`adr/DEC-12-01-edicion-contenido-calificable.md`](./adr/DEC-12-01-edicion-contenido-calificable.md) |
| `DEC-0022` | `DEC-13-01` | [#13](https://github.com/exelearning/moodle-mod_exelearning/issues/13) (issue) | [`adr/DEC-13-01-deteccion-calificable-por-isscorm.md`](./adr/DEC-13-01-deteccion-calificable-por-isscorm.md) |
| `DEC-0023` | `DEC-13-02` | [#13](https://github.com/exelearning/moodle-mod_exelearning/issues/13) (issue) | [`adr/DEC-13-02-deeplink-gradebook-grade-php.md`](./adr/DEC-13-02-deeplink-gradebook-grade-php.md) |
| `DEC-0024` | `DEC-13-03` | [#13](https://github.com/exelearning/moodle-mod_exelearning/issues/13) (issue) | [`adr/DEC-13-03-crear-desde-cero-y-pantalla-completa.md`](./adr/DEC-13-03-crear-desde-cero-y-pantalla-completa.md) |
| `DEC-0025` | `DEC-13-04` | [#13](https://github.com/exelearning/moodle-mod_exelearning/issues/13) (issue) | [`adr/DEC-13-04-importar-desde-exeweb-exescorm.md`](./adr/DEC-13-04-importar-desde-exeweb-exescorm.md) |
| `DEC-0026` | `DEC-13-05` | [#13](https://github.com/exelearning/moodle-mod_exelearning/issues/13) (issue) | [`adr/DEC-13-05-migracion-masiva-desde-ajustes.md`](./adr/DEC-13-05-migracion-masiva-desde-ajustes.md) |
| `DEC-0028` | `DEC-13-06` | [#13](https://github.com/exelearning/moodle-mod_exelearning/issues/13) (issue) | [`adr/DEC-13-06-enlaces-libro-calificaciones.md`](./adr/DEC-13-06-enlaces-libro-calificaciones.md) |
| `DEC-0029` | `DEC-13-07` | [#13](https://github.com/exelearning/moodle-mod_exelearning/issues/13) (issue) | [`adr/DEC-13-07-interruptor-calificable-por-actividad.md`](./adr/DEC-13-07-interruptor-calificable-por-actividad.md) |
| `DEC-0030` | `DEC-13-08` | [#13](https://github.com/exelearning/moodle-mod_exelearning/issues/13) (issue) | [`adr/DEC-13-08-version-sentinela-en-main.md`](./adr/DEC-13-08-version-sentinela-en-main.md) |
| `DEC-0031` | `DEC-13-09` | [#13](https://github.com/exelearning/moodle-mod_exelearning/issues/13) (issue) | [`adr/DEC-13-09-split-grading-attempts-formulario.md`](./adr/DEC-13-09-split-grading-attempts-formulario.md) |
| `DEC-0037` | `DEC-13-10` | [#13](https://github.com/exelearning/moodle-mod_exelearning/issues/13) (issue) | [`adr/DEC-13-10-deteccion-isscorm-en-datagame-cifrado.md`](./adr/DEC-13-10-deteccion-isscorm-en-datagame-cifrado.md) |
| `DEC-0042` | `DEC-13-11` | [#13](https://github.com/exelearning/moodle-mod_exelearning/issues/13) (issue) | [`adr/DEC-13-11-parche-save-guard-form-scrambled.md`](./adr/DEC-13-11-parche-save-guard-form-scrambled.md) |
| `DEC-0050` | `DEC-13-12` | [#13](https://github.com/exelearning/moodle-mod_exelearning/issues/13) (issue) | [`adr/DEC-13-12-herramienta-migracion-en-mod-exelearning.md`](./adr/DEC-13-12-herramienta-migracion-en-mod-exelearning.md) |
| `DEC-0027` | `DEC-16-01` | [#16](https://github.com/exelearning/moodle-mod_exelearning/issues/16) (PR) | [`adr/DEC-16-01-aceptar-zip-con-content-xml.md`](./adr/DEC-16-01-aceptar-zip-con-content-xml.md) |
| `DEC-0032` | `DEC-17-01` | [#17](https://github.com/exelearning/moodle-mod_exelearning/issues/17) (PR) | [`adr/DEC-17-01-ingesta-dual-scorm-xapi.md`](./adr/DEC-17-01-ingesta-dual-scorm-xapi.md) |
| `DEC-0033` | `DEC-18-01` | [#18](https://github.com/exelearning/moodle-mod_exelearning/issues/18) (PR) | [`adr/DEC-18-01-actualizacion-paquete-y-origen-url.md`](./adr/DEC-18-01-actualizacion-paquete-y-origen-url.md) |
| `DEC-0034` | `DEC-19-01` | [#19](https://github.com/exelearning/moodle-mod_exelearning/issues/19) (PR) | [`adr/DEC-19-01-categoria-de-calificacion.md`](./adr/DEC-19-01-categoria-de-calificacion.md) |
| `DEC-0035` | `DEC-19-02` | [#19](https://github.com/exelearning/moodle-mod_exelearning/issues/19) (PR) | [`adr/DEC-19-02-visibilidad-notas-alumno-peritem.md`](./adr/DEC-19-02-visibilidad-notas-alumno-peritem.md) |
| `DEC-0038` | `DEC-25-01` | [#25](https://github.com/exelearning/moodle-mod_exelearning/issues/25) (PR) | [`adr/DEC-25-01-sin-columna-overall-en-peritem.md`](./adr/DEC-25-01-sin-columna-overall-en-peritem.md) |
| `DEC-0039` | `DEC-26-01` | [#26](https://github.com/exelearning/moodle-mod_exelearning/issues/26) (PR) | [`adr/DEC-26-01-parser-content-xml-hibrido-dom.md`](./adr/DEC-26-01-parser-content-xml-hibrido-dom.md) |
| `DEC-0040` | `DEC-26-02` | [#26](https://github.com/exelearning/moodle-mod_exelearning/issues/26) (PR) | [`adr/DEC-26-02-mobile-external-api.md`](./adr/DEC-26-02-mobile-external-api.md) |
| `DEC-0041` | `DEC-26-03` | [#26](https://github.com/exelearning/moodle-mod_exelearning/issues/26) (PR) | [`adr/DEC-26-03-eventos-selectivos.md`](./adr/DEC-26-03-eventos-selectivos.md) |
| `DEC-0043` | `DEC-29-01` | [#29](https://github.com/exelearning/moodle-mod_exelearning/issues/29) (issue) | [`adr/DEC-29-01-deteccion-geogebra-auto-scorm.md`](./adr/DEC-29-01-deteccion-geogebra-auto-scorm.md) |
| `DEC-0044` | `DEC-34-01` | [#34](https://github.com/exelearning/moodle-mod_exelearning/issues/34) (PR) | [`adr/DEC-34-01-auditoria-bugs-criticos.md`](./adr/DEC-34-01-auditoria-bugs-criticos.md) |
| `DEC-0045` | `DEC-34-02` | [#34](https://github.com/exelearning/moodle-mod_exelearning/issues/34) (PR) | [`adr/DEC-34-02-transformacion-en-servido.md`](./adr/DEC-34-02-transformacion-en-servido.md) |
| `DEC-0046` | `DEC-36-01` | [#36](https://github.com/exelearning/moodle-mod_exelearning/issues/36) (PR) | [`adr/DEC-36-01-inyecciones-scorm-teacher-mode-plugin-vs-upstream.md`](./adr/DEC-36-01-inyecciones-scorm-teacher-mode-plugin-vs-upstream.md) |
| `DEC-0047` | `DEC-37-01` | [#37](https://github.com/exelearning/moodle-mod_exelearning/issues/37) (PR) | [`adr/DEC-37-01-clasificacion-funcional-archetype-purpose.md`](./adr/DEC-37-01-clasificacion-funcional-archetype-purpose.md) |
| `DEC-0048` | `DEC-66-01` | [#66](https://github.com/exelearning/moodle-mod_exelearning/issues/66) (PR) | [`adr/DEC-66-01-estrategia-cobertura-tests.md`](./adr/DEC-66-01-estrategia-cobertura-tests.md) |
| `DEC-0049` | `DEC-67-01` | [#67](https://github.com/exelearning/moodle-mod_exelearning/issues/67) (PR) | [`adr/DEC-67-01-auditoria-estandar-mejoras-2026-06.md`](./adr/DEC-67-01-auditoria-estandar-mejoras-2026-06.md) |
| `DEC-0051` | `DEC-68-01` | [#68](https://github.com/exelearning/moodle-mod_exelearning/issues/68) (PR) | [`adr/DEC-68-01-eventos-ciclo-de-vida-intento.md`](./adr/DEC-68-01-eventos-ciclo-de-vida-intento.md) |
| `DEC-0052` | `DEC-69-01` | [#69](https://github.com/exelearning/moodle-mod_exelearning/issues/69) (PR) | [`adr/DEC-69-01-completion-por-estado.md`](./adr/DEC-69-01-completion-por-estado.md) |
| `DEC-0053` | `DEC-70-01` | [#70](https://github.com/exelearning/moodle-mod_exelearning/issues/70) (PR) | [`adr/DEC-70-01-busqueda-global.md`](./adr/DEC-70-01-busqueda-global.md) |
| `DEC-0054` | `DEC-71-01` | [#71](https://github.com/exelearning/moodle-mod_exelearning/issues/71) (PR) | [`adr/DEC-71-01-extraccion-lib-php.md`](./adr/DEC-71-01-extraccion-lib-php.md) |
| `DEC-0055` | `DEC-72-01` | [#72](https://github.com/exelearning/moodle-mod_exelearning/issues/72) (PR) | [`adr/DEC-72-01-auditoria-followup-post-refactor.md`](./adr/DEC-72-01-auditoria-followup-post-refactor.md) |
| `DEC-0056` | `DEC-74-01` | [#74](https://github.com/exelearning/moodle-mod_exelearning/issues/74) (PR) | [`adr/DEC-74-01-tests-js-tracker-scorm-vitest.md`](./adr/DEC-74-01-tests-js-tracker-scorm-vitest.md) |
| `DEC-0057` | `DEC-77-01` | [#77](https://github.com/exelearning/moodle-mod_exelearning/issues/77) (PR) | [`adr/DEC-77-01-extraccion-no-destructiva.md`](./adr/DEC-77-01-extraccion-no-destructiva.md) |
| `DEC-0058` | `DEC-78-01` | [#78](https://github.com/exelearning/moodle-mod_exelearning/issues/78) (PR) | [`adr/DEC-78-01-fijar-editor-tag-en-release.md`](./adr/DEC-78-01-fijar-editor-tag-en-release.md) |
| `DEC-0064` | `DEC-85-01` | [#85](https://github.com/exelearning/moodle-mod_exelearning/issues/85) (PR) | [`adr/DEC-85-01-implementacion-ingesta-xapi.md`](./adr/DEC-85-01-implementacion-ingesta-xapi.md) |
| `DEC-0065` | `DEC-106-01` | [#106](https://github.com/exelearning/moodle-mod_exelearning/issues/106) (PR) | [`adr/DEC-106-01-editor-empaquetado-solo-en-release.md`](./adr/DEC-106-01-editor-empaquetado-solo-en-release.md) |
| `DEC-0066` | `DEC-108-01` | [#108](https://github.com/exelearning/moodle-mod_exelearning/issues/108) (PR) | [`adr/DEC-108-01-interruptor-global-editor-embebido.md`](./adr/DEC-108-01-interruptor-global-editor-embebido.md) |
| `DEC-0067` | `DEC-110-01` | [#110](https://github.com/exelearning/moodle-mod_exelearning/issues/110) (PR) | [`adr/DEC-110-01-pagina-estilos-solo-endpoint.md`](./adr/DEC-110-01-pagina-estilos-solo-endpoint.md) |
| `DEC-0068` | `DEC-111-01` | [#111](https://github.com/exelearning/moodle-mod_exelearning/issues/111) (PR) | [`adr/DEC-111-01-version-real-monotona-en-main.md`](./adr/DEC-111-01-version-real-monotona-en-main.md) |

Los slugs se conservaron **literalmente**. Sólo cambian el identificador del nombre de
archivo, los campos `id`/`tracking_issue`/`legacy_id` del frontmatter y el H1 (que el
corpus no tenía y ahora es obligatorio). Todo el contenido, la prosa en español y los
metadatos de procedencia (`agentes`, `fuentes`, `relacionados`, `herramienta_ia`) siguen
intactos. Los renombrados se hicieron con `git mv`, así que `git log --follow` sigue
resolviendo la historia completa.

La secuencia local conserva el orden relativo original dentro de cada número de
seguimiento: `DEC-0022`…`DEC-0050` del issue #13 pasaron a `-01`…`-12` por fecha.

`DEC-0045` arrastra una cadena de renombrados: nació como un **segundo** `DEC-0043` en el
PR #34 (colisionando con el `DEC-0043` de GeoGebra del PR #30) y el PR #35 lo renumeró a
`DEC-0045`. Su `legacy_id` registra `DEC-0045`, que es el identificador que llegó a
`main`; su número de seguimiento es 34, donde aterrizó la decisión.

## Registros que conservan la numeración retirada

Dieciocho registros **no** se han migrado porque no tienen número de seguimiento
verificable: se subieron directamente a `main` durante el arranque del repositorio (31
commits consecutivos con el asunto «Initial commit» y push directo posterior), no citan
ningún issue, y `gh api repos/.../commits/<sha>/pulls` devuelve vacío para todos ellos.

| Identificador | Commit que lo añadió |
|---|---|
| `DEC-0-01`, `DEC-0-02`, `DEC-0-03` | `bec976e` |
| `DEC-0-04` | `8cd169d` |
| `DEC-0-05`, `DEC-0-06` | `7af2303` |
| `DEC-0-07`, `DEC-0-08` | `d505d87` |
| `DEC-0-09` | `d0d6ecf` |
| `DEC-0-10` | `2c07b22` |
| `DEC-0-11` | `0aadc1b` |
| `DEC-0-12` | `4dd0866` |
| `DEC-0-13` | `a0ba1da` |
| `DEC-0-14` | `bab463f` |
| `DEC-0-15` | `59670b8` |
| `DEC-0-16` | `90d5cf5` |
| `DEC-0-17` | `a2116f6` |
| `DEC-0-18` | `4eccb06` |

**No se ha inventado un número para ellos y no se ha abierto ningún issue.** Sus
identificadores siguen siendo válidos y resolubles: son los ficheros que existen. La
lista está congelada en `research/tools/architecture_records.py`; un archivo `DEC-NNNN-*.md`
**nuevo** hace fallar la validación, así que la deuda no puede crecer.

Para cerrarla hace falta una decisión de mantenimiento entre estas opciones:

- **(a)** dejarlos como están indefinidamente — dos esquemas conviviendo;
- **(b)** agruparlos bajo el número del PR que los migre, leyendo «o su pull request»
  como el PR que les da identidad;
- **(c)** que el mantenedor aporte números de seguimiento concretos.

Candidatos débiles que **no** se han promovido a decisión, sólo para que el mantenedor
los valore:

- `DEC-0-18` — se escribió en `main` como `DEC-0059` (commit `4eccb06`) y se renumeró a
  `DEC-0-18` en `9b606b0` para esquivar la colisión con la rama del iframe seguro. El PR
  **#85** lo cita en su cuerpo y entrega su hermano `DEC-85-01`, pero el registro es
  anterior al PR.
- `DEC-0-04` — una sección de enmienda dentro del registro menciona «Issue #22, PR #23»,
  pero eso documenta una *revisión posterior*, no la decisión original.

## Documentos que pueden nombrar identificadores retirados

La validación permite identificadores retirados en:

| Ruta | Motivo |
|---|---|
| `research/decisiones/mapa-migracion-ids.md` | esta página |
| `research/decisiones/README.md` | enumera los registros no migrados |
| `research/schemas/decision.schema.yaml` | documenta el patrón retirado |
| `research/tools/architecture_records.py` y su test | la lista congelada y sus fixtures |
| `research/tareas/diario/2026-06-17-adr-validacion-xapi-y-2.0.yaml` | registro histórico de la colisión `DEC-0059`/`DEC-0-18` que motivó retirar el contador |
| cualquier línea `legacy_id:` | el campo existe precisamente para eso |

## Identificadores reservados en ramas abiertas

El PR [#80](https://github.com/exelearning/moodle-mod_exelearning/pull/80)
(`feature/secure-iframe-scorm-bridge`) añade siete registros más con la numeración
retirada y ya ha sobrevivido a una colisión del contador global (sus `DEC-0065`…`DEC-0067`
se renumeraron a `DEC-0069`…`DEC-0071` cuando `main` reclamó ese rango). Esa rama migra
sus propios identificadores en su propia rama; aquí no se tocan.
