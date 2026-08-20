---
id: DEC-122-01
title: "El overall del canal xAPI se deriva en servidor (roster del profesor + filas del intento); el almacenamiento en navegador queda rechazado"
status: Accepted
date: 2026-08-20
tracking_issue: 122
deciders:
  - erseco
  - claude-code
related:
  adrs: [DEC-85-01, DEC-68-01, DEC-25-01, DEC-5-01, DEC-0-18]
ai_assistance:
  tool: "Claude Code"
  model: "claude-opus-5"
---

# DEC-122-01: El overall del canal xAPI se deriva en servidor (roster del profesor + filas del intento); el almacenamiento en navegador queda rechazado

## Contexto

Upstream (exelearning#2302, ADR-2302-01) suprime los veredictos de paquete en multipágina por ser
demostrablemente erróneos (cada página emitía un veredicto page-local sobre el IRI del paquete: un
mismo intento podía enviar `passed` y `failed`). El PR #121, que reconstruía el overall aprendiendo
pesos/orden/censo de statements de alumnos, se cerró: PERITEM (el modelo por defecto) solo necesita
`objectid + score`, «overall parcial exacto» no era un requisito documentado, y el censo permitía a
cualquier alumno redefinir metadata que remodelaba la calificación y la completion de los demás.

Sin sustituto, el canal xAPI perdía en multipágina la fila `itemnumber=0`: no solo
`completionstatusrequired` — también **toda la calificación del grademodel OVERALL** (su único
`grade_update` vivía en la rama de verbos de paquete), las listas de intentos del alumno, el resumen
de participación del profesor, el WS `get_user_attempts` y el evento `attempt_completed`.

## Decisión

En cada `answered` del canal xAPI, el servidor deriva la fila `itemnumber=0` a partir de datos que
ya posee:

1. **Valor**: media sin pesos de las filas per-item del intento, computada con el
   `track::recompute_overall_pct()` público del canal SCORM (idéntica para paquetes sin pesos: el
   normalizador fija `weighted = 0.0`). Los pesos son irrecuperables en este canal por diseño — los
   statements no los llevan y la estructura suministrada por alumnos está vetada — así que un
   proyecto ponderado multipágina diverge del veredicto ponderado del emisor y esa divergencia se
   documenta, no se disimula.
2. **Completitud**: intersección del roster del profesor (grade items registrados, no borrados,
   parseados del paquete en el sync) con las filas del propio intento. Independiente de páginas; la
   extensión `pageCount` — escribible por el alumno — no se consulta jamás.
3. **Estado**: `incomplete` mientras falten ítems (la media parcial queda visible para el resumen de
   participación y el modelo OVERALL, como hace el canal SCORM); al completar, `gradepass > 0`
   decide `passed`/`failed` y `gradepass = 0` da `completed` (el servidor no inventa política de
   aprobado).
4. **Camino único de publicación** (`apply_overall`): fila + `grade_update` (solo OVERALL, DEC-25-01)
   + completion + eventos con `attempt_started` **antes** de `attempt_completed` incluso cuando el
   primer commit completa el intento. La rama de verbos de paquete usa el mismo camino; en
   single-page su valor ponderado autoritativo sobreescribe la fila derivada vía upsert.

## Alternativas rechazadas

- **E — estado cross-página en `sessionStorage` del emisor** (habría evitado tocar el plugin):
  **insegura, confirmada adversarialmente**. Ninguna clave que llegue al paquete exportado acota un
  intento: el `sessiontoken` vive solo en la página padre, el `odeId` es estable entre intentos,
  revisiones y despliegues del mismo origen, y los launch params mueren en el primer enlace relativo.
  El bucket por-pestaña del mismo origen sobrevive al reload de `view.php` que acuña el intento
  nuevo → seis escenarios de veredicto falso imprevenibles (nuevo intento en la misma pestaña, reset
  del profesor, actividad duplicada, revisión re-subida, máquina compartida, preview-luego-calificar),
  y el ingestor trata cualquier verbo de paquete como autoritativo: «a veces erróneo» es peor que
  «ausente y detectable». No re-proponer storage de navegador para esto.
- **B-minimal (solo escritura terminal)**: ahorraba ~15 líneas a cambio de que en OVERALL un alumno
  que no termina se quede sin nota alguna y el profesor vea «nunca intentado» a quien va por la
  mitad. Paridad con el canal SCORM > 15 líneas.
- **F (aceptar y documentar)**: refutada como coste «solo de completion» — ver Contexto.

## Consecuencias

- Cero cambios de esquema y cero migraciones; un solo fichero de producción (`ingestor.php`).
- La deriva de roster (re-publicación a mitad de intento) puede regresar un intento a `incomplete`
  — la misma clase de riesgo que el canal SCORM ya tolera; fijada por test.
- Residuo honesto: un multipágina lanzado directamente contra un LRS (sin este plugin) sigue sin
  veredicto de paquete; eso solo se cierra con metadata de pesos de confianza, no desde aquí.
- El SQL de monitorización antiguo («answered sin terminal») pasaría a alertar sobre tráfico sano;
  reescrito para vigilar solo el solape single-page.
