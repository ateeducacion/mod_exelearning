---
id: AN-013
titulo: "Reuso de tests Behat de plugins hermanos (mod_scorm, mod_h5pactivity, mod_exescorm, mod_exeweb) en mod_exelearning"
fecha: 2026-06-13
fuentes:
  - REPO-001
  - REPO-002
  - REPO-004
relacionados:
  - DEC-74-01
  - DEC-0-08
  - DEC-0-07
  - DEC-5-01
  - AN-008
  - AN-009
herramienta_ia:
  interfaz: claude-code
  modelo: claude-opus-4-8
---

## Resumen

Se cataloga qué escenarios Behat de `mod_scorm` y `mod_h5pactivity` (core),
`mod_exescorm` y `mod_exeweb` pueden **copiarse o adaptarse** a `mod_exelearning`.
Conclusión: lo más reutilizable son los flujos genéricos de actividad
(duplicar/borrar, ajustes del form, notas al gradebook), no la lógica específica
de cada estándar. Se implementan 3 escenarios de bajo riesgo (sin `@javascript`,
basados en generador); se descartan explícitamente el alta-por-UI con subida de
fichero y la disponibilidad por `timeopen/timeclose` por incompatibilidad real
con este plugin.

## Hechos citados

Inventario de los `tests/behat/` consultados (rutas locales, lectura directa):

- `mod_exescorm` (REPO-001): 8 features derivadas de SCORM — `add_exescorm`,
  `exescorm_activity_completion`, `exescorm_availability`,
  `completion_condition_require_status`, `multisco_review_mode`, `missing_org`,
  `save_progress_on_unload`, `exescorm_no_calendar_capabilities`. Sin generador
  de entidades propio; crea la actividad con `the following "activities" exist`
  (`activity: exescorm`, `packagefilepath`, `timeopen`, `timeclose`). Las
  features referencian `singlesco_exescorm12.zip`, **fixture inexistente** en
  `tests/packages/` (bug latente del repo origen).
- Moodle core (REPO-004): `mod/scorm/tests/behat` (13 features) y
  `mod/h5pactivity/tests/behat` (22 features). Aportan los pasos genéricos
  `I duplicate "X" activity`, `I delete "X" activity`,
  `I navigate to "View > Grader report" in the course gradebook`,
  `the following should exist in the "user-grades" table`, y el patrón de
  override de permisos (`scorm_no_calendar_capabilities.feature`).
- `mod_exeweb` (REPO-002): 2 features (`display_exeweb`,
  `exeweb_activity_completion`); es un recurso, no una actividad calificable →
  reuso bajo.

Infra ya disponible en `mod_exelearning` (no requiere cambios):

- `tests/generator/lib.php` acepta `packagefilepath`, `grademethod`,
  `grademodel`, `gradeenabled`, `maxattempt`, `reviewmode`, `groupmode`; fixture
  por defecto `research/fixtures/elpx/actividad-evaluable.elpx`.
- `tests/behat/behat_mod_exelearning.php` define el paso
  `the following eXeLearning SCORM scores exist` (siembra determinista sin UI).
- `tests/behat/mod_exelearning.feature` ya cubre, **sin `@javascript`**, la vista
  del profesor, el informe de intentos (vacío, multipágina, ruteo por objectid,
  borrado, separate-groups y selector de descarga).
- El camino de notas cruza al gradebook: `track::apply_item_scores()`
  (`classes/local/track.php:470`) → `apply_one()` → `grade_update()`
  (`classes/local/track.php:661`) en modelo per-item (DEC-0-08). Existe
  `backup/moodle2/` (la duplicación de actividad es válida).

## [INTERPRETACION]

Matriz de reuso (de mayor a menor), evaluada contra el caso de uso real del
plugin (módulo de actividad que embebe el editor y califica vía tracking
SCORM/xAPI):

| Origen | Feature | Veredicto | Por qué |
|---|---|---|---|
| h5pactivity | `duplicate_delete_h5pactivity` | **Adaptar (implementado)** | Duplicar/borrar es UI genérica de Moodle; prueba backup/restore + ciclo de vida de grade items. Sin `@javascript`. |
| h5pactivity | `h5pactivity_grade_settings` (aserción user-grades) | **Adaptar (implementado)** | Verifica que la nota llega al grader report; se mapea al pipeline propio (per-item, `grade_update`). |
| h5pactivity | `h5pactivity_grade_settings` (form) | **Adaptar (implementado, reducido)** | El generador no persiste ajustes; round-trip en el form con `id_maxattempt` (campo propio DEC-0-07) por id, independiente del texto de etiqueta. |
| scorm/exescorm | `*_no_calendar_capabilities` | **Aplazar** | Solo cobra sentido con un campo que genere eventos de calendario; el análogo aquí sería `completionexpected`, valor bajo. |
| core | `*_availability` (restrict access) | **Aplazar** | La disponibilidad por restricción de acceso es core, no del módulo; requiere `@javascript` (Add restriction). |
| exescorm | `add_exescorm` (alta por UI + upload) | **Descartar** | Exige `@javascript` + filemanager + `I switch to "..._object" iframe`; el plugin evita `@javascript` porque el iframe del paquete + el shim SCORM dejan XHR pendientes que cuelgan el driver JS (documentado en `mod_exelearning.feature` y en AN-008). El alta se cubre por generador. |
| exescorm | `exescorm_availability` (`timeopen/timeclose`) | **Descartar** | `mod_exelearning` **no** tiene campos `timeopen/timeclose` ni texto "Opened:/Closes:". |
| h5pactivity | content bank, edición inline, tipos de resultado, deployment | **Descartar** | Específicos de H5P; sin equivalente. |
| exeweb | `display_exeweb`, completion de recurso | **Descartar** | Recurso, no actividad calificable. |

Licencias (Rule 12): todas las fuentes son GPLv3 (Moodle core + plugins
ateeducacion) → compatibles. **No se copia ni vendora** ningún fichero externo;
los escenarios nuevos usan exclusivamente fixtures propias.

## Consecuencias para `mod_exelearning`

- Implementados en esta tanda (sin `@javascript`, basados en generador):
  - `tests/behat/mod_exelearning_management.feature`: duplicar+borrar la
    actividad (la copia debe seguir mostrando "Gradable iDevices detected:") y
    round-trip de `id_maxattempt` en el form de edición.
  - `tests/behat/mod_exelearning_grades.feature`: las puntuaciones sembradas
    aparecen en el grader report (modelo per-item; se siembran ambos iDevices de
    `multipage-gradable.elpx` al mismo valor para que el número aparezca sea cual
    sea el orden de columnas).
- No se añaden generadores de entidades (`behat_mod_exelearning_generator.php`):
  el paso `the following eXeLearning SCORM scores exist` ya cubre la siembra.

## [PENDIENTE]

- **CI manda**: Behat no corre en local (sin árbol Moodle dev). Validar los 3
  escenarios en `moodle-plugin-ci behat --profile chrome` y corregir desajustes
  de texto/pasos allí. Puntos de mayor riesgo: que el duplicado conserve el
  paquete y que el grader report renderice "80.00".
- Si en el futuro se implementa xAPI (DEC-17-01), revisar `sending_attempt.feature`
  / `save_content_state.feature` de h5pactivity como candidatos a adaptar.
