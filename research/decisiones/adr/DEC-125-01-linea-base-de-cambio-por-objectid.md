---
id: DEC-125-01
title: "La línea base de cambio del tracker se indexa por objectid, no por la posición de página"
status: Accepted
date: 2026-08-21
tracking_issue: 125
deciders:
  - erseco
  - claude-code
sources:
  - REPO-004
related:
  adrs: [DEC-5-01, DEC-13-11, DEC-0-07]
ai_assistance:
  tool: claude-code
  model: claude-opus-5
---

# DEC-125-01: La línea base de cambio del tracker se indexa por objectid, no por la posición de página

## Contexto

`captureItemScores()` (`js/scorm_tracker.js`) decide qué notas por iDevice se envían al
servidor comparando el `cmi.suspend_data` recién parseado contra una línea base del envío
anterior. Sólo viaja lo que ha cambiado: es una optimización de escritura, no un filtro
semántico.

Esa línea base estaba indexada por **N**, la posición del iDevice dentro del DOM de su
página.

## Problema

N no es una identidad. El formato heredado de `suspend_data` la reinicia en cada página, de
modo que el hueco 2 de la página 1 y el hueco 2 de la página 2 son iDevices distintos con la
misma clave. [[DEC-5-01]] ya lo había establecido para el enrutado en servidor —de ahí que
el servidor enrute por `objectid`— pero la línea base del cliente seguía siendo posicional.

Cuando un iDevice de la página 2 caía en un hueco cuyo ocupante de la página 1 tenía **la
misma nota y el mismo peso**, comparaba igual contra la línea base, se daba por «sin
cambios» y se descartaba. El alumno lo respondió; el servidor nunca se enteró; su columna
del libro de calificaciones simplemente no se escribió. En silencio, sin error, y sólo para
el alumnado que diera con esa combinación.

## Decisión

La línea base se indexa por `objectid`. Dos iDevices distintos nunca son «lo mismo sin
cambios» el uno respecto del otro.

Dos consecuencias de diseño que van con ella y no son opcionales:

- **La línea base ya no puede sustituirse en bloque por la página recién parseada.** Con
  claves de página eso funcionaba porque la clave sólo tenía sentido dentro de la página
  cargada; con `objectid` sustituir en bloque olvidaría todas las demás páginas y
  reemitiría sus notas en cuanto el alumno volviera a una de ellas. La línea base **arrastra
  hacia delante** las entradas de las páginas no cargadas, así que volver a una página no
  emite nada hasta que una nota cambie de verdad. La optimización de escritura sobrevive.
- **Las entradas que no resuelven contra el DOM de la página actual se descartan antes de
  comparar**, no después. Así una entrada obsoleta de otra página no puede atribuirse al
  iDevice que ocupe ese índice en la página cargada.

## Consecuencias

- Se cierra una pérdida de nota silenciosa dependiente de los datos: sólo se manifestaba
  cuando dos iDevices de páginas distintas coincidían en hueco, nota y peso.
- El cliente y el servidor comparten por fin la misma noción de identidad ([[DEC-5-01]]).
- La memoria de la línea base crece con el número de iDevices calificables visitados en la
  sesión, no con el de la página actual. Es despreciable: una entrada por iDevice, y el
  propio `cmi.suspend_data` está acotado a 4096 caracteres.
- No cambia el formato de `suspend_data` ni el contrato con el servidor: es una decisión
  interna del cliente sobre qué considera «cambiado».

## Validación

Tres tests en `tests/js/scorm_tracker.test.js`, los tres en rojo contra el tracker de `main`:
enrutar una entrada recién puntuada por `objectid`; emitir un iDevice de la página 2 que cae
en un hueco cuyo ocupante de la página 1 puntuó idéntico; y no reemitir notas sin cambios al
volver a una página. Los dos primeros son imágenes especulares y **son** la regla de
atribución: un tracker ingenuo en cualquiera de los dos sentidos pasa uno y falla el otro,
así que sólo la pareja fija el comportamiento. Suite JS completa: 39 tests, `exit 0`.
