---
id: DEC-0036
titulo: "Mantener contenttype_exelearning como plugin separado de mod_exelearning (no fusionar)"
estado: Aceptada
fecha: 2026-06-08
agentes:
  - erseco
  - claude-code
fuentes:
  - REPO-006
  - FTE-013
relacionados:
  - DEC-0002
  - DEC-0009
herramienta_ia:
  interfaz: claude-code
  modelo: claude-opus-4-8
---

# DEC-0036: Mantener contenttype_exelearning como plugin separado de mod_exelearning (no fusionar)

## Contexto

ATE desarrolla en paralelo dos plugins Moodle del ecosistema eXeLearning:

- **`mod_exelearning`** (este repo): módulo de actividad (`mod/`), ALPHA. Califica iDevices,
  tracking SCORM 1.2, gradebook multi-item. Almacena `.elpx`/`.zip` vía **File API** (no usa el
  banco de contenidos; ver restricciones de AGENTS.md).
- **`contenttype_exelearning`** (REPO-006,
  https://github.com/ateeducacion/moodle-contenttype_exelearning): tipo de contenido del
  **banco de contenidos** (`contentbank/contenttype/`), **STABLE v1.0.0** (2026-06-07).
  Visor/almacén de paquetes eXeLearning, sin calificación.

Se plantea si conviene **integrar `contenttype_exelearning` dentro de `mod_exelearning`**
(mismo plugin/repo) o **mantenerlos como plugins separados**.

## Problema

¿Es viable y deseable fusionar un *content bank content type* dentro de un *activity module*,
o deben distribuirse como plugins independientes? ¿Y debe declararse alguna dependencia entre
ellos?

## Opciones consideradas

1. **A. Mantener plugins separados (elegida).** Dos componentes/repos independientes
   (`mod/exelearning` y `contentbank/contenttype/exelearning`), cada uno con su `version.php`,
   madurez y cadencia de release. Sin dependencia dura entre ellos. Ventajas: idiomático en
   Moodle, responsabilidad única, instalación a la carta, releases desacopladas. Inconveniente:
   solapamiento de código menor (sandbox/extracción `.elpx`) duplicado (RIE-013).
2. **B. Fusionar en un único plugin.** **Inviable arquitectónicamente** (FTE-013): son tipos de
   plugin distintos con rutas de instalación fijas y separadas; un `contenttype` no puede ser
   subplugin de un módulo; el directorio de plugins de moodle.org exige entradas separadas. No
   hay forma de servir el `contenttype` desde dentro del `mod`. Además acoplaría ciclos de vida
   muy distintos (STABLE vs ALPHA).
3. **C. Monorepo + ZIP-bundle de conveniencia.** Un repo Git con ambos plugins en subdirectorios
   y un ZIP unificado para descomprimir en la raíz de Moodle. Posible **solo para
   distribución**: los componentes siguen siendo separados y el ZIP no es subible como entrada
   única al directorio. Es una variante de A en el plano de empaquetado, no una fusión real.

## Evidencia

- **FTE-013** (docs oficiales Moodle):
  - Frankenstyle = un componente, carpeta fija por tipo (`mod/exelearning` vs
    `contentbank/contenttype/exelearning`):
    https://moodledev.io/general/development/policies/codingstyle/frankenstyle ·
    https://moodledev.io/docs/apis/plugintypes
  - Subplugins restringidos a `mod` / editores HTML / `local` / `admin tools`; un `contenttype`
    **no** puede ser subplugin de un módulo: https://docs.moodle.org/dev/Subplugins
  - Content bank content types se instalan en `contentbank/contenttype/<name>` y se gestionan
    aparte: https://docs.moodle.org/dev/Content_bank_content_types
  - El directorio de plugins exige una entrada por plugin:
    https://moodledev.io/general/community/plugincontribution
  - Cooperación opcional vía `$plugin->dependencies`:
    https://moodledev.io/docs/apis/commonfiles/version.php
  - Precedente H5P: `mod_h5pactivity` + tipo de contenido del banco = componentes separados que
    cooperan: https://docs.moodle.org/en/Interactive_Content_-_H5P_activity
- **REPO-006** (`contenttype_exelearning`): `version.php` con `requires 2024042200` (Moodle 4.4),
  `supported [404,501]`, `MATURITY_STABLE`, v1.0.0 (2026-06-07), **sin `$plugin->dependencies`**.
  README: "es un visor/almacén; no califica iDevices (usa `mod_exelearning` para actividades
  calificables)". Solapamiento de código = *mirroring* de la política sandbox del iframe, sin
  `use mod_exelearning\*` ni clases compartidas.
- **DEC-0002**: política de no vendorizar repos externos dentro de este plugin (coherente con no
  absorber `contenttype_exelearning`).

## Decisión

- **D1. Plugins separados.** `mod_exelearning` y `contenttype_exelearning` permanecen como
  componentes/repos independientes. La fusión (opción B) queda descartada por inviabilidad
  arquitectónica del modelo de plugins de Moodle.
- **D2. Sin dependencia dura.** **No** se declara `$plugin->dependencies` en ningún sentido: son
  complementarios e independientes (el visor funciona sin la actividad y la actividad sin el
  visor). Forzar una dependencia obligaría a instalar uno para usar el otro, sin justificación
  funcional.
- **D3. Opcionales (seguimiento, no comprometidos):** (a) extraer una **librería común** de
  manejo `.elpx` (parser/extracción/sandbox) que ambos consuman, si la duplicación crece; (b)
  ofrecer un **ZIP-bundle de conveniencia** (opción C) para instalación conjunta, subiendo cada
  plugin por separado al directorio.

## Consecuencias

**Positivas**
- Alineado con la arquitectura idiomática de Moodle (frankenstyle, un tipo = una carpeta) y con
  el precedente H5P.
- Releases y madurez desacopladas (STABLE v1.0.0 del visor sin atarse a la ALPHA de la actividad).
- El administrador instala visor, actividad, ambos o ninguno, según necesidad.
- Coherente con DEC-0002 (sin vendoring) y con la frontera de `mod_exelearning` (File API, no
  banco de contenidos — DEC-0009 mantiene el alcance del módulo).

**Negativas / coste**
- Solapamiento de código duplicado entre ambos plugins (extracción/sandbox `.elpx`) ⇒ riesgo de
  deriva (RIE-013), no resuelto por esta decisión (mitigación futura opcional: D3a).

**Cambios que dispara**
- Ninguno en el código de `mod_exelearning`: es una decisión documental. No se toca `version.php`
  ni se añaden dependencias.

## Riesgos

- **RIE-013**: deriva/duplicación de la lógica de extracción y política sandbox `.elpx` entre
  `mod_exelearning` y `contenttype_exelearning`. Severidad baja; mitigación futura opcional =
  librería común compartida (D3a). Registrado en `status.yaml`.

## Validación

- Verificación documental: las reglas de FTE-013 confirman la inviabilidad de la fusión (rutas de
  instalación fijas por tipo, subplugins restringidos, una entrada por plugin en el directorio).
- `python3 tools/build_indexes.py && python3 tools/test_schema_validation.py` ⇒ OK, con
  `DEC-0036`, `REPO-006`, `FTE-013` presentes en los índices.
- No hay validación de código asociada (decisión sin cambios de implementación).

## Seguimiento

- **Abre (opcional):** evaluar una librería común `.elpx` (D3a) si la duplicación de
  extracción/sandbox crece ⇒ revisar RIE-013.
- **Abre (opcional):** valorar un ZIP-bundle de conveniencia (D3b) para instalación conjunta.
- **Cierra:** la pregunta de empaquetado mod vs contenttype para el ecosistema eXeLearning de ATE.
