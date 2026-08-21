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

**Una sesión que atraviesa un cambio de estado se parte en dos intentos.**
`resolve_attempt_number()` busca el intento de la sesión por `sessiontoken` **y por
gradabilidad**, así que un alumno con la página abierta cuando el profesor enciende la
calificación recibe un intento nuevo y calificable en su siguiente autocommit, en vez de
seguir escribiendo en una fila que jamás podrá producir nota. No le cuesta nada:
`count_user_attempts()` no cobra el intento no calificable contra `maxattempt`. El efecto
lateral importante es que **cada intento queda homogéneo** —todas sus filas se escribieron
bajo un mismo estado— que es lo que permite que la marca describa las notas junto a las que
está.

Sin partir, el caso encendido→apagado dejaba al alumno completando la actividad entera
dentro de una fila marcada como sólo-finalización, sin nota y sin ninguna señal.

**La marca puede bajar, nunca subir.** En `record_item()`, una escritura que trae datos del
periodo no calificable baja la fila a `gradable = 0`. Es necesario porque el upsert
**sustituye** la nota en lugar de añadir una fila: si no bajara, la fila afirmaría ser
calificable mientras guarda una nota obtenida fuera de evaluación. Subirla es lo que nunca
puede ocurrir: es la garantía que este campo existe para dar. Con el reparto anterior la
situación casi no se da —los intentos son homogéneos— y esto queda como guarda para
cualquier llamador que no pase por `resolve_attempt_number()`.

Una revisión anterior de este ADR justificaba «no actualizar nunca» diciendo que bajar la
marca destruiría el historial que [[DEC-124-01]] promete recuperar. La premisa era falsa:
las cuatro líneas siguientes de esa misma rama ya sustituyen `rawscore`, `maxscore`,
`scaledscore` y `status`, así que el historial anterior se pierde de todas formas y sólo
sobrevivía la marca, ya desligada del contenido que decía describir.

**Sin historial calificable no se publica nada.** `aggregate_scaled()` devuelve `null` cuando
todas las filas `itemnumber = 0` del alumno son sólo-finalización, y el código caía entonces a
`$score`, que es el `cmi.core.score.raw` del cliente. Publicar ese valor sería confiar en el
navegador precisamente en el caso en que el servidor ha decidido que nada del historial cuenta.
La publicación del overall exige ahora historial calificable. No afecta al primer POST normal:
`record_item()` se ejecuta antes, así que con el interruptor encendido siempre hay al menos una
fila calificable cuando se llega ahí.

**El backup lleva la marca.** `gradable` forma parte del significado académico de la fila, no
de su contabilidad, así que viaja en el `backup_nested_element` de los intentos y el restore la
lee explícitamente, con respaldo a `1` para copias anteriores a esta decisión. Omitirla dejaba
que el valor por defecto de la columna convirtiera en calificable, al restaurar, un historial
que no lo era: un backup/restore no puede cambiar en silencio el significado académico del
historial de un alumno. Es exactamente el mismo defecto que ya se corrigió para `gradeenabled`
(B4, [[DEC-34-01]]), una columna más tarde.

**Qué filtra y qué no.** No es una regla por tipo de consulta sino por lo que cada una
significa:

| consulta | filtra | por qué |
|---|---|---|
| `aggregate_scaled()`, `fetch_scaled_by_user_item()`, media del resumen | **sí** | son la nota |
| `COUNT(DISTINCT attempt)` de `count_user_attempts()` (`maxattempt`) | **sí** | `maxattempt` es un control de calificación —`mod_form.php` lo deshabilita junto al resto de ajustes de nota cuando la actividad no califica—, así que cobrárselo al alumno por trabajo que la propia actividad declaró fuera de evaluación crea un estado sin salida: con `maxattempt = 1`, quien usó la actividad mientras no calificaba llega al límite sin haber tenido nunca un intento calificable, y ya no puede obtener nota |
| `MAX(attempt)` de `resolve_attempt_number()` | **no** | asigna el siguiente número; saltarse filas reemitiría un número existente, colisionando con la clave del upsert de `record_item()` y fundiendo dos intentos en uno |
| `COUNT(DISTINCT userid)` de participación | **no** | cuenta quién ha participado, no quién ha sido calificado. Como la media **sí** filtra, las dos mitades de la frase describen poblaciones distintas, así que `participation_summary()` devuelve además `graded` y la cadena nombra las dos: *«N de M han intentado · media X% sobre G calificados»* |
| contador «intentos usados» de `view.php` | **sí**, vía `count_user_attempts()` | es el número contra el que se muestra el tope; contarlo aparte mostraría «1 de 1» a un alumno al que el servidor todavía acepta |
| `get_user_attempts` (servicio web) | **no**, pero lo expone | la lista es el historial del alumno y va entera; se añade `gradable` por intento y `usedattempts` con el número que el servidor aplica, para que un cliente no deduzca el tope de `count($attempts)` |
| `custom_completion` | **no** | la finalización es la razón por la que la fila existe |

Una revisión anterior de este ADR afirmaba que «el recuento y la asignación no filtran» como
regla general. Era incorrecto para `maxattempt`, y queda corregido arriba.

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
- **La exportación del informe lleva la marca; la tabla en pantalla todavía no.** El CSV/Excel
  gana una columna «cuenta para la calificación», porque sin ella una descarga no se puede
  reconciliar contra el libro de calificaciones. La tabla en pantalla sigue mostrando esas
  filas con su puntuación y sin distintivo: un profesor puede ver ahí un 95 que el libro no
  recoge. Añadir la columna o el distintivo visual es trabajo de presentación y queda
  anotado como seguimiento.
- **Las filas anteriores a esta versión se quedan como calificables.** La etapa 22 no hace
  backfill: después del hecho no hay forma fiable de saber cuáles se escribieron con el
  interruptor apagado. Filtrar por «instancias hoy no calificables» no discrimina —al apagar,
  `remove_all()` marca *todos* los mapeos como borrados— y degradaría historial legítimo de
  cualquier sitio que tenga ahora el interruptor apagado. El `CHANGELOG` lo dice
  explícitamente en vez de prometer la garantía sin condiciones.
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
