---
id: DEC-12-01
title: "Edición de contenido calificable: semántica snapshot + aviso al profesor (estilo SCORM)"
status: Accepted
date: 2026-06-02
tracking_issue: 12
legacy_id: DEC-0021
deciders:
  - erseco
  - claude-code
sources:
  - FTE-010
  - FTE-006
  - REPO-004
related:
  adrs: [DEC-0-07, DEC-0-08, DEC-0-12, DEC-5-01, DEC-6-01]
ai_assistance:
  tool: claude-code
  model: claude-opus-4-8
---

# DEC-12-01: Edición de contenido calificable: semántica snapshot + aviso al profesor (estilo SCORM)

## Contexto

Cuando un profesor edita un `.elpx` que **ya tiene intentos/notas** de alumnos (desde el
editor embebido → `editor/save.php`, o re-subiendo por el formulario →
`exelearning_update_instance`), el conjunto de tareas calificables puede cambiar de tres
formas: **añadir** una tarea, **borrar** una, o **editar las opciones** de una existente
(respuesta correcta, puntuación, número de ítems). La pregunta: ¿qué hace hoy el plugin,
qué hace el core de Moodle, y qué debería hacer?

El enlace nota↔tarea es `objectid` (`<odeIdeviceId>` estable de `content.xml`) →
`itemnumber` (columna del gradebook) → filas `exelearning_attempt`. El `objectid` se
preserva entre exports tras el fix upstream exelearning#1791 (DEC-0-12/RIE-006).
Detección: `classes/local/package.php::detect_gradable_idevices()`. Sincronización:
`lib.php::exelearning_sync_grade_items()`.

## Problema

El comportamiento actual por escenario, **antes** de esta ADR:

| Escenario | Comportamiento | Veredicto |
|---|---|---|
| **Añadir** tarea | nuevo `objectid` → nuevo `itemnumber` → columna nueva, sin notas | OK, paridad con core |
| **Borrar** tarea | soft-delete (`deleted=1`), columna oculta, intentos+historial **preservados** | OK, **mejor que SCORM** (que destruye tracks) |
| **Editar opciones** (mismo `objectid`) | mismo `itemnumber`, notas viejas siguen enlazadas, `scaledscore` es del contenido **viejo**. **No se detecta, no se avisa, no se puede recalcular** | **Hueco** (= mod_h5pactivity, el modelo más débil) |

El hueco real es **editar opciones**: el plugin no guardaba ninguna huella del contenido
del iDevice, así que un cambio de respuesta/puntuación pasaba inadvertido y la nota previa
(calculada contra el contenido viejo) persistía silenciosamente, pudiendo ser engañosa
(p.ej. con `grademethod=highest` una nota vieja alta domina).

## Opciones consideradas

1. **Aceptar paridad (solo documentar).** Igual que mod_h5pactivity: conservar,
   no recalcular, no avisar. Coste cero. *Contra:* deja el hueco de la nota obsoleta sin
   ninguna señal al profesor; es el modelo más débil del core (FTE-010).
2. **Recálculo estilo quiz.** *Imposible:* eXeLearning puntúa en el **cliente** y el
   servidor solo guarda el `scaledscore` ya calculado (`track.php`). No se puede
   re-derivar la nota de un intento pasado contra el contenido nuevo (FTE-010, Riesgos).
3. **Snapshot + aviso al profesor (estilo SCORM).** Mantener la semántica snapshot
   (no recalcular) y, cuando al guardar el conjunto calificable **cambia** y **existen
   intentos**, **avisar** al profesor de que las notas previas no se recalculan,
   apuntándole al reset de intentos que ya existe. Para detectar "editar opciones" se
   añade un **hash de contenido por iDevice**. *Pro:* iguala el mejor aviso del core
   (SCORM `confirmloosetracks`), bajo coste, sin migración disruptiva. *Contra:* el hash
   puede dar falsos positivos si el export reordena/reescribe bytes (mitigable y, al ser
   el aviso **informativo y no bloqueante**, tolerable).
4. **Hash + invalidar/resetear automáticamente.** Como (3) pero marcando intentos como
   obsoletos o reseteándolos. *Contra:* destruye datos del alumno sin intervención
   docente; se aleja de la semántica snapshot del core. Descartado por ahora.

## Evidencia

- **Core (FTE-010, verificado en Moodle 5.0.1):** mod_scorm preserva por `identifier`,
  **borra** tracks de SCOs eliminados, nunca recalcula y **avisa** (`confirmloosetracks`);
  mod_h5pactivity preserva, nunca recalcula, **sin versionado ni aviso**; mod_quiz fija la
  versión por intento y recalcula **opt-in** con dry-run. Principio común: el intento es
  un snapshot; no se cambian notas en silencio.
- **Restricción del plugin:** scoring client-side, servidor solo persiste `scaledscore`
  (`track.php`, `classes/local/attempts.php::record_item`). Recálculo servidor inviable.
- **Pregunta abierta de FTE-006** ("política de re-upload") apuntaba ya a esta DEC.

## Decisión

**Opción 3.** Se mantiene la **semántica snapshot** y se añade un **aviso al profesor
estilo SCORM**.

1. **Hash de contenido por iDevice.** Nuevo campo `contenthash CHAR(40)` en
   `exelearning_grade_item` (install.xml + upgrade stage 10, version `2026060102`).
   `detect_gradable_idevices()` calcula `sha1` del bloque de `content.xml` de cada
   iDevice (de su `<odeIdeviceId>` al siguiente token), **ignorando metadatos volátiles**
   (`*Date*`/`*modified*`/`*timestamp*`) para no disparar por timestamps de export.
2. **Delta en el sync.** `exelearning_sync_grade_items()` pasa a devolver
   `array{added,removed,changed}`. `changed` = mismo `objectid` con `contenthash` distinto
   (o reaparición de un item `deleted`). Filas pre-upgrade con hash `NULL` se rellenan en
   silencio (no cuentan como cambio) para no avisar en la primera sync tras el upgrade.
3. **Aviso condicionado.** `exelearning_warn_if_grades_stale($exeid, $delta, $cmid)` encola
   `\core\notification::warning(gradesetchangedwarning)` **solo si** el delta es no vacío
   **y** `attempts::activity_has_attempts()`. Enlaza al informe de intentos. Se invoca en
   `editor/save.php` (la notificación aparece en el `reload` del editor) y en
   `exelearning_update_instance()` (en el redirect del formulario). **No bloquea** el guardado.
4. **Remediación = la que ya existe.** El profesor borra intentos en `report.php`
   (capability `mod/exelearning:deleteattempt`), que recalcula vía
   `exelearning_recalculate_user_grades()` (DEC-0-07). No se añade reset automático.

## Consecuencias

- Positivas: cierra el hueco de la nota obsoleta con una señal clara; iguala el mejor
  aviso del core (SCORM) y supera a h5pactivity (que no avisa); mantiene la semántica
  snapshot del core; migración aditiva (una columna nullable), sin tocar intentos
  existentes; sin cambios en código vendorado (DEC-0-02) ni en `amd/*` (el aviso usa la
  cola de notificaciones del core, sin JS nuevo).
- Negativas / coste: el `contenthash` puede dar **falsos positivos** si un re-export
  reescribe el bloque sin cambio semántico → un aviso informativo de más (no bloquea, no
  altera notas). Si resultan ruidosos, se afina la región hasheada (RIE-012).
- Dispara: TAREA-013 (seguimiento de RIE-012).

## Riesgos

- **RIE-012 — nota obsoleta al editar opciones.** Severidad media, probabilidad media,
  estado **mitigado** (aviso + reset manual). El recálculo automático no es viable
  (scoring client-side); el residuo es el falso positivo/negativo del hash. Ver TAREA-014.

## Validación

- PHPUnit `tests/package_test.php`: el `contenthash` es estable, cambia solo en el iDevice
  editado, ignora timestamps; add/remove se reflejan en la detección.
- PHPUnit `tests/lib_test.php`: el sync persiste el hash; re-sync sin cambios da delta
  cero; un hash divergente da `changed=1` y se refresca; `activity_has_attempts()`;
  `exelearning_warn_if_grades_stale()` avisa solo con cambio **y** intentos.
- `php -l` en verde; `xmllint` valida `db/install.xml`.
- Suite completa + phpcs delegados a CI (moodle-plugin-ci, DEC-0-04).
- e2e/manual: subir `.elpx` con una tarea calificable, hacer un intento, editar la opción
  y guardar → aviso al recargar; la nota previa no cambia; el reset de intentos la limpia.

## Seguimiento

- TAREA-013 (RIE-012): observar falsos positivos del `contenthash` en paquetes reales;
  afinar la región hasheada si el ruido lo justifica.
