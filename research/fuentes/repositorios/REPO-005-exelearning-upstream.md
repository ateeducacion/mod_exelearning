---
id: REPO-005
titulo: "eXeLearning v4 — herramienta de autoría upstream"
tipo: authoring-tool
ruta_local: /Users/ernesto/Downloads/git/exelearning
url_upstream: https://github.com/exelearning/exelearning
commit_consultado: "50178c973 (HEAD clon local 2026-06-17; sha de la consulta original 2026-05-28 no registrado)"
fecha_consulta: 2026-05-28
licencia: "GPL-2.0-or-later [PENDIENTE: confirmar en upstream]"
rol_para_mod_exelearning: "Productor del paquete que mod_exelearning consume. Define la estructura del paquete publicado, los iDevices calificables y el motor JS de la sidebar."
herramienta_ia:
  interfaz: claude-code
  modelo: claude-opus-4-7
---

## Hechos

> **[ACTUALIZACION 2026-06-17]** Ficha de bootstrap (2026-05-28, opus-4-7). Correcciones de staleness:
> - **xAPI / PREG-002 superados:** la afirmación de abajo «la granularidad por iDevice se pierde en el cable
>   SCORM» y PREG-002 (exponer per-iDevice aguas arriba) quedan **superadas**: eXeLearning upstream **ya emite
>   statements xAPI por iDevice** (PR #1867, ver **FTE-011**), y `mod_exelearning` **ya logra
>   multi-grade-items** por iDevice vía el shim SCORM + ruteo por `objectid` estable (**DEC-5-01/DEC-17-01**).
> - **PREG-001 resuelta:** identificadores estables de iDevice confirmados (DEC-5-01, PR upstream #1791; diario 2026-05-29).
> - **Estructura del paquete:** la estructura **verificada contra fixtures reales** del `.elpx` extraído es
>   autoritativa en **FTE-008** (`content.xml` + `theme/` + `content/`); el diagrama web de abajo es del
>   bootstrap y puede no coincidir con el export actual.
> - `commit_consultado` fijado al HEAD del clon local (2026-06-17).

- **Versión objetivo: eXeLearning v4** (no v3/iteexe_online ni v2/iteexe).
- Formato de proyecto **único**: `.elpx` (zip con `content.xml` + recursos).
  El formato `.elp` (v2) **no es objetivo** de `mod_exelearning`.
- Exporta a: sitio web estático, SCORM 1.2, SCORM 2004, IMS Common Cartridge, EPUB3,
  pages (Fluxus), ELPX, "really-simple" (test).
- Los paquetes web ya incluyen el árbol de navegación (sidebar) implementado en JS
  vanilla + jQuery + Bootstrap (`libs/common.js`).
- Stack del proyecto upstream: TypeScript + Bun + Symfony PHP (legacy bridge).
- Repositorio: <https://github.com/exelearning/exelearning>.

## Estructura típica de un paquete publicado (web)

```
package/
├── index.html               # Entry point con sidebar + área de contenido
├── content/                 # Páginas html
├── styles/                  # CSS de tema
├── lib/                     # JS del player (navegación, accordion, etc.)
├── media/                   # Imágenes, vídeos
├── resources/               # Recursos adicionales
└── metadata.xml             # Metadatos del proyecto
```

## Estructura típica de un paquete SCORM publicado

```
package.zip
├── imsmanifest.xml          # Manifest SCORM (organizations, resources)
├── adlcp_rootv1p2.xsd / ims_xml.xsd / ... # Schemas (en SCORM 1.2)
├── index.html               # Entry point referenciado por la organization
├── content/, styles/, lib/, media/, …
```

## iDevices con potencial calificable

Categoría de iDevices que tradicionalmente puntúan en eXeLearning (a verificar contra
versión actual en `[PENDIENTE: TAREA-003 / EXP-001]`):

- Pregunta verdadero/falso
- Pregunta selección múltiple
- Pregunta de selección sencilla (quiz SCORM)
- Actividad de rellenar huecos
- Cloze / dropdown
- SCORM Quiz (agrupador)

## Integración SCORM observada (EXP-001)

Fuentes en el clon local:

- `public/app/common/scorm/SCORM_API_wrapper.js` — wrapper [pipwerks SCORM]
  (estándar de facto, GPL-MIT compatible, sin dependencias).
- `public/app/common/scorm/SCOFunctions.js`.
- `test/fixtures/export/*/<proyecto>_scorm/libs/` — fixtures de export SCORM.

En el paquete extraído (`Manual de eXeLearning.elpx`):

- `libs/common.js` instancia `pipwerks.SCORM` y emite `cmi.core.score.raw`,
  `cmi.core.lesson_status` ("passed"/"failed"), `cmi.suspend_data` (estado
  serializado del nodo).
- Cada iDevice calificable expone su score local mediante variables `mOptions.scorep`
  / `scorerp` (0..10).
- **Crítico**: el `cmi.core.score.raw` que viaja al LMS es el **score agregado por nodo
  (= por página)**, no por iDevice individual. La granularidad por iDevice **existe
  client-side pero se pierde en el cable SCORM**.

Implicación para `mod_exelearning`: para multi-grade-items hace falta aguas arriba
exponer per-iDevice (PREG-002 confirma la necesidad).

Existen dos exportadores SCORM separados:

- `test/integration/export/scorm12-exporter.spec.ts`
- `test/integration/export/scorm2004-exporter.spec.ts`

Cualquier propuesta upstream debería modificar ambos consistentemente, o el de 2004
con `cmi.objectives.{n}` para PREG-002.

## Capacidades respecto a `mod_exelearning`

- Producción de paquetes: sí (web, SCORM 1.2, SCORM 2004, cmi5 [PENDIENTE: confirmar]).
- Identificación estable de items calificables: parcial — los iDevices tienen `id` interno,
  pero no se sabe si se exporta como manifiesto consultable. PREG-001.

## Riesgos / Limitaciones

- Cambios entre versiones de eXeLearning podrían romper la heurística de detección de items.
- El motor JS de la sidebar depende de jQuery (a confirmar).

## Preguntas abiertas

- PREG-001: identificadores estables de iDevices calificables en el paquete publicado.
- PREG-002: posibilidad de aguas arriba publicar un manifiesto adicional declarativo
  (p. ej. `exelearning-gradeitems.json`) con `{id, title, maxscore}` por item calificable.
