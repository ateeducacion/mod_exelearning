---
id: AN-010
titulo: "Desglose honesto de la complejidad de mod_exelearning (¿es 'alta'? no: media y acotada)"
fecha: 2026-05-29
fuentes:
  - REPO-001
  - REPO-004
  - FTE-006
  - FTE-007
  - FTE-008
relacionados:
  - DEC-0-03
  - DEC-0-07
  - DEC-0-08
  - DEC-0-15
  - AN-002
  - AN-003
herramienta_ia:
  interfaz: claude-code
  modelo: claude-opus-4-8
---

## Motivo

DEC-0-15 etiquetó la complejidad de `mod_exelearning` como "alta". Revisión
(erseco): suena alarmista. Esta nota desglosa qué es realmente "complejo" y
corrige la etiqueta a **media, acotada y ya domada**.

## Qué NO es complejidad propia (es el estándar de Moodle)

- **Tabla de intentos propia (`exelearning_attempt`)**: NO es coste extra. El core
  hace lo mismo: `mod_scorm` tiene `scorm_attempt`, `scorm_aicc_session`,
  `scorm_scoes_value`; `mod_h5pactivity` tiene `h5pactivity_attempts` +
  `h5pactivity_attempts_results`. Nuestra tabla **calca** los campos de
  `h5pactivity_attempts` (userid, attempt, rawscore, maxscore, scaled, status,
  timecreated/timemodified). Es el patrón esperado, no una invención.
- **Multi grade items (`itemnumber > 0`)**: es un patrón **documentado del core**
  (FTE-006), usado por `mod_workshop` (submission + assessment) — AN-002. No es
  territorio inexplorado.
- **iframe + sidebar**: heredado tal cual de `mod_exeweb` (AN-001/AN-008). Probado.
- **Finalización por nota**: es `completionpassgrade` del core (DEC-0-10), no lógica
  custom.

## Dónde está la complejidad REAL (3 puntos acotados)

1. **Parseo de `content.xml` + detección de iDevices calificables** (`package.php`).
   Heurística sobre el formato de eXeLearning; el riesgo es que el formato cambie.
   Acotado: un parser con fallback, fixtures reales y `GRADABLE_IDEVICE_TYPES`.
2. **Puente SCORM 1.2 (shim)**: existe **porque eXeLearning no emite xAPI** hoy
   (FTE-007). Es la pieza con más "magia" (inyección de pipwerks, shim `window.API`,
   parseo de `cmi.suspend_data` por iDevice). Acotada a `view.php` + `track.php`.
3. **Estabilidad de `objectid` entre subidas/ediciones (RIE-006)**: depende de
   upstream; mitigado y documentado.

Todo lo demás (sync idempotente, self-heal, backup/restore, privacy) es código
mecánico y está cubierto por tests + CI verde.

## Veredicto de complejidad

- **Etiqueta correcta: media** (no alta). La complejidad está **concentrada en 2-3
  módulos bien aislados** (`package.php`, `view.php`/`track.php`), todos
  implementados, verificados (Docker + navegador) y en CI (4.5/5.0/5.1).
- La parte "frágil" no es el volumen de código sino la **dependencia de artefactos
  externos** (formato de export e ids de eXeLearning). Eso es *riesgo de
  acoplamiento*, no *complejidad de implementación*.
- La única pieza que añade complejidad "no estándar" es el **shim SCORM 1.2**, y es
  un mal necesario hasta que eXeLearning emita xAPI (DEC-0-14).

→ DEC-0-15 actualizada: fila "complejidad" pasa de **alta** a **media (acotada)**.
