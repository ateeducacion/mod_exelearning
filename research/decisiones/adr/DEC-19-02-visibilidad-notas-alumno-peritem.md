---
id: DEC-19-02
title: "Coherencia profesor/alumno en peritem: excluir la nota overall oculta de la agregación para no vaciar el total del alumno"
status: Superseded
date: 2026-06-04
tracking_issue: 19
legacy_id: DEC-0035
superseded_by: DEC-25-01
deciders:
  - erseco
  - claude-code
sources:
  - REPO-004
  - FTE-012
related:
  adrs: [DEC-0-08, DEC-0-10, DEC-5-01, DEC-6-01]
ai_assistance:
  tool: claude-code
  model: claude-opus-4-8
---

# DEC-19-02: Coherencia profesor/alumno en peritem: excluir la nota overall oculta de la agregación para no vaciar el total del alumno

## Contexto

La experta en usabilidad del INTEF reporta que, en un recurso eXe con **varios
elementos evaluables**, los elementos de calificación que ve el rol
**administrador/profesor no son los mismos** que ve el **alumno**: el alumno resuelve
las actividades pero **"no aparecen" cuando consulta sus calificaciones**.

Estado del código: en el modelo `peritem` (por defecto, DEC-0-08),
`exelearning_sync_grade_items()` (`lib.php`) crea el item overall `itemnumber=0`
**oculto** (`hidden=1`, líneas ~940-943) y lo mantiene únicamente para que la
finalización por nota de aprobado del core (`completionpassgrade`, DEC-0-10) tenga un
item con `gradepass`. Las columnas por-iDevice se crean **visibles**. La nota overall se
escribe en `track.php` (y en `exelearning_recalculate_user_grades`) también oculta.

## Problema

¿Por qué difieren las vistas y por qué el alumno percibe que sus notas no aparecen?

**Causa raíz (FTE-012):**

1. El profesor tiene `moodle/grade:viewhidden` y **ve** el item overall oculto; el
   alumno no → la **lista de columnas difiere** por ese item oculto.
2. Más grave: un item **oculto que sigue agregando** al total hace que Moodle, con el
   ajuste de sitio `grade_report_user_showtotalsifcontainhidden` en su **default de
   fábrica `GRADE_REPORT_HIDE_TOTAL_IF_CONTAINS_HIDDEN` (0)**, **vacíe el total** del
   alumno (`grade_report::blank_hidden_total_and_adjust_bounds` pone `finalgrade=null`).
   El alumno ve sus columnas por-iDevice pero su total aparece "-", de ahí "no aparecen".

Además, la overall (media de las por-iDevice × grademax) y las por-iDevice agregan a la
vez → riesgo de **doble conteo** en el total del curso.

## Opciones consideradas

- **A. Arreglar la vista del alumno (elegida).** Mantener peritem; el overall sigue
  oculto para la finalización pero se **excluye de la agregación** por-nota
  (`grade_grade::set_excluded(true)`). El alumno ve sus N notas por-iDevice y un total
  correcto; el profesor ve lo mismo + el item de finalización (ya inofensivo). Bajo
  riesgo, respeta DEC-0-08/DEC-0-10.
- **B. Overall visible para ambos.** Hacer visible el overall también al alumno (mismas
  columnas en ambos roles), ajustando la agregación para no duplicar. Más intrusivo en la
  lógica de agregación; cambia el modelo.
- **C. Cambiar el ajuste del sitio/curso** `showtotalsifcontainhidden`. Descartada: no es
  responsabilidad de un módulo cambiar ajustes globales del gradebook.

**Decisión del usuario (2026-06-04): opción A.**

## Decisión

- **D1.** En modo `peritem`, tras escribir la nota overall (`itemnumber=0`) de un usuario,
  marcarla como **excluida de la agregación** con `grade_grade::set_excluded(true)`. Helper
  `exelearning_exclude_overall_grade($instance, $userid)` (`lib.php`), llamado desde:
  - `track.php` (tras el `grade_update` del overall), y
  - `exelearning_recalculate_user_grades()` (tras republicar el overall).
  Es no-op en modo `overall` (allí el overall **es** la nota visible) e idempotente
  (`is_excluded()` guard).
- **D2.** Migración de datos en el stage `2026060401` de `db/upgrade.php`: excluir las
  notas overall ya existentes de las instancias `peritem`, para desbloquear los totales en
  upgrades reales.
- **D3.** **No** se fuerza `hidden=0` en las columnas por-iDevice: ya son visibles y
  forzarlo sobrescribiría un ocultado manual del profesor (`grade_update` respeta `hidden`
  si no se pasa).

Por qué funciona (FTE-012, H2/H3): las notas excluidas se descartan en **todos** los
métodos de agregación (`grade_category::aggregate_grades`) y `get_hiding_affected()`
**salta** las excluidas (`if ($grade_grade->is_excluded()) continue;`), de modo que el
total deja de considerarse "con ocultos" y **se muestra** al alumno. `set_excluded` no
toca `finalgrade`/`gradepass`, así que `completionpassgrade` sigue intacto.

## Evidencia

- REPO-004 (este repo): `lib.php:940-943` (overall `hidden=1` en peritem),
  `lib.php` per-item sin `hidden`, `track.php` (overall `hidden` condicional),
  `exelearning_recalculate_user_grades`.
- FTE-012 (H2/H3): `grade_grade::set_excluded`/`is_excluded`/`get_hiding_affected`
  (`lib/grade/grade_grade.php`), filtro de excluidas en `grade_category::aggregate_grades`
  (`lib/grade/grade_category.php`), `blank_hidden_total_and_adjust_bounds`
  (`grade/report/lib.php`), default `0` del ajuste (`grade/report/user/settings.php`).
- Verificación en Moodle 5.0.7 (Docker, 2026-06-04) con la API real del core:
  `OVERALL item0 hidden=1 excluded=1`, `PERITEM item1 hidden=0 excluded=0`, y —usando el
  propio `grade_grade::get_hiding_affected`— **`COURSE TOTAL blanked_by_hidden=NO
  (fixed)`** (el total del alumno ya no se vacía).

## Consecuencias

**Positivas**
- El alumno ve sus notas por-iDevice **y** un total correcto; desaparece la percepción de
  "notas que no aparecen".
- Se elimina el doble conteo del overall en el total del curso.
- `completionpassgrade` (DEC-0-10) sigue funcionando (la nota oculta conserva
  `finalgrade`/`gradepass`).

**Negativas / coste**
- El profesor sigue viendo el item overall oculto (es el mecanismo de finalización); ya no
  afecta al alumno. La igualdad **total** de columnas entre roles sería la opción B (no
  elegida).
- La exclusión es por-nota: se aplica en cada escritura/recalc del overall (idempotente).

## Validación

- PHPUnit `tests/grades_test.php`:
  `test_peritem_overall_grade_excluded_from_aggregation`,
  `test_overall_model_overall_grade_not_excluded`.
- `phpcs --standard=moodle` limpio.
- Comprobación en vivo (Docker) descrita en Evidencia.

## Seguimiento

- Si en el futuro se quiere igualdad total de columnas entre roles, reabrir por la opción B
  (overall visible + agregación ajustada).
