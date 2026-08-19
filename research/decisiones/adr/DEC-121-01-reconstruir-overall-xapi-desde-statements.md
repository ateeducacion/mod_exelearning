---
id: DEC-121-01
title: "Reconstruir el OVERALL xAPI desde el último estado ponderado de cada iDevice"
status: Proposed
date: 2026-08-19
tracking_issue: 121
supersedes: DEC-85-01
deciders:
  - erseco
  - codex
sources:
  - REPO-005
related:
  adrs: [DEC-17-01, DEC-0-18, DEC-6-01, DEC-25-01, DEC-85-01]
ai_assistance:
  tool: codex
  model: gpt-5
---

# DEC-121-01: Reconstruir el OVERALL xAPI desde el último estado ponderado de cada iDevice

> Supersede a [DEC-85-01](DEC-85-01-implementacion-ingesta-xapi.md) únicamente en la
> autoridad del valor numérico OVERALL. Se conservan sus decisiones sobre xAPI-primary,
> compatibilidad SCORM, validación, auditoría e idempotencia.

## Contexto

[[DEC-85-01]] decidió usar el `finalScore` del statement de paquete como OVERALL porque el contrato
upstream de 2026 no incluía el peso en los statements `answered`. El peso existía sólo en `_state`
del emisor JavaScript y, con la evidencia disponible entonces, era imposible reconstruir una nota
ponderada en Moodle.

La investigación que origina
[`exelearning/exelearning#2302`](https://github.com/exelearning/exelearning/pull/2302) demostró que
`_state` se crea de nuevo al cargar cada documento HTML. En una publicación multipágina, el
`finalScore` generado tras navegar puede representar sólo los iDevices contestados en la página
actual. Por tanto, un statement de paquete sigue siendo útil como evento de ciclo de vida, pero no
es una fuente numérica suficiente para un agregado que atraviese páginas.

El nuevo contrato upstream añade a cada `answered` evaluable dos extensiones xAPI numéricas en
`context.extensions`: `weight`, el peso relativo efectivo de 1 a 100, e `idevice-order`, el orden
global determinista dentro de la publicación. El `idevice-id` estable ya existente identifica el
estado que debe reemplazarse al volver a contestar.

## Problema

¿Cómo debe calcular Moodle el OVERALL de una publicación xAPI multipágina sin confiar en estado
JavaScript efímero, manteniendo el resultado exacto de eXeLearning y la compatibilidad con paquetes
exportados antes del nuevo contrato?

## Opciones consideradas

1. **Mantener el `finalScore` del statement de paquete como única autoridad.** No requiere cambios
   de esquema, pero conserva el defecto: tras una navegación multipágina el emisor puede haber
   olvidado contribuciones anteriores.
2. **Persistir o restaurar `_state` en el navegador.** `localStorage`, `sessionStorage` o una
   variable del padre podrían ocultar el reinicio de página, pero acoplarían Moodle a estado cliente
   adicional, no mejorarían el contrato xAPI y crearían nuevos problemas de ámbito e intentos.
3. **Reconstruir en Moodle el último estado por iDevice (elegida).** Persistir
   `idevice-id + score + weight + idevice-order`, reemplazar la fila al recontestar y calcular el
   agregado exacto. El statement de paquete queda como señal de estado; su score sólo es fallback
   para paquetes antiguos sin las extensiones.

## Evidencia

- `REPO-005`, PR upstream #2302: `_state` es local al documento, no se restaura mediante Web
  Storage ni xAPI State API, y el peso ya está disponible cuando se construye `answered`.
- `REPO-005`, `common.js#getFinalScore`: los pesos se normalizan a 100 puntos enteros mediante
  largest remainder, con desempate por orden de publicación y redondeo final a dos decimales.
- `REPO-005`, `common.js#registerActivity`: el censo de evaluables (alta con `score: 0` y su peso
  antes de contestar) vive **dentro de** `if (typeof pipwerks !== 'undefined' && pipwerks.SCORM)` y se
  materializa en `cmi.suspend_data`. Es decir, **sólo existe bajo SCORM**, donde el LMS lo persiste
  entre páginas. En exportaciones no-SCORM no hay censo alguno.
- `REPO-005`, `common.js#sendScoreNew`: `gamification.track('answered', …)` sólo se invoca bajo
  `if (game.gameStarted || game.gameOver)`. El emisor xAPI, por tanto, **sólo conoce lo contestado**,
  y `_packageScore()` normaliza sobre ese subconjunto. Consecuencia verificada con el agente que
  implementa upstream: el `finalScore` del statement de paquete ya venía inflado en intentos
  parciales **incluso en publicaciones de una sola página**; no lo introduce este contrato.
- Corolario: el resultado correcto es el que produce SCORM con el censo completo. Renormalizar en
  Moodle sobre el subconjunto contestado devolvería 100 a quien sólo acertó la pregunta de menor
  peso, que es exactamente el defecto que este ADR viene a corregir, no a replicar.
- `REPO-005`, `exe_xapi.js#emit` (upstream #2302): con `pageCount > 1` **no se emite ningún statement
  de paquete con resultado** — ni `completed`, ni `passed`, ni `failed`. Un agregado de página
  llevando el Activity IRI del paquete haría que dos páginas emitieran un `passed` y un `failed` para
  la misma actividad dentro del mismo intento. Los verbos de ciclo de vida `initialized` y
  `terminated` sí se siguen emitiendo, **una vez por carga de página y por `pagehide`**, con
  `result` nulo: no son señal de fin de intento.
- La tabla `exelearning_attempt` ya representa el último estado de cada `(actividad, usuario,
  intento, itemnumber)`: una nueva respuesta actualiza la misma fila, por lo que no hay que sumar
  el historial de statements.
- El listener xAPI vive en el documento Moodle exterior y ya reenvía statements completos aunque
  el iframe navegue entre páginas; no requiere persistencia JavaScript nueva.

## Decisión

1. Leer los metadatos del contrato como **mejor esfuerzo, nunca fatal**. Ningún problema de
   metadatos invalida el statement: `xapi_track.php` responde con HTTP 200 + `ok:false` y
   `js/xapi_listener.js` da por final cualquier 2xx, así que *cualquier* rechazo aquí es pérdida
   silenciosa de nota por construcción. La nota del iDevice siempre se aplica; lo único que puede
   descartarse es su entrada en la reconstrucción. Se requieren ambos valores juntos, así que si
   falta o es ilegible cualquiera de los dos el iDevice se califica en su columna y queda fuera del
   OVERALL. No es una conjetura defensiva: es el contrato upstream, que emite siempre el peso en un
   `answered` evaluable pero omite `idevice-order` cuando no puede resolver la posición global del
   iDevice, y deja precisamente a esos iDevices fuera de su propio agregado. Un peso fuera de 1..100
   se **acota** igual que `effectiveWeight()`/`getFinalScore()`. Un valor presente pero ilegible
   emite `debugging()` para que un emisor roto se vea en desarrollo sin coste para el alumno.
2. Aceptar la IRI `https://exelearning.net/xapi/extensions/idevice-weight` — nombre definitivo
   upstream, alineado con `idevice-id`/`idevice-type`/`idevice-order` — y mantener la grafía previa
   `.../extensions/weight` como alias. Una clave no reconocida degrada al camino legacy **sin
   error visible**, así que tolerar ambas cuesta un lookup y elimina un fallo invisible en
   producción.
3. Añadir `xapiweight` y `xapiorder` como columnas nullable de `exelearning_attempt`. Las filas
   SCORM, las filas OVERALL y los paquetes xAPI anteriores quedan a `NULL`.
4. Usar la fila actual por `itemnumber` como mapa de último estado. Recontestar un iDevice reemplaza
   score y metadatos; nunca se acumula el historial. La idempotencia real del pipeline es este
   upsert, no el `statement.id`: upstream firma su deduplicación con verbo + score + peso + orden, y
   el orden sale de la posición en el DOM, así que un script que mueva un `.idevice_node` hace que
   la misma respuesta se reemita con un UUID nuevo. Reprocesarla es inocuo porque sobrescribe la
   misma fila.
5. Calcular el OVERALL con la misma normalización de pesos, reparto largest remainder, desempate por
   `idevice-order` y redondeo de eXeLearning. El orden de llegada de statements no participa.
6. Aprender el **censo de evaluables del paquete** desde la extensión `idevice-census` que upstream
   emite en el `initialized` y el `terminated` de cada página (la copia del `terminated` es la
   completa: sale tras todo registro, mientras que la del `initialized` se vacía justo después de
   DOM-ready), y persistirlo en dos columnas nullable de
   `exelearning_grade_item`. Es metadato de paquete, idéntico para todos los usuarios: en cuanto
   alguien ha visitado todas las páginas, el paquete queda censado y **cualquier** intento parcial de
   **cualquier** alumno se reconstruye exacto desde su primera respuesta, marcando a 0 los evaluables
   no contestados igual que hace `getFinalScore` con censo. Las filas marcadas `deleted = 1` tras una
   resubida salen del cálculo.
7. Sin censo, reconstruir sólo cuando **todos** los iDevices evaluables registrados han reportado su
   peso en ese intento: sólo un intento contestado por completo lleva el vector de pesos entero, y
   con menos se renormalizaría sobre el subconjunto contestado, inflando. Un intento sin ninguna fila
   del contrato nuevo es legacy en ambos casos y conserva el score del statement de paquete: no se
   reconstruye como una fila de ceros.
8. Separar **poder calcular** de **haber terminado**. Con censo, una sola respuesta ya da nota exacta,
   pero el intento no está terminado. El fin de intento es que todos los evaluables tengan respuesta
   en ese intento, y sólo entonces se fija estado terminal.
9. Derivar el estado terminal en Moodle cuando el intento está completo. Un paquete
   multipágina **no emite ningún veredicto de paquete**, así que la reconstrucción completa es la
   única señal de fin de intento que Moodle va a recibir: sin derivarla, todo intento multipágina se
   quedaría en `incomplete` para siempre, sin cumplir nunca `completionstatusrequired` ni disparar
   `attempt_completed`. El emisor da por aprobado un paquete de una página con `>= 50/100`; el
   equivalente nativo en Moodle es el `gradepass` de la actividad, que es lo que decide aquí
   (`completed` cuando no hay nota de corte configurada). Un statement de paquete, cuando llega,
   sigue mandando sobre el estado. Nunca se degrada a `incomplete`: eso revocaría la finalización y
   rearmaría `attempt_completed` ([[DEC-68-01]]). Publicar nota recalcula finalización en ambos
   caminos ([[DEC-69-01]]) y el valor se acota a `grademin..grademax` en un único punto.
10. Tratar `initialized` y `terminated` como ciclo de vida: auditoría, más el censo que viaja en
    ambos. En multipágina llegan **N veces por intento** (uno por carga de página y uno por
    `pagehide`), con `result` nulo. Leer `terminated` como fin de intento cerraría el intento al pasar
    de la primera página. Un cambio de censo re-ejecuta la reconstrucción del alumno que lo trae: sin
    eso, quien contesta la página 1 y sólo visita la 2 completaría el censo sin que nada volviera a
    calcular su OVERALL, porque la reconstrucción sólo se dispara desde `answered`. El censo no se aprende en previsualización: [[DEC-0-06]] dice que una
    previsualización no escribe, y la excepción no compensa la ambigüedad.
11. Si un intento no contiene filas con el nuevo contrato, o no hay censo y su estado aún es parcial,
    conservar el score del statement de paquete como fallback compatible de [[DEC-85-01]]. No se
    cambia SCORM.
12. Asumir que el OVERALL xAPI y el OVERALL SCORM pueden diferir hasta en un punto asignado. SCORM
   recibe el mapa completo de `cmi.suspend_data` en cada commit, así que
   `track::recompute_overall_pct()` puede usar una media ponderada continua (33,33 para tres tercios
   iguales); xAPI reconstruye con el reparto entero largest remainder del propio eXeLearning (34).
   Es el redondeo del productor, no una divergencia introducida aquí.
13. No fusionar el PR Moodle 121 antes de que el PR upstream #2302 esté fusionado; el consumidor no
    debe preceder al productor del contrato. Ambos contratos —extensiones por iDevice y censo de
    página— entran en esos dos PRs, no por fases.

## Consecuencias

- **Positivas:** Moodle conserva contribuciones entre páginas, obtiene el mismo resultado ponderado
  que eXeLearning, reemplaza correctamente respuestas repetidas y deja de tratar un agregado
  cliente efímero como única autoridad.
- **Compatibilidad:** los campos xAPI son aditivos y nullable; paquetes antiguos siguen usando el
  fallback existente. Backup, restore y privacidad incluyen las dos columnas nuevas.
- **Coste:** se añaden dos columnas en `exelearning_attempt`, dos en `exelearning_grade_item` y un
  calculador puro; cada respuesta reconstruye el intento actual leyendo el censo del paquete y las
  filas del intento. Los metadatos por iDevice viajan en el mismo upsert que la nota
  (`attempts::record_item()`), sin lectura ni escritura adicionales.
- **Intentos parciales, resueltos por el censo.** Con el paquete censado, un intento parcial se
  reconstruye exacto: los evaluables no contestados pesan como el 0 que eXeLearning les cuenta. El
  censo se aprende una vez por paquete y se comparte entre usuarios e intentos, así que el coste lo
  paga la primera visita completa y lo aprovechan todos los demás.
- **Por qué el censo va en runtime y no en el exportador.** El peso no está disponible en tiempo de
  exportación de forma uniforme: cada tipo de iDevice lo resuelve en su propio JS (clase CSS en
  `geogebra-activity`, `dataGame`/`jsonProperties` en `identify` y `adaptative-quiz`), así que
  extraerlo obligaría a meter conocimiento por tipo de iDevice en el exportador. Verificado con el
  agente que implementa upstream #2302; de ahí que el censo se emita desde `registerActivity`, que ya
  corre por cada evaluable al cargar la página y tiene el peso ya resuelto.
- **Residuos aceptados.** (a) Un evaluable que se registre después del flush del `initialized` de esa
  carga queda fuera de esa copia del censo, pero la copia del `terminated` de la misma visita lo
  recoge (sale en `pagehide`, tras todo registro); la «siguiente visita» sólo es el remedio si ese
  statement de descarga se pierde. (b) Mientras el paquete no esté censado por completo se mantiene la regla
  anterior —sólo un intento contestado entero se reconstruye—, y en multipágina, donde no hay
  statement de paquete, un intento parcial no censado se queda sin fila OVERALL. (c) La
  previsualización del profesor no siembra el censo, porque no escribe ([[DEC-0-06]]).
- **Contrato externo:** la implementación depende de dos extensiones controladas por eXeLearning y
  de la estabilidad de `idevice-id` dentro de la publicación.
- **Historial:** [[DEC-85-01]] queda superseded porque su afirmación de que el score del paquete es
  el OVERALL autoritativo deja de ser válida para paquetes con el contrato nuevo.

## Riesgos

- **Productor y consumidor desalineados.** Mitigado con dependencia explícita: upstream #2302 debe
  fusionarse y publicarse antes de Moodle #121.
- **Metadatos parciales o manipulados.** Mitigado validando ambos campos como unidad, rangos y tipo
  JSON, además de las comprobaciones de identidad y pertenencia existentes.
- **Diferencias de redondeo.** Mitigado replicando el algoritmo largest remainder y cubriendo el
  desempate de tres pesos iguales con llegada de statements desordenada.
- **Mezcla con datos legacy.** Las columnas nullable seleccionan de forma inequívoca la ruta nueva;
  los intentos sin metadatos conservan el fallback anterior.
- **Renombre de la clave de extensión.** Una IRI que el consumidor no reconoce no da error: degrada
  al camino legacy en silencio y la funcionalidad desaparece sin rastro. Mitigado aceptando la
  grafía definitiva y la previa, y cubierto por test.
- **Ciclo de vida repetido.** `initialized`/`terminated` llegan N veces por intento multipágina.
  Mitigado tratándolos como auditoría; cubierto por el test de statement de ciclo de vida.

## Validación

- Tests unitarios del normalizador para metadatos válidos, ausentes, nulos, parciales, fuera de
  rango (acotados), de tipo incorrecto (rechazados) y mapa de `extensions` malformado (degradado).
- Test puro del cálculo para 25/75, empate largest remainder y filas inválidas.
- Tests de integración para dos páginas (100×25 y 40×75 = 55), múltiples iDevices, re-answer,
  persistencia y regresión de statements de paquete.
- Tests de integración para intento parcial (no infla el OVERALL y conserva el fallback), `answered`
  posterior a un statement terminal (conserva el estado y no reemite `attempt_completed`), iDevice
  borrado (deja de pesar) y `grademin` (el OVERALL reconstruido se acota igual que el del paquete).
- Test de integración para un intento que alcanza estado terminal **sin ningún statement de paquete**
  (el caso multipágina): estado derivado por `gradepass` y `attempt_completed` emitido exactamente
  una vez.
- Test unitario de la clave `idevice-weight` definitiva y de su alias previo.
- Tests del censo: parseo y saneado de entradas (peso acotado, entrada sin orden descartada, entrada
  malformada descartada), intento parcial reconstruido exacto (25 y no 100) con estado todavía
  `incomplete`, censo reutilizado por un segundo alumno que nunca emitió censo propio, y intento
  legacy no reconstruido pese a haber censo.
- Tests de backup/restore y privacidad para `xapiweight`/`xapiorder`.
- Suite completa del plugin, Moodle CodeSniffer, validación XMLDB y `architecture-check`.

## Seguimiento

- Mantener el PR 121 en borrador y bloqueado hasta la fusión de upstream #2302.
- Una vez disponible el contrato en una release de eXeLearning, validar un paquete real multipágina
  de extremo a extremo en Moodle y retirar el bloqueo de merge.
