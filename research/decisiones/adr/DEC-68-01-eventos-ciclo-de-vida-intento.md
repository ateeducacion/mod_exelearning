---
id: DEC-68-01
title: "Eventos de ciclo de vida del intento (attempt_started / attempt_completed), uno por intento"
status: Accepted
date: 2026-06-12
tracking_issue: 68
legacy_id: DEC-0051
deciders:
  - erseco
  - claude-code
sources:
  - REPO-004
related:
  adrs: [DEC-26-03, DEC-0-07, DEC-26-02, DEC-34-01]
ai_assistance:
  tool: claude-code
  model: claude-opus-4-8
---

# DEC-68-01: Eventos de ciclo de vida del intento (attempt_started / attempt_completed), uno por intento

## Contexto

La auditoría comparativa estándar (REPO-004) señala que, en observabilidad, mod_exescorm
expone 11 eventos frente a los 4 de mod_exelearning, y sitúa la "observabilidad" como uno
de los pocos ejes donde mod_exescorm puntúa por encima. [[DEC-26-03]] ya tomó una decisión
deliberada sobre esto: pasó de 1 a 4 eventos (`attempt_deleted`, `report_viewed`,
`course_module_instance_list_viewed`) y **descartó explícitamente "un evento por commit de
tracking"** porque el shim SCORM autocomita aproximadamente cada 500 ms e inundaría el
logstore.

Esta ADR retoma el eje sin contradecir esa decisión: añade observabilidad del **resultado
de aprendizaje** (no de cada commit) cerrando parte del hueco frente a mod_exescorm.

## Problema

Hoy `\mod_exelearning\local\track::ingest()` —la tubería compartida por el endpoint web
`track.php` y el servicio `save_track` ([[DEC-26-02]])— persiste el intento, rutea las notas
por iDevice y actualiza el libro y la finalización, pero **no emite ningún evento**. No hay
rastro en el logstore de que un alumno haya empezado o terminado un intento, ni señal para
que otros plugins (analítica, notificaciones, informes) reaccionen. Replicar los eventos
por-commit/por-elemento de mod_exescorm (`scoreraw_submitted`, `cmielement_submitted`)
reintroduciría justo el ruido que [[DEC-26-03]] evitó.

## Opciones consideradas

1. **Dos eventos de ciclo de vida, uno por intento (ELEGIDA).** `attempt_started` (en el
   commit que crea el intento) y `attempt_completed` (en el commit que lleva por primera vez
   el intento a un estado terminal: `passed` / `failed` / `completed`, con la nota final y el
   estado en `other`). Volumen acotado a O(intentos), no O(commits). Cierra el eje "inicio +
   resultado" que da mod_exescorm sin su ruido.
2. **Solo `attempt_started`.** Mínimo y seguro, pero pierde la visibilidad del resultado
   (cuántos intentos terminan y con qué nota), que es la parte de mayor valor analítico.
3. **`score_submitted` por commit (estilo `scoreraw_submitted`).** Máxima granularidad pero
   inunda el logstore: tras el primer iDevice puntuado, cada autocommit de ~500 ms lleva
   `cmi.core.score.raw` y dispararía el evento. **Rechazada**: es exactamente lo que
   [[DEC-26-03]] descartó.

## Análisis (valoración)

| Criterio | A: 2 eventos/intento | B: solo started | C: por commit |
|---|---|---|---|
| Cierra el eje observabilidad (REPO-004) | Sí (inicio + resultado) | Parcial | Sí pero con ruido |
| Volumen en logstore | O(intentos) | O(intentos) | O(commits) ≈ flood 500 ms |
| Coherencia con [[DEC-26-03]] | La extiende | La extiende | La contradice |
| Valor analítico (tasa de finalización, nota) | Alto | Bajo | Alto pero ilegible |
| Coste de implementación | Bajo (1 punto, `ingest()`) | Muy bajo | Bajo |

La opción A es la única que sube el eje penalizado **conservando** el principio de
[[DEC-26-03]] (eventos selectivos, sin ruido de tracking). El coste es mínimo: ambos eventos
se emiten desde el único chokepoint `ingest()`, así que web y móvil ([[DEC-26-02]]) emiten la
misma señal sin tocar los endpoints.

## Evidencia

- REPO-004: auditoría comparativa — observabilidad como eje donde mod_exescorm (11 eventos)
  supera a mod_exelearning (4). El propio informe critica la riqueza por-commit de
  mod_exescorm (`cmielement_submitted`) como carga de mantenimiento/ruido.
- [[DEC-26-03]]: "Descartados por ruido/bajo valor: un evento por commit de tracking
  (autocommit 500 ms inundaría el log)". Esta ADR respeta ese límite.
- Código: `\mod_exelearning\local\track::ingest()` filtra antes los commits preview / no-op
  (`rawscore` nulo) / sobre-cap, de modo que los eventos solo se emiten tras una escritura
  real. La detección "una vez por intento" usa `record_exists` (intento nuevo) y el `status`
  previo del registro overall (`itemnumber=0`) para la transición a terminal; `status` ya se
  almacena por intento ([[DEC-0-07]]).

## Decisión

Añadir dos eventos `\mod_exelearning\event\attempt_started` y
`\mod_exelearning\event\attempt_completed` (LEVEL_PARTICIPATING), emitidos desde
`track::ingest()` como máximo una vez por intento: `attempt_started` en la creación del
intento; `attempt_completed` en la primera transición a estado terminal, llevando la nota
overall recomputada en servidor y el estado en `other`. **No** se añade ningún evento
por-commit ni por-elemento. Esta ADR **extiende** [[DEC-26-03]] (no la supersede): suma los
dos eventos de ciclo de vida del alumno que faltaban.

## Consecuencias

- Positivas: el logstore refleja inicio y resultado de cada intento; habilita analítica de
  finalización/nota y hooks de otros plugins; cierra parcialmente el eje observabilidad de
  REPO-004; coste nulo en endpoints (un solo punto de emisión, web + móvil).
- Negativas / coste: dos clases de evento + strings nuevos a mantener; una consulta extra
  (`get_field` del status previo) por commit con nota, despreciable.
- Cambios que dispara: ninguno en otros ADRs; `docs/TRACKING.md` documenta los eventos.

## Riesgos

- RIE-016: un commit puntuado sin `lesson_status` explícito normaliza a `completed`
  ([[DEC-0-07]]), de modo que `attempt_completed` se dispararía en el primer commit con nota.
  Mitigación: es **una vez por intento** igualmente (el siguiente commit ve el estado previo
  ya terminal y no re-emite), así que no hay flood; el caso incompleto→completo real se
  cubre por la transición de estado. Aceptado.

## Validación

- `tests/events_test.php` extendido: una pasada por `track::ingest()` emite
  `attempt_started` + `attempt_completed` con `attempt`/`score`/`status`; un segundo commit
  en la misma sesión (sigue terminal) no emite nada; un commit `incomplete` seguido de
  `passed` emite `attempt_started` y luego `attempt_completed`; preview y commit status-only
  no emiten; `validate_data()` exige los campos requeridos.
- `phpcs --standard=moodle` 0/0. PHPUnit corre en CI (no local; ver memoria `phpunit_local`),
  gate Codecov patch ([[DEC-66-01]]).

## Seguimiento

- Cierra esta mejora de observabilidad. No abre tareas; deja la puerta a un futuro
  `attempt_reviewed` si surge necesidad, manteniendo el principio "uno por intento".
