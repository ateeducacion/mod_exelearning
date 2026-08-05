---
id: AN-012
titulo: "Mapeo de statements xAPI (exe_xapi.js) sobre la tubería de tracking existente"
fecha: 2026-06-04
fuentes:
  - REPO-004
  - REPO-005
  - FTE-003
  - FTE-007
  - FTE-011
relacionados:
  - DEC-0007
  - DEC-0014
  - DEC-5-01
  - DEC-6-01
  - DEC-17-01
herramienta_ia:
  interfaz: claude-code
  modelo: claude-opus-4-8
---

## Resumen

El "modelo interno común" para tracking dual **ya existe** en mod_exelearning: la tabla
**plana** `exelearning_attempt` (eje `itemnumber` 0..N + `sessiontoken`) más
`classes/local/track.php` + `attempts.php` + `package.php`. Un statement xAPI de
`exe_xapi.js` (FTE-011) se normaliza a la **misma** estructura `itemscores` que el shim
SCORM ya produce y se inyecta en `track::apply_item_scores()` / `attempts::record_item()`
/ `grade_update()`. No hace falta un modelo paralelo ni tablas nuevas de cabecera+detalle.

## Hechos citados

- **La tubería actual es ya un punto de inyección único.** `track.php:177-281` recibe
  `itemscores = { objectid: { scorepct(0..100), weighted, title } }`, rutea por
  `objectid → itemnumber` (`track::apply_item_scores`, DEC-5-01), recalcula el overall
  (`track::recompute_overall_pct`, DEC-6-01), graba intentos
  (`attempts::record_item`), agrega por `grademethod` (`attempts::aggregate_scaled`,
  DEC-0007) y publica con `grade_update(... itemnumber ...)`. El shim de `view.php:498-528`
  sólo **construye** ese `itemscores` leyendo el DOM del iframe (`captureItemScores`,
  `view.php:449-461`).
- **`exelearning_attempt` es plano por decisión.** DEC-0007 (líneas 176-186): se
  implementó "una sola tabla plana `exelearning_attempt` (no cabecera + detalle)", con
  `itemnumber=0` overall y `>0` por iDevice, reusando el mismo eje que
  `exelearning_grade_item`. DEC-0007:154 ya anticipaba xAPI: "cada `attempt_item` es un
  statement traducido".
- **El statement xAPI trae lo necesario para ese mapeo** (FTE-011): `object.id =
  {baseIri}/idevice/{ideviceId}` con `ideviceId == objectid`; `result.score.scaled`
  (0..1); verbos `answered` (por iDevice) y `completed`/`passed`/`failed` (paquete, con el
  overall ya ponderado); `context.extensions[package-id] = odeId`;
  `context.registration`.
- **El host es autoritativo sobre la identidad.** El emisor pone `actor` anónimo y espera
  que el host lo sobrescriba (`exe_xapi.js:431-447`).

## Tabla de mapeo (statement xAPI → modelo interno)

| Campo xAPI (FTE-011) | Modelo interno existente |
|---|---|
| `actor` (anónimo por defecto) | **IGNORADO** → servidor usa `$USER` (sin confiar PII) |
| `verb = initialized` | inicio de sesión/intento (vía `context.registration` ↔ `sessiontoken`) |
| `verb = answered` (por iDevice) | score por item → `track::apply_item_scores` |
| `verb = completed` | `completion` del overall (`itemnumber=0`) |
| `verb = passed` / `failed` | `status` passed/failed + `success` del overall |
| `verb = terminated` | fin de intento (cierre de sesión) |
| `object.id = …/idevice/{ideviceId}` | `ideviceId → exelearning_grade_item.objectid → itemnumber` (DEC-5-01); desconocido → **rechazo** |
| `result.score.scaled` (per-iDevice, 0..1) | `scorepct = scaled*100` → entrada `itemscores[objectid]` |
| `result.score.scaled` (paquete, 0..1) | overall: `scorepct = scaled*100` → `itemnumber=0` (evita recálculo; el productor ya pondera) |
| `result.success` / `result.completion` | `status` / `completion` del intento |
| `context.extensions[package-id]` (odeId) | validación de pertenencia del statement a la instancia |
| `context.registration` | clave de agrupación de intento (análogo a `sessiontoken`) |
| `statement.id` (UUID) | clave opcional de idempotencia (audit/dedup servidor) |

Nota de escala: el `answered` viene en 0..10 y el de paquete en 0..100 (FTE-011); ambos
exponen `result.score.scaled` (0..1), que es la forma canónica para `scorepct = scaled*100`.

## Modelo de confianza (el servidor valida TODO)

1. Sesión Moodle + `sesskey`.
2. Resolver `cmid → cm/instancia` en servidor; `require_capability('mod/exelearning:savetrack')`
   (o regla preview, DEC-0006).
3. **Ignorar `actor`** del cliente → usar `$USER` (paridad con la confianza nula del shim
   SCORM, que tampoco recibe identidad del paquete).
4. `object.id` debe resolver a un `objectid` **de esta instancia** (`exelearning_grade_item`,
   DEC-5-01); desconocido → rechazo (no crear items desde el cliente).
5. Respeta `gradeenabled` (DEC-13-07): con calificación desactivada no hay items → no-op.
6. Overall: preferir el statement de paquete (`passed`/`failed`), pero **revalidar en
   servidor**; nunca confiar ciegamente un "final score" del cliente (espíritu de DEC-6-01).
7. `postMessage`: el host **fija** `parentOrigin` a su origen y **valida** `event.origin`
   contra el origen del iframe (`pluginfile.php`); rechazar `'*'`/mismatch (RIE-013, cf.
   RIE-010, guard de postMessage del editor embebido).
8. Idempotencia: deduplicar por `statement.id` si se persiste el statement (auditoría).

## [INTERPRETACION]

- **Simetría con el camino SCORM.** Igual que Moodle inyecta el loader pipwerks en el
  `<head>` del paquete (`exelearning_inject_scorm_loader`, `lib.php:770`), puede inyectar
  `window.exeXapi = { parentOrigin: <origen Moodle>, actor: null, registration: <token> }`.
  Así el paquete honesto emite **sólo** a Moodle (no a `'*'`) y con un `registration` que
  Moodle controla — el análogo xAPI del `sessiontoken`. Esto es diseño de PR2, no de este
  PR.
- **`core_xapi` vs endpoint propio.** Dos vías de ingestión servidor:
  - *Nativa* (`core_xapi_statement_post` + `mod_exelearning\xapi\handler`, FTE-007, patrón
    h5pactivity AN-003): estándar, emite eventos Moodle, menos código. Coste: `core_xapi`
    liga el procesamiento a la **identidad del actor**, y nuestro paquete emite actor
    anónimo; encajarlo exige que el handler trate el statement como del usuario de sesión.
  - *Endpoint propio* (`classes/external/submit_xapi_statement`, AJAX, patrón
    `manage_embedded_editor` + `db/services.php`): control total del modelo de confianza
    (ignora actor → `$USER`), reutiliza trivialmente `apply_item_scores`. Coste: no usa el
    subsistema `core_xapi` (sin eventos/estado automáticos).
  - [HIPOTESIS] La vía más limpia para "ignorar actor y reusar la tubería" es el **endpoint
    propio** para ingestión/nota, con un **handler `core_xapi` opcional** sólo para
    eventos/analítica más adelante. Se decide en DEC-17-01 y se detalla en
    `docs/xapi-integration-plan.md`.

## [HIPOTESIS]

- El statement de paquete (`passed`/`failed`) hace **innecesario** el recálculo servidor
  del overall para el caso xAPI (a diferencia del SCORM multipágina, DEC-6-01), porque el
  productor ya entrega el agregado ponderado. A validar en PR2 con fixtures reales.

## Consecuencias para `mod_exelearning`

- xAPI entra como **fuente de ingestión adicional**, no como modelo nuevo: un normalizador
  fino (`statement → itemscores`) + el endpoint, ambos PR2.
- Cero cambios de esquema obligatorios; como mucho **una** tabla `exelearning_tracking_events`
  (`statementid` UNIQUE) para auditoría/idempotencia. NO cabecera+detalle (DEC-0007).
- El camino SCORM 1.2 queda **intacto** como compatibilidad (DEC-0003).

## [PENDIENTE]

- EXP-004: traza del contrato real (hecho) + captura en vivo dentro de Moodle (PR2).
- Confirmar en PR2 si el `registration` inyectado por Moodle basta para mapear intento sin
  tocar `attempts::resolve_attempt_number`.
