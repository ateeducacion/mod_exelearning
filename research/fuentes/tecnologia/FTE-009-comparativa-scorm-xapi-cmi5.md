---
id: FTE-009
titulo: "Comparativa de estándares e-learning: SCORM vs xAPI vs cmi5 (y dónde encaja mod_exelearning)"
categoria: estandar-comparativa
version_consultada: "2026"
enlaces_oficiales:
  - https://xapi.com/cmi5/comparison-of-scorm-xapi-and-cmi5/
  - https://aicc.github.io/CMI-5_Spec_Current/tincan/
  - https://aicc.github.io/CMI-5_Spec_Current/SCORM/
  - https://www.ispringsolutions.com/blog/elearning-standards
  - https://www.ispring.es/blog/aicc-vs-scorm-vs-xapi-vs-cmi5
context7:
  library_id: /adlnet/xapi-spec
  query: "cmi5 profile of xAPI, moveOn masteryScore AU assignable unit, session registration, verb sequence"
  fecha: 2026-05-29
  version_devuelta: "adlnet/xapi-spec + aicc.github.io/CMI-5_Spec_Current (consulta web 2026-05-29)"
fecha_consulta: 2026-05-29
relevancia_para_mod_exelearning: "Fundamenta la hoja de ruta de tracking (DEC-0003 vigente SCORM 1.2, DEC-0014 xAPI). Sirve para decidir si una futura capa xAPI/cmi5 merece la pena y qué exige de eXeLearning."
herramienta_ia:
  interfaz: claude-code
  modelo: claude-opus-4-8
---

## Resumen ejecutivo

> **[ACTUALIZACION 2026-06-17]** Comparativa de bootstrap (2026-05-29). Matiz de staleness: donde el cuerpo
> afirma como presente que «eXeLearning **no emite** xAPI» (input de DEC-0014/DEC-0015), eso **ya no es cierto**:
> upstream emite statements xAPI por iDevice (PR #1867, **FTE-011**); el consumo + validación canónica está en
> **FTE-015**, la versión 2.0 / forward-compat en **FTE-017**, y la síntesis en **AN-014** (decisión
> **DEC-17-01/DEC-0063**). La comparativa de estándares sigue válida; solo el «hoy no emite» quedó superado.

Tres estándares de tracking en juego (AICC queda descartado: legado de 1988, grupo
disuelto en 2014 — iSpring). En una frase:

- **SCORM (1.2 / 2004)**: instrucciones de lanzamiento + criterios de finalización
  normalizados, pero contenido **atado al paquete y al navegador/mismo dominio**, y
  datos limitados a elementos predefinidos que viven **en el LMS**. Soporte casi
  universal. Es lo que `mod_exelearning` usa hoy (vía puente SCORM 1.2, DEC-0003).
- **xAPI (Experience API / Tin Can)**: statements `actor-verb-object[-result]`
  flexibles enviados a un **LRS**; rastrea cualquier cosa (móvil, offline, juegos),
  datos portables. **Pero** "pelado" carece de reglas estandarizadas de
  lanzamiento/finalización y vocabulario → difícil de explotar de forma
  interoperable.
- **cmi5**: **perfil de xAPI** (no un subconjunto) para el caso de uso
  "**el LMS lanza el contenido**". Combina la **estructura de SCORM** con la
  **trazabilidad de xAPI**. Adopción creciente; "todos los authoring tools
  modernos ya lo soportan" (iSpring).

## Citas clave (autoritativas)

- cmi5 ⊂ xAPI: «ADL actively maintains cmi5 as an xAPI profile that defines the
  "LMS launches content" use case. cmi5 is implemented using the Experience API
  (xAPI) as the content-to-LMS communication layer». (aicc.github.io/tincan)
- Problema del xAPI pelado: «While xAPI Statements can capture all kinds of data,
  it becomes challenging for systems to extrapolate and analyze that data in a
  meaningful way without a defined vocabulary and instructions». (xapi.com)
- cmi5 como puente: «cmi5 bridges that gap by having xAPI's tracking flexibility
  while maintaining the structure of SCORM». (xapi.com)
- SCORM vs cmi5 (ubicación de datos): SCORM «all content is required to be located
  in the package... same domain as the LMS»; cmi5 «content does not have to be in
  the package and can be located on any domain/local device» y los datos van a un
  **LRS** vía REST/JSON. (aicc.github.io/SCORM)

## Qué añade cmi5 sobre xAPI pelado

- **AU (Assignable Units)**: 1..1000 por curso, jerarquía lanzable con criterios de
  progresión.
- **moveOn** + **masteryScore** por AU → finalización/aprobado interoperable.
- **session** + **Context.registration** en cada statement → reporting normalizado.
- **Secuencia de verbos definida** en una sesión de AU (launched, initialized,
  passed/failed, completed, terminated…).
- **"fetch URL"** (servicio de token) para autenticación segura del AU contra el LRS.

## Tabla de decisión (sintetizada)

| Criterio | SCORM 1.2/2004 | xAPI | cmi5 |
|---|---|---|---|
| Modelo | contenido en paquete, datos al LMS | statements a LRS | xAPI + reglas de launch/finalización |
| Granularidad de datos | predefinida (CMI) | libre (verbos/IRIs) | libre + vocabulario normalizado |
| Offline / móvil / no-navegador | no | sí | sí |
| Finalización interoperable | sí | no (sin perfil) | sí (moveOn/masteryScore) |
| Soporte LMS | casi universal | medio | creciente |
| Lanzamiento "LMS→contenido" | sí | no definido | sí (definido) |

## SCORM 1.2 vs xAPI en mod_exelearning (capa implementada, DEC-85-01)

> **[ACTUALIZACION 2026-06-18]** Con la ingesta xAPI ya implementada (PR de TAREA-015), esta tabla
> compara los **dos canales de calificación** tal como conviven en el plugin —a diferencia de la «Tabla de
> decisión» de arriba, que compara los estándares en abstracto e incluye cmi5—. El plugin usa **exactamente
> un** canal por paquete: xAPI si el paquete lo emite, SCORM en caso contrario.

| Dimensión | SCORM 1.2 (camino heredado) | xAPI (esta capa) | Ventaja |
|---|---|---|---|
| Transporte | shim `window.API` (pipwerks) que Moodle inyecta y fuerza a init | `postMessage` que el paquete emite de forma nativa | **xAPI** — sin shim ni dependencia de pipwerks |
| Detalle por iDevice | se parsea del string `cmi.suspend_data` con una regex sensible al idioma | un statement `answered` estructurado por iDevice | **xAPI** — sin parseo frágil de cadenas |
| Campo de puntuación | `cmi.core.score.raw` + el formato que serializa eXeLearning | `result.score.{scaled,raw,min,max}` tipado | **xAPI** — sólo se rompe si cambia la spec, no el formato del productor |
| Riqueza de interacción | puntuación global + estado | verbos, resultados por iDevice, contexto, extensiones | **xAPI** — captura mucho más que una nota final |
| Overall ponderado | se recalcula en servidor desde los ítems (los pesos viajan en suspend_data) | se toma del `finalScore` del paquete (los `answered` no llevan peso) y se valida | **SCORM** — el peso viaja con cada ítem; xAPI depende del statement de paquete (aquí se conserva la paridad) |
| Identidad / confianza | el paquete no afirma nada; el servidor usa `$USER` | el actor es anónimo por diseño; el servidor usa `$USER` | **empate** — ambos de confianza total en servidor |
| Idempotencia | ninguna (el upsert del intento absorbe repeticiones) | deduplicado por `statement.id` (`exelearning_tracking_events`) | **xAPI** — auditoría exactamente-una-vez |
| Offline / móvil / sin navegador | no (requiere el runtime SCORM en navegador) | sí (los mismos statements pueden ir a un LRS) | **xAPI** — portable más allá del iframe |
| Acoplamiento al productor | exige inyectar pipwerks + el parche del guard de `form`/`scrambled-list` (DEC-13-11) | ninguno — el emisor está siempre activo en cada export | **xAPI** — menos mutaciones en servido |
| Estado del estándar | heredado (SCORM 1.2, era 2004) | actual (xAPI 1.0.3, compatible hacia 2.0) | **xAPI** — moderno y mantenido |
| Ubicuidad LMS / tooling | casi universal, décadas de soporte | estándar moderno, adopción creciente | **SCORM** — máxima compatibilidad |
| Madurez en el plugin | productivo, por defecto desde DEC-0003 | nuevo en esta capa | **SCORM** — probado |
| Listo para analítica / LRS | no (los datos quedan como notas Moodle) | statements con forma de LRS (handler `core_xapi` futuro, diferido) | **xAPI** — vía hacia learning analytics |

**En resumen**

- **SCORM 1.2 destaca en** ubicuidad y madurez, y en llevar el peso por iDevice en línea (el overall
  ponderado no necesita una señal aparte). Permanece como camino de compatibilidad para paquetes
  anteriores al emisor xAPI (DEC-0003).
- **xAPI destaca en** granularidad estructurada por interacción, eliminar la regex frágil de
  `suspend_data` y la dependencia de pipwerks, idempotencia, portabilidad (móvil/offline/LRS) y ser el
  estándar moderno y a prueba de futuro. Es el canal primario para los paquetes que lo emiten (DEC-85-01).

## Implicación para mod_exelearning (ver DEC-0014 y AN-010)

- Hoy el bridge es **SCORM 1.2** porque eXeLearning **no emite xAPI** (FTE-007).
  Funciona y da multi-itemnumber con un shim (ver AN-010 / glosario "shim").
- Para una capa xAPI nativa harían falta cambios **aguas arriba en eXeLearning**
  (emitir statements por iDevice), no solo en el plugin. cmi5 añadiría además el
  modelo de lanzamiento (AU/fetch URL), que **no encaja** con el caso actual
  (contenido servido embebido por Moodle, no "lanzado" a un LRS externo).
- Conclusión preliminar: **xAPI puro** (consumido por `core_xapi` de Moodle) es el
  objetivo natural si upstream emite statements; **cmi5 es probablemente excesivo**
  para el caso "recurso embebido en Moodle" (su valor está en LRS externos y
  catálogos de cursos lanzables). Se detalla en DEC-0014 / DEC-0015.
