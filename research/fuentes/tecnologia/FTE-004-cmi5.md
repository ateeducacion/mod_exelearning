---
id: FTE-004
titulo: "cmi5"
categoria: estandar
version_consultada: "1.0"
enlaces_oficiales:
  - https://aicc.github.io/CMI-5_Spec_Current/
  - https://github.com/AICC/CMI-5_Spec_Current
context7:
  library_id: /adlnet/xapi-spec
  query: "cmi5 statement example, context category moveon and cmi5, result score scaled raw min max, progress extension, AU launch"
  fecha: 2026-05-29
  version_devuelta: "adlnet/xapi-spec (High): incluye el perfil cmi5 (ejemplo de statement con context.category moveon/cmi5 y result.score). Spec normativa cmi5 = AICC (ver enlaces_oficiales)."
fecha_consulta: 2026-05-29
relevancia_para_mod_exelearning: "Capa de empaquetado y reglas sobre xAPI que reemplaza SCORM: define `cmi5.xml`, AU (Assignable Units), launch model. Combina manifest tipo SCORM con tracking xAPI."
herramienta_ia:
  interfaz: claude-code
  modelo: claude-opus-4-7
---

## Qué es

> **[ACTUALIZACION 2026-06-17]** Ficha de bootstrap (2026-05-29). **cmi5 quedó FUERA DE ALCANCE** para
> `mod_exelearning`, con respaldo: su modelo (reproductor externo + LRS + AU/launch) no encaja con el recurso
> HTML embebido same-origin. Ver **DEC-17-01 §6**, **AN-014** (reconsideración matizada), **REPO-008**
> (mod_cmi5launch confirma player+LRS) y **FTE-017** (en xAPI.js cmi5 es paquete aparte `@xapi/cmi5`). Los
> `[PENDIENTE]` de más abajo se cierran por estar el tema fuera de alcance.

Perfil xAPI + paquete (`cmi5.xml`) definido por AICC/ADL como sucesor moderno de SCORM.
Cada AU se lanza con `fetchUrl` y reporta vía xAPI.

## Conceptos clave

- **AU** (Assignable Unit): unidad lanzable, ~equivalente a SCO.
- **Course structure** en `cmi5.xml` con bloques y AUs jerárquicos (= TOC).
- Verbos cmi5: `launched`, `initialized`, `completed`, `passed`, `failed`, `terminated`,
  `abandoned`, `waived`, `satisfied`.
- `masteryScore` por AU (0..1).

## API / Puntos de extensión relevantes

- Launch via URL con parámetros `endpoint`, `fetch`, `actor`, `registration`, `activityId`.
- Tracking 100% xAPI ⇒ reutiliza `core_xapi` de Moodle.

## Soporte para multi-grade-items

**Excelente.** Cada AU es naturalmente un grade item (≈ `1 AU → 1 itemnumber`).
`masteryScore` define el umbral por AU.

## Soporte para navegación/sidebar

Sí, via `course structure` en `cmi5.xml`. Compatible conceptualmente con la sidebar
eXeLearning si cada iDevice calificable se publica como AU.

## Implementaciones de referencia consultadas

- REPO-001 — mod_exescorm `datamodels/` incluye cmi5 (a confirmar nivel de soporte).
- [PENDIENTE: módulo cmi5 propio de Moodle o plugin contrib].

## Riesgos / Limitaciones

- Adopción menor que SCORM 1.2 en herramientas de autoría.
- Requiere LRS endpoint; en Moodle se cubre con `core_xapi` interno.

## Preguntas abiertas

- ¿eXeLearning publica cmi5 nativamente o requiere conversor? — PREG abrir.
- ¿Moodle expone `core_xapi` como endpoint cmi5-compatible (`fetchUrl` + auth)?
