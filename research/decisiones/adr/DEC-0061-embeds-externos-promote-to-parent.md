---
id: DEC-0061
titulo: "Embeds externos en modo seguro: promover YouTube/Vimeo/PDF al padre (overlay inline) — standalone, sin subdominio"
estado: Aceptada
fecha: 2026-06-14
agentes:
  - erseco
  - claude-code
fuentes:
  - RIE-001
relacionados:
  - DEC-0059
  - DEC-0060
herramienta_ia:
  interfaz: claude-code
  modelo: claude-opus-4-8
---

## Contexto

El modo seguro ([[DEC-0059]] + [[DEC-0060]]) sirve el `.elpx` en un iframe de **origen opaco**
(`sandbox` sin `allow-same-origin`, servido por `tokenpluginfile`), de modo que el HTML/JS no
confiable del autor no puede leer la sesión/DOM de Moodle. El precio es que **el contenido externo
embebido deja de funcionar**:

- **YouTube/Vimeo salen EN BLANCO.** La flag de sandbox se **propaga al iframe anidado** del player,
  que pierde su origen `youtube.com`/`vimeo.com` y no arranca. Verificado empíricamente y por la
  especificación HTML; no hay primitiva de navegador (credentialless, fenced frames, COEP/COOP,
  `referrerpolicy`…) que lo arregle desde dentro del sandbox.
- **Los PDF tampoco renderizan.** Chrome bloquea su visor (PDFium) en un iframe sandboxed sin
  `allow-same-origin`. Afecta a PDF remotos **y** a los empaquetados en el `.elpx`.

Cómo lo resuelven otros: Moodle (filter/media) y la mayoría de plugins **embeben YouTube directo, sin
sandbox**, confiando en el autor; H5P no ejecuta JS de autor. La única vía "clásica" para aislar +
embeber sería servir el contenido desde un **origen separado (subdominio)** → iframe cross-origin sin
sandbox opaco. **erseco descartó el subdominio** (requisito: el plugin debe funcionar *standalone*) y
descartó degradar a `legacy` para los vídeos ("hay que encontrar la forma de que se vean").

## Decisión

**Promote-to-parent con overlay inline.** El contenido sigue aislado en el sandbox opaco; un **shim**
inyectado en el paquete detecta los iframes de hosts en **lista blanca** (vídeo) o con ruta `.pdf`,
los sustituye por un **placeholder** del mismo tamaño y **reporta su geometría** al padre por
`postMessage`. Un **relay** en la página de Moodle (contexto de confianza) **valida + reconstruye** la
URL y **superpone el reproductor real** —que él renderiza en su propio contexto, con origen real, así
que funciona— **en el sitio exacto** del placeholder, sincronizado con el scroll. El player es
cross-origin (o un PDF del paquete servido como `application/pdf`) y no puede tocar el host; la lista
blanca + la validación estricta impiden que el contenido haga al padre cargar una URL arbitraria.

| Pieza | Cambio |
|---|---|
| `js/exe_embed_shim.js` (nuevo) | Corre DENTRO del iframe. Se **auto-activa solo en origen opaco** (`isOpaqueOrigin()`: `document.cookie` lanza / `window.origin==='null'`), así que el mismo fichero horneado queda **dormido en modo legacy**. Reemplaza iframes whitelisted/`.pdf` por un placeholder (`data-exe-embed-id` + url **absoluta**, resuelta contra la ubicación del contenido, mismo w/h) y reporta `{id,url,x,y,w,h}` en load/scroll/resize/mutation (rAF). Dual-export `window.exeEmbedShim` + `module.exports` (Vitest). |
| `js/exe_embed_relay.js` (nuevo) | Corre en el padre. Valida cada URL reportada: `new URL()`, rechaza userinfo (`@`), host exacto contra la lista, exige patrón de id (`/embed/{id}` YT, `/video/{id}` Vimeo) y **reconstruye** la URL canónica (nunca pasa la del contenido tal cual). Crea/posiciona un `<iframe>` player overlay clampado al iframe de contenido. Autentica por `event.source === iframe.contentWindow` (el origen opaco no da `event.origin` útil). |
| `classes/local/package_manager.php` | Copia `js/exe_embed_shim.js` a `libs/exe_embed_shim.js` del paquete (plugin-owned, refrescado en cada extracción). |
| `classes/local/scorm/scorm_injector.php` | Hornea `<script>window.__exeEmbedWhitelist=…</script>` + `<script src="libs/exe_embed_shim.js">` al inicio del `<head>` de **todo** HTML del paquete (marker `embed-shim`, idempotente). Independiente del bridge SCORM (reusa la misma iteración de extracción, [[DEC-0054]]). |
| `classes/local/ui/player_iframe.php` | `embed_whitelist()` + `DEFAULT_EMBED_HOSTS` (YouTube/Vimeo). La CSP ya permitía `frame-src 'self' $siteorigin https:`. |
| `view.php` | Inyecta el relay **inline** en el bloque `if ($securemode)` (independiente de SCORM, para todo paquete seguro), igual que el relay del bridge, para que su listener esté instalado antes de que cargue el iframe. |

Decisiones de diseño confirmadas por erseco:

1. **UX = overlay inline, NO modal/lightbox** ("aunque tengamos el riesgo debería ser inline"). El
   player se ve en su sitio y se reproduce ahí; con scroll el overlay sigue al hueco.
2. **PDFs = los locales del paquete siempre + cualquier `https` `.pdf`** (regla por extensión, sin
   lista blanca de host para PDF). **Invariante de seguridad:** un `.pdf` *same-origin* solo se
   renderiza si pertenece a este paquete (su path está bajo el directorio del contenido **o** contiene
   el hash del paquete como segmento). Como `tokenpluginfile` sirve los ficheros del paquete por
   extensión (`application/pdf`) con `X-Content-Type-Options: nosniff`, un `.pdf` del paquete nunca es
   HTML ejecutable; y una URL same-origin ajena (p.ej. `/admin/x.pdf`) se rechaza.
3. **`referrerpolicy`** del player: `strict-origin-when-cross-origin` para vídeo (evita YouTube Error
   153), `no-referrer` para PDF. El iframe de CONTENIDO mantiene su `no-referrer`.
4. **Solo en secure.** En legacy el contenido es same-origin, el shim queda dormido y los players ya
   van inline.

Lista blanca por defecto: `www.youtube.com`, `youtube.com`, `www.youtube-nocookie.com`,
`youtube-nocookie.com`, `player.vimeo.com`, `vimeo.com`.

## Consecuencias

- El modo seguro deja de **degradar la UX** del contenido que abre recursos externos: YouTube, Vimeo y
  PDF (remotos y empaquetados) se ven inline sin sacrificar el aislamiento ni exigir un subdominio.
- Las imágenes y el vídeo HTML5 externos ya cargaban por CSP (`img-src/media-src … https:`); esto solo
  añade los casos que el sandbox rompía (iframes de player + PDF).
- La feature pertenece a la **familia eXeLearning**: el mismo JS (shim + relay, misma lógica) está en
  wp-exelearning (PR #56) y omeka-s-exelearning (PR #21). Diferencia de cableado: **mod hornea** el
  shim en la extracción y **inlina** el relay en `view.php`; wp/omeka lo inyectan a la hora de servir.
- El bridge SCORM ([[DEC-0059]]) no se ve afectado: usa otro tipo de mensaje (`scorm` vs `exe-embed`)
  y su relay sigue independiente.

## Riesgos

- **Clickjacking del overlay (aceptado por erseco).** El player se superpone al área del contenido.
  Mitigaciones: el overlay lo **controla el padre** (el contenido solo reporta geometría + URL, no
  inyecta nada), se **clampa a los límites del iframe de contenido** (no puede cubrir la toolbar/UI de
  Moodle) y el player es **cross-origin** (no puede leer ni clicar el host). Geometría absurda
  (no finita) se descarta.
- **Render de URL arbitraria por el padre** → contenida por la lista blanca de host + reconstrucción
  de URL + rechazo de userinfo/patrón (salvaguarda de RIE-001, familia del modo seguro). Para PDF, la
  apertura es a un visor (no ejecuta como la página) y el caso same-origin queda atado a ficheros del
  propio paquete.
- **`targetOrigin: '*'`** en el `postMessage` del shim: aceptable porque no viaja ningún secreto y el
  padre autentica por `event.source` (el origen opaco no ofrece `event.origin` usable).

## Validación

- **Vitest** `tests/js/exe_embed.test.js` (14 tests): el validador acepta `youtube.com/embed/{id}`,
  `youtube-nocookie`, `player.vimeo.com/video/{id}` y **reconstruye** la URL canónica; **rechaza**
  `youtube.com.evil.com`, `evil.com@youtube.com`, esquema no-https, id inválido; PDF cross-origin
  `https` se acepta, PDF same-origin solo si es del paquete (dir/hash), `/admin/x.pdf` se rechaza; el
  shim produce placeholder para video/PDF y deja intacto un iframe no permitido.
- **Regresión SCORM (que no se rompa la puntuación)** — el modo seguro ahora carga **a la vez** el
  bridge SCORM y el shim/relay de embeds; deben convivir:
  - Vitest `tests/js/embed_scorm_coexistence.test.js` (4 tests): los 5 globals (tracker + shim/relay
    SCORM + shim/relay embed) quedan definidos y distintos (sin pisarse); el relay SCORM **sigue
    aceptando** un mensaje `track` (`acceptTrack` con nonce correcto) con los módulos de embed
    cargados, y rechaza el nonce erróneo; ambos canales se ignoran mutuamente (`scorm` vs `exe-embed`).
  - PHPUnit en el contenedor vivo (`make test` / `phpunit-docker.sh`): **119 tests OK** sobre
    `track_test` (endpoint que **guarda la puntuación**), `scorm_injector_test` (mi cambio: hornea
    bridge + embed, idempotente), `grades_test`/`lib_grades_test`/`gradeitems_test`/`grademodel_test`.
- **PHPUnit** `tests/local/scorm/scorm_injector_test.php`: asserts de que el marker `embed-shim`, el
  global `__exeEmbedWhitelist` y `libs/exe_embed_shim.js` se hornean en `index.html` y en las páginas
  anidadas (`../libs/…`), idempotente, **sin perder** el bridge SCORM ni el wrapper pipwerks.
- **`phpcs --standard=moodle`** 0/0 sobre `player_iframe.php`, `package_manager.php`,
  `scorm_injector.php`, `view.php` y los tests.
- **Verificación en vivo** (contenedor `mod_exelearning_3-moodle-1`, `iframemode=secure`):
  - **Embeds**: una actividad desde `research/fixtures/elpx/external-embeds-demo.elpx` renderiza
    **4 players inline** (YouTube, Vimeo, PDF remoto y **PDF local del paquete**) sobre el iframe de
    contenido opaco (servido por `tokenpluginfile`); el iframe `example.com` NO se promueve.
  - **SCORM scoring (lo más importante)**: respondido un iDevice calificable, el endpoint real
    `track.php` devuelve `{"ok":true,"attempt":1,"rawscore":100,"status":"completed","peritem":{"1":100,"2":100}}`;
    quedan 3 filas en `exelearning_attempt` (overall + 2 ítems), el gradebook marca `finalgrade=100` en
    ambos ítems, el informe de intentos lista el intento y la actividad pasa a "Hecho: recibir
    calificación". **El shim de embeds NO rompe el scoring** (es independiente del bridge).
- **Bug encontrado y corregido en la prueba en navegador**: mod sirve los assets del paquete con URLs
  **relativas** (a diferencia del proxy de wp/omeka que reescribe a absolutas), así que el shim debe
  **reportar la URL ABSOLUTA** (resuelta contra la ubicación del contenido) — el relay del padre no
  puede resolver una relativa (la resolvería contra la página host). Fix en el shim de los 3 plugins +
  test de regresión Vitest (`exe_embed.test.js`: una src relativa se reporta absoluta).
- **Fixtures**: `research/fixtures/elpx/{youtube-embed,vimeo-embed,external-embeds-demo}.elpx` (el
  último con PDF remoto + PDF local empaquetado + imagen/vídeo remotos + un iframe no-whitelist).

## Actualización (2026-06-14): re-validación con export real, proveedores, tokens y fix de navegación

### Re-validación de la NECESIDAD con un export real
Las fixtures sintéticas iniciales (`index.html` a mano) no eran representativas. Con un **export real
de eXeLearning** (`research/fixtures/elpx/remote-embeds.elpx`: `index.html` + `html/{vimeo,
mediatecamadrid,remote-pdf}-embed.html`) se repitió el experimento de control en mod (secure):
- shim **activado** → el vídeo de YouTube renderiza y reproduce;
- shim **desactivado** (control) → caja en **blanco**.

Causa confirmada en 4 ejes: (1) **no es CSP** — `frame-src 'self' … https:` permite YouTube
(`player_iframe.php:171`), sin error CSP en consola; (2) es la **propagación del origen opaco** al
iframe anidado del player; (3) por especificación un contexto de navegación anidado no puede tener
*menos* restricciones que su padre; (4) lo único que lo "arregla" es `allow-same-origin`, que rompe el
aislamiento. **No hay alternativa más simple que preserve la seguridad: el overlay promote-to-parent es
la solución mínima.** (La duda razonable de erseco quedó así verificada, no asumida.)

### Proveedores añadidos
**Dailymotion** (`www/geo.dailymotion.com`, embed `/embed/video/{id}`) y **EducaMadrid / Mediateca de
Madrid** (`mediateca.educa.madrid.org`, embed `/video/{id}/fs`), cada uno con validador por proveedor
que **reconstruye** la URL canónica (nunca pasa la del contenido tal cual). Verificado en vivo
(EducaMadrid renderiza inline en mod y omeka).

### Alineación de tokens del sandbox (los 3) + fix de CSP oculto
Token seguro canónico = `allow-scripts allow-popups allow-forms` (los iDevices de eXe usan
formularios). wp/omeka **no tenían `allow-forms`** ni en el atributo del iframe ni — más grave — en la
**directiva CSP `sandbox`** del contenido servido (`class-content-proxy.php`, `ContentController.php`,
hardcodeadas). Sin `allow-forms` en la CSP, el envío de formularios se bloquea aunque el iframe lo
permita (el sandbox efectivo es la *intersección* de ambos). Corregido en los dos + tests. Legacy
alineado a `…allow-forms allow-popups-to-escape-sandbox`.

### Bug de navegación entre páginas (reportado por erseco) + fix
Al paginar contenido eXe multi-página, el embed de la página anterior **quedaba pegado** en la nueva
(p.ej. YouTube persistía en la página de Vimeo, reescalado a su caja). Causa: el shim **reinicia su
contador de id por página**, así que el primer embed de cada página reusa `exe-embed-1`; el relay
reutilizaba el player existente y solo lo reposicionaba, **sin actualizar su `src`**. Reproducido en
vivo (la altura cambiaba a la de Vimeo pero el `src` seguía en YouTube). Fix: cada player se etiqueta
con `data-exe-embed-src`; en `sync()` se **reemplaza** cuando un id reusado apunta a otra URL. Test de
regresión Vitest + verificado en vivo en los 3 (mod: youtube→vimeo→mediateca→pdf cambian limpiamente;
wp y omeka, idem; el atributo del fix presente en el relevo servido).

### Anti-drift
La lógica de shim/relay es idéntica en los 3 (solo cambia el envoltorio de export). mod es la **fuente
canónica** (`js/exe_embed_{shim,relay}.js`); wp/omeka llevan cabecera "MIRROR". `tools/check-embed-sync.mjs`
normaliza y compara la lógica + whitelist + tokens de las 3 copias y sale ≠0 si divergen (herramienta
de mantenimiento local, no gate CI cross-repo: no hay infra compartida).

### Limitación conocida: iDevice de vídeo interactivo con fuente remota
El iDevice **vídeo interactivo** con fuentes remotas (YouTube/EducaMadrid) usa la **YouTube
IFrame Player API** (`new YT.Player` + `getCurrentTime`/`pauseVideo`/`seekTo`, 8 llamadas de
control en `idevices/interactive-video/interactive-video.js`) para pausar el vídeo y mostrar
preguntas cronometradas. En modo seguro esto **no funciona**: (1) en origen opaco el player
de YouTube no renderiza dentro del iframe; (2) promoverlo al padre (promote-to-parent) lo
**desacopla del control** del iDevice, que vive en el hijo opaco → sin pausa/seek/tiempo, las
preguntas no disparan.

Se prototipó un **bridge de control YT** en el embedder (un `window.YT` falso en el hijo que
proxyea a un player real en el padre). Verificado en vivo: el vídeo **reproduce** y la
pregunta **aparece y se responde** en el segundo configurado. Pero **reconstruir el layout
del iDevice desde el padre — portada/`Inicio`, `float:left` de `#player` al activar
(`interactive-video.css` `.active #player{float:left}`), la posición de cada slide — es
frágil** (el vídeo aterriza mal y el solapamiento vídeo/pregunta no cuadra). **Decisión
(erseco): NO mantener el bridge en el embedder** (revertido, no commiteado).

- **Estado en secure:** vídeo interactivo con fuente **remota** → usar **modo legacy**. Con
  fuente **local** (archivo del paquete, HTML5 `<video>`) funciona en secure (corre en el
  marco, sin player cross-origin).
- **Arreglo correcto (pendiente upstream):** está en el **iDevice de eXeLearning**, que conoce
  su propio layout: detectar origen opaco (`window.origin==='null'`) y o bien **degradar** (embed
  simple → lo promociona el shim existente, sin interactividad) o **conducir él mismo** un relay
  del padre. Más simple y robusto que parchear en los 3 embedders.

## Actualización (2026-06-14b): invariante estructural — embeds sin lista blanca (open por defecto)

### Reversión razonada del "sin whitelist no es seguro"
La actualización anterior concluyó "sin lista blanca no es seguro" por analogía con Moodle media / WP
oEmbed. **Esa conclusión era incorrecta para ESTE modelo de amenaza y se corrige.** Moodle/WP embeben
en páginas de CONFIANZA same-origin; aquí el contenido es NO confiable + opaco, y el player promovido es
**cross-origin y sandboxeado**, así que la SOP lo aísla del LMS **independientemente del host**. El host
es irrelevante para el ESCAPE; la lista blanca solo mitigaba **phishing/tracking** (marginal: el
contenido sandboxed ya puede mostrar HTML falso, hacer POST de formulario cross-origin y cargar img de
tracking). Lo propone el artículo de seguridad (§6.3) y lo confirma una revisión adversaria (agente
Plan, read-only): **"SÍ, con condiciones"**.

### Decisión: invariante estructural + modo `open` por defecto
- Ajuste `mod_exelearning/embedmode`: **`open` (def.)** promueve cualquier iframe `src` **https +
  cross-origin al LMS**; **`strict`** mantiene la lista blanca + reconstrucción por proveedor (para
  despliegues donde ni el autor es de fiar). Fail-safe a `strict` ante valor inválido; default real
  `open`. Esto **obvia** la idea de "whitelist configurable por admin".
- `validate()` (relay) exige: parsear URL **absoluta** (NO resolver relativas contra el LMS — heredarían
  su origen); `https:`; sin userinfo (`url.username/password`); cross-origin (`url.origin !==
  window.location.origin`); no subdominio/superdominio del host del LMS (límite por punto en ambos
  sentidos); no IP/IPv6/loopback/`localhost`/`.local`; host no vacío. PDF: cualquier https cross-origin
  o fichero same-origin del paquete.
- El shim promueve cualquier https cross-origin (+ `.pdf`) como candidato; el **relay** es la puerta
  autoritativa (ya no hay lista de hosts en el shim).

### Condiciones de seguridad (todas implementadas + verificadas)
- **Player de vídeo SANDBOXEADO**: `sandbox="allow-scripts allow-same-origin allow-popups allow-forms
  allow-presentation"` (SIN `allow-top-navigation` ni `allow-modals`). `allow-same-origin` va en el
  iframe **cross-origin** (el proveedor conserva SU origen, no el del LMS) → renderiza y la SOP lo
  aísla; es lo que permite sandboxearlo y **bloquear el top-navigation** (un sitio arbitrario no puede
  redirigir la pestaña). **NO** es el `allow-same-origin` prohibido del iframe de CONTENIDO (cuyo src ES
  el origen del LMS). Verificado en vivo: YouTube, Vimeo y EducaMadrid/JWPlayer renderizan bajo él.
- **D1 (redirect-laundering)**: guard `load` que elimina el player si aterriza **same-origin** al LMS
  (un `src` cross-origin que haga 30x al origen del LMS sería, con `allow-same-origin`, scriptable).
- **D2 (impersonación)**: los players se marcan `data-exe-embed-player` y `frameForSource`/`pingAll` los
  excluyen → un player sandboxeado no puede forjar un mensaje `sync` y hacerse pasar por contenido.
- **PDF SIN sandbox**: el visor PDF del navegador **no funciona dentro de un iframe sandbox** (icono de
  error; verificado), así que se deja **sin sandbox** (y por tanto sin `allow-same-origin`) como antes
  de DEC-0061 (los PDF ya eran "cualquier https .pdf"). El guard D1 sigue aplicando a PDFs cross-origin.
  Residual documentado: un servidor que sirva HTML en una ruta `.pdf` podría ejecutar scripts ahí
  (pre-existente, bajo). **pdf.js** (que eXe empaqueta) NO es viable en el contenido opaco (su `fetch`
  desde origen `null` está bloqueado por CORS, incl. `tokenpluginfile`); queda como mejora futura para
  PDFs locales servida desde el padre.
- **Sin backstop CSP en el padre**: una directiva `frame-src` en la página completa de Moodle podría
  romper iframes propios de Moodle, así que no se añade; el guard D1 + el sandbox son los controles.

### Verificación
- Vitest `tests/js/exe_embed.test.js` reescrito (open + strict + helpers IP/subdominio + sandbox del
  player + D2 forged-message). Suite JS **92/92**. `tools/check-embed-sync.mjs` con invariantes
  estructurales, **0 drift**.
- En vivo (mod localhost:80, modo `open`): YouTube/Vimeo/EducaMadrid/PDF renderizan, el player lleva el
  sandbox correcto (`data-exe-embed-player`), y navegar entre páginas cambia el player (fix de nav).

## Actualización (2026-06-28): dos problemas ortogonales + evolución a [[DEC-0071]]

Al reanudar el trabajo de medios externos conviene **separar con claridad dos problemas** que se
confunden a menudo (una síntesis automática los mezcló como si `referrerpolicy` arreglara el opaco):

- **Problema A — propagación del sandbox (el de este ADR).** El `sandbox` sin `allow-same-origin` se
  propaga al iframe anidado del player → pierde su origen `youtube.com`/`vimeo.com` y queda **en blanco**.
  Lo arregla **únicamente** promover el player fuera del opaco (el overlay de este ADR). **`referrerpolicy`
  NO lo arregla** (W3C Referrer Policy: origen opaco ⇒ sin referrer). Es lo que esta entrada verificó.
- **Problema B — Error 153 / referrer.** Independiente del sandbox: ocurre incluso sin sandbox cuando el
  host envía `Referrer-Policy: no-referrer` y el `<iframe>` no lleva `referrerpolicy`. Lo arregla el
  **atributo `referrerpolicy`** del player (precedencia element-level sobre la cabecera de la página). Ya
  presente aquí (punto 3 de la Decisión, `strict-origin-when-cross-origin`). Caso real (red Medusa,
  Humberto ATE): la infra fijaba `no-referrer` **y Jetpack eliminaba el atributo** del embed → desactivar
  esa función de Jetpack lo resolvía. Lección host: no dejar que un sanitizador elimine `referrerpolicy`.

Es decir: este ADR ya resuelve **A** (overlay) y **B** (referrerpolicy), por lo que el **vídeo simple ya
se ve en opaco**. Lo que queda abierto se decide en **[[DEC-0071]]**: (i) **endurecer el trust model** del
relay (nonce por vista + `MessageChannel` con capability + cruzar sólo `{provider, videoId}`), alineándolo
al bridge de eXeLearning sin abandonar el overlay inline; (ii) **arreglar el vídeo interactivo remoto**
—la **limitación** documentada arriba (líneas 187-210)— **modificando el iDevice** de eXeLearning para que,
en origen opaco, conduzca su control (play/pause/seek/time + hide/show) por el bridge endurecido. Clave:
como el overlay mantiene el player en la geometría del `#player`, **la layout del iDevice permanece en el
hijo** y no se reconstruye en el padre (que es lo que hizo frágil el prototipo revertido).

Notas: **Vimeo por dominio** valida el host del Referer; con el player en el padre (origen del LMS) +
`referrerpolicy=strict-origin-when-cross-origin`, la privacidad por dominio funciona **si** el dominio del
LMS está en la lista blanca de Vimeo. El **teacher-mode** ya no se oculta por inyección sino por el
parámetro core `?exe-teacher` ([[DEC-0070]]), compatible con el origen opaco.

## Seguimiento

- UI de admin para editar la lista blanca (hoy constante; configurable por filtro/ajuste como
  seguimiento, igual que en wp/omeka).
- Paridad mantenida con wp-exelearning (PR #56) y omeka-s-exelearning (PR #21): el JS shim/relay es la
  misma lógica en los tres.
- Independiente de TAREA-015 (xAPI) y TAREA-016 (origen por URL): el origen separado por URL sigue
  siendo una alternativa válida para quien lo quiera, pero ya no es **necesaria** para ver vídeos/PDF.
