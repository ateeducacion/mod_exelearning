---
id: DEC-0001
titulo: "Metodología de evidencia y ADRs para mod_exelearning"
estado: Aceptada
fecha: 2026-05-28
agentes:
  - erseco
  - claude-code
fuentes:
  - REPO-004
experimentos: []
herramienta_ia:
  interfaz: claude-code
  modelo: claude-opus-4-7
---

# DEC-0001: Metodología de evidencia y ADRs para mod_exelearning

## Contexto

`mod_exelearning` es un plugin Moodle nuevo cuyo diseño cruza tres dominios: la API
de Moodle (grading, xAPI, file storage, backup), los estándares LMS (SCORM 1.2/2004,
xAPI, cmi5, LTI 1.3 AGS) y el formato de paquete publicado por eXeLearning. Decidir
sin disciplina llevaría a un plugin frágil acoplado a una hipótesis no verificada.

## Problema

¿Cómo se toman, registran y revisan las decisiones técnicas durante la fase de
investigación y construcción del plugin?

## Opciones consideradas

1. **Notas libres en `/doc`** — flexible, pero sin trazabilidad ni separación
   hecho/decisión.
2. **ADRs sobre estructura plana `/research`** — disciplina formal, evidencia citada,
   IDs estables, append-only. Inspirado en `learningml-ng`.
3. **Estructura numerada 00..11 completa** (como `learningml-ng`) — más rígida,
   posiblemente excesiva para el alcance actual.

## Evidencia

- Repo de referencia `/Users/ernesto/Downloads/git/learningml-ng` muestra el modelo
  funcionando: 12 ADRs, 29 tareas, 9 experimentos, trazabilidad completa.
- AGENTS.md de este repo formaliza las reglas.

## Decisión

Se adopta la **Opción 2**: estructura plana en `research/` con disciplina de evidencia,
IDs estables, append-only e índice generado. Layout descrito en
[`../../README.md`](../../README.md). Reglas operativas en
[`../../AGENTS.md`](../../AGENTS.md).

## Consecuencias

Positivas:
- Trazabilidad: cualquier decisión técnica puede auditarse hasta su evidencia.
- Disciplina mínima sin sobrecarga ceremonial.
- Compatible con futuras migraciones al esquema 00..11 si la complejidad crece.

Negativas:
- Coste inicial de bootstrap (plantillas, schemas, tools).
- Requiere ejecutar `tools/build_indexes.py` después de cambios.

## Riesgos

- Que la disciplina se relaje cuando empiece la implementación. Mitigación: revisión
  obligatoria del checklist al cerrar cada tarea.

## Validación

- `python3 tools/test_schema_validation.py` debe ejecutarse en cada PR.
- Cada ADR aceptada pasa por el `checklist-adr-tecnologica.md`.

## Seguimiento

- DEC-0002 (política de clones).
- DEC-0003 (estándar de tracking).
