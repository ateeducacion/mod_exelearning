---
id: AN-002
titulo: "Patrón de múltiples grade items: mod_workshop como modelo"
fecha: 2026-05-28
fuentes:
  - REPO-004
  - FTE-006
relacionados:
  - DEC-0-03
herramienta_ia:
  interfaz: claude-code
  modelo: claude-opus-4-7
---

## Resumen

Moodle soporta nativamente múltiples grade items por instancia de actividad mediante
el parámetro `$itemnumber` de `grade_update`. El ejemplo canónico es `mod_workshop`,
que registra dos: envío (`itemnumber=0`) y evaluación (`itemnumber=1`). Replicar este
patrón en `mod_exelearning` con `N` items es **estándar Moodle**, no requiere ningún
truco.

## Hechos citados

- `public/lib/gradelib.php`: firma de `grade_update(..., $itemnumber, $grades, $itemdetails)`
  (FTE-006, REPO-004).
- `public/mod/workshop/lib.php:~1110-1130`: `workshop_grade_item_update` invoca
  `grade_update` dos veces con `itemnumber=0` y `itemnumber=1`, cada una con su
  `itemname`, `gradetype`, `grademax`, `grademin`.
- Borrado consistente con `$itemdetails = array('deleted' => true)` por cada
  `itemnumber`.

## [INTERPRETACION]

- Para `mod_exelearning` la estrategia natural es:
  - `itemnumber=0` reservado para una nota global agregada (opcional, configurable),
  - `itemnumber=1..N` para cada iDevice calificable detectado en el paquete.
- La asignación `iDevice → itemnumber` debe ser **estable** a través de re-uploads.
  Necesitamos un mapeo persistente (`mdl_exelearning_grade_item(exelearningid,
  itemnumber, objectid, name, maxscore)`).

## [HIPOTESIS]

- El `objectid` puede ser el `id` del iDevice exportado por eXeLearning (PREG-001).
- En un re-upload con items añadidos, se asignan `itemnumber` nuevos manteniendo los
  antiguos. Items removidos se marcan `deleted=true` pero conservan su `itemnumber`
  histórico para no romper la grade history.

## Consecuencias para `mod_exelearning`

- Tabla adicional `mdl_exelearning_grade_item` (o equivalente) requerida.
- `exelearning_grade_item_update($instance, $grades_por_item)`: bucle sobre items
  detectados.
- Configuración en `mod_form.php`: por cada item, el profesor puede sobrescribir
  `itemname`, `grademax`, ocultar/mostrar.

## [PENDIENTE]

- EXP-002 (TAREA-004): POC en Moodle local que registre 2 grade items desde una
  actividad de prueba mínima — verificar que aparecen en el libro de calificaciones.
- DEC-0-04 (futuro): esquema de tablas definitivo.
