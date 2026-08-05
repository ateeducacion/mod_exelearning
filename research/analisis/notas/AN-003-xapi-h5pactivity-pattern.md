---
id: AN-003
titulo: "Patrón xAPI: cómo mod_h5pactivity transforma statements en notas"
fecha: 2026-05-28
fuentes:
  - REPO-004
  - FTE-003
  - FTE-007
relacionados:
  - DEC-0-03
herramienta_ia:
  interfaz: claude-code
  modelo: claude-opus-4-7
---

## Resumen

`mod_h5pactivity` extiende `core_xapi\handler` para recibir statements del contenido
H5P embebido, persistir intentos en `mdl_h5pactivity_attempts` y empujar la nota al
gradebook. Es el modelo a copiar para `mod_exelearning`, con dos diferencias clave:
N grade items en lugar de uno, y un paquete eXeLearning en lugar de H5P.

## Hechos citados

- `public/lib/xapi/classes/handler.php` — clase base `core_xapi\handler` con
  `statement_to_event(statement $s): ?core\event\base`, `supports_group_actors()`
  (REPO-004, FTE-007).
- `public/mod/h5pactivity/classes/xapi/handler.php` — implementación: valida verbos
  (`answered`, `completed`), parsea object id (incluyendo subcontent), enruta a
  `attempt::save_statement()`.
- `public/mod/h5pactivity/classes/local/grader.php` — `grade_item_update` invoca
  `grade_update(..., 0, ...)` (REPO-004).

## [INTERPRETACION]

- Pieza fundamental para `mod_exelearning` adaptando dos puntos:
  1. **Routing por `object.id`**: el handler debe traducir `statement.object.id`
     (IRI estable del iDevice) a un `itemnumber` local. Se consigue con la tabla
     `mdl_exelearning_grade_item` propuesta en AN-002.
  2. **Bucle por item**: en lugar de un único `grade_item_update` final, se invoca
     `grade_update` con el `itemnumber` correspondiente al item afectado por el
     statement.
- Subcontent (H5P) ↔ iDevice (eXeLearning) son conceptualmente equivalentes.

## [HIPOTESIS]

- Si eXeLearning publica statements con `result.score.scaled` (0..1) por iDevice
  calificable, `mod_exelearning` puede calcular la nota multiplicando por `grademax`
  del item.
- Si en una primera fase eXeLearning **no** emite xAPI, hace falta un **shim cliente**
  (`amd/src/exelearning_xapi_bridge.js`) que escuche eventos JS del paquete (clicks,
  validaciones de quiz) y los convierta en statements. Esto va aguas arriba como
  proposición ⇒ PREG-002.

## Consecuencias para `mod_exelearning`

- Crear `classes/xapi/handler.php` que extienda `core_xapi\handler`.
- Crear `classes/local/grader.php` (paralelo al de h5pactivity) que sepa multi-item.
- Crear `classes/local/attempt.php` que persista interacciones por item.

## [PENDIENTE]

- EXP-001: capturar statements xAPI emitidos por un paquete eXeLearning actual
  (si existen) ⇒ confirma o niega la necesidad del shim.
- Diseñar el namespace de Activity IRIs: p. ej.
  `https://exelearning.net/xapi/activity/idevice/<uuid>`.
