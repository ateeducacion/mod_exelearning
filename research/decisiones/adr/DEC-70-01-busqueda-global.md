---
id: DEC-70-01
titulo: "Integración con la búsqueda global de Moodle (área de búsqueda + indexado de ficheros)"
estado: Aceptada
fecha: 2026-06-12
tracking_issue: 70
legacy_id: DEC-0053
agentes:
  - erseco
  - claude-code
fuentes:
  - REPO-004
relacionados:
  - DEC-67-01
herramienta_ia:
  interfaz: claude-code
  modelo: claude-opus-4-8
---

# DEC-70-01: Integración con la búsqueda global de Moodle (área de búsqueda + indexado de ficheros)

## Contexto

La auditoría estándar de 2026-06 ([[DEC-67-01]], fuente REPO-004) sitúa a `mod_exelearning`
como #1 global pero lo penaliza frente a `mod_exescorm` en el eje de **densidad de
plataforma**: integración con subsistemas transversales de Moodle que el plugin aún no
toca. La búsqueda global (`core_search`) es uno de esos huecos: `mod_scorm`/`mod_exescorm`
exponen un *search area* y `mod_exelearning` no, de modo que ni la actividad ni el
contenido eXe autorado aparecen en el buscador global de Moodle.

El plugin ya tiene todo lo necesario para indexar sin trabajo extra de fichero: declara las
áreas de fichero `content` y `package` (`exelearning_get_file_areas`, `lib.php`) y extrae el
`.elpx` a `content/{revision}/` durante `exelearning_save_and_extract_package`. El texto del
contenido ya está, por tanto, en el `filearea` `content`; sólo falta declararlo al
subsistema de búsqueda.

## Problema

¿Cómo hacer que tanto la **metadata de la actividad** (intro) como el **contenido eXe
autorado** sean localizables en la búsqueda global de Moodle, con el mínimo de superficie
nueva y sin reimplementar contexto/visibilidad/permisos?

## Opciones consideradas

1. **Área de búsqueda mínima (sólo `intro`)** — `class activity extends
   \core_search\base_activity` sin overrides. La implementación base indexa
   `title`=nombre y `content`=`intro`. Ventaja: trivial, cero riesgo. Inconveniente: el
   contenido eXe (el valor real) **no** se indexa; el buscador sólo encuentra la
   descripción de la actividad.
2. **Área de búsqueda + indexado de ficheros (`intro` + `content`)** [ELEGIDA] — además de
   (1), `uses_file_indexing()` → `true` y `get_search_fileareas()` → `['intro','content']`.
   La base adjunta los ficheros de esas áreas al documento (`attach_files()`), y el motor
   extrae su texto. Ventaja: el contenido eXe pasa a ser localizable reutilizando el
   `filearea` `content` ya poblado; patrón idéntico a `mod_scorm`. Inconveniente: marginal
   (coste de indexado de ficheros, ya asumido por cualquier actividad con ficheros).
3. **`get_document()` a medida (campo de contenido propio)** — como hace `mod_page`, parsear
   el `content.xml` y volcar el texto en `content`. Ventaja: control fino del texto
   indexado. Inconveniente: duplica el parser (`local\package`), añade superficie y acopla
   el área de búsqueda al formato eXe; el indexado de ficheros (opción 2) ya cubre el caso
   sin ese coste. Descartada por sobre-ingeniería.

### Opciones adyacentes consideradas y **DIFERIDAS**

- **`classes/dates.php` (`core\activity_dates`)** — expondría fechas de disponibilidad en
  la tarjeta del curso y la línea de tiempo. **No aplica hoy**: `mod_form.php` no expone
  ninguna ventana `timeopen`/`timeclose`. Diferida hasta que existan fechas de
  disponibilidad (requeriría columnas nuevas + formulario, fuera del alcance de búsqueda).
- **`classes/analytics/indicator/*`** — indicadores para modelos de analítica de
  aprendizaje. Prioridad baja: sólo aportan valor con modelos de analítica activos, que la
  mayoría de despliegues no usa. Diferida.

## Evidencia

- REPO-004 (`moodle-core`): el subsistema `core_search` y la clase
  `\core_search\base_activity` (`search/classes/base_activity.php`) resuelven contexto,
  `check_access()`/`uservisible`, `get_document_recordset()` y `attach_files()`; el
  `get_document()` por defecto mapea nombre→`title` e `intro`→`content`. `mod_scorm`
  (`mod/scorm/classes/search/activity.php`, REPO-004) es exactamente el patrón
  reutilizado: `uses_file_indexing()` + `get_search_fileareas()` → `['intro','content']`.
- REPO-001 (`mod-exescorm`): hereda de `mod_scorm` el mismo área de búsqueda; es la pieza
  que la auditoría señala que `mod_exelearning` no tenía.
- REPO local (este plugin): `exelearning_get_file_areas()` declara `content`; el `.elpx` se
  extrae a `content/{revision}/` en `exelearning_save_and_extract_package` (`lib.php`), así
  que el texto del contenido ya reside en el `filearea` indexable — no hace falta tocar
  almacenamiento.

## Decisión

Implementar la **opción 2**: un único fichero nuevo
`classes/search/activity.php` que extiende `\core_search\base_activity`, activa el indexado
de ficheros y declara las áreas `['intro','content']`. Sin overrides de
contexto/visibilidad/`get_document` (la base basta). String de área de búsqueda
`search:activity` en `lang/en` + `lang/es`. El área se auto-descubre; el admin la habilita
en el motor de búsqueda global y reindexar (`php admin/cli/search.php`) hace localizable el
contenido eXe.

## Consecuencias

- **Positivas:** cierra el hueco de densidad de plataforma frente a `mod_exescorm` con
  superficie 100 % aditiva (ficheros nuevos, sin tocar `lib.php`); el contenido eXe pasa a
  ser localizable en el buscador global; el contexto/visibilidad/permisos se delegan a la
  base, sin lógica de seguridad propia que mantener.
- **Negativas / coste:** el indexado de ficheros del `filearea` `content` añade un coste de
  CPU/IO en la reindexación proporcional al tamaño del paquete; es el mismo coste que
  cualquier actividad con ficheros y sólo se paga al reindexar.
- **Cambios que dispara:** ninguno en otros ADRs. `docs/ARCHITECTURE.md` añade la capa
  «Global search». Las opciones `dates`/`analytics` quedan registradas como diferidas.

## Riesgos

- RIE-017: **Indexado de contenido no visible o de fuga de información.** Si el área
  indexara ficheros que un usuario no debería ver, el buscador podría filtrar fragmentos.
  Severidad baja: la base `base_activity::check_access()` reverifica `uservisible` por
  módulo antes de devolver resultados, y sólo se indexan los `filearea` `intro`/`content`
  servidos ya bajo `require_capability('mod/exelearning:view')` en `exelearning_pluginfile`.
  Mitigación: no se sobrescribe `check_access()`; tests de `check_access` (admin/alumno,
  visible/oculto/borrado) garantizan que la visibilidad se respeta. Estado: mitigado.

## Validación

- `tests/search/search_test.php` (`final class search_test extends \advanced_testcase`): el área
  se auto-descubre y togglea (`is_enabled`); el recordset produce un documento por actividad
  y respeta timestamp y contexto (módulo y curso); el documento lleva
  `title`/`content`(intro)/`contextid`/`courseid`; `uses_file_indexing()` es `true` y
  `get_search_fileareas()` = `['intro','content']`, con ficheros adjuntos desde el paquete
  extraído; `check_access` concede a admin/alumno en actividad visible, deniega al alumno en
  oculta y reporta borrado para instancia inexistente. Espejo de `mod/book/tests/search`.
- `phpcs --standard=moodle` 0/0 en los ficheros nuevos. PHPUnit no corre en local
  (memoria `phpunit_local`); la suite la valida CI con gate Codecov ([[DEC-66-01]]).
- Verificación funcional: reindexar (`php admin/cli/search.php`) → el contenido eXe aparece
  en los resultados del buscador global.

## Seguimiento

- Cierra el hueco de búsqueda global del eje de densidad de plataforma ([[DEC-67-01]]).
- Abre como diferidas, sin tarea planificada: `core\activity_dates` (cuando existan ventanas
  de disponibilidad) y los indicadores de analítica.
