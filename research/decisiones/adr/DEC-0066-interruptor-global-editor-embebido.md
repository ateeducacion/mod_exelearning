---
id: DEC-0066
titulo: "Interruptor global del editor embebido: modo reproductor puro vía ajuste de sitio"
estado: Aceptada
fecha: 2026-07-24
agentes:
  - erseco
  - claude-code
fuentes:
  - REPO-002
  - REPO-004
relacionados:
  - DEC-0009
  - DEC-0065
  - DEC-0024
herramienta_ia:
  interfaz: claude-code
  modelo: claude-fable-5
---

## Contexto

Hay sitios que quieren usar `mod_exelearning` como **reproductor puro**: los docentes suben
paquetes `.elpx` producidos fuera (eXeLearning de escritorio, repositorios institucionales)
y el sitio no quiere ofrecer la edición en Moodle — por política editorial, por flujo de
trabajo (el contenido se autoriza fuera) o por simplicidad de soporte. Hoy el botón
"Editar con eXeLearning" aparece para cualquiera con `moodle/course:manageactivities`
siempre que el bundle del editor sea válido ([[DEC-0065]]); no existe forma soportada de
apagarlo sin mutilar el paquete.

Se valoraron dos mecanismos:

1. **Ajuste global de sitio** (checkbox en la página de ajustes del plugin).
2. **Capability nueva** (`mod/exelearning:useeditor`) concedida por defecto a
   editingteacher/manager — un "perfil mínimo de edición".

## Decisión

**Ajuste global `exelearning/editordisabled`** (checkbox negativo, desmarcado por defecto = edición activa), por ser lo
más sencillo y lo más parecido a cómo los plugins de actividad de Moodle apagan
funcionalidades completas de sitio (los ajustes `scorm_*` de mod_scorm, los toggles de
características en admin settings). La capability se descarta como mecanismo primario: el
"quién" ya lo gobierna `moodle/course:manageactivities` (paridad con el resto del plugin),
y una capability nueva obligaría a editar roles para lograr el caso de uso real, que es
binario y de sitio ("aquí no se edita"), no por-rol.

Alcance del interruptor:

- **Apagado**: el botón de edición no se muestra (`view.php` vía
  `exelearning_embedded_editor_enabled()`, que ahora combina ajuste + validez del bundle);
  los endpoints del editor **rechazan** peticiones directas — `editor/index.php` con página
  de error explicativa y `editor/save.php` con `exelearning_require_embedded_editor_enabled()`
  (ocultar el botón no es un control de acceso); `editor/static.php` deja de servir assets
  (ya pasaba por el helper). La subida y reproducción de `.elpx` no cambian.
- **Encendido** (default): comportamiento actual. El checkbox es **negativo a propósito**
  ("Desactivar el editor integrado", patrón de `stylesblockimport`): así config ausente y
  casilla desmarcada significan lo mismo (edición activa) y se evita la confusión de un
  default positivo que se muestra desmarcado hasta que `upgradesettings` lo materializa
  (observada en la revisión del PR).

## Consecuencias

- Positivas: caso "reproductor puro" soportado con un clic; superficie del editor
  desactivable de golpe (bootstrap, guardado y assets); sin cambios de roles ni upgrade de
  BD (es un config, no una columna).
- Negativas (asumidas): no permite granularidad por rol o por curso; si mañana hiciera
  falta, la capability del punto 2 puede añadirse *encima* del interruptor sin romper nada
  (el ajuste seguiría siendo el interruptor maestro).
- El CTA de "crear desde cero" ([[DEC-0024]]) degrada solo: con el editor apagado el
  docente ve el aviso de "sube un paquete" en lugar del de "créalo con el editor".

## Alternativas consideradas

| Alternativa | Por qué se rechaza |
|---|---|
| Capability `mod/exelearning:useeditor` | Resuelve un problema distinto (quién, no si); exige editar roles para el caso real; más superficie de configuración. Puede añadirse después si aparece demanda por-rol. |
| Ajuste por actividad | Dispersa la política editorial en N actividades; el caso de uso es de sitio. |
| No hacer nada (quitar el bundle del paquete) | Rompe [[DEC-0065]] (el empaquetado exige el editor) y castiga a los sitios que sí editan. |
