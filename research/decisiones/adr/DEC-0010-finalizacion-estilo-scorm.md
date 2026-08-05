---
id: DEC-0010
titulo: "Condiciones de finalización estilo SCORM (aprobar para completar)"
estado: Aceptada
fecha: 2026-05-28
agentes:
  - erseco
  - claude-code
fuentes:
  - REPO-004
experimentos: []
herramienta_ia:
  interfaz: claude-code
  modelo: claude-opus-4-8
relacionados:
  - DEC-0006
  - DEC-0007
  - DEC-0008
---

# DEC-0010: Condiciones de finalización estilo SCORM (aprobar para completar)

## Contexto

El usuario pide que la **finalización de la actividad** `mod_exelearning` se
comporte "como en SCORM: que se apruebe". Es decir, que Moodle marque la
actividad como completada cuando el alumno alcanza la nota de aprobado, igual
que `mod_scorm` permite completar por estado (`passed`/`completed`) o por nota
mínima (`completionscorerequired`).

## Problema

`mod_scorm` tiene condiciones de finalización propias
(`completionstatusrequired`, `completionscorerequired`) ligadas al data model
CMI. `mod_exelearning` NO arrastra el data model SCORM (ver DEC-0007), así que
replicarlas tal cual no aplica. ¿Qué mecanismo de "completar al aprobar"
usamos?

## Opciones consideradas

| # | Mecanismo | Pros | Contras |
|---|---|---|---|
| A | Condición custom del módulo (`FEATURE_COMPLETION_HAS_RULES` + `*_get_completion_state`) replicando `completionstatusrequired` | Control total; UI específica | Más código; reinventa lo que el core ya ofrece para módulos con nota |
| B | **Condición core "exigir nota para aprobar"** (`completionpassgrade`) | Es estándar Moodle (3.11+), aparece sola en el formulario para módulos con `FEATURE_GRADE_HAS_GRADE`; usa `grade_item.gradepass` | Necesita una "nota para aprobar" definida en el item overall |
| C | Sólo "exigir nota" (`completionusegrade`, cualquier nota) | Lo más simple | No distingue aprobado de suspenso → no es "que se apruebe" |

## Decisión

**Opción B.** La finalización "estilo SCORM = aprobar" se implementa con la
condición **core `completionpassgrade`** ("exigir nota para aprobar"), que
Moodle evalúa contra `grade_item.gradepass` del item overall (`itemnumber=0`).

Concretamente:

1. La instancia gana un campo `gradepass` (decimal) y el formulario un input
   "Nota para aprobar". `exelearning_grade_item_update()` lo propaga al
   `grade_item` overall (`'gradepass' => $instance->gradepass`).
2. Como `mod_exelearning` declara `FEATURE_GRADE_HAS_GRADE`, el bloque estándar
   de finalización ya muestra "El estudiante debe recibir una nota" y "exigir
   nota para aprobar" sin código adicional del módulo.
3. `track.php`, tras escribir la nota agregada con `grade_update()`, fuerza la
   reevaluación de finalización:
   `(new completion_info($course))->update_state($cm, COMPLETION_UNKNOWN, $userid)`.
4. No se añade `FEATURE_COMPLETION_HAS_RULES` (queda en `false`): no hace falta
   una condición custom.

### Equivalencia con SCORM

| SCORM | mod_exelearning |
|---|---|
| `completionscorerequired = N` | `gradepass = N` + `completionpassgrade = 1` |
| `completionstatusrequired = passed` | `completionpassgrade = 1` (aprobar = pasar) |
| `whatgrade` (highest/average/...) | `grademethod` (DEC-0007) |

## Demo (setup_demo.php + blueprint.json)

Para que el comportamiento sea visible y comparable, el curso `EXEDEMO`
incluye **tres** actividades evaluables con la MISMA condición de finalización
(`completion=automatic`, `completionusegrade=1`, `completionpassgrade=1`,
`gradepass=50`):

- `mod_exelearning` — el paquete `.elpx` con 2 iDevices calificables.
- `mod_scorm` — el paquete SCORM 1.2 `actividad-evaluable_scorm.zip`
  (`research/fixtures/scorm/`). Aporta el modelo de intentos/finalización
  nativo de SCORM como referencia.
- `mod_h5pactivity` — `question-set-demo.h5p`
  (`research/fixtures/h5p/`, un Question Set con varias preguntas). Aporta
  intentos múltiples + reporte nativos de H5P como referencia.

`setup_demo.php` habilita `enablecompletion` en el curso antes de crear las
actividades (requisito para que las condiciones por nota apliquen).

## Consecuencias

Positivas:
- Cero código de finalización custom; 100 % estándar Moodle.
- Coherente entre los tres módulos del demo → el profesor compara
  exelearning / SCORM / H5P con la misma semántica de "aprobar para completar".

Negativas:
- Requiere que el profesor fije una "nota para aprobar" (`gradepass`); si la
  deja en 0 la condición existe pero no dispara. Documentado en el help string
  `gradepass_help`.

## Validación

- `setup_demo.php` en Docker crea exelearning/scorm/h5pactivity con
  `course_modules.completion=2, completionusegrade=1, completionpassgrade=1`
  (verificado por consulta a `mdl_course_modules`).
- Alumno que alcanza ≥ 50 en el overall → actividad marcada como completada.
- `blueprint.json` replica los tres `addModule` con los mismos campos de
  finalización para el Moodle Playground.

## Seguimiento

- Verificación e2e por navegador de la marca de completado tras aprobar:
  **cerrada en TAREA-011 (2026-06-01)** con Google Chrome + Docker. La prueba
  cubre `grademodel=peritem` y `grademodel=overall`, ambos con
  `completionstate=2` tras alcanzar 80/100.

## Revisión 2026-06-01 — compatibilidad con `peritem`

La sesión e2e de TAREA-011 confirmó que DEC-0010 depende de que exista un
`grade_item` overall evaluable. Para mantener la UX de DEC-0008 y la semántica
SCORM-style de esta ADR, `peritem` conserva un overall oculto y `track.php`
publica ahí la nota agregada. Moodle core puede evaluar `completionpassgrade`,
pero el profesor sigue viendo las columnas por iDevice como presentación normal
del libro.
