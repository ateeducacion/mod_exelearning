---
id: DEC-0059
titulo: "Bridge SCORM por postMessage + iframe de origen opaco (modo seguro configurable): implementación de RIE-001 Tier 2"
estado: Aceptada
fecha: 2026-06-13
agentes:
  - erseco
  - claude-code
fuentes:
  - REPO-002
  - REPO-004
  - REPO-005
experimentos: []
relacionados:
  - RIE-001
  - AN-008
  - DEC-0019
  - DEC-0017
  - DEC-0042
  - TAREA-017
herramienta_ia:
  interfaz: claude-code
  modelo: claude-opus-4-8
---

## Contexto

`DEC-0019` cerró la investigación de RIE-001 (XSS cross-component desde un `.elpx`
malicioso) y dejó dos tiers documentados pero **sin implementar por decisión del
usuario**. Su roadmap fijaba explícitamente que el aislamiento real (Tier 2) iría en
**"su propio ADR"**: `M6 — reescribir el bridge a postMessage → M7 — origen opaco`.

Esta ADR es ese ADR. El usuario priorizó implementar el **bridge seguro** completo
(no sólo el endurecimiento Tier 1), configurable y con valor por defecto seguro, tras
confirmar que es la única alternativa que cumple los objetivos de aislamiento (el
contenido NO puede leer ni modificar el DOM/cookies/sesión/JS del marco padre de
Moodle). **No supersede a DEC-0019**: la implementa/avanza.

El problema de fondo (ya documentado en DEC-0019) es que el SCORM era 100%
same-origin, con **tres dependencias duras** que hacían que quitar `allow-same-origin`
matase el tracking en silencio:

1. el **padre** inyectaba `window.API` y el hijo (pipwerks) lo buscaba recorriendo
   `window.parent`;
2. el **padre** leía `iframe.contentDocument` para mapear el `objectid` de cada
   iDevice (DEC-0017);
3. el **teacher-mode hider** inyectaba `<style>` en el `contentDocument`.

## Problema

¿Cómo aislar el contenido `.elpx` (HTML/JS arbitrario del autor) del marco Moodle
**sin romper** el scoring SCORM ni la detección por `objectid`, dentro del modelo de
Moodle (un único `$CFG->wwwroot`, sin origen separado en core), de forma configurable
y compatible hacia atrás?

## Hechos verificados (con cita)

1. **El pipwerks empaquetado descubre el `window.API` de su PROPIA ventana antes de
   subir al padre.** `assets/scorm/SCORM_API_wrapper.js:71-106`: en `find(win)` el
   bucle `while ((!win.API && !win.API_1484_11) && win.parent && win.parent != win)`
   parte de `win = window`; si `window.API` existe en el iframe, el bucle no se
   ejecuta y devuelve el API local. → **Inyectar el shim `window.API` DENTRO del
   iframe hace que pipwerks lo use sin tocar el padre.** Es el linchpin que vuelve
   viable el origen opaco.
2. **`LMSGetValue` ya responde de una caché `cmi{}` local** (`js/scorm_tracker.js`,
   `LMSGetValue: function (k) { return cmi[k] || ''; }`). El contrato síncrono de
   pipwerks se satisface sin red, así que un relevo **asíncrono por `postMessage`**
   para las escrituras (`LMSSetValue`/`Commit`/`Finish`) es suficiente; no hace falta
   round-trip síncrono al padre.
3. **El almacenamiento web lanza `SecurityError` en un documento de origen opaco**, y
   el motor exportado de eXeLearning lo usa de forma incondicional en código que viaja
   en TODO export: `public/libs/exe_atools/exe_atools.js` (barra de accesibilidad),
   `public/app/common/exe_export.js`, el iDevice `checklist`
   (`.../checklist/export/checklist.js`) y `edicuatex`. Sus guardas `typeof
   localStorage` NO protegen (en origen opaco el acceso al getter, no el `typeof`,
   es lo que lanza). → **El shim debe instalar un polyfill de `localStorage`/
   `sessionStorage` en memoria** antes de que corra el contenido, o esos scripts
   rompen. (Verificación de uso: `rg "localStorage|sessionStorage"` sobre
   `/Users/ernesto/Downloads/git/exelearning`.)
4. **Comparación con core (re-verificada, coherente con DEC-0019):**
   - `core_h5p` valida `postMessage` por `event.source === window.parent` + un token
     de contexto (`event.data.context === 'h5p'`), pero **sin** validar `event.origin`
     y con contenido **curado** (`public/h5p/js/embed.js:38-46`). Patrón de
     acción/contexto reutilizable, modelo de amenaza distinto.
   - `mod_scorm`: iframe **sin `sandbox`**, mismo origen, el SCO recorre `window.parent`
     (`public/mod/scorm/player.php`, `loadSCO.php` findAPI). Más débil.
   - `mod_lti`: usa `allow=` (Permissions-Policy) + `X-Frame-Options`, no `sandbox`.
   - SimplePie es el **único** uso de `sandbox` en core (`allow-scripts
     allow-same-origin` para feeds). → **No existe precedente en core de un iframe
     sandbox de origen opaco con bridge**; se adopta el patrón de **identidad de
     ventana** de `wp-franer` (iframe sin `allow-same-origin`, validación por
     `event.source === iframe.contentWindow`, credenciales sólo en el padre) más la
     **lista cerrada de acciones + token de contexto** estilo H5P.

## Opciones consideradas

1. **A — No hacer nada (statu quo).** Mantiene RIE-001 abierto; no cumple los
   objetivos. Rechazada.
2. **B — Sólo `sandbox="allow-scripts"` (sin same-origin) sin bridge.** Rompe el
   tracking (las 3 dependencias lanzan `SecurityError`). Inviable sin M6.
3. **C — Bridge `postMessage` + origen opaco (M6+M7 Route A).** Elegida.
4. **D — Configurable (modo global) sobre C.** Adoptada como parte de C: ajuste
   `iframemode` (secure|legacy), default secure.
5. **E — Origen separado / subdominio (M7 Route B) o CSP de red completa (M3).**
   Route B requiere infra fuera de core (descartada para esta issue); M3 queda como
   follow-up opcional complementario, no bloqueante.

## Decisión

Implementar **C + D**: bridge SCORM por `postMessage` con el iframe en **origen
opaco**, **configurable** mediante un único ajuste global de administración
`mod_exelearning/iframemode` (`secure` por defecto | `legacy` de respaldo). Sin campo
por actividad (no se toca BD/upgrade/backup).

### Arquitectura

- **`\mod_exelearning\local\ui\player_iframe`** (`classes/local/ui/player_iframe.php`):
  resuelve el modo (fail-safe a `secure` ante valor inválido) y centraliza la lista de
  tokens del `sandbox` por modo (testeable sin renderizar `view.php`).
- **Modo `secure`** (`view.php`): el `sandbox` es `allow-scripts allow-popups
  allow-forms` (sin `allow-same-origin` ni `allow-popups-to-escape-sandbox`; sigue sin
  `allow-top-navigation` ni `allow-modals`). El padre NO inyecta `window.API`; inyecta
  inline el **relevo** `js/scorm_bridge_relay.js` y le pasa `{iframeid, cmid, trackurl
  (con sesskey+mode), session, nonce, teachermodevisible}`. El `sesskey` NUNCA cruza el
  bridge.
- **Shim en el iframe** (`js/scorm_bridge_shim.js`, copiado a `libs/exe_scorm_bridge.js`
  e inyectado al inicio de `<head>` por `scorm_injector`): se autoactiva sólo en origen
  opaco (`window.origin === 'null'`), instala el polyfill de storage, define
  `window.API` local reutilizando `js/scorm_tracker.js` (con la nueva opción
  `transport`), resuelve `objectid` de SU propio documento y postMessea los deltas al
  padre. Oculta el teacher-mode localmente con el flag del handshake.
- **`js/scorm_tracker.js`**: nueva opción `transport(data, sync)` que, cuando está
  presente, sustituye al XHR directo (el modo legacy y los tests siguen usando el XHR).
- **Modo `legacy`**: idéntico a hoy (same-origin, `window.API` en el padre, hider por
  `contentDocument`). El shim baked queda dormido (origen no opaco).
- **Permissions-Policy** (DEC-0019 M2, limpio): se mantiene como follow-up recomendado
  para `exelearning_pluginfile()` (no incluido aún en este cambio para acotar el PR).

### Protocolo del bridge

```
hijo→padre:  { exelearningBridge:<nonce>, type:'scorm', action:'ready'|'track',
               cmi:{...}, itemscores:{ <objectid>:{scorepct,weighted,title} } }
padre→hijo:  { exelearningBridge:<nonce>, type:'scorm', action:'config'|'ack',
               teachermodevisible:0|1 }
```

Handshake: el iframe carga → el shim postMessea `ready` → el padre valida
`event.source === iframe.contentWindow`, responde `config` con el `nonce` + el flag de
teacher-mode → los `track` posteriores llevan el `nonce` y el padre revalida `source`.
Validación del padre (defensa en profundidad; `track.php` revalida y clampa
server-side): identidad de ventana (ancla primaria) + `type==='scorm'` + `action` en
lista cerrada + `nonce` + forma del payload (`cmi` objeto). Mensajes desconocidos o
inválidos se ignoran en silencio. El flush al cerrar pestaña lo hace el **padre** con
`navigator.sendBeacon` en `pagehide` (same-origin, fiable), no un XHR síncrono.

## Consecuencias

- **Positivas:** RIE-001 pasa a `mitigado` por defecto. El contenido no puede leer
  `parent.document`/`document.cookie`/sesión ni forjar peticiones con el `sesskey`
  (que se queda en el padre). El ruteo por `objectid` (DEC-0017) y el guard de
  form/scrambled (DEC-0042) se preservan. El flush por `sendBeacon` es más fiable que
  el XHR síncrono en `beforeunload`.
- **Negativas / coste:** mayor superficie JS (shim + relevo, ambos con tests Vitest);
  el almacenamiento web del contenido pasa a ser por-sesión (no persiste entre
  recargas) en modo seguro; al quitar `allow-popups-to-escape-sandbox` se pierde el
  `window.print()` del popup de la rúbrica (regresión conocida de DEC-0019 M1). El
  modo `legacy` cubre cualquier paquete que falle bajo origen opaco.
- **Cambios que dispara:** RIE-001 → `mitigado`; abre TAREA-017 (implementación) y deja
  como follow-up opcional M2 (Permissions-Policy) y M3 (CSP de red con toggle).

## Riesgos

- **RIE-001** — pasa a `mitigado` (modo seguro por defecto). Residuo aceptado:
  organizaciones que activen `legacy` por compatibilidad vuelven al riesgo same-origin.
- **Compatibilidad de origen opaco:** verificar en Chrome y Firefox reales que el
  polyfill (`Object.defineProperty(window,'localStorage',…)`) sustituye el getter
  nativo (la configurabilidad varía entre motores; el shim degrada con try/catch).
- **Behat de grading `@javascript`:** el escenario que usa `window.API.LMSSetValue`
  asume el API en el padre; en `secure` vive en el iframe → adaptar o apoyarse en el
  paso server-side `the following eXeLearning SCORM scores exist` (mode-agnóstico).

## Limitación conocida (entornos PHP-WASM / service worker)

Los playgrounds PHP-WASM (p. ej. el Moodle Playground en GitHub Pages) sirven
`pluginfile.php` mediante un **service worker**. Un service worker **NO controla
iframes de origen opaco** (sandbox sin `allow-same-origin`), así que en modo `secure`
la petición del iframe a `content/<rev>/index.html` no la intercepta el SW, cae al
hosting estático y devuelve 404 (verificado por HAR: `_fetchedViaServiceWorker:false`,
`server: GitHub.com`, `x-cache: HIT`). Además ese 404 lo cachea el CDN, así que tras
un intento en `secure` el modo `legacy` puede seguir mostrando el 404 cacheado en la
misma pestaña hasta recargar en limpio. **Mitigación:** el `blueprint.json` del
Playground fuerza `iframemode=legacy`. En un Moodle real `pluginfile.php` es un
endpoint de servidor y el iframe de origen opaco lo carga con normalidad, así que el
modo `secure` por defecto funciona; la limitación es exclusiva de los hosts WASM/SW.

## Validación

- `php -l` limpio; **phpcs `--standard=moodle` 0/0** sobre los ficheros tocados.
- **Vitest 40/40 verde** (`make test-js`): 16 del tracker (sin regresión por
  `transport`) + 24 nuevos en `tests/js/scorm_bridge.test.js` (shim: polyfill de
  storage, detección de origen opaco, handshake, cola pre-nonce, identidad de fuente,
  ocultado teacher-mode; relevo: validadores puros, identidad de ventana, nonce, forma,
  cuerpo a `track.php`, `sendBeacon`).
- **PHPUnit** `tests/player_iframe_test.php` (`@covers \mod_exelearning\local\ui\
  player_iframe`): default secure, fail-safe ante valor inválido, legacy respetado,
  tokens del sandbox por modo (sin `allow-same-origin`/`allow-top-navigation`/
  `allow-modals` en secure). Se ejecuta en contenedor/CI (PHPUnit no corre en host).
- **Behat** (CI): grading e2e en modo secure + escenarios de report (mode-agnósticos).

## Actualización (2026-06-28): teacher-mode por parámetro core + relación con el bridge de medios

- **Teacher-mode ya no depende del bridge.** Esta ADR ocultaba el conmutador de profesor en `secure`
  con el **flag del handshake** (`teachermodevisible` en `config`, líneas 124/131/145) porque en opaco
  el padre no puede inyectar CSS. Desde [[DEC-0070]] (PR 86) la **revelación** del contenido de profesor
  la gobierna el **parámetro core `?exe-teacher`** que viaja en el `src` y el paquete lee desde dentro
  del iframe — compatible con origen opaco y **sin** inyección. La ocultación vía flag del bridge (y el
  hider `contentDocument` de legacy) quedan **superseded** para ese fin; el flag puede mantenerse por
  compatibilidad pero la fuente de verdad es el parámetro de URL.
- **Dos handshakes distintos.** El bridge **SCORM** de esta ADR (`type:'scorm'`) y el de **medios
  externos / embeds** ([[DEC-0061]], `type:'exe-embed'`) son **canales independientes** (no se pisan;
  tipos de mensaje distintos, relés separados). [[DEC-0071]] **alinea ambos** al patrón endurecido
  **nonce por vista + `MessagePort` con capability** (el de SCORM ya usa nonce; el de embeds lo
  incorporará), y el de xAPI ([[DEC-0069]]) sigue la misma disciplina de identidad de ventana.

## Seguimiento

- **TAREA-017**: implementación del bridge seguro (esta ADR).
- Follow-up opcional: **M2** Permissions-Policy y **M3** CSP de red estricta-con-toggle
  en `exelearning_pluginfile()` (DEC-0019); **M8** sandboxing de JS en cliente
  (TAREA-013) sigue pendiente como capa extra.
