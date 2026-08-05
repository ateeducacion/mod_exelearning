---
id: DEC-72-01
titulo: "Auditoría follow-up post-refactor (#71): 8 mejoras implementadas + 1 hallazgo descartado por decisión previa"
estado: Aceptada
fecha: 2026-06-12
tracking_issue: 72
legacy_id: DEC-0055
agentes:
  - erseco
  - claude-code
fuentes:
  - REPO-004
relacionados:
  - DEC-71-01
  - DEC-4-01
  - DEC-0019
  - DEC-6-01
  - DEC-13-12
  - DEC-13-11
herramienta_ia:
  interfaz: claude-code
  modelo: claude-opus-4-8
---

# DEC-72-01: Auditoría follow-up post-refactor (#71): 8 mejoras implementadas + 1 hallazgo descartado por decisión previa

## Contexto

Tras fusionar a `main` los cuatro PRs de la auditoría comparativa (DEC-68-01, DEC-69-01, DEC-70-01 y DEC-71-01, en especial el
refactor de `lib.php` [[DEC-71-01]]), una revisión de seguimiento produjo `plan.md` con 11 hallazgos
(P1–P3): seguridad de extracción, concurrencia, superficie del editor embebido y deriva documental
post-refactor. Esta ADR registra qué se implementó, qué se descartó y por qué, para que el `plan.md`
(efímero) pueda borrarse conservando el *qué y el porqué* en `research/`.

## Decisión

Implementar **8 mejoras** en un PR, con tests (TDD donde es unit-testable) y phpcs 0/0. Descartar 1
hallazgo por contradecir una decisión ya aceptada y tratar 2 como limpieza menor.

| # | Mejora | Pri | Evidencia → cambio |
|---|--------|-----|--------------------|
| 1 | Validar el nombre de entrada `.elpx` al migrar desde `mod_exescorm` | P1 | `migration/source/exescorm_source.php`: `classify()`/`resolve_elpx()` filtran con `zip_utils::is_unsafe_zip_entry()` + barrido `assert_extraction_contained()` post-extracción. Una `.elpx` hostil degrada a `nosource()`. |
| 3 | Centralizar la comprobación de extracción servible | P1 | `package_manager::extract_stored()` lanza `migrateextractfailed` si no hay `index.html`; se elimina el guard duplicado de `migration_service`. Ver RIE-019. |
| 4 | Lock atómico en el instalador del editor | P2 | `embedded_editor_installer`: `get_config/set_config` → `\core\lock\lock_config` (patrón de `track.php`). El timestamp `embedded_editor_installing` se conserva como **marcador de progreso cross-request** que lee `manage_embedded_editor::get_status()`. |
| 5 | Quitar `Service-Worker-Allowed: /` | P2 | `editor/static.php`: cabecera eliminada. `editor/index.php` ya impide registrar `preview-sw.js`, así que el scope raíz era superficie inútil. Documentado en `docs/EMBEDDED_EDITOR.md`. |
| 6 | Contención de rutas estricta y compartida | P2 | Nuevo `\mod_exelearning\local\editor_paths::is_within()` (`=== root` o `root + DIRECTORY_SEPARATOR`), usado por `editor/static.php` y `editor/styles.php`; unit-test cubre el caso prefijo-hermano. |
| 7 | Refs de docs post-refactor | P3 | ~27 refs frágiles `lib.php:NNNN` → nombres `\Clase::método()` en GRADEBOOK/TRACKING/ELPX_PACKAGE/scorm-shim/xapi-integration/PRIVACY_BACKUP_FILES (mapa de [[DEC-71-01]]). |
| 8 | `research/cumplimiento/privacidad.md` a estado real | P3 | De "hipótesis" a hecho presente: campos reales de `exelearning_attempt`, export/borrado implementados, xAPI como hoja de ruta. Cita `classes/privacy/provider.php`. |
| 10 | Docblock contradictorio de importación de estilos | P3 | `styles_service::is_import_blocked()`: el docblock decía "default true (bloqueado)" pero el código (y su test) devuelven `false` (permitido). Corregido el docblock. |

## Hallazgos descartados o minimizados

- **#2 — track.php: error en vez de proceder sin lock al expirar. DESCARTADO.** Contradice una decisión
  YA ACEPTADA: [[DEC-6-01]] (revisión 2026-06-01) y **RIE-011 ACEPTADO** documentan que degradar sin lock
  al expirar es deliberado — paridad con core (ni `mod_scorm` ni `mod_h5pactivity` protegen el TOCTOU),
  el índice UNIQUE `(exelearningid,userid,attempt,itemnumber)` es el respaldo, y el objetivo explícito es
  "nunca bloquear un commit legítimo". El propio comentario de `track.php` lo dice. Cambiarlo a erroring-out
  revertiría esa decisión del mantenedor; no se toca.
- **#9 — `licencias.md`: limpieza menor.** Reorganizado en RESUELTO (editor AGPL-3.0 vía `scripts/package.sh`;
  pipwerks MIT en `thirdpartylibs.xml`) / NO APLICA (JS de paquetes = contenido de usuario) / PENDIENTE-REAL
  (auditoría formal de patrones exescorm/exeweb/wp-exelearning, baja prioridad). No era un bug.
- **#11 — `composer lint` DX.** `phpcs --standard=moodle .` ignora los `exclude-pattern` de `.phpcs.xml.dist`,
  así que lintaba `research/experimentos/...`. Se añade `--ignore` (espejo de los excludes) a `lint`/`fix`
  manteniendo `--standard=moodle` (paridad con CI). No cambia código de producción.

## Consecuencias

- Positivas: superficie de seguridad/concurrencia reducida (zip-slip en migración, lock atómico, SW,
  contención de rutas); fallo ruidoso ante paquete corrupto en TODAS las vías de extracción; documentación
  alineada con el código tras [[DEC-71-01]]; DX de lint coherente con CI.
- Coste: ninguno funcional negativo esperado. Ver RIE-019 (el guard central ahora también aplica a
  view.php/editor/save.php).

## Riesgos

- RIE-019: el guard de extracción centralizado (#3) ahora lanza también en el self-heal de `view.php` y en
  `editor/save.php`, no solo en migración. MITIGADO: es el "fallar ruidosamente ante paquete corrupto"
  buscado; todos los fixtures (`actividad-evaluable.elpx`, `multipage-gradable.elpx`, `superelpx.elpx`, y el
  zip inline de `lib_test.php`) contienen `index.html`, así que ningún paquete válido lo dispara. Cubierto
  por `tests/local/package_manager_extract_test.php`.

## Validación

- `php -l` y `phpcs --standard=moodle` 0/0 sobre todos los PHP tocados. PHPUnit corre en CI (no local, ver
  [[project-mod-exelearning-phpunit-local]]); gate Codecov patch ([[DEC-66-01]]).
- Tests nuevos: `exescorm_source_security_test` (el guard `is_unsafe_zip_entry` marca `../`/`/abs`/`\\`/
  `file://`, y un `.elpx` seguro resuelve), `package_manager_extract_test` (paquete sin `index.html`
  lanza), `editor_paths_test` (prefijo-hermano y directorio padre denegados).
- NOTA de alcance (descubierta en CI): el filtrado a nivel de `classify()` (#1) es **defensa en
  profundidad** — `zip_packer::list_files()` de Moodle **ya normaliza** los nombres de entrada (un
  `../evil.elpx` llega saneado), así que la garantía vinculante es el barrido post-extracción
  `assert_extraction_contained()` en `resolve_elpx()`. Y la contención de lock entre peticiones (#4) **no
  es unit-testable**: los lock factories nativos de BD (MySQL `GET_LOCK`, PG advisory) son reentrantes por
  sesión, así que un segundo `get_lock` en el mismo proceso no falla; se confía en la semántica testada de
  `\core\lock`.

## Seguimiento

- Cierra el `plan.md` de seguimiento (borrado). Único pendiente real: auditoría formal de licencias de
  patrones hermanos (baja prioridad, #9). #2 queda como mitigación futura opcional según RIE-011.
