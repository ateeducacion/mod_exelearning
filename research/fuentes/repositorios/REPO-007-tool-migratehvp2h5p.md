---
id: REPO-007
titulo: "tool_migratehvp2h5p — migración oficial mod_hvp → mod_h5pactivity (Moodle HQ)"
tipo: moodle-plugin
ruta_local: "[no clonado; consultado vía GitHub API/raw]"
url_upstream: "https://github.com/moodlehq/moodle-tool_migratehvp2h5p"
commit_consultado: "main @ release 0.2.0 (consultado 2026-06-12)"
fecha_consulta: 2026-06-12
licencia: "GPL-3.0-or-later"
rol_para_mod_exelearning: "Precedente oficial del patrón 'migración de plugin contrib hacia un módulo core más nuevo como herramienta administrativa separada con CLI'. Citado en DEC-13-12 para corregir la afirmación previa de que no existía una migración hvp → h5pactivity."
herramienta_ia:
  interfaz: claude-code
  modelo: claude-opus-4-8
---

## Hechos

- **Repositorio oficial:** `github.com/moodlehq/moodle-tool_migratehvp2h5p` (cuenta de
  Moodle HQ). Component frankenstyle: `tool_migratehvp2h5p` (plugin de tipo `admin/tool/`).
- **Qué migra:** actividades del plugin **contrib `mod_hvp`** (Interactive Content de
  Joubel/H5P) hacia el módulo **core `mod_h5pactivity`** (añadido al core en Moodle 3.9,
  ~2020).
- **Autoría / origen:** Moodle HQ — Sara Arjona (sara@moodle.com) y Ferran Recio;
  seguimiento en el tracker como **MDL-67203** ("First skeleton of the migration tool",
  primer commit 2020-05-04; commit temprano "Implementing migration tool and CLI
  command"). Mantenido por Moodle HQ hasta 2025 (release 0.2.0, `MATURITY_STABLE`).
- **Arquitectura:** interfaz web (Administración del sitio → *Migrate content from mod_hvp
  to mod_h5pactivity*) **y CLI** (`cli/migrate.php --execute`, con límite de lote
  configurable, por defecto 100). Procesador de migración por actividad en `classes/`,
  cobertura en `tests/`, `settings.php`/`index.php`, `lang/` y plantillas en `templates/`.
- **Dependencias** (`version.php`): requiere **ambos** módulos instalados —
  `mod_h5pactivity` y `mod_hvp`. La rama `main` actual fija `requires = 2025041400`
  (Moodle reciente); el mínimo histórico fue Moodle 3.9 (cuando `mod_h5pactivity` entró
  en core).
- **No destructivo por defecto:** conserva las actividades `mod_hvp` originales y añade un
  enlace al banco de contenidos; configurable. Limitaciones documentadas: **no** migra el
  estado de intentos de los alumnos ni los ajustes globales de `mod_hvp` (display options,
  uso del hub…), porque `mod_h5pactivity` no los soporta.

## Verificación / no-hallazgos

- **No existe** migración `hvp → h5pactivity` dentro de `moodle/moodle` core: búsquedas de
  código en el repo core (`migratehvp`, referencias a `mod_hvp` en `mod/h5pactivity/`) sin
  resultados. La ruta oficial es este tool **separado**, no un paso de `db/upgrade.php` del
  módulo core.

## Relevancia para mod_exelearning (DEC-13-12)

- Confirma que el patrón Moodle para migrar un plugin contrib hacia un módulo core es una
  **herramienta administrativa separada** (`tool_*`), igual que `tool_lpmigrate` en core.
- DEC-13-12 **diverge** conscientemente (mantiene la herramienta dentro de
  `mod_exelearning`) porque el destino ya posee los internals necesarios, el par de
  orígenes es pequeño y la entrada solo aparece si hay un sibling instalado.
- Que este precedente incluya **CLI** respalda diferir `cli/migrate.php` a una segunda
  iteración (no embeberlo en la primera entrega).
