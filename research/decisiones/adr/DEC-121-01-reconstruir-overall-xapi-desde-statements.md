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
- La tabla `exelearning_attempt` ya representa el último estado de cada `(actividad, usuario,
  intento, itemnumber)`: una nueva respuesta actualiza la misma fila, por lo que no hay que sumar
  el historial de statements.
- El listener xAPI vive en el documento Moodle exterior y ya reenvía statements completos aunque
  el iframe navegue entre páginas; no requiere persistencia JavaScript nueva.

## Decisión

1. Aceptar el contrato de `exelearning/exelearning#2302` sólo cuando `weight` e `idevice-order`
   estén presentes juntos y sean números JSON válidos. Un contrato parcial o inválido se rechaza.
2. Añadir `xapiweight` y `xapiorder` como columnas nullable de `exelearning_attempt`. Las filas
   SCORM, las filas OVERALL y los paquetes xAPI anteriores quedan a `NULL`.
3. Usar la fila actual por `itemnumber` como mapa de último estado. Recontestar un iDevice reemplaza
   score y metadatos; nunca se acumula el historial.
4. Calcular el OVERALL con la misma normalización de pesos, reparto largest remainder, desempate por
   `idevice-order` y redondeo de eXeLearning. El orden de llegada de statements no participa.
5. Persistir y publicar un OVERALL `incomplete` después de cada `answered` nuevo. Los statements
   `completed`, `passed` y `failed` actualizan el estado de ciclo de vida sin sustituir el número
   reconstruido.
6. Si un intento no contiene filas con el nuevo contrato, conservar el score del statement de
   paquete como fallback compatible de [[DEC-85-01]]. No se cambia SCORM.
7. No fusionar el PR Moodle #121 antes de que el PR upstream #2302 esté fusionado; el consumidor no
   debe preceder al productor del contrato.

## Consecuencias

- **Positivas:** Moodle conserva contribuciones entre páginas, obtiene el mismo resultado ponderado
  que eXeLearning, reemplaza correctamente respuestas repetidas y deja de tratar un agregado
  cliente efímero como única autoridad.
- **Compatibilidad:** los campos xAPI son aditivos y nullable; paquetes antiguos siguen usando el
  fallback existente. Backup, restore y privacidad incluyen las dos columnas nuevas.
- **Coste:** se añaden dos columnas y un calculador puro; cada respuesta reconstruye el intento
  actual leyendo sus filas evaluables.
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

- Tests unitarios del normalizador para metadatos válidos, ausentes, parciales, fuera de rango y de
  tipo incorrecto.
- Test puro del cálculo para 25/75, empate largest remainder y filas inválidas.
- Tests de integración para dos páginas (100×25 y 40×75 = 55), múltiples iDevices, re-answer,
  persistencia y regresión de statements de paquete.
- Tests de backup/restore y privacidad para `xapiweight`/`xapiorder`.
- Suite completa del plugin, Moodle CodeSniffer, validación XMLDB y `architecture-check`.

## Seguimiento

- Mantener #121 en borrador y bloqueado hasta la fusión de upstream #2302.
- Una vez disponible el contrato en una release de eXeLearning, validar un paquete real multipágina
  de extremo a extremo en Moodle y retirar el bloqueo de merge.
