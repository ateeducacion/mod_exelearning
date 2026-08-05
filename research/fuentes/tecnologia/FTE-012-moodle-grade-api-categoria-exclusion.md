---
id: FTE-012
titulo: "Moodle grade API: categoría de un grade item (set_parent), exclusión de la agregación (set_excluded) y vaciado de totales con items ocultos"
categoria: api-moodle
version_consultada: "Moodle 4.5 LTS (MOODLE_405_STABLE) y 5.0 (MOODLE_500_STABLE) — comportamiento idéntico"
enlaces_oficiales:
  - https://github.com/moodle/moodle/blob/main/lib/gradelib.php
  - https://github.com/moodle/moodle/blob/main/lib/grade/grade_item.php
  - https://github.com/moodle/moodle/blob/main/lib/grade/grade_grade.php
  - https://github.com/moodle/moodle/blob/main/lib/grade/grade_category.php
  - https://github.com/moodle/moodle/blob/main/grade/report/lib.php
  - https://github.com/moodle/moodle/blob/main/grade/report/user/settings.php
context7:
  library_id: /moodle/moodle
  query: "grade_update categoryid allowlist on existing item; grade_grade set_excluded is_excluded get_hiding_affected; grade_report_user_showtotalsifcontainhidden default; grade_item set_parent; grade_category aggregate_grades excluded"
  fecha: 2026-06-04
  version_devuelta: "moodle/moodle (MOODLE_405_STABLE + MOODLE_500_STABLE) — reputación High. Confirma allowlist de grade_update, set_excluded/get_hiding_affected y el default del ajuste de totales."
fecha_consulta: 2026-06-04
relevancia_para_mod_exelearning: "Fundamenta DEC-19-01 (el selector de categoría debe aplicarse con grade_item::set_parent porque grade_update ignora categoryid) y DEC-19-02 (excluir la nota overall oculta de la agregación con grade_grade::set_excluded para que Moodle no vacíe el total del alumno)."
herramienta_ia:
  interfaz: claude-code
  modelo: claude-opus-4-8
---

## Qué es

Ficha de la **grade API** del core de Moodle para tres preguntas que condicionan el
diseño del libro de calificaciones de `mod_exelearning`: (1) cómo se asigna/mueve un
grade item a una categoría, (2) cómo se excluye un grade item de la agregación del
total, y (3) cuándo Moodle **vacía** el total del alumno por contener items ocultos.

> Método: contrastado leyendo el código del core (rutas citadas) además de Context7;
> verificado en `MOODLE_405_STABLE` y `MOODLE_500_STABLE` (comportamiento idéntico).

## H1 — `grade_update()` ignora `categoryid`; mover = `grade_item::set_parent()`

`grade_update()` (`lib/gradelib.php`) sólo aplica al grade item (nuevo o existente) los
campos de una **allowlist**:

```
itemname, idnumber, gradetype, grademax, grademin, scaleid, multfactor,
plusfactor, deleted, hidden
```

`categoryid` **no** está en la lista (`if (!in_array($k, $allowed)) { continue; }`), así
que `grade_update()` **ni asigna ni mueve** la categoría — ni al crear ni al actualizar.
La API canónica para colocar/mover un item bajo una categoría es
**`grade_item::set_parent($parentid)`** (`lib/grade/grade_item.php`): comprueba el cambio,
llama `force_regrading()` y `update()`. Es lo que hace el core para el desplegable
estándar "Grade category" en `course/modlib.php` (`edit_module_post_actions()` →
`if ($oldgradecat != $gradecat) { $item->set_parent($gradecat); }`; el valor `-1` crea
categoría nueva). El menú se construye con `grade_get_categories_menu($courseid)`; la
categoría superior del curso ("uncategorised") aparece con su **id real**, válido para
`set_parent()`.

## H2 — Exclusión de la agregación: por-nota con `grade_grade::set_excluded()`

No existe flag a nivel de `grade_item` para "no agregar" (`weightoverride`,
`aggregationcoef`, `hidden`, `locked` no lo hacen). La exclusión vive en la **nota**:
`grade_grade::$excluded` + `is_excluded()` + **`set_excluded($state)`** (pone
`excluded = time()` y `update()`), que es lo que activa "Excluir de los cálculos" en la
UI. `grade_update()` **no** lee `excluded` del objeto de nota (sólo `rawgrade`,
`feedback`, `feedbackformat`, `feedbackfiles`, `usermodified`, `datesubmitted`,
`dategraded`): hay que recuperar la `grade_grade` y llamar `set_excluded(true)`.

En `grade_category::aggregate_grades()` (`lib/grade/grade_category.php`) las notas
excluidas se **descartan antes** de la rama por método (`if (in_array($itemid,
$excluded)) { unset($grade_values[$itemid]); continue; }`), por lo que la exclusión es
**robusta en todos los métodos** de agregación (Natural, media ponderada, media…). Un
peso 0 (`weightoverride=1` + `aggregationcoef2=0`) sólo neutraliza el total en Natural y
no es portable → se prefiere `excluded`.

`set_excluded` **no** altera `finalgrade` ni `gradepass`: la nota sigue existiendo para
`completionpassgrade`.

## H3 — "Hide totals if they contain hidden items" vacía el total del alumno

Ajuste de sitio `grade_report_user_showtotalsifcontainhidden`
(`grade/report/user/settings.php`; override por curso `report_user_showtotalsifcontainhidden`).
Tres modos (`lib/grade/constants.php`):

- `GRADE_REPORT_HIDE_TOTAL_IF_CONTAINS_HIDDEN` = **0** → vacía el total.
- `GRADE_REPORT_SHOW_TOTAL_IF_CONTAINS_HIDDEN` = 1 → total excluyendo ocultos.
- `GRADE_REPORT_SHOW_REAL_TOTAL_IF_CONTAINS_HIDDEN` = 2 → total real incluyendo ocultos.

**Default de fábrica: 0 (vaciar).** El vaciado lo hace
`grade_report::blank_hidden_total_and_adjust_bounds()` (`grade/report/lib.php`): bajo el
modo 0 pone `$finalgrade = null` (se renderiza "-"). La decisión "este total contiene un
oculto" sale de `grade_grade::get_hiding_affected()` (`lib/grade/grade_grade.php`), que
**salta las notas excluidas**:

```php
if ($grade_grade->is_excluded()) {
    //nothing to do, aggregation is ok
    continue;
```

→ Si la nota oculta está **excluida**, no marca el total como "contiene ocultos" y el
total del alumno **se muestra**. Excluir el item arregla el vaciado; ocultarlo sin
excluir, no. (Cf. MSA-25-0012, 4.5.3/4.3.11: el core refuerza que las notas ocultas no
se filtren a quien no tenga `moodle/grade:viewhidden`.)

## Aplicación a mod_exelearning

- **DEC-19-01**: el selector `gradecat` se aplica a cada grade item con `set_parent()`
  (no con `grade_update`), en `exelearning_apply_grade_category()`.
- **DEC-19-02**: en modo peritem, tras escribir la nota overall (oculta) se llama
  `grade_grade::set_excluded(true)` (`exelearning_exclude_overall_grade()`), de modo que
  el total del alumno deja de vaciarse y no hay doble conteo, conservando
  `finalgrade`/`gradepass` para la finalización.
