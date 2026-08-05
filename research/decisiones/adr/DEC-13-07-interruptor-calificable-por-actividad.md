---
id: DEC-13-07
titulo: "Interruptor 'Calificable' por actividad (issue #13)"
estado: Aceptada
fecha: 2026-06-03
tracking_issue: 13
legacy_id: DEC-0029
agentes:
  - erseco
  - claude-code
fuentes:
  - REPO-004
relacionados:
  - DEC-0008
  - DEC-13-01
herramienta_ia:
  interfaz: claude-code
  modelo: claude-opus-4-8
---

# DEC-13-07: Interruptor 'Calificable' por actividad (issue #13)

## Contexto

A veces una actividad eXeLearning es **solo contenido** (sin evaluación) y no se quiere que
aparezca en el libro de calificaciones ni genere informes. La detección por `isScorm`
(DEC-13-01) decide qué iDevices puntúan, pero faltaba un control a nivel de **actividad** para
desactivar la calificación por completo. El usuario lo pidió como "un check, activado por
defecto; si se desactiva, no muestra ni informes ni nada en el libro".

## Decisión

Campo nuevo **`gradeenabled`** en la tabla `exelearning` (INT, **default 1**).

- **`mod_form.php`:** checkbox "Graded activity" (`advcheckbox 'gradeenabled'`, default 1) +
  `disabledIf` de todos los campos de nota cuando está desmarcado.
- **`gradeenabled = 0`:** `exelearning_sync_grade_items()` corta y llama a
  `exelearning_remove_all_grade_items()` — **soft-delete** de las filas
  `exelearning_grade_item` (`deleted=1`) + borrado de los `grade_item` de Moodle (incluido el
  overall, itemnumber 0) → nada en el libro. `view.php` oculta el banner de iDevices
  detectados, el resumen de participación y el resumen de intentos del alumno; el nodo
  "Informes" de la navegación no se añade. La actividad se comporta como un recurso.
- **Conservación (respuesta del usuario "ocultar y conservar"):** los intentos
  (`exelearning_attempt`) **no** se tocan, así que reactivar `gradeenabled` re-detecta y
  recalcula desde el historial.

## Consecuencias

- Cambio de esquema: `gradeenabled` + etapa de upgrade (2026060400) + bump de versión.
- Desactivar una actividad con notas existentes quita las columnas del libro pero preserva los
  intentos (no destruye datos del alumno).
- `completionpassgrade` (DEC-0010) no debe usarse con `gradeenabled=0` (se elimina el overall);
  si la actividad no califica, no procede la finalización por nota.

## Implementación

`db/install.xml` + `db/upgrade.php` (2026060400) + `version.php`; `mod_form.php` (checkbox +
disabledIf); `lib.php` (`exelearning_sync_grade_items` gate + `exelearning_remove_all_grade_items`;
nodo de informes); `view.php` (gating de banners/resúmenes); `lang/en/exelearning.php`
(`gradeenabled`, `gradeenabled_help`); tests `tests/lib_test.php`
(`test_gradeenabled_off_creates_no_grade_items`,
`test_gradeenabled_toggle_off_softdeletes_and_preserves_attempts`).
