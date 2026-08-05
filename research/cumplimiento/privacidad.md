# Privacidad — mod_exelearning

Objetivo: cumplir **GDPR** y la normativa española aplicable a menores.

Estado: el **privacy provider está implementado por completo**. La fuente de verdad es
`classes/privacy/provider.php`; este documento describe el comportamiento real del código,
no una hipótesis.

## Datos personales almacenados (estado actual)

El plugin guarda datos personales de **alumnado** en `exelearning_attempt` (historial de
intentos plano, DEC-0007) y datos de **profesorado/gestores** en `exelearning_migration`
(traza de auditoría de la herramienta de migración, DEC-13-05/DEC-13-12). `provider::get_metadata()`
declara ambas campo a campo y añade un enlace al subsistema `core_grades`, porque el plugin
publica las puntuaciones de cada usuario en el libro de calificaciones de Moodle mediante
`grade_update()`.

Campos declarados en los metadatos (`exelearning_attempt`, ver `db/install.xml`):

| Campo | Significado |
|---|---|
| `userid` | Usuario al que pertenece el intento. |
| `attempt` | Número de intento (base 1) por usuario y actividad. |
| `itemnumber` | `0` = nota global agregada; `>0` = iDevice calificable. |
| `rawscore` | Puntuación bruta. |
| `maxscore` | Puntuación máxima. |
| `scaledscore` | `rawscore/maxscore` en el rango `0..1`. |
| `status` | `completed` / `passed` / `failed` / `incomplete`. |
| `timecreated` / `timemodified` | Marcas de tiempo. |

Además, `provider::get_metadata()` declara el campo `usermodified` de la tabla `exelearning`
(qué usuario editó por última vez la configuración de la actividad) y el enlace al subsistema
`core_grades` para el flujo hacia el libro de calificaciones.

`sessiontoken` existe en la tabla pero **no** se exporta: es un token de correlación por carga
de página, no identificativo del usuario.

## Tabla de migración (`exelearning_migration`) — auditoría a nivel de sistema

`exelearning_migration` es un mapa de **auditoría e idempotencia** de la herramienta de
migración (qué actividad de `mod_exeweb`/`mod_exescorm` se migró a `mod_exelearning`, con
índice único `(sourcecomponent, sourcecmid)`). Guarda `userid`: el gestor que ejecutó la
migración (`migration_service.php`, `$USER->id`). La migración es una operación a **nivel de
sistema** (evento `migration_started` en `context_system`), así que el provider trata esta
tabla en **`context_system`**, no en el contexto de módulo:

- `get_metadata()` declara `userid`, `sourcecomponent`, `sourcecmid`, `targetcmid`,
  `timecreated`, `timemodified`.
- `get_contexts_for_userid()` devuelve el contexto de sistema si el usuario aparece como
  gestor en alguna fila; `get_users_in_context()` lista esos `userid` (excluyendo el 0).
- `export_user_data()` exporta las filas del gestor bajo el subcontexto
  «Activity migrations».
- **Borrado = anonimización, no borrado de fila.** Las tres rutas de borrado ponen
  `userid = 0` (el centinela «desconocido/pre-upgrade» ya definido en `install.xml`)
  conservando la fila. Borrar la fila rompería la idempotencia (re-migrar el mismo origen
  duplicaría la actividad destino). Esto sigue la guía oficial del Privacy API («borrar *o*
  *overwritten if a structure needs to be maintained*») y el patrón de Moodle core: `core_tag`
  anonimiza `userid → 0` en `context_system` («Do not delete … in case they are used by
  somebody else»), y `gradepenalty_duedate`/`quizaccess_seb` anonimizan `usermodified → 0`
  sobre filas estructurales.

## Interfaces implementadas

`provider` implementa los tres interfaces del Privacy API
(`core_privacy\local\metadata\provider`, `core_privacy\local\request\core_userlist_provider`,
`core_privacy\local\request\plugin\provider`):

- **Metadatos**: `get_metadata()` declara `exelearning_attempt`, el `usermodified` de
  `exelearning` y el enlace a `core_grades`.
- **Búsquedas inversas**: `get_contexts_for_userid()` (contextos con datos del usuario) y
  `get_users_in_context()` (usuarios con datos en un contexto), ambas sobre
  `exelearning_attempt` unido a `course_modules`/`context`.
- **Exportación**: `export_user_data()` recorre los contextos de módulo aprobados, lee los
  intentos del usuario ordenados por `attempt, itemnumber` y los escribe con
  `writer::with_context()`. Las marcas de tiempo se normalizan con
  `\core_privacy\local\request\transform::datetime()`.
- **Borrado** (tres puntos de entrada, todos recalculan el libro de calificaciones tras borrar
  los intentos, vía `clear_grades_for_users()` → `exelearning_recalculate_user_grades()`, para
  que ningún usuario borrado conserve una nota sin intento que la respalde):
  - `delete_data_for_all_users_in_context()` — borra todos los usuarios de un contexto.
  - `delete_data_for_user()` — borra un usuario en los contextos aprobados.
  - `delete_data_for_users()` — borra un conjunto de usuarios en un contexto.

## Relación con el libro de calificaciones

Las notas no se almacenan como dato propio del plugin: se derivan de `exelearning_attempt` y se
publican en `core_grades` mediante `grade_update()`. Por eso el provider declara el enlace al
subsistema `core_grades` (es Moodle core quien gestiona la exportación/borrado de las notas) y
cada ruta de borrado del plugin recalcula la nota para no dejar residuos.

## xAPI — roadmap, no es dato actual

El plugin **no** almacena statements xAPI hoy. El tracking actual es 100% shim SCORM 1.2 y la
ingesta xAPI es un plan (`docs/xapi-integration-plan.md`, DEC-17-01), pendiente de PR2/TAREA-015.
Cuando se implemente, las decisiones de privacidad a tomar serán:

- Si se persisten statements completos (con `result.response`) o solo el resumen
  `(verb, scaled)` necesario para calificar.
- El diseño anonimiza el `actor` en el cliente y atribuye el intento a `$USER` en el servidor,
  por lo que el provider de privacidad seguiría operando sobre columnas normalizadas
  (`exelearning_attempt`), no sobre JSON crudo. Una eventual tabla de auditoría
  (`exelearning_tracking_events`) tendría que añadirse a los metadatos en ese momento.

## Datos de menores

Moodle se despliega habitualmente en centros con menores. Implicaciones que el diseño respeta:

- No se envían datos a servicios externos por defecto (no hay LRS externo; está fuera de
  alcance, `docs/xapi-integration-plan.md` §7).
- El emisor xAPi previsto nunca expone `actor.mbox`: usa `actor.account` con `homePage` de
  Moodle (cuando exista la ruta xAPI).
- Política de retención: documentar si se borran los intentos al final del curso académico
  (responsabilidad del administrador del centro, no del plugin).

## Pendientes

- [PENDIENTE] Confirmar la política de logs de Moodle cuando se implemente xAPI: si
  `statement.id` u otros identificadores aparecen en logs con datos personales.
- [PENDIENTE] Al implementar xAPI (PR2), ampliar `get_metadata()` y las rutas de
  exportación/borrado si se añade una tabla de auditoría de statements.
