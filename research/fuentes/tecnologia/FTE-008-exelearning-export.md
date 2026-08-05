---
id: FTE-008
titulo: "Formato de paquete publicado por eXeLearning"
categoria: formato-paquete
version_consultada: "ELPX v4 (eXeLearning v4, github.com/exelearning/exelearning)"
enlaces_oficiales:
  - https://exelearning.net/
  - https://forum.exelearning.net/
  - https://github.com/exelearning/exelearning/blob/main/doc/elpx-format/content-xml.md
  - https://github.com/exelearning/exelearning/blob/main/doc/elpx-format/ids.md
context7:
  library_id: /exelearning/exelearning
  query: "elpx package format, content.xml structure, odeIdeviceId stable identifiers across import/export, SCORM 1.2 export, how gradable iDevices are published"
  fecha: 2026-05-29
  version_devuelta: "exelearning/exelearning main (doc/elpx-format, llms-full.txt) — High reputation, 1712 snippets"
fecha_consulta: 2026-05-29
relevancia_para_mod_exelearning: "Define el artefacto de entrada del plugin: estructura de archivos, motor JS de la sidebar, identificadores de iDevices calificables."
herramienta_ia:
  interfaz: claude-code
  modelo: claude-opus-4-7
---

## Qué es

> **[ACTUALIZACION 2026-06-17]** **PREG-001** (identificadores estables de iDevice) está **resuelta**
> (DEC-5-01, PR upstream #1791; doc oficial `elpx-format/ids.md`). El `[PENDIENTE: confirmar lista actual]` de
> formatos de más abajo se da por cerrado contra las fixtures reales del repo (`research/fixtures/elpx/`,
> `scorm-export/`, `web-export/`). Esta ficha es la **autoritativa** para la estructura del `.elpx` extraído
> (`content.xml` + `theme/` + `content/`), frente al diagrama web temprano de REPO-005.

Conjunto de archivos producidos por eXeLearning al "exportar" un proyecto `.elp` /
`.elpx` a uno de los formatos publicables: sitio web estático, SCORM 1.2, SCORM 2004,
IMS Common Cartridge, EPUB3, IMS QTI [PENDIENTE: confirmar lista actual].

## Estructura real observada (ELPX v4, Manual de eXeLearning + really-simple)

Evidencia: EXP-001 sobre `Manual de eXeLearning.elpx` (148+ entradas comprimidas como
ZIP `store`). Estructura top-level:

```
package.elpx (ZIP)
├── content.xml              # MANIFEST PROPIETARIO eXeLearning (no SCORM)
├── index.html               # Entry point con sidebar y router
├── content/                 # Recursos del proyecto (imágenes, vídeos, audio)
│   ├── css/                 # CSS base del tema
│   ├── img/
│   └── resources/<id>/      # Carpeta por recurso, ID temporal yyyymmddhhmmss<6 chars>
├── html/                    # Una página HTML por nodo del árbol de navegación
│   ├── <slug-pagina-1>.html
│   ├── <slug-pagina-2>.html
│   └── …
├── idevices/                # Templates de iDevice instanciados en el paquete
│   ├── text/{text.html,text.js,text.css}
│   ├── trueorfalse/…        # SCORM-aware (ver REPO-005)
│   ├── quick-questions-multiple-choice/…
│   ├── dragdrop/…, complete/…, classify/…, relate/…, sort/…
│   └── 40+ iDevices distintos en el manual
├── libs/                    # Librerías JS comunes
│   ├── jquery/, bootstrap/
│   ├── common.js            # Player principal (pipwerks SCORM, navegación)
│   ├── common_i18n.js
│   ├── exe_export.js
│   ├── exe_effects/, exe_highlighter/, exe_lightbox/, exe_magnify/,
│   ├── exe_math/, exe_media/, exe_tooltips/
│   ├── jquery-ui/, mermaid/
│   └── favicon.ico
├── theme/                   # Tema seleccionado (style.js + config.xml)
│   ├── style.js
│   └── config.xml
├── custom/                  # Overrides del autor (puede estar vacío)
└── (en SCORM export adicional)
    └── libs/SCORM_API_wrapper.js  +  libs/SCOFunctions.js  +  imsmanifest.xml
```

## content.xml — manifest propietario

Esquema observado (confirmado en 3 fixtures: `really-simple`, `Manual` y
`contenido-prueba-estilos-cata`):

- `<ode>` raíz.
- `<userPreferences>` — preferencias del autor (tema, …).
- `<odeResources>` — metadatos del proyecto: `odeVersionId`, `odeId`,
  `odeVersionName`, `eXeVersion`, `exe_version` (string de la versión instalada
  que generó el paquete), etc.
- `<odeProperties>` — propiedades del paquete (claves observadas):
  `pp_title`, `pp_subtitle`, `pp_lang`, `pp_author`, `pp_license`, `pp_licenseUrl`,
  `pp_description`, `pp_modified`, `pp_theme`, `pp_globalFont`, `pp_exelearning_version`,
  `pp_addExeLink`, `pp_addPagination`, `pp_addSearchBox`,
  `pp_addAccessibilityToolbar`, `pp_addMathJax`, `pp_extraHeadContent`, `footer`,
  `exportSource`. **No se ha encontrado** una propiedad `pp_singleSco` u
  homólogo que permitiera al autor colapsar el export SCORM en un único SCO; en
  v4 actual el comportamiento "SCO por página" parece ser el único disponible.
- `<odeNavStructures>` — **árbol de navegación (= sidebar)**: cada `<odeNavStructure>`
  con `<odePageId>` (ID estable `yyyymmddhhmmss<6CHARS>`), `<odeParentPageId>`
  (jerarquía), `<pageName>`, `<odeNavStructureOrder>`, `<odeNavStructureProperties>`
  (entre ellas `titlePage`, `titleNode`, `visibility`, `teacherOnly`).
- Por página, los iDevices se materializan con `<odeIdeviceId>` (ID estable) y
  `<odeIdeviceTypeName>` (tipo, p.ej. `trueorfalse`, `dragdrop`, `text`).
  Propiedades por iDevice observadas: `allowToggle`, `cssClass`, `editableInPage`,
  `hidePageTitle`, `highlight`, `minimized`, `teacherOnly`, `visibility`.

→ **Esto resuelve PREG-001 para el formato ELPX**: las IDs de página y de iDevice
son estables y exportadas en `content.xml`. Son el candidato natural para
`mdl_exelearning_grade_item.objectid`.

### Confirmación autoritativa (Context7 `/exelearning/exelearning`, 2026-05-29)

La doc upstream (`doc/elpx-format/content-xml.md` + `llms-full.txt`) confirma y precisa:

- `content.xml` es la raíz de todo `.elpx`: UTF-8, XML 1.0, **namespace
  `http://www.intef.es/xsd/ode`**, versión **ODE `2.0`**, schema en `content.dtd`
  empaquetado en la raíz del ZIP. Orden de elementos top-level:
  `userPreferences?`, `odeResources?`, `odeProperties?`, `odeNavStructures`.
- **Distinguir dos ids de paquete** (no confundir con `odeIdeviceId`):
  - `odeId` — id del proyecto, **estable, nunca se regenera**.
  - `odeVersionId` — **nuevo en cada export**.
  Por eso un re-export del mismo proyecto cambia `odeVersionId` pero conserva
  `odeId` y los `odeIdeviceId` (matiz de RIE-006: los ids de iDevice sólo se
  reasignan al **importar** el .elpx, no al re-exportar).
- Export a SCORM 1.2 (CLI del editor): `bun run cli elp:export in.elpx out.zip --format=scorm12`.
- Excluidos de `odeProperties` (no emitidos): `scormIdentifier`, `masteryScore`
  van al **manifest SCORM**, no a content.xml.

### Novedad v4: `__ELPX_MANIFEST__` (self-rezip)

Detectado en `contenido-prueba-estilos-cata.elpx`:

- `libs/elpx-manifest.js` define `window.__ELPX_MANIFEST__ = {version:1, files:[…]}`
  con la lista completa de archivos del paquete publicado.
- `libs/exe_elpx_download/exe_elpx_download.js` + `libs/fflate/fflate.umd.js`
  (pequeño zip JS) usan ese manifest para **reconstruir el ELPX original desde la
  web publicada** — habilita el botón "descargar original" del iDevice
  `download-source-file`.

Implicación para `mod_exelearning`:

- El plugin no debe servir ciegamente `libs/exe_elpx_download/` si la política del
  centro prohíbe descarga del fuente; controlable por el flag `exportSource` del
  paquete original. Documentar como ajuste de actividad.
- `__ELPX_MANIFEST__` es una fuente alternativa (en JS) para enumerar archivos del
  paquete; útil si en algún momento se decide validar integridad post-extracción.

## iDevices con potencial calificable (lista observada)

Del Manual de eXeLearning (40+ tipos):

- Cuestionarios: `trueorfalse`, `quick-questions`, `quick-questions-multiple-choice`,
  `quick-questions-video`.
- Coincidencias / orden: `relate`, `sort`, `classify`, `scrambled-list`, `complete`,
  `identify`, `discover`, `dragdrop`.
- Juegos: `crossword`, `word-search`, `puzzle`, `trivial`, `az-quiz-game`, `guess`,
  `padlock`, `magnifier`, `hidden-image`.
- Matemáticas: `mathproblems`, `mathematicaloperations`, `periodic-table`.
- Multimedia interactivo: `interactive-video`, `flipcards`, `beforeafter`,
  `image-gallery`, `select-media-files`.
- Otros: `casestudy`, `challenge`, `checklist`, `rubric`, `form`, `map`,
  `external-website`, `geogebra-activity`, `udl-content`.
- Texto/recursos (no calificables): `text`, `download-source-file`.

(Filtrar los efectivamente calificables requiere inspección por iDevice; ver AN-005.)

## Estructura típica (SCORM)

- `imsmanifest.xml` con `<organizations>` jerárquico.
- Recursos referenciados desde `<resource>` por `identifier` y `href`.
- HTML idéntico al export web.

## Identificadores expuestos (confirmado tras EXP-001)

- **Página**: `odePageId`. Dos formatos observados en v4 según la versión del editor
  que generó el paquete:
  - **Formato A (legacy v4)**: `yyyymmddhhmmss<6CHARS>` — p.ej. `20251121091824DNMCSV`.
    Visto en `Manual de eXeLearning.elpx` (extraído de `iteexe_online`).
  - **Formato B (v4 actual)**: **UUID v4** — p.ej. `633bbf80-bcd2-4a7b-a00d-b695d98d69a4`.
    Visto en `actividad-evaluable.elpx` generado por eXeLearning v4 el 2026-05-28.
  Ambos estables, únicos por página, declarados en `content.xml#/ode/odeNavStructures`.
- **iDevice**: `odeIdeviceId`. Dos formatos observados:
  - **Formato A**: `yyyymmddhhmmss<6CHARS>`.
  - **Formato B (v4 actual)**: `idevice-<unix_timestamp_ms>-<random9>` — p.ej.
    `idevice-1779989968114-sevb8qqdy`.
  Estables a través de re-uploads si el autor no recrea el iDevice. `mod_exelearning`
  debe aceptar **ambos** formatos como string opaco.
- **Tipo de iDevice**: `odeIdeviceTypeName` (slug ASCII estable, p.ej. `trueorfalse`,
  `guess`, `quick-questions-multiple-choice`).

[HIPOTESIS pendiente] En SCORM 2004 export, comprobar si `odeIdeviceId` se materializa
como `cmi.objectives.{n}.id` en `imsmanifest.xml` o si se pierde y solo queda
`cmi.core.score.raw` agregado por SCO. Ver REPO-005 § "Integración SCORM observada"
para evidencia de que el export SCORM actual agrega.

## Soporte para multi-grade-items

Depende del export: ver FTE-001/FTE-002/FTE-004 según la decisión que tome DEC-0003.

## Soporte para navegación/sidebar

**Sí, nativa, incluida en el paquete.** Es la razón de ser de la técnica iframe de
`mod_exeweb`.

## Implementaciones de referencia consultadas

- REPO-002 — mod_exeweb (sirve el paquete tal cual).
- REPO-003 — wp-exelearning (proxy + iframe).

## Riesgos / Limitaciones

- Cambios entre versiones de eXeLearning pueden romper la heurística de detección.
- Diferencia entre `.elp` (proyecto editable) y export publicado: documentar
  ambos en EXP-001.

## Preguntas abiertas

- PREG-001: identificadores estables de items calificables en cada formato de export.
- PREG-002: propuesta upstream de un manifiesto `gradeitems.json` adicional.
