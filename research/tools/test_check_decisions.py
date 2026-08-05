#!/usr/bin/env python3
"""test_check_decisions.py

Unit tests for check_decisions.py. Standard library only (unittest), like the
rest of research/tools/.

Run from the repository root:
    python3 research/tools/test_check_decisions.py
    python3 -m unittest discover -s research/tools -p 'test_*.py'
"""

from __future__ import annotations

import shutil
import sys
import tempfile
import textwrap
import unittest
from pathlib import Path

sys.path.insert(0, str(Path(__file__).parent))

import check_decisions as cd  # noqa: E402


def record(
    ident="DEC-13-01",
    titulo="Una decisión",
    estado="Aceptada",
    fecha="2026-06-03",
    tracking="13",
    legacy_id=None,
    extra="",
    body=None,
    h1=None,
):
    """Builds a well-formed record; every argument is a seam for a defect."""
    lines = [
        "---",
        f"id: {ident}",
        f'titulo: "{titulo}"',
        f"estado: {estado}",
        f"fecha: {fecha}",
    ]
    if tracking is not None:
        lines.append(f"tracking_issue: {tracking}")
    if legacy_id is not None:
        lines.append(f"legacy_id: {legacy_id}")
    lines += [
        "agentes:",
        "  - erseco",
    ]
    if extra:
        lines += extra.strip("\n").split("\n")
    lines += [
        "herramienta_ia:",
        "  interfaz: claude-code",
        "  modelo: claude-opus-5",
        "---",
        "",
        h1 if h1 is not None else f"# {ident}: {titulo}",
        "",
        body if body is not None else "## Contexto\n\nTexto.",
        "",
    ]
    return "\n".join(lines)


class Fixture:
    """A throwaway repository skeleton with an adr/ directory."""

    def __init__(self):
        self.root = Path(tempfile.mkdtemp())
        self.adr = self.root / cd.ADR_DIR
        self.adr.mkdir(parents=True)

    def write(self, filename, content):
        (self.adr / filename).write_text(content, encoding="utf-8")

    def other(self, relpath, content):
        path = self.root / relpath
        path.parent.mkdir(parents=True, exist_ok=True)
        path.write_text(content, encoding="utf-8")

    def change(self, dirname, filename, content):
        path = self.root / cd.CHANGES_DIR / dirname
        path.mkdir(parents=True, exist_ok=True)
        (path / filename).write_text(content, encoding="utf-8")

    def problems(self):
        decisions, structural = cd.discover_decisions(self.root)
        changes, change_errors = cd.discover_changes(self.root)
        return (
            [f"{p}: {m}" for p, m in structural + change_errors],
            [f"{p}: {m}" for p, m in cd.validate(decisions, changes)],
        )

    def cleanup(self):
        shutil.rmtree(self.root, ignore_errors=True)


class BaseCase(unittest.TestCase):
    def setUp(self):
        self.fx = Fixture()
        self.addCleanup(self.fx.cleanup)

    def assertClean(self):
        structural, metadata = self.fx.problems()
        self.assertEqual([], structural)
        self.assertEqual([], metadata)

    def assertReports(self, needle, where="metadata"):
        structural, metadata = self.fx.problems()
        haystack = structural if where == "structural" else metadata
        self.assertTrue(
            any(needle in item for item in haystack),
            f"expected {needle!r} in {haystack!r}",
        )


class FrontmatterTest(unittest.TestCase):
    def test_parses_scalars_lists_and_nested_maps(self):
        data, body = cd.parse_frontmatter(
            textwrap.dedent(
                """\
                ---
                id: DEC-13-01
                titulo: "Con: dos puntos"
                relacionados:
                  - DEC-13-02
                  - DEC-13-03
                inline: [a, b]
                herramienta_ia:
                  interfaz: claude-code
                  modelo: claude-opus-5
                ---

                # cuerpo
                """
            )
        )
        self.assertEqual("DEC-13-01", data["id"])
        self.assertEqual("Con: dos puntos", data["titulo"])
        self.assertEqual(["DEC-13-02", "DEC-13-03"], data["relacionados"])
        self.assertEqual(["a", "b"], data["inline"])
        self.assertEqual("claude-opus-5", data["herramienta_ia"]["modelo"])
        self.assertIn("# cuerpo", body)

    def test_returns_none_without_frontmatter(self):
        self.assertIsNone(cd.parse_frontmatter("# sin frontmatter\n"))

    def test_date_and_integer_helpers(self):
        self.assertTrue(cd.is_valid_date("2026-06-03"))
        self.assertFalse(cd.is_valid_date("2026-02-30"))
        self.assertFalse(cd.is_valid_date("3 de junio"))
        self.assertTrue(cd.is_positive_integer("13"))
        self.assertFalse(cd.is_positive_integer("013"))
        self.assertFalse(cd.is_positive_integer("0"))


class FilenameGrammarTest(BaseCase):
    def test_accepts_the_current_grammar(self):
        self.fx.write("DEC-13-01-una-decision.md", record())
        self.assertClean()

    def test_accepts_a_four_digit_tracking_number(self):
        # A four-digit tracking number must not be mistaken for the retired form.
        self.fx.write(
            "DEC-2058-01-una-decision.md",
            record(ident="DEC-2058-01", tracking="2058"),
        )
        self.assertClean()

    def test_rejects_a_new_record_on_the_retired_numbering(self):
        self.fx.write("DEC-0099-una-decision.md", record(ident="DEC-0099", tracking=None))
        self.assertReports("uses the retired global numbering", where="structural")

    def test_allows_the_frozen_bootstrap_records(self):
        self.fx.write(
            "DEC-0001-metodologia-evidencia.md",
            record(ident="DEC-0001", tracking=None),
        )
        self.assertClean()

    def test_rejects_a_bootstrap_record_that_declares_a_tracking_issue(self):
        self.fx.write(
            "DEC-0001-metodologia-evidencia.md",
            record(ident="DEC-0001", tracking="7"),
        )
        self.assertReports("keeps the retired numbering")

    def test_rejects_a_malformed_filename(self):
        self.fx.write("DEC-13-1-una-decision.md", record())
        self.assertReports("filename does not match", where="structural")

    def test_rejects_an_uppercase_slug(self):
        self.fx.write("DEC-13-01-Una-Decision.md", record())
        self.assertReports("filename does not match", where="structural")


class MetadataTest(BaseCase):
    def test_id_must_match_the_filename(self):
        self.fx.write("DEC-13-01-una-decision.md", record(ident="DEC-13-02"))
        self.assertReports("does not match filename")

    def test_tracking_issue_must_match_the_filename(self):
        self.fx.write("DEC-13-01-una-decision.md", record(tracking="14"))
        self.assertReports("does not match filename tracking number")

    def test_rejects_an_unknown_status(self):
        self.fx.write("DEC-13-01-una-decision.md", record(estado="Vigente"))
        self.assertReports("is not one of")

    def test_rejects_an_impossible_date(self):
        self.fx.write("DEC-13-01-una-decision.md", record(fecha="2026-02-30"))
        self.assertReports("is not a valid YYYY-MM-DD date")

    def test_requires_ai_provenance(self):
        content = record().replace("  modelo: claude-opus-5\n", "")
        self.fx.write("DEC-13-01-una-decision.md", content)
        self.assertReports("herramienta_ia.modelo")

    def test_requires_the_h1_to_mirror_the_title(self):
        self.fx.write("DEC-13-01-una-decision.md", record(h1="# Una decisión"))
        self.assertReports("but should be")

    def test_requires_an_h1(self):
        self.fx.write("DEC-13-01-una-decision.md", record(h1="", body="Texto sin encabezado."))
        self.assertReports("missing H1 heading")

    def test_rejects_a_duplicated_status_section(self):
        self.fx.write(
            "DEC-13-01-una-decision.md",
            record(body="## Estado\n\nAceptada\n\n## Contexto\n\nTexto."),
        )
        self.assertReports("status lives in the frontmatter only")

    def test_rejects_a_duplicate_local_sequence(self):
        self.fx.write("DEC-13-01-una-decision.md", record())
        self.fx.write("DEC-13-01-otra-decision.md", record(titulo="Otra decisión"))
        self.assertReports("duplicate local sequence")

    def test_ignores_cross_series_relations(self):
        self.fx.write(
            "DEC-13-01-una-decision.md",
            record(extra="relacionados:\n  - RIE-001\n  - AN-008"),
        )
        self.assertClean()

    def test_flags_an_unknown_decision_relation(self):
        self.fx.write(
            "DEC-13-01-una-decision.md",
            record(extra="relacionados:\n  - DEC-99-01"),
        )
        self.assertReports('relacionados references unknown decision "DEC-99-01"')


class SupersessionTest(BaseCase):
    def write_pair(self, old_status="Superseded", back_reference=True):
        self.fx.write(
            "DEC-13-08-vieja.md",
            record(
                ident="DEC-13-08",
                titulo="Vieja",
                estado=old_status,
                extra="reemplazada_por: DEC-111-01" if back_reference else "",
            ),
        )
        self.fx.write(
            "DEC-111-01-nueva.md",
            record(
                ident="DEC-111-01",
                titulo="Nueva",
                tracking="111",
                extra="supersede: DEC-13-08",
            ),
        )

    def test_accepts_a_two_sided_relationship(self):
        self.write_pair()
        self.assertClean()

    def test_rejects_a_one_sided_relationship(self):
        self.write_pair(back_reference=False)
        self.assertReports("does not declare reemplazada_por")

    def test_requires_the_superseded_record_to_say_so(self):
        self.write_pair(old_status="Aceptada")
        self.assertReports('not "Superseded"')

    def test_rejects_self_supersession(self):
        self.fx.write(
            "DEC-13-01-una-decision.md",
            record(extra="supersede: DEC-13-01"),
        )
        self.assertReports("cannot supersede itself")

    def test_rejects_an_unknown_supersession_target(self):
        self.fx.write("DEC-13-01-una-decision.md", record(extra="supersede: DEC-99-01"))
        self.assertReports('supersede references unknown decision "DEC-99-01"')


class ChangeDirectoryTest(BaseCase):
    def setUp(self):
        super().setUp()
        self.fx.write("DEC-13-01-una-decision.md", record())

    def test_accepts_a_well_formed_change(self):
        self.fx.change(
            "13-migracion-masiva",
            "proposal.md",
            '---\ntracking_issue: 13\ntitulo: "Migración masiva"\n---\n\nTexto.\n',
        )
        self.assertClean()

    def test_rejects_a_directory_without_a_tracking_number(self):
        self.fx.change(
            "migracion-masiva",
            "proposal.md",
            '---\ntracking_issue: 13\ntitulo: "X"\n---\n',
        )
        self.assertReports("does not match <tracking-number>", where="structural")

    def test_rejects_an_empty_change_directory(self):
        (self.fx.root / cd.CHANGES_DIR / "13-vacio").mkdir(parents=True)
        self.assertReports("contains no recognised document", where="structural")

    def test_rejects_a_mismatched_tracking_issue(self):
        self.fx.change(
            "13-migracion-masiva",
            "design.md",
            '---\ntracking_issue: 14\ntitulo: "X"\n---\n',
        )
        self.assertReports("does not match change directory tracking number")

    def test_flags_an_unknown_related_decision(self):
        self.fx.change(
            "13-migracion-masiva",
            "proposal.md",
            '---\ntracking_issue: 13\ntitulo: "X"\ndecisiones:\n  - DEC-99-01\n---\n',
        )
        self.assertReports('decisiones references unknown decision "DEC-99-01"')


class RetiredReferenceTest(BaseCase):
    def setUp(self):
        super().setUp()
        self.fx.write(
            "DEC-13-08-version-sentinela.md",
            record(ident="DEC-13-08", titulo="Sentinela", legacy_id="DEC-0030"),
        )
        self.decisions, _ = cd.discover_decisions(self.fx.root)

    def scan(self, files):
        return [f"{p}: {m}" for p, m in cd.find_retired_references(self.fx.root, files, self.decisions)]

    def test_flags_a_migrated_identifier(self):
        self.fx.other("lib.php", "// Ver DEC-0030 para el centinela.\n")
        self.assertEqual(1, len(self.scan(["lib.php"])))
        self.assertIn("lib.php:1", self.scan(["lib.php"])[0])

    def test_ignores_the_legacy_id_field(self):
        self.assertEqual([], self.scan([f"{cd.ADR_DIR}/DEC-13-08-version-sentinela.md"]))

    def test_ignores_bootstrap_identifiers_that_still_resolve(self):
        self.fx.other("lib.php", "// Ver DEC-0001, que sigue existiendo.\n")
        self.assertEqual([], self.scan(["lib.php"]))

    def test_ignores_the_documented_allowlist(self):
        self.fx.other(cd.MIGRATION_MAP, "| `DEC-0030` | `DEC-13-08` |\n")
        self.assertEqual([], self.scan([cd.MIGRATION_MAP]))

    def test_does_not_mistake_a_current_identifier_for_a_retired_one(self):
        self.fx.other("docs/x.md", "Ver DEC-2058-01 y DEC-13-08.\n")
        self.assertEqual([], self.scan(["docs/x.md"]))

    def test_skips_binary_and_missing_files(self):
        (self.fx.root / "blob.bin").write_bytes(b"\xff\xfe\x00DEC-0030")
        self.assertEqual([], self.scan(["blob.bin", "no/such/file.md"]))


class IndexTest(BaseCase):
    def test_orders_by_tracking_number_then_sequence(self):
        self.fx.write("DEC-0001-arranque.md", record(ident="DEC-0001", titulo="Arranque", tracking=None))
        self.fx.write("DEC-106-01-tarde.md", record(ident="DEC-106-01", titulo="Tarde", tracking="106"))
        self.fx.write("DEC-13-02-segunda.md", record(ident="DEC-13-02", titulo="Segunda"))
        self.fx.write("DEC-13-01-primera.md", record(ident="DEC-13-01", titulo="Primera"))
        self.fx.write("DEC-4-01-pronto.md", record(ident="DEC-4-01", titulo="Pronto", tracking="4"))

        decisions, errors = cd.discover_decisions(self.fx.root)
        self.assertEqual([], errors)
        index = cd.render_index(decisions)
        order = [line.split("]")[0][3:] for line in index.split("\n") if line.startswith("| [DEC-")]
        self.assertEqual(["DEC-0001", "DEC-4-01", "DEC-13-01", "DEC-13-02", "DEC-106-01"], order)

    def test_notes_the_superseding_record(self):
        self.fx.write(
            "DEC-13-08-vieja.md",
            record(ident="DEC-13-08", titulo="Vieja", estado="Superseded", extra="reemplazada_por: DEC-111-01"),
        )
        decisions, _ = cd.discover_decisions(self.fx.root)
        self.assertIn("reemplazada por DEC-111-01", cd.render_index(decisions))


class RepositoryTest(unittest.TestCase):
    """The real corpus must satisfy its own rules."""

    def test_the_repository_passes_its_own_validation(self):
        root = cd.find_repo_root(Path(__file__).parent)
        decisions, structural = cd.discover_decisions(root)
        changes, change_errors = cd.discover_changes(root)
        self.assertEqual([], structural + change_errors)
        self.assertEqual([], cd.validate(decisions, changes))

    def test_every_pending_identifier_still_exists(self):
        root = cd.find_repo_root(Path(__file__).parent)
        decisions, _ = cd.discover_decisions(root)
        present = {d.ident for d in decisions if d.legacy}
        self.assertEqual(set(cd.PENDING_LEGACY_IDS), present)

    def test_every_migrated_record_records_its_retired_identifier(self):
        root = cd.find_repo_root(Path(__file__).parent)
        decisions, _ = cd.discover_decisions(root)
        missing = [d.path for d in decisions if not d.legacy and not cd.as_text(d.data.get("legacy_id"))]
        self.assertEqual([], missing)


if __name__ == "__main__":
    unittest.main(verbosity=2)
