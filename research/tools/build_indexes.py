#!/usr/bin/env python3
"""build_indexes.py

Genera índices YAML en `research/docs/indices/` recorriendo el repositorio de
investigación. Sin dependencias externas (sólo stdlib).

Uso (desde la raíz del proyecto o desde research/):
    python3 research/tools/build_indexes.py
    python3 tools/build_indexes.py
"""

from __future__ import annotations

import os
import re
import sys
from pathlib import Path


def find_research_root(start: Path) -> Path:
    """Sube directorios hasta encontrar la carpeta research/."""
    current = start.resolve()
    for parent in [current, *current.parents]:
        if parent.name == "research" and (parent / "AGENTS.md").exists():
            return parent
        if (parent / "research" / "AGENTS.md").exists():
            return parent / "research"
    raise SystemExit("No se encontró el directorio research/ ancestro.")


FRONT_RE = re.compile(r"^---\s*\n(.*?)\n---\s*\n", re.S)


def parse_frontmatter(text: str) -> dict:
    """Parser de YAML frontmatter MUY simple: clave: valor escalar, sin anidación."""
    m = FRONT_RE.match(text)
    if not m:
        return {}
    fm = {}
    for line in m.group(1).splitlines():
        line = line.rstrip()
        if not line or line.startswith("#") or line.startswith("  "):
            continue
        if ":" in line:
            k, _, v = line.partition(":")
            fm[k.strip()] = v.strip().strip('"')
    return fm


def list_md(dir_: Path) -> list[Path]:
    if not dir_.exists():
        return []
    return sorted(p for p in dir_.glob("*.md") if p.is_file())


def list_yaml(dir_: Path) -> list[Path]:
    if not dir_.exists():
        return []
    return sorted(p for p in dir_.glob("*.yaml") if p.is_file())


DECISION_ID_RE = re.compile(r"^DEC-(\d+)(?:-(\d{2}))?$")


def decision_sort_key(item: dict) -> tuple:
    """Ordena decisiones por número de seguimiento y secuencia local.

    `DEC-4-01` va antes que `DEC-13-01`, que el orden lexicográfico del nombre de
    archivo invertiría. Los identificadores retirados `DEC-NNNN` (los registros del
    arranque, sin número de seguimiento) se agrupan delante conservando su orden.
    """
    m = DECISION_ID_RE.match(str(item.get("id", "")))
    if not m:
        return (2, 0, 0, str(item.get("id", "")))
    if m.group(2) is None:
        return (0, int(m.group(1)), 0, "")
    return (1, int(m.group(1)), int(m.group(2)), "")


def index_md(dir_: Path, root: Path, sort_key=None) -> list[dict]:
    out = []
    for p in list_md(dir_):
        fm = parse_frontmatter(p.read_text(encoding="utf-8"))
        out.append({
            "id": fm.get("id", p.stem),
            "titulo": fm.get("titulo", p.stem),
            "estado": fm.get("estado"),
            "fecha": fm.get("fecha"),
            "ruta": str(p.relative_to(root)),
        })
    if sort_key is not None:
        out.sort(key=sort_key)
    return out


def index_yaml(dir_: Path, root: Path, id_key: str = "id") -> list[dict]:
    out = []
    for p in list_yaml(dir_):
        # Lectura plana sin pyyaml: extraemos id y titulo por regex.
        text = p.read_text(encoding="utf-8")
        item = {"ruta": str(p.relative_to(root))}
        for key in (id_key, "titulo", "estado", "fecha", "pregunta"):
            m = re.search(rf"^{key}:\s*(.+)$", text, re.M)
            if m:
                item[key] = m.group(1).strip().strip('"')
        item.setdefault(id_key, p.stem)
        out.append(item)
    return out


def write_index_yaml(path: Path, items: list[dict]) -> None:
    """Serializador YAML mínimo sin pyyaml."""
    path.parent.mkdir(parents=True, exist_ok=True)
    lines = ["# Índice autogenerado por tools/build_indexes.py — no editar a mano.", "items:"]
    for it in items:
        lines.append("  - " + ", ".join(f"{k}: {v!r}" for k, v in it.items()))
    path.write_text("\n".join(lines) + "\n", encoding="utf-8")


def main() -> int:
    root = find_research_root(Path(__file__).parent)
    out_dir = root / "docs" / "indices"
    out_dir.mkdir(parents=True, exist_ok=True)

    indices = {
        "repos.yaml":         index_md(root / "fuentes" / "repositorios", root),
        "fuentes.yaml":       index_md(root / "fuentes" / "tecnologia", root),
        "notas.yaml":         index_md(root / "analisis" / "notas", root),
        "adrs.yaml":          index_md(root / "decisiones" / "adr", root, decision_sort_key),
        "tareas.yaml":        index_yaml(root / "tareas" / "backlog", root),
        "preguntas.yaml":     index_yaml(root / "tareas" / "preguntas", root),
        "experimentos.yaml":  index_yaml(root / "experimentos" / "resultados", root),
        "diario.yaml":        index_yaml(root / "tareas" / "diario", root, id_key="fecha"),
    }

    total = 0
    for name, items in indices.items():
        write_index_yaml(out_dir / name, items)
        print(f"  · {name}: {len(items)} entradas")
        total += len(items)
    print(f"OK — {total} entradas indexadas en {out_dir}")
    return 0


if __name__ == "__main__":
    sys.exit(main())
