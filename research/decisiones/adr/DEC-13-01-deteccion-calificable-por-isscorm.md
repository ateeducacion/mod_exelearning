---
id: DEC-13-01
titulo: "Detección de iDevices calificables por el flag isScorm (issue #13 #2 y #5)"
estado: Aceptada
fecha: 2026-06-03
tracking_issue: 13
legacy_id: DEC-0022
agentes:
  - erseco
  - claude-code
fuentes:
  - REPO-004
relacionados:
  - DEC-5-01
  - DEC-12-01
herramienta_ia:
  interfaz: claude-code
  modelo: claude-opus-4-8
---

# DEC-13-01: Detección de iDevices calificables por el flag isScorm (issue #13 #2 y #5)

## Contexto

El issue #13 pide, en el punto 2, detectar **solo** las actividades que el autor marcó
explícitamente para evaluación (hoy "se detectan todas por defecto"), y en el punto 5,
soportar 10 tipos de iDevice que hoy no se detectan aunque estén configurados para puntuar:
Form, Before and After, Hidden Image, Periodic Table, Select Multimedia, Memory Cards (`flipcards`),
Map, Interactive Video, Challenge y Lock (`padlock`).

Hasta esta ADR, `classes/local/package.php::detect_gradable_idevices()` incluía un iDevice
como calificable si su `odeIdeviceTypeName` estaba en una lista fija
`GRADABLE_IDEVICE_TYPES` (20 tipos). Eso causaba ambos problemas: (a) creaba columna de nota
para TODA actividad de un tipo "calificable" aunque el autor no la hubiera configurado para
puntuar (punto 2), y (b) omitía los 10 tipos no listados aunque sí reportaran nota (punto 5).

## Hallazgo

eXeLearning v4 marca cada iDevice con la propiedad `isScorm` dentro de `<jsonProperties>`:
`0` = no reporta nota, `1` = guarda la puntuación automáticamente, `2` = botón "Guardar
puntuación". Todo el framework de gamificación/SCORM del editor condiciona el reporte de
puntuación a `isScorm > 0` (`exelearning/public/app/common/common.js`, que construye la línea
de `cmi.suspend_data` que este plugin ya parsea en `classes/local/track.php`; y cada
`idevices/base/*/export/*.js`). Se verificó que los 10 tipos del punto 5 soportan `isScorm`.

## Decisión

Cambiar la **puerta de detección** de "tipo en lista fija" a "`isScorm > 0` en
`jsonProperties`", de forma **agnóstica al tipo**. Esto unifica y resuelve ambos puntos: se
detectan exactamente los iDevices que el autor marcó para evaluación, sea cual sea su tipo.
`GRADABLE_IDEVICE_TYPES` queda como **metadato informativo** (ampliado con los 10 tipos), no
como puerta. El runtime de scoring (`track.php`) no cambia: ya enruta por `objectid` y es
agnóstico al tipo, así que los tipos nuevos fluyen por la misma tubería.

## Consecuencias

- Los fixtures que representan actividades evaluables se actualizan para llevar `isScorm` en
  sus iDevices calificables (la detección por tipo no lo exigía antes).
- Un paquete sin `isScorm` en `jsonProperties` no detecta calificables; en v4 el editor
  siempre escribe el flag, de modo que su ausencia significa "no configurado para puntuar".
- `detect_gradable_idevices()` extrae `<jsonProperties>` por iDevice, decodifica entidades y
  busca `"isScorm": 1|2` (cubre también la forma anidada, p. ej. de interactive-video).

## Implementación

- `classes/local/package.php`: helper privado `idevice_reports_score()` y nueva condición de
  inclusión en `detect_gradable_idevices()`.
- Tests: `tests/package_test.php` (solo-marcados, tipos nuevos con `isScorm>0`, jsonProperties
  HTML-escapado, flag anidado).
- Fixtures: `research/fixtures/elpx/actividad-evaluable.elpx` y `multipage-gradable.elpx`
  llevan ahora `isScorm` en sus iDevices calificables.

## Enmienda (2026-06-03): `isScorm` también en `<htmlView>`

Verificado con `todos-los-idevices.elpx` (50 iDevices): `isScorm` vive en `<jsonProperties>`
para los iDevices json-type pero en `<htmlView>` para los html-type (interactive-video,
dragdrop, periodic-table, beforeafter, geogebra…), que **no** tienen jsonProperties. Leer solo
jsonProperties detectaba **2**; leyendo además el htmlView detecta **17** (los realmente
configurados; los 28 sin `isScorm` no están configurados y se excluyen, correcto). Fix:
`idevice_reports_score()` lee `isScorm` de jsonProperties y, si falta, del htmlView (helper
`extract_isscorm($block, $tag)`); calificable si `> 0`. Test:
`tests/package_test.php::test_isscorm_in_htmlview_detected`.
