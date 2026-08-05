# fixtures/

Paquetes **eXeLearning v4** reales para pruebas reproducibles del plugin
`mod_exelearning`. Todos los archivos provienen del repositorio oficial
[`github.com/exelearning/exelearning`](https://github.com/exelearning/exelearning)
(rama de la **v4**) — son las mismas fixtures que el propio proyecto utiliza en
sus tests de integración. Se replican aquí para que los experimentos de
`mod_exelearning` sean ejecutables sin necesidad de clonar eXeLearning.

> **Política de formato**: `mod_exelearning` trabaja **únicamente con archivos
> `.elpx` (eXeLearning v4)** y con sus exports derivados (Web, SCORM 1.2,
> SCORM 2004). Los archivos `.elp` (legacy v2) y la rama `iteexe_online` **no**
> son objetivo del plugin y han sido deliberadamente excluidos de esta carpeta.

> **Política de actualización**: cuando se actualice eXeLearning, regenerar estas
> fixtures desde el upstream y registrar la fecha en este README. No editarlas a
> mano.

> **[RETIRADA 2026-06-17]** Para reducir peso (regla «no commitear binarios pesados») se eliminaron del repo
> ~13 MB de fixtures **sin referencia** desde `tests/`/`scripts/`/`blueprint.json`: `arrows.elpx`,
> `really-simple-test-project.elpx`, `contenido-prueba-estilos-cata.elpx`, `actividad-evaluable_2/_3/4.elpx`,
> el ZIP **duplicado** `scorm-export/actividad-evaluable_scorm12.zip` (md5 idéntico a
> `scorm/actividad-evaluable_scorm.zip`) y los árboles **extraídos** `web-export/really-simple_web/` y
> `scorm-export/really-simple_scorm12/`. Las entradas de abajo se conservan como **documentación/evidencia**
> (conteos esperados de DEC-13-10, análisis SCO de AN-005); los binarios se **regeneran** con la sección
> «Cómo regenerar las fixtures». **Se conservan en disco** (en uso): `actividad-evaluable.elpx` (tests),
> `multipage-gradable.elpx` (tests), `superelpx.elpx` (`lib_test.php`), `scorm/actividad-evaluable_scorm.zip`
> y `h5p/question-set-demo.h5p` (blueprint).

## Origen upstream

[`https://github.com/exelearning/exelearning`](https://github.com/exelearning/exelearning),
rama v4. Clon local canónico: `/Users/ernesto/Downloads/git/exelearning/`.
Subruta de fixtures: `test/fixtures/` y `test/fixtures/export/`.

Fecha de la copia: **2026-05-28**.

## Contenido

### `elpx/` — proyectos editables formato v3 (zip de `content.xml` + recursos)

| Archivo | Tamaño | Notas |
|---|---|---|
| `arrows.elpx` | 455 KB | Sample minimal con jerarquía. |
| `really-simple-test-project.elpx` | 1.2 MB | Sample canónico de pruebas: 6 páginas en árbol, solo iDevices `text`. |
| `contenido-prueba-estilos-cata.elpx` | 2.1 MB | Sample real de mayor tamaño: 56 páginas, 7 tipos de iDevice (`text`, `udl-content`, `scrambled-list`, `rubric`, `interactive-video`, `form`, `download-source-file`). Provisto por el usuario el 2026-05-18. Bueno para probar estilos, catalogación, paginación, search box y `scrambled-list` (potencialmente calificable). |
| `actividad-evaluable.elpx` | 633 KB | **Sample canónico para EXP-002 (multi-grade-items)**: 1 página "Test 1" con **2 iDevices calificables** (`trueorfalse` + `guess`). IDs estables observados: `idevice-1779989968114-sevb8qqdy` y `idevice-1779990014981-upsl0qps2`. Generado por el usuario con eXeLearning v4 el 2026-05-28 — fuente directa para validar el flujo "1 paquete → 2 grade items". |
| `actividad-evaluable_2.elpx` | 632 KB | **Fixture del bug "DataGame cifrado" (DEC-13-10)**: `trueorfalse` (config en texto plano → detectado siempre) + `discover` (config cifrada en `*-DataGame` → **antes ignorado, ahora detectado**). Esperado: **2/2** calificables. Provisto por el usuario 2026-06-08. |
| `actividad-evaluable_3.elpx` | 952 KB | Igual familia: `trueorfalse`, `guess`, `form` (plano) + `discover`, `identify`, `classify` (cifrados) + 2 `text` no calificables. Esperado: **6/8** (antes 3/8). |
| `actividad-evaluable4.elpx` | 722 KB | 2×`trueorfalse` + 2×`guess` (plano) + 2×`discover` (cifrado). Esperado: **6/6** (antes 4/6). |

#### `superelpx.elpx` — paquete exhaustivo del bug 12/30 (issue #13, DEC-13-10)

`superelpx.elpx` (≈16 MB; es un **export web completo** con `html/`+`idevices/`+
`libs/`, no solo el `content.xml` — se conserva entero a petición del usuario,
aunque el plugin **solo lee `content.xml`**). Contiene **30 iDevices, uno de cada
tipo**, casi todos marcados como calificables (`isScorm:1`).

- **Antes del fix**: el plugin detectaba **12/30** (map, form, interactive-video,
  trueorfalse, trivial, beforeafter, dragdrop, flipcards, relate, scrambled-list,
  mathematicaloperations, periodic-table) — los que guardan `isScorm` en texto
  plano (`jsonProperties` o `htmlView`).
- **Después del fix (DEC-13-10)**: detecta **28/30**. Los 16 nuevos guardaban su
  config — incluido `isScorm` — **cifrada** en el div oculto `*-DataGame`
  (`escape()` + XOR 146; ver `libs/common.js::decrypt`). Los 2 que siguen fuera
  (`puzzle`, `hidden-image`) tienen `isScorm:0` real → correctamente excluidos.

Reproducción rápida del conteo (sin Moodle):

```bash
# Descifra cada DataGame (unescape + XOR 146) y cuenta isScorm>0 sobre content.xml.
# Es la misma lógica que classes/local/package.php::extract_isscorm_datagame().
```

`libs/common.js::decrypt` (cita upstream): `unescape(str)` y luego
`String.fromCharCode(146 ^ str.charCodeAt(pos))` por carácter.

### `web-export/really-simple_web/` — export "sitio web estático" del proyecto anterior

Estructura completa (~2.8 MB): `index.html` + `html/<slug>.html` + `idevices/`
+ `libs/` (incluye `common.js`, `jquery`, `bootstrap`) + `theme/`. **NO** incluye
`SCORM_API_wrapper.js`. Esta es la entrada que consumiría un equivalente directo
de `mod_exeweb`.

### `scorm-export/really-simple_scorm12/` — export SCORM 1.2 del mismo proyecto

Estructura ~3.0 MB:

- `imsmanifest.xml` — **manifest SCORM con jerarquía de SCOs** (ver más abajo).
- `index.html` (entry) + páginas en `html/`.
- `libs/SCORM_API_wrapper.js`, `libs/SCOFunctions.js`, `libs/common.js` con
  pipwerks SCORM.
- `content.xml` — manifest propietario eXeLearning incluido como recurso.
- Schemas SCORM 1.2 incluidos (`adlcp_rootv1p2.xsd`, `imscp_rootv1p1p2.xsd`,
  `lom.xsd`, etc.).

#### Lo crítico del `imsmanifest.xml` (citado literal)

Cada **página** del árbol de eXeLearning se exporta como un **SCO independiente**:

```xml
<resource identifier="RES-20251217061325YPVNGE"
          type="webcontent" adlcp:scormtype="sco" href="index.html"> … </resource>
<resource identifier="RES-202512170617021528Y4"
          type="webcontent" adlcp:scormtype="sco" href="html/page-1-1.html"> … </resource>
<resource identifier="RES-202512170617262192HA"
          type="webcontent" adlcp:scormtype="sco" href="html/page-1-1-1.html"> … </resource>
… (6 SCOs en total para 6 páginas)
<resource identifier="COMMON_FILES" type="webcontent"
          adlcp:scormtype="asset"> … (jquery, scorm wrapper, css, …) </resource>
```

→ **Implicación**: `mod_exelearning` (vía bridge SCORM o leyendo el manifest)
puede registrar **1 grade item por SCO = 1 grade item por página**. Multi-grade
queda resuelto al nivel de granularidad de página **sin cambios upstream**.
La granularidad por iDevice individual sigue requiriendo cambio aguas arriba
(PREG-002). Ver AN-005.

## Cómo regenerar las fixtures desde upstream

```bash
# Desde el clon de eXeLearning (cualquiera de los dos)
EXE=/Users/ernesto/Downloads/git/exelearning
DST=$(git rev-parse --show-toplevel)/research/fixtures

cp "$EXE/test/fixtures/really-simple-test-project.elpx" "$DST/elpx/"
cp "$EXE/test/fixtures/arrows.elpx"                     "$DST/elpx/"

cp -R "$EXE/test/fixtures/export/really-simple/really-simple-test-project_scorm" \
      "$DST/scorm-export/really-simple_scorm12"
cp -R "$EXE/test/fixtures/export/really-simple/really-simple-test-project_web" \
      "$DST/web-export/really-simple_web"
```

## Lo que NO está aquí (porque pesa demasiado)

- `Manual de eXeLearning 3.0.elpx` (28 MB) — paquete con 40+ tipos de iDevice
  (incluyendo cuestionarios). Para pruebas de iDevices calificables descargar de
  upstream:
  `/Users/ernesto/Downloads/git/exelearning/test/fixtures/Manual de eXeLearning 3.0.elpx`.
- `todos-los-idevices.elp` / `_dos_informes.elpx` (30 MB cada uno) — sample
  exhaustivo.
- Exports de SCORM 2004 (no copiados todavía; ver TAREA futura).

## Licencia

Los archivos provienen del proyecto eXeLearning ([GitHub](https://github.com/exelearning))
publicado bajo GPL-2.0-or-later. Su redistribución aquí mantiene la misma licencia.
