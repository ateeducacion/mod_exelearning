---
id: DEC-13-03
titulo: "Crear .elpx desde cero (paquete opcional) y control de pantalla completa (issue #13 #1 y #6)"
estado: Aceptada
fecha: 2026-06-03
tracking_issue: 13
legacy_id: DEC-0024
agentes:
  - erseco
  - claude-code
fuentes:
  - REPO-004
relacionados:
  - DEC-0009
  - DEC-0012
herramienta_ia:
  interfaz: claude-code
  modelo: claude-opus-4-8
---

# DEC-13-03: Crear .elpx desde cero (paquete opcional) y control de pantalla completa (issue #13 #1 y #6)

## Contexto

El issue #13 pide poder **crear un recurso eLPX desde cero** sin subir un fichero previo
(punto 1, como hacen los plugins hermanos) y mejorar la interfaz (punto 6): mover el botón
**Editar con eXe** a la derecha y añadir un control de **pantalla completa** que falta.

## Decisión

**Crear desde cero (punto 1).** El `filemanager` `package` del formulario pasa a ser
**opcional** (se elimina la regla `required` en `mod_form.php`). La tubería ya tolera el
paquete vacío: `exelearning_save_and_extract_package()` retorna pronto si no hay fichero, y
`exelearning_get_package_url()` degrada a `null`, con lo que el editor embebido recibe
`initialProjectUrl: ''` y crea un proyecto nuevo en cliente (Yjs); al guardar,
`editor/save.php` extrae y sincroniza (DEC-0012). En `view.php`, el estado "sin contenido"
deja de ser un error: si el editor embebido está disponible se muestra un aviso informativo
que invita a usar **Editar con eXe**; si está deshabilitado, se guía a subir un `.elpx` por
los ajustes; al alumno se le mantiene el error duro. Requiere, por tanto, el **editor
embebido instalado** (coherente con DEC-0009: solo editor embebido).

**Interfaz (punto 6).** El botón **Editar con eXe** se alinea a la derecha
(`d-flex justify-content-end`). Se añade un botón **Pantalla completa** en una barra sobre el
iframe; el iframe ya declara `allow="fullscreen"`. La lógica vive en
`amd/src/fullscreen.js`, reescrito (el anterior era jQuery heredado que apuntaba a elementos
inexistentes `#exewebpage`/`#toggleFullscreen`): módulo ES6 que usa la Fullscreen API sobre
`#exelearningobject` con fallback de prefijos y sincroniza `aria-pressed`.

## Consecuencias

- No hay cambios de esquema. `exelearning_add_instance()` ya inicializaba `revision = 1` y
  `exelearning_sync_grade_items()` tolera la ausencia de paquete (0 calificables).
- El generador de tests admite `packagefilepath => false` para crear instancias sin paquete.
- `amd/build/fullscreen.min.js` se regenera con `grunt amd` (Moodle main, `public/mod`).

## Implementación

- `mod_form.php` (paquete opcional), `view.php` (estado sin-contenido + botón derecha + barra
  de pantalla completa + init AMD), `amd/src/fullscreen.js` (+ `amd/build/`),
  `lang/en/exelearning.php` (`fullscreen`, `nocontentyet`, `nocontentyetupload`),
  `tests/generator/lib.php` y `tests/lib_test.php::test_create_instance_without_package`.
