---
id: REPO-001
titulo: "mod_exescorm — fork de mod_scorm con extensiones eXeLearning"
tipo: moodle-plugin
ruta_local: /Users/ernesto/Dropbox/Trabajo/ate/exelearning/mod_exescorm
url_upstream: "[PENDIENTE: confirmar URL upstream pública]"
commit_consultado: "e985f4d (HEAD clon local 2026-06-17; sha de consulta 2026-05-28 no registrado)"
fecha_consulta: 2026-05-28
licencia: "GPL-3.0-or-later [PENDIENTE: confirmar en cabeceras de archivos]"
rol_para_mod_exelearning: "Referencia primaria para tracking SCORM → gradebook y para la integración con eXeLearning Online + editor embebido."
herramienta_ia:
  interfaz: claude-code
  modelo: claude-opus-4-7
---

## Hechos

- Component frankenstyle: `mod_exescorm`.
- Requiere Moodle ≥ 4.2 (según exploración de `version.php`).
- Tipos de paquete soportados: `local`, `external`, `embedded`, `exescormnet`, `localsync`.
- Tipos de archivo aceptados en `mod_form.php`: `.zip`, `.xml`, `.elpx`.

## Rutas clave

| Ruta | Rol |
|---|---|
| `version.php` | Metadatos del plugin |
| `lib.php` | Hooks Moodle: `_add_instance`, `_update_instance`, `_delete_instance`, `_grade_item_update`, `_update_grades`, `_supports` |
| `locallib.php` | Parseo de `imsmanifest.xml`, generación de TOC, navegación |
| `mod_form.php` | Formulario de creación: upload, tipo, opciones de grading |
| `view.php` | Vista principal de la actividad: TOC + lanzamiento |
| `player.php` | Reproductor SCORM (iframe/popup) |
| `grade.php` | Reporte de calificaciones |
| `datamodels/` | Shim JS de SCORM 1.2 / 1.3 / cmi5 (`LMS.SetValue`/`GetValue`) |
| `db/install.xml` | Tablas: `exescorm`, `exescorm_scoes`, `exescorm_scoes_data`, `exescorm_scoes_track` |
| `db/access.php` | Capabilities (`viewreport`, `skipview`, `savetrack`, `deleteresponses`, `manageembeddededitor`, …) |
| `classes/exescorm_package.php` | Validación de paquete (ZIP/ELPX) |
| `classes/exeonline/` | Integración con eXeLearning Online (redirector, token manager) |
| `classes/completion/custom_completion.php` | Reglas de completion personalizadas |
| `backup/moodle2/` | Backup/restore (scoes + tracks + objectives) |
| `exelearning/` | Sub-app del editor eXeLearning embebido (Node.js) |

## Contratos relevantes

- `exescorm_grade_item_update($exescorm, ...)` — invoca `grade_update('mod/exescorm', $exescorm->course, 'mod', 'exescorm', $exescorm->id, 0, $grades, $params)`. **`itemnumber=0` siempre** ⇒ un único grade item por instancia.
- `grademethod` ∈ {SCOES (0), MAX (1), AVERAGE (2), SUM (3)}.
- Capability adicional respecto a `mod_scorm`: `mod/exescorm:manageembeddededitor`.

## Modelo de datos

- `exescorm_scoes`: metadatos de SCOs (título, launch URL, jerarquía).
- `exescorm_scoes_data`: metadatos manifest (objetivos, reglas de secuenciación).
- `exescorm_scoes_track`: interacciones del estudiante (`element`, `value`, `attempt`, `timemodified`).
- `grade_items`: un único registro (`itemnumber=0`) por instancia.

## Capacidades respecto a `mod_exelearning`

- Sidebar/TOC: sí, generada por `exescorm_get_toc()` a partir del manifest (no es la sidebar nativa de eXeLearning).
- Grading: **agregado en un único grade item**. Ésta es justamente la limitación que `mod_exelearning` quiere superar.
- Tracking: SCORM 1.2 / 2004 + cmi5 vía datamodels.
- Editor embebido: sí, vía `exelearning/` y configuración `exescorm_embedded_editor_available()` + `$CFG->exeonlinebaseuri`.
- Backup/restore: completo, incluye tracks.

## Infraestructura de build / CI

- `Makefile` con targets: `up/upd/down/pull/build/shell/clean/install-deps/lint/fix/
  phpmd/test/behat/check-bun/fetch-editor-source/build-editor/build-editor-no-update/
  clean-editor/package/help`. Docker-based (`check-docker`, `check-env`).
- `.github/workflows/`:
  - `release.yml` — `release.published` + `workflow_dispatch` → build editor +
    `make package` + adjunta ZIP al release.
  - `check-editor-releases.yml` — cron diario, detecta nuevo release de
    eXeLearning y rebuilda el editor estático.
  - `pr-playground-preview.yml` — preview deploy en PR.
- **No tiene `ci.yml`** con matriz Moodle/PHP (AN-006). Hueco a llenar en
  `mod_exelearning` vía DEC-0-04 + TAREA-006.

## Riesgos / Limitaciones

- Acoplamiento fuerte al modelo SCORM single-item; reusar `lib.php` directamente complica multi-itemnumber.
- ELPX como formato no estándar requiere conversión o handler propio.
- Editor embebido es Node.js externo: dependencia de runtime adicional para Moodle.

## Preguntas abiertas

- PREG-001: ¿la publicación SCORM de eXeLearning expone identificadores estables por iDevice calificable?
- PREG-002: ¿conviene aguas arriba en eXeLearning publicar un manifiesto adicional con la lista de items calificables y su `gradeItemId`?
