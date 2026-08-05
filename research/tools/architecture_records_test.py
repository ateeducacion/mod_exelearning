#!/usr/bin/env python3
"""Tests for architecture_records.py.

CANONICAL and byte-identical across the eXeLearning repositories, like the
module it exercises. Standard-library `unittest` only:

    python3 -m unittest discover -s <tools dir> -p '*_test.py'
"""

from __future__ import annotations

import json
import os
import shutil
import sys
import tempfile
import textwrap
import unittest

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))

import architecture_records as ar  # noqa: E402


def write(path: str, text: str) -> None:
    os.makedirs(os.path.dirname(path), exist_ok=True)
    with open(path, "w", encoding="utf-8") as handle:
        handle.write(textwrap.dedent(text).lstrip())


class Base(unittest.TestCase):
    PREFIX = "ADR"

    def setUp(self) -> None:
        self.root = tempfile.mkdtemp(prefix="arch-records-")
        if self.PREFIX != "ADR":
            write(os.path.join(self.root, ar.CONFIG_NAME), json.dumps({"prefix": self.PREFIX}))
        self.config = ar.load_config(self.root)
        os.makedirs(os.path.join(self.root, self.config["records_dir"]), exist_ok=True)
        os.makedirs(os.path.join(self.root, self.config["changes_dir"]), exist_ok=True)

    def tearDown(self) -> None:
        shutil.rmtree(self.root, ignore_errors=True)

    def record(self, filename: str, **over) -> None:
        ident = over.pop("id", None)
        if ident is None:
            parts = filename.split("-")
            ident = f"{parts[0]}-{parts[1]}-{parts[2]}"
        number = over.pop("tracking_issue", ident.split("-")[1])
        title = over.pop("title", "A decision")
        heading = over.pop("h1", f"{ident}: {title}")
        write(os.path.join(self.root, self.config["records_dir"], filename), f"""
            ---
            id: {ident}
            title: "{title}"
            status: {over.pop('status', 'Proposed')}
            date: {over.pop('date', '2026-08-05')}
            tracking_issue: {number}
            related:
              prs: [{over.pop('prs', '')}]
              changes: [{over.pop('changes', '')}]
              adrs: [{over.pop('adrs', '')}]
            supersedes: [{over.pop('supersedes', '')}]
            superseded_by: [{over.pop('superseded_by', '')}]
            ai_assistance:
              tool: "none"
              model: "none"
            ---

            # {heading}

            ## Context

            Text.
            """)

    def change(self, directory: str, name: str = "proposal.md", extra: str = "") -> None:
        number = directory.split("-")[0]
        write(os.path.join(self.root, self.config["changes_dir"], directory, name), f"""
            ---
            tracking_issue: {number}
            title: "A change"
            status: draft
            date: 2026-08-05
            {extra}
            ai_assistance:
              tool: "none"
              model: "none"
            ---

            # A change
            """)

    def problems(self):
        records, record_errors = ar.discover_records(self.root, self.config)
        changes, change_errors = ar.discover_changes(self.root, self.config)
        return record_errors + change_errors + ar.validate(records, changes, self.config)

    def assertProblem(self, fragment: str) -> None:
        problems = self.problems()
        self.assertTrue(
            any(fragment in p.message for p in problems),
            f"expected a problem containing {fragment!r}, got: {[p.message for p in problems]}",
        )


class TestFrontmatter(Base):
    def test_returns_none_without_frontmatter(self):
        self.assertIsNone(ar.parse_frontmatter("# Heading\n"))

    def test_parses_the_supported_subset(self):
        data, body = ar.parse_frontmatter(textwrap.dedent("""
            ---
            id: ADR-1234-02
            title: "Use asset:// references"
            empty: []
            inline: [ADR-1234-01, ADR-1234-03]
            deciders:
              - "@erseco"
              - "@other"
            related:
              prs: [90]
              adrs: []
            ai_assistance:
              tool: "Claude Code"
              model: "claude-opus-5"
            ---

            # Body
            """).lstrip())
        self.assertEqual(data["id"], "ADR-1234-02")
        self.assertEqual(data["title"], "Use asset:// references")
        self.assertEqual(data["empty"], [])
        self.assertEqual(data["inline"], ["ADR-1234-01", "ADR-1234-03"])
        self.assertEqual(data["deciders"], ["@erseco", "@other"])
        self.assertEqual(data["related"], {"prs": ["90"], "adrs": []})
        self.assertEqual(data["ai_assistance"], {"tool": "Claude Code", "model": "claude-opus-5"})
        self.assertEqual(body.strip(), "# Body")

    def test_nested_block_list(self):
        data, _ = ar.parse_frontmatter(textwrap.dedent("""
            ---
            related:
              adrs:
                - ADR-1-01
                - ADR-1-02
            ---

            x
            """).lstrip())
        self.assertEqual(data["related"], {"adrs": ["ADR-1-01", "ADR-1-02"]})


class TestGrammar(Base):
    def test_accepts_a_tracking_number_filename(self):
        match = self.config["record_re"].match("ADR-90-02-use-asset-uri-references.md")
        self.assertIsNotNone(match)
        self.assertEqual(match.groups(), ("90", "02", "use-asset-uri-references"))

    def test_accepts_the_bootstrap_sentinel(self):
        self.assertIsNotNone(self.config["record_re"].match("ADR-0-01-a-decision.md"))

    def test_rejects_bad_filenames(self):
        for bad in (
            "ADR-0035-file-attachment.md",  # retired global numbering
            "ADR-90-2-short-sequence.md",   # sequence must be two digits
            "ADR-90-02-Use-Caps.md",        # slug must be kebab-case
            "ADR-090-02-leading-zero.md",   # no leading zeros
            "SDD-0009-a-design.md",
        ):
            self.assertIsNone(self.config["record_re"].match(bad), bad)

    def test_change_directory_grammar(self):
        self.assertTrue(self.config["change_dir_re"].match("90-stale-content-url-redirects"))
        self.assertTrue(self.config["change_dir_re"].match("0-repository-bootstrap"))
        self.assertFalse(self.config["change_dir_re"].match("Not-Kebab"))

    def test_retired_pattern_ignores_current_identifiers(self):
        self.assertTrue(self.config["retired_re"].search("see ADR-0035"))
        self.assertTrue(self.config["retired_re"].search("see SDD-0009"))
        self.assertFalse(self.config["retired_re"].search("see ADR-1234-01"))

    def test_dates(self):
        self.assertTrue(ar.is_valid_date("2024-02-29"))
        for bad in ("2026-13-01", "2026-02-30", "2026-8-5", "yesterday", ""):
            self.assertFalse(ar.is_valid_date(bad), bad)


class TestDiscovery(Base):
    def test_skips_policy_files(self):
        for name in ar.SKIP_FILES:
            write(os.path.join(self.root, self.config["records_dir"], name), "# Not a record\n")
        self.record("ADR-90-01-a-decision.md")
        records, errors = ar.discover_records(self.root, self.config)
        self.assertEqual(len(records), 1)
        self.assertEqual(errors, [])

    def test_reports_retired_numbering_actionably(self):
        write(os.path.join(self.root, self.config["records_dir"], "ADR-0042-a.md"),
              "---\nid: ADR-0042\n---\n\n# x\n")
        _, errors = ar.discover_records(self.root, self.config)
        self.assertIn("retired global numbering", errors[0].message)

    def test_change_directory_needs_a_document(self):
        os.makedirs(os.path.join(self.root, self.config["changes_dir"], "90-empty"))
        changes, errors = ar.discover_changes(self.root, self.config)
        self.assertEqual(changes, [])
        self.assertIn("no recognised document", errors[0].message)

    def test_canonical_is_the_first_recognised_document(self):
        self.change("90-a-change", "design.md")
        self.change("90-a-change", "proposal.md")
        changes, _ = ar.discover_changes(self.root, self.config)
        self.assertEqual(changes[0].documents[0][0], "proposal.md")


class TestValidation(Base):
    def test_accepts_a_well_formed_corpus(self):
        self.record("ADR-90-01-first.md", title="First")
        self.change("90-a-change")
        self.assertEqual(self.problems(), [])

    def test_accepts_bootstrap_records(self):
        self.record("ADR-0-01-initial.md", title="Initial")
        self.record("ADR-0-02-second.md", title="Second")
        self.assertEqual(self.problems(), [])

    def test_rejects_id_filename_mismatch(self):
        self.record("ADR-90-01-first.md", id="ADR-90-02")
        self.assertProblem("does not match filename")

    def test_rejects_tracking_number_mismatch(self):
        self.record("ADR-90-01-first.md", tracking_issue="91")
        self.assertProblem("does not match filename number")

    def test_detects_duplicate_id(self):
        self.record("ADR-90-01-first.md", title="First")
        self.record("ADR-90-01-second.md", title="Second")
        self.assertProblem("duplicate id")

    def test_same_sequence_under_different_numbers_is_fine(self):
        self.record("ADR-90-01-first.md", title="First")
        self.record("ADR-91-01-second.md", title="Second")
        self.assertEqual(self.problems(), [])

    def test_rejects_bad_status_and_date(self):
        self.record("ADR-90-01-a.md", status="InProgress")
        self.assertProblem("is not one of")
        self.tearDown(); self.setUp()
        self.record("ADR-90-01-a.md", date="2026-13-99")
        self.assertProblem("not a valid YYYY-MM-DD")

    def test_rejects_dangling_reference(self):
        self.record("ADR-90-01-a.md", adrs="ADR-99-01")
        self.assertProblem("does not exist")

    def test_rejects_self_reference(self):
        self.record("ADR-90-01-a.md", adrs="ADR-90-01")
        self.assertProblem("references itself")

    def test_rejects_non_numeric_pr(self):
        self.record("ADR-90-01-a.md", prs='"#90"')
        self.assertProblem("not a positive integer")

    def test_rejects_non_url_external_ref(self):
        self.record("ADR-90-01-a.md")
        path = os.path.join(self.root, self.config["records_dir"], "ADR-90-01-a.md")
        with open(path, encoding="utf-8") as handle:
            text = handle.read()
        with open(path, "w", encoding="utf-8") as handle:
            handle.write(text.replace("supersedes: []", 'external_refs: [106]\nsupersedes: []'))
        self.assertProblem("is not an http(s) URL")

    def test_accepts_cross_repo_url(self):
        self.record("ADR-90-01-a.md")
        path = os.path.join(self.root, self.config["records_dir"], "ADR-90-01-a.md")
        with open(path, encoding="utf-8") as handle:
            text = handle.read()
        with open(path, "w", encoding="utf-8") as handle:
            handle.write(text.replace(
                "supersedes: []",
                'external_refs: [https://github.com/exelearning/wp-exelearning/pull/72]\nsupersedes: []'))
        self.assertEqual(self.problems(), [])

    def test_rejects_zero_pr(self):
        self.record("ADR-90-01-a.md", prs="0")
        self.assertProblem("not a positive integer")

    def test_rejects_one_sided_supersession(self):
        self.record("ADR-90-01-old.md", title="Old")
        self.record("ADR-91-01-new.md", title="New", supersedes="ADR-90-01")
        self.assertProblem("does not list superseded_by")

    def test_accepts_symmetric_supersession(self):
        self.record("ADR-90-01-old.md", title="Old", status="Superseded", superseded_by="ADR-91-01")
        self.record("ADR-91-01-new.md", title="New", supersedes="ADR-90-01")
        self.assertEqual(self.problems(), [])

    def test_flags_superseded_record_at_wrong_status(self):
        self.record("ADR-90-01-old.md", title="Old", superseded_by="ADR-91-01")
        self.record("ADR-91-01-new.md", title="New", supersedes="ADR-90-01")
        self.assertProblem('not "Superseded"')

    def test_rejects_self_supersession(self):
        self.record("ADR-90-01-a.md", supersedes="ADR-90-01")
        self.assertProblem("cannot supersede itself")

    def test_rejects_h1_mismatch(self):
        self.record("ADR-90-01-a.md", h1="Something else")
        self.assertProblem("H1 is")

    def test_rejects_missing_ai_assistance(self):
        self.record("ADR-90-01-a.md")
        path = os.path.join(self.root, self.config["records_dir"], "ADR-90-01-a.md")
        with open(path, encoding="utf-8") as handle:
            text = handle.read()
        with open(path, "w", encoding="utf-8") as handle:
            handle.write(text.replace('ai_assistance:\n  tool: "none"\n  model: "none"\n', ""))
        self.assertProblem("ai_assistance")

    def test_rejects_implementation_prs_outside_canonical(self):
        self.change("90-a-change", "proposal.md", extra="implementation_prs: [90]")
        self.change("90-a-change", "design.md", extra="implementation_prs: [90]")
        self.assertProblem("canonical metadata carrier")

    def test_rejects_change_tracking_mismatch(self):
        self.change("90-a-change")
        path = os.path.join(self.root, self.config["changes_dir"], "90-a-change", "proposal.md")
        with open(path, encoding="utf-8") as handle:
            text = handle.read()
        with open(path, "w", encoding="utf-8") as handle:
            handle.write(text.replace("tracking_issue: 90", "tracking_issue: 91"))
        self.assertProblem("does not match change directory number")

    def test_rejects_self_referencing_change(self):
        self.change("90-a-change", extra='related_changes: ["90-a-change"]')
        self.assertProblem("change references itself")


class TestRetiredScan(Base):
    def test_flags_a_retired_identifier(self):
        write(os.path.join(self.root, "notes.md"), "See ADR-0035 for the rationale.\n")
        problems = ar.find_retired_references(self.root, ["notes.md"], self.config)
        self.assertEqual(len(problems), 1)
        self.assertEqual(problems[0].file, "notes.md:1")

    def test_ignores_current_identifiers(self):
        write(os.path.join(self.root, "notes.md"), "See ADR-90-01 and ADR-91-02.\n")
        self.assertEqual(ar.find_retired_references(self.root, ["notes.md"], self.config), [])

    def test_allows_a_record_to_name_its_own_legacy_id(self):
        write(os.path.join(self.root, "design.md"),
              "---\nlegacy_id: SDD-0009\n---\n\nWritten as SDD-0009.\n")
        self.assertEqual(ar.find_retired_references(self.root, ["design.md"], self.config), [])

    def test_still_flags_a_different_legacy_id(self):
        write(os.path.join(self.root, "design.md"),
              "---\nlegacy_id: SDD-0009\n---\n\nSee ADR-0035.\n")
        self.assertEqual(len(ar.find_retired_references(self.root, ["design.md"], self.config)), 1)

    def test_skips_the_allowlist(self):
        self.config["legacy_allowlist"] = ["docs/policy.md"]
        write(os.path.join(self.root, "docs/policy.md"), "The retired ADR-0001 form.\n")
        self.assertEqual(ar.find_retired_references(self.root, ["docs/policy.md"], self.config), [])


class TestCommittedIndex(Base):
    def test_rejects_a_committed_index(self):
        problems = ar.find_committed_indexes(
            [f"{self.config['records_dir']}/records.md", "README.md"], self.config)
        self.assertEqual(len(problems), 1)
        self.assertIn("must not be committed", problems[0].message)

    def test_accepts_a_tree_without_one(self):
        self.assertEqual(ar.find_committed_indexes(["README.md"], self.config), [])


class TestRendering(Base):
    def test_is_deterministic_and_sorted(self):
        self.record("ADR-91-01-later.md", title="Later")
        self.record("ADR-90-02-second.md", title="Second")
        self.record("ADR-90-01-first.md", title="First")
        records, _ = ar.discover_records(self.root, self.config)
        changes, _ = ar.discover_changes(self.root, self.config)
        first = ar.render_index(records, changes, self.config)
        self.assertEqual(first, ar.render_index(records, changes, self.config))
        self.assertLess(first.index("ADR-90-01"), first.index("ADR-90-02"))
        self.assertLess(first.index("ADR-90-02"), first.index("ADR-91-01"))
        self.assertIn("Not a committed file", first)

    def test_labels_bootstrap_records(self):
        self.record("ADR-0-01-initial.md", title="Initial")
        records, _ = ar.discover_records(self.root, self.config)
        self.assertIn("bootstrap", ar.render_index(records, [], self.config))

    def test_renders_an_empty_corpus(self):
        self.assertIn("_None yet._", ar.render_index([], [], self.config))


class TestConfigurablePrefix(Base):
    """moodle-mod_exelearning has always called its records DEC. Only the
    prefix and the paths are configurable; every rule and every frontmatter
    key is identical in every repository."""

    PREFIX = "DEC"

    def test_accepts_the_configured_prefix(self):
        self.record("DEC-13-01-a-decision.md", title="A decision")
        self.assertEqual(self.problems(), [])

    def test_rejects_the_wrong_prefix(self):
        self.assertIsNone(self.config["record_re"].match("ADR-13-01-a-decision.md"))

    def test_accepts_bootstrap_records(self):
        self.record("DEC-0-01-initial.md", title="Initial")
        self.assertEqual(self.problems(), [])


if __name__ == "__main__":
    unittest.main()
