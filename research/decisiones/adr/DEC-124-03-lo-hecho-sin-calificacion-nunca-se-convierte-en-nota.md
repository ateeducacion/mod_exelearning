---
id: DEC-124-03
title: "Lo hecho mientras la actividad no calificaba nunca se convierte en nota"
status: Accepted
date: 2026-08-21
tracking_issue: 124
deciders:
  - erseco
  - claude-code
sources:
  - REPO-004
related:
  adrs: [DEC-13-07, DEC-69-01, DEC-124-01, DEC-124-02, DEC-25-01, DEC-0-07]
ai_assistance:
  tool: claude-code
  model: claude-opus-5
---

# DEC-124-03: Lo hecho mientras la actividad no calificaba nunca se convierte en nota

## Contexto

[[DEC-124-02]] fijó que el interruptor «Calificable» silencia la publicación, no la
ingesta: con `gradeenabled = 0` se sigue registrando el intento —lo necesita la
finalización por estado ([[DEC-69-01]])— pero no se publica ninguna nota. Y
[[DEC-124-01]] hizo efectivo que al reactivar el interruptor se republique desde el
historial.

Compuestas, las dos dejaban una pregunta sin responder: **¿qué pasa con lo que el alumno
hizo *durante* el intervalo apagado?**

## Problema

La respuesta dependía del modelo de nota, que es la peor respuesta posible.

Con el interruptor apagado no hay ningún `objectid` registrado —
`grade_item_manager::remove_all()` marca los mapeos `deleted = 1`— así que:

- en **PERITEM** no se escribía ninguna fila `itemnumber > 0`: al reactivar no había nada
  que resucitar;
- en **OVERALL** sí se escribía la fila `itemnumber = 0` que necesita la finalización, y
  llevaba una puntuación que **no** había pasado por el recálculo en servidor, porque no
  había objectids con los que recalcular: venía del `cmi.core.score.raw` del navegador. Al
  reactivar, `grade_recalculator` la leía y la publicaba.

Reproducido, con la misma secuencia en ambos modelos:

```
OVERALL  Item 0 must have no grade derived from the ungraded interval
         Failed asserting that '95.00000' is null.
PERITEM  (pasa)
```

Es decir: el mismo trabajo del mismo alumno se convertía en nota o no según una opción de
configuración que no habla de eso. Y en el caso en que sí se convertía, la nota procedía
de un valor informado por el cliente sin verificación en servidor.

## Opciones consideradas

1. **Conservar también las notas por iDevice durante el apagado**, suprimiendo únicamente
   los `grade_update()`, para que al reactivar se recuperara todo con un overall
   recompuesto en servidor. Simétrica, pero exige registrar los objectids mientras el
   interruptor está apagado —justo lo que `remove_all()` deshace— y convierte el
   interruptor en una pausa: lo hecho «fuera de evaluación» acabaría evaluado igualmente.
2. **Que lo hecho durante el apagado no se convierta nunca en nota** (elegida). El
   interruptor pasa a ser una afirmación sobre *qué es la actividad*, no un botón de
   pausa.

## Decisión

Una fila de `exelearning_attempt` escrita con la calificación apagada queda marcada como
**sólo para finalización**, y la agregación la ignora siempre.

- Campo nuevo `gradable` (INT, `NOTNULL`, **DEFAULT 1**) en `exelearning_attempt`, añadido
  por la etapa de upgrade 22 (`2026082101`).
- `attempts::record_item()` recibe un parámetro `$gradable` (por defecto `true`, para no
  cambiar el resto de llamadores) y `track::ingest()` le pasa `!empty($exe->gradeenabled)`
  en sus **dos** puntos de escritura. El segundo, dentro de `apply_one()`, es inalcanzable
  con el interruptor apagado —no hay objectid que enrute allí— pero se pasa el valor en
  lugar de codificar `true`, para que los dos puntos no puedan divergir si eso cambia.
- Las **tres** consultas de agregación filtran `gradable = 1`:
  `attempts::aggregate_scaled()`, `attempts::fetch_scaled_by_user_item()` y la media de
  participación del resumen. Si una sola no filtrara, la asimetría volvería por ahí.

**Lo que deliberadamente NO filtra**, que es la otra mitad de la decisión: las consultas
que *cuentan* o *asignan* siguen viendo todas las filas. El `COUNT(DISTINCT attempt)` que
aplica `maxattempt`, el `COUNT(DISTINCT userid)` de participación y el `MAX(attempt)` que
asigna el siguiente número de intento no llevan el filtro. Un alumno que gastó intentos
mientras la actividad no calificaba los gastó igualmente, y participó igualmente; y filtrar
en el `MAX(attempt)` haría que se reutilizaran números de intento, corrompiendo el
historial. La regla es: **la agregación de nota filtra, el recuento y la asignación no.**

Las filas preexistentes toman el valor por defecto `1`. Todo lo registrado antes de esta
etapa lo escribió un `ingest()` que no hacía esta distinción, y suponerlas calificables es
la opción conservadora: preserva las notas que un sitio ya hubiera publicado.

## Consecuencias

- Los dos modelos de nota obedecen la misma regla, y esa regla ya no depende de la
  configuración.
- Ningún `cmi.core.score.raw` sin verificar en servidor puede llegar al libro por la vía
  diferida de reactivar el interruptor.
- **No se pierde información.** La fila sigue ahí, con su puntuación, su estado y su marca
  de tiempo: alimenta la finalización por estado y aparece en el informe de intentos. Lo
  único que no hace es contar para una nota.
- El campo se declara a la API de privacidad, se exporta con `transform::yesno()` y lleva
  su cadena en los cinco idiomas.
- [[DEC-124-01]] sigue cumpliéndose para lo que sí era historial calificable: apagar y
  volver a encender recupera las notas anteriores al apagado. Lo que no recupera es lo que
  nunca fue una nota.

## Validación

`tests/grades_test.php::test_work_done_while_ungraded_never_becomes_a_grade`, con proveedor
de datos para los **dos** modelos. El alumno completa la actividad con el interruptor
apagado; se comprueba que las filas quedan con `gradable = 0`; el profesor activa la
calificación; se exige que las columnas vuelvan a existir y que **todas** estén vacías, y
que la fila `itemnumber = 0` siga presente para la finalización.

Verificado en rojo en dos variantes distintas, para separar las dos mitades del arreglo:
sin la marca, ambos modelos fallan la aserción de `gradable`; con la marca escrita pero sin
filtrar los lectores, `overall` falla con *«Failed asserting that '95.00000' is null»* y
`peritem` pasa — que es exactamente la asimetría descrita arriba. La etapa 22 se verificó
añadiendo la columna sobre una instalación existente (`BEFORE: absent` → `AFTER: present`).
Suite completa: 332 tests, 1209 aserciones.
