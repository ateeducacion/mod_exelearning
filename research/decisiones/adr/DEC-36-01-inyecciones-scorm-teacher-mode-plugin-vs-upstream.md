---
id: DEC-36-01
title: "Inyecciones SCORM-loader y teacher-mode: corrección en el plugin vs upstream en eXeLearning"
status: Accepted
date: 2026-06-10
tracking_issue: 36
legacy_id: DEC-0046
deciders:
  - erseco
  - claude-code
sources:
  - REPO-004
related:
  adrs: [DEC-80-06, DEC-34-02, DEC-13-11, DEC-17-01, DEC-0-16]
ai_assistance:
  tool: claude-code
  model: claude-fable-5
---

# DEC-36-01: Inyecciones SCORM-loader y teacher-mode: corrección en el plugin vs upstream en eXeLearning

> **Superseded-in-part (2026-06-28).** La inyección **#2 (teacher-mode hider)** descrita aquí es ya
> **histórica**: la función `exelearning_require_teacher_mode_hider()` se **eliminó** (PR 86) y el
> teacher-mode se revela por el **parámetro core `?exe-teacher`**, compatible con origen opaco y sin
> mutar el paquete — ver **[[DEC-80-06]]**. La inyección **#1 (SCORM-loader)** y el resto del análisis
> de esta entrada siguen vigentes salvo donde [[DEC-80-01]]/[[DEC-80-02]]/[[DEC-34-02]] los cubran.

## Contexto

El informe técnico comparativo (mod_exescorm / mod_exeweb / mod_exelearning) marca como
**deuda técnica nº1 y mayor riesgo de regresión** del plugin dos *workarounds* que acoplan
`mod_exelearning` a detalles internos del export/runtime de eXeLearning v4. Cualquier
cambio en cómo eXe genera el paquete puede romperlos. Son:

1. **`exelearning_inject_scorm_loader()`** (`lib.php:906`; llamada en extracción desde
   `exelearning_extract_stored_package()`, `lib.php:861`). Reescribe el `<head>` de cada
   `.html`/`.htm` del paquete para inyectar `<script src=".../libs/SCORM_API_wrapper.js">`,
   `<script src=".../libs/SCOFunctions.js">` y un `setInterval` que fuerza
   `pipwerks.SCORM.init()`. **Por qué:** el export **web** de eXe no carga el wrapper SCORM
   (solo lo trae el export **SCORM**), y eXe solo invoca `init()` en el flujo "al hacer
   clic en guardar"; con `isScorm==1` (auto-guardado tras cada pregunta) nunca se llama, así
   que sin el wrapper + init forzado los iDevices calificables muestran *"this page is not
   part of a SCORM package"*. Acoplado a la lógica interna de `libs/common.js:1052`.

2. **`exelearning_require_teacher_mode_hider()`** (`lib.php:880`; llamada en `view.php:586`).
   Encola JS de página-padre que inyecta un `<style>` en el `contentDocument` del iframe
   (same-origin, servido vía `pluginfile.php`) para ocultar `#teacher-mode-toggler-wrapper`,
   el conmutador de "modo profesor" que pinta el export de eXe y que no procede en la vista
   embebida de Moodle (paridad con `mod_exeweb`).

El shim SCORM 1.2 de `view.php` (`window.API` en la página padre) **no** es deuda y se
mantiene; el problema es la **mutación/inyección sobre el contenido del paquete**.

Este ADR registra el **análisis de corrección** de ambas y, en particular, el tradeoff de
arreglarlas **upstream en eXeLearning** frente a hacerlo **en el plugin**. No implementa
nada: la implementación plugin-side ya está diseñada en [[DEC-34-02]] (diferida) y la vía
definitiva (xAPI) en [[DEC-17-01]].

## Análisis por inyección

### #1 — SCORM-loader

- **En el plugin:**
  - *Corto/medio plazo:* transformación **en tiempo de servido** ([[DEC-34-02]]): inyectar al
    servir (`exelearning_pluginfile()`) dejando los ficheros del paquete prístinos, en vez
    de reescribirlos en extracción. Reduce la mutación at-rest pero **mantiene el
    acoplamiento** a los internals de eXe (sigue habiendo que conocer dónde/cómo inyectar).
  - *Definitivo:* migrar el tracking a **`core_xapi`** ([[DEC-17-01]]), que elimina el shim
    SCORM 1.2 **y** la inyección. Gated a que upstream `exelearning#1867` congele el contrato
    xAPI; aún no disponible.
- **En eXeLearning (upstream):**
  - Que el export **web** incluya el wrapper SCORM y lo **auto-inicialice** cuando
    `isScorm>0`, o que exista un **perfil de export "embebido/LMS"** pensado para empotrar.
  - **Nota clave (no reutilizar el export SCORM actual):** servir directamente el export
    SCORM de eXe *no* es solución — arrastra el **ciclo de vida SCO** (doble-commit en cada
    navegación: `unloadPage→LMSFinish` + `beforeunload`; `lesson_status`/`session_time`
    per-página) y una **CSS de presentación distinta** (cabecera/sidebar SCORM). El plugin
    sirve a propósito el export **web** (`body class="exe-export exe-web-site"`, sin
    `exe-scorm`) para evitar esas secuelas (ver [[DEC-13-11]]).

### #2 — Teacher-mode hider

- **En el plugin:** mover el ocultado a la **transformación servida** ([[DEC-34-02]]) — un
  `<style>` inyectado en el HTML servido — o a un **CSS servido** referenciado por el
  contenido, en vez de manipular el `contentDocument` del iframe en runtime. Es un cambio
  cosmético same-origin de bajo riesgo; la versión actual ya funciona.
- **En eXeLearning (upstream):** un **flag de export "modo embebido"** que no renderice el
  conmutador de modo profesor (o lo haga opt-in). Entonces el plugin no inyectaría nada.

## eXe (upstream) vs plugin — ventajas e inconvenientes

| | Arreglar en **eXeLearning** | Arreglar en el **plugin** |
|---|---|---|
| **Ventajas** | Elimina el acoplamiento a internals (riesgo nº1 del informe). Paquetes servidos **prístinos**. Beneficia a **todos** los embebedores (`mod_exeweb`, `mod_exescorm`, contenttype, Nextcloud), no solo a este plugin. Alineado con el roadmap de eXe (xAPI, `#1867`). | **Control total e inmediato**. Funciona para **cualquier `.elpx`** sin importar qué versión de eXe lo generó. Sin dependencia externa. |
| **Inconvenientes** | Fuera del control del equipo del plugin; depende de que eXe lo acepte y publique (cadencia de releases lenta). **Dependiente de versión:** los `.elpx` generados con versiones anteriores **seguirán necesitando** el workaround → **no se puede borrar el código del plugin** salvo que se imponga una **versión mínima de eXe** (poco realista para paquetes ya subidos). Precedente desfavorable: `exelearning#1925` se cerró **"by-design"** (el export web no es responsabilidad de eXe). Coste de coordinación. | Mantiene la **deuda y el acoplamiento** (la crítica del informe). **Frágil** ante cambios internos de eXe (`libs/common.js`). La transformación serve-time añade su propia complejidad (camino `pluginfile`, diferencias `send_file`/`send_stored_file`). |

## Decisión

Adoptar una estrategia **híbrida**:

1. **Implementación de referencia en el plugin:** la **transformación en tiempo de servido**
   ([[DEC-34-02]]) es el fix robusto, porque funciona para **todos** los `.elpx`
   independientemente de la versión de eXe. Se amplía su alcance para cubrir **también** la
   inyección #2 (teacher-mode), que hasta ahora quedaba "as-is": pasa a aplicarse vía la
   misma transformación servida en lugar de manipular el `contentDocument`.
2. **Opción upstream documentada (no ejecutada ahora):** se reconoce que el arreglo ideal
   a largo plazo vive en eXeLearning (export que incluye/auto-inicializa el wrapper +
   flag de modo embebido). Se **documenta** como camino de reducción de deuda; **no se
   redactan ni abren incidencias upstream en esta entrega** (decisión del usuario). Queda
   como acción futura opcional.
3. **No eliminar el workaround del plugin** aunque eXe lo corrija: por
   retrocompatibilidad con los `.elpx` heredados, el plugin debe conservar la lógica salvo
   que en el futuro se decida exigir una versión mínima de eXe (ver [[DEC-13-08]] / política
   de versiones).
4. **Salida definitiva:** cuando `core_xapi` ([[DEC-17-01]]) sea viable (upstream `#1867`),
   desaparece la necesidad del shim y de la inyección #1; este ADR y [[DEC-34-02]] quedarían
   superseded en esa parte.

## Consecuencias

- **Sin cambio de código en esta entrega** (solo documentación). La implementación
  plugin-side sigue pendiente en [[DEC-34-02]], cuyo alcance se amplía para incluir el
  teacher-mode hider (#2).
- Queda trazado el porqué de **no** servir el export SCORM de eXe (secuelas del ciclo SCO),
  para que futuras revisiones no reabran esa vía sin contexto.
- No se crea deuda nueva; se clarifica la existente y su hoja de salida (plugin → upstream
  opcional → xAPI definitivo).
- Acción futura opcional: si se prioriza, abrir en `exelearning/exelearning` (a) export web
  con wrapper SCORM auto-inicializado y (b) flag de export "modo embebido" sin toggle de
  profesor; enlazar aquí los números de incidencia.
