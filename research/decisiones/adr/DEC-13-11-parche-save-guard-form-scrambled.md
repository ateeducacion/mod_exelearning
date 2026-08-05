---
id: DEC-13-11
title: "Parchear al servir el guard de guardado de form/scrambled-list (embebedor-side de exelearning#1925)"
status: Accepted
date: 2026-06-09
tracking_issue: 13
legacy_id: DEC-0042
deciders:
  - erseco
  - claude-code
sources:
  - REPO-004
related:
  adrs: [DEC-13-10, DEC-13-01, DEC-5-01]
ai_assistance:
  tool: claude-code
  model: claude-opus-4-8
---

# DEC-13-11: Parchear al servir el guard de guardado de form/scrambled-list (embebedor-side de exelearning#1925)

## Contexto

Tras DEC-13-10 (12→28 de 30 detectados), @mnunezcedec y @HenarLG reportan en el
issue #13 que **`form` (Formulario) y `scrambled-list` (Lista desordenada)** llegan
al libro con **0** aunque el alumno acierte: el iDevice muestra la nota correcta en
pantalla ("Tu puntuación es 10 (4/4)") pero persiste `Puntuación: 0%` en
`cmi.suspend_data` y `cmi.core.score.raw = 0`. El resto puntúa bien.

## Hallazgo

`exe-scorm` es la clase del `<body>` que eXeLearning usa como interruptor de "modo
SCORM" (export SCORM). **De los 51 iDevices, solo 6 la mencionan**, y solo **`form`
(`form.js:741`) y `scrambled-list` (`scrambled-list.js:551`) condicionan su llamada a
`sendScore()` a ella**:

```js
// form.js:741
if ($('body').hasClass('exe-scorm') && data.isScorm > 0) { $form.sendScore(data); }
// scrambled-list.js:551
if (document.body.classList.contains('exe-scorm') && data.isScorm > 0) { this.sendScore(...); return; }
```

Los otros 4 que la mencionan solo la usan para **cargar el wrapper SCORM o el
arranque diferido**, no para guardar:

- `trueorfalse:82` — solo en init; su SAVE va por `isScorm > 0` (`trueorfalse.js:649`).
- `interactive-video:107` y `geogebra-activity:57` — solo deciden si el iDevice carga
  el wrapper él mismo; el SAVE va por `isScorm > 0` / botón manual, y el wrapper ya lo
  inyecta el plugin (`exelearning_inject_scorm_loader`). interactive-video puntúa 100
  en el paquete del reporte → no afectado.
- `adaptative-quiz:1600/1613` — `wireUpScorm`/arranque diferido; su `setupScorm` tiene
  rama `else` (sin exe-scorm) que registra la actividad y su SAVE va por `isScorm`.

El plugin sirve un export **web/elpx** (`<body class="exe-export exe-web-site">`, **sin**
`exe-scorm`) e inyecta la API SCORM 1.2 (shim `window.API` en `view.php`) + el wrapper
+ fuerza `pipwerks.init()`. Por eso 16/18 iDevices evaluables guardan; solo `form` y
`scrambled-list` exigen la clase y se quedan en el `0` que siembra `registerActivity`.

Upstream `exelearning/exelearning#1925` se cerró (by-design): *"los export SCORM ya
llevan `exe-scorm`; ese código guarda bien"* (@mnarvaezm). Correcto desde eXeLearning
→ la corrección corresponde al **embebedor**.

### Por qué NO inyectar `exe-scorm`

Se evaluó (y descartó) añadir `exe-scorm` al `<body>` servido. Funciona para el
scoring, pero la clase está **fuertemente acoplada**: enciende también (a) el ciclo de
vida SCO del paquete (`exe_export.js` `loadPage`/`unloadPage`), que provoca
**doble-commit** en cada navegación (`unloadPage→LMSFinish` + `beforeunload`), y
`lesson_status`/`session_time` per-página (gana la última); y (b) la **CSS de
presentación SCORM** (cabecera/sidebar distintos del modo web-site). Cada secuela
requería su propio hotfix (guard de `beforeunload`, override CSS), volviéndolo frágil.
Verificado empíricamente: con `exe-scorm` la nota se guarda, pero aparecen esas
secuelas; el experimento confirmó que es un *mismatch* arquitectónico.

## Decisión

Aplicar **embebedor-side** el mismo cambio de una línea que describe #1925: al extraer
el paquete, quitar la condición `body.exe-scorm` del **guard de guardado** de los dos
iDevices afectados, dejándolos como el resto (`if (data.isScorm > 0)`). Nada más se
toca: **sin** `exe-scorm` en el body → presentación web-site idéntica, sin ciclo SCO,
sin regresiones, sin override CSS.

## Consecuencias

- `form` y `scrambled-list` guardan su nota como los demás; el shim de `view.php` la
  enruta por `objectid` (DEC-5-01) sin cambios. Verificado: Formulario persiste
  `Puntuación: 100%` (`score.raw=50`) y scrambled-list invoca `sendScoreNew`, ambos con
  `body` web-site (sin `exe-scorm`).
- Cero cambio visual y cero ciclo de vida SCO (no hay doble-commit ni
  `lesson_status`/`session_time` per-página, que sí traía la vía exe-scorm).
- Dependencia de formato: el replace busca la cadena exacta del guard (la variante
  `data.isScorm`; los guards de init usan `ldata.isScorm`). Es idempotente y degrada
  con seguridad — si un futuro upstream reformatea el guard, el replace es no-op y el
  comportamiento vuelve al actual (no empeora). Si upstream corrige #1925, el replace
  también queda no-op.
- **Sin back-fill**: at-rest, solo afecta a subidas/re-guardados/ediciones nuevas
  (re-extracción). Las actividades ya extraídas se actualizan al re-subir/re-guardar/editar.
- Auditados los 51 iDevices: solo estos dos tienen el SAVE acoplado a `exe-scorm`; si en
  el futuro otro lo hiciera, se añade su cadena al mapa de `$patches`.

## Implementación

- `lib.php`: nueva función `exelearning_patch_idevice_save_guards($contextid, $revision)`,
  llamada tras `exelearning_inject_scorm_loader()` en la extracción. Itera el filearea
  `content`, y en `form.js`/`scrambled-list.js` hace `str_replace` de la cadena exacta
  del guard por `data.isScorm > 0`. Idempotente; re-store por delete+recreate (igual que
  `inject_scorm_loader`).
- Verificación: extracción nueva de `superelpx.elpx` → los servidos `form.js`/
  `scrambled-list.js` quedan con `if (data.isScorm > 0)`; en navegador (alumno) el
  Formulario guarda 100% y scrambled-list alcanza `sendScoreNew`, con `body` web-site.
- phpcs limpio.
