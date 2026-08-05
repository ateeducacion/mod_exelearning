---
id: AN-001
titulo: "Renderizado de la sidebar nativa de eXeLearning en Moodle"
fecha: 2026-05-28
fuentes:
  - REPO-002
  - REPO-003
  - REPO-005
  - FTE-008
relacionados:
  - DEC-0-03
herramienta_ia:
  interfaz: claude-code
  modelo: claude-opus-4-7
---

## Resumen

`mod_exeweb` y `wp-exelearning` ya resuelven el problema de mostrar la sidebar nativa
de eXeLearning: **se sirve el paquete publicado tal cual dentro de un `<iframe>`** y la
sidebar la implementa el propio JS del paquete. `mod_exelearning` debería heredar
exactamente esta técnica y no reimplementar un TOC al estilo `mod_exescorm`/`mod_scorm`.

## Hechos citados

- `mod_exeweb/locallib.php` define `exeweb_display_embed()` y `exeweb_display_frame()`
  que terminan emitiendo un `<iframe src="pluginfile.php/.../content/{revision}/index.html">`
  (REPO-002).
- El paquete publicado contiene `index.html` + `lib/exe_player.js` + `styles/`
  (REPO-005, FTE-008).
- `wp-exelearning` aplica la misma técnica con un endpoint REST (`/wp-json/exelearning/v1/content`)
  que actúa de content-proxy con headers de seguridad (REPO-003).

## [INTERPRETACION]

- La técnica iframe + `pluginfile.php` aísla CSS y JS del paquete del resto de Moodle.
  No hay riesgo de colisión de estilos ni de framework JS.
- La sidebar no necesita conocer Moodle. Sólo necesita poder emitir, desde dentro del
  iframe, **mensajes de tracking** (xAPI o SCORM) hacia el LMS exterior.

## [HIPOTESIS]

- Si el paquete eXeLearning emite eventos `postMessage(...)` con la estructura de un
  statement xAPI, un shim de `mod_exelearning` (en `amd/src/xapi_shim.js`) puede
  recogerlos y encadenarlos al endpoint `core_xapi_post_statement`. Esto evita inyectar
  globals (`API`, `API_1484_11`) propias de SCORM.
- El nodo activo de la sidebar puede sincronizarse con el progreso si el paquete
  expone fragment identifiers consistentes (`#idevice-NNN`).

## Consecuencias para `mod_exelearning`

- `view.php` ≈ `mod_exeweb/view.php` (iframe del paquete).
- `lib.php` añade gradebook (no presente en `mod_exeweb`) inspirado en
  `mod_workshop`/`mod_h5pactivity` (no en `mod_exescorm` aunque tienta).
- Renderer mínimo: header con título + iframe + footer opcional con barra de progreso.

## [PENDIENTE]

- EXP-001: descomprimir un paquete publicado real de eXeLearning y documentar los
  identificadores HTML de los iDevices calificables.
- Confirmar si el motor JS de la sidebar usa jQuery, vanilla o framework moderno.
- Definir contrato `postMessage` ↔ `core_xapi`.
