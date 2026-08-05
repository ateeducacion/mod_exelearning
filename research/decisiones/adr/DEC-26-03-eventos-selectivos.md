---
id: DEC-26-03
title: "Eventos selectivos de trazabilidad (sin ruido de tracking)"
status: Accepted
date: 2026-06-09
tracking_issue: 26
legacy_id: DEC-0041
deciders:
  - erseco
  - claude-code
sources:
  - REPO-001
  - REPO-002
related:
  adrs: [DEC-0-07]
ai_assistance:
  tool: claude-code
  model: claude-opus-4-8
---

# DEC-26-03: Eventos selectivos de trazabilidad (sin ruido de tracking)

## Contexto

`mod_exelearning` declaraba **un solo evento** (`course_module_viewed`), frente a los
11 de `mod_exescorm` y 2 de `mod_exeweb` (verificado). El encargo pide ampliar la
Events API **solo donde aporte valor real** de auditoría/analítica, sin añadir
eventos por añadir.

## Decisión

Añadir **tres** eventos, elegidos por valor de auditoría y coste bajo:

| Evento | crud | edulevel | Disparo |
|---|---|---|---|
| `attempt_deleted` | d | TEACHING | `report.php` al borrar un intento (acción destructiva del docente) |
| `report_viewed` | r | TEACHING | `report.php` al abrir el informe de intentos (acceso del docente a datos del alumno) |
| `course_module_instance_list_viewed` | r | — | `index.php` (estándar de módulo, hoy ausente) |

`attempt_deleted` no tiene `objecttable` de una sola fila (un "intento" abarca varias
filas de `exelearning_attempt`): el número de intento y el alumno viajan en
`other['attemptid']` y `relateduserid`, con `validate_data()` que lo exige.

### Descartes (justificación)

- **Tracking guardado / score enviado por commit**: el shim hace auto-commit cada
  500 ms (DEC-0-07); un evento por commit **inundaría** el log. No se añade. La señal
  útil (un intento) ya vive en `exelearning_attempt` y en el gradebook.
- **Editor abierto / actualizado, paquete exportado por docente**: bajo valor de
  auditoría frente al coste; no se añaden.
- **Paquete subido/actualizado**: ya hay trazabilidad vía `\core\event\course_module_updated`
  del núcleo y `revision`; un evento propio sería redundante.

## Consecuencias

- Trazabilidad de las dos acciones de docente relevantes (borrado de intento y acceso
  al informe) y del índice del curso, sin ruido en el log.
- Los eventos siguen las convenciones de Moodle (contexto correcto, snapshots cuando
  aplican, `get_objectid_mapping` para backup/restore) y no exponen datos sensibles.

## Implementación

- `classes/event/attempt_deleted.php`, `report_viewed.php`,
  `course_module_instance_list_viewed.php`.
- Cableado: `report.php` (borrado → `attempt_deleted`; vista → `report_viewed`, tras
  el redirect de borrado para no duplicar), `index.php` (`instance_list_viewed`).
- `lang/en/exelearning.php`: `eventattemptdeleted`, `eventreportviewed`.
- Tests: `tests/events_test.php` (crud/edulevel/contexto/descripción, validación de
  `attemptid` obligatorio). Suite completa 98/98 verde.
