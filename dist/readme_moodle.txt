Description of the eXeLearning editor import into mod_exelearning
=================================================================

Library:   eXeLearning (static editor build)
Location:  dist/static/
Upstream:  https://github.com/exelearning/exelearning
Licence:   AGPL-3.0-or-later
Version:   pinned by .editor-version in the plugin root; the version actually
           bundled in a release ZIP is recorded in thirdpartylibs.xml

This file lives in dist/ rather than in dist/static/ because the build wipes
dist/static/ on every rebuild (see "How to update" below).


What this is
------------

dist/static/ is the eXeLearning v4 authoring application, compiled to static
assets. It is the same application published at exelearning.net, developed and
maintained by the same team as this plugin (Cedec-INTEF and the collaborating
regional administrations), with its own public repository, release cycle and
licence.

Bundling it lets the plugin offer in-place editing right after installation,
with no download step. It is the only editor source the plugin uses: there is no
runtime installer and no moodledata copy — updating the editor means installing
the next plugin release, which always bundles the matching editor build
(classes/local/embedded_editor_source_resolver.php).


Licensing
---------

The editor is AGPL-3.0-or-later while this plugin is GPL-3.0-or-later. The two
may be combined and distributed together: section 13 of the GPLv3 grants
permission to combine a GPLv3 work with an AGPLv3 work, and section 13 of the
AGPLv3 grants the mirror-image permission. Each part keeps its own licence in
the combination, so the AGPL (including its network-interaction requirement)
continues to govern the editor under dist/static/.

The editor's own bundled dependencies and their licences are listed in
dist/static/libs/LICENSES.md.


Modifications made in Moodle
----------------------------

None. dist/static/ is copied verbatim from the upstream build output. The plugin
adds no patches: everything Moodle-specific lives in the plugin's own code
(editor/index.php bootstraps the editor in an iframe, editor/static.php serves
the assets, editor/save.php receives the saved package).


How to update
-------------

Requires Bun (https://bun.sh); the editor is not built with npm.

1. From the plugin root, build the editor for the release you want to track:

       make build-editor EXELEARNING_EDITOR_REF=v4.0.2 EXELEARNING_EDITOR_REF_TYPE=tag

   This shallow-clones the upstream repository into exelearning/ (git-ignored),
   runs "bun install && APP_VERSION=<ref> bun run build:static", then copies
   exelearning/dist/static/* into dist/static/ after emptying it.

2. Record the tag in .editor-version:

       echo v4.0.2 > .editor-version

3. Build the distributable ZIP:

       make package RELEASE=4.0.2

   scripts/package.sh stamps the dist/static entry (location, name, version read
   from .editor-version, licence AGPL-3.0-or-later) into the ZIP's copy of
   thirdpartylibs.xml. The committed thirdpartylibs.xml deliberately omits that
   entry because dist/static/ is absent from a plain git checkout and Moodle's
   "grunt ignorefiles" aborts on a missing <location>.

The scheduled workflow .github/workflows/check-editor-releases.yml performs
steps 1-3 automatically when a new editor release appears.
