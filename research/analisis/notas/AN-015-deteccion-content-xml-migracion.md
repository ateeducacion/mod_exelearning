---
id: AN-015
titulo: "Detección por contenido en la migración legacy: criterio content.xml"
fecha: 2026-06-22
fuentes:
  - REPO-001
  - REPO-002
  - REPO-004
  - REPO-005
relacionados:
  - AN-004
  - DEC-13-05
  - DEC-13-12
herramienta_ia:
  interfaz: claude-code
  modelo: claude-opus-4-8
---

## Resumen

Decisión explícita del producto (@erseco, 2026-06-22): la migración desde
`mod_exeweb` y `mod_exescorm` hacia `mod_exelearning` migra **únicamente los
paquetes de los que se puede recuperar un `content.xml` (ODE 2.0) de
eXeLearning**. Los proyectos `.elp` legacy (que llevan `contentv3.xml`, no
`content.xml`) y los paquetes SCORM/web "finales" sin fuente eXeLearning **no se
migran**: se detectan y se reportan, y la actividad legacy original se conserva
intacta (la migración nunca borra el origen, así que omitir un paquete no pierde
datos). Esto extiende AN-004 al lado de la migración: el plugin trabaja con la
fuente ODE `content.xml`, y el editor embebido, la sincronización de items
calificables y la validación del paquete dependen de ella.

## Hechos citados (análisis de código)

- **mod_exeweb** (REPO-002): una sola tabla `exeweb`; el paquete se guarda en la
  filearea `package` con `itemid = {exeweb}.revision`, y los ficheros extraídos en
  `content`. La validación de subida exige un fichero que case
  `/^content(v\d+)?\.xml$/` (admite `content.xml` y el legacy `contentv3.xml`).
  No hay campo de tipo de paquete: el origen (local/embedded/exeonline) no se
  persiste. El fichero de arranque se guarda en `entrypath` + `entryname`.
- **mod_exescorm** (REPO-001): fork de `mod_scorm`. El campo `exescormtype`
  distingue `local`, `localsync`, `embedded` (un `.elpx` guardado tal cual en
  `package`, sin parsear como SCORM), `external` y `aiccurl` (URLs remotas, sin
  copia local). El paquete local se extrae a la filearea `content`; el SCO de
  arranque se resuelve desde `imsmanifest.xml` y se guarda en
  `exescorm_scoes.launch`.
- **Exports de eXeLearning v4** (REPO-005): `.elpx`, IMS y export web "con
  fuente" llevan `content.xml` en la raíz; los exports SCORM 1.2/2004 llevan
  `imsmanifest.xml` y **no** `content.xml`; el export web simple lleva
  `index.html` y **no** `content.xml`; el `.elp` legacy lleva `contentv3.xml`. El
  marcador fiable y estable es `content.xml` (raíz) `<ode xmlns=".../ode"
  version="2.0">`.
- **mod_exelearning** (estado previo): el motor de extracción
  (`package_manager::extract_stored()`) ya es agnóstico al tipo — su única guarda
  es un `index.html` en la raíz del contenido extraído. La carencia estaba en la
  **detección de origen**:
  - `exescorm_source` solo consideraba migrable un paquete cuyo nombre acababa en
    `.elpx` o un ZIP SCORM que embebía un `.elpx`; un `.zip` con `content.xml` en
    la raíz se reportaba `nosource`.
  - `exeweb_source` no inspeccionaba el contenido: devolvía `ok` con que
    existiera cualquier fichero en `package`, de modo que un paquete sin fuente
    fallaba en la extracción (`STATUS_ERROR`).

## Regla unificada (única fuente de verdad)

Inspeccionando solo el directorio central del ZIP (sin extraer):

| Forma del paquete | Veredicto | Resolución |
|---|---|---|
| `content.xml` en la **raíz** (`.elpx`, `.zip` con fuente, IMS, export web con fuente) | `ok` (directo) | copiar el paquete completo |
| exactamente **un** `*.elpx` embebido y seguro | `ok` (embebido) | extraer solo esa entrada |
| **más de un** `*.elpx` embebido | `ambiguoussource` | — |
| ninguno: `.elp` legacy (`contentv3.xml`), SCORM/web sin fuente, ZIP corrupto | `nosource` | — |

Precedencia: un `content.xml` en raíz gana sobre el rastreo de `.elpx` embebidos.
Un `.elpx` nativo es un ZIP con `content.xml` en su raíz, así que resuelve a
`ok` directo igual que hoy — **sin regresión** en la migración `.elpx` existente.

## [INTERPRETACION] Por qué se excluyen .elp legacy y SCORM/web sin fuente

- Sin `content.xml` no hay proyecto ODE editable: el editor embebido no podría
  reabrir la actividad y la detección de items calificables (`grade_sync`, que
  parsea `content.xml`) no tendría nada que leer. Migrar tal paquete crearía una
  actividad degradada.
- La migración es **no destructiva** (DEC-13-05/DEC-13-12): la actividad legacy
  permanece operativa. Omitir un paquete no migrable no pierde datos; el
  administrador sigue usando el plugin legacy o reexporta con fuente.
- El `.elp` legacy queda fuera de alcance por decisión de producto (coherente con
  AN-004): no se contempla soporte del formato Python v2/v3.

## Cambios en el código

- Nuevo `classes/local/migration/source/package_probe.php`: clasificador y
  resolución basados en contenido, compartidos por ambos handlers
  (`classify()` + `resolve()`). Reutiliza `zip_utils` para las defensas de
  path-traversal / symlink ya existentes.
- `exescorm_source` y `exeweb_source` delegan en `package_probe`
  (`exescorm` mantiene el atajo `external`/`aiccurl`/`localsync` → `unsupported`;
  `exeweb` mantiene la resolución de `itemid` por revisión y su fallback).
- `lang/en/exelearning.php`: se afinan `migratescormnote` y
  `migratestatus_nosource` para nombrar el criterio `content.xml` (se reutiliza el
  bucket `nosource`; sin estados/constantes nuevos).

## Matriz de tests

| Caso | Origen | Resultado esperado |
|---|---|---|
| `.elpx` nativo | exeweb / exescorm | migrado (directo) |
| `.zip` con `content.xml` en raíz (export web/IMS con fuente) | exeweb / exescorm | migrado (directo) — **caso nuevo** |
| ZIP SCORM con 1 `.elpx` embebido | exescorm | migrado (embebido) |
| ZIP SCORM con >1 `.elpx` | exescorm | `ambiguoussource` |
| SCORM sin fuente (solo `imsmanifest.xml`) | exescorm | `nosource` |
| `.elp` legacy (`contentv3.xml`) | exeweb / exescorm | `nosource` |
| Tipos `external` / `aiccurl` / `localsync` | exescorm | `unsupported` |
| ZIP corrupto | exeweb / exescorm | `nosource` (sin excepción) |

Cubierta por `package_probe_test`, `exeweb_source_test`, `exescorm_source_test`,
`exescorm_source_security_test` y un test extremo a extremo en
`migration_service_test`.

## [PENDIENTE]

- La validación del `.elpx` embebido se basa en su extensión (no se abre el ZIP
  anidado en preflight por coste); la guarda de extracción (`index.html`) actúa de
  red de seguridad. Verificar `content.xml` dentro del `.elpx` anidado quedaría
  para una iteración futura si aparecieran paquetes patológicos.
- Los paquetes SCORM/web sin fuente eXeLearning no se preservan como contenido
  estático. Si en el futuro se quisiera ofrecer esa preservación, sería una
  decisión de producto aparte (no contemplada aquí).
