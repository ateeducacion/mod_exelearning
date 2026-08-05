---
id: DEC-0-12
tracking_issue: 0
legacy_id: DEC-0012
title: "Re-escaneo de iDevices y re-sincronización del libro tras guardar en el editor embebido"
status: Accepted
date: 2026-05-29
deciders:
  - erseco
  - claude-code
sources:
  - REPO-005
related:
  adrs: [DEC-0-07, DEC-0-08, DEC-0-09]
ai_assistance:
  tool: claude-code
  model: claude-opus-4-8
---

# DEC-0-12: Re-escaneo de iDevices y re-sincronización del libro tras guardar en el editor embebido

## Contexto

El editor embebido de eXeLearning (DEC-0-09) permite al profesor editar el
recurso desde la propia actividad ("Edit with eXeLearning") y guardar con
"Save to Moodle". El guardado va a `editor/save.php`, que recibe el `.elpx`
exportado, lo almacena en la filearea `package` con itemid = nueva revisión y
sube `exelearning.revision`.

Al editar, el profesor puede **añadir o quitar iDevices calificables** (un
`trueorfalse`, un `guess`, etc.). Cada iDevice calificable es una columna del
libro de calificaciones (patrón multi-itemnumber, AN-007 / DEC-0-08).

## Problema

`editor/save.php`, tal como se portó, sólo:
1. guardaba el ZIP nuevo en `package`,
2. hacía la extracción legacy de preview (`exelearning_package_legacy`),
3. subía la revisión.

**NO** re-extraía el contenido al filearea `content/<revision>` con el shim
SCORM, **ni** re-escaneaba los iDevices, **ni** re-sincronizaba el libro. Por
tanto, tras editar y guardar:

- El contenido servido en el iframe quedaba obsoleto (revisión vieja).
- Las columnas del gradebook NO reflejaban los iDevices añadidos/eliminados:
  un iDevice nuevo no creaba columna; uno borrado seguía contando.

Es el mismo tipo de fallo que el de las subidas programáticas (el `addModule`
del Playground no pasaba por `add_instance`): cualquier vía de escritura del
paquete que no sea el formulario debe disparar extracción + sync explícitos.

## Decisión

`editor/save.php`, tras actualizar `revision`, invoca:

```php
exelearning_extract_stored_package($context->id, (int)$exelearning->revision);
exelearning_sync_grade_items($exelearning->id, $context->id);
```

- `exelearning_extract_stored_package()` re-extrae el `.elpx` a
  `content/<revision>/`, re-inyecta el wrapper/loader SCORM (pipwerks) y deja
  `index.html` como mainfile. Localiza el paquete en **cualquier** itemid, así
  que toma la nueva revisión recién guardada.
- `exelearning_sync_grade_items()` re-parsea `content.xml`, y por cada iDevice
  calificable: si su `objectid` ya existía, **reactiva/actualiza** su fila
  (mismo `itemnumber`, preservando historial de notas); si es nuevo, asigna el
  siguiente `itemnumber` y crea su columna; los `objectid` que ya no aparecen
  se marcan `deleted=1` y su grade item se borra del libro con
  `grade_update(..., ['deleted'=>true])` — el historial de `grade_grades` lo
  conserva Moodle.

## Consecuencias

Positivas:
- Editar y guardar mantiene libro y contenido coherentes con el paquete real.
- Reutiliza exactamente la maquinaria de `add_instance` (sin duplicar lógica).
- El borrado de columnas es no destructivo (soft-delete + historial preservado).

Negativas / coste:
- Cada guardado re-extrae el ZIP y re-parsea `content.xml` (coste aceptable: el
  guardado es una acción puntual del profesor, no de cada alumno).

## Riesgo (RIE-006): estabilidad de `objectid` entre exports del editor

La reactivación idempotente depende de que el `odeIdeviceId` (nuestro
`objectid`) sea **estable** entre exports del MISMO iDevice. Observación
empírica durante la verificación: fixtures generados por distintas versiones del
editor usan formatos de id distintos (`idevice-1779989968114-sevb8qqdy` antiguo
vs `idevice-mpqfyyr9-ym7gzu5jp` nuevo). Si el editor reasigna el `objectid` de un
iDevice ya existente al re-exportar, el sync lo tratará como "uno nuevo + uno
borrado" → **columna duplicada** y pérdida de continuidad de la nota de ese
iDevice (la antigua queda en una columna soft-deleted).

Mitigaciones:
- Mientras el `objectid` se mantenga estable (caso normal del editor v4 al
  reabrir/guardar el mismo proyecto), el sync es idempotente — **verificado**:
  re-sincronizar el mismo paquete dos veces NO duplica columnas (sigue en 2).

### RESUELTO por upstream (2026-05-29): PR exelearning/exelearning#1791

El riesgo RIE-006 está **resuelto en upstream**. El PR
[`exelearning/exelearning#1791`](https://github.com/exelearning/exelearning/pull/1791)
("fix(ids): unify and harden the four stable-identifier PRs", **merged
2026-05-19**) hace que `<odeIdeviceId>`, `<odePageId>` y `<odeBlockId>` se
**preserven verbatim** al importar un `.elpx`; sólo se reasignan ante una
colisión real (modo merge). La invariante garantizada por el PR es: *"Round-trip
a `.elpx` twice without changes → diff of `content.xml` is empty (modulo
legitimate timestamps)"*.

Consecuencia para mod_exelearning: con un editor embebido **post-#1791**, editar
y guardar (`editor/save.php` → import en el editor → re-export → re-sync)
**preserva los `objectid`**, por lo que el re-sync es idempotente y **NO se
duplican columnas**. El antiguo comportamiento (reasignar ids en `buildPageData`)
era anterior a este PR.

Por tanto **NO se implementa** el emparejamiento de respaldo (pageid + tipo +
orden): sería código defensivo contra un bug ya corregido aguas arriba. Única
cautela: un despliegue que use un build del editor **anterior a #1791** podría
ver el comportamiento viejo; se documenta como limitación de versiones antiguas
del editor, no del plugin. La nota de seguimiento que pedía el respaldo queda
**cerrada**.

## Corrección posterior (2026-05-29): el borrado debe llamar a grade_update

Al verificar el caso "el profesor borra un iDevice evaluable" se detectó que
`exelearning_sync_grade_items()` marcaba `deleted=1` en NUESTRA tabla
`exelearning_grade_item` pero **no eliminaba la columna del libro**: la columna
quedaba como fantasma (`gradebook_peritem_cols` no bajaba). El grade item de
Moodle sólo desaparece cuando se le llama `grade_update(..., ['deleted'=>true])`.
Corregido: el bucle de borrado ahora hace ambas cosas (marca la fila propia Y
llama a `grade_update` con `deleted`), preservando el historial en `grade_grades`.
Verificado: borrar un iDevice deja el libro con sólo el overall + los iDevices
restantes (las columnas eliminadas desaparecen).

## Validación

Verificado en Docker (erseco/alpine-moodle:v5.0.7), instancia demo:

- Paquete con 2 iDevices (trueorfalse + guess) → guardar uno con 1 iDevice
  (scrambled-list): `live=1, deleted=2`, las 2 columnas anteriores soft-deleted.
- Re-sync con el MISMO paquete (mismos objectids) ×2 → `live=2,
  gradebook_peritem=2` (idempotente, sin duplicados).
- Restaurado el fixture canónico → 2 columnas con los objectids originales.

## Seguimiento

- TAREA-034: verificación e2e real pulsando "Save to Moodle" en el navegador
  tras editar (requiere el editor estático funcional; los assets ya se sirven).
- RIE-006: confirmar si el editor v4 preserva `odeIdeviceId` al re-exportar el
  mismo proyecto; si no, implementar el emparejamiento de respaldo.
