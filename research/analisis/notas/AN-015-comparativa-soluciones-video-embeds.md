---
id: AN-015
titulo: "Comparativa: soluciones de embeds de vídeo (YouTube/Vimeo) en contenido no confiable — mod_exelearning vs procomún vs eXeLearning vs el paper"
fecha: 2026-06-19
fuentes:
  - REPO-005
  - REPO-010
  - REPO-011
relacionados:
  - DEC-0061
  - DEC-0059
  - DEC-0060
  - AN-008
herramienta_ia:
  interfaz: claude-code
  modelo: claude-opus-4-8
---

## Resumen

Cuatro implementaciones/análisis del MISMO problema —reproducir vídeo de terceros (YouTube/Vimeo)
incrustado en un paquete `.elpx` **no confiable** que se sirve en un **iframe de origen opaco**— se
comparan aquí. La conclusión clave: **las cuatro coinciden en el núcleo** y se diferencian sólo en
**la capa** en la que actúan, el **canal de confianza** y **quién impone el sandbox del player**.

Núcleo compartido por todas: el `.elpx` corre en origen opaco (`sandbox` SIN `allow-same-origin`,
`origin="null"`); el flag se **propaga** a los iframes anidados, así que un YouTube/Vimeo anidado de
forma ingenua **sale en blanco**; la solución es **promote-to-parent**: el player real se monta en el
**padre confiable** (origen real del proveedor, cross-origin al host → aislado por el SOP), nunca
reintroduciendo `allow-same-origin` para el host; y como `event.origin` del frame opaco es la cadena
inútil `"null"`, el mensaje se autentica por **identidad de ventana** (`event.source === iframe.contentWindow`).
El paper lo nombra literalmente «el modelo de confianza que Moodle ya usa para incrustar YouTube».

**Veredicto (depende del rol):** no hay un ganador único.
- Para la **pureza del canal de seguridad**, **eXeLearning** gana: sólo cruza `{provider, videoId}`
  (nunca la URL del autor), el padre reconstruye la URL canónica desde una plantilla fija, y el
  handshake va con **identidad de ventana + nonce por vista + un MessagePort transferido** que un
  sub-frame hostil anidado no puede obtener. Un id malicioso ni siquiera puede plantillarse en una URL
  viva. **Pero** su asimetría: el sandbox/CSP del player vive **entero en el host** y el productor **no
  puede imponerlo** → como garantía para un host arbitrario es **condicional**.
- Para un **host desplegado que debe defenderse de paquetes arbitrarios** (nuestro caso),
  **mod_exelearning** es el más fuerte en conjunto: **posee e impone** el sandbox del player, trata
  como hostiles **tanto la URL como la geometría**, añade guardas D1/D2, y es el **mejor validado** de
  los cuatro (Vitest + e2e Playwright real en sandbox opaco + anti-drift). Además la mayor **fidelidad
  visual** (overlay inline, indistinguible de un embed normal).
- **procomún** es el **más simple** (click→modal, sin sincronía de geometría) pero el de **menos
  defensa en profundidad** (sin canonicalización de Vimeo, UI muerta en thumbnails, PDF sin sandbox).
- **El paper** aporta el **principio** (SoK) que las tres implementaciones materializan.

## El problema común

DEC-0059/DEC-0060 sirven el paquete en origen opaco para cerrar RIE-001 (que el paquete alcance la
sesión/sesskey del padre). Efecto colateral (el «dilema central» del paper, `:249`): los flags
`sandbox` se propagan a los iframes anidados → el player de YouTube/Vimeo hereda el origen opaco,
pierde el suyo (cookies/storage) y queda en blanco. Las tres implementaciones responden con
**promote-to-parent** + aislamiento por SOP del player en el origen real del proveedor.

## Hechos citados

### mod_exelearning (DEC-0061) — consumidor, overlay inline

- **Shim en el contenido** `js/exe_embed_shim.js` (horneado como `libs/exe_embed_shim.js` por
  `package_manager.php:256-259`, inyectado al inicio del `<head>` por `scorm_injector.php:108-123`),
  se auto-activa **sólo** en origen opaco; sustituye cada `iframe[src]` cross-origin-https-o-`.pdf` por
  un placeholder y postMessea `{id, url ABSOLUTA, x, y, w, h}` al padre con `targetOrigin '*'`.
- **Relay en el padre** `js/exe_embed_relay.js` (inline en `view.php:516-531`, sólo en `$securemode`):
  autentica por identidad de ventana (`frameForSource` exige un iframe de **contenido**, nunca un
  player promovido — `relay.js:297-308`), **valida** cada URL (`validate()`/`isCrossOriginHttps`,
  `relay.js:162-233`: https, sin userinfo, cross-origin, no IP/loopback/`.local`, no dominio relacionado
  con el LMS) y **superpone** el player real sobre la geometría del placeholder.
- **Sandbox del player** (`relay.js:251`): `allow-scripts allow-same-origin allow-popups allow-forms
  allow-presentation` — **omite `allow-top-navigation` y `allow-modals`**. El `allow-same-origin` aquí
  es **seguro** porque el `src` del player es cross-origin (proveedor) → el SOP lo aísla del host; NO es
  el `allow-same-origin` prohibido del iframe de contenido.
- **Guardas:** **D1** redirect-laundering (`armSameOriginGuard`, `relay.js:339-348`: elimina el player
  si aterriza same-origin al LMS), **D2** forged-message (players excluidos de `frameForSource`),
  clamp de geometría anti-clickjacking (`Math.min(embed.w, rect.width)`, overflow:hidden).
- **YouTube:** OPEN promociona verbatim; STRICT reconstruye `youtube-nocookie.com/embed/{id}` +
  `referrerpolicy=strict-origin-when-cross-origin` (evita el Error 153). **Vimeo:** STRICT reconstruye
  `player.vimeo.com/video/{id}`. Modos `mod_exelearning/embedmode` OPEN (invariante https+cross-origin,
  sin allowlist) vs STRICT (allowlist `DEFAULT_EMBED_HOSTS` + rebuild canónico), `player_iframe.php:133-139`.
- **Limitación:** interactive-video (control del player por la API del autor) **roto** en opaco; un
  puente de control YT se prototipó y **se revirtió por frágil**. Local `<video>` funciona.
- **Validación:** Vitest + **e2e Playwright en Firefox en sandbox opaco real** (`tests/e2e/embed.spec.cjs`)
  + `tools/check-embed-sync.mjs` (anti-drift entre mod/wp/omeka).

### procomún (ADR-0026/0027) — consumidor, click→modal

- Mismo modelo, **simplificado a click→modal**: shim `apps/api/static/elpx/embed-shim.js` (fachada +
  postMessage), relay `use-elpx-embed-relay.ts:34-54` (identidad de ventana), `classifyEmbed`
  (`elpx-embed-policy.ts:94-125`: rechaza userinfo, https-only, invariante video cross-origin), modal
  `EmbedModal.tsx`.
- Opacidad reforzada por **sandbox attr Y directiva CSP `sandbox`** en la respuesta
  (`elpx-content.ts:224-225`) → aguanta en pestaña nueva.
- Sandbox del player `EmbedModal.tsx:44`: `allow-scripts allow-same-origin allow-popups allow-forms
  allow-presentation` (sin top-nav). **YouTube** → `youtube-nocookie` (`elpx-embed-policy.ts:64-78`).
  **Vimeo: SIN rama de canonicalización** → sólo open-mode genérico, sin reescritura de privacidad.
- **Costes/defectos:** open-mode sin allowlist; player con `allow-same-origin` seguro **sólo** por la
  invariante cross-origin; **UI muerta** (fachada en thumbnails que no monta modal); PDF **sin sandbox**;
  requiere `ACAO: *` + `crossorigin=anonymous` en los `<script>` del paquete. Sin sincronía de geometría
  (más simple, menos superficie). Interactive-video roto (igual que mod).

### eXeLearning (rama `fix/opaque-iframe-external-media`) — productor, canal mínimo

- **Lado productor:** el exportador **hornea un runtime en cada export** (`exe_media_policy.js` +
  `exe_media_bridge.js`, inyectados por `PageRenderer.ts:305-308`), que se auto-ejecuta y detecta
  contexto opaco (`exe_media_bridge.js:66-96`).
- **Canal de confianza más limpio:** el hijo transmite **sólo `{provider, videoId}`** (nunca una URL)
  por un **MessageChannel con capability**; el padre **reconstruye** `youtube-nocookie/player.vimeo`
  canónico desde plantilla fija (`canonicalEmbedUrl`, `exe_media_policy.js:74-82`) y revalida el id
  (`^[A-Za-z0-9_-]{11}$` / `^[0-9]{6,12}$`). Handshake gated por **identidad de ventana + nonce por
  vista + MessagePort transferido** (`exe-media-host.js:298-335`) → un sub-frame hostil anidado **no
  tiene el port** y no puede inyectar comandos.
- **Player en el padre** vía **YouTube IFrame API / Vimeo Player.js SDK**. Click→modal `<dialog>`.
  Degradación grácil (aviso + abrir-en-pestaña al watchdog ~8s), nunca un frame en blanco.
- **El único que preserva interactive-video** (timing de preguntas) bajo origen opaco: hide/show del
  modal en vez de espejado de geometría (`interactive-video.js:664-707`). (Vimeo sólo en
  interactive-video, no en quick-questions-video, que es YouTube-only.)
- **Asimetría estructural (cons):** el sandbox/CSP del player vive **entero en el host** y el productor
  **NO puede imponerlo** (`external-media-bridge.md:122-138`); un host descuidado podría cargar el
  player sin CSP/sin negar top-nav. **Requiere que el host coopere** (vendorizar `exe-media-host.js`,
  cargar los SDK, fijar `frame-src`). Sin cooperación → sólo abrir-en-pestaña, no reproducción inline.

### El paper (SoK) — principio

- Marco: el peligro es JS de autor en el **mismo origen** que la sesión LMS. Mitigación = origen opaco
  + CSP `sandbox` en la respuesta + bridge `postMessage` validado por **identidad de ventana** (no por
  `event.origin`, contrastando con el origen-trust **roto de H5P**), `:297`.
- Vídeo (`:295,299,303`): promote-to-parent + SOP; invariante **sin allowlist** `https+cross-origin`
  cubre YouTube/Vimeo/Dailymotion; modo estricto y **oEmbed server-side** como alternativas
  conservadoras. Variante fachada+modal citada como más simple, mismo modelo.
- Defensa en profundidad: CSP de respuesta `connect-src 'self'` (cierra exfil), `object-src 'none'`,
  `frame-ancestors 'self'`; residuo acotado = pixel GET que exfiltra el token de fichero de **sólo
  lectura** (no el `sesskey`) vía `img/media/frame-src https:`; perfil CSP estricto opcional lo cierra.

## Comparativa por dimensiones

| Dimensión | mod_exelearning | procomún | eXeLearning | El paper |
|---|---|---|---|---|
| **Rol / capa** | Consumidor (player) | Consumidor (player) | Productor (export) | Principio (SoK) |
| **UI de reproducción** | Overlay **inline** (geometría sincronizada) | Click→**modal** | Click→**modal `<dialog>`** | Ambas; recomienda modal por simple |
| **Qué cruza el límite** | URL absoluta + geometría (ambas no confiadas) | URL absoluta + geometría | **Sólo `{provider, videoId}`** | URL (no confiada) |
| **Auth del mensaje** | Identidad de ventana + exclusión de players (D2) | Identidad de ventana | Identidad de ventana + **nonce + MessagePort** | Identidad de ventana |
| **Sandbox del player** | **Lo impone el consumidor** (sin top-nav/modals) | Lo impone el consumidor | **Lo impone el host** (productor no puede) | Lo impone el host |
| **YouTube** | OPEN verbatim / STRICT → nocookie | → nocookie | → nocookie (sólo id) | nocookie/allowlist o invariante |
| **Vimeo** | STRICT → `player.vimeo/video/{id}` | **Sin canonicalización** | → `player.vimeo/video/{id}` (sólo id) | igual que YouTube |
| **interactive-video** | **Roto** (puente revertido) | Roto | **Funciona** (único) | Sacrificado por aislamiento |
| **Guardas extra** | **D1 redirect-guard, D2, clamp** | — | nonce + port + enum cerrado | CSP respuesta |
| **Complejidad** | Alta (mejor validada) | **Baja** | Media (si controlas el productor) | n/a |
| **Validación** | Vitest + e2e Firefox opaco + anti-drift | menor | runtime propio | adversarial 7/7 (Chromium) |

## [INTERPRETACION] — ¿Qué es mejor?

«Mejor» **depende del rol**, y comparar de frente confunde un **diseño de canal** (eXeLearning) con un
**sandbox impuesto** (mod/procomún):

- Como **host de Moodle que recibe paquetes de autores arbitrarios**, lo correcto es que **el player
  imponga su propio sandbox** sin depender de que el paquete coopere. Ahí **mod_exelearning es la
  referencia**: es el mejor diseño para defender contenido arbitrario, impone el sandbox que el productor
  no puede, y es el mejor validado. procomún es el mismo modelo más simple pero con menos defensa.
- El **canal** de eXeLearning es **superior** (sólo cruza un id, reconstrucción desde plantilla, nonce +
  MessagePort): elimina toda la clase de **URL-laundering** que mod cubre con D1. Conviene **adoptar esa
  disciplina** para proveedores reconocidos, manteniendo D1 como cinturón para el camino genérico OPEN.
- La **fidelidad** se reparte: eXeLearning es el único que conserva **interactive-video**; mod tiene la
  mejor fidelidad **visual** (inline). No son excluyentes: mod aporta el **sandbox impuesto**,
  eXeLearning aporta la **fidelidad de cue-points** — son **complementarios**.

## Recomendación para mod_exelearning

1. **Mantener** el relay promote-to-parent (`js/exe_embed_relay.js`) como **frontera de seguridad
   canónica** para YouTube/Vimeo: es el diseño correcto para un host y el mejor validado.
2. **Estrechar el canal** adoptando la disciplina de eXeLearning «cruza sólo `{provider, videoId}` y
   reconstruye en el padre desde plantilla fija» para los proveedores reconocidos (reduce la superficie
   a validar la forma del id y elimina la clase URL-laundering). **Mantener D1** para el camino genérico
   OPEN.
3. **interactive-video:** NO re-bridgear el control de YouTube dentro de mod (se revirtió por frágil).
   En su lugar, cuando el paquete venga de eXeLearning actual, dejar que **el propio bridge de
   eXeLearning** (`exe_media_bridge.js` + un `exe-media-host.js` vendorizado) maneje los cue-points
   —el único enfoque que preserva el timing bajo origen opaco— **pero** con el relay de mod imponiendo
   el sandbox/CSP del player que eXeLearning no puede imponer. Complementarios.
4. **Vimeo:** añadir/confirmar la rama de canonicalización (procomún carece de ella) para paridad de
   privacidad (`player.vimeo.com/video/{id}`).
5. **Despliegues de alta seguridad:** ofrecer el **perfil CSP estricto opcional** del paper (§6.3) que
   cierra el residuo de exfil por pixel GET del token de sólo lectura (off por defecto para no romper
   imágenes externas/MathJax/CDN).

## Actualización (2026-06-28): marco de los dos problemas + decisión ([[DEC-0070]] + [[DEC-0071]])

**Dos problemas ortogonales (no confundir).** Esta nota cubre bien el **Problema A** (propagación del
sandbox → player en blanco → promote-to-parent). Conviene añadir explícitamente el **Problema B —
Error 153 / referrer**, **independiente** del sandbox: ocurre incluso en embebido normal cuando el host
envía `Referrer-Policy: no-referrer`/`same-origin` y el player no lleva `referrerpolicy`. Lo arregla el
**atributo `referrerpolicy`** (precedencia element-level sobre la cabecera). Caso real (red Medusa,
Humberto ATE): la infra fijaba `no-referrer` **y Jetpack eliminaba el atributo**. mod ya pone
`strict-origin-when-cross-origin` en el player (`DEC-0061:69-70`), así que resuelve A **y** B; el vídeo
simple **ya se ve** en opaco. `referrerpolicy` **no** sustituye al promote-to-parent (no arregla A).

**Decisión tomada (cierra el `[PENDIENTE]` #1 de abajo).** El usuario eligió **mantener el overlay
inline** de mod (no migrar a modal) y **endurecer** el trust model (nonce + `MessageChannel`, cruzar
sólo `{provider, videoId}`), y **arreglar el vídeo interactivo modificando el iDevice** de eXeLearning
(autorizado). Registrado en **[[DEC-0071]]**. Matiz respecto a la recomendación #3 de esta nota: en vez
de delegar los cue-points al **modal** del bridge de eXe, el iDevice (modificado) conduce el control por
el bridge endurecido mientras el **overlay mantiene el player en la geometría del `#player`** → la layout
queda en el hijo, sin la reconstrucción en el padre que hizo frágil el prototipo revertido. El
teacher-mode pasó a `?exe-teacher` ([[DEC-0070]]), lo que desbloqueó reanudar este trabajo en opaco.

## [PENDIENTE]

- ~~ADR de seguimiento si se decide la integración complementaria con el bridge productor de eXeLearning
  para interactive-video~~ → **decidido en [[DEC-0071]]** (overlay inline endurecido + arreglo del vídeo
  interactivo en el iDevice; el sandbox lo sigue imponiendo mod).
- Toggle CSP estricto (cierre del residuo de confidencialidad; eco de `anexos-tecnicos.md:181`).
- Validación cross-engine del **camino de promoción de embeds** específicamente (el aislamiento opaco se
  confirmó en 3 motores; el e2e de promoción es Firefox/Chromium).
- procomún: cerrar la **UI muerta** de thumbnails y el **PDF sin sandbox** si se toma como referencia.
