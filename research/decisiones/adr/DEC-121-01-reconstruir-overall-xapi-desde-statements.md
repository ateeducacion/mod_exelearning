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
- `REPO-005`, `common.js#registerActivity`: al cargar la página, **todos** los iDevices evaluables se
  dan de alta en `lmsData` con `score: 0` y su peso, antes de que el alumno conteste. El denominador
  de `getFinalScore` es por tanto la publicación completa, no el subconjunto contestado: normalizar
  sólo sobre lo contestado devolvería 100 a quien únicamente acertó la pregunta de menor peso.
- La tabla `exelearning_attempt` ya representa el último estado de cada `(actividad, usuario,
  intento, itemnumber)`: una nueva respuesta actualiza la misma fila, por lo que no hay que sumar
  el historial de statements.
- El listener xAPI vive en el documento Moodle exterior y ya reenvía statements completos aunque
  el iframe navegue entre páginas; no requiere persistencia JavaScript nueva.

## Decisión

1. Aceptar el contrato de `exelearning/exelearning#2302` sólo cuando `weight` e `idevice-order`
   estén presentes juntos y sean numéricos. Se rechaza el contrato **contradictorio**: media pareja
   presente, o un valor que no puede leerse como el número que el contrato exige. Se degrada al
   camino legacy (sin metadatos) cuando ambos faltan, cuando ambos son `null` — valor legal dentro
   de un mapa de `extensions` según [[DEC-0-18]] §5 — o cuando el propio mapa de `extensions` no es
   un mapa. Un peso fuera del dominio 1..100 se **acota** igual que hace `getFinalScore`, no se
   rechaza: `xapi_track.php` responde a un statement inválido con HTTP 200 + `ok:false` y
   `js/xapi_listener.js` da por final cualquier 2xx, así que rechazar equivale a perder la nota del
   iDevice en silencio.
2. Añadir `xapiweight` y `xapiorder` como columnas nullable de `exelearning_attempt`. Las filas
   SCORM, las filas OVERALL y los paquetes xAPI anteriores quedan a `NULL`.
3. Usar la fila actual por `itemnumber` como mapa de último estado. Recontestar un iDevice reemplaza
   score y metadatos; nunca se acumula el historial.
4. Calcular el OVERALL con la misma normalización de pesos, reparto largest remainder, desempate por
   `idevice-order` y redondeo de eXeLearning. El orden de llegada de statements no participa.
5. Reconstruir sólo cuando **todos** los iDevices evaluables registrados y no borrados de la
   actividad han reportado su peso en ese intento. Mientras el total de pesos del paquete sea
   desconocido no se publica ningún número reconstruido: un intento parcial reproduciría al alza el
   mismo defecto que este ADR corrige. Las filas cuyo `exelearning_grade_item` quedó marcado
   `deleted = 1` tras una resubida salen del cálculo.
6. Persistir y publicar el OVERALL reconstruido tras cada `answered` nuevo **sin tocar el estado**:
   se conserva el estado terminal que ya hubiera fijado un statement de paquete. Degradarlo a
   `incomplete` revocaría la finalización por `completionstatusrequired` y volvería a disparar
   `attempt_completed` ([[DEC-68-01]]). Publicar nota recalcula finalización en ambos caminos
   ([[DEC-69-01]]) y el valor se acota al rango `grademin..grademax` en un único punto.
7. Si un intento no contiene filas con el nuevo contrato, o su estado aún es parcial, conservar el
   score del statement de paquete como fallback compatible de [[DEC-85-01]]. No se cambia SCORM.
8. Asumir que el OVERALL xAPI y el OVERALL SCORM pueden diferir hasta en un punto asignado. SCORM
   recibe el mapa completo de `cmi.suspend_data` en cada commit, así que
   `track::recompute_overall_pct()` puede usar una media ponderada continua (33,33 para tres tercios
   iguales); xAPI reconstruye con el reparto entero largest remainder del propio eXeLearning (34).
   Es el redondeo del productor, no una divergencia introducida aquí.
9. No fusionar el PR Moodle 121 antes de que el PR upstream #2302 esté fusionado; el consumidor no
   debe preceder al productor del contrato.

## Consecuencias

- **Positivas:** Moodle conserva contribuciones entre páginas, obtiene el mismo resultado ponderado
  que eXeLearning, reemplaza correctamente respuestas repetidas y deja de tratar un agregado
  cliente efímero como única autoridad.
- **Compatibilidad:** los campos xAPI son aditivos y nullable; paquetes antiguos siguen usando el
  fallback existente. Backup, restore y privacidad incluyen las dos columnas nuevas.
- **Coste:** se añaden dos columnas y un calculador puro; cada respuesta reconstruye el intento
  actual leyendo sus filas evaluables. Los metadatos viajan en el mismo upsert que la nota
  (`attempts::record_item()`), sin lectura ni escritura adicionales.
- **Límite conocido:** un intento que nunca contesta todos los iDevices evaluables no obtiene nota
  reconstruida y se queda con el `finalScore` del paquete, es decir con el comportamiento previo a
  este ADR. Es el peor caso aceptado: nunca es peor que hoy y nunca infla. Aprender el peso a nivel
  de `exelearning_grade_item` (una vez por paquete, compartido entre usuarios) permitiría reconstruir
  también intentos parciales; queda como seguimiento, fuera del alcance de este PR.
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

## Validación

- Tests unitarios del normalizador para metadatos válidos, ausentes, nulos, parciales, fuera de
  rango (acotados), de tipo incorrecto (rechazados) y mapa de `extensions` malformado (degradado).
- Test puro del cálculo para 25/75, empate largest remainder y filas inválidas.
- Tests de integración para dos páginas (100×25 y 40×75 = 55), múltiples iDevices, re-answer,
  persistencia y regresión de statements de paquete.
- Tests de integración para intento parcial (no infla el OVERALL y conserva el fallback), `answered`
  posterior a un statement terminal (conserva el estado y no reemite `attempt_completed`), iDevice
  borrado (deja de pesar) y `grademin` (el OVERALL reconstruido se acota igual que el del paquete).
- Tests de backup/restore y privacidad para `xapiweight`/`xapiorder`.
- Suite completa del plugin, Moodle CodeSniffer, validación XMLDB y `architecture-check`.

## Seguimiento

- Mantener el PR 121 en borrador y bloqueado hasta la fusión de upstream #2302.
- Evaluar aprender `weight`/`idevice-order` en `exelearning_grade_item` (una vez por paquete) para
  reconstruir también intentos parciales, en lugar de esperar a que el intento reporte el paquete
  entero.
- Una vez disponible el contrato en una release de eXeLearning, validar un paquete real multipágina
  de extremo a extremo en Moodle y retirar el bloqueo de merge.
