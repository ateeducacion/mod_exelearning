---
id: DEC-17-01
title: "Ingesta dual de tracking: shim SCORM 1.2 + xAPI (exe_xapi.js) sobre una tubería común"
status: Proposed
date: 2026-06-04
tracking_issue: 17
legacy_id: DEC-0032
deciders:
  - erseco
  - claude-code
sources:
  - REPO-004
  - REPO-005
  - FTE-003
  - FTE-007
  - FTE-011
experiments:
  - EXP-004
related:
  adrs: [DEC-0-03, DEC-0-07, DEC-0-14, DEC-5-01, DEC-6-01, DEC-13-05, DEC-13-07]
ai_assistance:
  tool: claude-code
  model: claude-opus-4-8
---

# DEC-17-01: Ingesta dual de tracking: shim SCORM 1.2 + xAPI (exe_xapi.js) sobre una tubería común

## Contexto

El tracking vigente de `mod_exelearning` es **exclusivamente** un bridge SCORM 1.2
(DEC-0-03): el shim `window.API` de `view.php` reenvía a `track.php`, que reusa el mapa
`objectid → itemnumber` (DEC-5-01), recalcula el overall (DEC-6-01), graba intentos
(DEC-0-07) y publica con `grade_update()`. DEC-0-14 dejó el soporte xAPI como **diseño de
referencia diferido** porque su prerrequisito —que eXeLearning emita statements— no se
cumplía ("eXeLearning upstream NO emite xAPI hoy", 2026-05-29).

**Ese prerrequisito ya se cumple.** El PR upstream
[`exelearning/exelearning#1867`](https://github.com/exelearning/exelearning/pull/1867)
(draft) añade `public/app/common/xapi/exe_xapi.js`, un emisor xAPI siempre-activo en
**todos** los formatos de export, que publica statements por `postMessage` al host
(`{ type: 'exe-xapi-statement', statement }`) y/o a un LRS por launch (FTE-011). Esto
**reactiva** la opción C de DEC-0-14.

Este ADR es **documental** (PR1): fija la arquitectura de ingesta dual y su modelo de
confianza. La implementación (listener + endpoint + normalizador + handler/eventos + tests)
va en un PR posterior (TAREA-015), condicionada a que el contrato de #1867 se estabilice.

## Problema

¿Cómo ingiere `mod_exelearning` los statements xAPI de `exe_xapi.js` **sin** romper el
shim SCORM 1.2 vigente, **sin** duplicar el modelo de datos, y validando identidad,
pertenencia (cmid/instancia) y objectid en el servidor (no en el cliente)?

## Opciones consideradas

1. **Reutilizar la tubería existente (elegida).** Un normalizador fino convierte cada
   statement en la **misma** estructura `itemscores` que el shim SCORM ya produce y la
   inyecta en `track::apply_item_scores()` / `attempts::record_item()` / `grade_update()`.
   Reusa `exelearning_attempt` (plano) y `exelearning_grade_item` (mapa objectid). Como
   mucho **una** tabla `exelearning_tracking_events` (`statementid` UNIQUE) para
   auditoría/idempotencia.
   - ✔ Cero duplicación de lógica; SCORM y xAPI convergen en un único punto ya probado.
   - ✔ Honra DEC-0-07 (tabla plana) y DEC-5-01 (ruteo por objectid).
   - ✔ El camino SCORM queda intacto (compatibilidad, DEC-0-03).
   - ✘ El "modelo neutro" es implícito (la estructura `itemscores` + `exelearning_attempt`),
     no una capa nueva explícita.
2. **Modelo neutro nuevo + tablas cabecera/detalle.** Crear `classes/local/tracking/` con
   un evento interno y tablas `exelearning_attempts` + `exelearning_attempt_items`.
   - ✘ **Contradice DEC-0-07**, que evaluó exactamente este diseño (su opción 4) y lo
     **descartó** al implementar, eligiendo una tabla plana (DEC-0-07:176-186).
   - ✘ Duplica lógica que ya funciona y obliga a migrar datos.
3. **Esperar al merge de #1867.** No documentar ni diseñar hasta que upstream cierre.
   - ✘ Pierde la ventana para alinear el consumidor con el contrato mientras se puede
     influir; el coste de documentar ahora es bajo y reversible.

## Evidencia

- Contrato real del emisor (leído del código, no de un resumen): **FTE-011**
  (`exe_xapi.js` @ commit `59b9b9b`) — envoltorio `{type:'exe-xapi-statement', statement}`
  (`:468`), verbos `answered`/`completed`/`passed`/`failed`/`initialized`/`terminated`,
  `object.id = {baseIri}/idevice/{ideviceId}`, `result.score.scaled`, actor anónimo,
  `parentOrigin` configurable. Cobertura `exe_xapi.test.js`.
- La tubería actual es ya inyectable en un punto único: `track.php:177-281`,
  `classes/local/track.php`, `classes/local/attempts.php` (DEC-5-01, DEC-6-01, DEC-0-07).
- `exelearning_attempt` es plano por decisión y pensado para xAPI: **DEC-0-07:154,176-186**.
- Patrón consumidor Moodle: **FTE-007** (`core_xapi_statement_post` + handler) y **AN-003**
  (h5pactivity). Mapeo statement→tubería y modelo de confianza: **AN-012**.
- Conformidad con la spec xAPI 1.0.3: **FTE-003** + Context7 `/adlnet/xapi-spec`
  (2026-06-04, ver FTE-011).

## Decisión

**Opción 1.** xAPI se adopta como **canal de ingesta adicional** que normaliza statements
a la estructura `itemscores` existente y reutiliza `apply_item_scores` /
`record_item` / `grade_update`. Principios fijados:

1. **Modelo común = el que ya existe** (`exelearning_attempt` plano + `exelearning_grade_item`).
   Sin cabecera/detalle (DEC-0-07). Persistencia de statement crudo sólo para
   auditoría/idempotencia, opcional, en **una** tabla `exelearning_tracking_events`.
2. **Confianza cero en el cliente** (AN-012): ignorar `actor` → `$USER`; validar sesión,
   `sesskey`, cmid/instancia, capability `mod/exelearning:savetrack`, y `object.id →
   objectid` de esta instancia (rechazo si desconocido); revalidar el overall en servidor.
3. **Respeta el interruptor de calificación** `gradeenabled` (DEC-13-07): si la actividad no
   es calificable, no existen grade items y los statements no rutean a ninguna parte
   (no-op), coherente con el rechazo de objectid desconocido.
4. **Transporte seguro:** el host inyecta `window.exeXapi.parentOrigin = <origen Moodle>`
   (simetría con `exelearning_inject_scorm_loader`) y el listener valida `event.origin`
   contra el origen del iframe (`pluginfile.php`); rechazar `'*'`/mismatch.
5. **SCORM 1.2 permanece** como compatibilidad (DEC-0-03); xAPI no lo elimina.
6. **Fuera de alcance:** cmi5 (FTE-004/009) y dependencia de LRS externo. El emisor también
   los excluye (FTE-011).
7. **Vía de ingestión servidor** (endpoint propio que ignora actor vs `core_xapi`): se
   detalla y recomienda en `docs/xapi-integration-plan.md` (lean: endpoint propio para
   nota + handler `core_xapi` opcional para eventos), a confirmar en PR2.

Relación con DEC-0-14: este ADR **complementa** (no supersede) DEC-0-14; ejecuta su opción
C ahora que el prerrequisito upstream existe.

## Consecuencias

- Positivas: SCORM y xAPI alimentan una única tubería verificada; sin migración de esquema;
  granularidad e interacciones ricas disponibles; base para analítica/LRS sin acoplarla
  hoy; el camino productivo SCORM no se toca.
- Negativas / coste: se consume un contrato **draft** (riesgo de churn, RIE-013); el
  "modelo neutro" queda implícito en la estructura `itemscores` (documentado, no reificado).
- Cambios que dispara: abre **TAREA-015** (implementación PR2); actualiza la hoja de ruta de
  DEC-0-14; reformula la "Trampas/Pendiente" de `AGENTS.md` cuando se implemente.

## Riesgos

- **RIE-013 (nuevo) — consumir un contrato upstream no congelado y `parentOrigin='*'`.**
  Severidad media, probabilidad media. Mitigación: (a) host fija `parentOrigin` y el
  listener valida `event.origin` (defensa en profundidad, cf. RIE-010); (b) ignorar actor →
  `$USER`; (c) validar objectid/instancia; (d) gating de PR2 a que #1867 fije forma; la
  suite `exe_xapi.test.js` reduce el riesgo de cambios silenciosos.
- RIE-007/006 (cerrados): el ruteo por `objectid` estable (DEC-5-01, PR upstream #1791) ya
  sostiene el mapeo xAPI; no reintroducir el `N` local de página.

## Validación

- PR1 (este): docs coherentes y citables; `test_schema_validation.py` y
  `build_indexes.py` limpios; cero cambios en código de plugin.
- PR2 (TAREA-015): PHPUnit del normalizador (`answered`→itemscores; `passed`→overall;
  objectid desconocido→rechazo; actor no fiable; dedup por `statement.id`); paridad de nota
  con el camino SCORM; e2e en navegador capturando `postMessage` reales (EXP-004 fase viva).

## Seguimiento

- Abre **TAREA-015** (implementación de la ingesta xAPI, gated a #1867).
- Continúa **PREG-002** (cambios aguas arriba); FTE-011 documenta el cambio concreto.
- Documentación técnica en inglés: `docs/tracking-architecture.md`,
  `docs/scorm-shim-current-flow.md`, `docs/xapi-integration-plan.md`.
- Cuando se implemente, añadir docblocks en `track.php`/listener/endpoint citando
  `(DEC-17-01)` y `(see FTE-011)`.
