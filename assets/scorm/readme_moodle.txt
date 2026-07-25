Description of the SCORM runtime files import into mod_exelearning
==================================================================

Files:     SCORM_API_wrapper.js, SCOFunctions.js
Location:  assets/scorm/
Upstream:  https://github.com/exelearning/exelearning
           public/app/common/scorm/


What these are
--------------

The SCORM 1.2 runtime that eXeLearning embeds in every package it exports,
under the package's libs/ folder. The plugin keeps its own copy because
packages exported as plain web content do not always include libs/: when an
uploaded package is missing them, classes/local/package_manager.php copies
these two files into the extracted package and
classes/local/scorm/scorm_injector.php adds the matching <script> tags, so
gradable iDevices can reach the SCORM 1.2 bridge installed by view.php.

Provenance and licences (as rebuilt upstream in exelearning/exelearning
pull request 2209, mirrored into this plugin by the coordinated update):

- SCORM_API_wrapper.js — the unmodified upstream pipwerks SCORM wrapper
  (MIT, v1.1.20180906, byte-identical to
  pipwerks/scorm-api-wrapper@82e455b4032ee08febf64d2fa2bf1aacaebaa446).
  This is the only third-party library in this folder and it is declared in
  thirdpartylibs.xml.
- SCOFunctions.js — first-party eXeLearning code (AGPL-3.0-or-later, the
  same project and licence as the bundled editor): a runtime written from the
  SCORM 1.2 RTE specification, assembled from the exe-scorm12-* layers in the
  upstream repository. It is not a third-party library and is therefore not
  listed in thirdpartylibs.xml.


Modifications made in Moodle
----------------------------

None. Both files are verbatim copies of what eXeLearning ships inside exported
packages; all Moodle-specific behaviour lives in the plugin's own code
(js/scorm_tracker.js and classes/local/scorm/).


How to update
-------------

1. Copy both files from the eXeLearning release you want to track:

       SCORM_API_wrapper.js:
         public/app/common/scorm/scorm12/vendor/pipwerks/SCORM_API_wrapper.js
       SCOFunctions.js:
         libs/SCOFunctions.js from any SCORM 1.2 package exported by that
         release (or assemble the exe-scorm12-* layers per the upstream
         doc/development/scorm12-runtime-contract.md)

2. If upstream bumped the pipwerks version, update its <version> in
   thirdpartylibs.xml.

3. Re-run the tests that cover the injection path:

       make test ARGS=mod/exelearning/tests/local/scorm/scorm_injector_test.php
       make test ARGS=mod/exelearning/tests/lib_extract_test.php
