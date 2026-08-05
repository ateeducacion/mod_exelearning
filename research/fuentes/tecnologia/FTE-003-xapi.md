---
id: FTE-003
titulo: "xAPI (Experience API / Tin Can)"
categoria: estandar
version_consultada: "1.0.3"
enlaces_oficiales:
  - https://adlnet.gov/projects/xapi/
  - https://github.com/adlnet/xAPI-Spec
context7:
  library_id: /adlnet/xapi-spec
  query: "statement structure actor verb object result score scaled raw min max, IRI requirements, completed and answered verbs"
  fecha: 2026-05-29
  version_devuelta: "adlnet/xapi-spec (xAPI-Data.md, xAPI-About.md) — High. Re-confirmado verb/result/score y ejemplo cmi.interaction."
fecha_consulta: 2026-05-29
relevancia_para_mod_exelearning: "Estándar granular para statements (actor, verb, object, result). Moodle lo soporta nativamente vía core_xapi. Candidato principal para tracking de items independientes."
herramienta_ia:
  interfaz: claude-code
  modelo: claude-opus-4-7
---

## Qué es

> **[ACTUALIZACION 2026-06-17]** Ficha de bootstrap (2026-05-29, opus-4-7). La pregunta abierta de más abajo
> «¿eXeLearning emite xAPI hoy?» está **respondida: sí** (PR upstream #1867, **FTE-011**). Reglas canónicas de
> validación del consumidor en **FTE-015**, versión/forward-compat 2.0 en **FTE-017**, mapeo a la tubería en
> **AN-012/AN-014**, decisión en **DEC-17-01/DEC-0063**.

Especificación ADL que reemplaza el modelo CMI por **statements** firmados enviados a
un Learning Record Store (LRS). Forma: `actor + verb + object [+ result + context]`.

## Conceptos clave

- **Statement**: hecho registrado.
- **Activity ID**: IRI estable del object.
- **Verb**: IRI (`http://adlnet.gov/expapi/verbs/answered`, `completed`, …).
- **Result**: contiene `score.scaled`, `score.raw`, `success`, `completion`.

## API / Puntos de extensión relevantes

En Moodle:
- `core_xapi\handler` (abstract) — extender para implementar consumidor.
- `statement_to_event(statement $s): ?core\event\base`.
- Endpoints REST: `core_xapi_post_statement`, `core_xapi_post_state`, etc.
- Tabla `core_xapi_state`.

## Soporte para multi-grade-items

**Excelente.** Cada statement puede llevar un `object.id` distinto. El handler de
`mod_exelearning` filtra por `object.id` y resuelve qué `itemnumber` de gradebook
afectar. Patrón observado en `mod_h5pactivity`.

## Soporte para navegación/sidebar

Ortogonal: xAPI no define UI. La sidebar la sigue sirviendo el paquete eXeLearning
nativo; xAPI sólo es el canal de tracking.

## Implementaciones de referencia consultadas

- REPO-004 — `public/lib/xapi/`, `public/mod/h5pactivity/classes/xapi/handler.php`.

## Statement de referencia (de la spec)

Evidencia Context7 (`/adlnet/xapi-spec` · 2026-05-28). Ejemplo para una interacción
tipo cuestionario:

```json
{
  "actor": {"mbox": "mailto:learner@example.com", "objectType": "Agent"},
  "verb":  {"id": "http://adlnet.gov/expapi/verbs/answered",
            "display": {"en-US": "answered"}},
  "object": {
    "id": "http://example.com/quiz/question-1",
    "objectType": "Activity",
    "definition": {
      "type": "http://adlnet.gov/expapi/activities/cmi.interaction",
      "interactionType": "choice",
      "correctResponsesPattern": ["http"],
      "choices": [{"id":"http","description":{"en-US":"HTTP/HTTPS"}}]
    }
  },
  "result": {"response": "http", "success": true}
}
```

Score completo (de la spec):

```json
"result": {
  "score": {"scaled": 0.95, "raw": 95, "min": 0, "max": 100},
  "success": true, "completion": true, "duration": "PT1H30M"
}
```

Para `mod_exelearning`, `object.id` será la IRI estable del iDevice (ver
AN-002 / arquitectura) y `result.score.scaled` (0..1) se multiplicará por `grademax`
del item correspondiente.

## Riesgos / Limitaciones

- El LRS por defecto en Moodle no es completo (es estado mínimo); para análisis
  avanzados habría que externalizar a un LRS real.
- Verbo/object IRIs deben ser estables: requiere convención con eXeLearning.

## Preguntas abiertas

- ¿eXeLearning emite statements xAPI hoy en sus paquetes? — PREG-001 / PREG-002.
- ¿Conviene definir un perfil xAPI propio (`exelearning.net/xapi/profile/v1`)?
