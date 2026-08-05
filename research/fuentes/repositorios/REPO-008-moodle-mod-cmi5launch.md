---
id: REPO-008
titulo: "Moodle-mod_cmi5launch (ADL) — módulo de referencia de ingesta xAPI/cmi5 → gradebook"
tipo: moodle-plugin
ruta_local: "[no clonado; consultado vía GitHub API/raw]"
url_upstream: "https://github.com/adlnet/Moodle-mod_cmi5launch"
commit_consultado: "main @ 9c7d90da44035839a239380fcc2e0c7aa6910a85 (consultado 2026-06-17)"
fecha_consulta: 2026-06-17
licencia: "GPL-3.0-or-later (cabeceras .php) / Apache-2.0 (LICENSE raíz) — ver §Riesgos"
rol_para_mod_exelearning: "Implementación Moodle de referencia del pipeline statement→nota (agregación highest/average, 'highest score wins por sesión', registration como ancla de intento, backup del tracking). Rol: referencia, NO dependencia. Sustenta AN-014, DEC-17-01 y DEC-0015."
herramienta_ia:
  interfaz: claude-code
  modelo: claude-opus-4-8
---

## Hechos

- Componente Moodle: `mod_cmi5launch` (frankenstyle). Versión **1.1.0** (Build 2025070116),
  `MATURITY_STABLE`, requiere Moodle **4.3+** (`requires 2023100900`).
- Mantenido por ADL (autora principal Megan Bohland, sobre trabajo previo de Andrew Downes /
  xAPI Launch Link). Es el comparable más directo al endpoint xAPI que `mod_exelearning`
  construye (PR2/TAREA-015), **con una diferencia de modelo clave** (ver §Capacidades): cmi5launch
  **delega** el procesamiento de las reglas cmi5 a un **reproductor externo** (ADL CATAPULT cmi5
  player) y un **LRS**; `mod_exelearning` no tiene player y sirve el paquete embebido same-origin.
- Única librería de terceros: `cmi5PHP` v0.40.0 (Apache-2.0), declarada en `thirdpartylibs.xml`.

## Rutas clave

| Ruta | Rol |
|---|---|
| `version.php` | v1.1.0, `requires 4.3`, cabecera GPL v3-or-later, `@copyright 2024 Megan Bohland` |
| `lib.php` | Hooks: `cmi5launch_grade_item_update` (UN grade item, `itemnumber=0`), `_update_grades`, `_get_completion_state` |
| `db/install.xml` | 5 tablas: `cmi5launch`, `cmi5launch_usercourse`, `cmi5launch_player`, `cmi5launch_sessions`, `cmi5launch_aus`. **No** hay tabla de statements |
| `classes/local/grade_helpers.php` | Agregación highest/average sesión→AU→curso (`cmi5launch_highest_grade`/`cmi5launch_average_grade`/`cmi5launch_update_au_for_user_grades`) |
| `classes/local/progress.php` | `cmi5launch_request_statements_from_lrs` (GET por `registration`+`since`, header `X-Experience-API-Version: 1.0.3`), `cmi5launch_retrieve_score` ('highest score wins') |
| `classes/local/cmi5_connectors.php` | Auth Basic/Bearer al **player** + LRS; `retrieve_session_info_from_player` |
| `classes/local/session_helpers.php` | `cmi5launch_update_sessions`: **copia** los flags `iscompleted/ispassed/isfailed/...` provistos por el player |
| `db/tasks.php`, `classes/task/` | Polling programado (modelo **pull**, no push en tiempo real) |
| `backup/moodle2/backup_cmi5launch_stepslib.php` | Backup de `session`/`au`/`usercourse`/config |
| `classes/privacy/` | Privacy provider del tracking persistido |

## Contratos relevantes

- `cmi5launch_grade_item_update($cmi5launch, ...)` → `grade_update('mod/cmi5launch', ..., itemnumber=0, ...)`
  con `gradetype=GRADE_TYPE_VALUE`, `grademax=maxgrade`. **`itemnumber=0` único** (un solo grade item por
  actividad) — **CONTRASTA** con `mod_exelearning`, que usa multi grade-item (un item por iDevice, 1..N).
- `cmi5launch_get_completion_state($course, $cm, $userid, $type)`: completion **por existencia de statement**
  en el LRS que case verbo (`cmi5verbid`) + actividad + expiración (`cmi5expiry`); independiente del grading.
- **El estado de sesión NO lo calcula Moodle a partir de verbos**: lo provee el player (CATAPULT) por GET
  (`cmi5_connectors.php::cmi5launch_retrieve_session_info_from_player`); `session_helpers` copia
  `iscompleted/ispassed/isfailed/isterminated/isabandoned` + `score` a `cmi5launch_sessions`.

## Modelo de datos

- `cmi5launch_sessions`: una fila por sesión (`score`, `masteryscore`, flags `is*`, `progress`,
  `registrationscoursesausid`).
- `cmi5launch_aus`: por AU (`completed/passed/inprogress/satisfied`, `masteryscore`, `sessions`, `scores`, `grade`).
- `cmi5launch_usercourse`: instancia por usuario (`aus`, `ausgrades`, `grade`, **`registrationid`**).
- **Sin tabla de statements**: los statements se piden al LRS bajo demanda para reporting/completion;
  **no se persisten ni se deduplican por `statement.id`** (carencia citable — ver AN-014/M5).

## Mapeo statement → nota (lo más valioso para `mod_exelearning`)

1. El **player** procesa los verbos cmi5 (`launched/initialized/passed/failed/completed/terminated/satisfied`,
   `moveOn`, `masteryScore`) y expone por sesión los flags + un `score`.
2. `cmi5launch_retrieve_score` (`progress.php`) selecciona el score: prioriza `raw`, luego `scaled` redondeado,
   luego `scaled*max`; y **«si una sesión tiene más de un score, solo el más alto»** (*highest score wins*).
3. `grade_helpers.php` agrega en **dos niveles con el mismo `grademethod`** (1=highest=`max()`, 2=average=`sum/count`,
   redondeo a 2 decimales): sesiones→AU y AU→curso.
4. La nota final va a **un único** grade item (`itemnumber=0`) vía `grade_update`.

## Capacidades respecto a `mod_exelearning`

- **Sidebar/TOC:** N/A (lanza a un player externo; no embebe HTML con sidebar nativa como `mod_exelearning`).
- **Grading:** agregado en **un único** grade item (`itemnumber=0`). `mod_exelearning` lo supera con multi-item.
- **Tracking:** cmi5 (perfil xAPI) **vía LRS externo + player**; modelo *pull* (polling). `mod_exelearning`:
  xAPI por `postMessage` same-origin, *push*, ingesta server-side (DEC-17-01).
- **Backup/restore:** completo de `session/au/usercourse/config`; **pero no se observó el conditional `userinfo`**
  envolviendo el tracking (`[PENDIENTE]` verificar línea a línea — ver AN-014/M8).
- **Editor embebido:** N/A.

## Patrones reutilizables (referencia, sin copiar código)

1. **`highest score wins` por (registration, sesión)** (`progress.php::cmi5launch_retrieve_score`) → adoptable
   en el endpoint de `mod_exelearning` cuando lleguen varios `answered` del mismo iDevice/registration
   (regla de selección, distinta del dedup por `statement.id`). Ver AN-014/M3.
2. **Agregación jerárquica con `grademethod` configurable** (`grade_helpers.php`) → refuerza DEC-0015 y el
   `aggregate_values` existente (`classes/local/attempts.php:324-342`, highest/average/first/last/lowest).
3. **`registration` como ancla de correlación** intento↔statements (`usercourse.registrationid`) → valida el
   mapeo `registration↔sessiontoken` del plan (`view.php:401`, `random_string(20)`).
4. **Backup del tracking** en `backup_*_stepslib` para no perder notas en copias de seguridad (corrigiendo la
   omisión de `userinfo`).
5. **Organización del código** modelo plano (`au.php`/`session.php`) + `*_helpers.php` + `*_connectors` para I/O
   + `privacy/` provider → plantilla de estructura para el código xAPI de `mod_exelearning`.

## Riesgos / Limitaciones

- **Discrepancia de licencia:** el `LICENSE` raíz es **Apache-2.0** pero las cabeceras `.php` declaran
  **GPL v3-or-later**. Ambigüedad a aclarar **solo si se copiara código** (no es el caso: es referencia de
  patrones). Apache-2.0 es one-way compatible con GPLv3 (FSF), así que el riesgo efectivo es nulo.
- **Diferencia de modelo:** requiere **player externo + LRS**; el estado passed/failed/completed lo decide el
  **player**, no el plugin. Muchos detalles de `cmi5_connectors` (tenant/token/registration) **no son
  trasladables** a `mod_exelearning` (sin player). `[INTERPRETACION]` Como `mod_exelearning` no tiene player,
  **debe decidir passed/failed en el servidor** (umbral/`gradepass`, espíritu DEC-6-01) — ver AN-014/M4.
- **Sin idempotencia por `statement.id`** (no persiste statements): `mod_exelearning` mejora ese punto con
  `exelearning_tracking_events` (`statementid` UNIQUE) opcional.
- Las firmas de función provienen de WebFetch sobre los `.php` crudos (resumido por un modelo), no de lectura
  línea a línea. `[PENDIENTE]` revisar `grade_helpers.php`/`progress.php`/`backup_cmi5launch_stepslib.php`
  verbatim si se requiere literalidad.

## Preguntas abiertas

- PREG: ¿la regla `moveOn`/`masteryScore` del player aporta algo replicable server-side en `mod_exelearning`,
  o basta `gradepass` por item? (relacionado con AN-014/M4).
- PREG: confirmar línea a línea si `backup_cmi5launch_stepslib.php` omite el conditional `userinfo` antes de
  citarlo como anti-patrón firme (AN-014/M8).
