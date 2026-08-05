---
id: DEC-74-01
titulo: "Tests JS del tracker SCORM con Vitest; extracción del shim de view.php a js/scorm_tracker.js (fuente única, inyección inline)"
estado: Aceptada
fecha: 2026-06-12
tracking_issue: 74
legacy_id: DEC-0056
agentes:
  - erseco
  - claude-code
fuentes:
  - REPO-001
  - REPO-004
relacionados:
  - DEC-66-01
  - DEC-5-01
  - DEC-6-01
  - DEC-17-01
herramienta_ia:
  interfaz: claude-code
  modelo: claude-opus-4-8
---

# DEC-74-01: Tests JS del tracker SCORM con Vitest; extracción del shim de view.php a js/scorm_tracker.js (fuente única, inyección inline)

## Contexto

El plugin tenía cobertura PHPUnit madura ([[DEC-66-01]], 87,2 % con trinquete) pero
**cero tests de JavaScript**. La lógica JS **crítica para calificaciones** vivía embebida
como HEREDOC dentro de `view.php`: el shim SCORM 1.2 que pipwerks `findAPI()` necesita.
Ese shim parsea `cmi.suspend_data`, **rutea cada iDevice a su objectid estable**
([[DEC-5-01]], fix multipágina) y persiste notas con semántica de reintento. PHPUnit solo
cubría el **espejo PHP** (`\mod_exelearning\local\track::parse_suspend_data`), no el JS:
una regresión en el cliente corrompía el gradebook de forma silenciosa.

El usuario pidió validar si merecía la pena testear el JS como el PHP (incluida subida a
Codecov) y, en su caso, planificarlo acotándolo a lo que tiene **impacto de negocio**
(calificaciones), no a la UI (`fullscreen`, `resize`, modales del editor).

## Problema

1. ¿Qué runner JS encaja? El runner "oficial" de Moodle 5.3 es **Jest pero solo para
   ESM** en `public/**/js/esm/`; su doc dice que los módulos **AMD no corren en Jest**.
   Nuestro shim es AMD/heredoc, no ESM.
2. ¿Cómo testear código que hoy es un string PHP con placeholders, sin duplicarlo?
3. ¿Cómo no romper el grading? `window.API` debe existir **síncrono** antes de que el
   iframe del paquete ejecute `findAPI()`; un módulo AMD se carga **async** y haría
   *race* con el SCO.
4. ¿Cómo subir la cobertura JS a Codecov sin contaminar el trinquete PHP de [[DEC-66-01]]?

## Decisión

1. **Framework: Vitest + happy-dom + cobertura v8 + reporter junit.** Es el mismo stack
   que el editor eXeLearning del propio monorepo (`exelearning/vitest.config.mts`) →
   coherencia y subida a Codecov trivial (lcov + junit). Se descarta Jest por el límite
   AMD/ESM. Alcance acotado al **tracker SCORM**; la UI (`amd/src/fullscreen.js`,
   `resize.js`, `editor_modal.js`, …) y los wrappers pipwerks vendorizados
   (`assets/scorm/*`, intocables) quedan **fuera**. El editor embebido `exelearning/`
   **ya viene testeado** con su propia suite Vitest y no se reteste.

2. **Fuente única — extracción a `js/scorm_tracker.js`.** Se saca la lógica del HEREDOC a
   un módulo plano (no bajo `amd/src/`, para no disparar el build AMD ni inyectarse como
   tal), separando **funciones puras** (`parseSuspend`, `resolveObjectMap(doc)`,
   `captureItemScores(newParsed, prevParsed, domMap)`, `buildPayload`) de la **máquina de
   estados** (`createScormApi(config)`) con inyección de dependencias (xhr, timers,
   `getScoringDocument`, `bindUnload`). El módulo expone su API por `module.exports` (test
   runner) y `window.exeScormTracker` (navegador) desde un único cuerpo.

3. **Inyección inline síncrona en `view.php`.** `view.php` hace
   `file_get_contents(js/scorm_tracker.js)` y lo emite inline seguido de un bootstrap que
   asigna `window.API = window.exeScormTracker.createScormApi(cfg).api`. Así se mantiene
   `window.API` **síncrono antes del iframe** (no se convierte a AMD async) y se elimina
   el truco de placeholders `%CMID%`/`%TRACKURL%`/`addslashes`: la config pasa como JSON.
   El fichero que testea Vitest es **exactamente** el que corre en producción.

4. **CI/Codecov.** Job `jsunit` **separado** (Node-only, fuera de la matriz PHP de 22
   jobs): `npm ci` + `vitest run --coverage`. Sube cobertura (`lcov.info`) y resultados
   (`junit-js.xml`) a Codecov bajo el **flag `javascript`**, con un **componente**
   `javascript` (gate `patch 80 %` + `project auto`) para no diluir el trinquete PHP. El
   job hace checkout en la raíz, por lo que sus rutas (`js/`) no necesitan el `fixes:` que
   remapea el upload PHP (instalado bajo `moodle/mod/exelearning/`).

## Alcance de los tests JS — qué se cubre y por qué se descarta el resto

El criterio no es "¿tiene lógica?" sino **ROI**: *impacto en calificaciones* ×
*probabilidad de regresión*, frente al *coste de mock/build*. Se testea solo lo
crítico para notas y barato de aislar; lo demás es UI/glue o third-party cuya
cobertura natural es Behat e2e.

| Fichero / módulo | ¿Se testea? | Motivo |
|---|---|---|
| `js/scorm_tracker.js` (tracker SCORM) | **Sí — Vitest** | Lógica crítica de **notas**: parseo `cmi.suspend_data`, ruteo por `objectid` ([[DEC-5-01]]), máquina `send`/`dirty` (no perder notas). Funciones puras + inyección de dependencias → testeable sin Moodle. |
| `assets/scorm/SCORM_API_wrapper.js`, `SCOFunctions.js` (pipwerks) | **No** | Third-party vendorizado (`thirdpartylibs.xml`); las normas prohíben tocarlo. **No lo ejecuta el plugin**: corre dentro del iframe del paquete (SCO), inyectado por `exelearning_inject_scorm_loader` ([[DEC-36-01]]), acoplado a DOM/jQuery y a `window.parent` ([[DEC-0019]]). Su contrato (que `window.API` responda síncrono) ya lo cubren los tests del tracker + Behat e2e. |
| `amd/src/fullscreen.js`, `amd/src/resize.js` | **No** | UI pura (Fullscreen API / Mutation·ResizeObserver). Sin lógica de negocio ni impacto en notas; el riesgo lo cubre la prueba manual/visual. |
| `amd/src/modform.js` | **No** | Cambia labels de botones del form según el origen. UX glue, sin transformación de datos. |
| `amd/src/admin_embedded_editor.js` | **No** | Polling AJAX de instalación del editor (`define` + jQuery + `core/ajax`). Coste de mock alto, valor bajo; mejor test de integración de la página admin. |
| `amd/src/moodle_exe_bridge.js` | **No** | Detección de cambios por postMessage acoplada a internals de eXeLearning v4 + Yjs. Territorio e2e, no unit. |
| `amd/src/editor_modal.js` | **No — ROI bajo** | Es el único `amd/src` con lógica pura extraíble, pero **"tener lógica pura" ≠ "vale la pena"**: lo barato de testear (reescritura URL/revision, `nextRequestId`) es de **bajo riesgo** (un fallo = cache-busting/refresco con URL rara, recuperable con un reload, no afecta notas); lo de **riesgo real** (handshake postMessage `EXELEARNING_READY → CONFIGURE → OPEN_FILE`, retry/backoff, upload `fetch`, guard de cambios sin guardar) **no es unit-testeable barato** → su cobertura natural es **Behat**. Además es **glue del editor, no notas**, y extraerlo obliga a tocar `amd/src` → regenerar `amd/build` con grunt (moodle-plugin-ci falla si el build no coincide). Coste alto sobre la parte de **menos** valor. |

**Resumen:** se testea con Vitest únicamente `js/scorm_tracker.js`. El contrato
pipwerks↔`window.API` y los flujos del editor se cubren con **Behat** (extremo a
extremo), no con unit tests acoplados a mocks de Moodle/eXeLearning.

## Evidencia

- `js/scorm_tracker.js` — módulo extraído (funciones puras + `createScormApi`).
- `view.php` — inyección inline del módulo (sin placeholders); `php -l` y
  `phpcs --standard=moodle` en verde (exit 0).
- `tests/js/scorm_tracker.test.js` — 19 tests Vitest: parseo (coma decimal, clamp 0–100,
  split `.\t`, líneas mal formadas), `resolveObjectMap` (happy-dom), ruteo por objectid +
  descarte de entradas rancias cross-página ([[DEC-5-01]]), máquina de estados (autocommit,
  `dirty` retenido en fallo de POST = no perder notas, `LMSFinish` síncrono, payload).
- `vitest.config.mjs`, `package.json` (devDeps `vitest`/`happy-dom`/`@vitest/coverage-v8`).
- `.github/workflows/ci.yml` — job `jsunit`; `codecov.yml` — flag/componente `javascript`.
- `Makefile` — target `test-js`.
- Resultado local: **19/19 verde, 99,2 % de líneas** de `js/scorm_tracker.js` (solo quedan
  los *defaults* reales de XHR/`document` del navegador, sustituidos por stubs en test).

## Consecuencias

- **Positivas:** la lógica de calificaciones del cliente queda cubierta y blindada contra
  regresiones; el refactor a fuente única **limpia `view.php`** (elimina placeholders y
  `addslashes`); el `paridad` con el parser PHP queda documentado en los tests; es un paso
  hacia la **tubería común de tracking** ([[DEC-17-01]], SCORM 1.2 + xAPI).
- **Negativas / coste:** se añade un `package.json`/`node_modules` al plugin (solo dev) y
  un job de CI; `view.php` (código crítico) cambia → exige verificación de grading.
- **Cambios que dispara:** ninguno funcional esperado (extracción *behavior-preserving*).

## Riesgos

- Que el refactor de `view.php` altere el timing de `window.API` y rompa el grading.
  Mitigación: inyección **inline síncrona** (no AMD async) + verificación Behat/manual del
  flujo de nota.
- Que la cobertura JS dil0uya el trinquete PHP. Mitigación: **flag/componente separado**
  `javascript` en Codecov; el `project auto` PHP no se toca.
- Drift entre el parser JS y el PHP (`parse_suspend_data`). Mitigación: los tests JS fijan
  el contrato; queda como **seguimiento** un set de fixtures compartido PHP↔JS (no
  abordado en este alcance).

## Validación

- `npx vitest run --coverage` → 19/19 verde, 99,2 % líneas de `js/scorm_tracker.js`.
- `php -l view.php` → sin errores; `vendor/bin/phpcs --standard=moodle view.php` → exit 0.
- `python3 research/tools/test_schema_validation.py` y `build_indexes.py` → ADR válido e
  índices regenerados.
- Pendiente en CI: job `jsunit` verde y Codecov mostrando el componente `javascript`.

## Seguimiento

- Verificar el grading de punta a punta tras el refactor de `view.php` (Behat
  `tests/behat/mod_exelearning.feature` + prueba manual en EXEDEMO con `alumno1`).
- Opcional: fixtures compartidos PHP↔JS que aseguren paridad entre `parseSuspend` y
  `parse_suspend_data` (evita drift entre espejos).
- Cuando se aborde [[DEC-17-01]] (tubería común SCORM+xAPI), reutilizar este módulo como la
  capa cliente testeada.
