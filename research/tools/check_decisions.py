#!/usr/bin/env python3
"""check_decisions.py

Validates the decision records under `research/decisiones/adr/` and prints the
derived index. Identifiers are based on the GitHub tracking number of the change
that produced the decision -- see `research/decisiones/README.md`.

Usage (from the repository root):
    python3 research/tools/check_decisions.py check   # validate, non-zero on failure
    python3 research/tools/check_decisions.py list    # print the derived index

Or via the Makefile: `make architecture-check` / `make architecture-records`.

Standard library only, like the rest of `research/tools/`: this repository has no
Python dependency policy beyond the stdlib, and a validator is not a good reason
to introduce one.
"""

from __future__ import annotations

import re
import subprocess
import sys
from datetime import date
from pathlib import Path

# ---------------------------------------------------------------------------
# Configuration
# ---------------------------------------------------------------------------

ADR_DIR = "research/decisiones/adr"
CHANGES_DIR = "research/decisiones/cambios"
MIGRATION_MAP = "research/decisiones/mapa-migracion-ids.md"
POLICY_DOC = "research/decisiones/README.md"

STATUSES = ("Propuesta", "Aceptada", "Rechazada", "Superseded")

CHANGE_DOCUMENTS = ("proposal.md", "spec.md", "design.md", "research.md", "tasks.md")

# `DEC-13-01-slug.md`: tracking number without leading zeros, two-digit local
# sequence scoped to that number, kebab-case decision slug.
FILENAME_RE = re.compile(r"^DEC-([1-9][0-9]*)-([0-9]{2})-([a-z0-9]+(?:-[a-z0-9]+)*)\.md$")
LEGACY_FILENAME_RE = re.compile(r"^DEC-([0-9]{4})-([a-z0-9]+(?:-[a-z0-9]+)*)\.md$")
CHANGE_DIR_RE = re.compile(r"^([1-9][0-9]*)-([a-z0-9]+(?:-[a-z0-9]+)*)$")

# A retired identifier is `DEC-NNNN` NOT followed by a two-digit local sequence:
# without that lookahead a current identifier such as `DEC-1234-01` would match
# on its own four-digit prefix.
RETIRED_ID_RE = re.compile(r"\bDEC-[0-9]{4}(?!-[0-9]{2})\b")

# The bootstrap records: pushed straight to `main` with no issue and no pull
# request, so they have no verifiable tracking number and were not renamed. This
# list is FROZEN. A new `DEC-NNNN-*.md` file is rejected, so the debt cannot grow.
# See research/decisiones/mapa-migracion-ids.md for the evidence and the options
# for closing it.
PENDING_LEGACY_IDS = frozenset(
    [
        "DEC-0001", "DEC-0002", "DEC-0003", "DEC-0004", "DEC-0005", "DEC-0006",
        "DEC-0007", "DEC-0008", "DEC-0009", "DEC-0010", "DEC-0011", "DEC-0012",
        "DEC-0013", "DEC-0014", "DEC-0015", "DEC-0019", "DEC-0036", "DEC-0063",
    ]
)

# Files allowed to name a retired identifier, because documenting the migration
# requires naming what was migrated. Everything else must use current ones.
RETIRED_REFERENCE_ALLOWLIST = (
    MIGRATION_MAP,
    POLICY_DOC,
    "research/schemas/decision.schema.yaml",
    "research/tools/check_decisions.py",
    "research/tools/test_check_decisions.py",
    # Historical record of the DEC-0059 / DEC-0063 collision that motivated
    # retiring the global counter. Rewriting it would erase the evidence.
    "research/tareas/diario/2026-06-17-adr-validacion-xapi-y-2.0.yaml",
)

FRONTMATTER_RE = re.compile(r"\A---\r?\n(.*?)\r?\n---\r?\n?(.*)\Z", re.S)
H1_RE = re.compile(r"^# (.+)$", re.M)
STATUS_SECTION_RE = re.compile(r"^##+\s+Estado\s*$", re.M | re.I)


# ---------------------------------------------------------------------------
# Minimal frontmatter parser
# ---------------------------------------------------------------------------


def parse_frontmatter(text: str):
    """Parses the bounded YAML subset used by decision frontmatter.

    Scalars, inline lists, block lists and one level of nested mappings. This is
    deliberately not a general YAML parser: the schema is small and fixed, and
    PyYAML is not guaranteed to be installed.

    Returns (data, body) or None when there is no frontmatter.
    """
    match = FRONTMATTER_RE.match(text)
    if not match:
        return None

    data: dict = {}
    current_key = None
    current_list = None
    current_map = None

    def flush():
        nonlocal current_key, current_list, current_map
        if current_key is None:
            return
        if current_list is not None:
            data[current_key] = current_list
        elif current_map is not None:
            data[current_key] = current_map
        current_key = None
        current_list = None
        current_map = None

    for line in match.group(1).split("\n"):
        stripped = line.strip()
        if not stripped or stripped.startswith("#"):
            continue

        top = re.match(r"^([A-Za-z_][A-Za-z0-9_]*):(.*)$", line)
        if top:
            flush()
            key, rest = top.group(1), top.group(2).strip()
            if rest == "":
                current_key = key
            else:
                data[key] = _scalar_or_inline_list(rest)
            continue

        item = re.match(r"^\s+-\s*(.*)$", line)
        if item and current_key is not None:
            current_map = None
            if current_list is None:
                current_list = []
            current_list.append(_strip_quotes(item.group(1).strip()))
            continue

        nested = re.match(r"^\s+([A-Za-z_][A-Za-z0-9_]*):(.*)$", line)
        if nested and current_key is not None:
            current_list = None
            if current_map is None:
                current_map = {}
            current_map[nested.group(1)] = _scalar_or_inline_list(nested.group(2).strip())

    flush()
    return data, match.group(2)


def _strip_quotes(value: str) -> str:
    if len(value) >= 2 and value[0] == value[-1] and value[0] in "\"'":
        return value[1:-1]
    return value


def _scalar_or_inline_list(raw: str):
    if raw.startswith("[") and raw.endswith("]"):
        inner = raw[1:-1].strip()
        if not inner:
            return []
        return [_strip_quotes(part.strip()) for part in inner.split(",")]
    return _strip_quotes(raw)


def as_list(value) -> list[str]:
    if value is None:
        return []
    if isinstance(value, list):
        return [str(v) for v in value]
    if isinstance(value, dict):
        return []
    text = str(value).strip()
    return [text] if text else []


def as_text(value) -> str:
    if value is None or isinstance(value, (list, dict)):
        return ""
    return str(value)


def is_valid_date(value: str) -> bool:
    if not re.match(r"^\d{4}-\d{2}-\d{2}$", value):
        return False
    try:
        date.fromisoformat(value)
    except ValueError:
        return False
    return True


def is_positive_integer(value: str) -> bool:
    return bool(re.match(r"^[1-9][0-9]*$", value))


# ---------------------------------------------------------------------------
# Discovery
# ---------------------------------------------------------------------------


class Decision:
    def __init__(self, path, filename, number, sequence, legacy, data, body):
        self.path = path
        self.filename = filename
        self.number = number          # tracking number, or None for a pending record
        self.sequence = sequence      # local sequence, or None for a pending record
        self.legacy = legacy          # True when it keeps the retired numbering
        self.data = data
        self.body = body

    @property
    def ident(self) -> str:
        return as_text(self.data.get("id"))

    @property
    def expected_id(self) -> str:
        if self.legacy:
            return "DEC-" + LEGACY_FILENAME_RE.match(self.filename).group(1)
        return f"DEC-{self.number}-{self.sequence}"

    @property
    def titulo(self) -> str:
        return as_text(self.data.get("titulo"))

    @property
    def estado(self) -> str:
        return as_text(self.data.get("estado"))


def discover_decisions(root: Path):
    """Reads every record, reporting structural problems separately."""
    directory = root / ADR_DIR
    decisions: list[Decision] = []
    errors: list[tuple[str, str]] = []
    if not directory.is_dir():
        return decisions, [(ADR_DIR, "directory does not exist")]

    for path in sorted(directory.glob("*.md")):
        name = path.name
        if name in ("README.md", "template.md"):
            continue
        rel = f"{ADR_DIR}/{name}"

        # The current grammar is tried first: `DEC-1234-01-slug.md` also starts
        # with four digits, so the retired pattern would otherwise shadow it.
        match = FILENAME_RE.match(name)
        legacy_match = None if match else LEGACY_FILENAME_RE.match(name)

        if match is None and legacy_match is None:
            errors.append((rel, "filename does not match DEC-<tracking-number>-<NN>-<decision-slug>.md"))
            continue

        if legacy_match is not None:
            legacy_id = f"DEC-{legacy_match.group(1)}"
            if legacy_id not in PENDING_LEGACY_IDS:
                errors.append(
                    (
                        rel,
                        f"uses the retired global numbering. Rename to "
                        f"DEC-<tracking-number>-<NN>-<decision-slug>.md "
                        f"(see {POLICY_DOC}). Only the frozen bootstrap list may keep it.",
                    )
                )
                continue

        parsed = parse_frontmatter(path.read_text(encoding="utf-8"))
        if parsed is None:
            errors.append((rel, "missing YAML frontmatter"))
            continue

        data, body = parsed
        decisions.append(
            Decision(
                path=rel,
                filename=name,
                number=int(match.group(1)) if match else None,
                sequence=match.group(2) if match else None,
                legacy=match is None,
                data=data,
                body=body,
            )
        )

    return decisions, errors


def discover_changes(root: Path):
    """Reads the design-document directories, which may not exist yet."""
    directory = root / CHANGES_DIR
    changes = []
    errors: list[tuple[str, str]] = []
    if not directory.is_dir():
        return changes, errors

    for entry in sorted(p for p in directory.iterdir() if p.is_dir()):
        rel = f"{CHANGES_DIR}/{entry.name}"
        match = CHANGE_DIR_RE.match(entry.name)
        if not match:
            errors.append((rel, "directory name does not match <tracking-number>-<change-slug>"))
            continue

        documents = []
        for name in CHANGE_DOCUMENTS:
            doc = entry / name
            if not doc.is_file():
                continue
            parsed = parse_frontmatter(doc.read_text(encoding="utf-8"))
            if parsed is None:
                errors.append((f"{rel}/{name}", "missing YAML frontmatter"))
                continue
            documents.append((f"{rel}/{name}", name, parsed[0]))

        if not documents:
            errors.append((rel, f"contains no recognised document ({', '.join(CHANGE_DOCUMENTS)})"))
            continue

        changes.append({"dir": rel, "name": entry.name, "number": int(match.group(1)), "documents": documents})

    return changes, errors


# ---------------------------------------------------------------------------
# Validation
# ---------------------------------------------------------------------------


def validate(decisions, changes) -> list[tuple[str, str]]:
    problems: list[tuple[str, str]] = []

    def add(path, message):
        problems.append((path, message))

    known_ids = {d.ident for d in decisions if d.ident}
    by_id = {d.ident: d for d in decisions if d.ident}
    seen_ids: dict[str, str] = {}
    seen_sequences: dict[str, str] = {}

    for dec in decisions:
        if not dec.ident:
            add(dec.path, "missing required field `id`")
        elif dec.ident != dec.expected_id:
            add(dec.path, f'frontmatter id "{dec.ident}" does not match filename (expected "{dec.expected_id}")')

        if not dec.titulo:
            add(dec.path, "missing required field `titulo`")

        fecha = as_text(dec.data.get("fecha"))
        if not fecha:
            add(dec.path, "missing required field `fecha`")
        elif not is_valid_date(fecha):
            add(dec.path, f'fecha "{fecha}" is not a valid YYYY-MM-DD date')

        if not dec.estado:
            add(dec.path, "missing required field `estado`")
        elif dec.estado not in STATUSES:
            add(dec.path, f'estado "{dec.estado}" is not one of {", ".join(STATUSES)}')

        tracking = as_text(dec.data.get("tracking_issue"))
        if dec.legacy:
            if tracking:
                add(dec.path, "declares `tracking_issue` but keeps the retired numbering; rename the record")
        elif not tracking:
            add(dec.path, "missing required field `tracking_issue`")
        elif not is_positive_integer(tracking):
            add(dec.path, f'tracking_issue "{tracking}" is not a positive integer')
        elif int(tracking) != dec.number:
            add(dec.path, f"tracking_issue {tracking} does not match filename tracking number {dec.number}")

        legacy_id = as_text(dec.data.get("legacy_id"))
        if legacy_id and not re.match(r"^DEC-[0-9]{4}$", legacy_id):
            add(dec.path, f'legacy_id "{legacy_id}" is not a retired DEC-NNNN identifier')

        if not as_list(dec.data.get("agentes")):
            add(dec.path, "missing required field `agentes`")

        ia = dec.data.get("herramienta_ia")
        if not isinstance(ia, dict) or not as_text(ia.get("interfaz")):
            add(dec.path, "missing required field `herramienta_ia.interfaz` (use `none` if no AI tool was used)")
        if not isinstance(ia, dict) or not as_text(ia.get("modelo")):
            add(dec.path, "missing required field `herramienta_ia.modelo` (use `none` if no AI tool was used)")

        heading = H1_RE.search(dec.body)
        expected_h1 = f"{dec.expected_id}: {dec.titulo}"
        if heading is None:
            add(dec.path, "missing H1 heading")
        elif heading.group(1).strip() != expected_h1:
            add(dec.path, f'H1 is "{heading.group(1).strip()}" but should be "{expected_h1}"')

        if STATUS_SECTION_RE.search(dec.body):
            add(dec.path, "has an `## Estado` section; status lives in the frontmatter only")

        if dec.ident:
            previous = seen_ids.get(dec.ident)
            if previous is not None:
                add(dec.path, f'duplicate id "{dec.ident}" (also in {previous})')
            else:
                seen_ids[dec.ident] = dec.path

        if not dec.legacy:
            key = f"{dec.number}-{dec.sequence}"
            previous = seen_sequences.get(key)
            if previous is not None:
                add(dec.path, f"duplicate local sequence {dec.sequence} for tracking number {dec.number} "
                              f"(also in {previous})")
            else:
                seen_sequences[key] = dec.path

        # `relacionados` also carries cross-series references (AN-, RIE-, TAREA-,
        # FTE-, REPO-), which other tools own. Only decision references are ours.
        for ref in as_list(dec.data.get("relacionados")):
            if ref.startswith("DEC-") and ref not in known_ids:
                add(dec.path, f'relacionados references unknown decision "{ref}"')

        for ref in as_list(dec.data.get("supersede")):
            if ref == dec.ident:
                add(dec.path, "a decision cannot supersede itself")
            elif ref not in known_ids:
                add(dec.path, f'supersede references unknown decision "{ref}"')
            else:
                target = by_id[ref]
                if dec.ident not in as_list(target.data.get("reemplazada_por")):
                    add(dec.path, f'supersede "{ref}" but {target.path} does not declare '
                                  f"reemplazada_por: {dec.ident}")
                if target.estado != "Superseded":
                    add(target.path, f'is superseded by {dec.ident} but estado is "{target.estado}", '
                                     f'not "Superseded"')

        for ref in as_list(dec.data.get("reemplazada_por")):
            if ref == dec.ident:
                add(dec.path, "a decision cannot be superseded by itself")
            elif ref not in known_ids:
                add(dec.path, f'reemplazada_por references unknown decision "{ref}"')
            elif dec.ident not in as_list(by_id[ref].data.get("supersede")):
                add(dec.path, f'reemplazada_por "{ref}" but {by_id[ref].path} does not declare '
                              f"supersede: {dec.ident}")

    for change in changes:
        for path, name, data in change["documents"]:
            tracking = as_text(data.get("tracking_issue"))
            if not tracking:
                add(path, "missing required field `tracking_issue`")
            elif not is_positive_integer(tracking):
                add(path, f'tracking_issue "{tracking}" is not a positive integer')
            elif int(tracking) != change["number"]:
                add(path, f"tracking_issue {tracking} does not match change directory "
                          f"tracking number {change['number']}")

            if not as_text(data.get("titulo")) and not as_text(data.get("title")):
                add(path, "missing required field `titulo`")

            for ref in as_list(data.get("decisiones")):
                if ref not in known_ids:
                    add(path, f'decisiones references unknown decision "{ref}"')

    return problems


def tracked_files(root: Path) -> list[str]:
    """Tracked plus not-yet-added files, honouring .gitignore.

    Including untracked files matters: otherwise a brand-new file passes `check`
    locally and only fails in CI, once it has been committed.
    """
    try:
        result = subprocess.run(
            ["git", "ls-files", "--cached", "--others", "--exclude-standard"],
            cwd=str(root),
            capture_output=True,
            text=True,
            check=True,
        )
    except (OSError, subprocess.CalledProcessError):
        return []
    return sorted({line for line in result.stdout.split("\n") if line})


def find_retired_references(root: Path, files: list[str], decisions) -> list[tuple[str, str]]:
    """Flags migrated identifiers still spelled the retired way.

    The bootstrap identifiers are NOT flagged: those records still exist under
    that name, so a reference to them resolves.
    """
    migrated = {as_text(d.data.get("legacy_id")) for d in decisions if as_text(d.data.get("legacy_id"))}
    problems: list[tuple[str, str]] = []

    for rel in files:
        if any(rel == allowed or rel.startswith(allowed.rstrip("/") + "/") for allowed in RETIRED_REFERENCE_ALLOWLIST):
            continue
        path = root / rel
        if not path.is_file():
            continue
        try:
            content = path.read_text(encoding="utf-8")
        except (OSError, UnicodeDecodeError):
            continue

        for lineno, line in enumerate(content.split("\n"), start=1):
            if "legacy_id:" in line:
                continue
            for hit in RETIRED_ID_RE.findall(line):
                if hit in migrated:
                    problems.append(
                        (f"{rel}:{lineno}", f'references retired identifier "{hit}". '
                                            f"Use the current identifier (see {MIGRATION_MAP}).")
                    )
    return problems


# ---------------------------------------------------------------------------
# Index
# ---------------------------------------------------------------------------


def sort_key(dec: Decision) -> tuple:
    if dec.legacy:
        return (0, int(dec.filename[4:8]), 0)
    return (1, dec.number, int(dec.sequence))


def render_index(decisions) -> str:
    lines = [
        "# Índice de decisiones",
        "",
        "Generado por `make architecture-records`; no editar a mano.",
        "Política: research/decisiones/README.md",
        "",
        "| ID | Título | Estado | Nº de seguimiento | Fecha |",
        "|---|---|---|---|---|",
    ]
    ordered = sorted(decisions, key=sort_key)
    for dec in ordered:
        number = "—" if dec.legacy else f"#{dec.number}"
        lines.append(f"| [{dec.ident}]({dec.filename}) | {dec.titulo} | {dec.estado} | {number} "
                     f"| {as_text(dec.data.get('fecha'))} |")

    for estado in STATUSES:
        lines += ["", f"## {estado}", ""]
        group = [d for d in ordered if d.estado == estado]
        if not group:
            lines.append(f"_Sin decisiones en estado {estado}._")
            continue
        for dec in group:
            replaced = as_list(dec.data.get("reemplazada_por"))
            suffix = f" — reemplazada por {', '.join(replaced)}" if replaced else ""
            lines.append(f"- [{dec.ident}]({dec.filename}) — {dec.titulo}{suffix}")

    return "\n".join(lines) + "\n"


# ---------------------------------------------------------------------------
# CLI
# ---------------------------------------------------------------------------


def find_repo_root(start: Path) -> Path:
    for candidate in [start.resolve(), *start.resolve().parents]:
        if (candidate / ADR_DIR).is_dir():
            return candidate
    raise SystemExit(f"Could not find {ADR_DIR}/ above {start}.")


def report(title: str, problems) -> None:
    if not problems:
        return
    print(f"\n{title}", file=sys.stderr)
    for path, message in problems:
        print(f"  x {path}: {message}", file=sys.stderr)


def run(mode: str, root: Path) -> int:
    decisions, structural = discover_decisions(root)
    changes, change_errors = discover_changes(root)
    structural = structural + change_errors

    if mode == "list":
        report("Structural problems:", structural)
        if structural:
            print("\nRefusing to list records while structural problems remain.", file=sys.stderr)
            return 1
        print(render_index(decisions))
        return 0

    metadata = validate(decisions, changes)
    retired = find_retired_references(root, tracked_files(root), decisions)

    report("Structural problems:", structural)
    report("Metadata problems:", metadata)
    report("Retired identifier references:", retired)

    total = len(structural) + len(metadata) + len(retired)
    if total == 0:
        pending = sum(1 for d in decisions if d.legacy)
        print(f"Decision records OK — {len(decisions)} records ({pending} still on the retired numbering), "
              f"{len(changes)} change directories.")
        return 0
    print(f"\n{total} problem(s) found.", file=sys.stderr)
    return 1


def main(argv: list[str]) -> int:
    mode = argv[1] if len(argv) > 1 else ""
    if mode not in ("check", "list"):
        print("Usage: python3 research/tools/check_decisions.py <check|list>", file=sys.stderr)
        return 2
    return run(mode, find_repo_root(Path(__file__).parent))


if __name__ == "__main__":
    sys.exit(main(sys.argv))
