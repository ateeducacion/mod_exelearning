---
id: FTE-010
titulo: "Comportamiento de Moodle core al editar el contenido de una actividad ya calificada (scorm/h5pactivity/quiz)"
categoria: api-moodle
version_consultada: "Moodle 5.0.1"
enlaces_oficiales:
  - https://docs.moodle.org/en/SCORM_FAQ
  - https://docs.moodle.org/en/SCORM_settings
  - https://supportus.moodle.com/support/solutions/articles/80001142558
  - https://docs.moodle.org/en/Quiz_grades_report
  - https://docs.moodle.org/dev/Question_bank_improvements_for_Moodle_4.0
  - https://tracker.moodle.org/browse/MDL-70329
  - https://docs.moodle.org/en/Content_bank
context7:
  library_id: "[PENDIENTE: context7]"
  query: "[PENDIENTE: context7]"
  fecha: null
  version_devuelta: "[PENDIENTE: context7]"
fecha_consulta: 2026-06-02
relevancia_para_mod_exelearning: "Define la política de core (snapshot, no recálculo silencioso, aviso opt-in) que mod_exelearning debe igualar al editar un .elpx calificado (DEC-12-01)."
herramienta_ia:
  interfaz: claude-code
  modelo: claude-opus-4-8
---

## Qué es

Recopilación de **cómo se comportan los módulos de actividad del core de Moodle**
(`mod_scorm`, `mod_h5pactivity`, `mod_quiz`) cuando el contenido de una actividad **ya
calificada** cambia: se añade una tarea, se borra, o se editan sus opciones/respuesta
correcta/puntuación. Foco en qué les pasa a los **intentos** y a las **notas** previas.

## Conceptos clave

- **Un intento es un *snapshot* del contenido en el momento en que se hizo.** Es el
  principio común de los tres módulos: las notas ya otorgadas **no cambian en silencio**
  al editar el contenido; el recálculo, cuando existe, es **explícito/opt-in**.
- Esto encaja con SCORM/xAPI/cmi5: los datos de runtime CMI y las *statements* xAPI son
  registros **históricos e inmutables** de "qué hizo el alumno entonces", no
  re-derivables del contenido actual.

## Comportamiento por módulo (verificado contra Moodle 5.0.1)

### mod_scorm — re-subir un paquete sobre la actividad existente
- Empareja SCOs por su `identifier` de manifiesto, **no por posición**
  (`mod/scorm/datamodels/scormlib.php::scorm_parse_scorm()`, ~líneas 598-722).
  - **Mismo `identifier`** → se **reusa** el `scorm_scoes.id` (comentario en código:
    *"keep id so that user tracks are kept against the same ids"*). Los tracks de
    `scorm_scoes_track` se **preservan**.
  - **SCO que desaparece** → `scorm_delete_tracks()` + borra la fila: los tracks se
    **destruyen**.
  - **SCO nuevo** → `insert_record` con `scoid` nuevo y **sin tracks**.
  - Los tracks existentes **nunca se recalculan**.
- **Avisa**: strings `confirmloosetracks` (*"the package seems to be changed... some
  users tracks may be lost"*) y `trackingloose` (`mod/scorm/lang/en/scorm.php`).
- `updatefreq`/auto-update aplica **solo a paquetes externos** (no a contenido editado);
  `forcenewattempt` es ciclo de vida del intento, no edición de contenido.

### mod_h5pactivity / core_h5p — reemplazar el .h5p
- `h5pactivity_set_mainfile()` (`mod/h5pactivity/lib.php` ~498-509) solo borra y reescribe
  el fichero del filearea; **no toca `h5pactivity_attempts` ni `*_results`**.
- Los intentos son **registros inmutables de statements xAPI**
  (`mod/h5pactivity/classes/local/attempt.php`); la nota del libro se deriva **solo** de
  los scores ya guardados (`grader::get_user_grades_for_gradebook()` →
  `get_users_scaled_score()`, `grader.php` ~144-176). **No hay recálculo.**
- **No versiona** y **no avisa** al reemplazar el contenido. Es el modelo **más débil**:
  conserva notas/intentos (coherente con snapshot) pero puede dejar la nota desalineada
  del contenido actual sin remediación ni aviso.

### mod_quiz — patrón de oro (versionado + recálculo opt-in)
- **Versionado de preguntas (Moodle 4.0+)**: editar una pregunta usada crea una **nueva
  versión**; *"once the student has started the quiz, Moodle records which version... and
  sticks with that version"*. Los intentos **fijan la versión** con la que se hicieron.
  Un slot puede fijar versión concreta o "Always latest"; aún así, los intentos
  cerrados/en curso conservan su versión hasta un recálculo explícito.
  (`docs/dev/Question_bank_improvements_for_Moodle_4.0`, MDL-70329.)
- **Recálculo ("Regrade attempts")**: acción **opt-in** del profesor, *"using the current
  version of each question if possible"*, con **dry-run** que previsualiza los cambios
  sin afectar a los intentos (`docs/en/Quiz_grades_report`).
- **Ediciones incompatibles** (p.ej. 5→4 opciones tras un intento): el regrade
  **falla con seguridad** y mantiene la pregunta como la vio el alumno; no corrompe el
  intento.

## Tabla resumen

| Módulo | Intentos al editar | Notas | ¿Recalcula? | ¿Avisa? |
|---|---|---|---|---|
| mod_scorm | preserva si el `identifier` no cambia; **borra** si el SCO se elimina | tal cual | nunca | **sí** |
| mod_h5pactivity | **preserva** (log xAPI inmutable) | tal cual | nunca; sin versionado | **no** |
| mod_quiz | **preserva** (el intento fija la versión) | sin cambio hasta regrade | **opt-in** + dry-run | **sí** |

## Implementaciones de referencia consultadas

- REPO-004 — `mod/scorm/datamodels/scormlib.php`, `mod/scorm/lang/en/scorm.php`,
  `mod/scorm/locallib.php`, `mod/scorm/lib.php`.
- REPO-004 — `mod/h5pactivity/lib.php`, `classes/local/grader.php`,
  `classes/local/attempt.php`.
- REPO-004 — quiz/question versioning (docs + MDL-70329).

## Riesgos / Limitaciones

- mod_exelearning **puntúa en el cliente** (eXeLearning) y el servidor solo guarda el
  `scaledscore` ya calculado (`track.php`): por tanto el **recálculo estilo quiz es
  arquitectónicamente imposible** (no se puede re-derivar la nota de un intento pasado
  contra el contenido nuevo). El espacio de diseño se reduce a *snapshot* + aviso +
  (opcional) invalidar/resetear intentos. Ver DEC-12-01.

## Preguntas abiertas

- Cierra parcialmente la pregunta abierta de FTE-006 ("política de re-upload: preservar
  `itemnumber` por `objectid` estable vs renumerar"): mod_exelearning preserva por
  `objectid` (DEC-0-12/DEC-5-01) y ahora avisa de la nota obsoleta (DEC-12-01).
