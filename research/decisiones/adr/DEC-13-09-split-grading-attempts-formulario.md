---
id: DEC-13-09
titulo: "Separar el formulario del recurso en 'Grading' y 'Attempts management' (issue #13)"
estado: Aceptada
fecha: 2026-06-03
tracking_issue: 13
legacy_id: DEC-0031
agentes:
  - erseco
  - claude-code
fuentes:
  - REPO-001
relacionados:
  - DEC-0007
  - DEC-0008
  - DEC-13-07
herramienta_ia:
  interfaz: claude-code
  modelo: claude-opus-4-8
---

# DEC-13-09: Separar el formulario del recurso en 'Grading' y 'Attempts management' (issue #13)

## Contexto

La sección de calificación de `mod_form.php` tenía un único header (`gradingsection` /
"Grading") con **9 campos** que mezclaban dos conceptos distintos: la **calificación**
(`gradeenabled`, `grademodel`, `grademax`, `grademin`, `gradepass`, `gradedisplaytype`) y la
**gestión de intentos** (`maxattempt`, `grademethod` = qué intento cuenta, `reviewmode`). El
usuario pidió que "no haya tantos elementos en grading", tomando como referencia cómo
**mod_exescorm** (REPO-001) parte su formulario.

mod_exescorm usa dos headers: `gradesettings` ("Grade": `grademethod`, `maxgrade`) y
`attemptsmanagementhdr` ("Attempts management": `maxattempt`, `whatgrade`, `forcenewattempt`,
`lastattemptlock`).

## Decisión

Partir la sección en **dos headers**, replicando el patrón de mod_exescorm:

- **`gradingsection` ("Grading")** — cómo se puntúa la actividad: `gradeenabled` (interruptor
  maestro, DEC-13-07), `grademodel` (columnas por iDevice vs global, DEC-0008), `grademax`,
  `grademin`, `gradepass` (completación por nota, DEC-0010), `gradedisplaytype`.
- **`attemptssection` ("Attempts management")** — cómo se gestionan los intentos: `maxattempt`
  (límite), `grademethod` (qué intento cuenta: mayor/media/primero/último/menor, DEC-0007),
  `reviewmode` (revisión por el alumno).

Cadena nueva `attemptsmanagementheading` = "Attempts management" (en orden alfabético en
`lang/en`; espejada a es/ca/eu/gl con la marca `~` de traducción pendiente de revisión, DEC-11-01).

## Consecuencias

- **Sin cambio de comportamiento**: los elementos conservan nombre, tipo y valor por defecto; solo
  se reagrupan. El `disabledIf` del interruptor `gradeenabled` (DEC-13-07) sigue cubriendo los 8
  campos de ambas secciones, así que desmarcar "Graded activity" los deshabilita todos igual.
- La pantalla de ajustes queda más legible: la calificación no se mezcla con la política de
  intentos.
- No afecta al guardado/lectura (`add_instance`/`update_instance`) ni a los tests existentes (no
  dependen del header donde vive cada campo).

## Implementación

`mod_form.php` (nuevo header `attemptssection` tras los campos de nota; `maxattempt`,
`grademethod` y `reviewmode` movidos a la nueva sección; lista `disabledIf` actualizada).
`lang/en/exelearning.php` + `es`/`ca`/`eu`/`gl` (`attemptsmanagementheading`).
