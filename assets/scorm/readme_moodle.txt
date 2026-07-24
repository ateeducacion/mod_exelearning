Description of the SCORM runtime wrappers import into mod_exelearning
=====================================================================

Files:     SCORM_API_wrapper.js, SCOFunctions.js
Location:  assets/scorm/
Upstream:  https://github.com/exelearning/exelearning
           public/app/common/scorm/
Licence:   MIT (declared in thirdpartylibs.xml)
Version:   1.1.20121006


What these are
--------------

The SCORM 1.2 runtime that eXeLearning embeds in every package it exports, under
the package's libs/ folder. SCORM_API_wrapper.js is Philip Hutchison's pipwerks
SCORM API wrapper (MIT, http://pipwerks.com); SCOFunctions.js is an adaptation
of the ADL Technical Team's SCOFunctions.js. Both carry eXeLearning-specific
additions made upstream, marked in the source as "developed for The new
eXeLearning" (pipwerks.nav.*, pipwerks.UTILS.convertTotalMiliSeconds*, the
isScorm parameter and the doLMSSetValue call added for Moodle tracking).

The plugin keeps its own copy because packages exported as plain web content do
not always include libs/. When an uploaded package is missing them,
classes/local/package_manager.php copies these two files into the extracted
package and classes/local/scorm/scorm_injector.php adds the matching <script>
tags, so gradable iDevices can reach the SCORM 1.2 bridge installed by view.php.


Modifications made in Moodle
----------------------------

None. The files are upstream copies with no Moodle-specific edits; all
Moodle-side behaviour lives in the plugin's own code (js/scorm_tracker.js and
classes/local/scorm/).

The bundled snapshot predates the eXeLearning v4.0.0 tag. Compared with upstream
v4.0.2 the only differences are test-only scaffolding added upstream (CommonJS
"module.exports" blocks and _getStartDate/_setStartDate style helpers), which
serves the editor's unit tests and is not needed here.


How to update
-------------

1. Copy both files from public/app/common/scorm/ of the eXeLearning release you
   want to track:

       https://raw.githubusercontent.com/exelearning/exelearning/<tag>/public/app/common/scorm/SCORM_API_wrapper.js
       https://raw.githubusercontent.com/exelearning/exelearning/<tag>/public/app/common/scorm/SCOFunctions.js

2. Update <version> in thirdpartylibs.xml if upstream bumped it.

3. Re-run the tests that cover the injection path:

       make test ARGS="mod/exelearning/tests/local/scorm/scorm_injector_test.php mod/exelearning/tests/lib_extract_test.php"
