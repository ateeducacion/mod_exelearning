---
id: DEC-25-01
title: "Sin columna overall en peritem: el libro muestra solo las columnas por-iDevice (modelo workshop para la finalización)"
status: Accepted
date: 2026-06-08
tracking_issue: 25
legacy_id: DEC-0038
deciders:
  - erseco
  - claude-code
sources:
  - REPO-004
  - FTE-014
related:
  adrs: [DEC-0-08, DEC-0-10, DEC-19-01, DEC-19-02]
supersedes: DEC-19-02
ai_assistance:
  tool: claude-code
  model: claude-opus-4-8
---

# DEC-25-01: Sin columna overall en peritem: el libro muestra solo las columnas por-iDevice (modelo workshop para la finalización)

## Contexto

@HenarLG (usabilidad, INTEF) reporta que, en un recurso eXe con **varios elementos
evaluables**, el libro de calificaciones **confunde al docente**: junto a las
columnas por-iDevice aparece una columna **«overall»** (la nota agregada) que **no se
usa** y se percibe como «otra nota» sin sentido.

Estado del código (DEC-0-08, DEC-19-02): en el modelo `peritem` (por defecto),
`exelearning_sync_grade_items()` crea el item overall `itemnumber=0` **oculto**
(`hidden=1`) y lo mantiene **solo** para que la finalización por nota del core
(`completionpassgrade`, DEC-0-10) tenga un `grade_item` con `gradepass`. Pero
`hidden=1` **no quita la columna**: el profesor (con `moodle/grade:viewhidden`) la
sigue viendo en gris. DEC-19-02 ya lo dejó documentado como coste asumido en sus
consecuencias negativas («la igualdad total de columnas sería la opción B, no
elegida»). Esto es justo lo que ahora se reporta.

## Problema

¿Cómo eliminar la columna overall que confunde al docente en `peritem`, sin perder la
finalización por nota estilo SCORM (DEC-0-10), que hoy depende de ese item oculto?

## Hallazgo

Moodle 4.x/5.x **permite elegir contra qué `grade_item` evalúa la finalización por
nota**, igual que `mod_workshop` (que tiene dos items: envío y evaluación). El
selector `completiongradeitemnumber` se construye en
`completion/classes/form/form_trait.php` como un `<select>` **visible** que aparece
cuando el módulo declara más de un grade item (`else if (count($itemnames) > 1)`),
con opciones tomadas de `component_gradeitems::get_itemname_mapping_for_component()`
(en este plugin, `mod_exelearning\grades\gradeitems`). Por tanto **no hace falta** un
overall oculto permanente solo para la finalización: el docente puede apuntar la
finalización a un iDevice concreto, o usar el modo OVERALL para «aprobar la actividad
entera».

Revisión del **cálculo de notas/porcentajes** (solicitada): `attempts.php`
(`record_item`, `aggregate_scaled`), `track.php`/`classes/local/track.php`
(`recompute_overall_pct`, `apply_item_scores`) y `lib.php` son correctos —
`scaledscore` clampado a `[0,1]`, `scorepct` a `[0,100]`, guardas de división por
cero, media ponderada con *fallback* a media simple, y los cinco métodos de
agregación (highest/average/first/last/lowest) bien implementados. La única anomalía
real era el **doble conteo / vaciado del total** por el overall oculto que agrega,
parcheado en DEC-19-02 con `set_excluded`. Eliminar el overall en `peritem` **resuelve
la causa de raíz** y hace innecesario ese parche.

## Opciones consideradas

1. **A. Modelo workshop puro (elegida).** En `peritem` **borrar siempre** la columna
   overall (`itemnumber=0`). El libro muestra solo las columnas por-iDevice (simetría
   con OVERALL, que muestra solo el overall). La finalización por nota usa el selector
   nativo (`completiongradeitemnumber`) sobre un iDevice, o el modo OVERALL para la
   actividad entera. Simple, simétrico y nativo.
2. **B. Condicional.** Borrar el overall por defecto pero conservarlo si la
   finalización se configura sobre el item 0. Preserva «completar al aprobar el
   conjunto» dentro de `peritem`, pero añade acoplamiento al estado de finalización
   (hook `coursemodule_edit_post_actions` + re-sync) y casos borde de validación
   (`badcompletiongradeitemnumber`).
3. **C. Status quo (DEC-19-02).** Overall oculto + excluido de la agregación. No
   resuelve la queja: el profesor lo sigue viendo en gris.

**Decisión del usuario (2026-06-08): opción A.**

## Evidencia

- REPO-004 (este repo): `lib.php` `exelearning_sync_grade_items()` (rama
  overall/peritem), `exelearning_recalculate_user_grades()`, `track.php` (publicación
  del overall), `exelearning_remove_all_grade_items()` (patrón
  `grade_update(..., 0, null, ['deleted'=>true])`).
- FTE-014 (core Moodle, leído de github en v4.5/5.x):
  `completion/classes/form/form_trait.php` crea el `<select>`
  `completiongradeitemnumber` cuando `count($itemnames) > 1`;
  `course/moodleform_mod.php` valida `completiongradeitemnumber` /
  `completionpassgrade`; `core_grades\component_gradeitems` provee el mapeo.
- `classes/grades/gradeitems.php`: el mapeo estático `0..100` declara 101 items, lo
  que **siempre** dispara el `<select>` de finalización (>1 item) — ver Seguimiento.

## Decisión

- **D1.** En `peritem`, `exelearning_sync_grade_items()` **borra** el overall
  (`grade_update('mod/exelearning', course,'mod','exelearning', id, 0, null,
  ['deleted'=>true])`) en vez de crearlo oculto. OVERALL no cambia (overall visible,
  per-iDevice borrados).
- **D2.** `track.php` y `exelearning_recalculate_user_grades()` publican el overall
  (`itemnumber=0`) **solo en modo OVERALL**; en `peritem` no se publica (publicarlo lo
  recrearía). El intento overall se sigue registrando en `exelearning_attempt` para el
  informe de intentos, y la reevaluación de finalización
  (`completion_info::update_state`) es agnóstica del item.
- **D3.** Se **elimina** `exelearning_exclude_overall_grade()` (DEC-19-02): sin overall
  oculto no hay nada que excluir. Migración en `db/upgrade.php` stage `2026060800`:
  borra el overall heredado de las instancias `peritem` existentes.
- **D4.** La finalización «estilo SCORM = aprobar» (DEC-0-10) pasa a vivir en el modo
  OVERALL (overall visible con `gradepass`) o sobre un iDevice concreto vía
  `completiongradeitemnumber`. La demo (`blueprint.json`, `scripts/setup_demo.php`)
  **se mantiene en `peritem`** (para mostrar las dos columnas por-iDevice, que es lo
  que este DEC busca) y apunta la finalización al **iDevice 1**
  (`completionusegrade=1`, `completiongradeitemnumber=1`), demostrando el modelo
  workshop. El modo OVERALL queda como la vía para «completar al aprobar la actividad
  entera».

## Consecuencias

**Positivas**
- En `peritem` el profesor ve **solo** las columnas por-iDevice; desaparece la
  «nota extra» confusa (resuelve el reporte de @HenarLG).
- Se elimina de raíz el doble conteo / vaciado del total: ya no hace falta el parche
  `set_excluded` de DEC-19-02.
- Modelos simétricos y predecibles: OVERALL = una columna agregada; PERITEM = solo
  columnas por-iDevice.
- La finalización por nota usa el mecanismo **nativo** del core (modelo workshop).

**Negativas / coste**
- «Completar al aprobar la actividad entera» dentro de `peritem` ya no es directo:
  hay que usar el modo OVERALL o apuntar la finalización a un iDevice (que carece de
  `gradepass` propio, así que en `peritem` la demo usa `completionusegrade` —«exigir
  nota»— sobre el iDevice 1, no `completionpassgrade`). Revisa el combo
  `peritem + completionpassgrade-sobre-overall` validado en DEC-0-10/TAREA-011.
- Una actividad `peritem` sin iDevices calificables queda sin grade items (antes tenía
  el overall oculto). Es coherente con «no hay nada que calificar».

## Validación

- PHPUnit:
  - `tests/grademodel_test.php`:
    `test_peritem_creates_per_idevice_columns_no_overall`,
    `test_default_model_is_peritem`,
    `test_switch_peritem_to_overall_swaps_columns`,
    `test_switch_overall_to_peritem_swaps_columns`,
    `test_peritem_idevice_is_valid_completion_target`.
  - `tests/grades_test.php`: `test_peritem_has_no_overall_grade_item` (sustituye a
    `test_peritem_overall_grade_excluded_from_aggregation`),
    `test_overall_model_overall_grade_not_excluded`, categoría sobre items `1,2`.
  - `tests/lib_test.php`: `test_grademodel_peritem` y la detección por defecto ahora
    afirman que el overall (item 0) **no existe** en `peritem`.
  - Revisión de cálculo cubierta por `tests/track_test.php`
    (`test_recompute_overall_pct`, clamp 150→100 en `test_parse_suspend_data...`).
- `phpcs --standard=moodle` limpio en los ficheros tocados.
- Comprobación e2e (Docker/Playground): en `peritem` el *Calificador* muestra solo
  columnas por-iDevice (sin overall ni gris); el alumno ve un total correcto. En
  OVERALL + `gradepass=50` + `completionpassgrade=1`, completar al alcanzar ≥50.

## Seguimiento

- **Abre:** el mapeo estático `gradeitems::MAX_ITEMNUMBER = 100` hace que el `<select>`
  nativo de finalización liste 101 entradas (Overall, iDevice 1 … iDevice 100) aunque
  el paquete tenga 2. Recortar el cap o filtrar a items existentes mejora esa UX
  (fuera de scope de este DEC).
- **Cierra:** el coste «el profesor sigue viendo el overall oculto» que DEC-19-02 dejó
  abierto; el mecanismo `set_excluded` de DEC-19-02 queda obsoleto.
