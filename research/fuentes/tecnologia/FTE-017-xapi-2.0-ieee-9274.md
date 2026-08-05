---
id: FTE-017
titulo: "xAPI 2.0 (IEEE 9274.1.1-2023) — estado, documentación y aplicabilidad a mod_exelearning"
categoria: especificacion
version_consultada: "xAPI 2.0 = IEEE 9274.1.1-2023 (Active Standard, publicado 2023-10-06)"
enlaces_oficiales:
  - https://standards.ieee.org/ieee/9274.1.1/7321/
  - https://ieeexplore.ieee.org/document/10273185/
  - https://opensource.ieee.org/xapi/xapi-base-standard-documentation
  - https://xapi.ieee-saopen.org
  - https://sagroups.ieee.org/9274-1-1
context7:
  library_id: "[no en Context7: xAPI 2.0 = IEEE 9274.1.1 vive fuera del repo adlnet/xapi-spec (que es 1.0.3). Texto normativo consultado vía IEEE-SA Open (Apache-2.0)]"
  query: "[N/A — baseline 1.0.3 vía /adlnet/xapi-spec en FTE-015]"
  fecha: null
  version_devuelta: "[N/A]"
fecha_consulta: 2026-06-17
relevancia_para_mod_exelearning: "Decide la política de versión del endpoint xAPI (DEC-0-18): 2.0 NO obliga a nada (mod_exelearning no es un LRS), el modelo de Statement es retro-compatible y el emisor upstream envía 1.0.3 → consumir 1.0.3 pero validar la versión de forma PERMISIVA (tolerante a 2.0.0). Complementa FTE-015 (reglas canónicas 1.0.3)."
herramienta_ia:
  interfaz: claude-code
  modelo: claude-opus-4-8
---

## Qué es

**xAPI 2.0** es la sucesora de xAPI 1.0.3, estandarizada como **IEEE 9274.1.1-2023** («JSON Data Model
Format and RESTful Web Service for Learner Experience Data Tracking and Access»), aprobada el 2023-03-30 y
**publicada el 2023-10-06** (*Active Standard*). El trabajo pasó de **ADL a IEEE en 2019**; lo mantiene el
**IEEE Learning Technology Standards Committee (LTSC)** / grupo de trabajo **P9274.1.1** (comité C/LT). Es el
**primer estándar IEEE producido como open source** (licencia Apache-2.0, «the IEEE XAPI Authors»).

> **Nota de método:** el texto normativo se consultó vía IEEE-SA Open (markdown abierto), no Context7 (cuyo
> `/adlnet/xapi-spec` es 1.0.3, FTE-015). El portal `xapi.ieee-saopen.org` bloquea bots (HTTP 418); el
> contenido se obtuvo del repo open source `opensource.ieee.org/xapi`. `[PENDIENTE]` re-verificar verbatim
> los números de sección citados antes de transcribirlos literal en un ADR.

## Conceptos clave (citados al texto normativo IEEE 9274.1.1, consultado 2026-06-17)

- **Header de versión = `2.0.0`** (doc «for Content» §5.1.7): toda request/response al LRS lleva
  `X-Experience-API-Version`; ej. `2.0.0`. La propiedad `version` del Statement, si se fija, *«shall set it to
  2.0.0»*.
- **Aceptación de versiones por un LRS 2.0** (doc «for LRSs», versioning) — **el hallazgo decisivo**:
  - *«The LRS shall accept requests with a version header of 2.0 as if … 2.0.0.»*
  - *«The LRS shall **reject** requests with version header **prior to 2.0.0** unless … routed to a fully
    conformant implementation of a prior version.»*
  - *«The LRS shall reject requests with a version header of 2.1.0 or greater.»*
  - → **2.0 NO es transparente con 1.0.x a nivel de protocolo.** Esta cláusula vincula a un **LRS** conformante,
    no a un consumidor *ad hoc* same-origin como `mod_exelearning`.
- **`result.score` SIN cambios** (doc «for Content», Score Table): `scaled` decimal **[-1,1] inclusive**
  (Recommended); `raw` **entre `min` y `max`** si presentes; `min < max`. **Idéntico a 1.0.3** (FTE-015 §4.1.5).
- **Novedad real: `context.contextAgents` y `context.contextGroups`** (Context Table): ambos **Optional**,
  arrays que describen relaciones Agent/Group con el Statement. `contextActivities` (parent/grouping/...) intacto.
- **`timestamp`/`stored`:** `timestamp` ISO-8601/RFC-3339, precisión de milisegundos sigue siendo **SHOULD**
  (no MUST); `stored` lo fija el LRS. Sin cambio material para un consumidor.
- **Signed Statements (JWS):** prácticamente igual que 1.0.3 (RFC 7515, RS256/384/512, X.509 `x5c`).
- **`authority`/`authorization`:** *«Authorization was removed from the standard»* (estaba poco definida);
  varios `MUST→SHALL` y `SHOULD*→SHALL`, pero *«the requirements didn't change significantly»* (Rustici/xAPI.com).
- **HTTPS NO es MUST** (doc «for Content» §5.1.9 / «for LRSs» §4.1.9): *«Security is beyond the scope … 
  Implementors are encouraged to follow … HTTPS-Only»*. **Misma postura que 1.0.3** (desmiente el rumor de
  que 2.0 obliga HTTPS).

## API / Puntos de extensión relevantes

- **Documentación de acceso libre:** versión legible en `xapi.ieee-saopen.org`; markdown fuente (Apache-2.0)
  en `opensource.ieee.org/xapi/xapi-base-standard-documentation` (docs «Overview», «for Content», «for LRSs»).
  El PDF IEEE oficial se compra en IEEE Xplore/ANSI; el contenido normativo equivalente es **libre** vía
  IEEE-SA Open. `[PENDIENTE]` no verificada la inclusión en el *IEEE GET Program* como tal.
- **Conformancia 2.0:** existe suite oficial ADL (>1.400 tests para 2.0.0,
  `github.com/adlnet/lrs-conformance-test-suite`, `lrstest.adlnet.gov`); **Veracity Learning** fue el primer
  LRS comercial conformante con 2.0 (2023-09-14). Adopción **incipiente**; **1.0.3 sigue dominante** en
  producción y se soportará durante años. Adopters: `adopters.adlnet.gov`.

## Tabla de diferencias 2.0 vs 1.0.3 (para un consumidor que ignora actor/authority)

| Aspecto | 1.0.3 | 2.0.0 | ¿Afecta a `mod_exelearning`? |
|---|---|---|---|
| Header `X-Experience-API-Version` | `1.0.3` | `2.0.0` (`2.0`≡`2.0.0`) | Solo si validas el header; tú recibes `1.0.3` |
| LRS acepta versión anterior | LRS 1.0.x acepta `1.0.*` | **LRS 2.0 rechaza `<2.0.0`** | **No aplica: no eres LRS** |
| `result.score` (scaled/raw/min/max) | scaled∈[-1,1], raw∈[min,max] | **Idéntico** | **Nulo** (pipeline de notas igual) |
| `context.contextAgents`/`contextGroups` | No existen | **Nuevos (Optional)** | Solo si los leyeras; ignorables |
| `contextActivities`, `registration` | parent/... + UUID | Igual | Nulo |
| Signed Statements (JWS) | RFC 7515 | Igual | No firmas → nulo |
| HTTPS | "encouraged" | "encouraged" (igual) | Nulo (same-origin) |
| Forma del Statement | — | **Mayormente retro-compatible** | Un Statement 1.0.3 válido sigue válido en 2.0 |

## Soporte para multi-grade-items

Igual que 1.0.3 (FTE-015): xAPI no define grading. Los campos que consume el endpoint (`object.id` →
ideviceId → `itemnumber`; `result.score.scaled/raw/min/max`) son **idénticos** en 1.0.3 y 2.0.0 → la tubería
de notas no cambia con la versión.

## Soporte para navegación/sidebar

Ortogonal (xAPI no define UI).

## Implementaciones de referencia consultadas

- FTE-015 — xAPI 1.0.3 (baseline; reglas canónicas del endpoint).
- FTE-011 — `exe_xapi.js`: el emisor upstream envía `X-Experience-API-Version: 1.0.3` y `scaled=s/10`/`f/100`.
- REPO-008 — `mod_cmi5launch`: consumidor que usa `1.0.3` contra un LRS.

## Riesgos / Limitaciones

- **[INTERPRETACION] 2.0 no obliga a `mod_exelearning`:** su endpoint no es un LRS conformante (ignora `actor`,
  no implementa los recursos REST, no firma, no hace conformancia, es ingesta same-origin por `postMessage`).
  Las cláusulas SHALL de protocolo (incl. «rechazar `<2.0.0`») aplican a LRS/LRP, no a un consumidor parcial.
- **[INTERPRETACION] El emisor manda sobre la versión:** mientras upstream envíe `1.0.3`, fijar `2.0` en el
  consumidor sería incoherente (rechazarías a tu propio emisor). La fuente de verdad es el emisor, no el
  estándar más nuevo.
- **Migrar a "solo 2.0" o implementar un LRS** no aporta nada al caso embebido y rompería compatibilidad.
- `[PENDIENTE]` confirmar inclusión en IEEE GET Program; re-verificar verbatim los §§ del texto normativo.

## Preguntas abiertas

- PREG: si upstream migrara el emisor a `2.0.0` (header + `version:2.0.0` + quizá `contextAgents`), el
  consumidor —si solo lee `object.id` + `result.score` y es tolerante a versión— **seguiría funcionando sin
  cambios** `[HIPOTESIS]`. Única acción preventiva: no rechazar por header (ver DEC-0-18).
- PREG: ¿conviene registrar el perfil xAPI de eXeLearning (DEC-0-14 punto 3) como Profile IEEE-conforme algún
  día? Fuera de alcance hoy.
