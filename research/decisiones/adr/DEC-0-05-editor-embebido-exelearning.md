---
id: DEC-0-05
tracking_issue: 0
legacy_id: DEC-0005
title: "Editor eXeLearning embebido: heredar la maquinaria de mod_exeweb"
status: Superseded
superseded_by: DEC-0-09
date: 2026-05-28
deciders:
  - erseco
  - claude-code
sources:
  - REPO-001
  - REPO-002
  - REPO-005
experiments: []
ai_assistance:
  tool: claude-code
  model: claude-opus-4-7
---

# DEC-0-05: Editor eXeLearning embebido: heredar la maquinaria de mod_exeweb

> **Superseded por [DEC-0-09](DEC-0-09-solo-editor-embebido.md) (2026-05-29).**
> Esta versión contemplaba además el modo eXeLearning Online (hmac, editormode,
> exeonlinebaseuri). DEC-0-09 la reemplaza: **sólo editor embebido**, sin
> integración Online. El editor embebido en sí se mantiene (implementado y
> verificado); lo descartado es la rama Online.

## Contexto

`mod_exeweb` y `mod_exescorm` integran el editor visual de eXeLearning v4
directamente dentro de Moodle: un botón "Editar con eXeLearning" abre un
modal/iframe con la app estática (compilada con Bun a `dist/static/`) que
permite editar el paquete `.elpx` sin salir de Moodle y guardarlo de vuelta
por AJAX. La maquinaria es no trivial:

- Pieza JS estática del propio editor (clonada y compilada desde
  `github.com/exelearning/exelearning`).
- Pieza PHP en `editor/` que la sirve con headers correctos.
- Helpers en `classes/` para instalar/actualizar la estática.
- Endpoint `save.php` que recibe el ELPX exportado y dispara el flujo de
  detección de iDevices + sincronización de grade items.
- AMD JS (`editor_modal.js`, `moodle_exe_bridge.js`) que abre el modal,
  monta el iframe del editor y maneja postMessage.
- Capability `mod/<plugin>:manageembeddededitor` y settings de admin
  (`exeonlinebaseuri`, `hmackey1`).
- Target `make build-editor` en el Makefile + workflow
  `check-editor-releases.yml` que detecta nuevas releases del editor.

El usuario solicita explícitamente "trae toda la parte de generar el editor
y poner el editor del mod_exeweb".

## Problema

Decidir cómo `mod_exelearning` integra el editor embebido sin duplicar
trabajo ni divergir de la práctica de los plugins hermanos.

## Opciones consideradas

1. **Heredar la maquinaria 1:1 desde mod_exeweb** (recomendada).
2. Implementar otra vez desde cero con cambios de diseño.
3. No incluir editor en v1, depender de "subir un .elpx ya hecho".

## Evidencia

- `mod_exeweb` (REPO-002) tiene un editor embebido funcional probado en
  producción.
- Ambos `mod_exescorm` y `mod_exeweb` comparten el mismo template de
  editor + Makefile + .env.dist.
- El usuario (ATE (Área de Tecnología Educativa)) mantiene `github.com/exelearning/exelearning`
  (REPO-005) y los plugins hermanos, así que reutilizar es coherente.

## Decisión

Adoptar opción 1. `mod_exelearning` hereda la maquinaria del editor de
`mod_exeweb` con los siguientes ajustes mínimos:

### Archivos heredados (renombrando `exeweb` → `exelearning`)

| Procedencia mod_exeweb | Destino mod_exelearning |
|---|---|
| `Makefile` (targets `check-bun`, `fetch-editor-source`, `build-editor`, `clean-editor`, `package`) | `Makefile` |
| `editor/index.php` | `editor/index.php` |
| `editor/save.php` | `editor/save.php` |
| `editor/static.php` | `editor/static.php` |
| `editor/styles.php` | `editor/styles.php` |
| `manage_embedded_editor_upload.php` | `manage_embedded_editor_upload.php` |
| `classes/local/embedded_editor_installer.php` | `classes/local/embedded_editor_installer.php` |
| `classes/external/manage_embedded_editor.php` | `classes/external/manage_embedded_editor.php` |
| `amd/src/editor_modal.js` | `amd/src/editor_modal.js` |
| `amd/src/moodle_exe_bridge.js` | `amd/src/moodle_exe_bridge.js` |
| `amd/src/modform.js` | `amd/src/modform.js` |
| `amd/src/admin_embedded_editor.js` | `amd/src/admin_embedded_editor.js` |
| `amd/src/fullscreen.js` | `amd/src/fullscreen.js` |
| `amd/src/resize.js` | `amd/src/resize.js` |
| `settings.php` | `settings.php` (con `exeonlinebaseuri`, `hmackey1`, `embeddededitor`) |
| `.github/workflows/check-editor-releases.yml` | misma copia |
| `.github/workflows/release.yml` | misma copia |
| `.github/workflows/pr-playground-preview.yml` | misma copia |

### Artefactos NO commiteados

- `exelearning/` (clone de upstream eXeLearning v4 que produce
  `dist/static/`). Está en `.gitignore`.
- `dist/static/` (binario compilado del editor). Está en `.gitignore`.
- `.editor-version` (marker file actualizado por el workflow).

Se generan con:

```bash
make build-editor   # clona upstream + bun install + bun run build:static
                    # copia a dist/static/
```

### Capability

`mod/exelearning:manageembeddededitor` (ya declarada en `db/access.php`).

### Settings de admin (`settings.php` nuevo)

| Setting | Tipo | Default | Uso |
|---|---|---|---|
| `embeddededitor` | bool | true | toggle global del editor embebido |
| `exeonlinebaseuri` | text | `http://localhost:${APP_PORT}` | endpoint del eXeLearning Online opcional para "edición en la nube" |
| `hmackey1` | secret | `${APP_SECRET}` | clave HMAC para firmar el handshake con eXeLearning Online |

### Adaptaciones al guardar desde el editor

`editor/save.php` debe:
1. Validar sesskey + capability `mod/exelearning:addinstance` o
   `moodle/course:manageactivities`.
2. Almacenar el ELPX en `filearea=package`, `itemid=0`.
3. Reincrementar `revision`.
4. Volver a extraer `content/`.
5. Re-ejecutar `exelearning_sync_grade_items` → puede añadir/eliminar grade
   items si el editor cambió iDevices.

## Consecuencias

Positivas:
- Coherencia con `mod_exescorm`/`mod_exeweb`: mismo flujo CI y mismo modal.
- Reutilización: pull-requests upstream del editor benefician a los tres.
- El profesor edita el paquete sin salir de Moodle.

Negativas:
- Tamaño de release (~20-30 MB con `dist/static/`).
- Mantenimiento adicional: rebuild en cada release del editor (cubierto por
  `check-editor-releases.yml`).
- Bun como dependencia de build.

## Riesgos

- RIE-002 (nuevo): si el editor de eXeLearning v4 cambia API entre versiones,
  `editor/save.php` puede quedar desincronizado. Mitigación: pinear
  `EXELEARNING_EDITOR_REF` a un tag estable hasta validar nueva versión.

## Validación

- `make build-editor` desde un clon limpio compila sin errores.
- `make package RELEASE=0.x.0` produce `mod_exelearning-0.x.0.zip` con
  `dist/static/` incluido.
- Al hacer click en "Editar" desde la actividad, el modal abre el editor
  con el ELPX actual.
- Tras editar y guardar, los grade items se re-sincronizan.

## Seguimiento

- TAREA-007 (cierre v1 + DEC-0-04 features adoptar).
- TAREA-017: portar el editor — esta sesión.
- TAREA-018 (futura): adaptar `editor/save.php` para que dispare
  `exelearning_sync_grade_items` además del refresh de `content/`.
