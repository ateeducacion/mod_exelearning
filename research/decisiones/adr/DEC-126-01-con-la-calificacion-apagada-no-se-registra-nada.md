---
id: DEC-126-01
title: "Con la calificación apagada no se registra nada, y al encenderla se empieza de cero"
status: Accepted
date: 2026-08-22
tracking_issue: 126
deciders:
  - erseco
  - claude-code
sources:
  - REPO-004
supersedes: [DEC-124-02]
related:
  adrs: [DEC-13-07, DEC-69-01, DEC-124-01, DEC-124-02, DEC-124-03]
ai_assistance:
  tool: claude-code
  model: claude-opus-5
---

# DEC-126-01: Con la calificación apagada no se registra nada, y al encenderla se empieza de cero

## Contexto

[[DEC-124-02]] decidió que el interruptor «Calificable» silenciara la publicación de nota
pero no la ingesta: con la calificación apagada, `track::ingest()` seguía escribiendo la
fila de intento, marcada `gradable = 0`. Se guardaba por dos motivos declarados:

1. la finalización por estado ([[DEC-69-01]]) lee esa fila y no consulta `gradeenabled`;
2. era el historial que [[DEC-124-01]] usa para recalcular la nota cuando el interruptor
   vuelve a encenderse.

Eso obligó a un mecanismo entero para que ese historial no se convirtiera en nota:
la columna `gradable`, la herencia de gradabilidad por intento, el «envenenamiento» del
intento completo cuando una sesión cruza el interruptor, y `count_user_attempts()`
excluyendo los intentos no calificables de `maxattempt`. Cuatro piezas para sostener una
distinción entre «guardado» y «calificable» dentro de la misma tabla.

## Problema

La distinción no hace falta. Encender y apagar la calificación a mitad de uso es un caso
raro, y el valor de conservar lo hecho durante el periodo apagado es bajo: si se pierde,
no pasa nada. Lo que sí tiene coste es el mecanismo que lo sostiene, que es donde han
aparecido los fallos.

## Decisión

Con `gradeenabled = 0`, `track::ingest()` **no hace nada**: reconoce la petición y
devuelve `['ok' => true, 'noop' => true]` sin escribir fila de intento, sin tocar el
libro de calificaciones y sin emitir eventos.

Al encender el interruptor, la actividad **empieza a guardar desde ese momento**. No se
recupera nada de lo hecho mientras estaba apagada, ni desde el historial ni de ninguna
otra forma. Se acepta explícitamente que ese trabajo se pierda.

## Consecuencias

- La garantía de [[DEC-124-03]] —lo hecho sin calificación nunca se convierte en nota—
  se mantiene, y ahora de la forma más simple posible: no hay nada que pueda convertirse.
- [[DEC-124-01]] sigue vigente y sin cambios: al reactivar el interruptor se republica
  desde el historial. Lo único distinto es qué historial hay — solo el calificable, el
  que se grabó mientras la actividad estaba calificada. Lo hecho con el interruptor
  apagado ya no existe, así que no hay nada que pueda republicarse por error.
- **La finalización por estado deja de funcionar en una actividad no calificable.**
  [[DEC-69-01]] lee la fila de intento, y ya no se escribe ninguna. Es la contrapartida
  aceptada de esta decisión; si en el futuro se quiere finalización por estado sin
  calificación, necesitará su propio registro, no la tabla de notas.
- La columna `gradable` y la lógica de herencia por intento dejan de tener un productor
  de `0` por esta vía. **No se eliminan aquí**: quitar una columna es una migración y
  merece su propio cambio. Quedan inertes.
- `maxattempt` ya no puede consumirse con trabajo no calificable, porque ese trabajo no
  crea intentos.
- **Limitación conocida.** El cliente acumula el mapa de puntuaciones y lo reenvía
  entero en cada POST (`js/scorm_tracker.js`, deliberado para que un POST fallido no
  pierda una nota). Si el profesor enciende la calificación con una pestaña abierta, el
  primer POST posterior trae también lo puntuado mientras estaba apagada, y eso sí se
  califica. No se añade maquinaria para evitarlo: es el mismo caso raro, y recargar la
  página parte de cero.

## Alternativas consideradas

- **Mantener [[DEC-124-02]]** (guardar sin calificar). Es lo que había. Sostiene la
  finalización por estado sin calificación, al precio de la columna `gradable`, la
  herencia por intento y el envenenamiento de sesiones que cruzan el interruptor —
  mecanismo que hay que razonar entero cada vez que se toca la ingesta.
- **Guardar el periodo apagado aparte y republicarlo al encender.** Es [[DEC-124-02]]
  con otro nombre: hace falta la misma distinción entre guardado y calificable, y el
  mismo mecanismo para sostenerla. Además contradice lo decidido: si se pierde, no pasa
  nada.
- **Partir la sesión en un intento nuevo al encender.** Ya se descartó en
  [[DEC-124-02]]: el servidor no puede distinguir qué entradas del mapa acumulado se
  ganaron antes del cambio y cuáles después, así que el intento nuevo sería un recipiente
  limpio con contenido contaminado.
