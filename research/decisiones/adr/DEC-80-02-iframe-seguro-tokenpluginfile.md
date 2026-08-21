---
id: DEC-80-02
title: "Modo iframe seguro funcional: servir el contenido opaco por tokenpluginfile + CSP + watchdog (corrige la Ruta A de DEC-80-01)"
status: Accepted
date: 2026-06-13
tracking_issue: 80
legacy_id: DEC-0060
deciders:
  - erseco
  - claude-code
sources:
  - REPO-002
  - REPO-004
related:
  adrs: [DEC-80-01, DEC-0-16, DEC-5-01]
see_also:
  - RIE-001
  - AN-008
ai_assistance:
  tool: claude-code
  model: claude-opus-4-8
---

# DEC-80-02: Modo iframe seguro funcional: servir el contenido opaco por tokenpluginfile + CSP + watchdog (corrige la Ruta A de DEC-80-01)

## Contexto

DEC-80-01 implementó el modo `secure` (iframe de origen opaco + bridge SCORM por
postMessage) sirviendo el contenido por `pluginfile.php` normal (Ruta A de DEC-0-16).
La verificación en navegador (Chrome DevTools, Moodle real en `:80`) **refutó esa
Ruta A**: el `index.html` cargaba pero **todos los subrecursos (CSS/JS, incluido el
shim) daban 404**, dejando el contenido sin estilos y el SCORM sin `window.API`.

## Problema (causa raíz verificada)

La cookie de sesión de Moodle es **`SameSite=Lax`** por defecto
(`lib/classes/session/manager.php:513-518`). Un documento de **origen opaco** (iframe
`sandbox` sin `allow-same-origin`) tiene "site for cookies" nulo, así que **no envía
la cookie de sesión en sus peticiones de subrecursos**. Como `exelearning_pluginfile()`
exige `require_login()`, esos subrecursos se rechazan (302/403) → 404 efectivo. El
`index.html` sí carga porque su navegación la inicia el padre (lleva cookie Lax). →
**Aislar contenido same-origin autenticado del padre con solo el atributo `sandbox` es
imposible:** con `allow-same-origin` el contenido alcanza el padre (RIE-001); sin él,
no carga sus assets. (Por eso el core nunca usa origen opaco para contenido
autenticado; `wp-franer` puede porque inyecta el contenido inline con `srcdoc`, inviable
para un paquete eXeLearning multi-fichero.)

## Investigación de alternativas (con evidencia)

| Vía | ¿Aísla del padre? | ¿Carga assets/SCORM? | ¿En-plugin? | Veredicto |
|---|---|---|---|---|
| Legacy (same-origin) | No | Sí | Sí | RIE-001 abierto (fuga de sesskey/DOM) |
| **A · Token + opaco + bridge** | **Sí** | **Sí** | **Sí** | **Elegida** |
| B · Origen separado (subdominio) | Sí (real) | Sí | No (infra) | Fuera de alcance |
| C · Sandbox JS en cliente (M8) | — | No (rompe el contenido) | Sí | Inviable |

- **Token (Opción A):** `make_pluginfile_url(..., $includetoken=true)` genera
  `/tokenpluginfile.php/<token>/…` con `get_user_key('core_files', $userid)`. El token
  va en la **ruta**, así que los subrecursos relativos del iframe opaco lo arrastran y
  cargan **sin cookie de sesión**. `tokenpluginfile.php` valida el token
  (`require_user_key_login('core_files')`) y **sigue ejecutando** las comprobaciones de
  capacidad del callback (`exelearning_pluginfile` → `require_capability('mod/
  exelearning:view')`). El token es de **solo lectura de ficheros**: NO da poder de
  `sesskey` (ni acciones ni forms). **Precedente en core:** el propio H5P usa
  `includetoken` cuando va embebido (`h5p/classes/player.php:429`). Requiere
  `slasharguments` (token en ruta); on por defecto.
- **Origen separado (B):** no hay mecanismo en core; la URL está fijada a
  `$CFG->wwwroot` (`lib/classes/url.php:747,754`). Exigiría reverse-proxy + un eTLD+1
  distinto para que la cookie no llegue al contenido. Aislamiento máximo, pero infra
  fuera del plugin. Documentado como futuro.
- **M8 (sandbox JS):** inviable. El contenido exportado necesita DOM real + jQuery +
  Bootstrap + bucle de eventos; ShadowRealm/Worker no tienen DOM y un shim WASM (+100KB)
  lo haría inmanejable. El único acceso cross-frame es la API SCORM (ya intermediada).

## Decisión

Implementar la **Opción A endurecida** como modo `secure` (default), corrigiendo el
mecanismo de servido de la Ruta A de DEC-80-01 (el resto de DEC-80-01 —ajuste
`iframemode`, bridge postMessage, sandbox sin same-origin/popups-to-escape— sigue
vigente):

1. **Servir el iframe por `tokenpluginfile`** (token `core_files` de **TTL corto**,
   redondeado a la hora para reutilizarse; `view.php`). Los assets cargan sin cookie;
   el iframe sigue opaco (el contenido no puede tocar DOM/cookies/sesskey del padre).
2. **CSP + Permissions-Policy** desde `exelearning_pluginfile()` (solo modo secure,
   solo el documento HTML; `send_stored_file` no las borra). Endurece sin romper
   eXeLearning (que necesita inline + eval): `object-src 'none'`, `base-uri 'none'`,
   `frame-ancestors 'self'`, y `connect-src` acotado a este sitio (corta exfiltración
   del token por fetch/XHR). `\mod_exelearning\local\ui\player_iframe`.
3. **NO degradar en silencio a legacy.** Si secure no puede renderizar (slasharguments
   off, o un host con service worker que no sirve iframes opacos —p. ej. el Playground
   PHP-WASM), el shim nunca emite `ready` y el **watchdog del relay** muestra el aviso
   "configuración de seguridad impide mostrar este contenido; contacte al admin"
   (`securemodeblocked`), en vez de caer a same-origin. El admin elige legacy de forma
   explícita si lo desea. El watchdog usa **dos señales** para no dejar el aviso detrás
   de una espera larga en blanco: en cuanto el elemento iframe dispara `load` (que ocurre
   **también** cuando la navegación acaba en una página de error —el 404 del host con SW—)
   concede solo una **gracia corta** (~2,5 s) para el handshake; si `load` nunca llega,
   cae a un tope mayor (8 s). Así el aviso aparece justo tras el fallo de carga, no tras
   una ventana de varios segundos en la que el contenido "parecía" cargar.
4. **Self-heal del bridge:** los paquetes extraídos antes de DEC-80-02 no tienen el shim
   en `libs/`; `view.php` los re-extrae una vez (idempotente) para que secure funcione
   sin re-subir.

## Consecuencias

- Positivas: el modo seguro **funciona de verdad** en Moodle real (verificado en
  navegador) y aísla el contenido del padre. RIE-001 mitigado por defecto. El contenido
  recibe un token de **solo-lectura de ficheros** en vez del `sesskey` → estrictamente
  más seguro que legacy. No hay degradación silenciosa de seguridad.
- Negativas / coste: el token (aunque de solo lectura y TTL corto) queda visible en la
  URL del contenido; el CSP `connect-src` corta su exfiltración por fetch, pero un
  `<img>/<script>` a `https:` externo podría sacarlo (mitigado por el TTL; un perfil CSP
  estricto que lo cierre del todo rompería MathJax/YouTube → toggle futuro). El
  Playground (y cualquier host con SW) **no puede** servir secure y muestra el aviso.
- Dispara: corrige la Ruta A de DEC-80-01; RIE-001 → mitigado. Futuro: Ruta B
  (subdominio/infra) para aislamiento máximo; toggle CSP estricto.

## Riesgos

- **RIE-001 — mitigado** (modo seguro por defecto, funcional). Residuo: token de
  ficheros expuesto al contenido (acotado por CSP + TTL); legacy opt-in reabre el
  riesgo same-origin.
- **Compatibilidad:** `slasharguments` debe estar on (default); si no, el watchdog
  muestra el aviso. Hosts con service worker (Playground) no pueden secure.

## Validación

- **Chrome DevTools en Moodle real (`:80`):** iframe opaco + servido por
  `tokenpluginfile` (origen `null`, `SecurityError` al leer `contentWindow` desde el
  padre); CSS/JS cargan; el shim emite `ready`; el relay valida e `init` no lanza;
  **`track.php` responde `{ok:true, rawscore:100, peritem:{1:100}}`** (guarda la nota);
  el watchdog NO salta cuando secure funciona y el contenido renderiza con estilos.
- **Chrome DevTools en el Playground PHP-WASM (confirmación empírica 2026-06-13):** el
  iframe se construye en secure (sandbox `allow-scripts allow-popups allow-forms`, origen
  opaco — `SecurityError` al leer `contentWindow`), pero la petición
  `tokenpluginfile.php/<token>/<ctx>/mod_exelearning/content/1/index.html` devuelve **404**
  (el service worker no controla subframes opacos, así que la URL cae a GitHub Pages). El
  shim nunca emite `ready` → el watchdog **sí** muestra el aviso `securemodeblocked` y
  oculta el iframe. El token **no** ayuda aquí: el bloqueo es el SW, no la cookie. Esto
  confirma la "Limitación conocida": el Playground no puede servir secure y avisa (no
  cae a legacy). La sensación de "funciona sin aviso" era el retardo del watchdog (ahora
  ligado al `load`) más que el aviso quedaba bajo el pliegue.
- **phpcs `--standard=moodle` 0/0**; **Vitest 52/52** (shim, relay, watchdog —incluida la
  ruta rápida por `load`—, storage); PHPUnit `player_iframe_test` (modo, tokens sandbox,
  CSP, Permissions-Policy) en contenedor/CI. PHPUnit/Behat completos en CI.

## Seguimiento

- Toggle de admin para un CSP estricto (cerrar exfiltración del token por img/script a
  costa de contenido externo).
- Ruta B (subdominio dedicado eTLD+1 + reverse-proxy) como aislamiento máximo, fuera del
  plugin (DEC-0-16 M7 Route B).
- Confirmar en CI la matriz PHPUnit/Behat y, si procede, un escenario Behat de grading
  en modo secure (entrando al frame del paquete).
