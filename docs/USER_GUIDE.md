# eXeLearning resource — User Guide

A practical, task-oriented guide for **teachers** and **Moodle administrators**
using the *eXeLearning resource* activity (`mod_exelearning`). It does not assume
any programming knowledge. Developers should read `DEVELOPMENT.md` instead.

## Contents

1. [What is the eXeLearning resource?](#1-what-is-the-exelearning-resource)
2. [Adding an eXeLearning resource to a course](#2-adding-an-exelearning-resource-to-a-course)
3. [Editing the resource in place](#3-editing-the-resource-in-place-with-the-embedded-editor)
4. [How grading works](#4-how-grading-works)
5. [Previewing as a student](#5-previewing-as-a-student)
6. [The attempts report](#6-the-attempts-report)
7. [Site administration (admins)](#7-site-administration-admins)
8. [FAQ and troubleshooting](#8-faq-and-troubleshooting)

---

## 1. What is the eXeLearning resource?

The *eXeLearning resource* activity embeds a published eXeLearning v4 package
(an `.elpx` file) directly inside a Moodle course. The package keeps its own
**native navigation sidebar**, so students browse the content exactly as the
author designed it. At the same time, Moodle inspects the package and registers
**each gradable iDevice** (interactive exercise such as a true/false question,
a drag-and-drop, a crossword, and so on) as its **own column in the gradebook**.
A single resource can therefore contribute several independent grade columns —
for example, one resource containing two quizzes records two separate grades —
which is what sets this activity apart from a plain SCORM package that reports
only one aggregated grade.

---

## 2. Adding an eXeLearning resource to a course

1. Turn editing on in your course and choose **Add an activity or resource**.
2. Select **eXeLearning resource**.
3. Fill in the form (fields are explained below) and click **Save**.

When you save, Moodle extracts the package, renders it, and automatically
creates the gradebook columns for every gradable iDevice it detects.

### Form fields

**General**

- **Name** — the activity title shown in the course.
- **Description** — optional introduction text.
- **Package file (.elpx)** — upload your eXeLearning v4 package here. Only one
  `.elpx` file is accepted. The package is extracted on the server and shown
  with its native sidebar preserved.

**Grading**

- **Maximum grade per item** — the maximum score each gradable iDevice column
  can reach (default `100`). Every detected iDevice is registered as a separate
  gradebook column with this maximum.
- **Minimum grade per item** — the floor for each column (default `0`).
- **Grade to pass** — the minimum overall grade a student needs to pass. This
  value also drives "SCORM-style" completion (see section 4). Leave it at `0`
  to disable pass-based completion.
- **Attempts grading method** — when a student submits more than once, this
  decides which value reaches the gradebook:
  - *Highest attempt* (default)
  - *Average of attempts*
  - *First attempt*
  - *Last attempt* (most recent)
  - *Lowest attempt*
- **Gradebook columns** — how the activity reports to the gradebook (see
  section 4 for guidance on choosing):
  - *Per iDevice only* — one visible column per gradable iDevice. A hidden
    overall item is kept only for pass-grade completion. **This is the default.**
  - *Overall only* — a single aggregated column, like SCORM.
- **Attempts allowed** — the maximum number of attempts per student. Set to `0`
  for unlimited (the default). One attempt corresponds to one page-load session
  of the activity.
- **Students may review attempts** — whether students see a summary of their
  own previous attempts on the activity page:
  - *Always* (default)
  - *After the activity is complete*
  - *Never*
- **Grade display** — how each column is shown (this only changes the display
  format; Moodle always stores grades numerically):
  - *Default (inherit from course)*
  - *Real (0-100)*
  - *Percentage*
  - *Letter (A, B, …)*
  - *Real and percentage*

**Appearance**

- **Show eXeLearning teacher-mode toggle** — eXeLearning packages can include a
  "teacher mode" toggle that reveals content marked for teachers only. When this
  setting is disabled (the default), that toggle is hidden inside the resource by
  injecting CSS into the package, so students never see it. Enable it to let the
  toggle appear. This is independent of the *Try as a student (preview)* button,
  which is always available to teachers.

The form also includes Moodle's standard common module settings (visibility,
completion conditions, groups, and so on).

---

## 3. Editing the resource in place with the embedded editor

The embedded eXeLearning editor ships with the plugin, so on a standard install
(from an official release ZIP) teachers can edit the package without leaving
Moodle.

1. Open the activity. You will see an **Edit with eXeLearning** button near the
   top (it appears only for users who can manage the activity, and only when the
   bundled editor is available).
2. Click it. The eXeLearning editor opens in a modal window loaded with your
   current package.
3. Make your changes inside the editor.
4. Click **Save to Moodle** to write the changes back to the activity.

When you save, Moodle re-reads the package and **updates the gradebook columns
automatically**. If you added new gradable iDevices, new columns appear; if you
removed some, the corresponding columns are dropped. You do not need to edit the
gradebook by hand.

If you do not see the **Edit with eXeLearning** button, see the FAQ in
section 8.

---

## 4. How grading works

### Columns: one per gradable iDevice, or a single overall

The package is scanned for gradable iDevices. The **Gradebook columns** setting
controls which columns are created:

- **Per iDevice only** — the default. Each gradable iDevice becomes its own
  gradebook column, so every exercise is graded separately. Choose this when
  each exercise is a distinct graded task — it is what makes this activity more
  granular than a plain SCORM package.
- **Overall only** — choose this when you only care about a single combined
  score for the whole resource (the SCORM-style behaviour). Best when the
  individual exercises are practice and you grade the resource as a whole.

You can switch between the two models at any time; switching removes the columns
that no longer apply and creates the ones that do. Grade history is preserved.

### Attempts and aggregation

Every time a student works through the activity, their results are stored as an
**attempt**. When a student has several attempts, the **Attempts grading method**
decides which value is sent to the gradebook column: the highest, the average,
the first, the last (most recent), or the lowest attempt. You can cap how many
attempts a student is allowed with **Attempts allowed**.

### SCORM-style completion ("pass to complete")

If you set a **Grade to pass** greater than `0` and enable Moodle's
*Require passing grade* completion condition (under the activity's completion
settings), the activity is automatically marked complete once the student
reaches the passing grade — the same way a SCORM package completes when the
learner passes. Leave **Grade to pass** at `0` to disable this behaviour.

---

## 5. Previewing as a student

Teachers can experience the activity exactly as a student would, without
affecting any grades.

1. Open the activity.
2. Click **Try as a student (preview)**.
3. A yellow banner appears reading *Preview mode (test): Nothing you do here
   will be saved to the gradebook.*
4. Interact with the iDevices freely. Nothing is recorded as an attempt and
   nothing is written to the gradebook.
5. Click **Exit preview mode** to return to the normal (grading) view.

The preview button is only available to teachers (users who can manage the
activity). Students cannot enter preview mode under any circumstances.

---

## 6. The attempts report

From the activity page, teachers see a short **participation summary** (for
example, "3 of 5 students have attempted this activity · average 72%"). Next to
it is a **View attempts report** button that opens the full report.

If gradable iDevices were detected, teachers also see a
**Gradable iDevices detected:** banner listing each detected item.

### Report columns

The **Attempts report** lists one row per student, attempt, and item:

- **User** — the student.
- **Attempt** — the attempt number for that student.
- **Item** — which grade item the row refers to: *Overall* or a specific
  iDevice.
- **Score** — the raw score over the maximum (e.g. `8.00 / 10.00`).
- **Status** — the state of the attempt: *completed*, *passed*, *failed*, or
  *incomplete*.
- **Submitted** — when the attempt was last updated.
- **Actions** — a **Delete attempt** button (shown only to teachers with the
  delete capability).

### Deleting an attempt

Click **Delete attempt** on a student's overall row to remove that entire
attempt (all its item rows). Moodle then **recalculates that student's grade**
from the remaining attempts according to the chosen grading method, and confirms
with "The attempt was deleted and the grade was recalculated."

---

## 7. Site administration (admins)

These tasks require administrator rights (the
`mod/exelearning:manageembeddededitor` capability and `moodle/site:config`).
Go to **Site administration > Plugins > Activity modules > eXeLearning resource**
(`admin/settings.php?section=modsettingexelearning`). Everything is on a single
page.

### The embedded editor comes with the plugin

The embedded editor is what lets teachers edit packages in place (section 3).
It ships **inside the plugin release ZIP** and that bundled copy is the only one
the plugin uses — there is nothing to install, update or repair from Moodle, and
the editor cannot be updated independently: **updating the editor means updating
the plugin** to the next release, which always bundles the matching editor
version.

If your installation has no bundled editor (for example, the plugin was
installed from a source checkout instead of an official release ZIP), the
**Edit with eXeLearning** button will not appear for teachers and the editor
pages report that the editor is not included. The fix is to install the plugin
from an official release ZIP.

> Sites upgrading from older plugin versions may still have an editor copy under
> `moodledata/mod_exelearning/embedded_editor/` installed by the removed
> management page. It is no longer used and can be deleted at any time.

### Managing styles

On the settings page you can manage the eXeLearning styles available to the
embedded editor:

- **Style ZIP package** — upload an eXeLearning style package (`.zip`
  containing a valid `config.xml`). It is installed automatically on save.
- **Uploaded styles** — enable, disable, or delete styles you have uploaded.
  Disabling hides a style from the editor without deleting it.
- **Built-in styles** — enable or disable the editor's bundled styles
  individually. Disabled built-ins are hidden, not deleted; projects can always
  fall back to the default style.
- **Block user-imported styles** — when enabled, the editor hides the
  *User styles* tab and refuses to install a style bundled inside an imported
  `.elpx`. Authors may then only choose from the admin-approved list. Use this
  to keep a consistent, approved look across your site.

All style management happens on this settings page (the per-row buttons
confirm destructive actions before applying them).

---

## 8. FAQ and troubleshooting

**The "Edit with eXeLearning" button does not appear.**
Two common causes: (a) the installation carries no bundled editor — the plugin
was not installed from an official release ZIP (section 7); or (b) you are
viewing as a student or without the capability to manage the activity. The button
only appears for users who can manage the activity and only when the bundled
editor is available.

**"Gradable iDevices detected" shows nothing / no columns are created.**
The package contains no gradable iDevices of the supported types. Only the
iDevice types listed below are registered as grade columns; any other content
(text, images, plain pages, unsupported activity types) is ignored. Add a
supported gradable iDevice in eXeLearning and re-save the package.

**Supported gradable iDevice types**

The following eXeLearning v4 iDevice types are recognised and graded; everything
else is silently ignored:

- `trueorfalse`
- `guess`
- `quick-questions`
- `quick-questions-multiple-choice`
- `quick-questions-video`
- `dragdrop`
- `complete`
- `classify`
- `relate`
- `sort`
- `identify`
- `discover`
- `crossword`
- `word-search`
- `puzzle`
- `trivial`
- `az-quiz-game`
- `mathproblems`
- `mathematicaloperations`
- `scrambled-list`

**A student says their score did not save.**
Grades are recorded as the student works and are submitted automatically.
Confirm the student was not in preview mode (preview never saves) and that they
have not exceeded **Attempts allowed**. If the student used all attempts they
see "You have used all your allowed attempts for this activity."

**The gradebook shows a different value than the student's last attempt.**
This is expected when **Attempts grading method** is set to something other than
*Last attempt* — for example, *Highest attempt* reports the best score across
all attempts, not the most recent.

**Students cannot see their previous attempts.**
Check **Students may review attempts**. *Never* hides past attempts; *After the
activity is complete* only shows them once the student has completed/passed.

**I deleted an attempt by mistake.**
Deletion is permanent and the grade is recalculated immediately from the
remaining attempts. The student can submit a new attempt if attempts remain.
