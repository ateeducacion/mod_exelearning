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

**La marca pertenece al INTENTO, y puede bajar pero nunca subir.** Una sesión conserva el
intento con el que nació aunque el interruptor cambie por debajo, y el invariante es que
**todas las filas de un intento comparten su marca**. `record_item()` lo mantiene en las dos
direcciones: si alguna fila del intento ya es sólo-finalización, la que se escribe también lo
es; y la primera escritura no calificable **baja todas las filas que ya estuvieran ahí**, no
sólo la suya.

Bajar únicamente la fila que se escribe no basta, y deja un intento mixto. En PERITEM el POST
no calificable reescribe sólo la fila overall —con los mapeos borrados el filtro por objectid
vacía `itemscores` y `apply_one()` no llega a ejecutarse— así que las filas por iDevice
sobrevivirían calificables. Un intento mixto rompe tres cosas a la vez:
`count_user_attempts()` cuenta el intento como gastado si **alguna** de sus filas es
calificable, con lo que seguiría consumiendo `maxattempt`; la fila superviviente puede
republicarse al volver a encender; y corrompe la propia herencia, porque preguntar por la
gradabilidad del intento con `IGNORE_MULTIPLE` —que no lleva `ORDER BY`— devuelve una fila
cualquiera, de modo que `min(entrante, una fila cualquiera)` no es `min(entrante, el intento)`.

Partir la sesión en un segundo intento calificable se intentó y **es incorrecto**. El cliente
acumula su mapa `itemScores` y no lo vacía nunca —a propósito, para que un POST fallido no
pierda una nota— de modo que cada POST posterior reenvía todo lo capturado durante el periodo
no calificable. Un intento nuevo y calificable es, por tanto, un recipiente limpio para
contenido sucio: el servidor no puede saber qué entradas de ese mapa se ganaron antes del
cambio y cuáles después. Medido sobre la implementación que partía:

```
attempt=1 item=0 raw=95 gradable=0
attempt=2 item=0 raw=95 gradable=1
attempt=2 item=1 raw=95 gradable=1
GRADE item 1 = '95.00000'
```

Una sesión que ha cruzado el interruptor no puede producir ninguna nota fiable, así que no
produce ninguna. Recargar acuña un token nuevo y un intento limpio, y eso no le cuesta nada al
alumno porque `count_user_attempts()` no cobra el no calificable contra `maxattempt`.

El precio, asumido: un alumno con la pestaña abierta cuando se enciende la calificación sigue
trabajando sin nota hasta que recargue, y no recibe ningún aviso. Es preferible a la
alternativa —convertir en nota trabajo hecho fuera de evaluación, que es justo lo que este ADR
prohíbe— y su trabajo no se pierde: queda registrado, cuenta para la finalización y no le gasta
intentos. Avisarlo en la interfaz requiere que el servidor devuelva una señal y que el cliente
la lea; queda anotado como seguimiento.

**El tope de intentos sólo se aplica mientras la actividad califica.** `maxattempt` es un
control de calificación, y con `count_user_attempts()` filtrando, dejarlo armado con el
interruptor apagado negaría el acceso a una actividad **no calificable** por intentos
calificables gastados antes. Además cierra un rodeo: con el tope armado durante el periodo
apagado, la excepción `$sessionknown` —que existe para no cortar una sesión en curso— era lo
único que permitía escribir, y una sesión conocida del periodo apagado se llevaría esa exención
a un intento calificable posterior.

**Sin historial calificable no se publica nada, en ninguno de los dos modelos.**
`aggregate_scaled()` devuelve `null` cuando todas las filas del alumno para ese item son
sólo-finalización, y el código caía entonces al valor del cliente: `$score`
(`cmi.core.score.raw`) en el overall de `ingest()`, y `$rawitem` en el por-iDevice de
`apply_one()`. Los dos caminos tienen su propio fallback y los dos hay que cerrarlos —cerrar
sólo el overall dejaba PERITEM publicando el 95 del navegador, que es exactamente lo que
midió la sonda de arriba. Publicar ese valor sería confiar en el
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
| comprobación del tope en `ingest()` | **no se ejecuta** con el interruptor apagado | es un control de calificación; ver arriba |
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
