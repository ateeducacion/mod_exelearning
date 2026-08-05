---
id: DEC-110-01
title: "La página de estilos queda como endpoint de acciones: se elimina el gestor visible duplicado"
status: Accepted
date: 2026-07-24
tracking_issue: 110
legacy_id: DEC-0067
deciders:
  - erseco
  - claude-code
sources:
  - REPO-004
related:
  adrs: [DEC-67-01, DEC-108-01]
ai_assistance:
  tool: claude-code
  model: claude-fable-5
---

# DEC-110-01: La página de estilos queda como endpoint de acciones: se elimina el gestor visible duplicado

## Contexto

La gestión de estilos de eXeLearning vivía en **dos sitios** a la vez:

1. **La página de ajustes del plugin**, donde los tres widgets (subida, subidos,
   integrados) funcionan completos: el control de subida va dentro del formulario de
   ajustes y se instala al pulsar "Guardar cambios".
2. **`admin/styles.php`**, registrada como página visible del menú de administración, que
   volvía a pintar los mismos tres widgets con `output_html('')`.

La segunda vida era una trampa: el widget de subida pintado a pelo **no tiene formulario
ni botón que lo envíe**, así que un ZIP arrastrado ahí parece aceptado, nunca se instala y
desaparece al recargar sin mensaje alguno. Es el hallazgo **UX-01** de la validación
externa de la release 4.0.2 (Tresipunt, que lo sufrió durante sus pruebas), y el informe
ofrecía dos salidas: envolver el control en su propio formulario, o retirar la vista y
dejar la gestión solo en ajustes.

El fichero, sin embargo, **no puede desaparecer**: los botones activar/desactivar/borrar
de cada fila —también los de la página de ajustes— son enlaces GET con sesskey que
apuntan a `admin/styles.php`, porque un `<form>` anidado dentro del formulario de la
página de ajustes es HTML inválido y llegó a romper el guardado completo de esa página
(corregido en el PR 81 convirtiendo los botones en enlaces; ver el registro de la
auditoría [[DEC-67-01]]). Esas acciones necesitan un manejador fuera del formulario de
ajustes, y ese manejador es este script.

## Decisión

`admin/styles.php` queda como **controlador puro**, sin interfaz propia:

- Conserva el procesado de acciones (enable/disable/delete de subidos, toggles de
  integrados) y la **confirmación server-side del borrado** (la única pantalla que
  renderiza, necesaria porque el borrado llega por GET y un prefetch no debe borrar).
- Tras cada acción **redirige a la página de ajustes**, que pasa a ser el único lugar de
  gestión; una visita directa sin acción también redirige allí.
- El registro `admin_externalpage` se mantiene (lo exige `admin_externalpage_setup()` y
  el árbol de administración) pero **oculto del menú** (`hidden = true`).
- Se elimina el render duplicado de los tres widgets, y con él la subida huérfana:
  **UX-01 queda resuelto por eliminación de la superficie**, la opción (b) del informe.
- El docblock del script advierte explícitamente que no se le vuelva a añadir interfaz.

## Consecuencias

- Positivas: un único lugar de gestión (donde todo funciona); desaparece la subida que
  descartaba ficheros en silencio; menos superficie duplicada que mantener y revisar;
  los enlaces de acción existentes no cambian de URL (sin migración).
- Negativas (asumidas): quien tuviera `admin/styles.php` en marcadores aterriza en la
  página de ajustes (redirección, no error); la "página de estilos" desaparece de la
  documentación de usuario (actualizada en este cambio).

## Alternativas consideradas

| Alternativa | Por qué se rechaza |
|---|---|
| Envolver el control de subida de `styles.php` en su propio formulario (opción a del informe) | Mantiene dos gestores paralelos del mismo estado: más código, más superficie de revisión, y la duplicidad seguía confundiendo. |
| Dejarlo como estaba | La trampa de la subida silenciosa está confirmada por un tercero; "no romper nada" no justifica conservar una vista que descarta trabajo del administrador. |
| Mover las acciones a formularios POST propios dentro de ajustes | Imposible sin anidar formularios (el origen del problema que arregló el PR 81). |
