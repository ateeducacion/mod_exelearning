Description of the SCORM runtime files import into mod_exelearning
==================================================================

Files:     SCORM_API_wrapper.js, SCOFunctions.js, SOURCE
Location:  assets/scorm/
Upstream:  https://github.com/exelearning/exelearning
           public/app/common/scorm/scorm12/


What these are
--------------

The SCORM 1.2 runtime that eXeLearning embeds in every package it exports,
under the package's libs/ folder. This plugin ships the same two files and
installs them into every package it extracts.

There is one runtime per eXeLearning version, and this folder holds a copy of
one of them, unmodified. The copy is byte-identical to what
buildScorm12RuntimeFiles() produces upstream, including the version stamp in
the header of SCOFunctions.js:

    eXeLearning-SCORM12-Runtime: <eXeLearning version>

That line, and exeScorm12.runtimeVersion inside the file, are how anyone can
tell which release this copy tracks.


Provenance and licences
-----------------------

- SCORM_API_wrapper.js — the unmodified upstream pipwerks SCORM wrapper
  (MIT, v1.1.20180906, byte-identical to
  pipwerks/scorm-api-wrapper@82e455b4032ee08febf64d2fa2bf1aacaebaa446).
  This is the only third-party library in this folder and it is declared in
  thirdpartylibs.xml.
- SCOFunctions.js — first-party eXeLearning code (AGPL-3.0-or-later, the same
  project and licence as the bundled editor): a runtime written from the
  SCORM 1.2 RTE specification, assembled from the exe-scorm12-* layers in the
  upstream repository. It is not a third-party library, but it is not code
  under this plugin's licence either, so it is declared in thirdpartylibs.xml
  the same way the release ZIP declares the editor under dist/static — that
  file is where a reviewer looks for code the plugin ships under another
  licence. Its entry names the runtime stamp as the version; the commit it
  was built from and the digests of both files live in SOURCE.


Modifications made in Moodle
----------------------------

None. Both files are verbatim upstream, complete, with no layers dropped and
no local edits.

Everything Moodle-specific lives in the plugin's own code
(js/scorm_tracker.js and classes/local/scorm/).

If a future upstream version needs something changed to work here, change it
upstream. The moment this folder carries a local edit, "which runtime is this"
stops having an answer.


This runtime always wins
------------------------

classes/local/package_manager.php installs both files into every extracted
package, replacing whatever the package carried. That applies to a package
uploaded as a SCORM export too: an activity in this plugin grades with the
runtime the plugin ships and no other (DEC-105-01).

The pair is installed together or not at all. Half a runtime — the plugin's
wrapper next to a package's SCOFunctions.js — pairs files written against
different wrapper versions, which nobody tests.

This used to be conditional, installing the files only when the package lacked
them, and the file above used to carry a four-layer subset built to keep
window.exeScorm12.activities absent. Both are gone: the subset matched no
eXeLearning release and had to be assembled by hand on every update.

How the session gets opened is the other half of the model (DEC-105-02).
classes/local/scorm/scorm_injector.php puts a bootstrap on every page that
opens the session through the runtime's own host entry point,
exeScorm12.session.open({ ownsLifecycle: false }): the runtime's client comes
up, the entry policy runs and the write gate opens, while the page lifecycle
— the SCO's pagehide/visibilitychange handling — is NOT installed, because
every page of the package shares one session and a SCO lifecycle on page one
would close it for page two. End-of-page flushing is the plugin's own: the
tracker's synchronous beforeunload commit plus its 500 ms autocommit.

With the complete runtime installed, a package exported by an eXeLearning
that carries this runtime routes its scores through the activity registry:
common.js branches on window.exeScorm12.activities and writes the versioned
exe12/ cmi.suspend_data payload, which track.php and js/scorm_tracker.js
decode by its header. A package exported before the rewrite has no registry
consumer in its common.js and keeps writing the legacy lines, which the same
parsers still read. (An earlier revision of this file said the entry policy
never ran here and the legacy writer was kept whatever the registry. That
was measured on packages exported before the rewrite and is wrong for newer
ones: a post-rewrite package recorded nothing at all until the injector
opened the session.)

A SCORM 1.2 export uploaded as a package brings its own SCO entry —
<body class="exe-export exe-scorm exe-scorm12" onload="loadPage()">, and
exe_export.js calls loadPage() as well while the exe-scorm class is present.
The injector removes that entry (the onload attribute and the two class
tokens) before adding the bootstrap: the runtime hands the page lifecycle to
the FIRST successful open, and racing the page's load events against the
bootstrap is not a contract. The page is then what every other package this
plugin serves already is, a web export without the exe-scorm switch
(DEC-13-11).


How to update
-------------

1. Export any project as SCORM 1.2 with the eXeLearning release you want to
   track, and copy the two files straight out of the package's libs/ folder:

       libs/SCORM_API_wrapper.js
       libs/SCOFunctions.js

   That is the whole procedure. Do not assemble the file by hand, do not drop
   layers, do not patch anything.

2. Regenerate assets/scorm/SOURCE for the commit you exported from:

       core-commit:  the eXeLearning commit you built the export with
       runtime-version: the stamp now in the file
       sha256 of each of the two files

   scorm_runtime_test.php checks both files against those digests, so a copy that
   was edited here fails even if every banner and the stamp still look right.
   The stamp alone cannot do that: it names a release, and a release is built
   many times.

3. Check the stamp changed to the release you meant to track:

       head -3 assets/scorm/SCOFunctions.js

4. Update thirdpartylibs.xml: the <version> of the SCOFunctions.js entry is
   the stamp you just checked, and if upstream bumped the pipwerks version,
   its <version> too.

5. Re-run the tests that cover the runtime and the injection path:

       make test ARGS=mod/exelearning/tests/local/scorm/scorm_runtime_test.php
       make test ARGS=mod/exelearning/tests/local/scorm/scorm_injector_test.php
       make test ARGS=mod/exelearning/tests/lib_extract_test.php

   scorm_runtime_test.php fails if the copy is not a complete, stamped
   upstream runtime — that is what stops it drifting again.
