---
id: DEC-0070
titulo: "Teacher-mode por parámetro core `?exe-teacher` (sin inyección CSS): compatible con origen opaco; supersede el hider de DEC-0046"
estado: Aceptada
fecha: 2026-06-28
agentes:
  - erseco
  - claude-code
fuentes:
  - REPO-004
relacionados:
  - DEC-0046
  - DEC-0059
  - DEC-0045
  - DEC-0071
herramienta_ia:
  interfaz: claude-code
  modelo: claude-opus-4-8
---

## Contexto

El export de eXeLearning pinta un **conmutador de "modo profesor"** (`#teacher-mode-toggler-wrapper`)
que revela las capas de contenido reservadas al profesorado (soluciones, retroalimentación). En la
vista embebida de Moodle ese conmutador debía gobernarse desde el plugin.

La solución original ([[DEC-0046]] #2, `exelearning_require_teacher_mode_hider()`) **inyectaba un
`<style>` en el `contentDocument` del iframe** para ocultar/mostrar el conmutador. Eso exigía acceso
**same-origin** al documento del paquete (servido por `pluginfile.php`). Con el modo seguro
([[DEC-0059]] + [[DEC-0060]]) el contenido pasa a **origen opaco** (`sandbox` sin `allow-same-origin`):
el padre **no puede** leer ni escribir el `contentDocument` (lanza `SecurityError`), de modo que la
inyección CSS del hider es **estructuralmente incompatible con el modo opaco**. Mientras el teacher-mode
dependiera de inyección, el modo opaco no podía cubrir este caso → era un bloqueante para continuar el
trabajo del origen opaco.

## Decisión

**Adoptar el parámetro nativo `?exe-teacher=1` que expone eXeLearning core** (upstream
`exelearning#1772`): el export oculta el contenido de profesor por defecto y ofrece un selector para
mostrarlo cuando la URL lleva `exe-teacher=1`. El plugin **deja de mutar el paquete** y se limita a
**añadir el parámetro al `src` del iframe** (`view.php:274-285`):

```php
// El parámetro viaja en el src del iframe; el paquete lee su propio location.search
// incluso bajo origen opaco, así que NO hace falta inyección CSS del host.
if (!empty($exelearning->teachermodevisible)) {
    $iframeurl->param('exe-teacher', '1');
}
```

Claves de la decisión:

1. **Compatible con origen opaco.** El parámetro viaja **en el `src`** y el paquete lee su **propia**
   `location.search` desde dentro del iframe — no requiere que el host toque el `contentDocument`.
   Funciona igual en **legacy (same-origin) y en secure (opaco)** (`view.php:283`, `view.php:623`).
2. **El plugin no inyecta nada.** Se elimina `exelearning_require_teacher_mode_hider()` (antes
   `lib.php:880`, encolada en `view.php:586`). El paquete servido queda **prístino**, reduciendo el
   acoplamiento a internals de eXe que el informe técnico marcaba como deuda nº1 ([[DEC-0046]]).
3. **Gobernado por `teachermodevisible`** (ajuste por actividad): cuando está activo, el selector se
   ofrece a **todo visualizador** (sin role gate; paridad con la semántica de `mod_exeweb`).
4. **Es el "arreglo upstream" que [[DEC-0046]] anticipaba** para el hider (#2): la opción "flag de
   export modo embebido" se materializó como un parámetro de URL en eXe core, mejor aún que un flag de
   build porque funciona sobre **cualquier** `.elpx` que lo soporte sin reempaquetar.

Implementado y mergeado (PR 86, commit `4c33e8a`: "reveal via core `?exe-teacher` URL param, drop CSS
injection"). Este ADR registra la decisión ya integrada.

## Consecuencias

- **Supersede la parte de teacher-mode (#2) de [[DEC-0046]]**: la inyección CSS del hider es ya
  **histórica**. La parte de **SCORM-loader (#1)** de DEC-0046 no se ve afectada por esta entrada
  (su salida definitiva sigue siendo xAPI / [[DEC-0045]] según corresponda).
- **Desbloquea el modo opaco**: el teacher-mode deja de ser un caso que sólo funcionaba en same-origin.
  Con esto resuelto, se puede continuar el trabajo de medios externos en opaco ([[DEC-0071]]).
- **Menos deuda/acoplamiento**: el plugin ya no reescribe ni manipula el documento del paquete para el
  teacher-mode; un cambio en cómo eXe pinta el conmutador ya no rompe al plugin.
- **Dependencia de versión de eXe**: los `.elpx` exportados con versiones **anteriores** a
  `exelearning#1772` no honran el parámetro; en ellos el conmutador se comporta como en el export base
  (sin ocultar). Es una **degradación grácil** (no rompe la vista), no un fallo. No se conserva el hider
  para esos paquetes (decisión: no reintroducir inyección incompatible con opaco).

## Validación

- **Código**: `view.php:274-285` añade `exe-teacher=1` al `src` sólo si `teachermodevisible`; el
  comentario in-situ documenta que funciona bajo origen opaco porque el paquete lee su `location.search`.
  `grep` confirma que `exelearning_require_teacher_mode_hider`/`require_teacher_mode` **ya no existen**
  en el árbol (eliminadas en PR 86).
- **Ajuste**: `teachermodevisible` persiste en `lib.php` (`add`/`update`, defaults a 0).
- **Seguimiento**: la verificación en navegador del selector en **secure** (que el paquete muestre las
  capas de profesor con el parámetro y las oculte sin él, sin tocar el `contentDocument`) se ancla en la
  campaña de evidencia del modo seguro; enlazar el JSON de evidencia cuando se ejecute.

## Seguimiento

- Si en el futuro se exige una versión mínima de eXe ([[DEC-0030]]), documentar aquí el umbral a partir
  del cual `exe-teacher` está garantizado.
- Paridad con `wp-exelearning` / `omeka-s-exelearning`: si esos embebedores aún ocultan el conmutador por
  CSS, migrarlos al mismo parámetro (mismo motivo: compatibilidad con opaco).
