---
id: DEC-13-12
titulo: "La herramienta de migración exeweb/exescorm vive en mod_exelearning: endurecimiento (itemid=revision, clasificación exescorm, limpieza compensatoria, metadatos, preflight, eventos)"
estado: Aceptada
fecha: 2026-06-12
tracking_issue: 13
legacy_id: DEC-0050
agentes:
  - erseco
  - claude-code
fuentes:
  - REPO-001
  - REPO-007
relacionados:
  - DEC-13-05
  - DEC-13-04
  - DEC-26-03
  - DEC-0008
  - DEC-13-08
herramienta_ia:
  interfaz: claude-code
  modelo: claude-opus-4-8
---

# DEC-13-12: La herramienta de migración exeweb/exescorm vive en mod_exelearning: endurecimiento (itemid=revision, clasificación exescorm, limpieza compensatoria, metadatos, preflight, eventos)

## Contexto

La rama `feature/issue-13-import` añadió una herramienta de administración site-wide
que migra actividades `mod_exeweb` y `mod_exescorm` a nuevas actividades
`mod_exelearning` ([[DEC-13-05]], que supersede [[DEC-13-04]]). Era no destructiva: los
orígenes se conservan y el administrador verifica el resultado antes de desinstalar el
plugin legacy. La dirección era correcta, pero una revisión externa detectó bloqueantes
que **se verificaron todos en el código** antes de actuar:

1. **Bug `mod_exeweb` (bloqueante principal).** `resolve_source_elpx()` buscaba el
   paquete con `itemid = 0` para ambos componentes, pero `mod_exeweb` lo guarda en
   `itemid = {exeweb}.revision` (`mod_exeweb/classes/exeweb_package.php`,
   `file_save_draft_area_files(..., 'package', $data->revision, ...)`). Toda actividad
   exeweb real con `revision > 0` se reportaba como `nosource`. El bug era invisible en
   los tests porque el helper guardaba siempre con `itemid = 0`.
2. **Huérfanos ante fallo parcial.** El `catch` de `migrate_one()` devolvía error sin
   borrar el módulo destino ya creado: dejaba una actividad sin fila de mapping y un
   reintento creaba otra.
3. **Metadatos no preservados.** El destino se creaba con `visible=1`, `intro=''`,
   `groupmode=NOGROUPS` fijos, ignorando visibilidad, grupos, disponibilidad y
   finalización del cm origen.
4. **`mod_exescorm` sin clasificación.** No distinguía `local`/`embedded` (migrable) de
   `external`/`aiccurl`/`localsync` (no migrable), ni el paquete que **es** un `.elpx`
   directo (flujo EMBEDDED: `mod_exescorm/lib.php` salta el parseo SCORM cuando
   `reference` acaba en `.elpx`), ni los ZIP con varios `.elpx` embebidos (elegía el
   primero en silencio).
5. **Shell vacío silencioso (descubierto al endurecer).** `exelearning_extract_stored_package()`
   ignora el retorno de `extract_to_storage`; un `.elpx` corrupto producía una actividad
   "migrada" vacía sin error.

## Decisión

### La herramienta vive en `mod_exelearning` (destino)

Se mantiene la lógica de migración en el plugin **destino**, no en los orígenes. El
destino es quien posee el nuevo modelo de datos: extracción del `.elpx`
(`exelearning_extract_stored_package()`), sincronización de grade items
(`exelearning_sync_grade_items()`), los modelos de calificación ([[DEC-0008]]) y la
tabla de idempotencia. Los plugins origen se tratan como **fuentes legacy de solo
lectura** detrás de una interfaz (`source_interface`); no se les añade lógica de
escritura hacia el destino, lo que evitaría duplicar el flujo y obligaría a mantener
plugins legacy.

**Precedentes Moodle (verificados).** El core incluye `tool_lpmigrate` (plugin
`admin/tool/` con capability de sistema, procesador separado y tests) como migración
administrativa dentro del propio core. Para el caso "migrar un plugin contrib hacia un
módulo core más nuevo", el precedente oficial es **`tool_migratehvp2h5p`**
(`moodlehq/moodle-tool_migratehvp2h5p`, Moodle HQ — Sara Arjona / Ferran Recio,
MDL-67203, 2020; ver [[REPO-007]]): un plugin `admin/tool/` **separado** que migra
`mod_hvp` (contrib) a `mod_h5pactivity` (core) con interfaz web y **CLI**
(`cli/migrate.php`), procesador por actividad y tests, y `dependencies` sobre
`mod_h5pactivity` y `mod_hvp`. **No existe** una migración `hvp → h5pactivity` dentro de
`moodle/moodle` core: la ruta oficial es ese tool separado (corrige la afirmación
cautelosa de una versión previa de este ADR, que decía no haberla verificado).

Ambos precedentes son plugins `tool_*` **separados**. Aun así, para este par de orígenes
(`mod_exeweb`/`mod_exescorm`) se mantiene la herramienta **dentro de `mod_exelearning`**
porque el destino ya posee los internals que la migración necesita
(`exelearning_extract_stored_package()`, `exelearning_sync_grade_items()`, modelos de
calificación, idempotencia), el par de orígenes es pequeño, y la entrada de administración
solo aparece cuando hay un sibling instalado; un tercer plugin `tool_*` duplicaría la
instalación sin aportar aislamiento útil. Que `tool_migratehvp2h5p` incluya CLI confirma
que diferir el `cli/migrate.php` a una segunda iteración es coherente con la práctica
oficial.

Se descartó la alternativa de un plugin `tool_*` independiente: añadiría una segunda
instalación y separaría la herramienta de los internals que necesita.

### Refactor en clases testeables

La clase monolítica `classes/local/import_service.php` (introducida en esta misma rama,
nunca publicada: la versión seguía con el centinela `9999999999`, [[DEC-13-08]]) se
**eliminó** y se descompuso, sin fachada de compatibilidad (no había consumidores
externos que proteger):

```
classes/local/migration/
  migration_service.php          orquestación: get_available_sources / preflight / migrate_all / migrate_one / install_package
  migration_result.php           objeto resultado tipado con constantes de estado
  source/source_interface.php    contrato por sibling (instancia, inyectable en tests)
  source/source_query.php        SQL de enumeración site-wide compartida
  source/classification.php      veredicto barato de preflight (sin extraer)
  source/exeweb_source.php       itemid = {exeweb}.revision (+ fallback scan)
  source/exescorm_source.php     clasificación por exescormtype y layout de .elpx
  target/activity_builder.php    moduleinfo preservando metadatos del cm origen
  grade/overall_grade_migrator.php  copia de notas con reescalado
```

`admin/migrate.php` queda como controlador fino. Los tests de `import_test.php` se
portaron a `tests/local/migration/` (cada aserción antigua tiene destino nuevo).

### Endurecimientos concretos

- **`exeweb` itemid = revision** con *fallback*: si no hay fichero en
  `itemid = revision` (p. ej. backup restaurado con deriva de revisión), se escanea el
  filearea y se toma el `itemid` más alto. Test de regresión que falla con la lógica
  antigua (paquete en `itemid = 3`, fila `revision = 3` → antes `nosource`, ahora
  `migrated`).
- **Clasificación `exescorm`**: `external`/`aiccurl`/`localsync` → `unsupported` (sin
  tocar ficheros; `localsync` se excluye aunque tenga snapshot local en `package/0`,
  porque sigue sincronizándose desde una URL y migrarlo rompería esa relación);
  `exescormnet` permanece migrable (instanciación única convertida a `local`, no es
  sincronización continua); paquete que es un `.elpx` directo → migrable; ZIP con
  exactamente un `.elpx` embebido → migrable (se extrae solo esa entrada); cero →
  `nosource`; más de uno → `ambiguoussource`; ZIP corrupto → `nosource`. El listado del
  directorio central del ZIP (`stored_file::list_files()`) es barato y no extrae nada,
  apto para preflight.
- **Limpieza compensatoria**: si la creación del destino tiene éxito pero falla la
  instalación o la migración de notas, el `catch` llama a `course_delete_module()` (borra
  cm, instancia, fileareas de contexto y grade items). **Sin transacción** DB
  deliberadamente: las escrituras de fichero no son transaccionales y
  `course_delete_module()` no puede ejecutarse dentro de una; el diseño es compensación
  explícita. *Caveat*: `tool_recyclebin` puede respaldar el módulo a medio construir y su
  hook podría lanzar, así que el borrado va en un try/catch interno que degrada a
  "huérfano reportado" en vez de tumbar la ejecución completa. Sin fila de mapping ⇒ la
  actividad fallida es reanudable.
- **Preservación de metadatos** (`activity_builder`): `visible`,
  `visibleoncoursepage`, `groupmode`, `groupingid`, `availability` (cuando
  `enableavailability`), `intro`/`introformat`, `lang`, sección, y finalización
  (`completion*`, solo cuando `completion_info::is_enabled()`, como hace el formulario).
  **`idnumber` nunca se copia**: el cm origen sigue vivo en el mismo curso con ese
  idnumber, así que copiarlo garantizaría un duplicado a nivel de curso que rompe las
  búsquedas por idnumber. `completiongradeitemnumber` solo se traslada para el item
  overall (0) y solo si el destino agrega en `itemnumber 0` (los números por-iDevice no
  mapean entre plugins).
- **Validación post-extracción**: tras extraer, se exige que exista `content/{revision}/index.html`
  (entrada canónica de todo paquete eXeLearning v4). El área de contenido nunca queda
  realmente vacía porque la extracción inyecta los shims SCORM aunque el ZIP falle, así
  que la ausencia de `index.html` es la señal fiable de paquete corrupto; en ese caso se
  lanza `migrateextractfailed` y la limpieza compensatoria revierte el destino. Esto
  convierte el shell vacío silencioso de antes en un error limpio.
- **Eventos Moodle** (patrón [[DEC-26-03]], sin ruido): `migration_started` (una vez por
  ejecución, contexto sistema), `activity_migrated` (contexto del módulo destino),
  `activity_skipped` (con la razón de bloqueo) y `migration_failed` (con el error
  truncado a 255). `alreadymigrated` **no** dispara evento, para no inundar el log en
  reejecuciones.
- **Columnas de auditoría**: `exelearning_migration` gana `userid` (admin que ejecutó) y
  `timemodified`. Paso de upgrade `2026061201` (numerado por encima del recién
  renumerado `2026061200`), con backfill de filas pre-upgrade (`userid = 0`,
  `timemodified = timecreated`).
- **Preflight en la página admin**: antes del botón de ejecución se muestra total, ya
  migradas, migrables y bloqueadas por razón, sin extraer ningún paquete. Se sustituyó
  `progress_bar` por `\core\progress\display` (requiere `NO_OUTPUT_BUFFERING`, que la
  página antes no definía).

## Consecuencias

- La migración de `mod_exeweb` ahora funciona (antes fallaba el caso principal).
- Los fallos parciales no dejan huérfanos y son reanudables.
- El administrador ve por adelantado qué se migrará y qué no, y por qué.
- Las actividades migradas conservan visibilidad, grupos, disponibilidad, finalización e
  introducción del origen.
- Hay traza auditable (eventos + columnas `userid`/`timemodified`).

## Diferido

- **CLI** `cli/migrate.php --source=… --dry-run --execute` para instalaciones grandes:
  segunda iteración.
- **Caché MUC** de la clasificación por (cmid, contenthash) si el preflight escalara mal
  en sitios con miles de actividades.

## Verificación

- PHPUnit (Docker, Moodle 5.0.7 / PHP 8.3): suite del plugin en verde, incluidos los
  nuevos tests de `tests/local/migration/` (exeweb itemid=revision, clasificación
  exescorm, preservación de metadatos, reescalado de notas, idempotencia + auditoría,
  limpieza tras fallo parcial, preflight sin extraer, eventos).
- `phpcs --standard=moodle` 0 errores / 0 warnings sobre todo lo tocado.
- CI (matriz Moodle 4.5–5.2 × PHP 8.1–8.4 × pgsql/mariadb) valida además la ruta de
  upgrade desde versión anterior.
