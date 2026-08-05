---
id: DEC-85-01
title: "Implementación de la ingesta xAPI (TAREA-015): xAPI-primary para paquetes nuevos, SCORM inerte, overall desde el statement de paquete, siempre activo"
status: Accepted
date: 2026-06-18
tracking_issue: 85
legacy_id: DEC-0064
deciders:
  - erseco
  - claude-code
sources:
  - REPO-005
  - FTE-011
  - FTE-015
  - FTE-017
experiments:
  - EXP-004
related:
  adrs: [DEC-0-14, DEC-17-01, DEC-0-18, DEC-13-11, DEC-6-01, DEC-13-07, DEC-0-07, DEC-5-01]
ai_assistance:
  tool: claude-code
  model: claude-opus-4-8
---

# DEC-85-01: Implementación de la ingesta xAPI (TAREA-015): xAPI-primary para paquetes nuevos, SCORM inerte, overall desde el statement de paquete, siempre activo

## Contexto

`DEC-17-01` fijó la **arquitectura** de ingesta dual SCORM 1.2 + xAPI (normalizador fino →
`itemscores` → tubería única `track::apply_item_scores` / `attempts::*` / `grade_update`) y
`DEC-0-18` las **reglas de validación canónica** y la política de versión, ambas **Propuesta** y
**gated** a que el contrato del emisor upstream `exelearning#1867` se congelara. Ese prerrequisito
**ya se cumple**: el PR está **mergeado** (commit `e3b1bd13`, 2026-06-18). Esto desbloquea
**TAREA-015**, la mitad de implementación (PR2).

Este ADR registra las **decisiones de implementación** tomadas al ejecutar TAREA-015 —incluyendo
una **desviación informada** de `DEC-0-18 §2` que sólo se hace visible al leer el statement real
línea a línea— y las resoluciones de producto del maintainer de este turno. **Complementa** (no
supersede) `DEC-17-01`/`DEC-0-18`/`DEC-0-14`; al implementarlas, las mueve de *Propuesta* hacia
*Aceptada*.

### Verificación del contrato (ground truth, no resumen)

Leídos el `exe_xapi.js` mergeado (`REPO-005` @ `e3b1bd13`) y el `.elpx` de ejemplo exportado con esa
versión. El contrato **coincide** con `FTE-011`, con dos **adiciones de seguridad** upstream:

1. **Anonimización de PII** (`_postToParent`): si no hay `parentOrigin`, el emisor **sustituye el
   actor por anónimo** antes de difundir a `'*'` (el `'*'` deja de poder filtrar identidad).
2. **Escape XSS** en la inyección de configuración (`serializeForScript`): escapa `</script>`,
   U+2028 y U+2029 en `window.exeXapi={…}`.

Hechos cargantes confirmados en el código:

- **El `answered` por iDevice NO lleva `weighted`** (`_buildIdeviceStatement`): `result.score =
  {scaled:s/10, raw:s, min:0, max:10}`, `success=s>=5`, sin peso. El peso vive sólo en `_state` del
  emisor y se pliega en el `finalScore` ponderado del **statement de paquete** (`getFinalScore`).
- **El export web sirve `window.exeXapi.odeId` vacío** → `baseIri` cae al *fallback* basado en la
  URL servida (`_resolveConfig`), por lo que `object.id` es **dependiente de la ubicación**; el
  `ideviceId` estable está además en `context.extensions['…/idevice-id']` (= `objectid`, DEC-5-01).
- **Un mismo `sendScoreNew()` dispara ambos canales**: `gamification.track('answered')` (xAPI) **y**
  el `set()` de pipwerks (SCORM). Son **coextensivos**.

## Problema

Al servir Moodle un paquete post-#1867, **ambos** canales se disparan (Moodle ya inyecta pipwerks):
¿cómo evitar el **doble conteo** sin romper el camino SCORM productivo? ¿De dónde sale el **overall**
si los `answered` no llevan peso? ¿Cómo se **activa** el canal?

## Opciones consideradas (coexistencia)

1. **Reforzar / convergencia.** Ambos canales escriben el **mismo** intento (xAPI reusa el token de
   sesión SCORM → *upsert* idempotente). Sin duplicar intentos; SCORM intacto.
   - ✔ Mínimo cambio; literal a «xAPI adicional a SCORM».  ✘ La nota xAPI es redundante con SCORM.
2. **xAPI-primary (elegida).** Si el paquete servido trae `libs/xapi/exe_xapi.js`, **calificar por
   xAPI** y dejar `window.API` como **stub SCORM inerte** (responde `'true'`, no hace POST). SCORM 1.2
   sigue siendo el canal de los paquetes **legacy** (sin emisor).
   - ✔ Suelta la regex frágil de `cmi.suspend_data` para contenido nuevo; un único canal por paquete.
   - ✘ Cambia el comportamiento del camino productivo para paquetes nuevos.
3. **Tokens separados** (literal a una nota previa de `DEC-0-18 §3`).
   - ✘ **Defectuosa**: dos `sessiontoken` distintos → **dos intentos** por una interacción → infla
     `maxattempt` y el informe. Descartada.

## Evidencia

- **Coextensión y peso** (`REPO-005` @ `e3b1bd13`): `public/app/common/common.js` (`gamification.track`
  dentro de `sendScoreNew`) y `public/app/common/xapi/exe_xapi.js` (`emit`/`_buildIdeviceStatement`
  sin peso; `_packageScore`→`getFinalScore` ponderado). Confirma que suprimir el POST SCORM **no
  pierde** ninguna nota que xAPI no capture.
- **`object.id` location-based** (el `.elpx` de ejemplo): `window.exeXapi={"odeId":"",…}` en cada
  `<head>`; routear por el `idevice-id` extension / sufijo `/idevice/{id}`, no por `object.id`.
- **Validación canónica** (`FTE-015`/`DEC-0-18`): `scaled∈[0,1]` (dominio eXe), `raw∈[min,max]`,
  lista blanca de verbos, idempotencia por `statement.id`, ignorar `actor`/`authority`/`stored`.
- **Versión permisiva** (`FTE-017`/`DEC-0-18 §8`): `1.0.x` y `2.0.0` aceptadas; campos consumidos
  idénticos entre versiones.
- **Tubería reusable** (`AN-012`/`AN-014`): `track::apply_item_scores` rutea por `objectid` e ignora
  los no registrados; `attempts::*` agrega por `grademethod`; `EXP-004` documentó el contrato.

## Decisión

1. **Coexistencia = xAPI-primary (Opción 2).** Detección por **presencia de `libs/xapi/exe_xapi.js`**
   en el filearea `content` (`exelearning_package_emits_xapi`). En paquetes que emiten: `window.API`
   se arranca **inerte** (`createScormApi({disableTracking:true})`, sin POST) y se carga el listener
   xAPI; en paquetes **legacy**: SCORM califica como hoy. Se **mantiene** la inyección pipwerks +
   `idevice_patch` (DEC-13-11) para que **todos** los iDevices lleguen a `sendScoreNew` y emitan xAPI.
2. **Overall desde el statement de paquete (refina `DEC-0-18 §2`).** Como los `answered` **no llevan
   peso**, recomponer un overall *ponderado* desde ellos es imposible; el agregado ponderado
   autoritativo es el `finalScore` del statement de paquete (`passed/failed/completed`). El servidor
   lo **toma y valida** (`scaled∈[0,1]`, clamp al rango de nota) en vez de recomputar una media **no
   ponderada** —honrando `DEC-6-01` por *validación*, no por confianza ciega—. Las **columnas por
   iDevice** (modo PERITEM, por defecto) salen de `answered`.
3. **Siempre activo, sin ajuste.** `gradeenabled` (`DEC-13-07`) sigue siendo el único interruptor; no
   se añade ajuste de admin ni de actividad.
4. **Sólo calificación.** Listener + endpoint + normalizador/validador + tabla de auditoría +
   tests. El **handler `core_xapi`** y los eventos de analítica quedan **diferidos** a un PR posterior.
5. **Idempotencia/auditoría.** Una sola tabla **plana** `exelearning_tracking_events` (`statementid`
   UNIQUE); un `statement.id` repetido no se re-aplica. Sin cabecera/detalle (`DEC-0-07`).
6. **Confianza cero** (`DEC-0-18`): el endpoint ignora `actor`/`authority`/`stored`/`timestamp` →
   `$USER`; valida sesión, `sesskey`, `cmid`/instancia, capability `mod/exelearning:savetrack`, y que
   el `objectid` exista en esta instancia; el listener valida `event.origin` (rechaza `'*'`/mismatch).

### Componentes entregados (PR2)

`js/xapi_listener.js` (**IIFE inline**, doble-expuesto `window.exeXapiListener` + `module.exports`;
patrón de `js/scorm_tracker.js` / `DEC-74-01`, **sin** build AMD/grunt), `xapi_track.php` (endpoint
**AJAX plano** con `sesskey`, **simétrico a `track.php`**, que delega en `ingestor::ingest()`),
`classes/local/xapi/statement_normalizer.php`, `classes/local/xapi/ingestor.php`,
`classes/local/xapi/config_injector.php` (inyecta `parentOrigin`/`actor:null`, defensa en
profundidad RIE-013), tabla `exelearning_tracking_events` (`db/install.xml` + `db/upgrade.php` etapa
`2026061800`), flag `disableTracking` en `js/scorm_tracker.js`, y la detección
`exelearning_package_emits_xapi` con el cableado en `view.php`.

**Forma del endpoint y del cliente (refinamiento sobre el plan).** El listener es JS **crítico para
la nota**, así que sigue el patrón **IIFE inline** que el repo ya usa para `js/scorm_tracker.js`
(`DEC-74-01`): se inyecta síncrono, no necesita build AMD y es trivial de testear con Vitest. El
endpoint es un **script propio** `xapi_track.php` (no un `core_external`/`db/services.php`),
simétrico a `track.php` + `scorm_tracker.js`. Esto **satisface `DEC-0-18`**: la elección registrada
era *endpoint custom vs `core_xapi`*, y un script plano **es** un endpoint custom que ignora el
actor (`$USER`) y reusa la tubería; por eso **no** se añade entrada en `db/services.php`.

## Consecuencias

- **Positivas:** estándar moderno como canal **primario** para contenido nuevo; SCORM 1.2 **intacto**
  para legacy; **cero doble conteo** (un único canal por paquete); sin migración de esquema salvo la
  tabla de auditoría; la nota converge en la **misma** tubería verificada (`apply_item_scores`).
- **Negativas / coste:** cambia el comportamiento del camino productivo para paquetes nuevos;
  desviación **documentada** de `DEC-0-18 §2` (overall desde el paquete, no recálculo); consume un
  contrato upstream (ya **mergeado**, lo que reduce `RIE-013`).
- **Dispara:** mueve `DEC-17-01` y `DEC-0-18` de *Propuesta* hacia *Aceptada*; cierra `TAREA-015`;
  actualiza `FTE-011` (commit mergeado), `docs/xapi-integration-plan.md` y
  `docs/tracking-architecture.md` a *implementado*.

## Riesgos

- **RIE-013 (mitigado).** Consumir un contrato upstream: ahora **mergeado/congelado** (`e3b1bd13`); la
  validación canónica (`DEC-0-18`) + el chequeo de `event.origin` del listener + la suite
  `exe_xapi.test.js` upstream lo cubren.
- **RIE (nuevo, severidad baja).** Un paquete xAPI-capable cuyo iDevice calificable **no** emitiera
  `answered` perdería su nota al suprimir el POST SCORM. **Mitigado por diseño**: la propiedad de
  **coextensión** (el mismo `sendScoreNew` dispara ambos canales) garantiza que xAPI capture lo que
  SCORM capturaría; cubierto por el test de paridad.

## Validación

PHPUnit del normalizador (lista blanca de verbos; `scaled∉[0,1]`/`raw∉[min,max]` rechazados; `null`
fuera de `extensions`; versión `1.0.3` **y** `2.0.0`; `ideviceId` por extension y por sufijo) y del
ingestor (un `answered` actualiza el **mismo** itemnumber/nota que el camino SCORM —paridad—;
`passed`/`failed` fija overall+estado+completion; `objectid` desconocido → rechazo; `actor`
ignorado→`$USER`; `statement.id` duplicado no re-aplicado; `maxattempt`; `gradeenabled=0`→no-op).
Vitest del listener (acepta sólo `exe-xapi-statement`; rechaza origen `'*'`/mismatch; dedup por
`statement.id`) y del stub inerte (`disableTracking` no hace XHR). Behat e2e capturando un
`postMessage` real → columna del gradebook (fase viva de `EXP-004`).

## Seguimiento

- Cierra **TAREA-015**. Abre seguimiento **opcional**: handler `core_xapi` + eventos
  (`idevice_answered`/`package_passed`/`failed`) para analítica/LRS, fuera de alcance de este PR.
- `FTE-011` anota el commit mergeado y las dos adiciones de seguridad.
- Mantiene **fuera de alcance** (eco de `DEC-17-01 §6` / `DEC-0-18 §9`): cmi5, LRS externo, State/
  Profile/Document, Signed Statements, y el `'*'` como destino de confianza.
