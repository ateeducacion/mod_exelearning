---
id: AN-009
titulo: "Catálogo de features de mod_scorm player: adoptar / adaptar / aplazar / descartar"
fecha: 2026-05-28
fuentes:
  - REPO-004
relacionados:
  - DEC-0-03
  - DEC-0-04
  - AN-001
  - AN-005
  - AN-007
  - AN-008
  - EXP-002
herramienta_ia:
  interfaz: claude-code
  modelo: claude-opus-4-7
---

## Resumen

Se enumera todo lo que el reproductor SCORM de Moodle core hace (rutas
`mod/scorm/player.php`, `mod/scorm/loadSCO.php`, `mod/scorm/datamodel.php`,
`mod/scorm/prereqs.php`, `mod/scorm/mod_form.php`, `mod/scorm/locallib.php`,
`mod/scorm/lib.php`). Por cada feature se indica si `mod_exelearning` debe
**adoptarla**, **adaptarla**, **aplazarla** a v2 o **descartarla** y por qué.

Convención:

- **Adoptar**: copia con cambios mínimos en v1.
- **Adaptar**: implementación equivalente con cambio sustancial de mecanismo
  (p. ej. xAPI en lugar de CMI).
- **Aplazar**: deseable pero no en v1; va a un PREG/TAREA futura.
- **Descartar**: conflicto con el caso de uso (sidebar nativa de eXeLearning)
  o redundante con la stack moderna (xAPI/Moodle 5 grade API).

## 1. Lanzamiento y framing

| Feature SCORM player | Decisión | Motivo |
|---|---|---|
| Iframe `<iframe name="main" src="loadSCO.php?...">` | **Adaptar** | Hecho en `view.php` con `pluginfile.php/.../content/<rev>/index.html`. No necesitamos `loadSCO.php` porque el paquete eXeLearning no requiere arranque diferido para inyectar `window.API` (AN-007). |
| Modo popup (`scorm->popup`, `scorm_openpopup` JS) | **Aplazar v2** | UX nice-to-have: ventana separada con `target=_blank` o `window.open`. Útil para alumnos en pantallas pequeñas o cuando la sidebar del paquete necesita más espacio. No bloquea v1. |
| `set_pagelayout('embedded')` para popup | **Aplazar v2** | Depende del modo popup anterior. |
| `width` / `height` configurables por instancia | **Aplazar v2** | Hoy `view.php` usa `height="650"` fijo. Añadir un setting permite al profesor adaptarlo. |
| `forcejavascript` + `<noscript>` message | **Adoptar** | Trivial: añadir `<noscript>` que diga "Requiere JavaScript" dentro del iframe-wrapper en `view.php`. ✓ Bajo coste. |
| `keepalive` de sesión (`\core\session\manager::keepalive`, ping cada 30s) | **Adoptar** | Evita que la sesión de Moodle expire mientras el alumno está resolviendo un iDevice largo. ✓ Una sola línea. |
| `Exit` button explícito en la barra (`generate_exitbar`) | **Aplazar v2** | UX para volver al curso. Podemos usar el breadcrumb estándar. |

## 2. TOC / navegación

| Feature | Decisión | Motivo |
|---|---|---|
| `scorm_get_toc` (server-side) + `module.js` (cliente, M.mod_scorm.init) | **Descartar** | Conflicto directo con AN-001: el objetivo de `mod_exelearning` es **preservar la sidebar nativa** que viene en el paquete eXeLearning. Renderizarnos otro TOC server-side rompería esa promesa. |
| `hidetoc`, `nav`, `navpositionleft`, `navpositiontop`, `collapsetocwinsize` | **Descartar** | Mismo motivo: son ajustes del TOC server-side de Moodle. |
| `displaycoursestructure` | **Descartar** | Lo mismo. |
| `adlnav` (SCORM 2004 Sequencing & Navigation) | **Descartar** | No usamos sequencing; la navegación dentro del paquete la maneja eXeLearning. |

## 3. Tracking (datos de interacción)

| Feature | Decisión | Motivo |
|---|---|---|
| `window.API` / `API_1484_11` JS shim inyectado en cada SCO | **Adaptar** | En lugar de inyectar API SCORM global, exponer un shim **xAPI** que recoja eventos `postMessage` del paquete y los reenvíe a `core_xapi_post_statement` (AN-003, AN-007). Requiere implementar `classes/xapi/handler.php`. |
| `datamodel.php` endpoint con `confirm_sesskey()` + capability `:savetrack` | **Adaptar** | Moodle ya provee `core_xapi_post_statement` con sesskey + scopes OAuth. Reutilizar. ✓ |
| `scorm_insert_track($user, $scorm, $sco, $attempt, $element, $value, ...)` | **Adaptar** | Equivalente en xAPI: `attempt::save_statement($statement)` (patrón mod_h5pactivity). Persistencia en `mdl_exelearning_attempt`. |
| `prereqs.php` (evaluación de prerrequisitos SCO) | **Descartar** | Específico de SCORM Sequencing. eXeLearning no expone prerrequisitos por iDevice. |
| `autocommit` (commit periódico desde el SCO) | **Aplazar v2** | Útil pero xAPI ya hace statements puntuales; el equivalente sería un debounce + flush en el shim cliente. |
| `request.js` (XHR helpers SCORM) | **Descartar** | El cliente xAPI usará `fetch()` moderno. |
| `cmi.suspend_data` (estado libre 64KB) | **Adaptar** | xAPI `state` API (`core_xapi_post_state`) cubre el mismo caso de uso para "bookmark / progress" sin tocar la jerarquía CMI. |
| Logging del evento `sco_launched` | **Adaptar** | Crear `mod_exelearning\event\package_launched` análogo cuando se sirva `index.html`. |

## 4. Intentos

| Feature | Decisión | Motivo |
|---|---|---|
| `maxattempt` (límite N) | **Aplazar v2** | Útil para evaluación final; aplazar hasta que haya bridge xAPI funcional. |
| `whatgrade` (max/avg/first/last) | **Aplazar v2** | Depende de tener varios intentos. |
| `forcenewattempt` (alterna intentos cada vez) | **Aplazar v2** | Idem. |
| `lastattemptlock` (bloquea botón cuando max alcanzado) | **Aplazar v2** | Idem. |
| `newattempt=on` flag URL + `scorm_check_mode` (incrementa attempt) | **Aplazar v2** | Mecánica de intentos completa, v2. |
| `mode = browse|review|normal` | **Aplazar v2** | Modos de visualización (profesor previa, alumno post-revisión). |
| `mod/scorm:skipview` capability + `skipview` setting | **Descartar** | El bypass del intro page es propio del workflow SCORM; nuestro `view.php` es directo. |

## 5. Calificación

| Feature | Decisión | Motivo |
|---|---|---|
| `grademethod` con 4 opciones (`GRADESCOES`/`GRADEMAX`/`GRADEAVERAGE`/`GRADESUM`) | **Descartar** (en su forma actual) | Aglutina todos los SCOs en **un grade item**. Nosotros usamos multi-itemnumber estándar (AN-002). Un único item por iDevice resuelve el problema sin "métodos de agregación" globales. Lo que sí adoptamos como opción del profesor: ver fila siguiente. |
| `maxgrade` global | **Adoptar (adaptado)** | Equivalente: nuestro `grademax` por instancia, replicado a cada item (campo `grademax` en `exelearning_grade_item`). Implementado en EXP-002. |
| `masteryoverride` (status pasa a `passed`/`failed` según masteryScore) | **Aplazar v2** | Útil cuando se enlace con xAPI / completion. |
| `forcecompleted` (marca completo aunque no se cumpla) | **Descartar** | Hack para SCORM legacy que no emite `completed`. xAPI ya tiene `result.completion` explícito. |
| `gradedisplaytype` por item (REAL/PERCENTAGE/LETTER/…) | **Adoptado** | Implementado (selector en `mod_form.php` desde 0.3.0-dev). |

## 6. Disponibilidad

| Feature | Decisión | Motivo |
|---|---|---|
| `timeopen` / `timeclose` (`scorm_require_available`) | **Adoptar** | Standard de Moodle. Trivial añadir 2 `date_time_selector` y la comprobación en `view.php`. Útil para deadline de evaluaciones. |
| Aviso si no disponible (`$OUTPUT->box(get_string($reason, "scorm", ...))`) | **Adoptar** | Junto con la fila anterior. |
| Visibility por cm (`!$cm->visible`) | **Adoptar** | Estándar Moodle, ya cubierto por `require_capability` en `view.php`. ✓ |

## 7. Reportes / vista de profesor

| Feature | Decisión | Motivo |
|---|---|---|
| `report.php` con intentos por alumno + sub-reports (basic/objectives/interactions/graphs) | **Aplazar v2** | Una página de reporte por instancia es valiosa pero no bloquea v1. Capability `mod/exelearning:viewreport` ya definida. |
| Capability `mod/scorm:viewscores` (alumno ve sus scores) | **Adoptar** | Trivial: definir `mod/exelearning:viewscores`. |
| Capability `mod/scorm:deleteresponses` / `deleteownresponses` | **Aplazar v2** | Necesario cuando exista historial de intentos. |
| `reportsettings_form.php` (filtros del reporte) | **Aplazar v2** | Junto con `report.php`. |

## 8. Empaquetado / actualización de contenido

| Feature | Decisión | Motivo |
|---|---|---|
| `scormtype` `local` / `external` / `aicc` / `imsrepository` | **Adoptar parcial** | Sólo `local` en v1 (paquete subido al filemanager). `external` (URL) podría llegar en v2 si se necesita pegar URLs públicas de eXeLearning Online. |
| `packageurl` (URL externa) | **Aplazar v2** | Idem. |
| `updatefreq` (refresca paquete cada X) | **Aplazar v2** | Útil si el paquete vive en URL externa. |
| `packagehash` / verificación de cambios | **Aplazar v2** | Junto con updatefreq. |

## 9. Backup / restore

| Feature | Decisión | Motivo |
|---|---|---|
| `backup/moodle2/backup_scorm_stepslib.php` + tracks | **Adoptar** | Estándar Moodle. Crear nuestros backup steps que serialicen `exelearning`, `exelearning_grade_item` y (futuro) `exelearning_attempt`. |
| Restore de `scorm_scoes` + `scorm_scoes_track` | **Adoptar** | Idem. |
| `mod_exelearning_get_settings_definition` (para `pluginfile` durante backup) | **Adoptar** | Necesario para que se incluyan los archivos del paquete en el backup .mbz. |

## 10. Completion

| Feature | Decisión | Motivo |
|---|---|---|
| `completionview` (visto = completado) | **Adoptado** | `FEATURE_COMPLETION_TRACKS_VIEWS=true` declarado. ✓ |
| `completionstatusrequired` (estado `passed`/`completed`) | **Aplazar v2** | Cuando exista el bridge xAPI con statements de completion. |
| `completionscorerequired` (umbral de score) | **Aplazar v2** | Idem. |
| `completionstatusallscos` (todos los SCOs OK) | **Adaptar v2** | Equivalente: todos los iDevices con score ≥ umbral. |
| `mod_scorm\completion\custom_completion` | **Aplazar v2** | Implementar `mod_exelearning\completion\custom_completion` con las reglas xAPI cuando llegue el momento. |

## 11. Seguridad / robustez del servidor

| Feature | Decisión | Motivo |
|---|---|---|
| `confirm_sesskey()` en datamodel | **Adoptar (vía core_xapi)** | El endpoint `core_xapi_post_statement` ya valida sesskey/scope. ✓ |
| `require_capability('mod/scorm:savetrack', ...)` | **Adoptar** | Capability `mod/exelearning:savetrack` ya definida en `db/access.php`. |
| `loadSCO.php` con HTML mínimo "loggedinnot" para iframes | **Descartar** | Nuestra `view.php` hace `require_login` ANTES de pintar nada, así el iframe nunca verá login page de Moodle. |
| `send_header_404` para content faltante | **Adoptar** | Trivial: en `exelearning_pluginfile()` devolver 404 explícito en lugar de `return false`. Mejora trazas. |
| `dontforcesvgdownload = true` | **Adoptado** | Aplicado en 0.3.0-dev. ✓ |
| `lifetime = 0` en filearea content (no cache) | **Descartar** | Nuestra estrategia de revisión (`itemid=revision`) ya invalida cachés sin necesidad de `no-cache`. Mejor perf para alumnos. |
| Validación de params (`PARAM_INT`, `PARAM_ALPHA`, `PARAM_SAFEDIR` para version) | **Adoptado** | Ya usamos `required_param`/`optional_param` con tipos correctos. ✓ |

## 12. Settings de admin (site-wide)

| Feature | Decisión | Motivo |
|---|---|---|
| `settings.php` con defaults globales (popup, width, height, autocommit, …) | **Aplazar v2** | Bonito para administradores grandes; en v1 los defaults van hard-coded en `mod_form.php`. |
| `forbiddenfileslist` / `mandatoryfileslist` para validar paquetes | **Adoptar** | Heredarlo de mod_exeweb (ya tienen el patrón). Util para evitar paquetes maliciosos: rechazar `*.php`, `*.htaccess`, `*.cgi`. |
| `framesize` global (para frame mode) | **Descartar** | No usamos frame mode. |
| `exeonlinebaseuri`, `hmackey1` (heredados de mod_exescorm) | **Aplazar v2** | Sólo si en v2 integramos eXeLearning Online como autor embebido. |

## Resumen ejecutivo

**Adoptar en v1 (next sprint)**:
- `<noscript>` con mensaje "Requiere JavaScript".
- `keepalive` de sesión (1 línea).
- `timeopen` / `timeclose` con `require_available` style.
- `send_header_404` explícito en `pluginfile`.
- `mod/exelearning:viewscores` capability.
- `forbiddenfileslist` heredada de mod_exeweb para `package` validation.

**Adaptar en próximo hito (bridge xAPI)**:
- `window.API` → shim xAPI (`amd/src/xapi_bridge.js`).
- `datamodel.php` → `core_xapi_post_statement` ya gestionado por core.
- `scorm_insert_track` → `attempt::save_statement` en `classes/xapi/handler.php`.
- `cmi.suspend_data` → `core_xapi_post_state`.
- Backup/restore que incluya `exelearning_grade_item` + `exelearning_attempt`.

**Aplazar a v2** (no bloquea uso real):
- Modo popup + dimensiones configurables.
- Multi-attempt completo (maxattempt, whatgrade, forcenewattempt, lastattemptlock, browse/review modes).
- Reportes (`report.php` + filtros + delete responses).
- `external` scormtype + `updatefreq`.
- Completion rules por estado/score.
- Settings.php site-wide.

**Descartar** (incompatible con nuestro caso de uso o redundante):
- TOC server-side y todas sus opciones (`hidetoc`, `nav`, `navpositionleft`,
  `navpositiontop`, `collapsetocwinsize`, `displaycoursestructure`,
  `adlnav`). **Razón clave**: rompería la sidebar nativa de eXeLearning,
  que es el rasgo diferenciador del plugin.
- `grademethod` global (lo reemplaza multi-itemnumber).
- `forcecompleted` (xAPI ya distingue completion explícita).
- `loadSCO.php` indirección (no necesitamos arranque diferido sin API global).
- `request.js` SCORM helpers (usamos `fetch` moderno).
- `prereqs.php` SCORM Sequencing.
- `skipview` setting.
- `framesize` / frameset mode.
- `cmi.*` data model completo (lo sustituye xAPI granular).

## Consecuencias

- DEC-0-04 (build/CI) no se ve afectado.
- DEC-0-03 (Plan B: SCO-as-page + content.xml + multi-itemnumber) sigue siendo
  la dirección correcta. Esta nota refuerza el Plan B y mata definitivamente
  cualquier tentación de "copiar el SCORM player entero" (no necesario y
  contraproducente).
- Abre 5-6 micro-tareas concretas para v1 (lista "Adoptar") y otras tantas
  para el bridge xAPI siguiente.

## [PENDIENTE]

- TAREA-007: aplicar las 6 mejoras "Adoptar en v1" en un mismo PR.
- TAREA-008: scaffold del bridge xAPI (handler + classes/local/attempt.php +
  amd/src/xapi_bridge.js).
- PREG-002 (cambios upstream eXeLearning) sigue abierta para granularidad
  por iDevice nativa.
