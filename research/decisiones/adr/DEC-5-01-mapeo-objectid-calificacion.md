---
id: DEC-5-01
titulo: "Ruteo de calificaciones por objectid estable (mis-ruteo N→itemnumber, RIE-007)"
estado: Aceptada
fecha: 2026-06-01
tracking_issue: 5
legacy_id: DEC-0017
agentes:
  - erseco
  - claude-code
fuentes:
  - REPO-005
  - FTE-006
  - FTE-008
relacionados:
  - DEC-0003
  - DEC-0008
  - DEC-0012
  - DEC-4-01
herramienta_ia:
  interfaz: claude-code
  modelo: claude-opus-4-8
---

# DEC-5-01: Ruteo de calificaciones por objectid estable (mis-ruteo N→itemnumber, RIE-007)

## Contexto

DEC-4-01 confirmó pero **difirió** el hallazgo RIE-007: `track.php` usaba el
entero `N` parseado de `cmi.suspend_data`
(`{N}. "{título}"; Score…%; Weight…%`) **directamente** como el `itemnumber` del
grade item de Moodle. El productor de `N` es el `common.js` **vendorado** de
eXeLearning v4 (fuera de alcance editar, DEC-0002), mientras que nuestro
`itemnumber` lo asigna `exelearning_sync_grade_items()` (`lib.php`) como un
contador **secuencial global sólo sobre iDevices calificables**. DEC-4-01 dejó
explícito que merecía ADR propio y verificación e2e del `N` real.

Este ADR documenta el análisis exhaustivo del productor y del exportador
eXeLearning, **redefine la severidad del problema** (no es sólo mis-ruteo: en
paquetes multipágina hay **colisión y pérdida de datos** en cliente) y decide la
corrección.

## Problema

Análisis del productor vendorado y de los fixtures de exportación:

- **`N` es local a la página.** `public/app/common/common.js:1150-1154`:
  `let $idevices = $('.idevice_node'); … game.ideviceNumber =
  $idevices.index(el) + 1;` — índice DOM entre **todos** los iDevices
  (calificables o no) de la **página HTML actualmente cargada**.
- **La exportación web/SCORM multipágina es un fichero HTML por página** con
  navegación de página completa (`src/shared/export/exporters/Scorm12Exporter.ts`,
  `PageRenderer.ts`: enlaces `<a href="html/page-2.html">`). Verificado en los
  fixtures `research/fixtures/scorm-export/really-simple_scorm12/html/*.html` y
  `research/fixtures/web-export/really-simple_web/html/*.html`.
- **`cmi.suspend_data` persiste entre navegaciones del iframe y está indexado por
  el `N` local de página** (lectura-modificación-escritura en
  `registerActivity`/`parseSuspendData`/`convertToLineFormat`). El `window.API`
  vive en la ventana padre (`view.php`), que no se recarga al navegar el iframe;
  el `cmi` se acumula. **Consecuencia:** dos iDevices calificables en **páginas
  distintas** que comparten el mismo `N` local **colisionan y se sobrescriben** en
  `suspend_data` — corrompiendo tanto la entrada por-iDevice como el propio
  `getFinalScore()` (`cmi.core.score.raw`) del editor. Ningún arreglo **sólo de
  servidor** puede recuperarlo: la corrupción ocurre en cliente antes de llegar a
  `track.php`.
- Coincidencia `N == itemnumber` **sólo** en el caso degenerado de paquete de una
  página con todos los iDevices calificables.

[INTERPRETACION] El productor vendorado es, en rigor, también defectuoso aguas
arriba para su propio SCORM multipágina; no lo corregimos (DEC-0002), pero
podemos hacerlo mejor en el plugin sin tocarlo.

## Opciones consideradas

1. **Reconstrucción sólo en servidor (N→itemnumber desde `content.xml`).**
   El parser conoce el orden documental de todos los iDevices y podría calcular,
   por cada calificable, su índice local de página. **Inviable:** `suspend_data`
   no transporta el `pageid`, así que el servidor no puede desambiguar a qué
   página pertenece un `N` colisionado. No resuelve la colisión.
2. **Emparejamiento por título.** `suspend_data` lleva el título. **Frágil:**
   títulos no únicos, vacíos o con comillas sustituidas por espacio
   (`common.js` hace `title.replace(/"/g, ' ')`); y la colisión en `suspend_data`
   pierde datos antes del servidor. Rechazada.
3. **Puente por `objectid` estable (elegida).** El `id` del `.idevice_node` en el
   HTML exportado **es** el `objectid` (`<odeIdeviceId>` en `content.xml`,
   verificado byte-idéntico en fixtures: `20251217061742582ZHV`), que es justo lo
   que ya almacenamos en `exelearning_grade_item` (UNIQUE `exelearningid,
   objectid`). El shim de `view.php` ya puede leer el `contentDocument` del iframe
   (mismo origen vía `pluginfile.php`, técnica idéntica a
   `exelearning_require_teacher_mode_hider`, `lib.php:709`). El shim resuelve cada
   evento de puntuación a su `objectid` **en el momento en que ocurre** (la página
   que puntúa está cargada), y el servidor rutea por `objectid → itemnumber`. Cero
   cambios en código vendorado, **sin** cambio de esquema.

## Evidencia

- Productor `N` local de página: `exelearning/public/app/common/common.js:1150-1154`
  (`$('.idevice_node').index(el)+1`), formato en `convertToLineFormat` (1182-1194),
  RMW en `registerActivity`/`parseSuspendData` (1156-1199). [REPO-005, FTE-008]
- Exportación un-HTML-por-página + navegación completa:
  `Scorm12Exporter.ts`, `PageRenderer.ts` (`getPageLink`, `<a href>`), y fixtures
  `…/html/page-*.html`. [REPO-005, FTE-008]
- `id` del `.idevice_node` == `<odeIdeviceId>` == `objectid`: comparación
  `web-export/really-simple_web/index.html` vs
  `scorm-export/really-simple_scorm12/content.xml` (`20251217061742582ZHV`).
- Mapeo `objectid↔itemnumber` ya persistido: `db/install.xml`
  (`exelearning_grade_item`, índices UNIQUE) y `lib.php::exelearning_sync_grade_items`.
- Estabilidad del `objectid` aguas arriba: PR `exelearning/exelearning#1791`
  (merge 2026-05-19) preserva `<odeIdeviceId>` verbatim — el mismo resultado que
  cerró RIE-006 en **DEC-0012**. Esto sostiene que rutear por `objectid` es
  estable entre exports.
- Contrato Moodle: los grade items se identifican por `itemnumber`; rutear a un
  `itemnumber` inexistente descarta la nota en silencio (Grade API). [FTE-006]
- Acceso padre→iframe mismo-origen ya en uso: `lib.php:709`
  (`exelearning_require_teacher_mode_hider`).

## Decisión

**Puente por `objectid` (opción 3) con fallback legacy.**

1. **Shim cliente (`view.php`, JS inline — NO es AMD, no requiere `grunt amd`).**
   En cada escritura de `cmi.suspend_data`: parsear el dict, **diferenciar** contra
   el anterior, y para cada clave `N` cambiada cuyo `domMap[N]` resuelva en la
   página cargada (`document.getElementById('exelearningobject').contentDocument
   .querySelectorAll('.idevice_node')` → `{i+1: node.id}`), acumular
   `itemScores[objectid] = {scorepct, weighted, title}`. Sólo se captura el iDevice
   recién puntuado (siempre en la página actual); las entradas obsoletas de otras
   páginas que quedan en el `suspend_data` colisionado **no resuelven** contra el
   DOM actual y se descartan — eso es lo que evita la colisión. Se envía
   `itemscores` en el payload, junto al `suspend_data` para el fallback.
2. **Servidor (`track.php` + nueva clase `\mod_exelearning\local\track`).** Si
   `payload.itemscores` está presente → `track::apply_item_scores()` rutea por
   `objectid → itemnumber` (lookup en `exelearning_grade_item`). Si no →
   `track::apply_legacy_peritem()` (ruteo por `N`, comportamiento previo intacto,
   sólo fiable en single-page). La lógica de parseo y ruteo se extrae a la clase
   para poder testearla sin invocar el endpoint.

## Consecuencias

- Positivas: per-iDevice correcto en paquetes multipágina; colisión resuelta en el
  único punto donde la identidad es recuperable (cliente, en el instante del
  scoring); cero cambios vendorados (DEC-0002) y sin migración de esquema; el
  fallback preserva el comportamiento verificado para single-page.
- Negativas / coste: el shim crece (~50 líneas JS, validado con `node --check`);
  depende del acceso mismo-origen al iframe (ya disponible). Si el
  `contentDocument` no es accesible (timing), no se emite `itemscores` y se usa el
  fallback → sin regresión.
- **Limitación documentada:** la nota **global** (`cmi.core.score.raw`,
  `getFinalScore`) del productor sigue corrupta en colisión multipágina; esta
  corrección arregla el per-iDevice. El recálculo del overall a partir de los
  per-item (disponible con los mismos datos `itemscores`) queda como seguimiento,
  para no alterar el overall single-page ya verificado.

## Riesgos

- **RIE-007 — RESUELTO** por este ADR (per-iDevice). El residuo (overall
  multipágina) se acota arriba.
- RIE-009 (nuevo, bajo): si una versión del editor **anterior a #1791** reasigna
  `odeIdeviceId` al re-exportar, un re-sync podría re-mapear `objectid`; mismo
  supuesto que RIE-006, ya cerrado para editores post-#1791 (DEC-0012).

## Validación

- Paridad PHP↔JS del parser de `suspend_data`: salida **byte-idéntica**
  verificada sobre el mismo input (locale `Puntuación/Peso` y `Score/Weight`,
  decimales, clamp 150%→100, separador `.\t`).
- PHPUnit `tests/track_test.php`: ruteo por objectid al itemnumber correcto;
  **caso de colisión** (dos iDevices, mismo `N`, páginas distintas) ruteado
  distinto por objectid y **perdido** por el legacy (regresión guardada);
  fallback legacy intacto para single-page; objectid desconocido ignorado.
- PHPUnit `tests/lib_test.php`: el fixture multipágina
  `research/fixtures/elpx/multipage-gradable.elpx` parsea a objectids distintos en
  páginas distintas con itemnumbers estables 1 y 2.
- Behat `tests/behat/mod_exelearning.feature`: escenario multipágina que detecta
  las dos columnas distintas y (con `@javascript`) ejercita el puente puntuando en
  dos páginas y comprobando las columnas correctas del libro.
- Suite completa delegada a CI (moodle-plugin-ci, matriz Moodle 4.5/5.0/5.1 × PHP
  8.1–8.4 × mariadb/pgsql).
- e2e en navegador (chrome-devtools / manual): paquete multipágina real, puntuar
  en dos páginas, confirmar que `payload.itemscores` lleva los objectids correctos
  — la confirmación del `N` real que pedía DEC-4-01.

## Seguimiento

- Recálculo opcional del overall (`itemnumber=0`) a partir de `itemscores` para
  cerrar el residuo de colisión multipágina del `cmi.core.score.raw`.
- RIE-008 (DEC-4-01): pinning de checksum/firma del ZIP del editor — independiente.

## Revisión 2026-06-01 (CI Behat)

- Evidencia: PR #8, run GitHub Actions `26767455663`, jobs Moodle 4.5 y 5.2
  fallaron igual: 5 escenarios pasaban y el único `@javascript` abortaba en
  `BeforeStep` antes del primer `Background` (`mod_exelearning.feature:8`) por
  `wait_for_pending_js`: `core/first` seguía pendiente tras 30 s.
- Decisión operativa: retirar el escenario de navegador de Behat CI y sustituirlo
  por un escenario determinista sin `@javascript` que siembra puntuaciones por
  `objectid` usando la misma ruta servidor (`track::apply_item_scores`) y verifica
  el informe. Behat vuelve a bloquear CI; no se usa `continue-on-error`.
- Cobertura vigente: `tests/track_test.php` mantiene la regresión de colisión
  multipágina por `objectid`; `tests/lib_test.php` mantiene la detección de
  objectids distintos; `tests/behat/mod_exelearning.feature` mantiene la
  presentación del informe. La verificación de navegador real queda en la pista
  e2e manual/Playwright indicada arriba.
- Esta revisión supersede la viñeta de validación previa que decía que Behat
  ejercitaba el puente "con `@javascript`": esa cobertura de navegador se retiró
  de Behat CI por inestabilidad del driver JS y se conserva como e2e real fuera
  de la matriz `moodle-plugin-ci`.
