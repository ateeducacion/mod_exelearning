---
id: AN-008
titulo: "Comparativa: iframe simple (mod_exelearning) vs SCORM player de Moodle core"
fecha: 2026-05-28
fuentes:
  - REPO-002
  - REPO-004
relacionados:
  - DEC-0-03
  - DEC-0-04
  - AN-001
  - EXP-002
herramienta_ia:
  interfaz: claude-code
  modelo: claude-opus-4-7
---

## Resumen

Nuestro `view.php` mete el ELPX extraído en un `<iframe>` que apunta a
`pluginfile.php/.../content/<rev>/index.html`. El `mod/scorm/player.php` de
Moodle core hace MUCHO más: monta una `window.API` SCORM, valida `sesskey` en
cada llamada al endpoint `datamodel.php`, gatekeepea con capability
`mod/scorm:savetrack`, ofrece modo popup, sirve TOC server-side, valida modo y
`currentorg` contra BD, etc. Para nuestro caso de uso (preservar la sidebar
nativa de eXeLearning v4) la mayor parte de ese aparato sobra. Sí merecen
adoptarse 4 puntos concretos.

## Hechos citados

### mod_scorm/player.php (REPO-004)

- `confirm_sesskey()` en `mod/scorm/datamodel.php` antes de cada SetValue.
- `has_capability('mod/scorm:savetrack', $context)` antes de aceptar tracking.
- `mod/scorm/loadSCO.php` renderiza HTML "loggedinnot" si no hay sesión (en
  vez de enseñar el login dentro del iframe).
- `data_for_js('scormplayerdata', …, true)` inyecta `window.scormplayerdata`
  (`true` = secured, sólo para scripts del módulo).
- `mod/scorm/lib.php:1019`: `send_stored_file($file, $lifetime, 0, false, ['dontforcesvgdownload' => true, ...])`.
- `mod/scorm/lib.php`: `lifetime = 0` para filearea `content` (no cache).
- Modo popup (`displaymode == 'popup'`): `$PAGE->set_pagelayout('embedded')`.
- TOC server-side (`scorm_get_toc(...)`).
- Validación `currentorg` contra `scorm_scoes` para evitar inyección.

### mod_exelearning/view.php actual (EXP-002)

- `require_capability('mod/exelearning:view', $context)`.
- `pluginfile`: `has_capability('mod/exelearning:view', $context)` antes de
  servir. Cache `$CFG->filelifetime` (24h por defecto).
- Iframe sin `sandbox`, con `allow="fullscreen"`.
- TOC delegado al JS del paquete eXeLearning (queremos preservar la sidebar
  nativa — AN-001).
- No hay endpoint cliente↔servidor todavía (el bridge xAPI llegará).

## [INTERPRETACION]

Las garantías que SCORM player añade existen porque expone una API JS global
(`window.API`/`API_1484_11`) que cualquier código en la página podría llamar.
Nuestro plugin **no expone ninguna API JS global** hoy: el paquete sólo
renderiza visualmente. Por tanto:

- `sesskey` + `mod/X:savetrack`: necesarios sólo cuando implementemos el
  bridge xAPI → cubiertos por `core_xapi_post_statement` (Moodle core ya
  valida sesskey/token automáticamente).
- `loadSCO.php` HTML-fallback: nuestro `view.php` ya hace `require_login`
  antes de pintar nada, así que el iframe nunca verá el login page.
- `data_for_js` con flag secured: no aplica todavía (no inyectamos JS propio).
- TOC server-side: no queremos esto, justamente queremos la sidebar nativa
  (AN-001).
- Popup: UX nice-to-have, no de seguridad.

Aún así hay 4 mejoras **bajas en coste, altas en valor** que conviene aplicar
ya:

1. **`dontforcesvgdownload = true`** en nuestro `pluginfile`. eXeLearning v4
   embebe SVGs en `content/css/icons/` que deben renderizarse, no descargarse.
2. **`sandbox` selectivo del iframe**: `allow-scripts allow-same-origin
   allow-popups allow-forms`. Bloquea `allow-top-navigation` y `allow-modals`,
   que un paquete malicioso podría usar para escapar a la página padre o
   abrir alerts intrusivos. `allow-same-origin` sigue siendo necesario para
   que el JS del paquete cargue sus assets relativos.
3. **`require_capability` en `pluginfile`** en lugar de `has_capability`:
   devuelve 403 explícito en vez de "404 silencioso".
4. **`Content-Disposition: inline`** por defecto para HTML servido vía
   pluginfile (evita que el browser ofrezca descargar `index.html`).
5. **Doc-comment** sobre por qué NO usamos sandbox total (sería incompatible
   con jQuery del paquete + pipwerks SCORM en SCORM export).

## [HIPOTESIS]

- Servir el contenido extraído desde un **subdominio dedicado** (estilo
  `userhost.example.com/pluginfile.php/...`) eliminaría el riesgo de XSS
  cross-component que tiene un iframe con `allow-same-origin` cuando el
  paquete viene de un autor no confiable. Patrón usado por H5P y Google Docs.
  Coste alto en infraestructura (DNS + virtualhost). Reservar para
  organizaciones que reciben paquetes de terceros.
- Sandbox total (`<iframe sandbox>`) rompe el JS del paquete porque jQuery +
  iDevices necesitan `allow-scripts` Y `allow-same-origin`. No es opción.

## Sandbox aplicado tras EXP-002

```html
<iframe sandbox="allow-scripts allow-same-origin
                 allow-popups allow-forms
                 allow-popups-to-escape-sandbox" …>
```

Chrome dispara un warning informativo: *"An iframe which has both allow-scripts
and allow-same-origin for its sandbox attribute can escape its sandboxing."*

Es cierto técnicamente: combinar ambos permite al iframe quitarse el sandbox
manipulando su `iframe.sandbox` desde el DOM padre. Lo aceptamos **a sabiendas**
porque el sandbox sigue bloqueando vectores que sí podemos negar sin romper
el paquete:

| Capability negada | Ataque que mitiga |
|---|---|
| `allow-top-navigation` | Un paquete malicioso no puede cambiar la URL de la pestaña Moodle (redirect clickjacking). |
| `allow-modals` | No puede mostrar `alert()`/`confirm()`/`prompt()`. |
| `allow-pointer-lock` | No puede capturar el ratón. |
| `allow-orientation-lock` | No puede forzar orientación móvil. |
| `allow-presentation` | No puede invocar la Presentation API. |

La protección "real" frente a XSS cross-component requiere
**subdominio dedicado** (HIPOTESIS arriba) o un futuro `Permissions-Policy`
header restrictivo. Documentado como RIE-001.

## Consecuencias para `mod_exelearning`

Aplicar las 4 mejoras de la sección anterior en este commit. Documentar como
RIE-001 el riesgo de XSS por paquete malicioso en organizaciones que admiten
paquetes de autores no confiables, con mitigación recomendada =
subdominio dedicado.

## [PENDIENTE]

- Implementar bridge xAPI (PREG futura) → reusará la disciplina de `mod_scorm`
  cuando exista el endpoint cliente↔servidor.
- Considerar modo popup como ajuste del profesor en `mod_form.php` (v2).

## Revisión 2026-06-02 — HIPÓTESIS del subdominio cerrada (DEC-0-16)

La investigación de TAREA-012 (DEC-0-16) verifica la HIPÓTESIS de arriba: Moodle core
**NO** ofrece servir pluginfile desde un origen separado (`$CFG->wwwroot` único;
`url.php` lo hardcodea), así que el subdominio dedicado requiere **infraestructura
fuera de core**. Además confirma que el bridge SCORM es **100% same-origin** (el padre
lee `iframe.contentDocument` para el `objectid` de DEC-5-01, el hijo recorre
`window.parent.API`, el teacher-mode hider inyectaba CSS en el `contentDocument` —histórico:
hoy el teacher-mode usa el parámetro core `?exe-teacher`, compatible con opaco, DEC-80-06—), por lo
que quitar `allow-same-origin` u optar por origen opaco **rompe el tracking** salvo que
antes se reescriba el bridge a `postMessage` (patrón H5P). Comparativa y roadmap de
hardening (Tier 1: Permissions-Policy + CSP estricto-con-toggle + quitar
`allow-popups-to-escape-sandbox`; Tier 2: `postMessage` → origen opaco/subdominio; y
TAREA-013: sandboxing JS in-frame) en **DEC-0-16**.
