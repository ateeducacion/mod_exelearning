---
id: DEC-4-01
title: "Auditoría de seguridad y corrección multi-agente: 21 hallazgos (18 corregidos, 3 diferidos)"
status: Accepted
date: 2026-06-01
tracking_issue: 4
legacy_id: DEC-0016
deciders:
  - erseco
  - claude-code
sources:
  - REPO-005
related:
  adrs: [DEC-0-07, DEC-0-08, DEC-0-09, DEC-0-10, DEC-0-12]
ai_assistance:
  tool: claude-code
  model: claude-opus-4-8
---

# DEC-4-01: Auditoría de seguridad y corrección multi-agente: 21 hallazgos (18 corregidos, 3 diferidos)

## Contexto

Con el plugin ya funcional end-to-end (instala, extrae `.elpx`, sirve con
sidebar, califica vía puente SCORM 1.2 → `track.php` → `grade_update`
multi-itemnumber, editor embebido instalable desde GitHub), se ejecutó una
**auditoría de seguridad y corrección** sobre el código **propio** del plugin.
Quedó fuera de alcance el código vendorado (`editor/`, `exelearning/`, `dist/`,
`assets/scorm/`, wrappers pipwerks/SCORM), conforme a DEC-0-02.

PR de implementación: `ateeducacion/mod_exelearning#4`, rama
`fix/critical-bugs-audit`, commit `43b4735`.

## Metodología

[INTERPRETACION] Orquestación multi-agente (workflow de 135 agentes):

1. **Fan-out** de 12 dimensiones de búsqueda en paralelo (control de acceso /
   capacidades / CSRF, inyección SQL, XSS/escapado de salida, path-traversal /
   zip-slip, SSRF/descarga remota, corrección de calificación, validación de
   parámetros, backup/restore, privacidad/GDPR, JS de cliente, concurrencia /
   consistencia de esquema, lógica de `lib.php`/`view.php`/parser).
2. **Verificación adversarial**: cada hallazgo candidato pasó por **3
   refutadores con lentes distintas** (alcanzabilidad por un actor no
   autorizado / existencia de la protección en otro punto / relectura del
   comportamiento real del código). Sólo sobrevive el hallazgo que ≥2 de 3 no
   logran refutar. Esto descarta los falsos positivos típicos de los buscadores
   LLM.
3. **Crítico de completitud** + dedupe + síntesis priorizada por severidad.

Resultado: **21 hallazgos confirmados** (0 críticos, ~9 altos, ~9 medios, ~3
bajos según síntesis). Se **corrigen 18**; se **difieren 3** con justificación
(uno de ellos, #10, sólo pendiente de regenerar el build AMD con `grunt amd`).

## Hallazgos corregidos (18)

Cada corrección lleva su comentario en inglés en el código fuente citando el
porqué (norma de codificación del repo).

### Seguridad — vía de instalación del editor embebido

- **TLS sin verificar (MITM).** `classes/local/embedded_editor_installer.php`
  creaba ambos clientes `\curl` con `ignoresecurity => true` y nunca fijaba
  `CURLOPT_SSL_VERIFYPEER`; el editor descargado se sirve en un iframe a los
  autores, por lo que era sustituible en tránsito. **Solución:**
  `CURLOPT_SSL_VERIFYPEER=1` + `CURLOPT_SSL_VERIFYHOST=2` en `fetch_releases_feed`
  y `download_to_temp`.
- **Bypass del blocklist SSRF.** Retirar `ignoresecurity` reactiva el
  `curl_security_helper` de Moodle en la petición **y** en los hasta 5 redirects
  seguidos hacia el CDN de releases.
- **Excepción Playground.** En el runtime Moodle Playground (php-wasm) el tráfico
  saliente pasa por un shim de red JS que no puede verificar la cadena TLS, así
  que ahí la verificación estricta fallaría. Se añade `is_playground()` —gate por
  la constante `MOODLE_PLAYGROUND` que define el `config.php` del runtime
  (verificado en `ateeducacion/moodle-playground`,
  `src/runtime/config-template.js`)— y `curl_security_options()`: **estricto en
  servidor real, relajado sólo bajo Playground**. No rompe la instalación del
  editor en Playground sin debilitar producción.
- **Zip-slip.** `extract_to_temp()` llamaba a `ZipArchive::extractTo()` sin
  validar entradas (alcanzable por la descarga de GitHub **y** por una subida
  cruda de admin en `manage_embedded_editor_upload.php`). **Solución:** validar
  cada entrada con `styles_service::is_unsafe_zip_entry()` antes de extraer.

### Calificación / integridad de datos

- **`gradepass` ausente de `db/install.xml`.** Sólo lo añadía `db/upgrade.php`
  (stage 5), así que en **instalaciones nuevas** se descartaba silenciosamente y
  la finalización por aprobado (DEC-0-10) nunca disparaba. Añadido a la tabla
  (hace pasar `grademodel_test::test_gradepass_propagates_to_overall` en BD nueva).
- **Re-extracción del `.elpx` en cada vista (DoS auto-infligido).** El self-heal
  de `view.php`, al estar condicionado a "no hay grade item calificable",
  re-descomprimía y re-parseaba el paquete completo en **cada vista** de
  cualquier paquete de sólo-contenido (0 iDevices calificables). **Solución:**
  marca `gradesyncrev` (columna nueva + upgrade stage 9); cada revisión se
  escanea como mucho una vez; `update_instance` rearma el escaneo al cambiar el
  contenido.
- **Desbordamiento de itemnumber.** `exelearning_sync_grade_items()` asignaba
  itemnumbers sin tope; ahora se limita a `gradeitems::MAX_ITEMNUMBER` (100) para
  no crear columnas que Moodle no puede etiquetar.
- **Puntuaciones fuera de rango / pérdida silenciosa de parseo.** `track.php`
  ahora acota la puntuación a `[grademin, grademax]` / `[0,100]` y emite un aviso
  `debugging()` cuando un `suspend_data` no vacío parsea a cero ítems.
- **Pérdida en backup/restore.** El backup omitía `grademodel`, `maxattempt`,
  `reviewmode`, `teachermodevisible`, `gradepass` (el restore los revertía a los
  defaults de install.xml) y no remapeaba/anotaba `usermodified`. Corregido en
  `backup/restore stepslib`.
- **Rama de parser muerta.** En `classes/local/package.php` la rama `<odePage>`
  no casaba con paquetes v4 reales (0 ocurrencias en las 12 fixtures);
  sustituida por un recorrido en orden de documento sobre `<odeNavStructure>` que
  además recupera el nombre de página. **Detección byte-idéntica** verificada
  sobre las 12 fixtures (simulación fiel del regex).

### Control de acceso / privacidad

- **Fuga en grupos separados (`report.php`).** Un profesor sin
  `moodle/site:accessallgroups` podía ver **y borrar** intentos de alumnos de
  otros grupos. **Solución:** filtrar la consulta, comprobar pertenencia al grupo
  antes de borrar, y mostrar selector de grupo. El borrado + recálculo de nota se
  envuelven ahora en una transacción.
- **Privacy provider.** Declara el enlace al subsistema `core_grades` y el campo
  exportado `maxscore`; y **limpia las notas del libro al borrar datos** (una
  supresión GDPR dejaba antes una nota huérfana sin intentos que la respaldaran).
- **Reset de curso.** Implementados `*_reset_userdata` / `*_reset_gradebook` /
  `*_reset_course_form_definition` / `*_reset_course_form_defaults`: resetear un
  curso ahora purga intentos y notas (antes quedaban intactos, a diferencia de
  SCORM/H5P).

## Hallazgos diferidos (3)

### #10 (listo, pendiente de build) — Guard de origen en el postMessage legacy

`amd/src/editor_modal.js::handleLegacyBridgeMessage()` aceptaba mensajes sin
comprobar `source`/`origin` (podía sobrescribir `session.packageUrl` —luego
`fetch` con credenciales— o forzar un guardado). La corrección es un guard de una
línea idéntico al del handler moderno (`event.source === iframe.contentWindow` +
`editorOrigin`). **Por qué se difiere:** tocar `amd/src/*.js` obliga a regenerar
`amd/build/` con `grunt amd` (norma del repo), y este entorno no tiene
`grunt`/`node_modules` ni árbol Moodle; `moodle-plugin-ci grunt` falla ante
cualquier divergencia build↔fuente. Se aplica en un follow-up reconstruido con
`grunt amd`.



### RIE-007 (nuevo) — `suspend_data` N tratado como itemnumber (mis-ruteo de notas)

`track.php` indexa los resultados por el N del `suspend_data`
(`{N}. "{título}"; Score…`) y usa N **directamente** como `itemnumber` del grade
item. Pero el productor de N es el **`common.js` vendorado** de eXeLearning v4,
donde N = índice DOM entre **todos** los iDevices de la página, mientras que
nuestro `itemnumber` es un contador secuencial **sólo** sobre iDevices
calificables (`exelearning_sync_grade_items`). Coinciden únicamente en el caso
degenerado de paquete de una página con todos los iDevices calificables. En el
modo por defecto (PERITEM), una nota puede caer en la columna equivocada o
descartarse.

**Por qué se difiere:** una corrección correcta exige un mapeo estable por
`objectid` que requiere (a) cambiar el productor vendorado (prohibido por
DEC-0-02) o (b) un rediseño del puente (p. ej. que el shim envíe el `objectid`),
lo que **alteraría el comportamiento de calificación ya verificado**. Es una
decisión de diseño → **merece un ADR propio** (futuro DEC) y verificación e2e en
navegador del N real que emite el editor. No se aplica un parche a ciegas.

### RIE-008 (nuevo) — Sin verificación de integridad del ZIP del editor descargado

`do_install()` sólo valida los bytes mágicos PK y la presencia de `index.html`;
no compara un checksum/firma fijado. **Por qué se difiere:** requiere una fuente
de digest de confianza publicada por el release. Las correcciones de TLS + SSRF
de este ADR cierran el vector de sustitución **en tránsito**; el pinning del
digest queda como defensa en profundidad de seguimiento.

## Validación

- `php -l`: limpio en los 12 ficheros PHP modificados.
- `vendor/bin/phpcs --standard=moodle`: **0/0** en todos los PHP modificados.
- `node --check`: limpio en `amd/src/editor_modal.js` y en el build minificado.
- Parser: detección (objectid, tipo) **idéntica** a la implementación previa
  sobre las 12 fixtures `.elpx`.
- `db/install.xml` bien formado; savepoint de upgrade (`2026060100`) ==
  `version.php`.
- Suite completa PHPUnit/Behat: delegada a CI (moodle-plugin-ci, matriz
  Moodle 4.5/5.0/5.1 × PHP 8.1–8.4 × mariadb/pgsql).

[PENDIENTE: regenerar `amd/build/editor_modal.min.js` con `grunt amd` desde un
árbol Moodle] — el build se sincronizó a mano con el guard equivalente (verifica
con `node --check`) porque no hay `grunt`/`node_modules` en este entorno; el paso
`moodle-plugin-ci grunt` de la CI exigirá regenerarlo.

## Seguimiento

- **RIE-007**: **RESUELTO en DEC-5-01** (mapeo por `objectid` estable capturado en
  cliente + ruteo en `track.php`, con fallback legacy). El análisis reveló que el
  problema es más grave de lo descrito aquí: en paquetes multipágina hay colisión
  y pérdida de datos en cliente, no sólo mis-ruteo.
- **RIE-008**: pinning de checksum/firma del ZIP del editor cuando el release
  publique digests.
- Regenerar `amd/build/` con `grunt amd` para que el fix #10 (postMessage) quede
  en el bundle canónico y la CI de grunt pase.

## Revisión 2026-06-01 — seguimiento #10 cerrado

TAREA-008 / RIE-010 queda mitigada en un follow-up dedicado: el guard de
`event.source`/`event.origin` se centralizó en `amd/src/editor_modal.js` y se
aplica tanto al bridge moderno como al legacy antes de procesar `event.data`.
`amd/build/editor_modal.min.js` y su sourcemap fueron regenerados con Moodle
5.2beta local y `grunt amd --root=public/mod/exelearning`.

El pendiente de esta ADR sobre regenerar `amd/build/` ya no aplica para #10; la
barrera futura pasa a ser la CI de `grunt`, que debe seguir detectando cualquier
divergencia entre fuente y build.
