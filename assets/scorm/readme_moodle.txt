Description of the SCORM runtime files import into mod_exelearning
==================================================================

Files:     SCORM_API_wrapper.js, SCOFunctions.js
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
  upstream repository. It is not a third-party library and is therefore not
  listed in thirdpartylibs.xml.


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
runtime the plugin ships and no other.

The pair is installed together or not at all. Half a runtime — the plugin's
wrapper next to a package's SCOFunctions.js — pairs files written against
different wrapper versions, which nobody tests.

This used to be conditional, installing the files only when the package lacked
them, and the file above used to carry a four-layer subset built to keep
window.exeScorm12.activities absent. Both are gone. Measured on 25 recorded
scenarios and two package vintages, serving the complete runtime instead of the
subset produces identical LMS traffic: a web export does not carry the
exe-scorm body class, so it never calls loadPage(), the entry policy never
runs, and common.js keeps using the legacy cmi.suspend_data writer whether the
registry is installed or not. The subset was defending against something that
cannot happen in this plugin's serving model, at the cost of a file that
matched no eXeLearning release.


How to update
-------------

1. Export any project as SCORM 1.2 with the eXeLearning release you want to
   track, and copy the two files straight out of the package's libs/ folder:

       libs/SCORM_API_wrapper.js
       libs/SCOFunctions.js

   That is the whole procedure. Do not assemble the file by hand, do not drop
   layers, do not patch anything.

2. Check the stamp changed to the release you meant to track:

       head -3 assets/scorm/SCOFunctions.js

3. If upstream bumped the pipwerks version, update its <version> in
   thirdpartylibs.xml.

4. Re-run the tests that cover the runtime and the injection path:

       make test ARGS=mod/exelearning/tests/local/scorm/scorm_runtime_test.php
       make test ARGS=mod/exelearning/tests/local/scorm/scorm_injector_test.php
       make test ARGS=mod/exelearning/tests/lib_extract_test.php

   scorm_runtime_test.php fails if the copy is not a complete, stamped
   upstream runtime — that is what stops it drifting again.
