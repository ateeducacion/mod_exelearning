---
id: DEC-13-05
titulo: "Migración masiva de mod_exeweb/mod_exescorm desde los Ajustes del plugin (issue #13 #3)"
estado: Aceptada
fecha: 2026-06-03
tracking_issue: 13
legacy_id: DEC-0026
agentes:
  - erseco
  - claude-code
fuentes:
  - REPO-004
relacionados:
  - DEC-13-03
  - DEC-13-04
supersede: DEC-13-04
herramienta_ia:
  interfaz: claude-code
  modelo: claude-opus-4-8
---

# DEC-13-05: Migración masiva de mod_exeweb/mod_exescorm desde los Ajustes del plugin (issue #13 #3)

## Contexto

DEC-13-04 implementó el punto 3 como un **import por-actividad** (selector en el formulario de
la actividad + hook en `exelearning_add_instance`). El usuario pidió un enfoque mejor: una
**herramienta de migración masiva** en los Ajustes del plugin que copie las actividades de
los hermanos en **todas las aulas** de una vez, con progreso y avisos, porque la operación es
administrativa y de ámbito de sitio.

## Decisión

Sustituir el import por-actividad por una herramienta de migración site-wide, **conservando
el motor** de DEC-13-04 (`import_service::import_package` + `resolve_source_elpx` /
`extract_embedded_elpx`).

- **Entrada (admin):** `settings.php` registra una `admin_externalpage`
  (`/mod/exelearning/admin/migrate.php`) **solo si** `core_component::get_plugin_list('mod')`
  contiene `exeweb` o `exescorm`. Nueva capacidad `mod/exelearning:migrate` (CONTEXT_SYSTEM,
  manager, `RISK_DATALOSS`).
- **Página:** muestra, por hermano instalado, el nº de actividades site-wide, una **caja de
  aviso** (afecta a todas las aulas; puede dar problemas; NO se borran los originales;
  verificar antes de desinstalar el plugin original; en exescorm las notas van a la
  calificación general) y un botón **"Migrar actividades {hermano}"**. La ejecución es
  **síncrona con barra de progreso** (`progress_bar`), tras `\core\session\manager::write_close()`
  y subir límites de tiempo/memoria; al terminar, tabla de resultados por actividad.
- **No destructiva:** las actividades originales se conservan intactas.
- **Por hermano:** `mod_exeweb` → copia el `.elpx`, detección normal por `isScorm`
  (grademodel PERITEM). `mod_exescorm` con fuente embebida → `.elpx` + grademodel **OVERALL**
  + copia de la nota final de cada usuario a la calificación general; **sin fuente → se omite**
  (el original se conserva; no hay conversión inversa con pérdida).
- **Idempotencia + auditoría:** tabla `exelearning_migration` (`sourcecomponent`,
  `sourcecmid`, `targetcmid`, `timecreated`; índice único en `(sourcecomponent, sourcecmid)`).
  Re-lanzar salta las ya migradas.

## Consecuencias

- Se eliminan del PR#15 el selector `importsource` (mod_form) y el hook en `add_instance`;
  los strings `importsource*` desaparecen y se añaden los `migrate*` + la capacidad.
- Cambio de esquema (nueva tabla) → `db/upgrade.php` + bump de `version.php` (2026060302).
- Las actividades se crean con `add_moduleinfo` (patrón `setup_demo.php`) en el mismo
  curso/sección que el origen.
- Migración de notas: se lee la nota final del origen (`grade_item` + `grade_grade` →
  `grade_get_grades` para forzar el recálculo) y se republica por
  `exelearning_grade_item_update($target, $grades)` (itemnumber 0). No se crean filas
  `exelearning_attempt` (basta el `grade_grade`).
- **Cobertura:** el motor, `create_target_module`, `migrate_grades_overall` (con `mod_assign`
  como módulo origen real de prueba, ya que los hermanos no están en CI) y la idempotencia de
  `migrate_one` se cubren con PHPUnit. La enumeración site-wide y la página admin no se
  cubren en CI (hermanos ausentes) — documentado.

## Implementación

- `settings.php`, `admin/migrate.php`, `classes/local/import_service.php` (métodos
  `list_all_sources`, `count_sources`, `migrate_all`, `migrate_one`, `create_target_module`,
  `migrate_grades_overall`), `db/access.php`, `db/install.xml`, `db/upgrade.php`,
  `version.php`, `lang/en/exelearning.php`, `tests/import_test.php`.
