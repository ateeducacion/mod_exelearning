---
id: DEC-124-01
title: "Republicar desde el historial en todo cambio de configuración de nota, incluido el interruptor 'Calificable'"
status: Accepted
date: 2026-08-21
tracking_issue: 124
deciders:
  - erseco
  - claude-code
sources:
  - REPO-004
related:
  adrs: [DEC-13-07, DEC-12-01, DEC-25-01, DEC-34-01, DEC-0-07]
ai_assistance:
  tool: claude-code
  model: claude-opus-5
---

# DEC-124-01: Republicar desde el historial en todo cambio de configuración de nota, incluido el interruptor 'Calificable'

## Contexto

`exelearning_update_instance()` distingue desde [[DEC-34-01]] (B2) dos clases de edición:

- **cambio de configuración de nota** — los intentos guardados siguen siendo válidos, pero
  `exelearning_sync_grade_items()` borra y recrea las columnas del libro, así que las notas
  publicadas desaparecerían o quedarían agregadas con el método antiguo. Se **republican**
  desde `exelearning_attempt` llamando a `exelearning_update_grades($data, 0)`;
- **cambio de contenido** (re-subida del paquete) — deliberadamente **no** se recalcula, para
  conservar la semántica *snapshot-and-warn* de [[DEC-12-01]]: la puntuación se calcula en el
  cliente y el servidor no puede re-derivar un intento pasado contra el contenido nuevo.

La condición que separaba ambos casos enumeraba dos campos: `grademodel` y `grademethod`.

## Problema

`gradeenabled` es un cambio de configuración de nota y no estaba en esa lista. [[DEC-13-07]]
conserva `exelearning_attempt` al apagar el interruptor precisamente para que *«reactivar
`gradeenabled` re-detecta y recalcula desde el historial»*, y `grade_item_manager::remove_all()`
lo repite en su propia documentación. Sólo se cumplía la mitad: `grade_sync::sync()` re-detecta
y vuelve a crear las columnas, pero **vacías**, y nada las repoblaba.

Medido, con un intento calificado ya publicado:

```
STEP 1 calificable=1 : overall='80.00000'  intentos=2
STEP 2 calificable=0 : overall='NO ITEM'   intentos=2
STEP 3 calificable=1 : overall='NULL'      intentos=2
STEP 4 tras un exelearning_update_grades() explícito: overall='80.00000'
```

El profesor que apaga y vuelve a encender el interruptor se encuentra la columna vacía para
alumnado que ya estaba calificado. El dato nunca se perdió —el paso 4 lo demuestra— pero
recuperarlo exigía una acción que la interfaz no ofrece.

## Decisión

La condición deja de enumerar campos concretos como excepciones y pasa a cubrir **cualquier
cambio de configuración de nota**, `gradeenabled` incluido:

```php
$newgradeenabled = (int) ($data->gradeenabled ?? $oldrow->gradeenabled);
if (
    (int) $data->grademodel !== (int) $oldrow->grademodel
    || (int) $data->grademethod !== (int) $oldrow->grademethod
    || $newgradeenabled !== (int) $oldrow->gradeenabled
) {
    exelearning_update_grades($data, 0);
}
```

Dos detalles del diseño, ambos deliberados:

- **El sentido «off» es un no-op seguro y no necesita condición propia.**
  `grade_sync::update_grades()` ya retorna de inmediato con `gradeenabled` vacío, así que al
  apagar no se publica nada en las columnas que `sync()` acaba de borrar. Añadir aquí una
  comprobación de dirección sería código muerto.
- **Cuando el llamador omite el campo se cae al valor almacenado**, no a una constante. A
  diferencia de `grademodel`/`grademethod`, que tienen un valor por defecto seguro más arriba
  en la función, aquí suponer «calificable» dejaría que una actualización programática
  encendiera la calificación en silencio. Con el respaldo, omitir el campo significa *sin
  cambio*, que es lo correcto.

`gradeenabled` se añade también al `SELECT` de `$oldrow`, que sólo traía `revision`,
`grademodel` y `grademethod`.

## Consecuencias

- [[DEC-13-07]] pasa a cumplirse entera: apagar conserva el historial **y** encender lo
  recupera. Su promesa deja de ser aspiracional sin necesidad de reescribir el ADR, que
  permanece `Accepted` e intacto.
- El interruptor es de verdad reversible desde la interfaz, sin intervención de un
  administrador ni recálculo manual.
- [[DEC-12-01]] no se ve afectada: la re-subida de paquete sigue sin recalcularse, y sigue
  avisando mediante `exelearning_warn_if_grades_stale()`.
- La agregación republicada respeta `grademethod` y `grademodel` ([[DEC-0-07]], [[DEC-25-01]]),
  porque reutiliza la misma tubería que los otros dos casos.

## Validación

`tests/grades_test.php::test_gradeenabled_toggled_back_on_republishes_from_history` recorre el
ciclo completo con un intento real ingerido por `track::ingest()`: califica, apaga (comprueba
que la columna desaparece y los intentos no), enciende y exige el 80 de vuelta. Verificado en
rojo antes del arreglo —`Failed asserting that null matches expected 80.0`— y en verde después.
Suite completa: 327 tests, 1187 aserciones, sin fallos.
