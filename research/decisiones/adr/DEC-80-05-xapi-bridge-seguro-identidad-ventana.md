---
id: DEC-80-05
title: "xAPI sobre el bridge seguro: el listener confía por identidad de ventana en el iframe opaco (extiende DEC-85-01 al modo seguro de DEC-80-01)"
status: Accepted
date: 2026-06-19
tracking_issue: 80
legacy_id: DEC-0069
deciders:
  - erseco
  - claude-code
sources:
  - REPO-005
  - FTE-011
related:
  adrs: [DEC-85-01, DEC-80-01, DEC-80-02, DEC-80-04, DEC-0-18, DEC-0-16, DEC-0-07]
ai_assistance:
  tool: claude-code
  model: claude-opus-4-8
---

# DEC-80-05: xAPI sobre el bridge seguro: el listener confía por identidad de ventana en el iframe opaco (extiende DEC-85-01 al modo seguro de DEC-80-01)

## Contexto

`DEC-85-01` implementó la ingesta xAPI (**xAPI-primary**) asumiendo que el iframe del paquete se
sirve **same-origin** por `pluginfile.php`: el listener `js/xapi_listener.js` confía un statement
sólo si `event.origin === origen del host` (RIE-013, su cabecera lo dice literalmente).

`DEC-80-01`/`DEC-80-02` introdujeron el **modo seguro** (por defecto): el paquete corre en un iframe
de **origen opaco** (sandbox sin `allow-same-origin`, servido por `tokenpluginfile`). En un origen
opaco, el `event.origin` de los postMessage del paquete es la **cadena `"null"`**, que nunca
coincide con el origen del host.

Al fusionar `main` (DEC-85-01) en la rama del modo seguro —**ambos por defecto**— la configuración
por defecto pasa a ser **secure + xAPI-primary**. Con el listener tal cual, en modo seguro
**descarta todos los statements** y la nota xAPI **se pierde en silencio**. En el merge inicial se
gateó xAPI a legacy como medida conservadora; este ADR registra la **solución definitiva**.
**Extiende** (no supersede) `DEC-85-01` al modo seguro de `DEC-80-01`.

## Problema

¿Cómo recibir y calificar los statements xAPI cuando el paquete corre en un iframe de **origen
opaco**, sin reintroducir el acoplamiento same-origin que el modo seguro elimina (`RIE-001`) y sin
**doble conteo** con el canal SCORM del bridge?

## Opciones consideradas

1. **Gatear xAPI a legacy (status quo del merge).** En secure calificar siempre por el bridge SCORM.
   - ✔ Simple; no rompe nada.  ✘ xAPI-primary (recomendado, por defecto) **no surte efecto en la
     configuración por defecto**; el canal moderno queda inactivo justo donde más se usa.
2. **Relajar el listener a aceptar `event.origin === "null"`.**
   - ✘ **Inseguro**: cualquier iframe opaco de la página (p.ej. un embed promovido sandboxeado,
     `DEC-80-03`) tiene origen `"null"`; `"null"` **no identifica** al emisor. Descartada.
3. **Validar por identidad de ventana (elegida).** El listener confía un statement cuando
   `event.source === contentWindow del iframe del paquete`, **exactamente como** el relay del bridge
   SCORM (`DEC-80-01`). El navegador fija `event.source` a la ventana emisora y el script de la página
   **no puede falsificarlo**; el origen opaco **no la anonimiza** (sólo afecta a `event.origin` y al
   acceso DOM same-origin). En modo legacy se conserva la validación por origen.

## Evidencia

- **Contrato del emisor** (`REPO-005` @ `e3b1bd13`, `FTE-011`): el emisor hace
  `root.parent.postMessage({type:'exe-xapi-statement', statement}, parentOrigin||'*')` **síncrono**
  desde la ventana top-level del iframe — **sin Worker, sin MessageChannel, sin frame hijo dinámico**.
  En nuestro empotrado (iframe **hijo directo** de `view.php`) el padre recibe el mensaje con
  `event.source === iframe.contentWindow`. El `parentOrigin` lo fija `config_injector` al origen del
  host; el `targetOrigin` constriñe al **receptor**, no a `event.origin`.
- **Origen opaco** (`DEC-80-02`): el sandbox sin `allow-same-origin` serializa el origen a la cadena
  `"null"`, no al host; sólo afecta a `event.origin` y al DOM same-origin, **no** a `event.source`
  (comportamiento del navegador, del que el relay SCORM ya depende —`DEC-80-04`).
- **Precedente en el repo**: `js/scorm_bridge_relay.js` ancla en `e.source !== fr.contentWindow`
  («an opaque origin has no useful event.origin»).
- **Confianza cero del servidor** (`DEC-0-18`/`DEC-85-01`): `xapi_track.php` exige `sesskey` +
  capability y atribuye la nota a `$USER` ignorando el actor; el paquete opaco **no tiene sesskey** y
  no puede llamar al endpoint directamente. Identidad de ventana = **sin nonce**, pero el modelo de
  confianza es **idéntico a legacy** (que confía en todo script same-origin) porque el contenido se
  **autocalifica** y el servidor **acota**.

## Decisión

1. **Listener con doble puerta de confianza.** `js/xapi_listener.js` admite dos modos: **origen**
   (legacy, `allowedOrigin`) e **identidad de ventana** (secure, `iframeid` →
   `document.getElementById(iframeid).contentWindow`, resuelto **perezosamente por mensaje** porque
   el listener se inyecta **antes** de existir el elemento iframe). En identidad de ventana se
   **ignora** `event.origin` (el `"null"` opaco) y el ancla es `event.source`.
2. **xAPI-primary en ambos modos.** `view.php`:
   `$emitsxapi = exelearning_xapi_primary_enabled() && exelearning_package_emits_xapi(...)` (se quita
   el gateo `!$securemode`). En secure inyecta el listener con `iframeid`; en legacy con
   `allowedOrigin`. Comparte `$sessiontoken` como `registration` (`DEC-0-07`).
3. **SCORM inerte por el padre, no por el shim.** Para evitar doble conteo, en secure el **relay**
   (`js/scorm_bridge_relay.js`, parámetro `disableTracking`) **valida pero no reenvía** el POST SCORM
   cuando hay xAPI-primary. La decisión vive en el **padre** (código fresco inyectado en cada carga),
   **no** en el shim **horneado** en el paquete: así es robusta aunque el shim sea de una versión
   previa al flag. El shim **no puede alcanzar `track.php`** por sí mismo (origen opaco, sin sesskey),
   así que suprimir el reenvío en el relay es **autoritativo**. En legacy, el `disableTracking` del
   propio `scorm_tracker.js` (`DEC-85-01`) cumple el mismo rol.
4. **Sin degradación de seguridad real.** El statement es **autorreportado** en ambos modos; el
   servidor valida y acota (`DEC-0-18`). Identidad de ventana iguala a legacy en confianza, pero
   conserva el **aislamiento de sesión** del modo seguro: el paquete sigue sin ver el `sesskey`, y el
   padre (que lo tiene) reenvía **sólo** statements del propio iframe.

### Componentes entregados

`js/xapi_listener.js` (modo identidad de ventana: `iframeid`/`expectedSource`, resolución perezosa,
función `isTrusted`; conserva el modo origen), `js/scorm_bridge_relay.js` (parámetro `disableTracking`
que suprime el POST tras validar), `view.php` (`$emitsxapi` sin gateo; relay con
`disableTracking => $emitsxapi`; listener con `iframeid` en secure o `allowedOrigin` en legacy, vía el
helper `$emitinlinemodule`). Tests Vitest: del listener por identidad de ventana (acepta sólo el
`contentWindow` del iframe aunque el origen sea `"null"`; rechaza cualquier otra ventana aunque alegue
el origen del host; resolución perezosa) y del relay inerte (un `track` válido no hace POST, pero
`ready` sigue haciendo handshake y el beacon de `pagehide` no envía nada).

## Consecuencias

- **Positivas:** xAPI-primary funciona en la **config por defecto** (secure); un **único canal** por
  paquete en ambos modos (cero doble conteo); reusa el patrón de **identidad de ventana** ya
  verificado del bridge SCORM; **no toca el shim horneado** (robusto ante paquetes ya extraídos).
- **Negativas / coste:** identidad de ventana **no lleva nonce** (el emisor es código upstream que no
  lo conoce), pero es equivalente en confianza a legacy; el `event.origin` `"null"` se **ignora**
  deliberadamente en secure.
- **Dispara:** revierte el gateo a legacy del merge inicial; actualiza `docs/tracking-architecture.md`
  y `docs/xapi-integration-plan.md`.

## Riesgos

- **RIE (bajo).** Un script malicioso **dentro** del paquete podría postear un `exe-xapi-statement`
  falso (no hay nonce). **Mitigado**: es exactamente lo que ya puede hacer en legacy (mismo origen) y
  en SCORM (el `set()` de pipwerks); el servidor **acota** (lista blanca de verbos, `objectid`
  registrado, `scaled∈[0,1]`, idempotencia por `statement.id`, nota a `$USER`). El modo seguro sigue
  impidiendo lo único que importa: **alcanzar la sesión/sesskey del padre** (`RIE-001`).
- **RIE (muy bajo).** Un embed promovido (`DEC-80-03`) que intentara suplantar: su `event.source` es
  la ventana del **player**, no la del iframe del paquete → **rechazado** por identidad de ventana.
- **Limitación heredada de `DEC-85-01` (no introducida aquí): el kill switch surte efecto en la
  PRÓXIMA carga, no en sesiones en curso.** `$emitsxapi` (y por tanto `disableTracking`) se hornea en
  el HTML al cargar la página, mientras `xapi_track.php` consulta `exelearning_xapi_primary_enabled()`
  en cada request. Si un admin **apaga** xapiprimaryenabled con una página ya abierta, esa página
  suprime SCORM (estado horneado) pero el servidor ignora el xAPI (estado nuevo) → esa vista **no
  califica hasta recargar** (al recargar, `$emitsxapi=false` → SCORM califica normal). Es **simétrico
  a `DEC-85-01`** (mismo patrón en legacy) y este ADR sólo lo extiende a secure de forma **consistente**;
  no se corrige aquí porque las soluciones (consultar el ajuste por AJAX, o que `xapi_track.php` honre
  un token de página) o bien debilitan el kill switch o bien requieren estado de servidor por página
  —una decisión de producto separada sobre la semántica del kill switch de `DEC-85-01`—. Mitigación
  operativa: cambiar el ajuste fuera de horario de actividad. Documentado en `docs/xapi-qa-checklist.md`.

## Validación

`phpcs --standard=moodle` 0/0 (view.php y plugin completo); Vitest **114/114** (nuevos: identidad de
ventana del listener + relay `disableTracking`); `php -l view.php` OK. **Pendiente (fase viva):** e2e
en Moodle real capturando un `postMessage` real del emisor desde el iframe opaco → columna del
gradebook (Behat/Playwright entrando al frame; el modo seguro **no** se prueba en el Playground
PHP-WASM, que no sirve iframes opacos —`DEC-80-02`).

## Seguimiento

- Mantiene **fuera de alcance** (eco de `DEC-85-01`): handler `core_xapi` + eventos, cmi5, LRS externo,
  y el `'*'` como destino de confianza.
- Si un futuro emisor upstream adoptara un canal con **nonce/handshake**, el modo identidad de ventana
  podría endurecerse a nonce, como el bridge SCORM.
