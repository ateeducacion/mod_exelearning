---
id: AN-007
titulo: "El SCORM 1.2 export de eXeLearning v4 agrega iDevices: confirma parsear content.xml"
fecha: 2026-05-28
fuentes:
  - REPO-005
  - FTE-001
  - FTE-008
relacionados:
  - DEC-0-03
  - AN-005
  - PREG-001
  - PREG-002
  - EXP-001
herramienta_ia:
  interfaz: claude-code
  modelo: claude-opus-4-7
---

## Resumen

El usuario generó con eXeLearning v4 un paquete con **1 página y 2 iDevices
calificables** (`trueorfalse` + `guess`) y lo exportó en formato **SCORM 1.2** —
fixture
[`research/fixtures/scorm-export/actividad-evaluable_scorm12.zip`](../../fixtures/scorm-export/actividad-evaluable_scorm12.zip).
La inspección del `imsmanifest.xml` confirma que **el exporter SCORM 1.2 agrega
ambos iDevices en un único SCO**, sin `imsss:objectives` separados. Por tanto el
camino "consumir el SCORM export" no resuelve multi-grade-items por iDevice.

## Hechos citados

`imsmanifest.xml` del fixture (147 líneas):

```xml
<manifest identifier="eXe-MANIFEST-…"
          xmlns="http://www.imsproject.org/xsd/imscp_rootv1p1p2" …>
  <metadata>
    <schema>ADL SCORM</schema>
    <schemaversion>1.2</schemaversion>
  </metadata>
  <organizations default="eXe-…">
    <organization identifier="eXe-…" structure="hierarchical">
      <title>Test 1</title>
      <item identifier="ITEM-633bbf80-…-b695d98d69a4"
            identifierref="RES-633bbf80-…-b695d98d69a4">
        <title>Test 1</title>
      </item>
    </organization>
  </organizations>
  <resources>
    <resource identifier="RES-633bbf80-bcd2-4a7b-a00d-b695d98d69a4"
              type="webcontent" adlcp:scormtype="sco" href="index.html"> … </resource>
  </resources>
</manifest>
```

- **1 sólo SCO** (`scormtype="sco"`).
- **Cero `imsss:objectives`** en el manifest.
- El `content.xml` propietario incluido en el ZIP **sí** lista los 2 iDevices
  con sus IDs estables (`idevice-1779989968114-sevb8qqdy` trueorfalse,
  `idevice-1779990014981-upsl0qps2` guess). La información está, pero no la
  publica vía SCORM.

## [INTERPRETACION]

- AN-005 sigue siendo cierto a nivel general: "1 SCO por página". Con 1 página,
  hay 1 SCO. El número de iDevices calificables por página no influye en la
  granularidad SCORM.
- Para granularidad **por iDevice**, hay 3 opciones:
  1. **Parsear `content.xml` server-side desde `mod_exelearning`** ← natural en
     ELPX, sin tocar SCORM.
  2. Convencer al exporter SCORM 2004 de eXeLearning de emitir
     `<imsss:objectives>` por iDevice (cambio upstream — PREG-002).
  3. Que los iDevices emitan statements xAPI con `object.id` estable y
     `mod_exelearning` los consuma via `core_xapi_post_statement`. Hoy no
     parecen emitirse desde el HTML publicado (sólo escriben a
     `pipwerks.SCORM` cuando hay wrapper presente).

## Consecuencias para `mod_exelearning`

**Ruta operativa de v1** (consolidación de AN-005 + AN-007):

1. El plugin acepta `.elpx` directamente (no SCORM).
2. Tras subida en `_add_instance`, extrae `content.xml` del ZIP y enumera los
   iDevices con `odeIdeviceTypeName` ∈ whitelist (`trueorfalse`, `guess`,
   `quick-questions*`, `dragdrop`, `complete`, `classify`, `relate`, `sort`,
   `identify`, `discover`, `crossword`, `word-search`, `puzzle`, `trivial`,
   `az-quiz-game`, `mathproblems`, `mathematicaloperations`, `scrambled-list`).
3. Registra en `mdl_exelearning_grade_item(itemnumber, objectid, name, maxscore)`
   un registro por iDevice + cabecera del SCO.
4. Llama `grade_update(..., itemnumber=$n, ...)` por cada item.
5. Para el tracking en runtime, inyecta en el iframe un shim JS que escuche
   las llamadas `pipwerks.SCORM.set('cmi.core.score.raw', …)` del paquete y las
   convierta en statements xAPI hacia `core_xapi_post_statement` con
   `object.id = <objectid>`. Como en el web export no hay pipwerks (sólo en
   SCORM export), el shim debe interceptar los eventos JS del iDevice
   directamente (los `mOptions.scorep`/`scorerp` documentados en REPO-005).

Plan B (si interceptar los eventos JS es frágil): aceptar también el ZIP del
SCORM export, parsearlo igualmente para `content.xml` (que sigue dentro), pero
**inyectar nuestra propia API SCORM** en el iframe (`window.API`) que recoja los
`cmi.*` y los traduzca a per-iDevice usando el orden de aparición en el DOM.
Más complejo y frágil — se descarta para v1.

## [PENDIENTE]

- EXP-002 implementa el camino "parsear content.xml + registrar N grade items".
  Es el cierre práctico de DEC-0-03.
- PREG-002 sigue abierta como propuesta upstream a medio plazo (que eXeLearning
  emita statements xAPI o `cmi.objectives.{n}` con `<objectid>` estable).
