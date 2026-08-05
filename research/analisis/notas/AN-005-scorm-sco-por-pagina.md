---
id: AN-005
titulo: "El export SCORM 1.2 de eXeLearning emite 1 SCO por página: implicaciones para multi-grade-items"
fecha: 2026-05-28
fuentes:
  - REPO-005
  - FTE-001
  - FTE-008
relacionados:
  - DEC-0-03
  - AN-001
  - AN-002
  - PREG-001
  - PREG-002
  - EXP-001
herramienta_ia:
  interfaz: claude-code
  modelo: claude-opus-4-7
---

## Resumen

Inspección de `research/fixtures/scorm-export/really-simple_scorm12/imsmanifest.xml`
(EXP-001) revela que **eXeLearning ya exporta cada página del árbol de navegación
como un SCO independiente** (`<resource adlcp:scormtype="sco" href="html/page-N.html">`).
Para el sample mínimo, 6 páginas ⇒ 6 SCOs. Esto desbloquea **multi-grade-items a
nivel de página sin requerir ningún cambio aguas arriba**, abriendo un Plan B
realista para DEC-0-03.

## Hechos citados

Manifest extraído (EXP-001):

```xml
<organizations default="eXe-20251217061325EKESBR">
  <organization identifier="eXe-20251217061325EKESBR" structure="hierarchical">
    <title>Really Simple Test Project</title>
    <item identifier="ITEM-…" identifierref="RES-20251217061325YPVNGE"><title>Page 1</title>
      <item …><title>Page 1 - 1</title>
        <item …><title>Page 1 - 1 -1</title></item>
      </item>
      <item …><title>Page 1 - 2</title></item>
    </item>
    <item …><title>Page 2</title>
      <item …><title>Page 2 - 1</title></item>
    </item>
  </organization>
</organizations>
<resources>
  <resource identifier="RES-20251217061325YPVNGE"
            type="webcontent" adlcp:scormtype="sco" href="index.html"> … </resource>
  <resource identifier="RES-202512170617021528Y4"
            type="webcontent" adlcp:scormtype="sco" href="html/page-1-1.html"> … </resource>
  …
  <resource identifier="COMMON_FILES"
            type="webcontent" adlcp:scormtype="asset"> … </resource>
</resources>
```

Y la emisión de score en `libs/common.js` (REPO-005) usa pipwerks SCORM:

```js
pipwerks.SCORM.set("cmi.core.score.raw", newFinalScore);
pipwerks.SCORM.set("cmi.core.lesson_status",
    isPassed ? "passed" : "failed");
```

→ El `score.raw` viaja **una vez por SCO**, calculado client-side como agregado
de los iDevices calificables presentes en esa página.

## [INTERPRETACION]

- A nivel de protocolo, cada SCO de SCORM 1.2 puede llevar su propio `cmi.core.score.raw`
  hacia el LMS. Moodle, en `mod_scorm`/`mod_exescorm`, **colapsa todos los scores en un
  único `grade_item`** vía `grademethod` (max / avg / sum / nº de SCOs). Pero el bridge
  hacia gradebook es ajustable: si en lugar de agregar emitiéramos
  `grade_update('mod/exelearning', …, itemnumber=$indice_sco, …)`, obtendríamos
  exactamente "1 grade item por SCO = 1 grade item por página".
- **No es un hack**: es el patrón `mod_workshop` (AN-002) aplicado al routing
  `SCO identifier → itemnumber` con la tabla `mdl_exelearning_grade_item` propuesta
  en la arquitectura.

## [HIPOTESIS]

- "Página = unidad calificable" puede ser el sweet spot pedagógico: el profesor diseña
  un capítulo por nota, no se ahoga gestionando 30 grade items.
- Mantener iDevice-level grading como objetivo de v2, vía xAPI o `cmi.objectives.{n}`
  (PREG-002).

## Consecuencias para `mod_exelearning`

- **Plan B viable hoy**: consumir el SCORM 1.2 export de eXeLearning, parsear
  `imsmanifest.xml`, registrar N `grade_item` (uno por `<item>` con
  `adlcp:scormtype="sco"`).
- Reaprovechar `libs/SCORM_API_wrapper.js` + `libs/SCOFunctions.js` (pipwerks) como
  canal, recibiendo `LMSSetValue` por SCO. Endpoint Moodle: heredarlo de `mod_scorm`
  conceptualmente o implementar uno propio simplificado.
- La sidebar nativa de eXeLearning se preserva como en AN-001 (sigue siendo HTML+JS
  del paquete).

Esto redibuja DEC-0-03:

- **Plan A (xAPI)**: granularidad por iDevice, requiere cambios upstream o shim JS.
- **Plan B (SCORM 1.2 con SCO-as-grade-item)**: granularidad por página, cero cambios
  upstream. **Recomendable como v1**.
- **Plan C (SCORM 2004 con objectives)**: granularidad por iDevice, requiere upstream.

## [PENDIENTE]

- Confirmar comportamiento del export SCORM 2004 (`scorm2004-exporter.spec.ts`):
  ¿también un SCO por página, o uno único?
- Verificar si eXeLearning expone una opción `singleSco=true` que destruya la
  hipótesis (`pp_singleSco` o similar en `pp_*` properties).
- Diseñar el bridge JS Moodle ↔ pipwerks (qué se cambia respecto a `mod_exescorm`).
