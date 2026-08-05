---
id: DEC-0011
titulo: "Presentación de intentos en la portada de la actividad: resumen SCORM, resumen-profesor estilo Tarea, o valor estilo H5P"
estado: Aceptada
fecha: 2026-05-28
agentes:
  - erseco
  - claude-code
fuentes:
  - REPO-004
relacionados:
  - DEC-0006
  - DEC-0007
  - DEC-0008
  - DEC-0010
herramienta_ia:
  interfaz: claude-code
  modelo: claude-opus-4-8
---

# DEC-0011: Presentación de intentos en la portada de la actividad: resumen SCORM, resumen-profesor estilo Tarea, o valor estilo H5P

## Contexto

Tras implementar intentos (DEC-0007), `mod_exelearning` ya tiene:

- Un **bloque al alumno** en `view.php`: aviso "Intentos: X de Y" (o "Intentos
  usados: X") y, según `reviewmode`, un desplegable con la nota de cada intento
  previo.
- Una **pestaña "Informes"** (`report.php`, DEC ya integrada): tabla
  profesor con *Usuario × Intento × Ítem × Nota × Estado × Fecha* + "Borrar
  intento".

El mantenedor observa que **mod_scorm** muestra, en la portada de la actividad,
un panel distinto (captura aportada):

```
Número de intentos permitidos: Sin límite
Número de intentos realizados: 1
Calificación del intento 1: 50%
Método de calificación: Intento más alto
Calificación informada: 50%
[Borrar todos los intentos SCORM]
```

Y pregunta qué patrón debe seguir `mod_exelearning`: (A) el resumen por-alumno
de SCORM, (B) un resumen agregado para el profesor estilo Tarea ("cuántos han
contestado"), o (C) el valor inline mínimo estilo H5P (lo que ya hay).

## Problema

¿Qué se muestra en la **portada** de la actividad (`view.php`), para **alumno**
y para **profesor**, respecto a intentos y resultados, de forma coherente con
los patrones del core de Moodle y con el hecho de que en `mod_exelearning` el
contenido se renderiza **inline en un iframe** (no hay pantalla de lanzamiento
separada como en SCORM)?

## Evidencia: patrones del core (REPO-004)

| Módulo | Portada de la actividad | Público | Naturaleza |
|---|---|---|---|
| `mod_scorm` | Tabla "intentos permitidos / realizados / nota por intento / método / nota informada" + borrar. La portada **es** la pantalla de lanzamiento; el SCO se abre con "Entrar". | Alumno (su histórico) | Resumen por-alumno en portada |
| `mod_assign` | Tabla "Resumen de calificación": participantes, entregados, pendientes de calificar, fecha límite, tiempo restante. | Profesor | Resumen agregado del grupo |
| `mod_quiz` | "Resumen de tus intentos previos" (tabla con nota por intento) + nota final + nº intentos permitidos. | Alumno | Resumen por-alumno en portada |
| `mod_h5pactivity` | Renderiza el contenido; los resultados del alumno y el detalle por intento viven en el **Informe**. Vista mínima en portada. | Alumno mínimo / profesor en Informe | Valor inline + report |

Observación clave: SCORM y Quiz ponen el **resumen por-alumno en la portada
PORQUE la portada no muestra el contenido** (hay un paso "Entrar"/"Intentar
ahora"). En `mod_exelearning`, igual que en H5P, **el contenido se ve inline**:
meter una tabla grande de intentos por encima del iframe empuja el contenido
hacia abajo y compite con él.

## Opciones consideradas

### A. Resumen por-alumno estilo SCORM en la portada

Un panel sobre el iframe con: intentos permitidos, intentos realizados, nota por
intento, método de calificación (`grademethod`), nota informada, y botón
"Reiniciar / nuevo intento".

| ✔ Pros | ✘ Contras |
|---|---|
| Familiar para quien viene de SCORM; transparencia total de la nota. | Diseñado para portadas SIN contenido inline; aquí empuja el iframe hacia abajo. |
| Expone `grademethod` y `maxattempt` que ya tenemos (DEC-0007). | Redundante con el desplegable de revisión que ya mostramos al alumno. |
| Cubre el caso "¿por qué tengo esta nota?". | No responde la pregunta del profesor ("cuántos han contestado"). |

### B. Resumen agregado para el profesor estilo Tarea ("cuántos han contestado")

Un banner visible **sólo al profesor** en la portada: nº de alumnos
matriculados, cuántos han hecho ≥1 intento, % de participación, nota media, y
enlace a "Informes" para el detalle.

| ✔ Pros | ✘ Contras |
|---|---|
| Responde la necesidad real del profesor de un vistazo, sin entrar al Informe. | Más consultas a BD en cada carga de la portada (contar intentos/alumnos). |
| Coherente con `mod_assign` (patrón establecido y reconocible). | Hay que respetar grupos/agrupamientos (separate groups) para el conteo. |
| Reutiliza datos de `exelearning_attempt` que ya existen. | Duplica parcialmente lo que el Informe ya da (pero resumido). |

### C. Valor inline mínimo estilo H5P (estado actual)

Lo que ya hay: al alumno, "Intentos: X de Y" + revisión opcional; al profesor,
pestaña "Informes". Nada agregado en portada.

| ✔ Pros | ✘ Contras |
|---|---|
| Mínima fricción visual; el contenido inline manda. | El profesor no ve participación sin entrar al Informe. |
| Ya implementado y verificado. | Menos "rico" que SCORM para el alumno (no muestra método/nota informada en portada). |
| Coherente con H5P, el módulo más parecido a nosotros. | — |

## Decisión propuesta

**C como base + B para el profesor** (híbrido), evitando A.

Concretamente:

1. **Alumno (mantener C, pulir hacia SCORM sin tabla):** conservar el bloque
   inline actual, añadiendo en una sola línea —sólo si la actividad es
   calificable— el *método de calificación* y la *nota informada* (lo útil de la
   captura SCORM) sin reproducir la tabla completa de SCORM. El detalle de
   intentos sigue en el desplegable de revisión (gated por `reviewmode`,
   DEC-0007).
2. **Profesor (añadir B):** banner agregado en la portada visible con
   `mod/exelearning:viewreport`: "N de M estudiantes han realizado intentos ·
   media X" + botón "Ver informe". Respeta grupos. Es el "resumen estilo Tarea"
   que pide el mantenedor.
3. **Descartar A** como panel completo en portada: su tabla está pensada para
   una pantalla de lanzamiento sin contenido; aquí el iframe ya ocupa la
   portada. La información rica por-alumno e por-intento vive en "Informes".

Razón: nuestro módulo se parece a **H5P** (contenido inline), no a SCORM
(lanzamiento aparte), así que el patrón base correcto es C; y la pregunta
operativa del profesor ("cuántos han contestado") la resuelve el patrón de
**Tarea** (B), barato porque los datos ya están en `exelearning_attempt`.

## Consecuencias

Positivas:
- El profesor ve participación de un vistazo sin entrar al Informe.
- El alumno mantiene una portada limpia con su nota/método visibles.
- Cero tablas redundantes; el detalle exhaustivo queda en "Informes".

Negativas:
- +1/+2 consultas agregadas por carga de portada para el profesor (mitigable con
  un único `GROUP BY` y caché de request).
- Hay que manejar grupos separados en el conteo para no filtrar datos entre
  grupos.

## Validación (si se acepta)

- Profesor en `EXEDEMO` ve "1 de 2 estudiantes han realizado intentos · media
  …" en la portada de la actividad y el botón a Informes.
- Alumno ve su línea "Intentos: X de Y · método: intento más alto · nota: 50".
- Con grupos separados, el conteo del profesor refleja sólo su grupo.

## Seguimiento

- TAREA-032: banner agregado de profesor en `view.php` (patrón B) + helper de
  conteo en `classes/local/attempts.php` respetando grupos.
- TAREA-033: enriquecer la línea del alumno con método + nota informada.
- (Descartado) panel-tabla SCORM completo en portada.
