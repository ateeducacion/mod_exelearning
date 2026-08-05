---
id: DEC-13-06
title: "Enlaces del libro de calificaciones: análisis y destino del 'grade analysis' (issue #13 #4)"
status: Accepted
date: 2026-06-03
tracking_issue: 13
legacy_id: DEC-0028
deciders:
  - erseco
  - claude-code
sources:
  - REPO-004
related:
  adrs: [DEC-13-02, DEC-13-07]
ai_assistance:
  tool: claude-code
  model: claude-opus-4-8
---

# DEC-13-06: Enlaces del libro de calificaciones: análisis y destino del 'grade analysis' (issue #13 #4)

## Contexto

El punto 4 del issue #13 pide que "los enlaces del libro de calificaciones lleven directamente
a la actividad/evaluación concreta, no al inicio del recurso". El usuario pidió analizar **qué
hace cada enlace/menú** del grade item y si tiene sentido, y documentarlo. Análisis verificado
contra el core de Moodle main (`public/grade/lib.php`, `public/grade/report/*`).

## Hallazgos — enlaces de un grade item de actividad

| Enlace / menú | Dónde aparece | Destino por defecto | ¿Puede cambiarlo un plugin? |
|---|---|---|---|
| **Cabecera de columna** (`gradeitemheader`) | grader / user report | `/mod/MOD/view.php?id=<cmid>` (sin `itemnumber`) | **NO** — `grade_helper::get_activity_link()` lo fija; sin hook |
| Nombre de la actividad (user report) | user report | igual (`view.php?id`) | **NO** |
| **Grade analysis** | menú por-nota | `/mod/MOD/grade.php?id&itemid&itemnumber&gradeid&userid` **si** el módulo trae `grade.php` | **SÍ** (presencia de `grade.php` + destino del `redirect`) |
| Single view / edit / calculation / hide / lock / sort | menú de columna | páginas core de `grade/` | NO (core estándar) |

**Conclusión:** la **cabecera de columna NO es deep-linkable** (límite duro del core: la URL la
genera el core con solo el cmid). El **único** punto que el plugin controla es `grade.php`, que
alimenta el enlace **"grade analysis"** del menú por-nota (aparece porque enviamos `grade.php`,
igual que `mod_scorm` y `mod_h5pactivity`).

## Decisión

- **Mantener `grade.php`** (habilita "grade analysis"; coherente con SCORM/h5p).
- **Destino por rol** (revisión de DEC-13-02): profesor/corrector
  (`mod/exelearning:viewreport`) → `report.php` (informe de intentos, "ver el intento");
  alumno → `view.php?idevice=<objectid>` (el iDevice concreto del punto 4). Helper
  `exelearning_grade_analysis_url()`.
- **Documentar el límite:** la **cabecera** seguirá yendo a `view.php?id` (inicio del recurso);
  el punto 4 se cumple en el "grade analysis", no en la cabecera (no se hackea el core).
- **No tocar** los demás enlaces del menú (edit/hide/lock/single view): son del core y tienen
  sentido tal cual; el plugin no aporta nada cambiándolos.

## Consecuencias

- `report.php` acepta `userid` (lo reenvía el enlace "grade analysis" del libro): el profesor cae
  en los intentos de ese alumno concreto (filtra la tabla y muestra su nombre), respetando el modo
  de grupos. Sin `userid` se ve el informe completo. (Implementado 2026-06-04.)
- El alumno solo ve "grade analysis" en su *user report*; como `report.php` exige capacidad de
  informes, al alumno se le envía al contenido — de ahí la lógica por rol.

## Implementación

`grade.php` (redirect por rol, reenvía `userid`), `lib.php::exelearning_grade_analysis_url()`
(parámetro `userid`), `report.php` (filtro por `userid` + nombre del alumno), test
`tests/lib_test.php::test_grade_analysis_url_role_based`.
