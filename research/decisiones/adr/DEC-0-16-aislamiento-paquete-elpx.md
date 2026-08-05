---
id: DEC-0-16
tracking_issue: 0
legacy_id: DEC-0019
title: "Aislamiento del paquete .elpx (RIE-001): análisis, paridad con core y roadmap"
status: Accepted
date: 2026-06-02
deciders:
  - erseco
  - claude-code
sources:
  - REPO-004
  - REPO-002
  - REPO-005
related:
  adrs: [DEC-0-03, DEC-4-01, DEC-5-01]
see_also:
  - RIE-001
  - AN-008
ai_assistance:
  tool: claude-code
  model: claude-opus-4-8
---

# DEC-0-16: Aislamiento del paquete .elpx (RIE-001): análisis, paridad con core y roadmap

## Contexto

RIE-001 (TAREA-012) es un riesgo de **XSS cross-component**: un paquete eXeLearning
`.elpx` contiene HTML/JS **arbitrario del autor** y se embebe en `view.php` dentro de
un `<iframe>` servido por `pluginfile.php` sobre el **único** `$CFG->wwwroot`. El
iframe lleva hoy `sandbox="allow-scripts allow-same-origin allow-popups allow-forms
allow-popups-to-escape-sandbox"` (`view.php:521-531`). Al compartir origen con Moodle
y permitir scripts, un paquete malicioso puede leer `document.cookie`/DOM, alcanzar
`window.parent` y forjar peticiones autenticadas con el `sesskey` (que se inyecta en la
URL de `track.php`, `view.php:360`). AN-008 ya documentó el sandbox parcial actual y
dejó como HIPÓTESIS la mitigación "real" (subdominio dedicado / `Permissions-Policy`).

Esta ADR cierra la **investigación** de TAREA-012: cómo lo resuelve el core de Moodle
(`mod_scorm`, `core_h5p` usado por `mod_h5pactivity`), qué es viable dentro del plugin
y qué exigiría infraestructura. La metodología fue 5 verificaciones de hechos sobre el
core (`/Users/ernesto/Downloads/git/moodle`) + 14 revisiones adversariales (atacante /
QA) sobre cada mitigación candidata.

**Decisión del usuario (2026-06-02): documentar pros/contras + comparación con core +
roadmap; NO implementar ninguna mitigación todavía.**

## Problema

El contenido del `.elpx` corre **same-origin** con Moodle. Con `allow-scripts` +
`allow-same-origin` sobre un documento del propio origen, el sandbox del iframe **no
aísla de verdad**: Chrome incluso avisa ("an iframe which has both allow-scripts and
allow-same-origin … can escape its sandboxing"). El daño potencial es robo de
sesión/CSRF si una organización admite paquetes de autores no confiables.

## Comparación con core (verificada)

| Módulo | Aislamiento del contenido | Cita |
|---|---|---|
| `mod_scorm` | `<iframe>` **sin `sandbox`** alguno; mismo origen; el SCO recorre `window.parent` buscando `API`/`API_1484_11`. **Estrictamente más débil.** | `public/mod/scorm/player.php:279-285`; `loadSCO.php` (findAPI) |
| `core_h5p` (`mod_h5pactivity`) | Tampoco usa `sandbox`; inyecta el contenido en un iframe `about:blank` vía `contentDocument.write` y comunica por `postMessage` **con validación de origen y contexto**. Pero su contenido son **librerías curadas e instaladas por el admin**, no HTML arbitrario de autor → **otro modelo de amenaza**, no transferible al `.elpx`. | `public/h5p/templates/h5piframe.mustache`; `public/h5p/js/embed.js:38-46` |
| `mod_exelearning` (hoy) | **Sandbox parcial**: bloquea `allow-top-navigation` y `allow-modals`; omite `allow-pointer-lock`/`presentation`/`orientation-lock`. **El más fuerte de los tres.** | `view.php:511-531` |

**Veredicto de paridad:** igual que con RIE-011 (`maxattempt`, DEC-6-01), **core no
resuelve esto**. `mod_scorm` corre HTML no confiable sin ningún sandbox; `core_h5p`
solo se permite no aislar porque su contenido está curado. mod_exelearning, que sí
corre HTML arbitrario, ya es el **mejor aislado de los tres** — pero ninguno logra
aislamiento real por origen, porque core fuerza el servido en un único `wwwroot`.

## Hechos verificados (con cita)

1. **Core NO ofrece un mecanismo de origen separado** (refutado). `$CFG->wwwroot` es
   único y obligatorio; `moodle_url::make_pluginfile_url()` lo hardcodea
   (`public/lib/classes/url.php:732-771`; `public/lib/setup.php:191-198,549-550`). La
   única opción CORS-de-ficheros, `$CFG->h5pcrossorigin`, es para CDN y **relaja** el
   acceso, no lo aísla. → Aislamiento por origen = **infra fuera de core** (reverse
   proxy / vhost dedicado), no es un flag ni un cambio en el plugin.
2. **El bridge SCORM es 100% same-origin** (confirmado; tres dependencias duras):
   - el **padre** lee `iframe.contentDocument` para mapear el `objectid` de cada
     iDevice (DEC-5-01): `resolveObjectMap()` en `view.php:402-415`, alimentando
     `itemscores` en el POST a `track.php` (`view.php:437`);
   - el **hijo** (pipwerks) recorre `window.parent` buscando `window.API`:
     `assets/scorm/SCORM_API_wrapper.js:71-77,130-141` → shim inyectado en el padre
     `view.php:470-495`;
   - el **teacher-mode hider** inyecta `<style>` en el `contentDocument`
     (`lib.php:712-728`, "the iframe is same-origin … so this DOM access is allowed").
   No hay `postMessage` en el flujo de notas hoy (`view.php:514` solo lo cita como
   futuro). → **`allow-same-origin` es estructural**: quitarlo (u origen opaco) hace
   que las tres accesos lancen `SecurityError` y el tracking muera en silencio
   (`isActive=false`, "not part of a SCORM package").
3. **CSP / Permissions-Policy SÍ se pueden emitir** desde `exelearning_pluginfile()`
   antes de `send_stored_file()`. El camino de core (`send_stored_file` → `send_file`
   → `readfile_accel`/`readstring_accel`, en `public/lib/filelib.php`) **nunca** pone
   ni borra esas cabeceras, y `header()` con `replace=true` solo sustituye cabeceras
   del mismo nombre → una CSP fijada antes sobrevive intacta. `X-Frame-Options` tampoco
   se emite en este camino (lo pone `send_headers()` de `weblib.php`, que no se invoca
   para pluginfile), así que el control de framing también recaería en el plugin.
4. **Un CSP no puede ser estricto en `script-src`** (parcial). El motor eXeLearning
   emite `<script>` inline en cada página exportada, hace `eval()` en la init de
   tooltips (`common.js:492/497`) y el propio plugin inyecta un bootstrap inline
   (`lib.php:746-755`). → `'unsafe-inline'` + `'unsafe-eval'` son **inevitables** sin
   reescribir el HTML. jQuery va **empaquetado local** (no CDN); fuentes vía
   `@font-face` relativo; los únicos externos son MathJax (CDN), YouTube IFrame API y
   embeds de autor (mediateca/youtube) → gobernados por `script-src`/`frame-src`.

## Menú de mitigaciones (tiered) — pros/contras y hallazgo adversarial

> Notación: cada mitigación trae el veredicto de la pasada adversarial (atacante / QA).

### Tier 1 — endurecimiento barato (no rompe el bridge)

- **M1 — quitar `allow-popups-to-escape-sandbox`** (`view.php:529`).
  - **Pro:** cierra una escalada real — un popup escapado se abre **sin sandbox** y
    puede navegar el top, minando la omisión deliberada de `allow-top-navigation`.
  - **Contra (QA):** los popups dejan de heredar `allow-modals`, así que **se rompe el
    botón "Imprimir" del popup del iDevice rúbrica** (`window.print()` requiere
    `allow-modals`). Es la única regresión neta (interactive-video usa `confirm/alert`
    en el frame principal, que ya carece de `allow-modals`).
  - **Atacante:** parcial — cierra solo ese vector; el robo de sesión/DOM via
    same-origin sigue abierto.
  - **Trade-off documentado, sin decidir** (decisión del usuario: documentar).

- **M2 — cabecera `Permissions-Policy`** en `exelearning_pluginfile()` (`lib.php`):
  `camera=(), microphone=(), geolocation=(), payment=(), usb=(), serial=(),
  bluetooth=(), hid=(), magnetometer=(), accelerometer=(), gyroscope=(), midi=(),
  display-capture=()`.
  - **QA: AGUANTA LIMPIO** — es la única mitigación sin trade-off. Hay que **excluir
    `fullscreen`** (lo concede el iframe `allow='fullscreen'`, lo usan iDevices) y solo
    tiene sentido en el filearea `content` (no-op en `package`, que es descarga).
  - **Atacante:** ortogonal — sólido para sensores/hardware, no toca el robo de
    sesión/DOM (fuera de su vocabulario).

- **M3 — CSP de red** en `exelearning_pluginfile()`:
  `default-src 'none'; script-src 'self' 'unsafe-inline' 'unsafe-eval'; style-src
  'self' 'unsafe-inline'; img-src 'self' data: blob:; media-src 'self' blob:; font-src
  'self'; connect-src 'self'; frame-src 'self'; child-src 'self'; object-src 'none';
  base-uri 'none'; form-action 'self'; frame-ancestors 'self'`.
  - **Pro:** `connect-src 'self'` corta exfiltración perezosa (XHR/fetch/beacon a hosts
    atacantes); falla-cerrado trackers de terceros baked-in (AddThis, Google Tag
    Manager); `object-src 'none'` + `base-uri 'none'` + `form-action 'self'` +
    `frame-ancestors 'self'` cierran object/`<base>`/form/clickjacking; añade el
    control de framing que core no pone en pluginfile.
  - **Contra (atacante): bypasseable como aislamiento** — el JS same-origin sigue
    leyendo `window.parent`/DOM/`sesskey` y hace `fetch` credenciado a endpoints de
    Moodle (que `connect-src 'self'` permite); `window.open` exfiltra fuera del alcance
    de `connect-src`.
  - **Contra (QA):** **rompe MathJax (CDN `cdn.jsdelivr.net`), la YouTube IFrame API y
    embeds externos** (mediateca/youtube). La pasada adversarial demostró que un toggle
    de admin debe ensanchar **`script-src` + `frame-src` + `img-src` + `connect-src` a
    la vez** (no solo frame/img, como se planteó al principio) o el math/vídeo siguen
    rotos.
  - **Diseño elegido para cuando se implemente (decisión del usuario):** **estricto por
    defecto + un único toggle de admin "permitir contenido externo"** que relaje
    coordinadamente esos cuatro directivas a `https:` (o a una allowlist de hosts).

- **M5 — nonce-stamping de scripts inline: DESCARTADO.** Bypasseable (el atacante usa
  `<script src>` same-origin; no se puede quitar `'self'` sin romper el motor), no
  cubre scripts creados con `createElement` sin `strict-dynamic` (rompe Mermaid/MathJax
  /YouTube), rompe el modelo de caché por revisión (`$lifetime`) al exigir reescritura
  por petición, y es frágil (cualquier bloque inline sin noncear mata el tracking en
  silencio). Coste alto, ganancia marginal sobre M3/M4.

### Tier 2 — aislamiento real (proyecto arquitectónico, futuro)

- **M6 — reescribir el bridge a `postMessage`** (patrón H5P). Prerrequisito de M7.
  - Por sí solo **no mitiga** (con same-origin el `event.origin` check es vacío) y
    **añade superficie**: al mover la resolución de `objectid` al hijo, el paquete
    autoría objectids+scores → **forja de notas** de primera clase salvo que el padre
    fije `event.source === iframe.contentWindow`.
  - **Trampas (QA):** contrato **síncrono** de pipwerks (`LMSGetValue` devuelve valor
    al momento → el hijo debe mantener su propio `cmi{}` local y responder GETs sin red);
    descubrimiento de la API (pipwerks `find/get` solo miran `window.parent`/`opener`,
    no el propio `window`); el flush **síncrono** en `beforeunload`/`LMSFinish` no es
    replicable con `postMessage` async → la última nota al cerrar pestaña puede
    perderse; el teacher-mode hider pasa a "best-effort" dependiente del hijo.

- **M7 — origen opaco o subdominio dedicado.** **Único tier que cierra la raíz.**
  - **Route A (en plugin):** quitar `allow-same-origin` (o emitir
    `Content-Security-Policy: sandbox allow-scripts allow-forms allow-popups`), forzando
    un **origen opaco** aun servido same-site. El paquete deja de poder leer cookies/DOM
    /sesión de `wwwroot`.
  - **Route B (infra, la más fuerte):** servir el filearea `content` desde un
    **hostname distinto** (reverse proxy / vhost). Fuera de core (no hay flag; `url.php`
    hardcodea `wwwroot`). **Cuidado (atacante):** el subdominio debe estar en un
    **eTLD+1 separado** con cookies host-only + `SameSite`, o la cookie de sesión se
    envía igual y se puede hacer `fetch` credenciado de vuelta.
  - **Estrictamente dependiente de M6:** aplicar M7 sin M6 rompe el tracking entero
    (las tres dependencias same-origin lanzan `SecurityError`).
  - **Residuos a cubrir aun con M6:** fijar `postMessage` al `contentWindow` (anti
    forja), y quitar `allow-popups-to-escape-sandbox` (y a poder ser `allow-popups`)
    porque un popup escapado vuelve al origen real de Moodle.

- **M8 — sandboxing de JS en el cliente** (vía a INVESTIGAR — TAREA-013). Alternativa o
  complemento al aislamiento por origen que **mantiene el servido same-origin** pero
  neutraliza la autoridad del JS del paquete ejecutándolo en un contexto restringido.
  Tecnologías a evaluar:
  - **ShadowRealm** (TC39, Stage 3): global aislado síncrono en proceso, sin acceso al
    DOM/`window` del host. Soporte de navegadores aún parcial.
  - **SES / hardened JS + Compartments** (Agoric `ses` / `lockdown()`): congela los
    intrínsecos y ejecuta el código no confiable en un `Compartment` con un `globalThis`
    mediado.
  - **Web Worker + proxy de DOM**: correr el JS del paquete en un worker (sin DOM) y
    exponer un DOM virtual mediado por `postMessage` (modelo `WorkerDOM` de AMP).
  - **Intérprete JS en WASM** (QuickJS/`quickjs-emscripten`, estilo plugins de Figma):
    ejecuta el código del autor fuera del motor JS del navegador, con APIs explícitas.
  - **Librerías tipo `sandboxjs` / `vm`-en-navegador**: sandboxes basados en `Proxy`
    sobre un `globalThis` falso (menor garantía; históricamente evadibles).
  - **Veredicto preliminar (a verificar en TAREA-013):** **viabilidad probablemente
    baja** con el motor eXeLearning v4 tal cual, porque el paquete asume un **DOM real +
    jQuery + pipwerks** y manipula el documento directamente; un worker sin DOM o un
    realm sin DOM no lo ejecutan sin un shim de DOM completo (coste alto y frágil).
    Donde sí podría encajar: aislar **solo** el JS de iDevices/autor de alto riesgo, o
    como capa extra sobre M7. Decidir tras el espurio coste/beneficio frente a M6+M7.

## Decisión

**Documentar y aceptar la postura actual.** mod_exelearning ya supera a `mod_scorm` y a
`core_h5p` en aislamiento same-origin; RIE-001 es severidad media / probabilidad baja
(solo material en organizaciones que aceptan `.elpx` de autores no confiables). **No se
implementa ninguna mitigación ahora.** RIE-001 permanece `identificado` con un plan
documentado.

**Roadmap recomendado** (cuando se priorice el hardening):
1. **Tier 1** (mejora real, bajo riesgo, no rompe el bridge): **M2** (limpio) + **M3**
   con el diseño **estricto-por-defecto + toggle de admin** + decidir **M1** (asumiendo
   la pérdida del print de la rúbrica, documentada). Tras Tier 1, mod_exelearning sería
   inequívocamente el mejor aislado de los tres siendo aún same-origin.
2. **Tier 2** (aislamiento de verdad): **M6 → M7**, en su **propio ADR**, con
   reescritura del camino de notas verificado y re-validación Behat/PHPUnit completa.

## Consecuencias

- Positivas: queda registrado, con evidencia citable, que la postura actual ya excede a
  core, y existe un camino de hardening priorizado y adversarialmente revisado para
  cuando haga falta. El backlog de prioridad alta queda vacío.
- Negativas / coste: el riesgo same-origin sigue presente (aceptado). Si una
  organización empieza a aceptar paquetes de terceros, conviene activar Tier 1.

## Riesgos

- **RIE-001 — sigue `identificado`** (no mitigado en código). Mitigación v1 vigente: el
  sandbox parcial de AN-008. Plan de mitigación documentado aquí.

## Validación

- Hechos del core verificados con cita `file:line` reproducible en
  `/Users/ernesto/Downloads/git/moodle` (url.php, setup.php, filelib.php, weblib.php,
  mod/scorm/player.php, h5p/js/embed.js).
- Dependencias same-origin del bridge verificadas en `view.php`, `lib.php` y
  `assets/scorm/SCORM_API_wrapper.js` (citas en §Hechos).
- Sin cambios de código → no se ejecuta PHPUnit/behat para esta ADR.

## Seguimiento

- Implementación Tier 1 (M2 + M3 estricto-con-toggle + M1): trabajo futuro priorizable;
  el diseño del CSP y del toggle ya está fijado arriba.
- Implementación Tier 2 (M6 → M7): proyecto arquitectónico futuro, ADR propio.
- **TAREA-013**: investigar M8 (sandboxing de JS en el cliente: ShadowRealm, SES/
  Compartments, Web Worker + DOM proxy, QuickJS-WASM, librerías tipo `sandboxjs`) como
  alternativa/complemento que mantiene el servido same-origin. Evaluar viabilidad real
  con el motor eXeLearning (DOM + jQuery + pipwerks) y coste/beneficio frente a M6+M7.
