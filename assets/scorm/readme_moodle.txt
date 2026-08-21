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

SCORM_API_wrapper.js — none. Verbatim upstream.

SCOFunctions.js — not the same file upstream ships inside exported packages,
but every line in it IS byte-identical to upstream. The only difference is
which layers are included. Read the next section before touching this file.

  It is assembled from FOUR of the five runtime layers, in upstream's relative
  order: client, policy, lifecycle, adapter. The `exe-scorm12-activities.js`
  layer is deliberately omitted, for the reason given below.

There are no local edits. Upstream's adapter treats the activities layer as
optional in its install guard precisely so that this subset composes, so the
four layers are copied verbatim. If a future upstream version reintroduces a
hard dependency on the registry, do NOT patch it here — fix it upstream, or
this file starts drifting.

All Moodle-specific behaviour lives in the plugin's own code
(js/scorm_tracker.js and classes/local/scorm/).


Why the activities layer must NOT be shipped
--------------------------------------------

The activities layer installs a central activity registry at
`window.exeScorm12.activities`. Upstream's `common.js` decides how to write
`cmi.suspend_data` by probing for it:

    getActivityRegistry: function () {
        if (typeof window === 'undefined' || !window.exeScorm12) return null;
        return window.exeScorm12.activities || null;
    },

When that returns non-null, common.js switches `cmi.suspend_data` to the new
per-iDevice "exe12" format. This plugin's grading code
(classes/local/track.php and js/scorm_tracker.js) reads the LEGACY
suspend_data format. So shipping the activities layer here would silently
break per-iDevice grading for every activity in every package the plugin
serves — the score would still be written, but the plugin could no longer
parse what the runtime stored.

Keeping the registry absent makes `getActivityRegistry()` return null, which
keeps common.js on the legacy suspend_data writer. That is the invariant this
folder exists to protect. Do not "fix" it by adding the fifth layer, and do
not substitute a stub object: any TRUTHY `window.exeScorm12.activities` flips
common.js to the exe12 format just as the real registry does.

The remaining four layers are safe without it. The policy layer is written to
degrade: it reaches the registry only through

    return global.exeScorm12 && global.exeScorm12.activities;

and every use is null-guarded (`activities ? activities.summary() : null`,
`if (!activities) return ...`). The adapter never calls the registry at all —
before the removed guard line it only re-exported it — which is why dropping
that one condition is safe.

Why the guard line has to go: since upstream PR 2209 the adapter's layer
guard reads

    if (!exeScorm12 || !exeScorm12.client || !exeScorm12.activities ||
        !exeScorm12.policy || !exeScorm12.lifecycle || !pipwerks) { ... return; }

With the four-layer subset that guard is TRUE, so the adapter logs
"SCORM tracking is disabled" and returns before defining anything. The
package then has no `window.scorm` and no `window.loadPage` — the whole
runtime is dead, which is worse than the problem being fixed. Verified: an
unpatched four-layer build fails at `window.loadPage is not a function`.


How to update
-------------

1. Copy SCORM_API_wrapper.js verbatim from the eXeLearning release you want
   to track:

       public/app/common/scorm/scorm12/vendor/pipwerks/SCORM_API_wrapper.js

2. Do NOT copy SCOFunctions.js out of an exported SCORM package. Since
   upstream PR 2209 that file contains the activities layer, and using it
   here would silently kill per-iDevice grading (see the section above).
   Assemble it instead, from these four upstream layers, in this order:

       public/app/common/scorm/scorm12/exe-scorm12-client.js
       public/app/common/scorm/scorm12/exe-scorm12-policy.js
       public/app/common/scorm/scorm12/exe-scorm12-lifecycle.js
       public/app/common/scorm/scorm12/exe-scorm12-adapter.js

   (Upstream's own order is client, activities, policy, lifecycle, adapter —
   see SCORM12_RUNTIME_LAYER_PATHS in
   src/shared/export/utils/Scorm12Runtime.ts. Drop activities and keep the
   rest in that relative order.)

3. Apply the one local change to the adapter layer: delete the line

           !exeScorm12.activities ||

   from the layer guard near the top of exe-scorm12-adapter.js, and leave a
   comment in its place starting with `MOODLE LOCAL CHANGE (mod_exelearning)`
   explaining why (copy the wording from the current file). If that line is
   no longer there, upstream has changed the guard — re-read the section
   above and re-assess before continuing.

4. Concatenate, matching upstream's assembly format exactly
   (buildScorm12RuntimeFiles in src/shared/export/utils/Scorm12Runtime.ts).
   The file is the banner followed by a blank line, then the sections joined
   by a newline, where each section is:

       /* ==== <layer filename> ==== */\n<layer text, trimmed>\n

   and the banner is the 10-line comment at the top of the current file.
   Keeping this byte-exact matters: it is what lets a reviewer diff this file
   against a freshly assembled one and see only the documented local change.

5. Verify the four properties that must hold, in a browser console on a page
   serving the package (or any JS sandbox that loads the two files in order,
   SCORM_API_wrapper.js first):

       window.scorm                     // must be defined (adapter installed)
       typeof window.loadPage           // must be "function"
       window.exeScorm12.activities     // must be undefined  <-- the invariant
       typeof window.exeScorm12.policy.setScoreDetailed   // must be "function"

   Then confirm a score written through
   `window.exeScorm12.policy.setScoreDetailed(80, 0, 100)` reaches
   `cmi.core.score.raw` on the LMS side. The first two properties catch a
   missing guard patch (step 3); the third catches an accidentally included
   activities layer; the fourth catches a stale copy of the policy layer,
   which is what made a #2209 package throw
   "runtime.policy.setScoreDetailed is not a function" and never record a
   score.

6. If upstream bumped the pipwerks version, update its <version> in
   thirdpartylibs.xml.

7. Re-run the tests that cover the injection path:

       make test ARGS=mod/exelearning/tests/local/scorm/scorm_injector_test.php
       make test ARGS=mod/exelearning/tests/lib_extract_test.php
