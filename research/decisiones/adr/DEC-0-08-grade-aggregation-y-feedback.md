---
id: DEC-0-08
tracking_issue: 0
legacy_id: DEC-0008
title: "Agregación de grade items: por iDevice (default) o nota global (modo 'ambas' eliminado, rev. 2026-05-29)"
status: Accepted
date: 2026-05-28
deciders:
  - erseco
  - claude-code
sources:
  - REPO-004
  - FTE-001
  - FTE-006
experiments: []
ai_assistance:
  tool: claude-code
  model: claude-opus-4-7
---

# DEC-0-08: Agregación de grade items: por iDevice (default) o nota global (modo 'ambas' eliminado, rev. 2026-05-29)

## Contexto

Tras EXP-002 y el bridge SCORM funcionando, el libro de calificaciones del
curso `EXEDEMO` muestra para una sola actividad `mod_exelearning`:

| # | Grade item | rawgrade | Peso del curso | Contribución | Retroalimentación |
|---|---|---|---|---|---|
| 0 (overall) | `test1` | 100,00 | 25,00 % | 25,00 % | `lesson_status=passed` |
| 1 | `test1 · trueorfalse` | 100,00 | 25,00 % | 25,00 % | — |
| 2 | `test1 · guess` | 60,00 | 25,00 % | 15,00 % | — |

Dos problemas observados:

1. **Doble conteo**: el alumno gana 65 % del total del curso por responder
   una sola actividad. El overall (`itemnumber=0`) representa la MISMA
   evaluación que la suma ponderada de sus iDevices, así que están
   contando dos veces.
2. **Retroalimentación literal `lesson_status=passed`**: cadena CMI cruda
   que llegamos a guardar en `mdl_grade_grades.feedback`. No es estándar
   Moodle ni legible.

## Problema

Definir un modelo de agregación coherente para `mod_exelearning` que:

- Permita al profesor elegir si la nota es **única** (overall) o **detallada
  por iDevice** sin que el alumno gane "puntos extra" por aparecer en dos
  sitios.
- Sustituya `lesson_status=passed` por algo legible (o nada).

## Opciones consideradas

### A. **Sólo overall** (1 grade item, como mod_scorm).

- `itemnumber=0` recibe el score agregado.
- Los iDevices individuales NO crean `grade_items`. Los datos detallados
  viven sólo en `mdl_exelearning_attempt_item` (cuando exista DEC-0-07).
- Profesor que quiera "ver" por iDevice usa el report del módulo
  (`report.php`).

| ✔ Pros | ✘ Contras |
|---|---|
| Modelo Moodle estándar (1 actividad = 1 nota). | Pierdes la posibilidad de incluir items concretos en cálculos de curso (p. ej. "el trueorfalse vale como un examen final"). |
| Cero doble conteo. | El gradebook del alumno no muestra el desglose; hay que ir al report. |
| UI gradebook más limpia. | Si el profesor quiere "obligar a aprobar el trueorfalse específicamente" no puede con `mod_quiz`-style conditional access. |

### B. **Sólo items individuales** (N grade items, sin overall).

- `itemnumber=1..N`, uno por iDevice. No emitimos `itemnumber=0`.
- Cada iDevice contribuye independientemente al curso con el peso que el
  profesor le ponga.

| ✔ Pros | ✘ Contras |
|---|---|
| Trazabilidad y conditional access por iDevice. | Si una actividad tiene 10 iDevices, el gradebook se llena de columnas. |
| Coherente con xAPI (1 statement = 1 nota). | El profesor TIENE que repartir pesos manualmente o aceptar la suma. |
| Doble conteo imposible. | Pierdes el concepto de "nota de la actividad". |

### C. **Híbrido con overall `aggregation_excluded`** (recomendada).

- Emitimos los `itemnumber=1..N` por iDevice (visibles, suman al total).
- Emitimos `itemnumber=0` (overall) marcando el `grade_item.aggregationcoef
  = 0` o `grade_item.hidden = 0` y, crítico, `grade_category->aggregation`
  configurado para que el overall NO se sume al total del curso. Moodle
  permite esto via `grade_item->aggregationcoef2 = 0` o registrando el
  grade_item con `outcomeid != 0`.
- Forma estándar: crear una **`grade_category` propia** por instancia del
  módulo que agrupe `itemnumber=1..N` y devuelva un "total de la
  actividad" como el overall. El total del curso suma sólo la categoría,
  no los items individuales.

| ✔ Pros | ✘ Contras |
|---|---|
| El gradebook muestra desglose Y el "total" único de la actividad. | Más código (crear grade_category + asociar items). |
| El profesor decide aggregation method de la categoría (media, máxima, etc.). | Necesita testing en backup/restore (las categorías deben restaurarse). |
| Coherente con cómo lo presenta el report.php (1 fila por intento + detalle por iDevice). | UI inicial más compleja: hay que documentarlo. |

### D. **Configurable en el formulario** (offer + flag).

- `mod_form.php` añade un selector "Modelo de calificación":
  - `overall` (A)
  - `peritem` (B)
  - `both` (C) — default recomendado.
- `lib.php::exelearning_sync_grade_items` se ramifica según el flag.

| ✔ Pros | ✘ Contras |
|---|---|
| Flexibilidad. | Hay que documentarlo bien para que el profesor lo entienda. |
| Cambiar de `overall` a `peritem` tras tener notas requiere migración: ¿borrar items sobrantes? ¿conservar histórico? |

## Decisión propuesta

**D con default = C** (híbrido con categoría) y migración no destructiva.

Concretamente:

1. Añadir `mdl_exelearning.grademodel` ENUM(`overall`, `peritem`, `both`) con
   default `both`.
2. `mod_form.php` muestra selector "Modelo de calificación" con ayuda que
   explica las tres opciones.
3. `sync_grade_items` emite items según el modelo:
   - `overall`: sólo `itemnumber=0`. Los `mdl_exelearning_grade_item` siguen
     existiendo (para el report); pero no se llama `grade_update` por ellos.
   - `peritem`: sólo `itemnumber=1..N`. NO se emite `itemnumber=0`. Si existía
     un `itemnumber=0`, se marca `deleted=true` en gradelib.
   - `both`: emite todos los `itemnumber` y crea una `grade_category` por
     instancia. La categoría agrupa `itemnumber=1..N`. El `itemnumber=0`
     queda como un grade item visible PERO `excluded=1` del total del curso
     (no suma).

### Feedback

- **Eliminar** el `feedback = 'lesson_status=' . s($status)` de `track.php`.
  La columna "Retroalimentación" queda vacía por defecto.
- Si en el futuro queremos mostrar algo legible, usar `get_string('passed'|
  'failed', 'core')` o un breve resumen "3/4 iDevices completados, 75 %".
  Eso se diseña en una iteración futura con accesibilidad en mente.

## Consecuencias

Positivas:
- Profesores no técnicos eligen "overall" y todo es comprensible.
- Profesores con cursos avanzados eligen "peritem" o "both" sin doble
  conteo.
- Retroalimentación legible (o vacía por defecto, sin ruido).

Negativas:
- Más superficie de testing.
- Migración desde el comportamiento actual (que es de facto `both` con
  doble conteo) requiere actualizar la categoría/exclusión de las
  instancias existentes.

## Riesgos

- RIE-005: si el profesor cambia `grademodel` después de tener notas
  registradas, los grade items previamente emitidos pueden quedar
  huérfanos. Mitigación: marcar siempre `deleted=true` por grade_update
  antes de cambiar; no borrar manualmente. Histórico de `grade_grades` se
  preserva por Moodle.

## Validación

- Crear instancia con `grademodel=overall` → 1 columna `EXEDEMO`,
  contribuye 25 % al curso.
- Crear instancia con `grademodel=peritem` (2 iDevices) → 2 columnas, cada
  una contribuye su porcentaje sin overall.
- Crear instancia con `grademodel=both` → 3 columnas, pero sólo la categoría
  contribuye al total (no el overall+iDevices duplicado).

## Seguimiento

- TAREA-027 (próxima sesión): añadir `grademodel` al schema + selector en
  mod_form + ramificación en `sync_grade_items`.
- TAREA-028: limpiar `feedback` en track.php (1 línea).
- TAREA-029: documentar el modelo en `lang/en/exelearning.php` con strings
  de ayuda claros.
- TAREA-030: testear backup/restore con `grade_category` propia.

## Actualización — Implementado (2026-05-28, claude-opus-4-8)

Estado → **Aceptada**. Implementado el **selector `grademodel`** (campo int en
`exelearning`, default `2 = both`) con las tres ramas en
`exelearning_sync_grade_items()` y, en espejo, en `track.php`:

- `overall (0)`: `grade_update` sólo del `itemnumber=0`; los `>0` se marcan
  `deleted` en gradelib pero las filas `exelearning_grade_item` se conservan
  para el report.
- `peritem (1)`: `grade_update` sólo de los `>0`; el `itemnumber=0` se borra del
  libro.
- `both (2)`: emite todos y **excluye el overall del total del curso** con la vía
  ligera `grade_item->weightoverride=1` + `aggregationcoef2=0`
  (`exelearning_exclude_overall_from_total()`), en lugar de crear una
  `grade_category` propia. Simplificación consciente sobre la opción C del ADR:
  evita la complejidad de categoría + su backup/restore; funciona con la
  agregación Natural (default de Moodle). Si en el futuro se requiere exclusión
  bajo cualquier método de agregación, migrar a `grade_category`.

`feedback` ya es `null` en `track.php` (TAREA-028 hecho). Strings de ayuda en
`lang/en/exelearning.php` (`grademodel*`). Formulario: selector en `mod_form.php`.
Diferido: testeo de backup con la exclusión (el backup actual incluye la
instancia con `grademodel`, así que el modelo se restaura; la marca de peso 0 se
re-aplica al re-sincronizar).

## Revisión — Eliminado el modo `both` (2026-05-29, claude-opus-4-8)

**Decisión de erseco (sesión de mejora 2026-05-29):** se **elimina el modo
`both`**. El selector `grademodel` pasa a tener **dos opciones excluyentes** y
el **default cambia de `both` a `peritem`**:

- `peritem (1)` — **default**. Una columna por iDevice calificable; sin columna
  overall. Es el diferencial del plugin frente a SCORM y se muestra desde que se
  crea la actividad.
- `overall (0)` — una única columna agregada estilo SCORM.

**Motivo:** la UI debe mostrar *una u otra* presentación, no ambas a la vez. El
valor real del plugin son las N columnas por iDevice, así que se exponen por
defecto; el profesor cambia a `overall` si quiere una sola columna.

**Cambios aplicados:**
- Eliminados la constante `EXELEARNING_GRADEMODEL_BOTH`, la rama `both` en
  `exelearning_sync_grade_items()`/`track.php`/`exelearning_recalculate_user_grades()`
  y la función `exelearning_exclude_overall_from_total()` (ya no hay doble
  conteo que evitar: nunca coexisten overall y per-iDevice en el libro).
- `db/install.xml`: default `grademodel` `2 → 1`. Migración en `db/upgrade.php`
  (stage `2026052900`): filas con `grademodel=2` → `1` (conserva las columnas
  por iDevice que el profesor ya veía bajo `both`) + `change_field_default`.
- `lang`: eliminado `grademodel_both`; `grademodel_help` reescrito.
- Tests `grademodel_test.php` reescritos (sin `both`); `tests/generator/lib.php`
  default `1`. `docs/USER_GUIDE.md` actualizado.

`grademethod` por defecto se confirma en `highest` (sin cambio; ya lo era).

## Revisión 2026-06-01 — overall oculto en `peritem` para finalización

TAREA-011 (e2e real por navegador) detectó una interacción no cubierta por
PHPUnit/Behat: con `grademodel=peritem`, la decisión de borrar por completo el
`itemnumber=0` impedía que Moodle evaluara `completionpassgrade`, porque la
condición core de "aprobar para completar" necesita un `grade_item` con nota y
`gradepass`.

La semántica visible de DEC-0-08 se mantiene: en `peritem`, el libro muestra las
columnas por iDevice como valor diferencial del plugin. El ajuste aceptado es
conservar y actualizar un `itemnumber=0` **oculto** que sirve sólo como soporte
técnico para la finalización core de DEC-0-10. En `overall`, el `itemnumber=0`
sigue siendo la única columna visible.

Evidencia:

- `research/evidencias/2026-06-01-tarea-011-e2e/summary.json`: Chrome + Docker
  verifica `completionstate=2` tras puntuar 80/100 tanto en `peritem` como en
  `overall`.
- `peritem.json`: `itemnumber=0` recibe `finalgrade=80` y los iDevices mantienen
  sus columnas (`itemnumber=1`, `itemnumber=2`).
