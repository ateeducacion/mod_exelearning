---
id: DEC-124-02
title: "El interruptor 'Calificable' silencia la publicación de nota, no la ingesta"
status: Accepted
date: 2026-08-21
tracking_issue: 124
deciders:
  - erseco
  - claude-code
sources:
  - REPO-004
related:
  adrs: [DEC-13-07, DEC-69-01, DEC-25-01, DEC-5-01, DEC-124-01, DEC-85-01]
ai_assistance:
  tool: claude-code
  model: claude-opus-5
---

# DEC-124-02: El interruptor 'Calificable' silencia la publicación de nota, no la ingesta

## Contexto

`track::ingest()` nunca consultó `gradeenabled`. Con el interruptor apagado
([[DEC-13-07]]) la instancia no tiene `grade_item`s, así que el filtro por objectid
registrado deja `itemscores` vacío, el recálculo en servidor no llega a ejecutarse y la
publicación del overall caía de vuelta al `cmi.core.score.raw` que envía el CLIENTE.

Y `grade_update()` **recrea** un `grade_item` borrado, así que el efecto no era publicar
en una columna existente sino resucitar la que el profesor acababa de quitar. Medido:

```
tras apagar la calificación : overall=NO ITEM   items=0
tras un envío del alumno    : overall=95.00000  intentos=1
```

Contradice literalmente lo que dice el formulario —*«no grade columns and no reports»*— y
la propia decisión de [[DEC-13-07]].

## Problema

El defecto existe en `main`, pero estaba **enmascarado**. `view.php` pasaba
`disableTracking = $emitsxapi` ([[DEC-85-01]]), de modo que el shim SCORM quedaba inerte
para cualquier paquete que emitiera xAPI — que son todas las exportaciones recientes de
eXeLearning, porque el emisor viaja en todas. Al retirar el canal xAPI desaparece
`disableTracking`, el shim está siempre vivo, y el defecto pasa de latente a universal.
Por eso se arregla aquí y no en otro sitio.

La pregunta de diseño es dónde está la frontera del interruptor. Un cortocircuito al
principio de `ingest()` —la primera respuesta, ensayada en `#105`— la sitúa en «no se
registra nada», y eso rompe dos cosas:

- **[[DEC-69-01]]**, la finalización por estado, lee el `status` de la fila
  `itemnumber = 0` filtrando por `exelearningid`, `userid`, `itemnumber` y `status`,
  **nunca por `gradeenabled`**; `mod_form.php` tampoco condiciona la regla al
  interruptor. Un profesor puede exigir «finalizada al completar» en una actividad no
  calificable, y sin esa fila no se completaría nunca.
- **[[DEC-13-07]]** conserva los intentos para poder recomponer la nota al reactivar, que
  es lo que [[DEC-124-01]] acaba de hacer efectivo. Sin filas no hay historial.

## Decisión

El interruptor gobierna **la publicación en el libro de calificaciones**, no la ingesta.
La condición se coloca en el único punto donde `ingest()` publica el overall, junto a la
condición de modo que ya vivía allí ([[DEC-25-01]]):

```php
if ($grademodel === EXELEARNING_GRADEMODEL_OVERALL && !empty($exe->gradeenabled)) {
```

Con el interruptor apagado se siguen registrando el intento, el estado, la finalización y
los eventos; lo único que no ocurre es `grade_update()`.

**El camino PERITEM no necesita guarda propia**, y esto no es una suposición: `apply_one()`
enruta por objectid registrado ([[DEC-5-01]]), y al apagar el interruptor
`grade_item_manager::remove_all()` marca las filas `deleted = 1`, así que no queda ningún
objectid que reconocer y nunca se ejecuta. Añadir allí una segunda comprobación sería
código muerto que sugeriría, en falso, la existencia de un camino que no existe. El caso
`peritem` del test lo fija: pasa en verde **antes** del arreglo y sigue en verde después.

## Consecuencias

- El `cmi.core.score.raw` del cliente ya no alcanza el libro con el interruptor apagado,
  ni resucita columnas borradas.
- [[DEC-69-01]] sigue funcionando en actividades no calificables.
- [[DEC-13-07]] y [[DEC-124-01]] conservan el historial que necesitan.
- `ingest()` no gana ninguna rama `noop` nueva: el `noop` que ya devuelve cuando el
  payload no trae puntuación ([[DEC-26-02]]) no cambia.
- El ensayo previo de esta decisión vivía en la rama de `#105` como cortocircuito de
  entrada. Se retira de allí: `#105` queda como sustitución de librería sin cambio de
  comportamiento, y la decisión y su implementación viven aquí, donde está la causa por la
  que el defecto deja de estar enmascarado.

## Validación

`tests/track_test.php::test_ingest_with_grading_disabled_records_attempt_but_publishes_no_grade`,
con proveedor de datos para los **dos** modelos de nota. Afirma las dos mitades: `items`
vacío en `grade_get_grades()` —que es más fuerte que «el valor es null», porque exige que
el propio `grade_item` no reaparezca— y la fila `itemnumber = 0` de `exelearning_attempt`
presente. Verificado en rojo antes del arreglo (`overall`: *Failed asserting that two
arrays are identical*) y en verde después. Suite completa: 330 tests, 1196 aserciones.
