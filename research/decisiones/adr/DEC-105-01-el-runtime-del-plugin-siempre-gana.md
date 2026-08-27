---
id: DEC-105-01
title: "El runtime del plugin siempre gana: el par SCORM 1.2 vendorado se instala en todo paquete, completo y sin parches"
status: Accepted
date: 2026-08-27
tracking_issue: 105
deciders:
  - erseco
  - claude-code
sources:
  - REPO-005
  - REPO-004
related:
  adrs: [DEC-16-01, DEC-36-01, DEC-13-11, DEC-105-02, DEC-0-02]
ai_assistance:
  tool: claude-code
  model: claude-opus-5
---

# DEC-105-01: El runtime del plugin siempre gana: el par SCORM 1.2 vendorado se instala en todo paquete, completo y sin parches

## Contexto

El plugin fabrica una sesión SCORM 1.2 alrededor de contenido que no es un paquete SCORM:
sirve la exportación web de eXeLearning con su sidebar nativa e inyecta en cada página el
par `libs/SCORM_API_wrapper.js` + `libs/SCOFunctions.js` (DEC-36-01,
`classes/local/scorm/scorm_injector.php`). Ese par es **el que decide las notas**: es quien
escribe `cmi.core.score.raw` y `cmi.suspend_data`, que después leen `track.php` y
`js/scorm_tracker.js`.

Hasta esta decisión, `classes/local/package_manager.php` instalaba cada fichero del par
**sólo si el paquete no lo traía**, y la copia de `assets/scorm/SCOFunctions.js` era un
subconjunto de cuatro capas montado a mano para que `window.exeScorm12.activities` no
existiera. Dos hechos cambian el problema:

1. Desde DEC-16-01 el plugin acepta cualquier ZIP con `content.xml` en la raíz, y una
   exportación SCORM 1.2 de eXeLearning lo cumple: `Scorm12Exporter.ts` añade siempre
   `content.xml` al paquete (`src/shared/export/exporters/Scorm12Exporter.ts`, paso 6b,
   `exelearning/exelearning@a457d71a7`). Un paquete así **trae su propio runtime**, de una
   versión de eXeLearning desconocida, y referencia los dos ficheros con sus propios
   `<script>`.
2. eXeLearning reescribió el runtime SCORM 1.2 (`exelearning/exelearning#2209`,
   ADR-2209-01): cinco capas propias bajo AGPL-3.0-or-later ensambladas por
   `buildScorm12RuntimeFiles()` (`src/shared/export/utils/Scorm12Runtime.ts`) más el
   wrapper pipwerks upstream sin modificar, con un **sello de versión** en la cabecera
   (`eXeLearning-SCORM12-Runtime: <versión>`) y en `exeScorm12.runtimeVersion`. Hay
   exactamente un runtime por versión de eXeLearning.

## Problema

¿Qué runtime califica cuando el paquete trae uno? Con la instalación condicional la
respuesta dependía de la antigüedad del paquete: el plugin leía un `cmi.suspend_data`
escrito por un código que no controlaba ni podía identificar, y el bucle por fichero podía
además dejar **medio runtime** — el wrapper del plugin junto al `SCOFunctions.js` del
paquete, dos ficheros escritos contra versiones distintas del wrapper que nadie prueba.
Y el subconjunto de cuatro capas no coincidía con ninguna release de eXeLearning: había que
montarlo a mano en cada actualización y ya se había quedado un fix por detrás de upstream
(`exelearning/exelearning@59c84359c`, 54 minutos después del commit de vendorado).

## Opciones consideradas

1. **Instalación condicional (estado anterior)**: instalar cada fichero sólo si falta.
   Ventaja: no toca lo que el paquete trae. Inconvenientes: el runtime que califica es una
   incógnita por paquete; posibilidad de medio runtime; la copia propia era un subset sin
   correspondencia con ninguna release.
2. **Rechazar en la subida las exportaciones SCORM** (que traen runtime). Elimina el caso,
   pero es una decisión de producto que recorta lo aceptado en DEC-16-01, y no resuelve el
   subset ni la trazabilidad de la copia.
3. **Instalar siempre el par del plugin, completo y sin parches, borrando el del paquete**
   (elegida). Un solo runtime, identificable, el mismo para todo paquete.

## Evidencia

- Instalación incondicional y "par o nada": `classes/local/package_manager.php`, paso 5
  (commit `b64d3dc`, "always serve the plugin's SCORM runtime, whatever the package
  brought"). Test: `tests/lib_extract_test.php::test_extraction_replaces_a_runtime_the_package_brought_with_it`.
- Un runtime, una vez: `scorm_injector::inject()` elimina los `<script>` del paquete que
  apunten a `SCORM_API_wrapper.js`/`SCOFunctions.js` antes de inyectar los suyos (commit
  `08e65b7`). Test: `tests/local/scorm/scorm_injector_test.php::test_a_package_that_already_loads_the_runtime_does_not_get_a_second_copy`.
  Medido en un Moodle real antes del fix: una exportación SCORM subida servía cada página
  con dos tags de cada fichero.
- Copia íntegra y trazable: `tests/local/scorm/scorm_runtime_test.php` (7 tests) exige las
  cinco capas en el orden de upstream, el sello de versión (distinto de `unknown`), ausencia
  de marcador de parche local, wrapper pipwerks, y que los **sha256** de ambos ficheros
  coincidan con los declarados en `assets/scorm/SOURCE` junto al commit de eXeLearning que
  los produjo (`core-commit`, 40 hex). Verificación externa (auditoría 2026-08-27): el
  ensamblado con `buildScorm12RuntimeFiles()` reproduce byte a byte el digest fijado, tanto
  para `db5a5a6da` (`a13556b1…`) como para `a457d71a7` (`99e7ab8e…`).
- Licencia: `SCOFunctions.js` es código del proyecto eXeLearning bajo AGPL-3.0-or-later
  (cabecera SPDX en cada capa y en el banner ensamblado); el wrapper es pipwerks
  `v1.1.20180906`, byte-idéntico a `pipwerks/scorm-api-wrapper@82e455b` (MIT). El ZIP de
  release ya declara `dist/static` (mismo proyecto, misma licencia) en `thirdpartylibs.xml`
  (`scripts/package.sh`, DEC-67-01 #002); los revisores del directorio de plugins leen ese
  fichero como "código que no está bajo la licencia del plugin".

## Decisión

1. **El par del plugin se instala siempre**, en todo paquete extraído, reemplazando el que
   el paquete traiga. Se instala **completo o no se instala**: nunca medio runtime.
2. **La copia es íntegra**: las cinco capas, sin parches locales, con el sello de versión y
   con `assets/scorm/SOURCE` declarando repositorio, referencia, commit, versión y sha256 de
   los dos ficheros. `scorm_runtime_test.php` hace fallar cualquier deriva. Se retira el
   subconjunto de cuatro capas. La regla de "no vendorar" (DEC-0-02) sigue aplicando a
   `research/`; esta copia es un artefacto de release del propio proyecto, y su procedencia
   es precisamente lo que SOURCE y los tests fijan.
3. **Un runtime, una vez**: el injector elimina los `<script>` del paquete que carguen el
   runtime e inyecta los suyos con la ruta correcta para cada profundidad.
4. **Se declara en `thirdpartylibs.xml`** `assets/scorm/SCOFunctions.js` como código del
   proyecto eXeLearning bajo AGPL-3.0-or-later, con el sello del runtime como versión,
   igual que el ZIP de release declara `dist/static`. No es una librería de terceros, y así
   lo dice su descripción; se declara porque no está bajo la licencia del plugin, que es lo
   que ese fichero enumera. `README.md` y `assets/scorm/readme_moodle.txt` lo explican.
5. **La versión vendorada es, de momento, interina**: `exelearning/exelearning#2209` en
   `a457d71a7` con sello `v0.0.0-alpha` (build de desarrollo de una rama sin fusionar). El
   re-pin definitivo se hace contra la **release etiquetada**: regenerar los dos ficheros
   desde ese build, comprobar que la cabecera dice `eXeLearning-SCORM12-Runtime: vX.Y.Z`, y
   regenerar SOURCE con el tag como `core-ref` y el SHA de release como `core-commit`.
   `scorm_runtime_test.php` no necesita cambios para eso: falla hasta que SOURCE se
   regenere, que es lo buscado.

## Consecuencias

- Positivas: la respuesta a "¿qué runtime calificó esta actividad?" es única y verificable
  (sello + SOURCE + digests); no hay medio runtime ni runtime doble; la actualización es
  copiar dos ficheros de un export y regenerar SOURCE, sin montaje manual; el plugin queda
  preparado para el `cmi.suspend_data` versionado (`exe12/`) que escribe el runtime nuevo,
  que `track.php` y `js/scorm_tracker.js` decodifican por su cabecera manteniendo el parser
  legacy para paquetes anteriores.
- Negativas / coste: una exportación SCORM subida deja de calificar con su propio runtime
  (es la intención); el runtime completo instala el registro de actividades, así que el
  modelo de servido tiene que abrir la sesión como host (DEC-105-02); cada actualización de
  eXeLearning que cambie el runtime exige un re-pin en el plugin y un bump de
  `version.php` en la release que lo lleve.
- Incompatibilidad conocida: la rama `feature/secure-iframe-scorm-bridge` (PR #80) parchea
  `find()` del wrapper pipwerks vendorado. Es incompatible con esta decisión (el digest de
  SOURCE fallaría): ese cambio pertenece a la capa cliente de eXeLearning o al shim del
  bridge, nunca a `assets/scorm/`.

## Riesgos

- Que SOURCE nombre un commit que no produjo los digests (la prueba sólo se hizo a mano en
  la auditoría). Mitigación pendiente en **Seguimiento**: un job de CI que reconstruya
  `SCOFunctions.js` desde `core-commit` y compare digests.
- Que la copia se quede por detrás de la release que produjo el contenido. Mitigación: el
  sello es visible (`head -3 assets/scorm/SCOFunctions.js`) y `scorm_runtime_test.php`
  rechaza `unknown`; el re-pin de release es un paso de la checklist.

## Validación

`make test ARGS=mod/exelearning/tests/local/scorm/scorm_runtime_test.php` (7 tests),
`tests/local/scorm/scorm_injector_test.php`, `tests/lib_extract_test.php`; ensamblado
externo con `buildScorm12RuntimeFiles()` desde el `core-commit` de SOURCE y comparación de
sha256 (reproducido para los dos pins hasta la fecha).

## Seguimiento

- Re-pin contra la release etiquetada de eXeLearning (punto 5 de la decisión) y bump de
  `version.php` en la release del plugin que lo lleve (DEC-111-01).
- Job de CI (en ambos repositorios) que reconstruya el runtime desde `core-commit` y compare
  con SOURCE: es lo único que convierte el pin por digest en procedencia.
- Añadir a `research/fixtures/elpx/` una exportación posterior a la reescritura (las
  actuales son `v0.0.0-nightly-202605281218`, sin `exeScorm12`), para que el camino `exe12/`
  se pruebe de extremo a extremo y no sólo con cadenas literales.
- Si PR #80 se retoma, reubicar su cambio del buscador de API fuera de `assets/scorm/`.
