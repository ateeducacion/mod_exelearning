---
id: DEC-37-01
titulo: "Clasificación funcional del módulo: mantener MOD_ARCHETYPE_ASSIGNMENT + MOD_PURPOSE_ASSESSMENT"
estado: Aceptada
fecha: 2026-06-11
tracking_issue: 37
legacy_id: DEC-0047
agentes:
  - erseco
  - claude-code
fuentes:
  - REPO-004
relacionados:
  - DEC-13-07
  - DEC-0015
  - DEC-0008
herramienta_ia:
  interfaz: claude-code
  modelo: claude-opus-4-8
---

# DEC-37-01: Clasificación funcional del módulo: mantener MOD_ARCHETYPE_ASSIGNMENT + MOD_PURPOSE_ASSESSMENT

## Contexto

El informe técnico comparativo (`mod_exescorm` / `mod_exeweb` / `mod_exelearning`)
plantea como **inferencia de diseño** —no como incumplimiento— que, dado que el
formulario permite **desactivar la calificación** por actividad (`gradeenabled`,
[[DEC-13-07]]) y entonces el módulo "se comporta como un recurso plano", es discutible
que `mod_exelearning` se autoidentifique como `MOD_ARCHETYPE_ASSIGNMENT` y
`MOD_PURPOSE_ASSESSMENT`.

La clasificación vive en `exelearning_supports()` (`lib.php:44`):

```php
case FEATURE_MOD_ARCHETYPE:
    return MOD_ARCHETYPE_ASSIGNMENT;   // lib.php:46-47
...
case FEATURE_GRADE_HAS_GRADE:
    return true;                       // lib.php:58-59
...
case FEATURE_MOD_PURPOSE:
    return MOD_PURPOSE_ASSESSMENT;     // lib.php:66-67
```

Este ADR registra el **análisis de la observación** y la decisión. No se había revisado
explícitamente la taxonomía tras añadir el interruptor `gradeenabled` ([[DEC-13-07]]).

## Problema

¿Es la taxonomía funcional (`archetype` + `purpose`) fiel al comportamiento del módulo
en **todos** los modos, incluido `gradeenabled = 0`? Y si no lo fuera en ese modo,
¿puede —y debe— ajustarse?

## Restricción técnica clave

`exelearning_supports($feature)` recibe **únicamente la constante de feature, nunca la
instancia** (`lib.php:44`). Moodle resuelve `FEATURE_MOD_ARCHETYPE` y
`FEATURE_MOD_PURPOSE` a nivel de **tipo de módulo** —vía
`plugin_supports('mod', 'exelearning', $feature)`— y los usa para el **selector de
actividades** (agrupación por *purpose*), defaults de creación y categorización, **no
por instancia**. En consecuencia, la clasificación **no puede variar según
`gradeenabled`**: cualquier cambio es necesariamente **global** para el tipo de módulo
(REPO-004: semántica `archetype`/`purpose` de Moodle core).

## Opciones consideradas

1. **Mantener `MOD_ARCHETYPE_ASSIGNMENT` + `MOD_PURPOSE_ASSESSMENT` (status quo).**
   - *Ventajas:* el **propósito primario** y la razón de ser del plugin es la
     **evaluación multi-ítem** ([[DEC-0015]], [[DEC-0008]]); el archetype `ASSIGNMENT`
     aporta los defaults correctos (grupos/agrupamientos, completion, ubicación en
     "Actividades" y no en "Recursos") y `FEATURE_GRADE_HAS_GRADE = true` es coherente.
     El modo sin nota es un **caso secundario opcional**, no la identidad del módulo.
   - *Inconvenientes:* con `gradeenabled = 0` el módulo es *de facto* un recurso, y un
     docente podría inferir del *purpose* `ASSESSMENT` que siempre hay nota.

2. **Cambiar a `MOD_ARCHETYPE_RESOURCE` + `MOD_PURPOSE_CONTENT` globalmente.**
   - *Ventajas:* fiel al modo recurso.
   - *Inconvenientes (graves):* rompe la expectativa del **caso principal** (evaluable);
     cambia el selector de actividades, la agrupación, los defaults de completion/grupos
     y cómo Moodle lista el módulo; incoherente con `FEATURE_GRADE_HAS_GRADE = true`;
     impacto sobre instalaciones existentes que lo usan como evaluable.

3. **Variar la clasificación según `gradeenabled` (condicional por instancia).**
   - *Inviable:* `supports()` no recibe la instancia y Moodle cachea las features por
     tipo de módulo; no es implementable sin *hacks* no soportados (REPO-004).

## Evidencia

- `lib.php:44-71` — `exelearning_supports()` es estático; devuelve `ASSIGNMENT`,
  `ASSESSMENT` y `GRADE_HAS_GRADE = true`.
- [[DEC-13-07]] — `gradeenabled` es un interruptor **por actividad** que deshabilita los
  campos de nota/intentos en `mod_form.php` (`disabledIf`), no una propiedad del tipo de
  módulo.
- [[DEC-0015]] — justificación de la multicalificación: la evaluación multi-ítem es la
  razón de ser del plugin.
- REPO-004 — `plugin_supports('mod', …, FEATURE_MOD_ARCHETYPE/FEATURE_MOD_PURPOSE)` se
  resuelve por tipo de módulo y alimenta el *activity chooser*.

## Decisión

**Opción 1 — mantener `MOD_ARCHETYPE_ASSIGNMENT` + `MOD_PURPOSE_ASSESSMENT`.** Sin cambio
de código. Se **documenta** que `gradeenabled = 0` es un **"modo recurso" dentro de un
módulo de archetype evaluable**, no una taxonomía distinta: el propósito declarado
refleja el caso principal y mayoritario del módulo, y la clasificación no puede ni debe
oscilar por instancia.

## Consecuencias

- **Positivas:** la taxonomía sigue siendo fiel al uso principal; sin regresiones en
  selector de actividades, completion, gradebook ni informes; se cierra la observación
  del informe con contexto registrado para no reabrirla sin él.
- **Negativas / coste:** persiste una leve disonancia en el modo `gradeenabled = 0`
  (mitigada por documentación y por que el toggle es explícito y del docente).
- **Cambios que dispara:** ninguno en código. Se refleja en `docs/AUDIT_FOLLOWUP.md`,
  `docs/ARCHITECTURE.md` y `docs/GRADEBOOK.md`. Si en el futuro se quisiera un "modo
  recurso de primera clase", la vía correcta sería una feature/plugin aparte, no una
  clasificación condicional.

## Riesgos

- Expectativa docente de nota cuando `gradeenabled = 0`. Mitigación: documentación
  (`docs/GRADEBOOK.md`) y carácter explícito del interruptor ([[DEC-13-07]]).

## Validación

Sin cambio de código → sin test nuevo. Comprobación cualitativa: el módulo aparece en la
pestaña de actividades evaluables del *activity chooser* y aplica los defaults de
`ASSIGNMENT` (verificable en el Docker de demo). El comportamiento de gradebook ya está
cubierto por la suite existente.

## Seguimiento

- Cierra la observación "clasificación funcional" del informe comparativo (ver
  `docs/AUDIT_FOLLOWUP.md`).
- No abre tareas de implementación.
