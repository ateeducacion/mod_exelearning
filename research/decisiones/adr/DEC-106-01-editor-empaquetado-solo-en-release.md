---
id: DEC-106-01
titulo: "Editor embebido exclusivamente empaquetado en la release: eliminar el instalador/actualizador en runtime"
estado: Aceptada
fecha: 2026-07-24
tracking_issue: 106
legacy_id: DEC-0065
agentes:
  - erseco
  - claude-code
fuentes:
  - REPO-005
  - REPO-002
  - REPO-001
relacionados:
  - DEC-0005
  - DEC-0009
  - DEC-4-01
  - DEC-13-08
  - DEC-78-01
herramienta_ia:
  interfaz: claude-code
  modelo: claude-fable-5
---

# DEC-106-01: Editor embebido exclusivamente empaquetado en la release: eliminar el instalador/actualizador en runtime

## Contexto

Desde [[DEC-0005]]/[[DEC-0009]] el plugin soporta **dos fuentes** para el editor embebido, con
precedencia resuelta por `embedded_editor_source_resolver` (moodledata → bundled → none):

1. **Instalada por el administrador** en `moodledata/mod_exelearning/embedded_editor/`, descargada
   en runtime desde las releases de GitHub de [[REPO-005]] mediante
   `embedded_editor_installer` (~950 líneas: descubrimiento de la última versión vía feed Atom,
   descarga con verificación SHA-256 contra la API de GitHub, anti-SSRF/TLS de Moodle, extracción
   anti zip-slip, instalación atómica con rollback, lock anti-concurrencia) más su superficie de
   gestión: widget AJAX en Ajustes (`admin_setting_embeddededitor` + AMD
   `admin_embedded_editor` + mustache), dos funciones externas
   (`manage_embedded_editor_action`/`_status`), un endpoint de subida manual de ZIP
   (`manage_embedded_editor_upload.php`, usado históricamente por el Playground) y tres configs
   (`embedded_editor_version`, `embedded_editor_installed_at`, `embedded_editor_installing`).
2. **Empaquetada** en `dist/static/` dentro del ZIP de release (obligatoria y reproducible desde
   [[DEC-78-01]]: el editor se compila del tag homónimo y se declara en el `thirdpartylibs.xml` del
   ZIP con licencia AGPL-3.0-or-later).

La validación externa del Marketplace (tresipunt, 2026-07, hallazgo 4.1) señaló el problema de
fondo: la descarga en runtime es **remotely sourced executable code** — código ejecutable que el
plugin sirve a los usuarios sin haber pasado por el paquete revisado. Es el comentario clásico de
los revisores del directorio, y su "plan B" sugerido era exactamente este: retirar el actualizador.
Además:

- Dos instalaciones del mismo `version.php` pueden servir **builds distintos** del editor (el de
  moodledata manda), lo que rompe la trazabilidad versión-plugin ↔ versión-editor y complica
  soporte y diagnóstico.
- El instalador arrastra superficie de ataque y complejidad operativa que no paga su coste una vez
  que **toda release oficial ya incluye el editor**: red, TLS (con relajación
  `MOODLE_PLAYGROUND`, señalada también por el informe), SSRF, extracción de ZIP, rollback,
  bloqueos y estados en moodledata.
- El Playground ya no lo necesita: desde el paso `unzip` del `blueprint.json` el editor se
  despliega **antes de usarse y fuera del runtime del plugin**, desde un asset inmutable de una
  release fijada (no `main`).

## Decisión

El ZIP de release oficial es el **único** mecanismo de distribución del editor embebido.

1. **`dist/static/` es la única fuente en runtime.** El resolver deja de mirar moodledata y de
   implementar precedencia: valida el bundle (existencia, `index.html` legible, estructura de
   assets) y devuelve fuente disponible o ninguna. Se eliminan `SOURCE_MOODLEDATA` y compañía sin
   dejar abstracciones de compatibilidad.
2. **Se elimina toda la gestión en runtime**: instalador (descubrimiento, descarga, SHA-256,
   extracción, rollback, lock), funciones externas AJAX, endpoint de subida, widget de Ajustes,
   AMD, mustache, strings solo suyos y sus tests. Un upgrade step limpia las tres configs. La
   capability `mod/exelearning:manageembeddededitor` **se conserva** porque también protege el
   gestor de estilos (`admin/styles.php`, `editor/styles.php`); no es exclusiva del instalador.
3. **Sin editor válido, el plugin degrada con claridad**: el botón de edición no se ofrece y los
   endpoints del editor responden 404, igual que hasta ahora con `SOURCE_NONE`.
4. **El empaquetado falla en vez de producir un ZIP sin editor**: `scripts/package.sh` valida
   `dist/static/` + `index.html` + estructura + `.editor-version` no vacío antes de crear nada
   (stderr + exit ≠ 0, sin ZIP parcial), y siempre estampa la declaración del editor en el
   `thirdpartylibs.xml` del ZIP. `scripts/check-package.sh` cubre ambos caminos en CI.
5. **El Playground sigue soportado a nivel de blueprint/workflow**: descarga el asset del editor de
   una release fijada por tag exacto y lo despliega en `dist/static/` — sin reutilizar ni retener
   el instalador del plugin.

## Consecuencias

Positivas:

- Todo el código ejecutable que sirve el plugin forma parte del paquete revisado; desaparece la
  objeción de *remotely sourced code* del Marketplace.
- Una versión del plugin ⇔ un build conocido del editor ([[DEC-78-01]] queda como única vía).
- Menos superficie: sin red, TLS/SSRF, extracción, rollback ni estados en moodledata; Ajustes y
  arquitectura runtime más simples; soporte reproducible.

Negativas (asumidas):

- Actualizar el editor exige publicar una release nueva del plugin (el ecosistema ya versiona en
  lockstep, así que en la práctica era el flujo real).
- Un checkout de desarrollo no contiene `dist/static/`: hay que compilarlo (`make build-editor`) o
  inyectarlo (Playground) antes de usar la edición embebida; los tests usan un override de
  directorio solo-PHPUnit.
- Un directorio `moodledata/mod_exelearning/embedded_editor/` heredado queda obsoleto e ignorado;
  no se borra automáticamente (documentado, sin riesgo: nada lo lee ya).

## Alternativas consideradas

| Alternativa | Por qué se rechaza |
|---|---|
| Mantener el actualizador tal cual | Mantiene la objeción de código remoto y la divergencia plugin↔editor; coste de seguridad permanente para un caso que la release ya cubre. |
| Actualizador presente pero desactivado por defecto | El código sigue en el paquete y el revisor lo sigue auditando; un toggle no elimina la superficie ni la objeción. |
| Subida manual de un ZIP del editor por el admin | Sigue siendo código ejecutable no revisado con el plugin y reintroduce validación/extracción/rollback; mismo problema con otra puerta. |
| Conservar ambas fuentes (moodledata + bundled) | La precedencia es la causa de la divergencia de builds; sin instalador, la fuente moodledata es un apéndice muerto. |
| Cargar el editor desde una URL remota | Peor en todos los ejes: disponibilidad, integridad, privacidad y revisión; contrario a [[DEC-0009]]. |
