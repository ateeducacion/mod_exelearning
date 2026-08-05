---
id: DEC-0-07
tracking_issue: 0
legacy_id: DEC-0007
title: "Gestión de intentos: tabla propia + modelo h5pactivity (no SCORM)"
status: Accepted
date: 2026-05-28
deciders:
  - erseco
  - claude-code
sources:
  - REPO-004
  - FTE-001
  - FTE-003
  - FTE-004
  - FTE-006
experiments: []
ai_assistance:
  tool: claude-code
  model: claude-opus-4-7
---

# DEC-0-07: Gestión de intentos: tabla propia + modelo h5pactivity (no SCORM)

## Contexto

Las actividades calificables de Moodle permiten al alumno **realizar varios
intentos** y elegir cómo se agregan al gradebook (`maxattempt`, `whatgrade`,
`forcenewattempt`, `lastattemptlock`, `browse`/`review` modes). `mod_exelearning`
hoy registra **un único intento** que sobrescribe sobre `mdl_grade_grades`.
El usuario solicita el sistema completo.

## Problema

¿Qué modelo de intentos adopta `mod_exelearning`? Hay tres familias de
referencia en Moodle core y diferencias significativas entre ellas.

## Opciones consideradas

| # | Modelo | Tabla(s) | Pros | Contras |
|---|---|---|---|---|
| 1 | **SCORM**: `mod_scorm_attempt` + `mod_scorm_scoes_value` + `mod_scorm_element` | 3 tablas anidadas | Granularidad CMI completa; integra con sequencing/forcenewattempt/whatgrade | Modelo CMI heredado, pensado para SCO multi-página; coste alto si no usamos el data model SCORM. Esquema complejo (4+ joins para una nota). |
| 2 | **h5pactivity**: `mod_h5pactivity_attempt` + `mod_h5pactivity_attempts_results` | 2 tablas planas | Sencillo, alineado con xAPI nativo; campos directos `rawscore`, `maxscore`, `scaled`, `duration`, `completion`, `success` | Sólo single-grade (no multi-itemnumber por naturaleza). Hay que extender para nuestro caso N items por intento. |
| 3 | **Workshop**: `mod_workshop_submissions` + `_assessments` | 2 tablas | Multi-grade-items (submission/assessment) | Diseñado para revisión por pares, no para intentos secuenciales del mismo alumno. |
| 4 | **Tabla propia híbrida** (mezcla h5pactivity + multi-item) | `exelearning_attempt` (cabecera) + `exelearning_attempt_item` (detalle por iDevice) | Ajuste perfecto al modelo SCORM 1.2 + multi-itemnumber que ya usamos; integra natural con `mdl_exelearning_grade_item` | Más código a mantener (CRUD + UI report) |

## Evidencia

- `mod/scorm/db/install.xml`: tabla `scorm_attempt` con `userid, scormid, attempt, timestarted, timefinished, lastmodified`; `scorm_scoes_value` con `attemptid, elementid, value`; `scorm_element` glosario (REPO-004).
- `mod/h5pactivity/db/install.xml`: tabla `h5pactivity_attempts` (cols `id, h5pactivityid, userid, attempt, rawscore, maxscore, scaled, duration, completion, success`) + `h5pactivity_attempts_results` para detalle (REPO-004).
- `mod/scorm/lib.php`: `scorm_get_grade_method_array` define 4 métodos
  (`GRADEHIGHEST`, `GRADEAVERAGE`, `GRADESUM`, `GRADESCOES`); `scorm_grade_user`
  itera intentos según `whatgrade`.
- `mod/h5pactivity/classes/local/grader.php`: define `gradeMethod` con 4
  opciones (highest/average/last/first) sin tocar la complejidad de CMI.

## Decisión propuesta

**Opción 4** (tabla propia híbrida) con la **semántica de h5pactivity adaptada
a multi-itemnumber**, no la de SCORM. Razones:

1. Ya tenemos `mdl_exelearning_grade_item` (mapa objectid → itemnumber).
   Reusarlo en `mdl_exelearning_attempt_item` es natural.
2. El modelo xAPI (DEC-0-03) es de granularidad fina por statement; encaja con
   "una fila por iDevice por intento", no con "un blob CMI por SCO".
3. Evitamos arrastrar el data model CMI de SCORM (255-char strings, glosario de
   `cmi.suspend_data`, etc.).
4. SCORM `whatgrade` queda como tributario: lo replicamos como
   `mod_exelearning.grademethod` con 4 valores idénticos a h5pactivity.

### Esquema (propuesto)

```sql
-- Cabecera por intento del alumno.
mdl_exelearning_attempt (
    id              bigint(10) PK,
    exelearningid   bigint(10) FK → exelearning.id,
    userid          bigint(10) FK → user.id,
    attempt         int(10),               -- monotónico por (exelearningid, userid)
    timestarted     bigint(10) NOTNULL,
    timefinished    bigint(10) NULL,
    completion      varchar(20),           -- 'incomplete' | 'completed'
    success         varchar(20) NULL,      -- 'passed' | 'failed' | NULL
    rawscore        decimal(10,5) NULL,    -- score agregado (overall)
    maxscore        decimal(10,5) NULL,
    scaled          decimal(10,5) NULL,    -- 0..1
    duration        bigint(10) NULL,       -- seconds
    INDEX (exelearningid, userid, attempt UNIQUE)
);

-- Detalle por iDevice dentro de un intento.
mdl_exelearning_attempt_item (
    id              bigint(10) PK,
    attemptid       bigint(10) FK → exelearning_attempt.id,
    itemnumber      int(10) NOTNULL,       -- coincide con exelearning_grade_item.itemnumber
    objectid        varchar(191),          -- copia denormalizada para consultas rápidas
    rawscore        decimal(10,5),
    maxscore        decimal(10,5),
    scaled          decimal(10,5),
    completion      varchar(20),
    success         varchar(20),
    timemodified    bigint(10),
    INDEX (attemptid, itemnumber UNIQUE),
    INDEX (attemptid, objectid)
);
```

### Settings en `mod_form.php` (heredados de h5pactivity)

| Setting | Tipo | Valores |
|---|---|---|
| `maxattempt` | int | `0` (ilimitado) o `1..N` |
| `grademethod` | select | `last` (default), `first`, `highest`, `average` |
| `reviewmode` | select | `always`, `none`, `aftercompletion` (controla si el alumno ve sus respuestas) |
| `displayoptions` | text | bitmask para flags UI |

NO incluimos por simplicidad (vs SCORM):

- `forcecompleted` (xAPI ya distingue).
- `forcenewattempt` (la decisión la toma el alumno con botón "Nuevo intento").
- `lastattemptlock` (se infiere de `maxattempt` alcanzado).
- `whatgrade` SCORM (es `grademethod` en nuestro modelo).

### Flujo en `track.php`

1. Identificar `$attempt = current_attempt($exelearningid, $userid)`. Si no
   existe o el último está finalizado, crear nuevo `attempt` row.
2. Por cada par `(itemnumber, scorepct)` del `cmi.suspend_data`:
   - Upsert en `exelearning_attempt_item`.
3. Actualizar fila de `exelearning_attempt` con scores agregados.
4. Si `cmi.core.lesson_status = passed|failed` (o `cmi.completion_status = completed`),
   marcar `attempt.timefinished = time()`.
5. Re-calcular nota efectiva via `grademethod` (highest, last, etc.) y emitir
   `grade_update` por cada `itemnumber`.

### Vista del profesor

Nueva página `report.php?id=<cmid>` con:
- Tabla alumno × intento (cada celda muestra `scaled` y enlace al detalle).
- Detalle de intento expande `attempt_item` → score por iDevice.
- Filtros por estado (incompleto/completado/aprobado/suspendido).
- Botón "Borrar intento" (capability `mod/exelearning:deleteresponses`).

### Modos (extensión de DEC-0-06)

- `mode=grading` → crea/incrementa intento.
- `mode=preview` → NO toca la tabla `attempt` (igual que ahora con
  `grade_grades`).
- `mode=review` (futuro) → muestra un intento histórico read-only (sin guardar
  cambios nuevos).

## Consecuencias

Positivas:
- Trazabilidad completa del aprendizaje (cuándo intentó, qué iDevice falló).
- Permite informes pedagógicos (cuántos intentos para aprobar, qué iDevices
  cuestan más).
- Coherente con xAPI futuro: cada `attempt_item` es un statement traducido.

Negativas:
- Coste de implementación: ~600 LOC PHP (CRUD + lib.php + report.php +
  privacy provider + backup/restore).
- Migración: las filas históricas de `mdl_grade_grades` que ya guardamos sin
  attempt requieren backfill (asignarlas como `attempt=1`).

## Riesgos

- RIE-004: si el alumno abre la actividad en dos pestañas a la vez, podría
  crear dos intentos simultáneos. Mitigación: lock por `(userid, exelearningid)`
  con `set_user_preference('mod_exelearning_active_attempt', $id)`.

## Validación

- Crear actividad con `maxattempt=3` y `grademethod=highest`.
- Alumno hace 3 intentos: 60, 80, 70 → gradebook muestra 80.
- Cuarto intento bloqueado por UI.
- Profesor ve los 3 intentos en `report.php`, puede borrar el #2 → recalculo
  automático devuelve 70 al gradebook.

## Actualización — Implementado (2026-05-28, claude-opus-4-8)

Estado → **Aceptada**. Implementado con dos simplificaciones pragmáticas sobre
el esquema propuesto:

1. **Una sola tabla plana `exelearning_attempt`** (no cabecera + detalle). Cada
   fila es `(exelearningid, userid, attempt, itemnumber)` con `rawscore`,
   `maxscore`, `scaledscore`, `status`, `sessiontoken`. El `itemnumber=0` es el
   overall y los `>0` los iDevices — reusa el mismo eje que
   `exelearning_grade_item`. Evita el JOIN cabecera↔detalle del diseño original
   manteniendo la misma información.
2. **Agrupación de intentos por `sessiontoken`** en vez de "intento = recarga".
   `view.php` genera un token aleatorio por carga de página y el shim lo manda
   en cada auto-commit; `track.php` mapea todos los commits de esa carga al
   mismo número de intento (upsert por `(…, attempt, itemnumber)`). Así los
   múltiples commits con debounce de 500 ms NO inflan el contador de intentos.

`grademethod` se implementó como `int` 0..4 (highest/average/first/last/lowest)
en `mod_exelearning\local\attempts` y en el `mod_form`. La nota del libro de
cada `itemnumber` es la agregación del histórico de intentos según ese método
(`attempts::aggregate_scaled`).

Ficheros: `classes/local/attempts.php`, `db/{install.xml,upgrade.php}`
(`exelearning_attempt` + `gradepass` + `grademethod`), `track.php`, `view.php`,
`mod_form.php`, `report.php`, `classes/privacy/provider.php`,
`backup/moodle2/*`, `lang/en/exelearning.php`. Finalización por aprobado en
[[DEC-0-10]].

**Fase 2 (misma sesión)**: implementados `maxattempt` (límite de intentos,
0=ilimitado, enforced en `track.php`), `reviewmode`
(none/always/aftercompletion, resumen al alumno en `view.php`) y **borrar
intento** en `report.php` (capability `mod/exelearning:deleteattempt` +
`exelearning_recalculate_user_grades()`). Campos nuevos en la instancia:
`maxattempt`, `reviewmode` (version 2026052803).

## Seguimiento

- DONE TAREA-023: esquema + lib + track.php.
- DONE TAREA-024: `report.php` con tabla de intentos.
- DONE TAREA-025: `classes/privacy/provider.php`.
- DONE TAREA-026: backup/restore con attempts.
- PENDIENTE: `maxattempt` + `reviewmode` + borrar intento (UI report).
- AN-010 (futura): comparativa cuantitativa de las 4 opciones con esfuerzo
  estimado por feature.
