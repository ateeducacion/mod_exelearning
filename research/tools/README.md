# tools/

Scripts utilitarios. No depender de paquetes pip externos sin justificar.

- `build_indexes.py` — recorre `fuentes/`, `analisis/`, `decisiones/`,
  `experimentos/`, `tareas/`, y genera `../docs/indices/{repos,fuentes,adrs,tareas,preguntas,experimentos,notas}.yaml`.
- `test_schema_validation.py` — valida YAML/JSON contra los schemas de `../schemas/`.
- `check_decisions.py` — valida los identificadores, el frontmatter, el grafo de
  supersesión y las referencias de las decisiones, y rechaza cualquier identificador
  retirado que sobreviva en el árbol. El modo `list` imprime el índice derivado.
  **Es el único de estos scripts que corre en CI** (job `release-workflow-check`).
- `architecture_records_test.py` — tests unitarios de `check_decisions.py` (`unittest`).

Ejecución (desde `research/`):

```bash
python3 tools/build_indexes.py
python3 tools/test_schema_validation.py
python3 tools/check_decisions.py check
python3 tools/architecture_records_test.py
```

Desde la raíz del repositorio: `make architecture-check` y `make architecture-records`.

Idempotencia: los scripts deben poder ejecutarse repetidamente sin efectos secundarios
externos.
