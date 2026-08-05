---
id: DEC-69-01
titulo: "Regla de finalización personalizada por estado (completionstatusrequired)"
estado: Aceptada
fecha: 2026-06-12
tracking_issue: 69
legacy_id: DEC-0052
agentes:
  - erseco
  - claude-code
fuentes:
  - REPO-004
relacionados:
  - DEC-25-01
  - DEC-67-01
  - DEC-34-01
herramienta_ia:
  interfaz: claude-code
  modelo: claude-opus-4-8
---

# DEC-69-01: Regla de finalización personalizada por estado (completionstatusrequired)

## Contexto

La auditoría estándar ([[DEC-67-01]], REPO-004) penaliza a `mod_exelearning` en el eje
de **amplitud de plataforma** frente a `mod_exescorm`: este último declara
`FEATURE_COMPLETION_HAS_RULES = true` y ofrece reglas de finalización personalizadas
(estado SCORM, puntuación, todos los SCO), mientras `mod_exelearning` devolvía `false`
y dependía exclusivamente de las condiciones nativas de Moodle (ver vista, recibir
nota, nota de aprobado). La tabla `exelearning_attempt` ya almacena un campo `status`
con los valores `completed|passed|failed|incomplete` (`db/install.xml`), de modo que
la información necesaria para una regla por estado ya existe y solo falta exponerla a
través del contrato de *custom completion* de Moodle.

## Problema

¿Cómo cerrar el hueco de "completion rules" respecto a `mod_exescorm` **sin** clonar la
complejidad multi-SCO que el propio informe critica, y **sin** crear mecanismos
solapados con la finalización por nota ya existente ([[DEC-25-01]], [[DEC-0010]])?

## Opciones consideradas

1. **No hacer nada** — seguir con `HAS_RULES = false`. Ventaja: cero coste. Inconveniente:
   mantiene el hueco de amplitud que la auditoría señala; el profesor no puede completar
   la actividad "al alcanzar el estado X" sin pasar por la nota.
2. **Clonar las tres reglas de `mod_exescorm`** (`completionstatusrequired`,
   `completionscorerequired`, `completionstatusallscos`). Ventaja: paridad literal.
   Inconveniente: `completionscorerequired` duplica la finalización por nota ([[DEC-25-01]],
   [[DEC-0010]]) — dos vías para lo mismo, justo el antipatrón que el informe critica — y
   `completionstatusallscos` es un concepto multi-SCO ajeno a eXe (no hay varios SCO por
   módulo). Coste y superficie altos.
3. **Una sola regla por estado, a nivel de módulo** (elegida) — `completionstatusrequired`
   con tres niveles de exigencia (aprobado / completado / cualquiera). Ventaja: cierra el
   eje exacto que penaliza la auditoría con coste mínimo, reutiliza el campo `status` ya
   persistido y respeta la abstracción de completion de Moodle (un estado por módulo).
   Inconveniente: no replica literalmente las tres reglas de exescorm (decisión deliberada).

## Análisis

Valoración del eje que cierra esta decisión (matriz comparativa de *completion rules*):

| Regla | `mod_exescorm` | `mod_exelearning` (antes) | `mod_exelearning` (esta ADR) | Justificación |
|-------|----------------|--------------------------|------------------------------|----------------|
| `HAS_RULES` | `true` | `false` | **`true`** | Cierra el hueco de amplitud (REPO-004 / [[DEC-67-01]]). |
| Estado requerido | sí (bitmask de estados SCORM) | — | **sí** (aprobado/completado/cualquiera) | El campo `status` ya existe en `exelearning_attempt`. |
| Puntuación requerida | sí | — (vía nota) | **no** | Solapa con completion-by-grade ([[DEC-25-01]], [[DEC-0010]]); dos vías = antipatrón. |
| Todos los SCO | sí | — | **no** | Concepto multi-SCO; eXe es un módulo = un estado. |

Por qué **solo estado**: la finalización "por nota mínima" ya está cubierta de forma
nativa (`completionpassgrade` + `gradepass`, [[DEC-0010]]) y el modelo de notas
per-iDevice/overall ([[DEC-25-01]]) permite apuntar la condición a un ítem concreto. Añadir
`completionscorerequired` crearía dos caminos para "completar al superar una puntuación",
divergentes en aristas (qué ítem, qué agregación), que es precisamente la duplicación que
la auditoría reprocha a exescorm. La regla por estado, en cambio, no tiene equivalente
nativo y aprovecha datos que el pipeline de tracking ([[DEC-34-01]]) ya escribe.

Alineación con la abstracción de Moodle: [[DEC-67-01]] **rechazó** la finalización
*por iDevice* (varios estados de finalización dentro de un mismo módulo) por pelearse con
el modelo de Moodle, donde la finalización es **un estado por course_module**. La regla de
esta ADR es **a nivel de módulo** (un único estado requerido para toda la actividad), de
modo que **no entra en conflicto** con ese rechazo: encaja exactamente en
`core_completion\activity_custom_completion`, igual que mod_scorm/mod_quiz.

## Evidencia

- **REPO-004 / [[DEC-67-01]]**: la auditoría sitúa el hueco de *completion rules*
  (`HAS_RULES` exescorm `true` vs exelearning `false`) como mejora de amplitud pendiente.
- **`db/install.xml`** (tabla `exelearning_attempt`): el campo `status` con
  `completed|passed|failed|incomplete` ya está persistido por el pipeline de tracking.
- **`mod/scorm/lib.php` / `mod/scorm/classes/completion/custom_completion.php`** (clon de
  Moodle en `../moodle`): patrón canónico de `*_supports`, `*_get_coursemodule_info`,
  `add_completion_rules`/`completion_rule_enabled` y la subclase de
  `activity_custom_completion`, que esta implementación reproduce a escala de una regla.
- **[[DEC-25-01]] / [[DEC-0010]]**: la finalización por nota ya existe, lo que motiva
  **descartar** `completionscorerequired`.

## Decisión

Implementar la **opción 3**: una única regla de finalización personalizada
`completionstatusrequired`, a nivel de módulo, con tres niveles (aprobado / completado /
cualquiera de los dos). Contrato completo de Moodle:

- `db`: columna `completionstatusrequired` INT(1) *nullable* en `exelearning`
  (`db/install.xml` + upgrade stage 17, `2026061202`). `NULL` deshabilita la regla.
  Constantes `EXELEARNING_COMPLETIONSTATUS_PASSED|COMPLETED|ANY` en `lib.php`.
- `lib.php`: `exelearning_supports(FEATURE_COMPLETION_HAS_RULES) → true`;
  `exelearning_get_coursemodule_info()` expone
  `customdata['customcompletionrules']['completionstatusrequired']` (solo con completion
  automática); persistencia del campo en add/update_instance.
- `mod_form.php`: `add_completion_rules()` (checkbox + selector de estado),
  `completion_rule_enabled()` y `data_postprocessing()`.
- `classes/completion/custom_completion.php`: `extends activity_custom_completion`;
  `get_state()` devuelve `COMPLETION_COMPLETE` si hay una fila **overall** (`itemnumber = 0`)
  en `exelearning_attempt` del usuario con `status IN (...)` según la exigencia, si no
  `COMPLETION_INCOMPLETE`. El filtro `itemnumber = 0` es **obligatorio**: las filas por-iDevice
  (`itemnumber > 0`) las graba `track::apply_one()` con `status = 'completed'` fijo, así que sin
  el filtro un solo iDevice puntuado completaría la actividad aunque el intento overall esté
  `failed`/`incomplete`. Solo la fila overall lleva el `lesson_status` real.
- Backup/restore: el campo entra en la lista de campos del backup
  (`backup_exelearning_stepslib.php`), de modo que la regla sobrevive el roundtrip.

**Descartado** explícitamente: `completionscorerequired` (solapa con [[DEC-25-01]]) y
`completionstatusallscos` (multi-SCO, ajeno a eXe).

## Consecuencias

- **Positivas**: cierra el eje de *completion rules* frente a exescorm; reutiliza datos ya
  persistidos; superficie mínima (una columna nullable, una subclase, cambios localizados
  en `lib.php`/`mod_form.php`); aditivo y retrocompatible (`NULL` = comportamiento previo).
- **Negativas / coste**: una columna y un *upgrade savepoint* más; el profesor dispone de
  una condición adicional que debe entender (documentada en `docs/GRADEBOOK.md`).
- **Cambios que dispara**: ninguno pendiente; convive con la finalización por nota
  ([[DEC-0010]], [[DEC-25-01]]) sin solaparse.

## Riesgos

- **RIE-014** (severidad baja, probabilidad baja): un cambio del estado requerido tras
  haber intentos ya registrados podría recalcular la finalización de forma inesperada para
  el alumnado ya evaluado. **Mitigación**: `data_postprocessing()` solo persiste el valor
  con completion automática activa; `track::ingest()` recalcula la finalización en cada
  commit, de modo que el estado se mantiene coherente con los intentos reales; el campo es
  nullable y por defecto `NULL` (regla desactivada), preservando el comportamiento previo.
- **RIE-015** (severidad baja, probabilidad baja): divergencia futura respecto al patrón de
  `mod_scorm` si Moodle cambia el contrato de `activity_custom_completion`. **Mitigación**:
  la implementación es un calco fino del patrón canónico (REPO-004), cubierto por la suite
  de PHPUnit y el gate de cobertura ([[DEC-66-01]]).

## Validación

- `tests/completion_test.php` (`advanced_testcase`): regla disponible cuando está activa;
  sin intento / estado `incomplete` → `COMPLETION_INCOMPLETE`; intento `passed`/`completed`
  vía `track::ingest()` → `COMPLETION_COMPLETE`; las exigencias `passed`/`completed`
  discriminan el estado correcto; `exelearning_supports(FEATURE_COMPLETION_HAS_RULES)` es
  `true`.
- `tests/backup_restore_test.php`: el roundtrip preserva `completionstatusrequired`.
- `phpcs --standard=moodle` 0/0 sobre los ficheros tocados; PHPUnit no corre en local
  (memoria `phpunit_local`) → CI + Codecov patch ≥80% ([[DEC-66-01]]) validan la suite.

## Seguimiento

- Cierra el hueco de *completion rules* identificado en [[DEC-67-01]].
- No abre tareas; `completionscorerequired`/`completionstatusallscos` quedan descartadas y
  documentadas para no re-evaluarlas.
