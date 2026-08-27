---
id: DEC-105-02
title: "La sesión SCORM se abre desde el host con session.open sin propiedad del ciclo de vida, y se neutraliza la entrada SCO de las exportaciones subidas"
status: Accepted
date: 2026-08-27
tracking_issue: 105
deciders:
  - erseco
  - claude-code
sources:
  - REPO-005
related:
  adrs: [DEC-105-01, DEC-36-01, DEC-13-11, DEC-16-01, DEC-74-01, DEC-5-01]
ai_assistance:
  tool: claude-code
  model: claude-opus-5
---

# DEC-105-02: La sesión SCORM se abre desde el host con session.open sin propiedad del ciclo de vida, y se neutraliza la entrada SCO de las exportaciones subidas

## Contexto

El modelo de servido del plugin no tiene SCOs: `view.php` publica un único `window.API`
(el tracker de `js/scorm_tracker.js`, DEC-74-01) y **todas las páginas** del paquete
comparten esa sesión y un único `cmi.core.lesson_status`. El contenido servido es una
exportación web (sin la clase `exe-scorm` en `<body>`), y DEC-13-11 documenta por qué el
plugin no añade esa clase: encendería el ciclo de vida SCO de la página y la CSS de
presentación SCORM; en su lugar se parchean al servir los dos únicos iDevices que
condicionan su guardado a ella (`form`, `scrambled-list`; `idevice_patch.php`).

Hasta ahora el bootstrap inyectado (DEC-36-01) se limitaba a `pipwerks.SCORM.init()`.
Con el runtime reescrito de `exelearning/exelearning#2209` — que DEC-105-01 instala
completo — eso deja de bastar: el runtime **retiene toda escritura al LMS hasta que su
política de entrada ha corrido**, y esa política sólo corre al abrir la sesión por uno de
sus dos puntos de entrada: `loadPage()` (el del SCO) o `exeScorm12.session.open()` (el de un
host, añadido en `#2209` para este plugin; `public/app/common/scorm/scorm12/exe-scorm12-adapter.js`
@ `a457d71a7`).

Además, desde DEC-16-01 el plugin acepta una exportación SCORM 1.2 subida como paquete, y
esa página **trae su propia entrada SCO**: `<body class="exe-export exe-scorm exe-scorm12"
onload="loadPage()">` (`Scorm12Exporter.ts`/`PageRenderer.ts`), y `exe_export.js` llama
también a `window.loadPage()` cuando el `<body>` lleva `exe-scorm`.

## Problema

Tres fallos medidos en un Moodle real, uno por cada forma de abrir la sesión que no es la
elegida (mensajes de los commits `866a20e` y `09b3f4c`):

1. **`pipwerks.SCORM.init()` solo**: pipwerks abre su conexión, pero el cliente propio del
   runtime sigue inactivo y **rechaza localmente con 301 cada escritura** antes de llegar
   al LMS. Es silencioso: el registro de actividades tenía la nota (`scored 1`, `score 50`),
   la política de entrada se declaraba aplicada, `cmi.core.score.raw` quedaba vacío y no
   salía ningún POST hacia `track.php`. La columna del libro de calificaciones quedaba
   vacía para una actividad completada.
2. **`loadPage()`** (el ciclo SCO): instala el ciclo de vida de página
   (`pagehide`/`visibilitychange` → terminación). Como todas las páginas comparten sesión y
   estado, tras publicar `passed` en la página 1, `loadPage()` en la página 2 veía un SCO
   terminado y **cerraba la sesión**: la página 2 corría con una conexión muerta y no
   grababa nada.
3. **La entrada SCO del propio paquete** (exportación SCORM subida): aunque el bootstrap
   abra bien la sesión, el `onload="loadPage()"` de la página y la llamada de
   `exe_export.js` abren **además** la sesión como SCO. El runtime aplica desde
   `b3ae6167a` la regla "el primer `open` que tiene éxito decide quién posee el ciclo de
   vida": si gana el del paquete, vuelve el fallo 2. Quién gana es una **carrera** entre el
   primer tick (50 ms) del bootstrap y los eventos de carga de la página.

## Opciones consideradas

1. **`pipwerks.SCORM.init()`** — descartada: fallo 1, y silencioso.
2. **`loadPage()`** — descartada: fallo 2 (fue el estado de la rama durante un commit,
   `866a20e`, revertido por `09b3f4c`).
3. **`exeScorm12.session.open({ ownsLifecycle: false })`** (elegida): el punto de entrada
   que el runtime ofrece a un host. Levanta el cliente propio, aplica la política de
   entrada (abre la puerta de escritura) y **no instala** el ciclo de vida de página, que
   queda en manos del host.
4. Para la exportación SCORM subida, tres formas de tratar su entrada SCO:
   - **Confiar en "el primer open gana"** — descartada: es una carrera, no un contrato.
   - **Rechazar la subida** — decisión de producto que recorta DEC-16-01; no tomada aquí.
   - **Neutralizar la entrada** en el injector (elegida): quitar el `onload="loadPage()"` y
     las clases `exe-scorm`/`exe-scorm12` del `<body>`. Determinista, y deja la página
     exactamente como cualquier otro paquete que el plugin sirve (DEC-13-11).

## Evidencia

- Punto de entrada de host y regla de propiedad: `exe-scorm12-adapter.js` (`session.open`,
  `state.ownsLifecycle`, "the first successful open decides") y
  `doc/development/scorm12-runtime-contract.md` §3 en `exelearning/exelearning@a457d71a7`;
  política de entrada idempotente en `b3ae6167a`.
- Entrada SCO del export: `Scorm12Exporter.ts` (`bodyClass = 'exe-export exe-scorm
  exe-scorm12'`, `onLoadScript: 'loadPage()'`), `PageRenderer.ts` (`onload="…"`),
  `public/app/common/exe_export.js::loadScorm()` (`classList.contains('exe-scorm')` →
  `initScorm()` → `window.loadPage()`), mismo commit.
- Bootstrap y neutralización: `classes/local/scorm/scorm_injector.php`
  (`inject()`, `neutralise_sco_entry()`). Tests en
  `tests/local/scorm/scorm_injector_test.php`: el bootstrap llama a
  `ns.session.open({ ownsLifecycle: false })` y no a `window.loadPage()`; una exportación
  SCORM (raíz y `html/`) queda con el bootstrap como único abridor, sin `onload`, sin
  `exe-scorm`, conservando el resto de atributos y clases; una exportación web vuelve byte a
  byte desde `<body>`; proveedor de casos de `neutralise_sco_entry()` (comillas, orden de
  atributos, mayúsculas, un `onload` que no es la entrada SCO, atributo `class` vaciado, sin
  `<body>`).
- Vaciado de fin de página: `js/scorm_tracker.js` (commit síncrono en `beforeunload` y
  autocommit de 500 ms). El contenido nunca llama a `LMSFinish` en este modelo.
- Consumidores de `exe-scorm` en el contenido (`exelearning@a457d71a7`): `common.js`
  (`loadAndInitScorm` de los juegos), `exe_export.js`, y los iDevices `form`,
  `scrambled-list`, `trueorfalse`, `adaptative-quiz`, `geogebra-activity`,
  `interactive-video`, `progress-report`; CSS de presentación en `themes/base/neo`. Todos
  ellos ya corren sin la clase en cada `.elpx` que el plugin sirve hoy.

## Decisión

1. **El bootstrap inyectado abre la sesión con `exeScorm12.session.open({ ownsLifecycle:
   false })`** en cada página, sondeando cada 50 ms hasta que el runtime exponga
   `session.open`; `pipwerks.SCORM.init()` queda sólo como fallback tras dos segundos, para
   un runtime anterior a ese punto de entrada — camino muerto mientras DEC-105-01 sirva la
   copia del plugin, y un test fija que la copia vendorada define `exeScorm12.session`.
2. **El bootstrap no llama a `loadPage()`**, y **nada más en la página debe hacerlo**: el
   injector elimina del `<body>` el `onload="loadPage()"` (exactamente ese handler; otro
   `onload` se respeta) y las clases `exe-scorm`/`exe-scorm12`, conservando el resto
   (`exe-export`, clase de fuente global, `lang`). Una página sin entrada SCO — toda
   exportación web — no se reescribe.
3. **El ciclo de vida de página es del plugin**: el runtime no instala `pagehide` ni
   `visibilitychange`; el vaciado de fin de página lo hacen el `beforeunload` síncrono del
   tracker y el autocommit de 500 ms.
4. **Una exportación SCORM subida se sirve como una exportación web**: sin clase
   `exe-scorm`, con el runtime del plugin (DEC-105-01), con el parche de DEC-13-11. Es la
   misma regla que ya rige para todo `.elpx`, aplicada al paquete que llega con la clase.

## Consecuencias

- Positivas: el plugin graba con el runtime reescrito (antes: registro con nota y libro
  vacío); una sesión por carga de página, coherente con el modelo de intentos (DEC-0-07);
  quién posee el ciclo de vida deja de ser una carrera; un único modelo de servido para
  todo paquete, que es el que prueban los fixtures del plugin.
- Negativas / coste: los iDevices que condicionan comportamiento a `body.exe-scorm` siguen
  en una exportación SCORM subida el mismo camino "web" que en cualquier `.elpx` de hoy
  (deuda ya conocida: DEC-13-11 cubre `form`/`scrambled-list`); la política de entrada
  promociona `''`/`not attempted` a `incomplete` en cada apertura de página, lo que cuesta
  un POST `noop` extra por página (`ingest()` no escribe mientras `score.raw` esté vacío);
  el plugin depende del nombre, opciones y retorno de `exeScorm12.session.open`, fijados por
  aserciones de cadena en `scorm_injector_test.php` — si upstream los cambia, cambian el
  bootstrap y los tests a la vez.
- Documentación upstream: `scorm12-runtime-contract.md` §1/§12 y ADR-2209-01 deben
  describir a este consumidor tal como es ahora (cinco capas, `session.open`, parsers
  `exe12/`), no el snapshot de julio.

## Riesgos

- Un runtime vendorado sin `session.open` haría caer el bootstrap en
  `pipwerks.SCORM.init()` y reproduciría el fallo 1 en silencio. Mitigación: DEC-105-01
  (siempre la copia del plugin) + el test que exige `exeScorm12.session` en el fichero
  vendorado.
- Si `exelearning#2322` retira las guardas `exe-scorm` de `form`/`scrambled-list`,
  `idevice_patch` pasa a no hacer nada (degrada sin romper). Decidir entonces si se retira
  con un DEC o se conserva para paquetes antiguos.

## Validación

`tests/local/scorm/scorm_injector_test.php` (6 tests) y
`tests/local/scorm/scorm_runtime_test.php` en CI; mediciones en Moodle real recogidas en
los mensajes de `866a20e` y `09b3f4c`; el harness de grabación de `exelearning#2310`
reproduce este bootstrap (`session.open({ ownsLifecycle: false })`) como modelo de servido
del plugin, con lo que las trazas que grabe son las de este modelo.

## Seguimiento

- Re-pin del runtime contra la release etiquetada (DEC-105-01 §5) y revisión de las
  aserciones de cadena del bootstrap si `session.open` cambia.
- Fixture posterior a la reescritura en `research/fixtures/elpx/` y escenario de extremo a
  extremo por `exe12/` (DEC-105-01, seguimiento).
- Retirar o conservar `idevice_patch` cuando upstream retire las guardas `exe-scorm`.
