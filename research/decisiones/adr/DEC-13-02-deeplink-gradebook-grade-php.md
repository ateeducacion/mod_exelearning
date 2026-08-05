---
id: DEC-13-02
title: "Deep-link desde el gradebook al iDevice vía grade.php (issue #13 #4)"
status: Accepted
date: 2026-06-03
tracking_issue: 13
legacy_id: DEC-0023
deciders:
  - erseco
  - claude-code
sources:
  - REPO-004
related:
  adrs: [DEC-5-01, DEC-13-01]
ai_assistance:
  tool: claude-code
  model: claude-opus-4-8
---

# DEC-13-02: Deep-link desde el gradebook al iDevice vía grade.php (issue #13 #4)

## Contexto

El issue #13 (punto 4) pide que los enlaces del libro de calificaciones de Moodle lleven a la
**actividad concreta** asociada a cada nota, en lugar de al inicio del recurso.

El Gradebook API de Moodle **no** ofrece un override de URL por `itemnumber`: las llamadas
del plugin son `*_grade_item_update` / `*_update_grades` / `*_supports`, ninguna fija el
destino del enlace. Sí existe, en cambio, el patrón estándar `/mod/MOD/grade.php`: el libro
de calificaciones enlaza cada ítem de una actividad a ese script pasándole `id` (cmid) e
`itemnumber` (verificado en `public/mod/h5pactivity/grade.php` de Moodle main, que lee
`id`/`itemnumber`/`userid` y hace `redirect()`).

## Decisión

Añadir `/mod/exelearning/grade.php` que recibe `id` + `itemnumber`, traduce el `itemnumber`
al `objectid` del iDevice dueño (tabla `exelearning_grade_item`) y redirige a
`view.php?id=<cmid>&idevice=<objectid>`; `itemnumber=0` (nota global) lleva a la portada.

En `view.php`, el parámetro `idevice` (validado contra `[A-Za-z0-9_-]`) se aplica como
**fragmento** (`set_anchor`) del `src` del iframe. Los iDevices exportados se renderizan como
`<article id="<odeIdeviceId>">`, de modo que el ancla desplaza nativamente hasta la actividad.

La lógica de traducción vive en una función pura testeable,
`exelearning_grade_item_view_url()`, para no depender de ejecutar `grade.php` en los tests.

## Consecuencias

- **Garantizado** en paquetes de una página (ancla nativa). En multipágina, el iDevice puede
  vivir en otro fichero HTML; el deep-link aterriza en la portada (mejor esfuerzo) hasta que
  se añada la resolución `objectid → fichero de página` (mejora futura).
- No hay cambios de esquema: `exelearning_grade_item` ya almacena `objectid` e `itemnumber`.
- `grade.php` solo hace `require_login` + `redirect`; las comprobaciones de capacidad las
  realiza `view.php` (igual que el patrón de core).

## Implementación

- `grade.php` (nuevo, patrón h5pactivity).
- `lib.php`: `exelearning_grade_item_view_url()`.
- `view.php`: lectura de `optional_param('idevice', ...)` y `set_anchor` del iframe.
- Test: `tests/lib_test.php::test_grade_item_view_url_deeplinks_by_itemnumber`.
