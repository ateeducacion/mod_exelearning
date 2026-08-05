---
id: DEC-0063
titulo: "Reglas de validación canónica del endpoint xAPI y política de versión (1.0.3 con tolerancia a 2.0)"
estado: Propuesta
fecha: 2026-06-17
agentes:
  - erseco
  - claude-code
fuentes:
  - FTE-015
  - FTE-017
  - FTE-011
  - REPO-008
  - REPO-009
  - FTE-016
experimentos:
  - EXP-004
herramienta_ia:
  interfaz: claude-code
  modelo: claude-opus-4-8
---

# DEC-0063: Reglas de validación canónica del endpoint xAPI y política de versión (1.0.3 con tolerancia a 2.0)

## Contexto

`DEC-17-01` (Propuesta) fijó la **arquitectura** de ingesta dual SCORM 1.2 + xAPI: un normalizador fino
convierte cada statement a la estructura `itemscores` y reutiliza la tubería única
(`track::apply_item_scores()` → `grade_update()`), con el endpoint custom `submit_xapi_statement` ignorando
`actor` → `$USER`. Lo que `DEC-17-01` y `docs/xapi-integration-plan.md` **no** fijaron son las **reglas
concretas de validación** que ese endpoint debe imponer al statement que ingiere, ni **qué versión** de xAPI
consumir/validar. Hoy la spec se cita de forma indirecta (FTE-009 comparativa; FTE-011 emisor), no contra la
normativa.

La investigación AN-014 (cruce con el ecosistema ADL/xAPI: REPO-008 `mod_cmi5launch`, REPO-009
`Moodle-PHP-Libs`, FTE-015 xAPI 1.0.3 canónica, FTE-016 `@xapi/xapi`) y FTE-017 (xAPI 2.0 / IEEE
9274.1.1-2023) aportan ahora la base normativa para cerrar ese hueco. Este ADR **acepta como diseño
vinculante** (para PR2 / TAREA-015) las reglas de validación canónica (AN-014 M1/M2/M3/M4/M5/M6) y la política
de versión. **Complementa** `DEC-17-01` y `DEC-0014`; no los supersede. Es documental: la implementación va en
TAREA-015, condicionada a que el contrato upstream `exelearning#1867` (FTE-011) se congele.

## Problema

¿Qué reglas **canónicas** (citables a xAPI 1.0.3) debe imponer `submit_xapi_statement` al validar lo que
ingiere —antes de normalizar a `itemscores`— y **qué versión** de xAPI debe esperar/validar, dado que (a) el
emisor upstream envía `X-Experience-API-Version: 1.0.3`, (b) xAPI 2.0 (IEEE 9274.1.1) ya está publicada, y (c)
`mod_exelearning` **no es un LRS** sino un consumidor same-origin que ignora la identidad del cliente?

## Opciones consideradas

### Sobre la VALIDACIÓN

1. **Validación mínima actual** (statu quo del plan): solo «`object.id` desconocido → reject».
   - ✘ Deja entrar statements sintácticamente inválidos; RIE-013 se mitiga por intuición, no por regla citable.
2. **Validación canónica sintáctica (elegida).** Codificar como rechazos duros las reglas de xAPI 1.0.3
   (FTE-015) sobre los campos que se consumen.
   - ✔ Defensa en profundidad citable; cierra RIE-013 con normativa.
   - ✔ Coherente con la tubería existente (que ya ignora objectids no registrados y no confía el overall).
3. **Validación semántica completa tipo LRS** (esquema JSON exhaustivo de todo el statement, State/Profile, etc.).
   - ✘ `mod_exelearning` no es un LRS; sobre-ingeniería. La spec dice validar **sintaxis, no significado**
     (FTE-015, `xAPI-Data.md` §4.4).

### Sobre la VERSIÓN

A. **Fijar estrictamente 1.0.3 y rechazar otros headers.**
   - ✘ Rechazaría a un futuro emisor 2.0; frágil ante migración upstream.
B. **Migrar el consumidor a 2.0 / implementar un LRS conformante.**
   - ✘ Un LRS 2.0 **debe rechazar** header `<2.0.0` (FTE-017): rechazaría al emisor `1.0.3` **actual**.
     Sobre-ingeniería sin beneficio para el caso embebido.
C. **Consumidor tolerante a versión, alineado con 1.0.3 (elegida).** Esperar/validar `1.0.x` y, defensivamente,
   aceptar también `2.0`/`2.0.0`; nunca rechazar por header.
   - ✔ Retro/forward-compatible a coste cero: los campos consumidos (`object.id`, `result.score.scaled/raw/min/max`)
     son **idénticos** en 1.0.3 y 2.0.0 (FTE-017).
   - ✔ La regla 2.0 «rechazar `<2.0.0`» vincula a un LRS, no a este consumidor *ad hoc*.

## Evidencia

- **Reglas canónicas 1.0.3:** FTE-015 — `score.scaled ∈ [-1,1]`, `raw ∈ [min,max]` (`xAPI-Data.md` §4.1.5);
  validación **sintáctica** y rechazo de `null` fuera de `extensions`/IRI sin esquema (§4.4); idempotencia por
  `statement.id` con semántica 409/400 (`xAPI-Communication.md` §2.1.2/2.1.3); `authority`/`stored` los controla
  el LRS y el cliente no asegura su identidad (§4.1.9/4.1.10); `X-Experience-API-Version` obligatorio en HTTP
  (§6.2). Confirmado por Context7 `/adlnet/xapi-spec` (2026-06-17).
- **Estado xAPI 2.0:** FTE-017 — IEEE 9274.1.1-2023 (Active); modelo de Statement **retro-compatible**
  (`result.score` idéntico; solo `contextAgents`/`contextGroups` nuevos y Optional); un **LRS 2.0 rechaza
  header `<2.0.0`** (vincula a LRS); HTTPS no es MUST; 1.0.3 dominante en producción.
- **Contrato del emisor:** FTE-011 — `exe_xapi.js` envía `1.0.3`, `scaled=s/10` por iDevice y `f/100` por
  paquete (no `[0,1]` uniforme), `statement.id` UUID fresco por emit (dedup de página débil).
- **Patrón de referencia:** REPO-008 — `mod_cmi5launch` selecciona «highest score wins» por sesión y **no**
  deduplica por `statement.id` (carencia que cubre `exelearning_tracking_events`). AN-012/AN-014 — mapeo y modelo
  de confianza. REPO-009 — `Moodle-PHP-Libs` descartado como dependencia. FTE-016 — tipos `@xapi/xapi` para
  fixtures.

## Decisión

**Validación = Opción 2 · Versión = Opción C.** El endpoint `classes/external/submit_xapi_statement` (PR2)
impondrá, **antes** de normalizar a `itemscores`:

1. **Rangos de score** (FTE-015 §4.1.5): rechazar `result.score.scaled ∉ [-1,1]` y `raw ∉ [min,max]` cuando
   `min`/`max` están presentes. El dominio de eXeLearning es `[0,1]`: un `scaled<0` está **fuera del dominio
   esperado** del emisor → **rechazo** (no clamp), salvo que se documente lo contrario en la implementación.
2. **Mapeo de escala** (FTE-011): documentar que `scaled` ya viene normalizado a `[0,1]` (`s/10` por iDevice,
   `f/100` por paquete), por lo que `scorepct = scaled*100` es correcto; validar el rango igualmente.
3. **Lista blanca de verbos:** `answered`/`completed`/`passed`/`failed`/`initialized`/`terminated`; cualquier
   otro → ignorar (no error).
4. **Object/identidad de actividad:** `object.id` debe ser IRI absoluta resoluble a un `ideviceId →
   exelearning_grade_item.objectid → itemnumber` **de esta instancia** (DEC-5-01); desconocido → **rechazo**.
5. **Validación sintáctica** (FTE-015 §4.4): rechazar `null` fuera de `extensions` y tipos/IRI mal formados.
6. **Confianza cero en el cliente** (FTE-015 §4.1.9/4.1.10, ancla normativa de AN-012): **ignorar** `actor`,
   `authority`, `stored` y `timestamp` del cliente → atribuir a `$USER` (capability `mod/exelearning:savetrack`),
   fijar `stored = now` server-side, tratar `timestamp` como informativo. Validar `event.origin` en el listener
   (RIE-013).
7. **Idempotencia y selección** (FTE-015 §2.1.2/2.1.3 + REPO-008): dedup por `statement.id` en
   `exelearning_tracking_events` (`statementid` UNIQUE) → ante id repetido **no** re-aplicar `apply_item_scores`
   (no modificar estado); registrar conflicto si el payload difiere (semántica 409). **Distinta** del dedup: ante
   varios `answered` del mismo `(registration, ideviceId)`, seleccionar **el de mayor score** («highest score
   wins», análogo a `cmi5launch_retrieve_score`), coherente con el `grademethod`/`aggregate_values` existente.
8. **Política de versión (Opción C):** esperar/validar de forma **permisiva** — aceptar `1.0.x` y, defensivamente,
   `2.0`/`2.0.0`; **nunca** rechazar por header de versión ni por `statement.version`. No migrar a «solo 2.0» ni
   implementar un LRS. El header HTTP `X-Experience-API-Version` solo aplica a la rama `POST {endpoint}statements`;
   en la vía `postMessage` (transporte principal) validar `statement.version` solo si está presente.
9. **Descartes de alcance explícitos** (eco de DEC-17-01 §6, ahora con respaldo normativo): **fuera de alcance**
   State API/Document Resources, Agent/Activity Profile, Signed Statements (JWS), cmi5 y LRS externo. Su valor
   está en un LRS completo / catálogos lanzables, no en un recurso embebido same-origin (FTE-015, FTE-017, FTE-016
   `@xapi/cmi5` aparte).

## Consecuencias

- **Positivas:** el endpoint gana reglas de validación **citables** (cierra RIE-013 con normativa, no intuición);
  **forward-compatible con xAPI 2.0 a coste cero** (campos consumidos idénticos); idempotencia alineada con la
  semántica canónica del LRS; «highest score wins» elimina ambigüedad ante `answered` repetidos.
- **Negativas / coste:** consume un contrato upstream **draft** (RIE-013, churn posible); las reglas añaden
  código de validación en PR2; el «modelo neutro» sigue implícito en `itemscores` (DEC-0007/DEC-17-01).
- **Cambios que dispara:** alimenta `docs/xapi-integration-plan.md` (§4 validación, §5 escala, §6 idempotencia,
  §7 fuera de alcance) y `docs/tracking-architecture.md` (trust boundary) cuando se implemente; `thirdpartylibs.xml`
  **no** cambia (REPO-009 descartado; solo `pipwerks`). Refuerza la postura «cmi5 fuera de alcance» de DEC-0014.

## Riesgos

- **RIE-013 (existente) — consumir contrato upstream no congelado.** Mitigación: gating de PR2 a que #1867 fije
  el envelope/`parentOrigin`; suite `exe_xapi.test.js`; validación canónica de este ADR como defensa en profundidad.
- **RIE (nuevo, severidad baja) — deriva de versión upstream.** Si eXeLearning migrara el emisor a `2.0.0`, un
  consumidor que rechazara por header se rompería. **Mitigado por diseño:** la Opción C valida la versión de forma
  permisiva (acepta `1.0.x` y `2.0.0`) y solo lee `object.id` + `result.score`, idénticos entre versiones (FTE-017).

## Validación

- PR2 (TAREA-015) — PHPUnit del endpoint: rechazo de `scaled` fuera de rango y `raw ∉ [min,max]`; rechazo de
  `object.id` desconocido; verbo fuera de lista blanca → ignorado; dedup por `statement.id` (no re-aplica);
  «highest score wins» por `(registration, ideviceId)`; `actor` ignorado → `$USER`; aceptación de headers
  `1.0.3` **y** `2.0.0`; paridad de nota con el camino SCORM. Fixtures derivadas de los tipos `@xapi/xapi`
  (FTE-016). E2e en navegador capturando `postMessage` reales (EXP-004 fase viva).

## Seguimiento

- Al implementar (PR2): fundir las reglas en `docs/xapi-integration-plan.md` y `tracking-architecture.md`, y
  añadir docblocks citando `(DEC-0063)` y `(see FTE-015/FTE-017)`.
- Pendientes heredados (AN-014): backup del tracking envuelto por `userinfo` (M8, verificar
  `backup_cmi5launch_stepslib.php` antes de citarlo como anti-patrón); decisión `custom vs core_xapi` (FTE-007/
  AN-003); `registration ↔ sessiontoken` (formato UUID server-side).
- Continúa TAREA-015 (gated a #1867) y PREG-002 (cambios upstream).

## Resoluciones de diseño (erseco, 2026-06-17)

Resueltas las preguntas abiertas que el ADR/AN-014 dejaban al maintainer. El núcleo de la decisión
(validación canónica + política de versión) no cambia; el ADR sigue **Propuesta** (gated a #1867):

1. **`result.score.scaled` fuera de `[0,1]` → RECHAZO (400), no clamp.** El emisor de eXeLearning solo
   produce `[0,1]` (`s/10` por iDevice, `f/100` por paquete); un valor fuera de rango es señal de statement
   malformado o de origen no-eXe → rechazo ruidoso + log. (Se mantiene también el rechazo de `scaled∉[-1,1]`
   de la spec; el dominio efectivo aceptado es `[0,1]`.)
2. **Overall (`itemnumber=0`) = RECÁLCULO server-side desde los `answered`**, con paridad total con el camino
   SCORM (`recompute_overall_pct`, DEC-6-01). El statement de paquete (`passed`/`failed`) se usa solo como
   señal de `status`/`success`, **no** como fuente del agregado; el servidor nunca confía el overall del cliente.
3. **`registration` ↔ `sessiontoken`: CONVIVEN.** SCORM mantiene `sessiontoken` (`random_string(20)`); xAPI usa
   `registration` (UUID generado/controlado server-side) como su llave de intento sobre la **misma** tabla
   `exelearning_attempt`. No se toca el camino SCORM productivo.
4. **Vía de ingesta = ENDPOINT CUSTOM** (`classes/external/submit_xapi_statement`, ignora `actor`→`$USER`,
   reusa `apply_item_scores`) **+ handler `core_xapi` OPCIONAL más tarde** solo para eventos/analítica. Cierra
   la duda `custom vs core_xapi` del Seguimiento.
