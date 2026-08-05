---
id: DEC-71-01
titulo: "Extracción de la lógica de lib.php a clases por responsabilidad (grades/*, local/scorm/*, package_manager, urls)"
estado: Aceptada
fecha: 2026-06-12
tracking_issue: 71
legacy_id: DEC-0054
agentes:
  - erseco
  - claude-code
fuentes:
  - REPO-004
relacionados:
  - DEC-67-01
  - DEC-36-01
  - DEC-25-01
  - DEC-0007
  - DEC-4-01
herramienta_ia:
  interfaz: claude-code
  modelo: claude-opus-4-8
---

# DEC-71-01: Extracción de la lógica de lib.php a clases por responsabilidad (grades/*, local/scorm/*, package_manager, urls)

## Contexto

La auditoría estándar del repositorio ([[DEC-67-01]], *improve skill*, 2026-06-11) recomendó
**sacar lógica de `lib.php` a clases por responsabilidad**: el fichero acumulaba ~1751 líneas
mezclando el contrato Moodle (hooks de ciclo de vida, `pluginfile`, callbacks de nota) con
lógica de dominio densa (sincronización del libro de calificaciones, recálculo masivo,
extracción de paquete, transformaciones SCORM al servir, builders de URL). Ese hallazgo se
registró pero quedó **diferido** en esa ronda ("real pero esfuerzo alto … presentado al
mantenedor, no seleccionado esta ronda"; ver [[DEC-67-01]], sección *Hallazgos considerados y
descartados*). Este ADR **selecciona** ese trabajo y lo ejecuta como refactor dedicado.

## Problema

`lib.php` era el único fichero "gordo" del plugin y violaba el principio que el resto del
código sí respeta ("thin controllers, fat domain classes", `docs/ARCHITECTURE.md`). Las
consecuencias concretas:

- La lógica de notas (`exelearning_sync_grade_items()`, ~241 líneas, más recálculo,
  naming y categoría) vivía como funciones globales **no testeables en aislamiento**: solo
  se podían ejercitar a través de los callbacks de Moodle, no como unidades puras.
- Las transformaciones SCORM al servir (inyección del wrapper, parche de save-guard
  [[DEC-13-11]]) y la gestión del filearea de paquete estaban entreveradas con el contrato
  Moodle, dificultando razonar sobre la deuda conocida ([[DEC-36-01]]).
- El tamaño del fichero penalizaba la legibilidad y la revisión.

El reto es hacerlo **sin cambiar comportamiento**: el cálculo de notas, el manejo de
ficheros y la inyección SCORM son sensibles ([[DEC-0007]], [[DEC-25-01]]) y están cubiertos
por una suite existente que debe seguir verde **sin tocar sus asserts**.

## Opciones consideradas

1. **No hacerlo (mantener el diferido de [[DEC-67-01]])** — coste cero, pero el hallazgo
   sigue abierto y la lógica de notas sigue sin ser unit-testable en aislamiento.
2. **Big-bang: mover todo de golpe a una o dos clases gigantes** — reduce líneas de
   `lib.php` pero traslada el problema (clases monolíticas) y produce un diff enorme,
   difícil de revisar y de correlacionar con la suite de paridad.
3. **Extracción por tiers, una clase por responsabilidad, dejando delegadores finos en
   `lib.php`** — `lib.php` conserva exactamente las firmas que Moodle invoca y las que el
   resto del plugin llama; cada función no trivial pasa a ser un `return Clase::metodo(...)`.
   Movimiento **mecánico** (copiar el cuerpo, no reescribirlo). Reutiliza lo ya extraído
   (`local\package`, `local\attempts`, `grades\gradeitems`).

## Evidencia

- [[DEC-67-01]] (`REPO-004`): registra el hallazgo "Descomposición de `lib.php` (~1715
  líneas)" como diferido y trazado al mantenedor; este ADR lo cierra.
- `docs/ARCHITECTURE.md` (pre-refactor): "El único código 'pesado' en `lib.php` es el set
  de grade-sync — que **debe** estar ahí porque son callbacks de Moodle". El refactor
  matiza esto: el callback debe estar; su **cuerpo** no.
- `lib.php` antes: **1751 líneas** (`git show HEAD:lib.php | wc -l`). Después: **956
  líneas** (≈795 líneas movidas a clases). La estimación del plan (~1100) se superó porque
  los delegadores son más compactos que el objetivo conservador.
- Suite de paridad existente que valida que el comportamiento no cambia:
  `tests/grades_test.php`, `tests/gradeitems_test.php`, `tests/grademodel_test.php`,
  `tests/lib_grades_test.php`, `tests/lib_extract_test.php`, `tests/lib_package_test.php`,
  `tests/package_test.php`, `tests/backup_restore_test.php` — **sin cambios de asserts**.
- `phpcs --standard=moodle` sobre todos los ficheros tocados: **0 errores / 0 warnings**.

## Decisión

Se elige la **opción 3 (extracción por tiers con delegadores finos)**. Mapa de extracción:

**Se queda en `lib.php` (contrato Moodle, intocable salvo adelgazar el cuerpo):**
`exelearning_supports` (sin tocar — lo edita otro carril), `*_add/update/delete_instance`,
`*_reset_*`, `*_pluginfile`, `*_get_file_areas`, `*_view`, `*_extend_settings_navigation`,
`*_get_grade_item_names`, y los delegadores finos.

| Tier | Origen (función `exelearning_*`) | Destino (clase::método) |
|------|----------------------------------|-------------------------|
| 1 — Grades | `sync_grade_items`, `warn_if_grades_stale`, cuerpo de `update_grades` | `grades\grade_sync::{sync,warn_if_stale,update_grades}` |
| 1 — Grades | `recalculate_grades_for_users`, `recalculate_user_grades` | `grades\grade_recalculator::{recalculate_for_users,recalculate_user}` |
| 1 — Grades | `grade_item_update`, `grade_item_name`, `remove_all_grade_items`, `apply_grade_category` | `grades\grade_item_manager::{update_item,format_name,remove_all,apply_category}` |
| 1 — Grades | `relax_completion_grade_errors` | `grades\completion_validator::relax_errors` |
| 2 — SCORM | `inject_scorm_loader` | `local\scorm\scorm_injector::inject` |
| 2 — SCORM | `patch_idevice_save_guards` | `local\scorm\idevice_patch::patch` |
| 2 — Package | `get_stored_package`, `package_has_content_xml`, `save_and_extract_package`, `extract_stored_package`, `get_package_url` | `local\package_manager::{get_stored_package,validate_content_xml,save_and_extract,extract_stored,get_package_url}` |
| 3 — URLs | `grade_item_view_url`, `grade_analysis_url`, `navigation_before_key` | `local\urls::{grade_item_view_url,grade_analysis_url,navigation_before_key}` |
| 3 — UI | `require_teacher_mode_hider` | `local\ui\teacher_mode_hider::require_for_iframe` |

**Cero-churn en los llamadores externos:** se conservan los wrappers globales en `lib.php`,
así que `view.php`, `report.php`, `grade.php`, `mod_form.php`, `editor/*`, `classes/external/*`,
`classes/privacy/*` y `classes/local/migration/*` siguen llamando a las mismas funciones
`exelearning_*` sin cambios.

## Consecuencias

- **Positivas:** la lógica de notas/paquete/SCORM/URLs pasa a ser unit-testable en
  aislamiento (se añaden `tests/grades/grade_item_manager_test.php`,
  `tests/local/scorm/scorm_injector_test.php`, `tests/local/package_manager_test.php`);
  `lib.php` cae de 1751 a 956 líneas y queda alineado con "thin controllers, fat domain
  classes"; cierra el hallazgo diferido de [[DEC-67-01]].
- **Negativas / coste:** un nivel más de indirección (delegador → clase) en los callbacks;
  los wrappers globales se mantienen como superficie de compatibilidad (deuda mínima,
  documentada). Sin coste funcional.
- **Cambios que dispara:** `docs/ARCHITECTURE.md` actualizado con el nuevo mapa de clases;
  ningún cambio de esquema, de API externa ni de backup.

## Riesgos

- **RIE-018 — Regresión en el cálculo de notas durante el movimiento.** Mover los bucles de
  `grade_update()` (sync, recálculo masivo, remove-all, item-update) podría alterar
  silenciosamente el orden o las condiciones de publicación y corromper el libro
  ([[DEC-0007]], [[DEC-25-01]]). **Mitigación:** el movimiento es **mecánico** (cuerpo copiado
  verbatim, mismas firmas, mismas constantes globales `EXELEARNING_GRADEMODEL_*`); la suite
  de notas existente (`grades_test`, `gradeitems_test`, `grademodel_test`, `lib_grades_test`,
  `backup_restore_test`) se conserva **sin cambiar asserts** como prueba de paridad; se
  añaden unit tests de la lógica ahora aislada; `phpcs` 0/0 y el gate **Codecov patch ≥80%**
  ([[DEC-66-01]]) más la matriz CI ([[DEC-0004]]) validan en verde antes de fusionar. Riesgo
  residual bajo: PHPUnit no corre en local (memoria `phpunit_local`), se confía en CI.

## Validación

- `git show HEAD:lib.php | wc -l` = 1751; `wc -l lib.php` = 956 (paridad de líneas movidas).
- `php -l` limpio en `lib.php` y las 9 clases nuevas.
- `phpcs --standard=moodle` = 0 errores / 0 warnings en todos los ficheros tocados.
- La suite de paridad (grades/package/backup) debe seguir verde en CI sin tocar asserts.
- Unit tests nuevos verdes en CI (`grade_item_manager_test`, `scorm_injector_test`,
  `package_manager_test`).

## Seguimiento

- Cierra el hallazgo "Descomposición de `lib.php`" diferido en [[DEC-67-01]].
- Los wrappers globales `exelearning_*` quedan como superficie de compatibilidad; una
  limpieza futura podría migrar los llamadores internos a las clases si conviene, pero hoy
  se prefiere cero-churn.
