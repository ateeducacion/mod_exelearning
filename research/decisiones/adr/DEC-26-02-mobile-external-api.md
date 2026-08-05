---
id: DEC-26-02
title: "API externa / móvil (servicios MOODLE_OFFICIAL_MOBILE_SERVICE + save_track)"
status: Accepted
date: 2026-06-09
tracking_issue: 26
legacy_id: DEC-0040
deciders:
  - erseco
  - claude-code
sources:
  - REPO-001
  - REPO-002
  - REPO-004
related:
  adrs: [DEC-5-01, DEC-6-01]
ai_assistance:
  tool: claude-code
  model: claude-opus-4-8
---

# DEC-26-02: API externa / móvil (servicios MOODLE_OFFICIAL_MOBILE_SERVICE + save_track)

## Contexto

El informe comparativo y el encargo confirman (verificado en `db/services.php`) que
`mod_exelearning` **no exponía ningún servicio externo para la app móvil ni para
clientes externos**: solo declaraba dos funciones de administración del editor
embebido (`manage_embedded_editor_action/_status`, caps de site admin). `mod_scorm`
expone 8 funciones en `MOODLE_OFFICIAL_MOBILE_SERVICE` y `mod_exescorm` 9; era la
mayor carencia de superficie de ecosistema frente a sus hermanos.

## Decisión

Añadir un conjunto **canónico** de servicios externos, declarados en
`MOODLE_OFFICIAL_MOBILE_SERVICE`, siguiendo los patrones de `mod_scorm`/`mod_exeweb`
(clases namespaced sobre `core_external\external_api`, `external_warnings`,
`core_external\util::validate_courses`, `helper_for_get_mods_by_courses`). Cada uno
valida contexto, login y capabilities en código:

| Función | Tipo | Capability | Notas |
|---|---|---|---|
| `get_exelearnings_by_courses` | read | `mod/exelearning:view` | Listado por curso; `warnings` para cursos inaccesibles; `packageurl` solo a docentes |
| `view_exelearning` | write | `mod/exelearning:view` | Evento `course_module_viewed` + completion (delega en `exelearning_view`) |
| `get_exelearning_access_information` | read | — (contexto) | Flags `can*` por capability (patrón `load_capability_def`) |
| `get_user_attempts` | read | `mod/exelearning:view`; otro usuario → `:viewreport` | Intentos overall del usuario |
| `get_user_grades` | read | `mod/exelearning:view`; otro usuario → `:viewreport` | Notas por iDevice (refleja el gradebook real) |
| `save_track` | write | `mod/exelearning:savetrack` | Tracking desde la app, **reusa `track::ingest()`** |

### `save_track` — salvaguardas server-side

`save_track` es la única escritura sensible. Reutiliza la tubería compartida
`track::ingest()` (DEC-26-02 introduce esta extracción desde `track.php`), de modo que
la app móvil pasa por **exactamente** los mismos controles que el endpoint web:

- **No confía en el itemnumber del cliente**: las notas se enrutan por `objectid`
  estable (DEC-5-01); un `objectid` que el paquete no expone se **ignora**.
- **No confía en la nota global del cliente**: el overall se **recalcula
  server-side** desde los scores por iDevice (DEC-6-01).
- **No se puede sesgar el overall con objectids falsos**: `ingest()` **filtra**
  `itemscores` a los objectids registrados (no borrados) antes del recálculo
  (cierre de una brecha detectada por los tests de `save_track`).
- Clamping al rango `[grademin, grademax]`; tope de intentos (`maxattempt`) aplicado;
  siempre escribe para el **usuario autenticado** (un cliente no puede calificar a
  otro).

## Consecuencias

- La app oficial de Moodle y clientes externos pueden listar instancias, registrar la
  vista, consultar permisos/intentos/notas y enviar tracking, con paridad de
  ecosistema frente a `mod_scorm`/`mod_exescorm`.
- `track.php` queda como un endpoint fino (auth + respuesta) sobre `track::ingest()`;
  web y WS no pueden divergir en las salvaguardas.
- No se ofrece renderizado del contenido navegable vía WS (requeriría un remote
  add-on de la app): fuera de alcance; el contenido se sirve por `pluginfile`.
- **Límite documentado**: `save_track` ingiere SCORM 1.2 (score por iDevice +
  overall); xAPI sigue en hoja de ruta (DEC-0-14/DEC-17-01). El contrato de `itemscores`
  (objectid ⇒ scorepct/weighted) es estable y reutilizable por una futura ingesta xAPI.

## Implementación

- `classes/external/`: `get_exelearnings_by_courses.php`, `view_exelearning.php`,
  `get_exelearning_access_information.php`, `get_user_attempts.php`,
  `get_user_grades.php`, `save_track.php`.
- `classes/local/track.php`: `ingest()` (orquestación compartida) + `registered_objectids()`
  + filtrado de `itemscores`.
- `db/services.php`: 6 funciones nuevas en `MOODLE_OFFICIAL_MOBILE_SERVICE`.
- `lang/en/exelearning.php`: `error_maxattemptsreached`.
- Tests: `tests/external_test.php` (14 casos: params/returns vía `clean_returnvalue`,
  contexto/login, matriz de permisos own-vs-otro, `packageurl` solo docente,
  `warnings` por curso inaccesible, y seguridad de `save_track` —objectid desconocido
  ignorado, overall no sesgable, `maxattempt`—) + 4 casos de `track::ingest()` en
  `tests/track_test.php`. 94/94 verde en la suite completa.
- **Compatibilidad**: usa `core_external\external_api` (ya empleado por el editor) y
  `core_external\util::validate_courses` / `core_course\external\helper_for_get_mods_by_courses`
  (presentes en 4.5–5.2). La CI valida la matriz Moodle 4.5/5.0/5.1/5.2.
