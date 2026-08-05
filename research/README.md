# research/ — Repositorio de investigación de `mod_exelearning`

Documentación basada en evidencia para diseñar el plugin de actividad Moodle
`mod_exelearning`. Inspirado en la metodología de
[`learningml-ng`](https://github.com/erseco/learningml-ng) y su `1er-prompt.md`.

## Reglas

Leer [`AGENTS.md`](./AGENTS.md) antes de añadir o modificar nada.

Resumen: evidencia antes que preferencia, IDs estables, append-only, idioma español,
trazabilidad total, separación de capas (hechos / interpretación / decisión).

## Mapa del directorio

| Carpeta | Propósito | IDs |
|---|---|---|
| [`fuentes/repositorios/`](./fuentes/repositorios/) | Fichas de repos externos analizados (mod_exescorm, mod_exeweb, wp-exelearning, moodle, eXeLearning upstream) | `REPO-NNN` |
| [`fuentes/tecnologia/`](./fuentes/tecnologia/) | Fichas de estándares y APIs (SCORM, xAPI, cmi5, LTI AGS, Moodle grade API, core_xapi, formato de paquete eXeLearning) | `FTE-NNN` |
| [`analisis/notas/`](./analisis/notas/) | Notas de análisis e interpretación | `AN-NNN` |
| [`analisis/matrices/`](./analisis/matrices/) | Matrices de decisión en YAML | — |
| [`decisiones/adr/`](./decisiones/adr/) | Architecture Decision Records | `DEC-<nº-seguimiento>-<NN>` |
| [`experimentos/resultados/`](./experimentos/resultados/) | Experimentos reproducibles (comando, commit, entorno, métricas) | `EXP-NNN` |
| [`tareas/backlog/`](./tareas/backlog/) | Tareas operativas | `TAREA-NNN` |
| [`tareas/diario/`](./tareas/diario/) | Diario diario `YYYY-MM-DD.yaml` | — |
| [`tareas/preguntas/`](./tareas/preguntas/) | Preguntas abiertas a investigar | `PREG-NNN` |
| [`cumplimiento/`](./cumplimiento/) | Accesibilidad, privacidad, licencias | — |
| [`plantillas/`](./plantillas/) | Plantillas MD/YAML y checklists | — |
| [`schemas/`](./schemas/) | Schemas YAML/JSON que validan los documentos | — |
| [`tools/`](./tools/) | Scripts utilitarios (índices, validación) | — |
| [`docs/indices/`](./docs/indices/) | Índices generados automáticamente (no editar a mano) | — |

## Entradas rápidas

- Estado actual: [`status.yaml`](./status.yaml)
- Próximas acciones: [`TODO.md`](./TODO.md)
- Dashboard estático: abrir [`index.html`](./index.html) desde un servidor local
  (`python3 -m http.server` dentro de `research/`).

## Cómo añadir contenido

1. Identificar la carpeta correcta según el tipo (hecho → `fuentes/`, interpretación →
   `analisis/`, decisión → `decisiones/adr/`, etc.).
2. Copiar la plantilla relevante de [`plantillas/`](./plantillas/).
3. Asignar el ID.
   - **Decisiones (`DEC-`):** no hay contador global. El ID lo da el **número de
     seguimiento de GitHub** del cambio (su issue, o su PR si no tiene issue) más una
     secuencia local de dos dígitos: `DEC-<nº>-<NN>`. Ver
     [`decisiones/README.md`](./decisiones/README.md). Nunca abrir un issue sólo para
     obtener un número.
   - **Resto de series** (`REPO-`, `FTE-`, `AN-`, `EXP-`, `TAREA-`, `PREG-`, `RIE-`):
     siguiente ID libre en la serie (consultar `docs/indices/` para el último usado).
     **Importante:** comprobar también ramas/worktrees de PR abiertos antes de fijar el
     número, para no reutilizar IDs.
4. Rellenar campos. Si falta un dato, escribir `[PENDIENTE: <descripción>]`.
5. Añadir entrada de tarea o diario en [`tareas/`](./tareas/).
6. Ejecutar `python3 tools/build_indexes.py`, `python3 tools/test_schema_validation.py`
   y `make architecture-check`.
7. Commit con mensaje convencional (`docs(research): añadir FTE-009 sobre …`).
