#!/usr/bin/env python3
"""Validate and list architecture decision records.

This file is CANONICAL and byte-identical across the eXeLearning repositories.
Everything repository-specific lives in `architecture-records.json` next to it,
so the rules cannot drift between repositories. If you need to change a rule,
change it here and re-sync every copy — do not fork it locally.

Records are identified by the GitHub tracking number of the change they belong
to: the issue when there is one, otherwise the pull request. GitHub allocates
issue and pull-request numbers from a single repository-wide sequence, so the
two can never collide.

Usage::

    python3 <this file> check   # validate; non-zero on failure
    python3 <this file> list    # print the record index to stdout

The index is deliberately NOT a committed file: it is derived entirely from
frontmatter, and a generated file in version control conflicts on every
concurrent branch — the exact problem this convention removes.

Standard library only, so it runs on any CI image without an install step.
"""

from __future__ import annotations

import datetime
import json
import os
import re
import subprocess
import sys
from dataclasses import dataclass, field

# --------------------------------------------------------------------------- #
# Configuration
# --------------------------------------------------------------------------- #

CONFIG_NAME = "architecture-records.json"

DEFAULT_CONFIG = {
    # Record prefix. "ADR" everywhere except moodle-mod_exelearning, whose
    # records have always been "DEC" and stay that way: this convention governs
    # numbering, not vocabulary.
    "prefix": "ADR",
    "records_dir": "docs/architecture/adr",
    "changes_dir": "docs/architecture/changes",
    # Paths allowed to mention retired identifiers, because documenting a
    # migration requires naming what was migrated. Prefix match.
    "legacy_allowlist": [],
    # Retired identifier shapes this repository migrated away from.
    "legacy_patterns": [r"\b(?:ADR|SDD)-[0-9]{4}(?!-[0-9]{2})\b"],
}

RECORD_STATUSES = ("Proposed", "Accepted", "Rejected", "Superseded")
CHANGE_STATUSES = ("draft", "in-review", "accepted", "implemented", "superseded", "abandoned")
CHANGE_DOCUMENTS = ("proposal.md", "spec.md", "design.md", "research.md", "tasks.md")
SKIP_FILES = ("README.md", "template.md", "records.md", "index.md")

# `0` is a sentinel for records that predate tracking — created when the
# repository itself was bootstrapped. GitHub numbers issues and pull requests
# from 1, so 0 can never collide with a real one.
BOOTSTRAP_NUMBER = 0
NUMBER = r"(0|[1-9][0-9]*)"
SLUG = r"([a-z0-9]+(?:-[a-z0-9]+)*)"
POSITIVE_INT_RE = re.compile(r"^(0|[1-9][0-9]*)$")
HTTP_URL_RE = re.compile(r"^https?://\S+$")

BANNER = "<!-- Produced by `make architecture-records`. Not a committed file. -->"


def load_config(root: str) -> dict:
    config = dict(DEFAULT_CONFIG)
    path = os.path.join(root, CONFIG_NAME)
    if os.path.isfile(path):
        with open(path, encoding="utf-8") as handle:
            config.update(json.load(handle))
    config["record_re"] = re.compile(rf"^{re.escape(config['prefix'])}-{NUMBER}-([0-9]{{2}})-{SLUG}\.md$")
    config["change_dir_re"] = re.compile(rf"^{NUMBER}-{SLUG}$")
    config["retired_re"] = re.compile("|".join(config["legacy_patterns"])) if config["legacy_patterns"] else None
    config["retired_name_re"] = re.compile(rf"^(?:ADR|SDD|{re.escape(config['prefix'])})-[0-9]{{4}}-")
    return config


# --------------------------------------------------------------------------- #
# Model
# --------------------------------------------------------------------------- #

@dataclass
class Diagnostic:
    file: str
    message: str


@dataclass
class Record:
    path: str
    filename: str
    id: str
    number: int
    sequence: str
    data: dict
    h1: str | None


@dataclass
class Change:
    name: str
    number: int
    slug: str
    documents: list = field(default_factory=list)


# --------------------------------------------------------------------------- #
# Frontmatter
# --------------------------------------------------------------------------- #

def parse_frontmatter(text: str):
    """Parse the bounded YAML subset the schema uses: scalars, inline lists,
    block lists and one level of nested mappings. Returns (data, body) or None.

    Deliberately not a general YAML parser — the schema is fixed and small, and
    a YAML dependency is not warranted for a documentation linter.
    """
    match = re.match(r"^---\r?\n(.*?)\r?\n---\r?\n?(.*)$", text, re.DOTALL)
    if not match:
        return None

    data: dict = {}
    state = {"key": None, "list": None, "map": None, "nested": None}

    def flush():
        if state["key"] is None:
            return
        if state["list"] is not None:
            data[state["key"]] = state["list"]
        elif state["map"] is not None:
            data[state["key"]] = state["map"]
        state.update(key=None, list=None, map=None, nested=None)

    for line in match.group(1).splitlines():
        if not line.strip() or line.strip().startswith("#"):
            continue

        top = re.match(r"^([A-Za-z_][A-Za-z0-9_]*):(.*)$", line)
        if top:
            flush()
            rest = top.group(2).strip()
            if rest == "":
                state["key"] = top.group(1)
            else:
                data[top.group(1)] = _scalar_or_list(rest)
            continue

        if state["key"] is None:
            continue

        item = re.match(r"^\s+-\s*(.*)$", line)
        if item:
            value = _unquote(item.group(1).strip())
            if state["nested"] is not None:
                state["map"] = state["map"] or {}
                if not isinstance(state["map"].get(state["nested"]), list):
                    state["map"][state["nested"]] = []
                state["map"][state["nested"]].append(value)
            else:
                state["map"] = None
                state["list"] = state["list"] or []
                state["list"].append(value)
            continue

        nested = re.match(r"^\s+([A-Za-z_][A-Za-z0-9_]*):(.*)$", line)
        if nested:
            state["list"] = None
            state["map"] = state["map"] or {}
            rest = nested.group(2).strip()
            if rest == "":
                state["nested"] = nested.group(1)
                state["map"][nested.group(1)] = []
            else:
                state["nested"] = None
                state["map"][nested.group(1)] = _scalar_or_list(rest)

    flush()
    return data, match.group(2)


def _unquote(value: str) -> str:
    return re.sub(r'^["\'](.*)["\']$', r"\1", value)


def _scalar_or_list(raw: str):
    if raw.startswith("[") and raw.endswith("]"):
        inner = raw[1:-1].strip()
        return [_unquote(p.strip()) for p in inner.split(",")] if inner else []
    return _unquote(raw)


def as_list(value) -> list:
    if value is None or isinstance(value, dict):
        return []
    if isinstance(value, list):
        return [str(v) for v in value]
    text = str(value).strip()
    return [text] if text else []


def as_text(value) -> str:
    return "" if value is None or isinstance(value, (list, dict)) else str(value)


def related_group(data: dict, key: str) -> list:
    group = data.get("related")
    return as_list(group.get(key)) if isinstance(group, dict) else []


def is_valid_date(value: str) -> bool:
    if not re.match(r"^\d{4}-\d{2}-\d{2}$", value):
        return False
    try:
        datetime.date.fromisoformat(value)
    except ValueError:
        return False
    return True


# --------------------------------------------------------------------------- #
# Discovery
# --------------------------------------------------------------------------- #

def discover_records(root: str, config: dict):
    directory = os.path.join(root, config["records_dir"])
    records: list = []
    errors: list = []
    if not os.path.isdir(directory):
        return records, errors

    for filename in sorted(os.listdir(directory)):
        if not filename.endswith(".md") or filename in SKIP_FILES:
            continue
        rel = f"{config['records_dir']}/{filename}"

        # The current grammar is tested first: `ADR-1234-01-…` also starts with
        # four digits, so a retired-first check would reject valid records.
        match = config["record_re"].match(filename)
        if not match:
            errors.append(Diagnostic(
                rel,
                "uses the retired global numbering. Rename to "
                f"{config['prefix']}-<number>-<NN>-<decision-slug>.md."
                if config["retired_name_re"].match(filename)
                else f"filename does not match {config['prefix']}-<number>-<NN>-<decision-slug>.md",
            ))
            continue

        with open(os.path.join(directory, filename), encoding="utf-8") as handle:
            parsed = parse_frontmatter(handle.read())
        if parsed is None:
            errors.append(Diagnostic(rel, "missing YAML frontmatter"))
            continue

        data, body = parsed
        heading = re.search(r"^# (.+)$", body, re.MULTILINE)
        records.append(Record(
            path=rel, filename=filename, id=as_text(data.get("id")),
            number=int(match.group(1)), sequence=match.group(2), data=data,
            h1=heading.group(1) if heading else None,
        ))

    return records, errors


def discover_changes(root: str, config: dict):
    directory = os.path.join(root, config["changes_dir"])
    changes: list = []
    errors: list = []
    if not os.path.isdir(directory):
        return changes, errors

    for entry in sorted(os.listdir(directory)):
        full = os.path.join(directory, entry)
        if not os.path.isdir(full):
            continue
        rel = f"{config['changes_dir']}/{entry}"

        match = config["change_dir_re"].match(entry)
        if not match:
            errors.append(Diagnostic(rel, "directory name does not match <number>-<change-slug>"))
            continue

        documents = []
        for name in CHANGE_DOCUMENTS:
            path = os.path.join(full, name)
            if not os.path.exists(path):
                continue
            with open(path, encoding="utf-8") as handle:
                parsed = parse_frontmatter(handle.read())
            if parsed is None:
                errors.append(Diagnostic(f"{rel}/{name}", "missing YAML frontmatter"))
                continue
            documents.append((name, parsed[0]))

        if not documents:
            errors.append(Diagnostic(rel, f"contains no recognised document ({', '.join(CHANGE_DOCUMENTS)})"))
            continue

        changes.append(Change(name=entry, number=int(match.group(1)), slug=match.group(2), documents=documents))

    return changes, errors


# --------------------------------------------------------------------------- #
# Validation
# --------------------------------------------------------------------------- #

def _check_date(path, value, out):
    if not value:
        out.append(Diagnostic(path, "missing required field `date`"))
    elif not is_valid_date(value):
        out.append(Diagnostic(path, f'date "{value}" is not a valid YYYY-MM-DD date'))


def _check_status(path, value, allowed, out):
    if not value:
        out.append(Diagnostic(path, "missing required field `status`"))
    elif value not in allowed:
        out.append(Diagnostic(path, f'status "{value}" is not one of {", ".join(allowed)}'))


def _check_tracking(path, value, expected, source, out):
    if not value:
        out.append(Diagnostic(path, "missing required field `tracking_issue`"))
    elif not POSITIVE_INT_RE.match(value):
        out.append(Diagnostic(path, f'tracking_issue "{value}" is not a valid tracking number'))
    elif int(value) != expected:
        out.append(Diagnostic(path, f"tracking_issue {value} does not match {source} number {expected}"))


def _check_urls(path, values, out):
    """Cross-repository references are full URLs: a bare number would be
    ambiguous outside the repository that allocated it."""
    for value in values:
        if not HTTP_URL_RE.match(value):
            out.append(Diagnostic(path, f'external_refs value "{value}" is not an http(s) URL'))


def _check_numbers(path, values, label, out):
    for value in values:
        if not POSITIVE_INT_RE.match(value) or value == "0":
            out.append(Diagnostic(path, f'{label} value "{value}" is not a positive integer'))


def validate(records, changes, config) -> list:
    out: list = []
    known_ids = {r.id for r in records if r.id}
    known_changes = {c.name for c in changes}
    by_id = {r.id: r for r in records if r.id}
    seen: dict = {}

    for record in records:
        expected = f"{config['prefix']}-{record.number}-{record.sequence}"
        data, path = record.data, record.path

        if not record.id:
            out.append(Diagnostic(path, "missing required field `id`"))
        elif record.id != expected:
            out.append(Diagnostic(path, f'frontmatter id "{record.id}" does not match filename (expected "{expected}")'))

        title = as_text(data.get("title"))
        if not title:
            out.append(Diagnostic(path, "missing required field `title`"))

        _check_date(path, as_text(data.get("date")), out)
        _check_status(path, as_text(data.get("status")), RECORD_STATUSES, out)
        _check_tracking(path, as_text(data.get("tracking_issue")), record.number, "filename", out)

        ai = data.get("ai_assistance")
        if (not isinstance(ai, dict)
                or not as_text(ai.get("tool"))
                or not as_text(ai.get("model"))):
            out.append(Diagnostic(path, "missing `ai_assistance.tool` / `ai_assistance.model` (use `none` if unused)"))

        if record.h1 is None:
            out.append(Diagnostic(path, "missing H1 heading"))
        elif record.h1 != f"{expected}: {title}":
            out.append(Diagnostic(path, f'H1 is "{record.h1}" but should be "{expected}: {title}"'))

        if record.id:
            if record.id in seen:
                out.append(Diagnostic(path, f'duplicate id "{record.id}" (also in {seen[record.id]})'))
            else:
                seen[record.id] = path

        for ref in related_group(data, "adrs") + as_list(data.get("related_adrs")):
            if ref == record.id:
                out.append(Diagnostic(path, "record references itself"))
            elif ref not in known_ids:
                out.append(Diagnostic(path, f'related record "{ref}" does not exist'))
        for ref in related_group(data, "changes"):
            if ref not in known_changes:
                out.append(Diagnostic(path, f'related change "{ref}" does not exist'))
        _check_numbers(path, related_group(data, "prs"), "related.prs", out)
        _check_urls(path, as_list(data.get("external_refs")), out)

        for ref in as_list(data.get("supersedes")):
            if ref == record.id:
                out.append(Diagnostic(path, "record cannot supersede itself"))
            elif ref not in known_ids:
                out.append(Diagnostic(path, f'supersedes references unknown record "{ref}"'))
            else:
                target = by_id[ref]
                if record.id not in as_list(target.data.get("superseded_by")):
                    out.append(Diagnostic(path, f'supersedes "{ref}" but {target.path} does not list superseded_by: [{record.id}]'))
                if as_text(target.data.get("status")) != "Superseded":
                    out.append(Diagnostic(
                        target.path,
                        f'is superseded by {record.id} but status is not "Superseded"' ,
                    ))

        for ref in as_list(data.get("superseded_by")):
            if ref == record.id:
                out.append(Diagnostic(path, "record cannot be superseded by itself"))
            elif ref not in known_ids:
                out.append(Diagnostic(path, f'superseded_by references unknown record "{ref}"'))
            elif record.id not in as_list(by_id[ref].data.get("supersedes")):
                out.append(Diagnostic(path, f'superseded_by "{ref}" but that record does not list supersedes: [{record.id}]'))

    for change in changes:
        canonical_name, canonical = change.documents[0]
        path = f"{config['changes_dir']}/{change.name}/{canonical_name}"

        if not as_text(canonical.get("title")):
            out.append(Diagnostic(path, "missing required field `title`"))
        _check_date(path, as_text(canonical.get("date")), out)
        _check_status(path, as_text(canonical.get("status")), CHANGE_STATUSES, out)
        _check_numbers(path, as_list(canonical.get("implementation_prs")), "implementation_prs", out)

        for ref in as_list(canonical.get("related_adrs")):
            if ref not in known_ids:
                out.append(Diagnostic(path, f'related_adrs references unknown record "{ref}"'))
        for ref in as_list(canonical.get("related_changes")):
            if ref == change.name:
                out.append(Diagnostic(path, "change references itself"))
            elif ref not in known_changes:
                out.append(Diagnostic(path, f'related_changes references unknown change "{ref}"'))

        for name, data in change.documents:
            doc_path = f"{config['changes_dir']}/{change.name}/{name}"
            _check_tracking(doc_path, as_text(data.get("tracking_issue")), change.number, "change directory", out)
            if not as_text(data.get("title")):
                out.append(Diagnostic(doc_path, "missing required field `title`"))
            _check_numbers(doc_path, as_list(data.get("related_prs")), "related_prs", out)
            _check_urls(doc_path, as_list(data.get("external_refs")), out)
            if name != canonical_name and data.get("implementation_prs") is not None:
                out.append(Diagnostic(
                    doc_path,
                    f"declares implementation_prs, but {canonical_name} is the canonical metadata carrier",
                ))

    return out


def tracked_files(root: str) -> list:
    """Tracked files plus not-yet-added ones, honouring .gitignore.

    Untracked files matter: otherwise a brand-new file passes `check` locally
    and only fails in CI, once it has been committed.
    """
    try:
        result = subprocess.run(
            ["git", "ls-files", "--cached", "--others", "--exclude-standard"],
            cwd=root, capture_output=True, text=True, check=False,
        )
    except OSError:
        return []
    return sorted(set(line for line in result.stdout.splitlines() if line)) if result.returncode == 0 else []


def find_retired_references(root: str, files: list, config: dict) -> list:
    if config["retired_re"] is None:
        return []
    out: list = []
    for rel in files:
        if any(rel == a or rel.startswith(a) for a in config["legacy_allowlist"]):
            continue
        path = os.path.join(root, rel)
        if not os.path.isfile(path):
            continue
        try:
            with open(path, "rb") as handle:
                raw = handle.read()
            if b"\0" in raw:
                continue
            text = raw.decode("utf-8")
        except (OSError, UnicodeDecodeError):
            continue

        own = None
        if rel.endswith(".md"):
            parsed = parse_frontmatter(text)
            if parsed:
                own = as_text(parsed[0].get("legacy_id")) or None

        for number, line in enumerate(text.splitlines(), start=1):
            if "legacy_id:" in line:
                continue
            hit = config["retired_re"].search(line)
            if hit and hit.group(0) != own:
                out.append(Diagnostic(
                    f"{rel}:{number}",
                    f'references retired identifier "{hit.group(0)}". Use the current identifier.',
                ))
    return out


def find_committed_indexes(files: list, config: dict) -> list:
    """The index is derived, not stored. Committing it reintroduces exactly the
    merge-conflict class this convention removes."""
    targets = {f"{config['records_dir']}/records.md", f"{config['changes_dir']}/records.md"}
    return [
        Diagnostic(rel, "the record index must not be committed — it is derived from frontmatter "
                        "and conflicts on every concurrent branch. Delete it.")
        for rel in files if rel in targets
    ]


# --------------------------------------------------------------------------- #
# Rendering
# --------------------------------------------------------------------------- #

def render_index(records, changes, config) -> str:
    lines = [BANNER, "", "# Architecture record index", "", "## Decision records", ""]
    if not records:
        lines += ["_None yet._", ""]
    else:
        lines += ["| ID | Title | Status | Tracking | Date |", "|---|---|---|---|---|"]
        for r in sorted(records, key=lambda r: (r.number, r.sequence)):
            tracking = "bootstrap" if r.number == BOOTSTRAP_NUMBER else f"#{r.number}"
            lines.append(f"| [{r.id}]({r.filename}) | {as_text(r.data.get('title'))} | "
                         f"{as_text(r.data.get('status'))} | {tracking} | {as_text(r.data.get('date'))} |")
        lines.append("")

    lines += ["## Changes", ""]
    if not changes:
        lines += ["_None yet._", ""]
    else:
        lines += ["| Change | Title | Status | Tracking | Documents |", "|---|---|---|---|---|"]
        for c in sorted(changes, key=lambda c: (c.number, c.slug)):
            _, canonical = c.documents[0]
            tracking = "bootstrap" if c.number == BOOTSTRAP_NUMBER else f"#{c.number}"
            lines.append(f"| `{c.name}` | {as_text(canonical.get('title'))} | "
                         f"{as_text(canonical.get('status'))} | {tracking} | "
                         f"{', '.join(n for n, _ in c.documents)} |")
        lines.append("")

    return "\n".join(lines)


# --------------------------------------------------------------------------- #
# CLI
# --------------------------------------------------------------------------- #

def report(title: str, problems: list) -> None:
    if not problems:
        return
    print(f"\n{title}", file=sys.stderr)
    for problem in problems:
        print(f"  ✗ {problem.file}: {problem.message}", file=sys.stderr)


def run(mode: str, root: str) -> int:
    config = load_config(root)
    records, record_errors = discover_records(root, config)
    changes, change_errors = discover_changes(root, config)
    structural = record_errors + change_errors

    if mode == "list":
        report("Structural problems:", structural)
        if structural:
            print("\nRefusing to list records while structural problems remain.", file=sys.stderr)
            return 1
        print(render_index(records, changes, config))
        return 0

    files = tracked_files(root)
    metadata = validate(records, changes, config)
    retired = find_retired_references(root, files, config)
    committed = find_committed_indexes(files, config)
    problems = structural + metadata + retired + committed

    report("Structural problems:", structural)
    report("Metadata problems:", metadata)
    report("Retired identifier references:", retired)
    report("Committed index:", committed)

    if not problems:
        bootstrap = sum(1 for r in records if r.number == BOOTSTRAP_NUMBER)
        suffix = f" ({bootstrap} from repository bootstrap)" if bootstrap else ""
        print(f"Architecture records OK — {len(records)} records{suffix}, {len(changes)} changes.")
        return 0
    print(f"\n{len(problems)} problem(s) found.", file=sys.stderr)
    return 1


def main() -> int:
    if len(sys.argv) != 2 or sys.argv[1] not in ("check", "list"):
        print(f"Usage: python3 {os.path.basename(__file__)} <check|list>", file=sys.stderr)
        return 2
    return run(sys.argv[1], os.getcwd())


if __name__ == "__main__":
    raise SystemExit(main())
