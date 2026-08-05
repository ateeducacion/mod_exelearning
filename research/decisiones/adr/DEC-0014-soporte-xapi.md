---
id: DEC-0014
titulo: "Soporte xAPI: qué haría falta en eXeLearning y en mod_exelearning, y si compensa"
estado: Aceptada
fecha: 2026-05-29
agentes:
  - erseco
  - claude-code
fuentes:
  - REPO-004
  - REPO-005
  - FTE-003
experimentos: []
herramienta_ia:
  interfaz: claude-code
  modelo: claude-opus-4-8
relacionados:
  - DEC-0003
  - DEC-0007
---

# DEC-0014: Soporte xAPI: qué haría falta en eXeLearning y en mod_exelearning, y si compensa

## Contexto

El tracking vigente de `mod_exelearning` es un **bridge SCORM 1.2** (DEC-0003):
el shim `window.API` en `view.php` captura las llamadas `LMSSetValue` que hacen
los iDevices (vía el wrapper pipwerks que eXeLearning incluye en su export
SCORM) y las reenvía a `track.php`, que parsea `cmi.core.score.raw` y, por
iDevice, la cadena `cmi.suspend_data`, y llama a `grade_update()`.

El mantenedor quiere mantener SCORM 1.2 como canal productivo, y abrir este ADR
para **proponer qué haría falta para soportar xAPI** (en eXeLearning y en el
plugin) y sopesar ventajas/inconvenientes, de cara a una decisión futura.

Hecho verificado (2026-05-29): **eXeLearning upstream NO emite xAPI hoy**.
Búsqueda en `exelearning/exelearning`: 0 resultados para `xapi`, `tincan`,
`xAPI statement`. El export actual es Web / SCORM 1.2 / SCORM 2004 / IMS CP; el
tracking se hace exclusivamente por el data model CMI de SCORM.

## Problema

¿Qué se necesitaría para que `mod_exelearning` registrara el progreso de los
alumnos vía **xAPI** (statements) en lugar de —o además de— el bridge SCORM 1.2?
¿Compensa frente a lo que ya tenemos?

## Limitaciones del bridge SCORM 1.2 actual (motivación)

- **Granularidad pobre**: SCORM 1.2 expone un único `cmi.core.score.raw` por SCO.
  El detalle por iDevice lo extraemos parseando `cmi.suspend_data` con una regex
  frágil (`^(\d+)\. "…"; …: (\d+)%; …: (\d+)%`), que depende del formato exacto
  que serializa eXeLearning v4. Si upstream cambia ese formato, se rompe.
- **Semántica limitada**: SCORM 1.2 sólo distingue `completed`/`incomplete` y
  `passed`/`failed`; no captura intentos por pregunta, respuestas dadas, tiempos,
  ni interacciones ricas.
- **Acoplamiento a pipwerks**: dependemos de que eXeLearning inyecte el wrapper
  pipwerks y de hacer su `init()` nosotros (trampa ya documentada).
- **No estándar moderno**: xAPI/cmi5 es el estándar actual; SCORM 1.2 es legado.

## Qué haría falta — en eXeLearning (upstream)

Hoy no emite xAPI; habría que añadir, en orden de menor a mayor ambición:

1. **Emisión de statements xAPI desde el paquete publicado.** Que cada iDevice
   calificable, al resolverse, construya un statement xAPI 1.0.3
   (`actor`/`verb`/`object`/`result`) y lo entregue a un *endpoint configurable*
   o lo publique por `postMessage` a la ventana contenedora (Moodle). Es el
   cambio central y el más reutilizable (sirve a cualquier LMS, no solo Moodle).
2. **Identificadores estables de actividad** por iDevice como `object.id` (IRI).
   Ya resuelto en parte: el `odeIdeviceId` es estable tras el PR #1791 (ver
   DEC-0012); habría que materializarlo como IRI de actividad xAPI.
3. **Perfil xAPI propio de eXeLearning** (`Activity Types` y `Verbs`
   publicados, p.ej. `https://exelearning.net/xapi/...`) para que los statements
   sean interoperables y describibles.
4. (Opcional) **Export cmi5** además del SCORM, para LMS que prefieran ese
   empaquetado.

## Qué haría falta — en mod_exelearning (plugin)

Asumiendo que eXeLearning emita statements (vía postMessage, lo más simple
same-origin en nuestro iframe):

1. **Bridge JS xAPI** (`amd/src/xapi_bridge.js`): escuchar los `postMessage` con
   statements desde el iframe del paquete, validarlos y reenviarlos a un endpoint
   del plugin.
2. **Receptor servidor** que use el subsistema **`core_xapi`** de Moodle (el
   mismo que usa `mod_h5pactivity`): `\core_xapi\handler` propio que valida el
   statement, lo persiste y lo traduce a `grade_update()` por `itemnumber`
   reutilizando el mapa `exelearning_grade_item` (objectid → itemnumber) que ya
   tenemos.
3. **Almacén de statements / intentos**: encajar con la tabla de intentos
   (DEC-0007); cada statement de un iDevice → una fila de intento por item.
4. **Capabilities y privacy**: declarar el origen xAPI en el provider de privacy.
5. Mantener el **bridge SCORM 1.2 como fallback** para paquetes que no emitan
   xAPI (compatibilidad hacia atrás).

Referencia de patrón: `mod_h5pactivity` ya hace exactamente esto con `core_xapi`
(`classes/xapi/handler.php`); es el modelo a copiar (AN-003).

## Opciones consideradas

### A. Mantener solo el bridge SCORM 1.2 (statu quo, DEC-0003)

| ✔ Pros | ✘ Contras |
|---|---|
| Ya implementado, verificado y suficiente para el caso actual (nota por iDevice). | Granularidad pobre; regex de `suspend_data` frágil. |
| Cero dependencia de cambios upstream. | Estándar legado. |
| Funciona en cualquier despliegue. | No captura interacciones ricas. |

### B. xAPI nativo, requiere cambios upstream + plugin

| ✔ Pros | ✘ Contras |
|---|---|
| Estándar moderno; granularidad e interacciones ricas; alineado con `core_xapi` y H5P. | **Bloqueado por upstream**: eXeLearning no emite xAPI hoy; sin (1) upstream, el plugin no puede hacer nada. |
| Reusa el mapa objectid→itemnumber ya existente. | Doble esfuerzo (eXeLearning + plugin) y coordinación entre dos repos. |
| Base para analítica de aprendizaje (LRS). | Mientras tanto no aporta nada productivo. |

### C. Híbrido: SCORM 1.2 ahora, xAPI cuando upstream lo emita

| ✔ Pros | ✘ Contras |
|---|---|
| No bloquea; el plugin sigue calificando hoy con SCORM 1.2. | Mantener dos canales si conviven. |
| Permite adoptar xAPI incrementalmente cuando exista en eXeLearning. | — |
| El receptor `core_xapi` se puede diseñar ya y activar al llegar los statements. | |

## Recomendación (a validar)

**A como vigente + C como hoja de ruta.** Mantener el bridge SCORM 1.2 como canal
productivo (no romper lo que funciona) y dejar este ADR como **propuesta de
diseño** del soporte xAPI, condicionado a que **eXeLearning emita statements**
(prerrequisito upstream, hoy inexistente). No tiene sentido implementar el lado
plugin de xAPI mientras el paquete no emita nada que escuchar.

Acción upstream sugerida: abrir/seguir una propuesta en `exelearning/exelearning`
para la emisión de statements xAPI por iDevice (punto 1), que es el desbloqueo
necesario. Es la continuación natural de PREG-002 (que ya logró ids estables vía
#1791).

## Consecuencias

- Si se acepta A+C: no hay trabajo de plugin inmediato; este ADR queda como
  diseño de referencia y se reactiva cuando upstream emita xAPI.
- DEC-0003 se mantiene (SCORM 1.2 vigente); este ADR es su evolución natural.

## Preguntas abiertas — resueltas (2026-05-29, erseco)

- ¿Hay interés de ATE/INTEF en analítica de aprendizaje vía LRS que justifique el
  empuje upstream de xAPI? → **No por ahora.** SCORM 1.2 cubre la calificación en
  gradebook (requisito actual). No se impulsa la emisión xAPI aguas arriba; el
  roadmap queda teórico hasta que haya demanda concreta.
- ¿`postMessage` vs endpoint LRS configurable como transporte? → **Sin decidir**
  (no urge: sólo aplica si algún día upstream emite xAPI).
- ¿cmi5 en alcance? → **Sin decidir** (fuera de alcance hasta reactivar el ADR).

## Decisión (2026-05-29, erseco)

**Aceptada A+C:** SCORM 1.2 como canal productivo vigente + este ADR como diseño
de referencia que se **reactiva sólo si eXeLearning upstream empieza a emitir
statements xAPI**. No hay trabajo de plugin inmediato.

## Seguimiento

- Prerrequisito upstream: emisión de statements xAPI en eXeLearning (continúa la
  línea de PREG-002 / #1791). **No se abre issue por decisión de erseco** (sin
  prioridad de analítica LRS en ATE ahora).
- Si upstream emite xAPI: implementar `xapi_bridge.js` + handler `core_xapi` +
  integración con intentos (DEC-0007), modelando `mod_h5pactivity` (AN-003), y
  decidir entonces transporte (`postMessage` vs LRS) y cmi5.
