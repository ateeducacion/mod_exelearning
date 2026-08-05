---
id: DEC-0-15
tracking_issue: 0
legacy_id: DEC-0015
title: "¿Merece la pena la multicalificación (N grade items por iDevice)? Justificación, DAFO y comparativa"
status: Accepted
date: 2026-05-29
deciders:
  - erseco
  - claude-code
sources:
  - REPO-001
  - REPO-002
  - REPO-004
  - REPO-005
  - FTE-001
  - FTE-006
  - FTE-007
  - FTE-008
  - FTE-009
related:
  adrs: [DEC-0-03, DEC-0-07, DEC-0-08, DEC-0-10, DEC-0-14]
see_also:
  - AN-010
ai_assistance:
  tool: claude-code
  model: claude-opus-4-8
---

# DEC-0-15: ¿Merece la pena la multicalificación (N grade items por iDevice)? Justificación, DAFO y comparativa

## Contexto y motivación

`mod_exelearning` nace para cubrir un hueco entre dos plugins existentes de ATE:

- **`mod_exeweb`** sirve un paquete eXeLearning v4 dentro de Moodle con su
  **sidebar nativa** (técnica iframe), pero **no califica**: es un visor de
  recurso, sin libro de calificaciones, intentos ni finalización por nota.
- **`mod_exescorm`** sí califica, pero como **una única nota** por paquete
  (el `cmi.core.score.raw` agregado de SCORM), perdiendo el detalle por
  ejercicio; además presenta el contenido con el TOC de SCORM, no con la
  sidebar nativa de eXeLearning.

La necesidad pedagógica de ATE es **evaluar cada ejercicio (iDevice) calificable
de un mismo recurso eXeLearning por separado**, manteniendo la experiencia de
navegación nativa. Eso exige **N columnas en el gradebook (una por iDevice)** en
lugar de una sola — la *multicalificación*. `mod_exelearning` fusiona la sidebar
de `mod_exeweb` con un modelo de calificación que va más allá de `mod_exescorm`.

Este ADR responde: **¿merece la pena esa multicalificación, dado su coste?**

## Cómo se consigue (resumen técnico)

El plugin extrae `content.xml` del `.elpx`, detecta los iDevices calificables
(`GRADABLE_IDEVICE_TYPES`), mapea cada `odeIdeviceId` estable a un `itemnumber`
y registra N grade items con `grade_update(..., itemnumber=$n, ...)` (FTE-006).
El tracking llega por un **puente SCORM 1.2** (shim `window.API` + pipwerks
inyectado) que `track.php` parsea y reparte por `itemnumber` (DEC-0-03). Los
intentos viven en una tabla propia con `grademethod` (DEC-0-07) y la
finalización es la nativa `completionpassgrade` (DEC-0-10).

## Ventajas

### Frente a `mod_exeweb`
- Añade **calificación** (exeweb no tiene ninguna): libro, intentos, finalización.
- Columnas **por iDevice**, no solo presencia del recurso.
- Conserva **íntegra** la sidebar nativa (misma técnica iframe).

### Frente a `mod_exescorm` (y `mod_scorm` core)
- **N columnas vs 1 nota**: el profesor ve el rendimiento por ejercicio dentro
  del mismo recurso, no un agregado opaco.
- **Sidebar nativa** de eXeLearning en lugar del TOC de SCORM (mejor UX y
  coherencia con cómo se diseñó el contenido).
- Modelo de **intentos propio** con método configurable (highest/average/…)
  y `grademodel` (por iDevice / global) — DEC-0-07, DEC-0-08.
- No obliga a re-exportar a SCORM: trabaja sobre el `.elpx` v4 directamente.

## Inconvenientes y complejidad

**Es más complejo que ambos** (exeweb no califica; exescorm da 1 nota). El coste:

- Parsear `content.xml` y mantener la heurística de detección de iDevices
  calificables frente a la evolución del formato de eXeLearning (FTE-008).
- Mapear `odeIdeviceId → itemnumber` de forma **idempotente** y sobrevivir a
  re-uploads. La estabilidad de ids (**RIE-006**) está **resuelta aguas arriba**:
  el PR `exelearning/exelearning#1791` (merged, ya en `main`) preserva los
  `odeIdeviceId` al importar/exportar, así que editar+guardar con un editor
  post-#1791 **no duplica columnas**. (Sólo persiste como limitación con editores
  anteriores a #1791.)
- El puente **SCORM 1.2 es un shim**, no tracking nativo: eXeLearning no emite
  xAPI hoy (FTE-007/DEC-0-14). Es pragmático y funciona, pero es deuda técnica.
- Detalles frágiles ya domados: `itemnumber_mapping` (MAX=100), inyección
  pipwerks, self-heal de subidas programáticas, re-sync tras editar.
- Riesgo de **saturar el libro** con muchas columnas (mitigado: `grademodel`
  por defecto por iDevice, conmutable a global — DEC-0-08 rev.).

**¿Más simple o más complicado? Complejidad MEDIA y acotada, no "alta"**
(desglose en **AN-010**). Matices importantes para no exagerar:

- La **tabla de intentos propia NO es coste extra**: es el estándar. `mod_scorm`
  crea `scorm_attempt` / `scorm_aicc_session` / `scorm_scoes_value`, y
  `mod_h5pactivity` crea `h5pactivity_attempts` / `_attempts_results`. Nuestra
  `exelearning_attempt` **calca** los campos de `h5pactivity_attempts`. Registrar
  intentos es lo que hace cualquier actividad evaluable de Moodle.
- El **multi-itemnumber** es un patrón **documentado del core** (FTE-006), ya usado
  por `mod_workshop`. No es territorio nuevo.
- La complejidad real se concentra en **2-3 puntos aislados**: el parser de
  `content.xml`, el **shim SCORM 1.2** y la estabilidad de ids (RIE-006). El resto
  es código mecánico cubierto por tests + CI.

Es decir: la parte delicada no es el volumen de código sino el **acoplamiento al
formato de export de eXeLearning** (riesgo externo), más el shim. La complejidad
**compra valor pedagógico real** (evaluación granular) que ninguna alternativa
ofrece con sidebar nativa.

### ¿Qué es el "shim" SCORM 1.2 y por qué existe?

Un **shim** es una pequeña capa de compatibilidad que finge ser algo que el
contenido espera encontrar. El contenido eXeLearning publicado está preparado para
hablar **SCORM 1.2**: busca un objeto JavaScript `window.API` (con
`LMSInitialize`, `LMSSetValue`, `LMSCommit`…) en la ventana padre. `mod_exelearning`
**no es un SCORM**, así que inyecta ese `window.API` falso (el shim) en `view.php`,
junto con el wrapper pipwerks. Cuando el iDevice "guarda su puntuación", llama al
shim, que reenvía los datos por AJAX a `track.php`; ahí se parsea
`cmi.suspend_data` y se reparte por `itemnumber` (una nota por iDevice). **Por qué
se hace así:** es la única vía hoy, porque eXeLearning **no emite xAPI** (FTE-007);
el shim reaprovecha el canal que el contenido ya sabe usar sin tocar el paquete.

## DAFO

**Debilidades (internas)**
- Puente SCORM 1.2 (shim) en vez de xAPI nativo; mayor superficie de código.
- Dependencia del formato `content.xml` y de la estabilidad de `odeIdeviceId`.
- Tope `MAX=100` iDevices; saturación potencial del gradebook.

**Amenazas (externas)**
- Cambios del export de eXeLearning rompen la detección.
- Reasignación de ids upstream al importar (RIE-006).
- Cambios de la Grade API de Moodle; presión a futuro contra SCORM 1.2.

**Fortalezas (internas)**
- **Único** que combina sidebar nativa eXe + calificación **granular por iDevice**.
- Reutiliza piezas probadas (iframe de exeweb, puente SCORM, patrón
  multi-itemnumber del core, modelo de intentos tipo h5pactivity).
- Basado en estándar vigente (SCORM 1.2) + intentos + finalización nativos.
- Verificado end-to-end (Docker + navegador) y CI verde en Moodle 4.5/5.0/5.1.

**Oportunidades (externas)**
- Hoja de ruta **xAPI nativo** (DEC-0-14) cuando eXeLearning emita statements
  → analítica de aprendizaje fina vía LRS.
- Impulso de eXeLearning v4 e interés institucional (ATE/INTEF).
- Posible aportación upstream (ids estables, manifiesto de gradeitems).

## Comparativa

| Dimensión | mod_exeweb | mod_exescorm | mod_scorm (core) | mod_h5pactivity | **mod_exelearning** |
|---|---|---|---|---|---|
| Calificación en libro | ninguna | 1 nota (agregada) | 1 nota | 1 columna | **N columnas (1/iDevice) + global opcional** |
| Granularidad | — | baja | baja | media (1 col, detalle interno) | **alta** |
| Sidebar nativa eXe | sí | no (TOC SCORM) | no | n/a | **sí (iframe)** |
| Tracking | no | SCORM 1.2/2004 | SCORM 1.2/2004 | **xAPI nativo (core_xapi)** | SCORM 1.2 (shim) → multi-itemnumber |
| Intentos | no | sí | sí | sí | **sí (tabla propia + grademethod)** |
| Finalización por nota | no | sí | sí | sí | **sí (completionpassgrade)** |
| Edición in-situ | sí (editor embebido) | parcial | no | editor H5P externo | **sí (editor embebido)** |
| Formato de entrada | `.elpx` v4 | `.elp`/`.elpx` | `.zip` SCORM | `.h5p` | `.elpx` v4 |
| Complejidad de impl. | baja | media | media | media | **media (acotada, ver AN-010)** |
| Tabla de intentos propia | no | sí (`scorm_attempt`…) | sí | sí (`h5pactivity_attempts`) | **sí (`exelearning_attempt`, calca h5p)** |

Matiz sobre **H5P**: `mod_h5pactivity` es el modelo "moderno" (xAPI nativo, gran
detalle de interacción interno), pero **expone una sola columna** en el libro.
La multicalificación de `mod_exelearning` es, de hecho, **más granular en el
gradebook** que H5P — ese es el diferencial. El precio es no tener xAPI nativo
(todavía).

## Decisión / Veredicto

**Sí merece la pena, con matices.**

- **Sí**, porque la **calificación granular por iDevice (N columnas en el libro)**
  es algo que **ningún otro plugin ofrece**: `mod_exescorm`/`mod_scorm` dan una
  sola nota agregada; `mod_h5pactivity` da una columna; `mod_exeweb` no califica.
  Ése es el diferencial buscado y el valor pedagógico (evaluar cada ejercicio
  dentro de un recurso), real y demandado por ATE, implementado y verificado.
  *(La sidebar nativa se preserva y es deseable, pero no es el motivo de ser del
  plugin: es una propiedad heredada de `mod_exeweb`, no el diferencial.)*
- **Con matices**: la complejidad y la dependencia del shim SCORM 1.2 + formato
  `content.xml` son deuda técnica reconocida. La evolución sana es **xAPI nativo**
  (DEC-0-14) cuando eXeLearning emita statements; mientras, el enfoque actual es
  pragmático, funciona y se apoya en estándares vigentes.

En resumen: la multicalificación es la **razón de ser** del plugin y justifica
su coste; el camino de reducción de deuda está trazado (DEC-0-14) y condicionado
a upstream.

## Hoja de ruta de tracking: ¿xAPI o cmi5? (ver FTE-009)

Diferencia esencial: **cmi5 es un perfil de xAPI** para el caso "el LMS lanza el
contenido". xAPI es la capa de comunicación (statements a un LRS); cmi5 le añade
reglas de lanzamiento (AU/fetch URL), `moveOn`/`masteryScore`, sesión/registration
y una secuencia de verbos definida.

**¿Cuál encaja en mod_exelearning?**

- **xAPI puro** es el objetivo natural: Moodle ya lo consume nativamente con
  `core_xapi` (FTE-007: webservice `core_xapi_statement_post` + clase `handler`),
  igual que `mod_h5pactivity` (AN-003). Encaja con el modelo actual (contenido
  **embebido** en Moodle, no lanzado a un LRS externo).
- **cmi5 es probablemente excesivo** aquí: su valor (AU lanzables, fetch URL,
  catálogo de cursos) aplica a LRS externos y a "contenido como servicio", no a un
  recurso embebido y calificado dentro de Moodle. Añadiría complejidad de
  lanzamiento sin beneficio para este caso.

**Qué haría falta en eXeLearning (prerrequisito upstream) — coste estimado.**
Hoy la puntuación ya se calcula y se canaliza por una capa única en el export:
`libs/common.js` namespace `gamification.scorm` (`initScorm()`,
`scorm.set("cmi.core.score.raw", …)`, `SetScoreMax/Min`) + `libs/SCOFunctions.js`
(adaptado de ADL: `doLMSSetValue`, `lesson_status`). Emitir xAPI es **añadir un
emisor hermano en ese mismo punto**, no rehacer nada.

Ficheros a tocar upstream (orden de magnitud):
- `libs/common.js` — añadir `gamification.xapi`: construir el statement
  `{actor, verb:"answered/completed", object.id: IRI(odeIdeviceId),
  result.score.scaled}` y enviarlo por `postMessage` al padre (caso Moodle
  embebido) o `fetch` a un LRS (caso web).
- nuevo `libs/xapi_emit.js` (wrapper fino, análogo a `SCORM_API_wrapper.js`).
- pipeline de export + plantilla: una opción "export con xAPI" que inyecte el
  wrapper y una convención de IRI por iDevice (reusa `odeIdeviceId`, ya estable).

**Coste: medio y acotado** (un puñado de ficheros JS + la opción de export + la
convención de IRI). El punto de enganche es **único y conocido**; no es una
reescritura. La parte de perfil/vocabulario (verbos, activity types) es diseño,
no código.

**Ventajas de hacerlo (para eXeLearning y para el plugin):**
- Tracking **granular por iDevice nativo**, sin el shim SCORM (canal directo a
  `core_xapi` de Moodle y a cualquier LRS).
- Beneficia a **todo el export web** de eXeLearning (analítica fuera de Moodle),
  no sólo a este plugin.
- Elimina a futuro la dependencia frágil del shim y del parseo de `suspend_data`.

**Coste en el plugin (lado Moodle): bajo–medio** — ver más abajo: reusa la misma
tabla de intentos y el mismo `grade_update` multi-itemnumber; sólo cambia el
transporte (shim → statements).

**Coste de una capa de compatibilidad xAPI en el plugin (cuando upstream emita):**
- *Bajo–medio.* Reusa lo ya construido: `classes/xapi/handler.php` extendiendo
  `\core_xapi\handler`, mapeo `object.id → itemnumber` (igual que hoy mapeamos
  `cmi.suspend_data → itemnumber`), y la **misma** tabla `exelearning_attempt` y el
  **mismo** `grade_update` multi-itemnumber. Se sustituye el transporte (shim
  SCORM → statements xAPI), no el modelo de notas.
- El shim SCORM 1.2 podría **coexistir** (paquetes antiguos) o retirarse según la
  versión mínima de eXeLearning soportada.
- cmi5 quedaría fuera de alcance salvo demanda explícita de LRS externo.

**Pasos a futuro (resumen):** (1) seguir/empujar en upstream la emisión de xAPI por
iDevice; (2) cuando exista, implementar `handler` + `xapi_bridge.js` reusando el
modelo de intentos/notas actual; (3) decidir versión mínima y si el shim SCORM se
mantiene como fallback. Detallado en DEC-0-14.

## Consecuencias

- Se mantiene la arquitectura actual (multi-itemnumber + puente SCORM 1.2).
- Se prioriza domar la fragilidad conocida (RIE-006, detección) sobre rehacer el
  canal de tracking, hasta que xAPI nativo sea viable.
- Este ADR es la base del informe ejecutivo PDF (TAREA-007).
