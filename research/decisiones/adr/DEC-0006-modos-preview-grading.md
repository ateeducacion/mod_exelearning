---
id: DEC-0006
titulo: "Modos de visualización: preview (test) y grading (calificar)"
estado: Aceptada
fecha: 2026-05-28
agentes:
  - erseco
  - claude-code
fuentes:
  - REPO-004
experimentos: []
herramienta_ia:
  interfaz: claude-code
  modelo: claude-opus-4-7
---

# DEC-0006: Modos de visualización: preview (test) y grading (calificar)

## Contexto

Las actividades calificables de Moodle distinguen entre modo "vista normal"
(la nota cuenta) y modo "preview / browse" (el profesor o el alumno hace una
prueba sin que se afecte el gradebook). `mod_scorm` lo gestiona con
`mode=normal|browse|review` en el URL del player. `mod_assign` tiene un
botón "vista previa". `mod_quiz` tiene un modo "preview" sólo para profesores.

El usuario solicita que `mod_exelearning` incluya esa distinción:
- **Modo test** (preview): el profesor entra a probar la actividad sin que
  la nota cuente.
- **Modo calificar** (grading): el alumno hace la actividad y la nota se
  guarda en gradebook.

## Problema

Definir cómo el plugin distingue ambos modos a nivel de URL, UI, y
endpoint de tracking.

## Opciones consideradas

1. **Param URL `?mode=preview|grading`** (recomendada).
2. Botón explícito "modo prueba" que cambia un flag de sesión.
3. Capability-based: si el usuario tiene `addinstance`, automáticamente está
   en preview a menos que pulse "entrar como alumno".

## Evidencia

- `mod/scorm/player.php` usa `mode=normal|browse|review` con sanitización
  `PARAM_ALPHA` (REPO-004 / AN-009).
- `mod/scorm/locallib.php::scorm_check_mode` ajusta intentos según modo.
- `mod/quiz` usa popup separado para preview (`preview.php?attempt=...`).

## Decisión

Adoptar opción 1. `mod_exelearning` soporta dos modos:

| Modo | URL | Quién | Efecto |
|---|---|---|---|
| `grading` (default) | `view.php?id=N` o `view.php?id=N&mode=grading` | alumno (capability `mod/exelearning:savetrack`) | Las llamadas `LMSSetValue` / `LMSCommit` SÍ se persisten via `track.php`. Se actualiza `mdl_grade_grades`. |
| `preview` | `view.php?id=N&mode=preview` | profesor (capability `moodle/course:manageactivities`) | Las llamadas SCORM responden OK pero `track.php` IGNORA `grade_update` y devuelve `{ok:true, mode:'preview'}`. Permite al profesor probar la actividad sin contaminar el libro de calificaciones. |

### UI

`view.php` muestra una pestaña/banner cuando está en `preview`:
- "Estás en modo prueba. Ningún resultado se guardará en el libro de
  calificaciones."
- Botón "Salir del modo prueba" → `view.php?id=N` (vuelve a grading).

En modo `grading`, si el usuario tiene `moodle/course:manageactivities`, se
muestra un enlace "Probar como alumno" → `view.php?id=N&mode=preview`.

### Endpoint `track.php`

Recibe `mode` adicional. Validaciones:
- `mode=preview`: cualquier capability suficiente para `view`. NO llama a
  `grade_update`. Responde `{ok:true, mode:'preview', rawscore:N}`.
- `mode=grading` (default): require `mod/exelearning:savetrack`. Llama a
  `grade_update` como hoy.

### Compatibilidad con xAPI futuro

Cuando se implemente el bridge xAPI, el statement contendrá
`context.contextActivities.category` con un IRI que marque preview vs
grading (siguiendo perfil cmi5):

```json
"category": [
  { "id": "https://exelearning.net/xapi/category/preview" }
]
```

El handler ignorará statements con esa categoría a efectos de gradebook.

## Consecuencias

Positivas:
- Profesores pueden auto-evaluar la actividad antes de habilitarla.
- Patrón familiar para Moodle (`?mode=preview`).
- No requiere capability nueva — reutiliza `moodle/course:manageactivities`.

Negativas:
- Hay que añadir lógica en `view.php` (banner) + `track.php` (skip
  grade_update).
- Si el alumno descubre la URL `?mode=preview`, ¿debería poder usarla?
  → NO: en `track.php` validamos que el usuario tenga capability de
  gestión antes de aceptar `mode=preview`.

## Riesgos

- RIE-003: un alumno con capability incorrecta accede a preview y "pierde
  notas históricas". Mitigación: `track.php` verifica
  `moodle/course:manageactivities` antes de aceptar `mode=preview`; si no
  tiene capability, fuerza `mode=grading`.

## Validación

- Profesor accede a `view.php?id=N&mode=preview` → banner visible,
  envía LMSCommit, nada se guarda en `mdl_grade_grades`.
- Alumno accede a `view.php?id=N&mode=preview` → `mode` se reinterpreta como
  `grading` (sin capability suficiente). La nota SÍ se guarda.
- Profesor sale del modo prueba → vuelve a flujo normal.

## Seguimiento

- TAREA-019 (esta sesión): implementar `?mode=preview` en view.php +
  track.php.
- TAREA-020 (futura, junto con bridge xAPI): aplicar la categoría xAPI
  para preview en statements.

## Actualización (2026-05-29): toggle ocultable `teachermodevisible`

Paridad con `mod_exeweb`: se añade el campo de instancia `teachermodevisible`
(advcheckbox en `mod_form`, default 1, sección "Appearance") que permite al
profesor **ocultar el toggle "Try as a student (preview)"** en la vista de la
actividad. Importante: es sólo presentación — la capability
`moodle/course:manageactivities` sigue controlando el acceso real al modo
preview, así que un alumno NUNCA entra en preview aunque el toggle esté oculto,
y si `teachermodevisible=0` y la URL trae `?mode=preview`, `view.php` fuerza
`grading`. Campo en `db/install.xml` + upgrade savepoint 2026052806. Verificado
en navegador: con 0 desaparece el toggle (resto —editor, items, informe—
intacto); con 1 reaparece.
