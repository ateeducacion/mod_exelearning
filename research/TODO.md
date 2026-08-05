# TODO inmediato

Lista humana de pendientes documentales. La fuente de verdad operativa sigue
siendo `status.yaml` + `tareas/backlog/`.

## Prioridad Alta

(sin pendientes)

## Prioridad Media

- [ ] TAREA-016 / DEC-18-01: implementar el reemplazo visible del paquete +
      origen por URL con sincronización (patrón mod_scorm: selector
      `packagesource` + columna `reference` + `create_file_from_url` + gating por
      `contenthash` + `curl_security_helper` + ajuste admin `allowexternalurl`
      opt-in) + botón "Actualizar ahora" (Fase 1). **Fase 2 DESCARTADA por ahora**
      (DEC-18-01 §Resolución de alcance, 2026-06-17): sin `updatefreq`/`db/tasks.php`/
      token REST eXe v4. El reemplazo YA está soportado por `update_instance`; falta hacerlo descubrible.
- [ ] TAREA-015 / DEC-17-01: implementar la ingesta xAPI dual (listener AMD +
      endpoint + normalizador) reutilizando la tubería existente, sin romper el
      shim SCORM 1.2. Gated a que el PR upstream #1867 congele el contrato.
      **Diseño del endpoint DECIDIDO 2026-06-17** (DEC-0063 §Resoluciones: `scaled∉[0,1]`→rechazo 400,
      overall recalculado server-side, `registration`/`sessiontoken` conviven, endpoint custom + `core_xapi` opcional).
- [ ] Cumplimiento: solo **accesibilidad** pendiente (pasada `axe-core`; known-gap post-STABLE, no
      bloqueante). Licencias y privacidad: vigentes (revisadas 2026-06-17, ver `cumplimiento/`).
- [x] TAREA-013 / RIE-001: **DECIDIDO 2026-06-17** — RIE-001 **aceptado** (media/baja, mitigación v1
      sandbox); el hardening (DEC-0019: Permissions-Policy, CSP estricto, origen opaco) lo implementa la rama
      `feature/secure-iframe-scorm-bridge`, no como bloqueante. No se abre ficha de investigación separada.

## Cerrado

- [x] issue 73 / DEC-77-01 / RIE-019: extracción de paquete no-destructiva
      (stage→validate→swap) COMPLETADA (2026-06-13). `extract_stored` borra solo
      `content/{revision}` + rollback de la revisión parcial; reemplazo estacionado y
      validado antes de mover el puntero de BD y podar la revisión anterior; editor de
      irrecuperable a recuperable. Promueve `maturity` BETA→STABLE. 263 tests verdes.
- [x] TAREA-012 / RIE-001: investigación de aislamiento del `.elpx` COMPLETADA
      (DEC-0019, 2026-06-02). Core no aísla (mod_scorm sin sandbox; core_h5p curado);
      mod_exelearning ya es el mejor aislado de los tres; no hay origen separado en core
      (requiere infra). Roadmap de hardening documentado (NO implementado por decisión):
      Tier 1 (M2 Permissions-Policy + M3 CSP estricto-con-toggle + M1) → Tier 2 (M6
      postMessage bridge → M7 origen opaco/subdominio). Implementación = trabajo futuro.
- [x] TAREA-009 / RIE-011: TOCTOU de `maxattempt` ACEPTADO por paridad con core
      (ni mod_scorm ni mod_h5pactivity lo protegen; el UNIQUE ya está presente).
      Lock `\core\lock` confinado a `!sessionknown` queda como mitigación futura
      opcional. Ver DEC-6-01 (revisión 2026-06-01). Riesgo baja/baja.
- [x] Fase 0: estructura, plantillas, schemas, índices, dashboard y diario.
- [x] TAREA-002: Context7 completado en FTE-001..FTE-008.
- [x] TAREA-003: EXP-001 completado.
- [x] TAREA-004: EXP-002 completado.
- [x] TAREA-005: DEC-0003 aceptada y matriz cuantificada.
- [x] TAREA-006: CI matriz Moodle/PHP/DB documentada e implementada.
- [x] TAREA-007: DEC-0015 justifica la multicalificación.
- [x] TAREA-008 / RIE-010: aplicado guard de origen al puente legacy
      `postMessage`; `amd/build/` regenerado con `grunt amd` en Moodle 5.2beta.
- [x] TAREA-010: el ZIP del editor descargado desde GitHub se verifica contra
      el digest SHA-256 publicado por GitHub Releases API.
- [x] TAREA-011: e2e real por navegador completado: `completionpassgrade`,
      `grademodel` peritem/overall, libro, `maxattempt`, `reviewmode` y puente
      iframe/SCORM verificados con Chrome + Docker.
