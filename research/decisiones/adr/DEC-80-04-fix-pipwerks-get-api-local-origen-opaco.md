---
id: DEC-80-04
title: "Fix: el bridge SCORM seguro nunca guardaba nota porque el get() de pipwerks no miraba la ventana local"
status: Accepted
date: 2026-06-14
tracking_issue: 80
legacy_id: DEC-0062
deciders:
  - erseco
  - claude-code
sources:
  - REPO-002
experiments: []
related:
  adrs: [DEC-80-01, DEC-80-02, DEC-5-01, DEC-13-11]
see_also:
  - RIE-001
ai_assistance:
  tool: claude-code
  model: claude-opus-4-8
---

# DEC-80-04: Fix: el bridge SCORM seguro nunca guardaba nota porque el get() de pipwerks no miraba la ventana local

## Contexto

`DEC-80-01` implementó el bridge SCORM por `postMessage` con el iframe en **origen
opaco** (modo `secure`, por defecto). Su pieza clave ("el linchpin") era inyectar un
`window.API` (shim) DENTRO del iframe para que el pipwerks empaquetado lo descubriese
**sin tocar el padre** cross-origin.

Al verificar en vivo (`localhost:80`, modo `secure`) que la puntuación SCORM se
guardaba, erseco reportó que **NO se guardaba**: contestar una pregunta y pulsar
"Guardar la puntuación" no creaba ningún intento en `exelearning_attempt` ni llegaba
ninguna petición a `track.php`. En modo `legacy` sí se guardaba. La "verificación"
previa de DEC-80-01 había usado un POST directo a `track.php` que **saltaba el bridge**,
enmascarando el fallo.

## Problema

¿Por qué en modo `secure` el SCO nunca persiste la nota, si el shim sí inyecta
`window.API` en el iframe y el handshake del bridge (`ready`→`config`) funciona?

## Hechos verificados (con cita)

Diagnóstico en vivo instrumentando el shim del iframe opaco (no observable desde fuera)
y emitiendo el estado de pipwerks por `postMessage` al padre. Resultados decisivos sobre
una actividad evaluable real en modo `secure`:

1. **El shim funciona:** `window.API === shim` (`apiIsShim:true`), el handshake llega
   (sin "secure blocked"), `pipwerks` cargó (`typeof === 'object'`).
2. **Pero la conexión nunca se activa:** `pipwerks.SCORM.connection.isActive === false`,
   `pipwerks.SCORM.version === null`, `pipwerks.SCORM.API.isFound === false`,
   `pipwerks.SCORM.API.handle == null`, y `LMSInitialize` **nunca se llamó** (contador
   0). Por tanto `LMSSetValue`/`LMSCommit` del guardado son no-ops silenciosos → 0 filas.
3. **Causa raíz — `get()` lanza `SecurityError` y nunca mira la ventana local.**
   Forzando `pipwerks.SCORM.API.get()` desde el shim:
   `"SecurityError: Failed to read a named property 'API' from 'Window': Blocked a frame
   with origin \"null\" from accessing a cross-origin frame."`
   El `get()` vendado (`assets/scorm/SCORM_API_wrapper.js:130-150`, **modificado**
   respecto al pipwerks estándar) arrancaba directamente en el **padre** y **omitía la
   ventana actual**:
   ```js
   if (win.parent && win.parent != win) { API = find(win.parent); }  // ← sin find(win)
   if (!API && win.top.opener) { API = find(win.top.opener); }
   ```
   - En `legacy` el padre es same-origin (Moodle, con `window.API`) → funciona.
   - En `secure` el padre es opaco/cross-origin → `find(win.parent)` accede a
     `parent.API` y lanza `SecurityError` → `init()` revienta (lo traga su `catch`) →
     `isActive` se queda en `false` → nada se guarda.
4. **El "linchpin" de DEC-80-01 verificó la función equivocada.** DEC-80-01 §"Hechos
   verificados" (1) cita `find(win)` (`SCORM_API_wrapper.js:71-106`), que sí parte de la
   ventana actual. Pero el punto de entrada real es `getHandle()`→`get()`, y **este
   `get()` no llamaba a `find(window)`**. La premisa "pipwerks descubre el `window.API`
   local antes de subir" era cierta para `find()` pero **falsa para `get()`**, que es
   quien decide. El origen opaco del modo `secure` nunca fue alcanzable.
5. **No hay doble pipwerks.** El export de eXeLearning sólo **usa** `pipwerks.SCORM.get/
   set` (`libs/common.js:945,1175`); el único `pipwerks` es el vendado por mod
   (`var pipwerks = {}`), inyectado por `scorm_injector`. Descartado como causa.

## Causa raíz

El `get()` vendado de pipwerks fue alterado en algún momento para mirar **sólo el
padre** y saltarse la ventana actual. Eso es exactamente incompatible con la
arquitectura de DEC-80-01, que provee el API **localmente** en un iframe cuyo padre es
**inalcanzable** por opaco.

## Decisión

Restaurar en `assets/scorm/SCORM_API_wrapper.js` el orden estándar de pipwerks en
`get()`: **mirar primero la ventana actual** (`find(win)`) y sólo después subir al padre
/ opener, **envolviendo cada salto cross-origin en `try/catch`** para que un ancestro
opaco no pueda abortar la búsqueda nunca más.

```js
try { API = find(win); } catch (e) { trace(...); }                       // ventana local primero
if (!API && win.parent && win.parent != win) {
  try { API = find(win.parent); } catch (e) { trace(...); }              // fallback same-origin (legacy)
}
try { if (!API && win.top && win.top.opener) { API = find(win.top.opener); } } catch (e) { trace(...); }
```

- **`secure`:** `find(win)` encuentra el `window.API` del shim de inmediato (el bucle de
  `find` no sube porque `win.API` existe) → `init()` activa la conexión → el guardado
  fluye shim → `postMessage('track')` → relevo → `track.php` → BD.
- **`legacy`:** sin API local, `find(win)` **sube** al padre same-origin y devuelve su
  `window.API`, idéntico al comportamiento previo (equivalencia probada en test).

Cambio mínimo y acotado a `get()`; no se toca `find()`, ni el shim, ni el relevo, ni el
inyector, ni BD/upgrade.

## Consecuencias

- **Positiva:** el modo `secure` por defecto (DEC-80-01/DEC-80-02) **por fin guarda la
  nota SCORM** de extremo a extremo; el "linchpin" que DEC-80-01 daba por hecho ahora se
  cumple de verdad. El aislamiento de RIE-001 se mantiene intacto (el `sesskey` sigue en
  el padre; el contenido sigue sin alcanzarlo).
- **Endurecimiento:** `get()` ya no puede lanzar por un ancestro opaco; degrada a `null`
  con traza, como manda el contrato de pipwerks.
- **Sin regresión en `legacy`:** la búsqueda sigue resolviendo al `window.API` del padre
  same-origin (sube por `find`), verificado por test unitario.

## Validación

- **Vivo (`localhost:80`, modo `secure`):** tras contestar + "Guardar la puntuación", se
  escriben las filas reales en `exelearning_attempt` (`rawscore=100.00`,
  `status=completed`/`passed`) y el bridge emite `track` (`hasCmi:true`). Antes del fix:
  0 filas, 0 `track`.
- **Vitest** `tests/js/scorm_api_wrapper.test.js` (nuevo, 6 casos, verde): `get()`
  devuelve el API **local** y no el del padre (regresión del bug); `get()` no lanza con
  padre opaco y encuentra el local; `get()` devuelve `null` sin lanzar cuando no hay API
  y el padre es opaco; `get()` sigue subiendo a un padre same-origin sin API local
  (legacy); `init()` activa la conexión vía API local con padre opaco; `set()` llega al
  API local una vez activa (la nota se guarda). Suite JS completa **87/87**.
- **phpcs/PHPUnit/Behat**: el fix es JS vendado (sin PHP); se confirma en CI.

## Seguimiento

- **Test de regresión vendado:** este ADR introduce el primer test Vitest sobre
  `assets/scorm/*` (declarado "fuera de alcance" en `vitest.config`), justificado por ser
  crítico para la nota. No cuenta para cobertura (`include: ['js/**']`); es guardia de
  comportamiento.
- **Lección para DEC-80-01:** verificar el **punto de entrada real** (`getHandle`/`get`),
  no sólo la función auxiliar (`find`), antes de declarar un linchpin.
- Posible follow-up: un Behat `@javascript` de grading que corra en modo `secure` real
  (no sólo el paso server-side) habría cazado esto antes; queda recomendado.
