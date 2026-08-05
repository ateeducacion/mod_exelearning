---
id: DEC-19-01
titulo: "Selector de categoría de calificación por actividad (gradecat) aplicado a todos los grade items vía set_parent"
estado: Aceptada
fecha: 2026-06-04
tracking_issue: 19
legacy_id: DEC-0034
agentes:
  - erseco
  - claude-code
fuentes:
  - REPO-004
  - FTE-012
relacionados:
  - DEC-0008
  - DEC-13-07
  - DEC-13-09
herramienta_ia:
  interfaz: claude-code
  modelo: claude-opus-4-8
---

# DEC-19-01: Selector de categoría de calificación por actividad (gradecat) aplicado a todos los grade items vía set_parent

## Contexto

La experta en usabilidad del INTEF reporta que **en la sección "Grading" del
formulario del recurso eXe no aparece la opción de "Categoría"** del libro de
calificaciones, presente en cualquier otra actividad de Moodle. Es necesario poder
asociar cada recurso a una categoría de calificación (p.ej. "Unidad 1").

Estado del código (`mod_form.php`): el formulario usa `standard_coursemodule_elements()`
(no la variante de grading) y construye los campos de calificación a mano
(`gradeenabled`, `grademodel`, `grademax`, `grademin`, `gradepass`, `gradedisplaytype`;
DEC-13-09). **No** existe campo `gradecat`, **ni** columna `exelearning.gradecat`
(`db/install.xml`), **ni** se pasa `categoryid` en ninguna de las llamadas a
`grade_update()` de `lib.php`/`track.php`.

Particularidad del plugin: en modo `peritem` (por defecto, DEC-0008) una actividad
registra **varios** grade items (overall oculto `itemnumber=0` + uno por iDevice
calificable), y `exelearning_sync_grade_items()` los crea/actualiza en cada
guardado/`view` (re-subir un paquete puede añadir columnas). La categoría debe sobrevivir
a esas re-sincronizaciones.

## Problema

1. ¿Cómo se añade el selector estándar de categoría sin romper la UI de grading propia?
2. Con multicalificación (N columnas), ¿dónde se colocan los items?
3. ¿Cómo se asigna/mueve de categoría de forma fiable en crear **y** actualizar, dado
   que la sincronización recrea/actualiza items?

## Opciones consideradas

- **A. Categoría plana estándar (elegida).** Un único selector `gradecat` por actividad;
  TODAS las columnas (overall + por-iDevice) se colocan en la categoría elegida, como
  cualquier actividad de Moodle. Simple y predecible.
- **B. Subcategoría por actividad.** La categoría elegida sería el **padre** de una
  subcategoría creada por actividad que agrupa las columnas por-iDevice (su total sería
  la nota consolidada). Más ordenado, pero que un módulo cree/gestione `grade_category`
  es no estándar, complica backup/restore y la edición manual en el gradebook.
- **C. No persistir `gradecat`** y aplicar la categoría sólo al guardar leyendo el valor
  del formulario. Descartada: la sincronización (re-subida, self-heal de `view.php`,
  cambio de `grademodel`) recrea items que quedarían fuera de la categoría.

**Decisión del usuario (2026-06-04): opción A (plana estándar).**

## Decisión

- **D1.** Nueva columna `exelearning.gradecat` (INT, NOT NULL, DEFAULT 0;
  `db/install.xml` + stage `2026060401` en `db/upgrade.php`). `0` = no forzar
  categoría (categoría superior del curso). `version.php` permanece en el centinela
  `9999999999` (DEC-13-08).
- **D2.** Selector estándar en `mod_form.php` (sección `gradingsection`), **reutilizando
  las cadenas del core** `get_string('gradecategoryonmodform', 'grades')` + su ayuda y
  `grade_get_categories_menu($COURSE->id)` → cero strings nuevos (evita la trampa de
  orden alfabético de `lang/en`) y traducción es/ca/eu/gl gratis. Se añade a la lista
  `disabledIf(..., 'gradeenabled', 'notchecked')` (DEC-13-07). `data_preprocessing()`
  precarga el valor real desde la categoría del item overall cuando `gradecat` está sin
  fijar (actividades anteriores a la columna o movidas a mano), para no relocalizarlas al
  re-guardar.
- **D3.** La categoría se aplica con **`grade_item::set_parent($gradecat)`** —no con
  `grade_update`, que ignora `categoryid` (FTE-012, H1)— en el helper
  `exelearning_apply_grade_category()`, llamado al final de
  `exelearning_sync_grade_items()` (cubre overall + cada por-iDevice, también columnas
  nuevas tras re-subida). Sólo actúa si `gradecat > 0` y la categoría difiere.

## Evidencia

- REPO-004 (este repo): `mod_form.php` usa `standard_coursemodule_elements()` y carece de
  `gradecat`; `db/install.xml` tabla `exelearning` sin la columna; `lib.php`
  (`exelearning_sync_grade_items`, `exelearning_grade_item_update`) y `track.php` no pasan
  `categoryid`.
- FTE-012 (H1): `grade_update()` ignora `categoryid` (allowlist en `lib/gradelib.php`);
  `grade_item::set_parent()` es la API canónica (la usa `course/modlib.php`); el menú es
  `grade_get_categories_menu()` y el id de la categoría superior es válido para
  `set_parent`.
- Verificación en Moodle 5.0.7 (Docker, 2026-06-04): tras fijar `gradecat` y sincronizar,
  `grade_item->categoryid` del overall y de cada por-iDevice == categoría elegida
  (`FEATURE#1 all_items_in_category=YES`).

## Consecuencias

**Positivas**
- Paridad con la UX estándar de Moodle; el recurso eXe se organiza en el gradebook como
  cualquier actividad.
- La categoría sobrevive a re-subidas, self-heal y cambios de `grademodel` (se reaplica en
  cada sync).

**Negativas / coste**
- Una columna nueva (`gradecat`) y un helper. `set_parent` dispara regrade (coste menor).
- **Backup/restore entre cursos**: `gradecat` guarda un id de categoría; si no existe en el
  curso destino, `set_parent` es no-op y los items quedan en la categoría superior
  (degradación aceptable; documentado).

## Validación

- PHPUnit `tests/grades_test.php`:
  `test_gradecat_places_all_items_in_category`, `test_gradecat_moves_items_on_update`.
- `phpcs --standard=moodle` limpio en los archivos tocados.
- Comprobación en vivo (Docker) descrita en Evidencia.

## Seguimiento

- Mantener `gradecat` en el backup/restore del plugin (la columna se respalda con la
  fila de instancia; degradación documentada en cross-course).
