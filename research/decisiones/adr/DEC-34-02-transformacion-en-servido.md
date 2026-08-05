---
id: DEC-34-02
titulo: "Transformación del paquete en tiempo de servido (eliminar la reescritura del HTML en extracción)"
estado: Propuesta
fecha: 2026-06-10
tracking_issue: 34
legacy_id: DEC-0045
agentes:
  - erseco
  - claude-code
fuentes:
  - REPO-004
relacionados:
  - DEC-13-11
  - DEC-13-01
  - DEC-17-01
  - DEC-0019
herramienta_ia:
  interfaz: claude-code
  modelo: claude-fable-5
---

# DEC-34-02: Transformación del paquete en tiempo de servido (eliminar la reescritura del HTML en extracción)

## Contexto

El **informe técnico comparativo** señala como **deuda técnica nº1** de
`mod_exelearning` que, en tiempo de **extracción**, el plugin **reescribe
permanentemente** los ficheros del paquete almacenado:

- `exelearning_inject_scorm_loader()` (`lib.php`): por cada `.html`/`.htm` del filearea
  `content`, hace `preg_replace('~</head>~i', …)` para inyectar
  `<script src="libs/SCORM_API_wrapper.js">`, `<script src="libs/SCOFunctions.js">` y un
  `setInterval` que fuerza `pipwerks.SCORM.init()`. Marca de idempotencia
  `<!-- mod_exelearning:scorm-loader -->`.
- En `exelearning_extract_stored_package()` se **copian** además
  `libs/SCORM_API_wrapper.js` y `libs/SCOFunctions.js` desde `assets/scorm/` al filearea.
- `exelearning_patch_idevice_save_guards()` ([[DEC-13-11]]): `str_replace` sobre `form.js`
  y `scrambled-list.js` dentro del filearea (delete+recreate).

El shim SCORM 1.2 (`window.API` en `view.php`, parent-side) **no** es la deuda y se
mantiene. La salida definitiva es migrar el tracking a `core_xapi` ([[DEC-17-01]],
condicionada a upstream `exelearning#1867`), que eliminaría tanto el shim como la
inyección — pero **aún no está disponible**.

## Decisión (propuesta)

Mover **toda** la mutación de contenido de la **extracción** al **servido**, dejando los
ficheros del paquete **prístinos** en el almacenamiento:

- Nueva clase testeable `classes/local/content_transformer.php` (namespace
  `mod_exelearning\local`), estática y pura, con la lógica movida **verbatim** desde
  `lib.php` (marca + payload del loader, mapa de guards de [[DEC-13-11]]). Métodos:
  `transform(stored_file): ?string` (dispatcher; `null` = servir sin tocar),
  `transform_html(string,bool)`, `transform_idevice_js(string,string)`,
  `wrapper_asset_path(string): ?string`.
- `exelearning_pluginfile()` aplica la transformación al servir el filearea `content`:
  HTML y los dos JS parcheados se reescriben al vuelo con `send_file($content, …,
  pathisstring=true)`; el resto va por `send_stored_file()`. Determinista por revisión →
  el mismo `lifetime` de caché (la URL embebe `revision`).
- Los dos wrappers se sirven **virtualmente** desde `assets/scorm/` cuando faltan en el
  almacenamiento (un export SCORM que ya trae su `libs/` gana por el hit de
  almacenamiento), en vez de copiarlos al filearea.
- **Sin paso de upgrade ni re-extracción forzada**: las instalaciones existentes tienen
  los ficheros ya mutados (marca presente, guards ya aplicados, `libs/` presentes), de
  modo que el transformer es **no-op** sobre ellos y se sirven como hoy. Las áreas
  convergen solas al siguiente re-guardado/edición del paquete.

## Por qué se difiere (no se implementa aún)

- **Alcance y riesgo**: toca el camino de servido (`pluginfile`) que afecta a CADA
  petición de contenido (iframe, Mobile/[[DEC-26-02]], descargas). Diferencias de
  `send_file` vs `send_stored_file` (xsendfile, byte-ranges, `Last-Modified`/304) deben
  evaluarse; aceptable solo para HTML + 2 JS pequeños, pero exige verificación dedicada.
- **El fix transicional ya vive en extracción** y es funcional (DEC-13-11 + inject), con
  retrocompatibilidad garantizada por las marcas de idempotencia.
- **La salida definitiva es xAPI** ([[DEC-17-01]]): cuando el contrato upstream se congele,
  desaparece la necesidad del shim/inyección y este refactor podría quedar reducido.

Por todo ello se decide **documentar el diseño** y **diferir la implementación** a una
TAREA futura, priorizando en esta tanda las correcciones de bugs críticos ([[DEC-34-01]]).

## Consecuencias

- Ficheros del paquete servidos sin mutar at-rest (la crítica del informe), con la
  inyección aplicada solo en el flujo de servido y revertible sin tocar el almacenamiento.
- Sin migración de datos: retrocompatible por construcción.
- Pendiente: tests del `content_transformer` (extracción prístina; idempotencia de
  `transform_html`; guards; rutas raíz vs subdirectorio; `wrapper_asset_path`) y
  reescritura de los tests que hoy asertan el fichero almacenado para asertar la salida
  servida. Implementación → TAREA futura.
