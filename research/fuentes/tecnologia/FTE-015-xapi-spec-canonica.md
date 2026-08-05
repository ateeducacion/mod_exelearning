---
id: FTE-015
titulo: "xAPI 1.0.3 (especificación canónica ADL) — reglas de validación e idempotencia del endpoint"
categoria: especificacion
version_consultada: "Experience API 1.0.3 (adlnet/xAPI-Spec, rama master)"
enlaces_oficiales:
  - https://github.com/adlnet/xAPI-Spec
  - https://github.com/adlnet/xAPI-Spec/blob/master/xAPI-Data.md
  - https://github.com/adlnet/xAPI-Spec/blob/master/xAPI-Communication.md
  - https://github.com/adlnet/xAPI-Spec/blob/master/xAPI-About.md
context7:
  library_id: /adlnet/xapi-spec
  query: "Statement validation rules an LRS must enforce: result.score scaled range -1..1, raw between min and max, statementId UUID dedup and 409 Conflict, X-Experience-API-Version header, authority and stored controlled by LRS, null rejected outside extensions, voiding"
  fecha: 2026-06-17
  version_devuelta: "adlnet/xapi-spec (xAPI-Data.md / xAPI-Communication.md). Confirma: header X-Experience-API-Version: 1.0.3 obligatorio en POST /statements; 'stored' MUST be set/overwritten by the LRS; 409 Conflict en PUT sin If-Match para recurso existente; manejo de Voided Statements; result.score {scaled, raw, min, max}."
fecha_consulta: 2026-06-17
relevancia_para_mod_exelearning: "Ancla normativa citable (hoy solo se cita indirectamente vía FTE-009/Context7). Fija las reglas que el endpoint submit_xapi_statement DEBE imponer al validar lo que ingiere (cierra RIE-013 con reglas, no con intuición) y respalda el trust model 'IGNORA actor→$USER'. Commit master @ ca782a1129bc6ae848640ff4e8e262334bdd0ba5."
herramienta_ia:
  interfaz: claude-code
  modelo: claude-opus-4-8
---

## Qué es

Repositorio **canónico** de la **Experience API (xAPI)** mantenido por ADL. Define la comunicación de
actividad de aprendizaje mediante **Statements JSON** y un servicio REST (**LRS**). La rama `master`
contiene la versión **1.0.3** en tres partes: **Part One** (`xAPI-About.md`, personas: *Learning Record
Provider*/LRP = emisor, *Learning Record Store*/LRS = consumidor/autoridad), **Part Two** (`xAPI-Data.md`,
estructura y validación de datos) y **Part Three** (`xAPI-Communication.md`, procesamiento, validación,
REST y seguridad). Sucesora: xAPI 2.0 = **IEEE 9274.1.1-2023** (no está en este repo; mayormente compatible
a nivel de Statement, eleva recomendaciones de 1.0 a requisitos).

> Para `mod_exelearning` se **fija 1.0.3** (alineado con el shim SCORM 1.2 y con el header
> `X-Experience-API-Version: 1.0.3` del emisor upstream FTE-011), **no** 2.0/IEEE.

## Conceptos clave (citados a la spec)

- **Statement mínimo:** REQUIERE exactamente `actor`, `verb`, `object`. `id` (UUID), `result`, `context`,
  `timestamp` son opcionales; **`stored`, `authority`, `version` los fija/controla el LRS**. Una propiedad no
  puede aparecer más de una vez; no se incluyen propiedades con string vacío.
  (`xAPI-Data.md` §4.1 Statement Properties).
- **Actor (Agent):** DEBE incluir exactamente **un** Inverse Functional Identifier (`mbox`, `mbox_sha1sum`,
  `openid` o `account{homePage,name}`). → El emisor no debe poner PII; el endpoint **ignora `actor`** y usa
  `$USER` (`xAPI-Data.md` §4.1.2).
- **Object (Activity):** `id` es un IRI **único y estable**; `definition.type` es un IRI (p.ej.
  `…/activities/cmi.interaction`). El plan usa `object.id = {baseIri}/idevice/{ideviceId}` → resoluble a
  `itemnumber` (`xAPI-Data.md` §4.1.4).
- **Result/Score:** `scaled` decimal en **[-1, 1] inclusive**; `raw` **entre `min` y `max`** si están
  presentes; `success`/`completion` booleanos; `duration` en ISO-8601. *«The Score Object SHOULD NOT be used
  for scores relating to progress or completion»* (`xAPI-Data.md` §4.1.5).
- **Context/registration:** `registration` es un **UUID** que asocia el Statement a un intento/registro;
  `contextActivities.parent/grouping/...` son Activities (el LRS DEBE devolverlas como array)
  (`xAPI-Data.md` §4.1.6).
- **Inmutabilidad y voiding:** salvo `id`/`authority`/`stored`/`timestamp`/`version` y el `display` del verbo,
  el Statement es **inmutable**; no se borra ni edita: se **anula** con verbo
  `http://adlnet.gov/expapi/verbs/voided` + `object {objectType: StatementRef, id}`. *«Any Statement that
  voids another cannot itself be voided»* (`xAPI-Data.md` §4.1.10/§4.3/§4.2.4).
- **Authority/Stored los controla el LRS:** *«The LRS MUST ensure that all Statements stored have an
  authority»* y *«SHOULD overwrite the authority based on the credentials used»*; *«The "stored" property MUST
  be set by the LRS… MUST overwrite any value currently in the "stored" property»*. El cliente **no** puede
  asegurar su propia identidad de forma fiable (`xAPI-Data.md` §4.1.9/§4.1.10; confirmado por Context7
  2026-06-17).

## API / Puntos de extensión relevantes (para el endpoint)

- **Idempotencia por `statement.id`** (Part Three §2.1.2/2.1.3): *«An LRS MUST NOT make any modifications to
  its state based on receiving a Statement with a statementId that it already has»*; ante id ya visto que
  **difiere** → **409 Conflict**; un batch con dos statements del mismo id → **400 Bad Request**.
  → la tabla opcional `exelearning_tracking_events` (`statementid` UNIQUE) replica exactamente esta semántica.
- **Versionado HTTP** (Part Three §6.2): *«The Client MUST include the X-Experience-API-Version header in every
  request»*, fijado a la última patch (**1.0.3**). El LRS rechaza peticiones sin header y con versión `>=1.1.0`;
  acepta cualquier `1.0.x`. (Confirmado por Context7 2026-06-17.) → **Aplica solo a la rama HTTP**
  `POST {endpoint}statements`; el transporte `postMessage` del plugin **no** lleva header HTTP (validar
  `statement.version` si está presente).
- **Validación = sintaxis, no semántica** (`xAPI-Data.md` §4.4): *«The LRS SHOULD enforce rules regarding
  structure. The LRS SHOULD NOT enforce rules regarding meaning»*. El LRS DEBE **rechazar** `null` (salvo
  dentro de `extensions`), tipos erróneos, IRI/IRL sin esquema, y claves/valores fuera de formato. Procesa
  números con ≥ precisión IEEE-754 float32.
- **State / Document / Profile Resources** (Part Three §2.2/2.4): persistencia clave/valor para suspend/resume
  con concurrencia optimista (ETag + `If-Match`/`If-None-Match`, **412**). **No** necesario para
  `mod_exelearning` (el shim SCORM ya cubre `suspend_data`; el grading vive en `exelearning_attempt`).
- **Seguridad** (Part Three §4): el LRS soporta OAuth 1.0 / HTTP Basic / CAC; HTTPS fuertemente recomendado
  (no estrictamente obligatorio en 1.0.3). En `mod_exelearning` la seguridad real la pone Moodle (sesskey +
  capability `mod/exelearning:savetrack` + validación de `event.origin`, RIE-013), no la spec.

## Soporte para multi-grade-items

Indirecto: xAPI no define grading (valida sintaxis, no semántica). Aporta los **rangos válidos**
(`scaled ∈ [-1,1]`, `raw ∈ [min,max]`) y la semántica de `registration` (UUID de intento). El mapeo
`object.id → ideviceId → exelearning_grade_item.objectid → itemnumber → track::apply_item_scores()` es
responsabilidad del **endpoint** del plugin (mismo pipeline que SCORM, DEC-5-01/DEC-6-01).

## Soporte para navegación/sidebar

Ortogonal: xAPI no define UI. La sidebar la sirve el paquete nativo eXeLearning (intacto).

## Implementaciones de referencia consultadas

- REPO-008 — `mod_cmi5launch`: usa exactamente `X-Experience-API-Version: 1.0.3` y Basic auth al LRS;
  confirma `registration` como ancla de intento; **no** persiste statements (sin idempotencia por id).
- FTE-011 — `exe_xapi.js` (emisor upstream): forma del statement (`answered`/`completed`/`passed`/`failed`,
  `result.score.scaled`, `cmi.interaction`/`assessment`) conforme a esta spec.
- FTE-016 — `@xapi/xapi`: tipos TypeScript del statement (contraste de shape).

## Riesgos / Limitaciones

- El repo `master` es **1.0.3 congelado**; xAPI 2.0 (IEEE 9274.1.1-2023) vive fuera de GitHub. Fijar 1.0.3.
- `mod_exelearning` **no es un LRS**: solo ingiere un subconjunto (`answered`/`completed`/`passed`/`failed`) y
  **descarta** State/Profile/Signed Statements/concurrencia ETag/OAuth → declararlo explícitamente (AN-014/M6)
  para no inducir expectativas de conformidad LRS.
- Licencia del repo **Apache-2.0** (compatible GPLv3); pero solo se reutilizan **reglas** (hechos no
  protegibles), sin incorporar código → riesgo nulo.
- `[PENDIENTE]` para texto MUST/SHOULD **verbatim** en una ficha normativa, re-verificar párrafo a párrafo
  contra `xAPI-Data.md`/`xAPI-Communication.md` al commit fijado antes de citar literal en el plan/ADR.

## Preguntas abiertas

- PREG: ¿el endpoint **clampa** `scaled` a `[0,1]` (dominio eXeLearning) o **rechaza** `scaled<0` con 400?
  La spec permite `-1..1` (AN-014/M1, M2).
- PREG: para statements sin `statement.id`, ¿genera UUID server-side (pierde dedup cross-emit) o rechaza?
  El emisor envía UUID fresco por emit, así que en la práctica debería existir (AN-014/M5; cf. FTE-011 dedup).
