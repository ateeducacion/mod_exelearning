---
id: DEC-80-07
title: "Estrategia unificada de medios externos en origen opaco: overlay inline + canal id-only + control del vídeo interactivo por MessagePort (sin nonce-como-auth)"
status: Accepted
date: 2026-06-28
tracking_issue: 80
legacy_id: DEC-0071
deciders:
  - erseco
  - claude-code
sources:
  - REPO-005
  - REPO-010
  - REPO-011
related:
  adrs: [DEC-80-01, DEC-80-02, DEC-80-03, DEC-80-06]
ai_assistance:
  tool: claude-code
  model: claude-opus-4-8
---

# DEC-80-07: Estrategia unificada de medios externos en origen opaco: overlay inline + canal id-only + control del vídeo interactivo por MessagePort (sin nonce-como-auth)

## Contexto

Con el teacher-mode ya resuelto sin inyección ([[DEC-80-06]]), se reanuda el trabajo de **medios
externos en origen opaco**. [[DEC-80-03]] dejó el vídeo YouTube/Vimeo y el PDF **funcionando** en modo
seguro mediante *promote-to-parent* con **overlay inline**, pero con una limitación documentada (sus
líneas 187-210): el **iDevice de vídeo interactivo** con fuente **remota** no funciona en opaco, y el
prototipo de puente de control se **revirtió por frágil**. La comparativa [[AN-015]] dejó como
`[PENDIENTE]` decidir la integración complementaria con el bridge de eXeLearning para recuperar el
*timing* de preguntas.

Antes de decidir conviene separar **dos problemas que se confunden con frecuencia** (la síntesis
automática los mezcló):

- **Problema A — propagación del sandbox (el del modo opaco).** El `sandbox` sin `allow-same-origin`
  del iframe de contenido **se propaga** al iframe anidado del player → YouTube/Vimeo pierden su origen
  (`youtube.com`/`vimeo.com`), no acceden a su storage y quedan **en blanco**. Lo arregla **únicamente**
  promover el player a un contexto no-opaco (overlay/modal en el padre) o `allow-same-origin` (prohibido
  en el iframe de contenido). **`referrerpolicy` NO lo arregla** (W3C Referrer Policy: "if document's
  origin is an opaque origin, return no referrer"). Es lo que verificó empíricamente [[DEC-80-03]].
- **Problema B — Error 153 / referrer.** Independiente del sandbox: ocurre **incluso sin** sandbox,
  cuando el host envía `Referrer-Policy: no-referrer`/`same-origin` y el `<iframe>` del player **no**
  lleva `referrerpolicy`. YouTube responde `Error 153 (embedder.identity.missing.referrer)`. Lo arregla
  el **atributo `referrerpolicy` por-iframe**, que **anula** la cabecera de la página (MDN: la
  precedencia es element-level). Caso real (red Medusa, reportado por Humberto ATE): la infraestructura
  fijaba `Referrer-Policy: no-referrer` y **Jetpack eliminaba el atributo** `referrerpolicy` del embed
  (Gutenberg lo ponía a `strict-origin-when-cross-origin`); desactivar esa función de Jetpack lo
  resolvía. Lección para los embebedores: no dejar que un sanitizador/plugin elimine `referrerpolicy`.

**mod_exelearning ya resuelve ambos**: A con el overlay promote-to-parent y B con
`referrerpolicy=strict-origin-when-cross-origin` en el player promovido (`DEC-80-03:69-70`). Por tanto
**el vídeo simple ya se ve en opaco** (verificado en vivo en [[DEC-80-03]]). Lo que queda abierto es (i)
**endurecer el trust model** del relay de mod y (ii) **recuperar el vídeo interactivo** sin abandonar la
UX de overlay inline elegida por erseco.

## Decisión

**Overlay inline endurecido + arreglo del vídeo interactivo en el iDevice.** Se conserva la UX y la
frontera de seguridad de [[DEC-80-03]] (el player lo monta y sandboxea el **host**, no el productor) y se
incorpora la disciplina de canal del bridge de eXeLearning **sin** cambiar a modal.

1. **Mantener el overlay inline** (`js/exe_embed_relay.js`) como frontera canónica: el player se ve en
   su sitio (geometría sincronizada), indistinguible de un embed normal. **No** se adopta el modal/
   lightbox del bridge de eXe (descartado por erseco por la UX).

2. **Endurecer el trust model** del relay alineándolo al patrón del bridge de eXeLearning, hoy más
   fuerte que el de mod (que sólo usa identidad de ventana + `targetOrigin '*'`):
   - **nonce por vista** acuñado por el padre en el handshake y exigido en cada mensaje posterior;
   - **MessageChannel con capability** (`MessagePort` transferido) para el tráfico tras el handshake, de
     modo que un sub-frame hostil anidado **no tenga referencia al puerto** y no pueda inyectar comandos;
   - **estrechar el canal**: cruzar sólo `{provider, videoId}` (no la URL del autor) para los proveedores
     reconocidos y **reconstruir** la URL canónica en el padre desde plantilla fija (elimina la clase
     *URL-laundering*); **mantener la guarda D1** para el camino genérico OPEN, donde sí se promueve por
     URL. Reutilizar el módulo de política compartido al estilo de `exe_media_policy.js`.
   - Factorizar un **relay del padre reutilizable estilo `exe-media-host.js`**, en la medida de lo
     posible, **pero con renderer de overlay** (no modal), para reducir la triplicación mod/wp/omeka que
     hoy vigila `tools/check-embed-sync.mjs`.

3. **Arreglar el vídeo interactivo modificando el iDevice de eXeLearning** (autorizado por erseco; es el
   "arreglo correcto upstream" que [[DEC-80-03]]:207-210 ya señalaba que debía vivir en el iDevice):
   - El iDevice **detecta origen opaco** (`window.origin === 'null'`) y, en ese caso, **enruta su control
     de reproducción** (play/pause/seek/getCurrentTime/getDuration + eventos `timeupdate`/`ended`/`ready`,
     más `hide`/`show` para las preguntas cronometradas) por el **canal del bridge endurecido**, en lugar
     de instanciar directamente `new YT.Player`.
   - **Clave que evita la fragilidad del prototipo revertido:** con el **overlay** el player real sigue la
     geometría del `#player` del iDevice, así que **la layout del iDevice (portada/`Inicio`, `float:left`
     de `#player` al activar, posición de cada slide) permanece en el hijo** y **no** se reconstruye en el
     padre. El padre sólo ejecuta los comandos de control sobre el player que ya está superpuesto en su
     sitio. Esto supera la limitación de [[DEC-80-03]] (el prototipo se revirtió porque intentaba
     reconstruir la layout en el padre).
   - **Degradación grácil:** sin bridge cooperante (o paquete antiguo), el iDevice degrada a embed simple
     (lo promociona el shim, sin interactividad) o muestra aviso — nunca un frame en blanco.
   - El canal de control va **autenticado por el mismo nonce/MessagePort** del punto 2 (no abre una vía
     de confianza nueva).

4. **Higiene de referrer (Problema B), ya presente, confirmada como invariante:** todo player promovido
   lleva `referrerpolicy=strict-origin-when-cross-origin` (PDF: `no-referrer`). El iframe de **contenido**
   conserva su `no-referrer`. Evaluar `referrerpolicy=origin` a secas si también resuelve el Error 153
   (fuga aún menor). Documentar para los embebedores host: fijar la cabecera de respuesta
   `Referrer-Policy: strict-origin-when-cross-origin` y **no** permitir que un plugin/sanitizador
   (p. ej. Jetpack en WordPress) elimine el atributo.

5. **Vimeo — caveat operativo de despliegue:** la **privacidad por dominio** de Vimeo valida el **host
   del Referer**. Con promote-to-parent el player corre en el **padre (origen real del LMS)** y, con
   `referrerpolicy=strict-origin-when-cross-origin`, Vimeo recibe el **origen del LMS** → la privacidad
   por dominio funciona **si** el dominio del LMS está en la lista blanca de Vimeo. Los vídeos públicos no
   dependen de ello. Documentarlo (no es un cambio de código).

### Condiciones de seguridad (invariantes a preservar)

Recogidas de [[DEC-80-03]] (§2026-06-14b) y de la verificación adversaria de este diseño:

- El iframe de **contenido** sigue **sin `allow-same-origin`** (origen opaco; invariante de RIE-001).
- El **player promovido** es cross-origin y va `sandbox="allow-scripts allow-same-origin allow-popups
  allow-forms allow-presentation"` **sin** `allow-top-navigation` ni `allow-modals`. El `allow-same-origin`
  aquí es seguro porque el `src` es del **proveedor** (no del LMS) → el SOP lo aísla del host; **no** es
  el `allow-same-origin` prohibido del iframe de contenido.
- **Guarda D1** (elimina el player si aterriza same-origin al LMS) y **D2** (los players se excluyen de la
  autenticación por identidad de ventana, no pueden forjar `sync`) se mantienen.
- **Default `open` vs `strict`** (`mod_exelearning/embedmode`): se mantiene `open` por defecto, con el
  razonamiento ya registrado en [[DEC-80-03]] §2026-06-14b (el player es cross-origin + sandboxeado → el
  SOP lo aísla con independencia del host; la lista blanca sólo mitiga phishing/tracking, que el
  contenido sandboxeado ya puede hacer por otras vías). El **clickjacking del overlay** es un **riesgo
  residual aceptado** (overlay controlado por el padre, clampado a la caja de contenido, player
  cross-origin). **Mejora de guía:** documentar para administración cuándo conviene `strict` (cursos con
  autores no confiables) y dejar el endurecimiento del trust model (punto 2) como cierre de la objeción
  adversaria sobre la asimetría de validación.

## Actualización (2026-06-28b): corrección tras revisión adversaria del diseño + decisiones de erseco

La revisión adversaria del diseño de implementación (workflow, veredicto `sound:false`) corrige dos
puntos del apartado **Decisión** y erseco confirma el rumbo:

**1. El nonce NO es un autenticador real aquí — se retira como "endurecimiento".** El iframe es **origen
opaco con código de autor NO confiable**, y el relay le **entrega el nonce** en el handshake; por tanto
ese código no confiable lo conoce y puede incluirlo en cualquier mensaje. El autenticador real ya es
**`event.source === iframe.contentWindow`** (infalsificable por SOP) en los mensajes de ventana, y la
**posesión del `MessagePort`** transferido en el canal de control. El bridge actual de mod ([[DEC-80-03]]:
event.source + validación de URL + D1/D2 + player cross-origin sandboxeado) **ya era sólido**; el punto 2
sobrevaloraba el nonce. **Decisión (erseco): sin nonce-como-auth.** El endurecimiento genuino que queda:
- (a) **canal id-only** `{provider, objectId}` para proveedores reconocidos → el padre reconstruye la URL
  canónica desde plantilla fija y **revalida** la invariante estructural (https, cross-origin, no-LMS…);
  reduce la superficie de *URL-laundering*. Se **mantiene D1** para el camino genérico OPEN por URL.
- (b) **`MessagePort` como capability** = el canal del control de medios de la opción 2 (un sub-frame
  anidado no tiene el puerto). No autentica frente al código de autor del propio documento (que sí lo
  tiene), pero es el canal limpio para el control bidireccional.

**2. `hide`/`show` se ELIMINA del canal de control (era clickjacking).** Si el iDevice pudiera ocultar el
overlay, dejaría ver la UI del LMS detrás. **No hace falta:** la pregunta cronometrada la revela el
**propio CSS del iDevice en el hijo** (`.active #player{float:left}` encoge/mueve el player y muestra
`#slide` al lado); el overlay **solo sigue la geometría del `#player`** (ya reportada por mutación/resize),
así que la pregunta aparece en el hueco liberado sin que el padre oculte nada. El canal de control queda
en **`play`/`pause`/`seek`/`getCurrentTime`/`getDuration`** + eventos **`timeupdate`/`ready`/`ended`/`error`**
(con `requestId` para respuestas async). Sin `hide`/`show`.

**3. Control del player promovido por raw `postMessage` (`enablejsapi=1`), NO por SDK externo.** Para
pausar en el cue y leer `currentTime` de un iframe cross-origin de YouTube/Vimeo, el relay del padre crea
el player con `enablejsapi=1` (+ `origin`) y lo controla **posteando `{event:'command',func:…}`** al
iframe y **parseando** sus eventos (`onReady`, `infoDelivery` con `currentTime`/`duration`,
`onStateChange`). **Decisión (erseco): NO se carga la YouTube IFrame API ni el Vimeo Player SDK en la
página de Moodle** (evita ejecutar script externo en la página de confianza del LMS y ampliar la CSP
`script-src`). El relay traduce los comandos del iDevice (que llegan por el `MessagePort`) a estos
`postMessage` al player, y reenvía los eventos del player al iDevice por el puerto.

Resultado: la **opción 1** se reduce a su parte con valor real (**id-only** + mantener event.source/D1/D2;
**sin** refactor a nonce), y la **opción 2** es el grueso (canal de control por `MessagePort` + control del
player por raw postMessage + cambio en el iDevice). La revisión adversaria del **código** (no del diseño)
se hará al cerrar la implementación.

## Consecuencias

- **Implementación en curso (2026-06-28b)** con tests unitarios (TDD/Vitest), tras la corrección del
  diseño: en mod, **id-only** + el **canal de control por `MessagePort`** (sin nonce, sin hide/show) +
  control del player por raw `postMessage`; cambios en el iDevice de vídeo interactivo de eXeLearning;
  paridad en `wp-exelearning`/`omeka-s-exelearning` como seguimiento.
- **Recupera el vídeo interactivo remoto en secure** sin renunciar al overlay inline, cerrando la
  limitación de [[DEC-80-03]] y el `[PENDIENTE]` #1 de [[AN-015]].
- **Reduce la triplicación** mod/wp/omeka al compartir el módulo de política + el relay reutilizable;
  `tools/check-embed-sync.mjs` sigue como anti-drift mientras no haya infra compartida.
- **No reabre** los vectores cerrados por el modo opaco: el endurecimiento sólo añade defensa
  (nonce/capability); las invariantes de sandbox no cambian.

## Seguimiento (implementación; fuera de alcance de esta entrega)

- **mod_exelearning:** evolucionar `js/exe_embed_relay.js` + `js/exe_embed_shim.js` al handshake
  endurecido: **canal id-only** + **canal de control por `MessagePort`** (sin nonce-como-auth, sin
  hide/show); control del player por raw `postMessage` (`enablejsapi=1`); Vitest del nuevo contrato;
  `tools/check-embed-sync.mjs` a 0 drift; e2e Playwright en sandbox opaco real con
  `research/fixtures/elpx/remote-embeds.elpx` (control shim on/off + interactivo que pausa y dispara la
  pregunta en el segundo configurado).
- **eXeLearning (upstream):** modificar el iDevice `interactive-video` (y `quick-questions-video`,
  YouTube-only) para detectar origen opaco y conducir el control por el bridge; degradación grácil.
- **wp-exelearning:** hoy tiene el **espejo viejo** (`exe-embed-shim.js` + `exe-embed-relay.js` +
  `exelearning-media-modal.js`); "hacer lo mismo" = adoptar el contrato endurecido + el arreglo de vídeo
  interactivo cuando aterricen en mod (mantener paridad). Añadir la nota operativa Jetpack/Referrer-Policy
  del Problema B en su documentación.
- **Lenguaje/ajustes:** si la implementación añade strings o ajustes, respetar el namespace `exelearning`
  (no `mod_`), prefijo `~` para traducción automática pendiente ([[DEC-11-01]]), orden alfabético y paridad
  de idiomas.
