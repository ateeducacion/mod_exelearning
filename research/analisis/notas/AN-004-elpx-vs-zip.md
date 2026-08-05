---
id: AN-004
titulo: "Formato de entrada de mod_exelearning: ELPX v4 únicamente"
fecha: 2026-05-28
fuentes:
  - REPO-001
  - REPO-002
  - REPO-005
  - FTE-008
relacionados:
  - DEC-0-03
  - DEC-0-05
herramienta_ia:
  interfaz: claude-code
  modelo: claude-opus-4-7
---

## Resumen

Decisión explícita del producto (eserco, 2026-05-28): `mod_exelearning` trabaja
**exclusivamente con archivos `.elpx` de eXeLearning v4** y los exports publicados
derivados (Web estático, SCORM 1.2, SCORM 2004). Los formatos legacy `.elp` (v2/v3) y
las ramas `iteexe`/`iteexe_online` **quedan fuera del alcance**.

## Hechos citados

- `.elpx` es un ZIP con `content.xml` (manifest propietario), `index.html`,
  `html/<slug>.html`, `idevices/<type>/{*.html,*.js,*.css}`, `libs/`, `theme/`,
  `content/` (recursos). Ver FTE-008 (estructura observada con EXP-001).
- El export SCORM 1.2 (REPO-005, fixtures upstream) emite 1 SCO por página + un
  asset `COMMON_FILES`. Ver AN-005.
- `mod_exescorm/mod_form.php` también acepta `.zip` y `.xml`, pero esos modos
  legacy no se reutilizan: `mod_exelearning` no aspira a sustituir a `mod_scorm`
  como visor SCORM genérico.

## [INTERPRETACION]

- Aceptar sólo ELPX simplifica:
  - Validación del paquete (esquema `content.xml` conocido).
  - Detección de items calificables (`<odeIdeviceTypeName>` + lista de tipos
    calificables fija).
  - Versionado: una sola línea de upstream (eXeLearning v4) que seguir.
- Aceptar también el export "sitio web" o el "SCORM" sería opcional en v2, pero el
  primer ciudadano del plugin es el ELPX. Cuando el LMS Moodle reciba el archivo,
  podrá:
  1. Si es `.elpx`: extraer `content.xml`, parsear, publicar internamente como sitio
     web estático (reutilizando el pipeline del editor embebido) y registrar items.
  2. Si es un export SCORM ya publicado: leerlo como SCORM y reutilizar el bridge
     (Plan B en AN-005).

## Consecuencias para `mod_exelearning`

- `mod_form.php` aceptará un único `filetype`: `.elpx` (en v1).
- La fixture canónica para tests es
  `research/fixtures/elpx/really-simple-test-project.elpx`.
- Plugin no debe asumir ELPs ni soportarlos.

## [PENDIENTE]

- DEC-0-05 (futuro): formalizar política de formatos de entrada en v1/v2.
- Documentar en `mod_form.php` el mensaje de error si el usuario intenta subir un
  `.elp` legacy o un `.zip` arbitrario.
