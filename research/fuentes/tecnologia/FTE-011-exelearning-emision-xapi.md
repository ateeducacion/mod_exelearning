---
id: FTE-011
titulo: "Emisión xAPI en eXeLearning (exe_xapi.js, PR upstream #1867)"
categoria: api-otro
version_consultada: "exelearning/exelearning PR #1867 — MERGED en commit e3b1bd13 (2026-06-18); contrato verificado antes en el draft 59b9b9b"
enlaces_oficiales:
  - https://github.com/exelearning/exelearning/pull/1867
  - https://raw.githubusercontent.com/exelearning/exelearning/59b9b9b46f20a92dd86631c173b325f6c0940274/public/app/common/xapi/exe_xapi.js
  - https://github.com/adlnet/xAPI-Spec/blob/master/xAPI-Data.md
context7:
  library_id: /adlnet/xapi-spec
  query: "verb IRIs answered/completed/passed/failed/initialized/terminated, result.score scaled raw min max, activity definition cmi.interaction y assessment, context registration y contextActivities.parent, actor anonymous account"
  fecha: 2026-06-04
  version_devuelta: "adlnet/xapi-spec (xAPI-Data.md / xAPI-About.md) — High. Confirma answered + cmi.interaction + result.success, result.score {scaled,raw,min,max}, completed/failed, context.registration, contextActivities.parent y actor con account.name='anonymous'."
fecha_consulta: 2026-06-04
relevancia_para_mod_exelearning: "Desbloquea DEC-0-14: los paquetes publicados ya emiten statements xAPI por postMessage; mod_exelearning puede consumirlos reusando el mapa objectid→itemnumber (DEC-5-01) y la tubería de intentos (DEC-0-07)."
herramienta_ia:
  interfaz: claude-code
  modelo: claude-opus-4-8
---

## Qué es

> **Actualización 2026-06-18 — PR #1867 MERGEADO (`e3b1bd13`).** El contrato está
> **congelado** y coincide con lo documentado abajo (verbos, envoltorio
> `{type:'exe-xapi-statement', statement}`, `object.id={baseIri}/idevice/{ideviceId}`,
> `result.score.scaled`, actor anónimo, `parentOrigin` configurable). Dos adiciones de
> seguridad upstream: (a) **anonimización de PII** al difundir a `'*'` sin `parentOrigin`
> (`_postToParent` sustituye el actor por anónimo); (b) **escape XSS** (`</script>`,
> U+2028/U+2029) en la inyección `window.exeXapi={…}` (`serializeForScript`). Confirmado
> además: el `answered` por iDevice **no lleva peso** (vive en `_state`, plegado en el
> `finalScore` del statement de paquete) y el export web sirve `odeId` vacío (routear por
> el `idevice-id` extension). Esto desbloquea **TAREA-015** e informa **DEC-85-01**.

`exe_xapi.js` es el **emisor xAPI siempre-activo** que el PR upstream
[`exelearning/exelearning#1867`](https://github.com/exelearning/exelearning/pull/1867)
(`feat(export): emit xAPI statements from published packages`, **mergeado 2026-06-18 en
`e3b1bd13`**; contrato observado en el draft `59b9b9b46f20a92dd86631c173b325f6c0940274`,
rama `feature/add-xapi-support`) añade a **todos** los formatos de exportación
(`public/app/common/xapi/exe_xapi.js:16-22`). Se incluye vía `BASE_LIBRARIES`, de modo
que cualquier paquete publicado es "xAPI-compatible out of the box", **sin opción de
exportación**.

Esto **invalida el hecho de partida de DEC-0-14** ("eXeLearning upstream NO emite xAPI
hoy", verificado 2026-05-29) y **reactiva** su hoja de ruta (opción C: SCORM 1.2 vigente
+ xAPI cuando upstream lo emita).

> Nota de método: este contrato se documenta **leyendo el código fuente** del PR
> (`exe_xapi.js` + `exe_xapi.test.js`), no a partir de un resumen. Un resumen automático
> previo afirmó por error que el statement se emitía "crudo, sin envoltorio con `type`";
> el código real **sí** usa el envoltorio `{ type: 'exe-xapi-statement', statement }`
> (`exe_xapi.js:468`). Prevalece el código.

## Conceptos clave

- **Independiente de SCORM/pipwerks.** La capa de gamificación (`common.js`) llama a
  `gamification.track(...)`, que reenvía aquí, sea cual sea el formato de export
  (`exe_xapi.js:24-25`). No depende del bridge SCORM 1.2 que hoy usa mod_exelearning.
- **Configuración inyectada por el exportador** en `<head>` como `window.exeXapi`
  (`exe_xapi.js:157-173`): `{ odeId, baseIri, activityId, packageTitle, language, actor,
  parentOrigin, registration }`. `baseIri` por defecto =
  `https://exelearning.net/xapi/{odeId}`; `activityId` por defecto = `baseIri`.
  → **Punto de control del host:** Moodle, que sirve el paquete, puede inyectar
  `window.exeXapi` (igual que ya inyecta el loader pipwerks) y fijar `parentOrigin`,
  `registration` y `actor`.
- **Identidad de actividad estable.** `object.id` por iDevice =
  `{baseIri}/idevice/{ideviceId}`, donde `ideviceId` es el `<odeIdeviceId>` estable
  (PR upstream #1791, ver DEC-0-12/DEC-5-01) — el **mismo** valor que
  `exelearning_grade_item.objectid`.

## Contrato observado (código, `exe_xapi.js` @ 59b9b9b)

### Transporte (`_send` → ambos pueden ejecutarse, `exe_xapi.js:455-486`)
1. **postMessage al padre** (`_postToParent`, `:460-471`): si hay ventana padre real,
   `root.parent.postMessage({ type: 'exe-xapi-statement', statement }, target)` con
   `target = config.parentOrigin || '*'`. El `'*'` es **fallback** sólo cuando no se
   configuró `parentOrigin` (comentario `:464-467`: "statements carry no PII, so the
   fallback is safe by design").
2. **POST a LRS** (`_postToLrs`, `:473-486`): sólo si la URL trae parámetros de launch
   (`endpoint` + `auth`); `fetch(endpoint + 'statements', POST,
   headers{Authorization, X-Experience-API-Version: '1.0.3'})`.
3. **No-op** si no hay ninguno (web plano / EPUB offline).

### Verbos (ADL, `exe_xapi.js:48-56`)
`answered`, `completed`, `passed`, `failed`, y los de ciclo de vida `initialized` /
`terminated`. Los de ciclo de vida son **xAPI genérico, NO cmi5** (comentarios `:53`,
`:359`). cmi5 queda fuera del PR.

### Statements emitidos
- **Por iDevice — `answered`** (`_buildIdeviceStatement`, `:276-296`), en cada `emit()`
  con score numérico:
  - `object.id = {baseIri}/idevice/{ideviceId}`; `definition.type =
    .../activities/cmi.interaction`; `definition.name` = mapa de idioma del título;
    `definition.extensions[".../extensions/idevice-type"]`.
  - `result.score = { scaled: score/10, raw: score, min: 0, max: 10 }` (escala
    **0..10** del iDevice), `result.success = score >= 5`, `result.completion = true`.
  - `context.contextActivities.parent = [{ id: activityId }]`.
  - `context.extensions`: `package-id` (odeId), `idevice-id`, `idevice-type`,
    `page-id`, `page-title` (sólo las presentes; `_contextExtensions`, `:306-316`).
- **Por paquete — `completed` + (`passed` | `failed`)** (`_buildPackageStatements`,
  `:325-338`), recalculado en cada `emit()` desde el estado acumulado
  (`getFinalScore`, escala **0..100**):
  - `object` = Activity del paquete (`id = activityId`, `definition.type =
    .../activities/assessment`).
  - `result.score = { scaled: finalScore/100, raw: finalScore, min: 0, max: 100 }`,
    `success = finalScore >= 50` (`PASS_THRESHOLD`, `:74`), `completion = true`.
  - `passed` si `>= 50`, si no `failed`; `completed` siempre.
- **Ciclo de vida — `initialized` / `terminated`** (`:113-148`, `:364-366`): sólo si hay
  transporte; cada uno **una vez**; `terminated` en `pagehide`/`unload`; **sin** `result`.

### Envoltorio del statement (`_statement`, `:378-400`)
`{ id: uuidv4(), actor, verb, object, timestamp, [result], [context] }`. `context`
incluye `registration` (de launch o config, `:408-412`), `contextActivities.parent` y
`extensions` cuando existen.

### Actor (`_actor`, `:437-447`)
`config.actor || launch.actor ||` **anónimo**
`{ objectType: 'Agent', account: { homePage: baseIri, name: 'anonymous' } }`. El emisor
**nunca inventa PII**; el comentario es explícito: "the host (e.g. Moodle) is
authoritative and will attach/override the real learner".

### Deduplicación (`_isDuplicate`, `:496-502`)
En página, por clave (`idevice:{ideviceId}` o `package:{verb.id}`) + firma
(`verb.id | result.score.raw`): un re-render con el mismo score no re-emite. **El
`statement.id` es un UUID fresco por emisión** (`uuidv4`, `:526-537`), no estable entre
re-emisiones del mismo score.

## Conformidad con la spec (Context7 `/adlnet/xapi-spec`, 2026-06-04)
La forma emitida coincide con la spec: `answered` + `cmi.interaction` + `result.success`;
`result.score {scaled, raw, min, max}` (ej. spec cmi5: `scaled 0.65, raw 65, min 0, max
100`); `completed`/`failed`; `context.registration`; `contextActivities.parent`; y actor
con `account.name = 'anonymous'`. [HIPOTESIS] No se observan desviaciones del estándar.

## Soporte para multi-grade-items
**Excelente y directo.** Un `answered` por iDevice calificable con `object.id` estable →
se mapea 1:1 a `exelearning_grade_item.objectid → itemnumber` (DEC-5-01). El statement de
paquete (`passed`/`failed`) entrega además el **overall** ya ponderado, evitando el
recálculo servidor que el camino SCORM necesita (DEC-6-01).

## Soporte para navegación/sidebar
Ortogonal: xAPI no define UI. La sidebar la sigue sirviendo el paquete nativo (intacto).

## Implementaciones de referencia consultadas
- REPO-005 — `exelearning/exelearning` PR #1867:
  `public/app/common/xapi/exe_xapi.js` (emisor) y `exe_xapi.test.js` (vitest; codifica el
  contrato: IRIs por odeId, score escalado, passed/failed por umbral 50, dedup,
  `parentOrigin` como `targetOrigin`, actor anónimo, lifecycle una vez).
- REPO-004 / FTE-007 — `core_xapi` (consumidor Moodle) y `mod_h5pactivity` (AN-003).
- FTE-003 — spec xAPI 1.0.3 (verbos, result.score, IRIs).

## Riesgos / Limitaciones
- **Contrato draft.** El PR no está mergeado; la forma puede cambiar antes del merge
  (RIE-013). La cobertura de `exe_xapi.test.js` reduce el riesgo de cambios silenciosos.
- **Fallback `parentOrigin = '*'`.** Si el host no fija `parentOrigin`, el statement se
  emite a `'*'`. Mitigación en el consumidor: el host **fija** `parentOrigin` a su origen
  y, además, valida `event.origin` al recibir (defensa en profundidad). Ver AN-012.
- **Actor anónimo / no fiable.** El consumidor debe **ignorar** `actor` y usar el usuario
  Moodle autenticado (`$USER`).
- **`statement.id` no idempotente** entre re-emisiones del mismo score (UUID fresco): la
  dedup fuerte es la de página; el servidor debe deduplicar por su propia clave si lo
  necesita.

## Preguntas abiertas
- ¿Congelará el PR #1867 el contrato (`{type, statement}` + `parentOrigin`) antes del
  merge? → seguimiento en PREG-002 / TAREA-015.
- ¿Conviene que mod_exelearning ingiera vía `core_xapi_statement_post` (FTE-007) o vía un
  endpoint propio que ignore el actor? → analizado en AN-012, se decide en DEC-17-01.
