# tools/

Scripts utilitarios. No depender de paquetes pip externos sin justificar.

- `build_indexes.py` — recorre `fuentes/`, `analisis/`, `decisiones/`,
  `experimentos/`, `tareas/`, y genera `../docs/indices/{repos,fuentes,adrs,tareas,preguntas,experimentos,notas}.yaml`.
- `test_schema_validation.py` — valida YAML/JSON contra los schemas de `../schemas/`.
- `architecture-records.mts` — valida los identificadores, el frontmatter, el grafo de
  supersesión y las referencias de las decisiones, y rechaza cualquier identificador
  retirado que sobreviva en el árbol. El modo `list` imprime el índice derivado.
  **Es el único de estos scripts que corre en CI** (job `release-workflow-check`).
  Es una copia literal del fichero canónico de `exelearning/exelearning`, donde
  lo cubre `bun test`. No lo edites aquí: corrígelo en core y vuelve a copiarlo.

Ejecución (desde `research/`):

```bash
python3 tools/build_indexes.py
python3 tools/test_schema_validation.py
node tools/architecture-records.mts check
node tools/architecture-records.mts list
```

Desde la raíz del repositorio: `make architecture-check` y `make architecture-records`.

Idempotencia: los scripts deben poder ejecutarse repetidamente sin efectos secundarios
externos.
