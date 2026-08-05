---
id: AN-014
titulo: "Qué aporta el ecosistema ADL/xAPI (cmi5launch, Moodle-PHP-Libs, xAPI-Spec, xAPI.js) a la integración xAPI de mod_exelearning"
fecha: 2026-06-17
fuentes:
  - REPO-008
  - REPO-009
  - FTE-015
  - FTE-016
  - FTE-017
  - FTE-011
relacionados:
  - DEC-0-18
  - DEC-17-01
  - DEC-0-03
  - DEC-0-14
  - DEC-0-15
  - DEC-0-07
  - DEC-5-01
  - DEC-6-01
  - DEC-13-07
herramienta_ia:
  interfaz: claude-code
  modelo: claude-opus-4-8
---

## Resumen

Los cuatro recursos **no aportan código de runtime ni cambian la arquitectura** ya fijada en DEC-17-01 +
`docs/xapi-integration-plan.md`, pero sí una **base normativa y de patrones** que la precisa y blinda.
`xAPI-Spec 1.0.3` (FTE-015) es el ancla de mayor valor: fija las **reglas de validación** que el endpoint
`submit_xapi_statement` debe imponer y respalda canónicamente el trust model «IGNORA `actor`→`$USER`».
`mod_cmi5launch` (REPO-008) es la referencia directa de **patrón statement→nota** (*highest score wins*,
agregación highest/average, `registration` como ancla) y revela un **hueco de diseño**: como
`mod_exelearning` **no tiene player**, debe decidir `passed/failed` **en el servidor**. `@xapi/xapi` (FTE-016,
MIT) da **tipos y fixtures** sin dependencia. `Moodle-PHP-Libs` (REPO-009) es **ruido**: vendor-bundle sin
licencia y sin código xAPI → se documenta que **no** es dependencia.

## Hechos citados

- **El pipeline servidor ya es un punto de inyección único y triple-caller-ready.** `track::ingest()`
  (`classes/local/track.php:59-291`) lo invocan hoy el endpoint web (`track.php`) y el WS móvil
  (`classes/external/save_track.php:137`); un endpoint xAPI sería el **tercer caller**, no un pipeline paralelo.
  La plantilla `external_api` es `classes/external/manage_embedded_editor.php` (`db/services.php`).
- **Multi-item y trust ya implementados:** `apply_item_scores()` (`track.php:470-520`) rutea por `objectid`
  estable (DEC-5-01) e **ignora silenciosamente** objectids no registrados (`registered_objectids()`,
  `track.php:117-124`); `recompute_overall_pct()` **nunca** confía el overall del cliente (DEC-6-01);
  `aggregate_values()` implementa highest/average/first/last/lowest (`attempts.php:324-342`).
- **cmi5launch confirma el patrón de nota** (REPO-008): *«si una sesión tiene más de un score, solo el más
  alto»* (`progress.php::cmi5launch_retrieve_score`) + agregación highest/average en dos niveles
  (`grade_helpers.php`); **un solo grade item** (`itemnumber=0`) — el plugin lo supera con multi-item.
- **cmi5launch delega el estado a un player externo** (CATAPULT): `session_helpers::cmi5launch_update_sessions`
  **copia** `is(completed|passed|failed|...)` provistos por el player. `mod_exelearning` **no tiene player**.
- **cmi5launch NO persiste statements ni deduplica por `statement.id`** (no hay tabla de statements en
  `db/install.xml`) — carencia que `exelearning_tracking_events` (`statementid` UNIQUE) cubriría.
- **Reglas canónicas xAPI 1.0.3** (FTE-015, `xAPI-Data.md`/`xAPI-Communication.md` @ master): `score.scaled ∈
  [-1,1]`, `raw ∈ [min,max]`; idempotencia por `statement.id` (no modificar estado ante id repetido; 409 si
  difiere; 400 en batch duplicado); **el LRS controla `authority`/`stored`** (§4.1.9/4.1.10) y el cliente «no
  asegura su identidad de forma fiable»; `X-Experience-API-Version: 1.0.3` obligatorio **en HTTP**; validación
  **sintáctica** (rechazar `null` fuera de `extensions`, IRI sin esquema). Confirmado por Context7
  `/adlnet/xapi-spec` (2026-06-17).
- **El emisor upstream NO usa un `[0,1]` uniforme** (FTE-011): `answered` por iDevice → `scaled = s/10`
  (`raw=s, max=10, success=s>=5`); paquete → `scaled = f/100` (`raw=f, max=100`). `scorepct = scaled*100` es
  correcto **solo** porque `scaled` ya está normalizado a `[0,1]`.
- **`@xapi/xapi`** (FTE-016): `Statement.ts`/`Result.ts` modelan el shape 1:1; `XAPI.Verbs` da los IRIs
  canónicos; `fetchAdapter` desacoplado valida usar `fetch` nativo (no `axios`); cmi5 vive en `@xapi/cmi5`.
- **`Moodle-PHP-Libs`** (REPO-009): vendor-bundle Composer, `license: null` (404 en LICENSE), sin código xAPI;
  `firebase/php-jwt` y `guzzle` que contiene **ya están en Moodle core**.

## Beneficios por recurso (pregunta 1: «¿en qué me benefician?»)

- **cmi5launch (REPO-008)** — patrón statement→nota citable (*highest score wins*, agregación highest/average),
  `registration` como ancla de intento, precedente de **backup del tracking**, y la **prueba por contraste** de
  que sin player el `passed/failed` debe vivir en Moodle. Carencia citable: sin idempotencia por `statement.id`.
- **xAPI-Spec 1.0.3 (FTE-015)** — reglas de validación duras y citables (rangos de score, idempotencia,
  authority/stored, version header, descartes de alcance) que **cierran RIE-013** con normativa, no intuición.
- **xAPI.js (FTE-016)** — tipos del statement y `XAPI.Verbs` como contrato compacto + base de fixtures, MIT,
  sin dependencia de runtime.
- **Moodle-PHP-Libs (REPO-009)** — solo evita reinvestigarlo: queda **descartado** como dependencia.

## Mejoras propuestas a la especificación (pregunta 2: «¿qué mirar para mejorar mi spec?»)

> Recomendaciones (un AN **no decide**, `research/AGENTS.md` §3). Su adopción en `docs/xapi-integration-plan.md`,
> `tracking-architecture.md` o un ADR es un paso posterior.

| ID | Mejora | Destino | Evidencia | Prioridad |
|---|---|---|---|---|
| **M1** | Sección «Validación canónica del endpoint»: rechazar `scaled∉[-1,1]`, `raw∉[min,max]`, `statement.id` no-UUID, verbo fuera de lista blanca, `null` fuera de `extensions`; ignorar `actor`/`authority`/`stored`/`timestamp` del cliente | `xapi-integration-plan.md` §4 + futuro `submit_xapi_statement` | FTE-015 (`xAPI-Data.md` §4.4, §4.1.5) | **alta** |
| **M2** | Precisar `scaled→scorepct`: el emisor usa `s/10` por iDevice y `f/100` por paquete (no `[0,1]` uniforme); validar rango; aclarar dominio eXeLearning `[0,1]` | `xapi-integration-plan.md` §5 (tabla, línea 104) | FTE-011 (líneas 23-31) + FTE-015 §4.1.5 + REPO-008 `retrieve_score` | **alta** |
| **M3** | Regla `highest score wins por (registration, ideviceId)` para selección, **distinta** del dedup por `statement.id`; anclar al `grademethod`/`aggregate_values` existente | `xapi-integration-plan.md` §4/§6 + DEC-17-01 | REPO-008 `progress.php`/`grade_helpers.php`; `attempts.php:324-342` | media |
| **M4** | Anclar normativamente «IGNORA `actor`/`authority`/`stored`/`timestamp`»; contrastar con cmi5launch (confía en LRS/player; aquí el statement llega del navegador → validar `event.origin`) | `xapi-integration-plan.md` §4.3 + `tracking-architecture.md` | FTE-015 §4.1.9/4.1.10; REPO-008 `cmi5_connectors.php` | media |
| **M5** | Idempotencia con semántica LRS (id repetido → no re-aplicar; 409 si difiere); `X-API-Version` solo en la rama HTTP, no en `postMessage` | `xapi-integration-plan.md` §4.7/§6 + futura `exelearning_tracking_events` | FTE-015 Part Three §2.1.2/2.1.3, §6.2 | media |
| **M6** | Declarar descartes de alcance nominales: State API/Document Resources, Agent/Activity Profile, Signed Statements (JWS) — además de cmi5/LRS externo | `xapi-integration-plan.md` §7 | FTE-015 Part Three §2.2/2.4; FTE-016 (`@xapi/cmi5` aparte) | media |
| **M7** | Citar tipos MIT de `@xapi/xapi` como contrato del shape (sin vendorar) y derivar fixtures válidos/inválidos; usar `XAPI.Verbs` | `xapi-integration-plan.md` §5 + plan de tests (Vitest/PHPUnit) | FTE-016 `Statement.ts`/`Result.ts`/`XAPI.Verbs` | baja |
| **M8** | Backup/restore del tracking xAPI envuelto por la condición `userinfo` (cmi5launch respalda session/au/usercourse pero parece **omitir** ese flag) | DEC-0-07 + futuro `backup_exelearning_stepslib` | REPO-008 `backup_cmi5launch_stepslib.php` `[PENDIENTE]` | baja |
| **M9** | Documentar que `Moodle-PHP-Libs` **no** es dependencia; JWT/HTTP futuros con `\Firebase\JWT\JWT`/`\core\http_client` de core | `xapi-integration-plan.md` + `thirdpartylibs.xml` (solo pipwerks) | REPO-009 (license:null); moodledev.io thirdpartylibs | baja |

## Decisiones de reuso y licencia

| Recurso | Rol | Nota de licencia (GPLv3 = requisito Moodle) |
|---|---|---|
| cmi5launch (REPO-008) | **reference** | Cabeceras `.php` GPLv3-or-later; LICENSE raíz Apache-2.0 (one-way compat GPLv3). Solo se usan **patrones**, no se copia código → riesgo nulo |
| Moodle-PHP-Libs (REPO-009) | **ignore** | Sin licencia agregada (license:null/404) → redistribución ambigua; viola «no vendorar» (DEC-0-02). JWT/guzzle ya en core |
| xAPI-Spec 1.0.3 (FTE-015) | **reference** | Repo Apache-2.0; solo se reutilizan **reglas** (hechos no protegibles) → riesgo nulo. Fijar **1.0.3** (no 2.0/IEEE) |
| xAPI.js (FTE-016) | **reference + test-fixtures** | MIT (compat GPLv3). NO dependencia de runtime (arrastra `axios`, sin build AMD). Si se copiaran tipos: conservar aviso MIT + `thirdpartylibs.xml` |

## [INTERPRETACION] — Reconsideración de cmi5: *matizada*, sigue fuera de alcance

La evidencia **no** cambia la postura «cmi5 fuera de alcance» (DEC-0-14/FTE-009): la **refuerza** —cmi5launch
depende de un player externo (CATAPULT) + LRS, y en xAPI.js cmi5 es un paquete **separado** (`@xapi/cmi5`),
ambos prueban que su valor está en LRS/catálogos lanzables, no en un recurso HTML embebido same-origin— y
añade un **matiz de diseño**: precisamente porque cmi5launch **delega** `passed/failed/completed` al player y
`mod_exelearning` **no tiene player**, la decisión `passed/failed` debe vivir en el **servidor** (umbral/
`gradepass`, espíritu DEC-6-01), no esperarse del paquete. Esto no supersede DEC-0-14; concreta M4 y M6.

## [HIPOTESIS]

- El statement de paquete (`passed`/`failed`) podría **evitar** el recálculo server-side del overall que el
  SCORM multipágina necesita (DEC-6-01), porque el productor ya entrega el agregado ponderado (FTE-011,
  AN-012). **Pero** M4 sugiere lo contrario para `success/passed`: sin player, revalidar el umbral en servidor.
  A resolver en PR2 con fixtures reales (EXP-004 fase viva) — coherencia con DEC-6-01 a fijar.
- `M1` con **rechazo duro** (400) de `scaled<0` vs **clamp** documentado a `[0,1]`: el dominio eXeLearning es
  `[0,1]`; probablemente baste validar y normalizar, pero la política debe decidirse antes de implementar.

## Consecuencias para `mod_exelearning`

- Cero cambios de arquitectura: la ingesta xAPI sigue siendo un **normalizador fino → `apply_item_scores`**
  (DEC-17-01). Lo que cambia es la **especificación del endpoint**: gana reglas de validación citables (M1-M6).
- El listener (`amd/src/xapi_listener.js`) y el endpoint (`classes/external/submit_xapi_statement`) ganan un
  **contrato tipado** (FTE-016) y **fixtures** (M7) para los tests Vitest/PHPUnit de PR2.
- `Moodle-PHP-Libs` queda **cerrado** como no-dependencia (M9); `thirdpartylibs.xml` sigue declarando solo
  `pipwerks`.

## [PENDIENTE]

- Confirmar el contrato **congelado** de `exelearning#1867` (M2/M3 dependen de `s/10` y `f/100`); seguimiento
  PREG-002 / TAREA-015.
- `custom vs core_xapi` (PR2): ¿`core_xapi_statement_post` acepta actor anónimo→`$USER` sin hacks? (FTE-007/AN-003).
- `registration↔sessiontoken`: ¿UUID xAPI reemplaza al `sessiontoken` (`random_string(20)`, `view.php:401`) o
  conviven? Definir generación/formato server-side para evitar colisiones.
- Verificar línea a línea `backup_cmi5launch_stepslib.php` antes de citar la omisión de `userinfo` como
  anti-patrón firme (M8).
- `[PENDIENTE]` re-verificar verbatim las cláusulas MUST/SHOULD de FTE-015 contra `xAPI-Data.md`/
  `xAPI-Communication.md` al commit fijado antes de citarlas literal en un ADR.

## Actualización 2026-06-17 — xAPI 2.0 y formalización en DEC-0-18

- **xAPI 2.0 (IEEE 9274.1.1-2023) NO obliga a `mod_exelearning`** (FTE-017): no somos un LRS; el modelo de
  Statement es retro-compatible (`result.score` idéntico; solo `contextAgents`/`contextGroups` nuevos y
  opcionales); la regla «un LRS 2.0 rechaza header `<2.0.0`» **vincula a un LRS**, no a este consumidor
  same-origin. Como el emisor upstream envía `1.0.3`, la política recomendada es **consumir 1.0.3 pero validar
  la versión de forma permisiva** (aceptar `1.0.x` y, defensivamente, `2.0.0`; nunca rechazar por header). →
  **M-versión**, destino `xapi-integration-plan.md` §4 + `xapi_listener.js`, prioridad media.
- **`DEC-0-18` (Propuesta)** formaliza como diseño vinculante (PR2/TAREA-015) las mejoras **M1, M2, M3, M4, M5,
  M6** y la M-versión, citando FTE-015 + FTE-017. Las mejoras **M7** (fixtures `@xapi/xapi`), **M8** (backup
  `userinfo`) y **M9** (`Moodle-PHP-Libs` no-dependencia) quedan como seguimiento, no decididas en este ADR.

**Resoluciones de diseño (erseco, 2026-06-17)** — las preguntas abiertas del endpoint quedan decididas y
registradas en **DEC-0-18 §Resoluciones de diseño**: `scaled∉[0,1]` → **rechazo 400**; overall → **recálculo
server-side** (DEC-6-01); `registration`/`sessiontoken` **conviven**; ingesta = **endpoint custom** +
`core_xapi` opcional luego. En paralelo: DEC-18-01 → **solo Fase 1** (reemplazo descubrible + URL externa
opt-in admin, refresco manual); DEC-34-02 → **diferida** (la salida definitiva es xAPI); RIE-001 → **aceptado**
+ roadmap en la rama `feature/secure-iframe-scorm-bridge`.
