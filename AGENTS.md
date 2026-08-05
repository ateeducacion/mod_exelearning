# AGENTS.md (raíz)

Repositorio del plugin **`mod_exelearning`** (Moodle 4.5 LTS → 5.x). Fase actual:
**plugin funcional end-to-end** — instala, extrae `.elpx`, sirve con sidebar nativa,
guarda calificaciones en gradebook vía SCORM 1.2 bridge → `track.php` → `grade_update`
multi-itemnumber. Demo Docker + Moodle Playground operativos.

Reglas operativas de investigación: [`research/AGENTS.md`](./research/AGENTS.md)
(append-only, evidencia citable, español, no vendorar externos).

## Estado actual (plugin `MATURITY_STABLE` — actualizado 2026-06-17)

> **[ACTUALIZACION 2026-06-17]** El plugin es **STABLE** (DEC-77-01, PR 77). El "snapshot" original era de
> 2026-05-28; el detalle de abajo es **histórico** (se mantiene por trazabilidad). El estado canónico vivo es
> el **código** + `docs/ARCHITECTURE.md` + el índice completo de decisiones `research/docs/indices/adrs.yaml`
> (64 ADRs, generado; `make architecture-records` lo imprime). La tabla "Decisiones clave" de más abajo es un
> **resumen curado**, no el índice: detalla hasta las decisiones del issue #13 y agrupa el resto en una fila.

### Hecho
- Esqueleto plugin + multi-grade-items (`classes/grades/gradeitems.php`, MAX=100).
- Parser `content.xml` (`classes/local/package.php`, `GRADABLE_IDEVICE_TYPES` ×20).
- Bridge SCORM 1.2: `view.php` shim + `exelearning_inject_scorm_loader` (pipwerks
  auto-init en `<head>`) + `track.php` con parseo de `cmi.suspend_data`
  (regex `^(\d+)\. "([^"]*)"; [^:]+: ([\d.]+)%; [^:]+: ([\d.]+)%`).
- Modos preview/grading (DEC-0-06, verificado).
- **Intentos (DEC-0-07, Aceptada)**: tabla `exelearning_attempt` + agregación
  `grademethod` (highest/average/first/last/lowest) en `classes/local/attempts.php`
  + `report.php` + privacy provider + backup/restore. Agrupación por
  `sessiontoken` (1 intento por carga de página). Verificado en Docker.
- **Finalización estilo SCORM (DEC-0-10, Aceptada)**: `gradepass` + condición
  core `completionpassgrade` ("aprobar para completar"). `track.php` refuerza
  `completion->update_state` tras grabar nota.
- **Self-heal de subidas programáticas**: `view.php` re-extrae el paquete y
  re-detecta iDevices si faltan (arregla el `addModule` del Playground, que no
  pasa por `exelearning_add_instance`). `exelearning_extract_stored_package()`
  separada para reusarla sin draft. Verificado.
- Editor embebido portado de `mod_exeweb` (instalador GitHub + external API).
- Demo seeder idempotente (`scripts/setup_demo.php`) con 3 actividades
  evaluables (exelearning + `mod_scorm` + `mod_h5pactivity`, todas con
  finalización por aprobado) + `blueprint.json` (Playground). Verificado.
- Docker compose `erseco/alpine-moodle:v5.0.7` + MariaDB.
- Icono `pix/monologo.svg` (X sin hamburguesa).
- README estilo `mod_exeweb`, dependabot, composer.json.

### Pendiente (orden sugerido)
1. **TAREA-016 / DEC-18-01** _(Propuesta → impl)_: reemplazo visible del paquete + origen
   por URL con sincronización (patrón `mod_scorm`: `packagesource` + columna `reference` +
   `create_file_from_url` + gating por `contenthash` + `curl_security_helper` + admin
   `allowexternalurl` opt-in) + botón "Actualizar ahora" (Fase 1); `updatefreq` + `db/tasks.php`
   + token REST eXe v4 (Fase 2 opcional). El reemplazo YA funciona vía `update_instance`.
2. **TAREA-015 / DEC-17-01** _(Propuesta → impl)_: ingesta xAPI dual (listener AMD + endpoint +
   normalizador) reutilizando la tubería existente; gated a que el PR upstream #1867 congele el contrato.
3. **Auditorías de cumplimiento**: licencias, privacidad y accesibilidad.
4. **TAREA-013 / RIE-001 (M8)**: investigar sandboxing de JS en cliente (ShadowRealm, SES/
   Compartments, Web Worker + DOM proxy, QuickJS-WASM, librerías tipo `sandboxjs`) como
   mitigación que mantiene el servido same-origin. Ver DEC-0-16 (M8).
5. _(Futuro, documentado, sin priorizar)_ **RIE-001** hardening del `.elpx`: roadmap en
   DEC-0-16 — Tier 1 (Permissions-Policy + CSP estricto-con-toggle + quitar
   `allow-popups-to-escape-sandbox`) → Tier 2 (bridge `postMessage` → origen opaco/subdominio).

Cerradas: **TAREA-012 / RIE-001** investigación (DEC-0-16); **TAREA-009 / RIE-011**
`maxattempt` aceptado por paridad con core (DEC-6-01, commit `f6e8ec8`).

### Hecho en sesión 2026-05-28 (tarde-noche, claude-opus-4-8)
- DEC-0-08 `grademodel` (selector peritem [default] / overall; modo both eliminado en rev. 2026-05-29).
- DEC-0-07 fase 2: `maxattempt` + `reviewmode` + borrar intento en `report.php` (cap
  `mod/exelearning:deleteattempt`) + recálculo `exelearning_recalculate_user_grades`.
- Editor embebido **inline en settings** + **estilos definidos** portados de
  `exelearning/mod_exeweb` (DEC-0-09: sin modo online). Página rota eliminada.
- CI `ci.yml` con matriz moodle-plugin-ci (DEC-0-04).

### Hecho en sesión 2026-06-03 (issue #13 PR núcleo, claude-opus-4-8)
- **Detección por `isScorm`** (DEC-13-01): `package.php` detecta calificables por el flag
  `isScorm>0` del iDevice (no por lista de tipos) → resuelve issue #13 #2 (solo marcados) y
  #5 (10 tipos nuevos) a la vez. `track.php` sin cambios (enruta por `objectid`).
- **Crear desde cero** (DEC-13-03): `package` opcional en `mod_form.php`; `view.php` muestra CTA
  de edición en vez de error; el editor embebido crea proyecto nuevo (issue #13 #1).
- **Deep-link gradebook** (DEC-13-02): nuevo `grade.php` mapea `itemnumber→objectid` y
  redirige a `view.php?idevice=…` (ancla nativa); helper `exelearning_grade_item_view_url()`
  (issue #13 #4).
- **UI** (DEC-13-03): botón "Editar con eXe" a la derecha + botón pantalla completa;
  `amd/src/fullscreen.js` reescrito (ES6, Fullscreen API sobre el iframe) (issue #13 #6).
- **Migración masiva** (DEC-13-05, reescribe DEC-13-04; PR stacked): herramienta en Ajustes
  (`admin/migrate.php`, cap `mod/exelearning:migrate`) que copia TODAS las actividades
  `mod_exeweb`/`mod_exescorm` del sitio a nuevas actividades eXeLearning (`add_moduleinfo`),
  con barra de progreso y avisos; no destructiva; exescorm→calificación general; idempotente
  (tabla `exelearning_migration`). Motor `import_service::import_package` reutilizado de
  DEC-13-04 (issue #13 #3). Completa el issue #13.

### Hecho en sesión 2026-06-04 (ADRs documentales, claude-opus-4-8)
- **Ingesta dual SCORM 1.2 + xAPI** (DEC-17-01, Propuesta): PR1 documental; xAPI ingiere
  reutilizando la tubería existente (`exelearning_attempt` + `objectid→itemnumber`).
  Implementación → TAREA-015.
- **Actualización de contenido** (DEC-18-01, Propuesta): el **reemplazo** del `.elpx` YA está
  soportado por `exelearning_update_instance` (`revision++`, re-extrae, re-sync, aviso de notas
  obsoletas DEC-12-01). Para **origen por URL** se descarta el file picker URL de Moodle
  (`repository_url` se oculta para `.zip/.elpx` y haría copia única sin sync) y se adopta el
  patrón `mod_scorm` (`packagesource` + `reference` + `create_file_from_url` + `updatefreq`),
  añadiendo un botón "Actualizar ahora" (lo que a `mod_scorm` le falta). eXe v4 no tiene
  permalink público (export REST con Bearer JWT, sin versionado). Implementación → TAREA-016.

### Hecho en sesión 2026-06-04 (categoría + visibilidad notas, claude-opus-4-8)
- **Categoría de calificación** (DEC-19-01, Aceptada): columna `exelearning.gradecat` +
  selector estándar (`gradecategoryonmodform` + `grade_get_categories_menu`) aplicado a
  TODOS los grade items vía `grade_item::set_parent` (`grade_update` ignora `categoryid`,
  FTE-012) en `exelearning_apply_grade_category`. Petición usabilidad INTEF #1.
- **Visibilidad de notas del alumno** (DEC-19-02, Aceptada): en `peritem` el overall oculto
  seguía agregando → Moodle vaciaba el total del alumno (default
  `grade_report_user_showtotalsifcontainhidden=0`). Fix: excluir la nota overall de la
  agregación con `grade_grade::set_excluded` (`exelearning_exclude_overall_grade` desde
  `track.php` y `exelearning_recalculate_user_grades`) + migración en `upgrade.php` (stage
  `2026060401`). `get_hiding_affected` salta las excluidas → total visible;
  `finalgrade`/`gradepass` intactos (completion OK). Petición usabilidad INTEF #2.
  Verificado en Docker (Moodle 5.0.7): `COURSE TOTAL blanked_by_hidden=NO`.

### Hecho en sesión 2026-06-09 (parser híbrido + Mobile API + eventos, claude-opus-4-8)
- **Parser `content.xml` híbrido** (DEC-26-01): `classes/local/package.php` pasa a
  `DOMDocument` por `local-name()` para la estructura (robusto a namespaces/entidades/
  CDATA/orden de atributos); se reutilizan intactos `extract_isscorm`/`decrypt_datagame`/
  `hash_idevice_block`; fallback al escáner regex (`detect_gradable_idevices_regex`) con
  log si el XML está malformado. **Bug crítico cazado por fixtures reales**: los `.elpx`
  declaran `<!DOCTYPE ode SYSTEM "content.dtd">` → se acepta el DTD externo (`LIBXML_NONET`
  sin `DTDLOAD`/`NOENT`) y solo se rechazan entidades **internas**. 22 tests.
- **Mobile/External API** (DEC-26-02): 6 funciones en `classes/external/` registradas en
  `MOODLE_OFFICIAL_MOBILE_SERVICE`; `save_track` reusa la nueva `track::ingest()`
  (extraída de `track.php`) con salvaguardas server-side (objectid routing, recálculo
  overall, filtro de `itemscores` a objectids registrados). 14 tests.
- **Eventos** (DEC-26-03): `attempt_deleted` + `report_viewed` + `course_module_instance_list_viewed`.
- **Test roundtrip backup/restore** (P2). Suite completa **99/99 verde**, `phpcs --standard=moodle` 0/0.
- `version.php` intacto (centinela DEC-13-08). README con sección "Web services (Mobile API)".

## Decisiones clave (ver `research/decisiones/adr/`)

Resumen **curado** (comentario editorial que no está en el frontmatter). El **índice**
completo y autoritativo es generado: `research/docs/indices/adrs.yaml`
(`python3 research/tools/build_indexes.py`) o `make architecture-records`.

Los ADRs se identifican por el número de seguimiento de GitHub del cambio (issue, o PR
si no hay issue) — ver [`research/decisiones/README.md`](./research/decisiones/README.md)
y el mapa de identificadores retirados en
[`research/decisiones/mapa-migracion-ids.md`](./research/decisiones/mapa-migracion-ids.md).

| ADR | Estado | Resumen |
|---|---|---|
| DEC-0-01 | Aceptada | Metodología evidencia + ADRs |
| DEC-0-02 | Aceptada | Política clones externos (no vendorar) |
| DEC-0-03 | **Aceptada** (2026-05-29) | SCORM 1.2 estándar de tracking vigente y suficiente; xAPI sólo hoja de ruta |
| DEC-0-04 | **Aceptada** (2026-05-29) | CI matriz Moodle 4.5/5.0/5.1/5.2 × PHP 8.1-8.4 × pgsql/mariadb; `version.php` soporta Moodle [405, 502] |
| DEC-0-05 | **Superseded** by DEC-0-09 | Editor embebido (versión con online) |
| DEC-0-06 | Aceptada | Modos preview/grading |
| DEC-0-07 | **Aceptada** | Intentos: tabla plana `exelearning_attempt` + `grademethod` (implementado) |
| DEC-0-08 | **Aceptada** (rev. 2026-05-29) | Selector `grademodel` `peritem` (default) / `overall`; modo `both` eliminado |
| DEC-0-09 | Aceptada | **Sólo editor embebido**; eliminado eXeLearning Online / hmac |
| DEC-0-10 | **Aceptada** | Finalización estilo SCORM = core `completionpassgrade` + `gradepass` |
| DEC-0-11 | **Aceptada** | Presentación intentos en portada: resumen profesor (Tarea) + línea alumno; detalle en Informes |
| DEC-0-12 | **Aceptada** | `editor/save.php` re-extrae + re-sincroniza libro tras guardar (RIE-006: estabilidad objectid) |
| DEC-0-13 | **Aceptada** | Editor Online vs embebido: confirma solo-embebido (DEC-0-09); reapertura futura iría por opción D (enlace, sin HMAC) |
| DEC-0-14 | **Aceptada** (2026-05-29) | Soporte xAPI A+C: SCORM 1.2 vigente + diseño de referencia; sin empuje upstream (analítica LRS no prioritaria) |
| DEC-0-15 | **Aceptada** (2026-05-29) | Justificación de la multicalificación: DAFO + comparativa (exeweb/exescorm/scorm/h5p); veredicto: merece la pena con matices (deuda = shim SCORM, hoja de ruta = xAPI DEC-0-14) |
| DEC-4-01 | **Aceptada** (2026-06-01) | Auditoría de seguridad multi-agente: 21 hallazgos (18 corregidos, 3 diferidos) |
| DEC-5-01 | **Aceptada** (2026-06-01) | Ruteo de calificaciones por `objectid` estable (mis-ruteo N→itemnumber, RIE-007) |
| DEC-6-01 | **Aceptada** (2026-06-01) | Recálculo del overall desde `itemscores` (cierre RIE-007) + hardening menor |
| DEC-0-16 | **Aceptada** (2026-06-02) | Aislamiento del `.elpx` (RIE-001): análisis, paridad con core y roadmap (NO implementado por decisión) |
| DEC-11-01 | **Aceptada** (2026-06-02) | Traducciones es/ca/eu/gl: reuso de hermanos + marca «~» para auto-traducción pendiente de revisión |
| DEC-12-01 | **Aceptada** (2026-06-02) | Edición de contenido calificable: semántica snapshot + aviso al profesor (estilo SCORM) |
| DEC-13-01 | **Aceptada** (2026-06-03) | Detección de calificables por `isScorm>0` (no por lista de tipos) → issue #13 #2 y #5 |
| DEC-13-02 | **Aceptada** (2026-06-03) | Deep-link del gradebook al iDevice vía `grade.php` (itemnumber→objectid→ancla) → issue #13 #4 |
| DEC-13-03 | **Aceptada** (2026-06-03) | Crear `.elpx` desde cero (paquete opcional) + pantalla completa → issue #13 #1 y #6 |
| DEC-13-04 | **Superseded** by DEC-13-05 | Importar por-actividad desde `mod_exeweb`/`mod_exescorm` (motor reutilizado por DEC-13-05) |
| DEC-13-05 | **Aceptada** (2026-06-03) | Migración masiva desde Ajustes (admin, site-wide, no destructiva, progreso, exescorm→nota general) → issue #13 #3 |
| DEC-16-01 | **Aceptada** (2026-06-03) | Aceptar `.zip` (con `content.xml`) además de `.elpx` en la subida |
| DEC-13-06 | **Aceptada** (2026-06-03) | Enlaces del gradebook: análisis y destino del 'grade analysis' → issue #13 #4 |
| DEC-13-07 | **Aceptada** (2026-06-03) | Interruptor 'Calificable' por actividad (`gradeenabled`) → issue #13 |
| DEC-13-08 | **Superseded** by DEC-111-01 | Versión 'sentinela' (`9999999999`/dev) en main; la real la inyectaba `make package` |
| DEC-13-09 | **Aceptada** (2026-06-03) | Separar el formulario en 'Grading' y 'Attempts management' → issue #13 |
| DEC-17-01 | **Propuesta** (2026-06-04) | Ingesta dual de tracking: shim SCORM 1.2 + xAPI (`exe_xapi.js`) sobre tubería común → TAREA-015 |
| DEC-18-01 | **Propuesta** (2026-06-04) | Actualización de contenido: reemplazo del `.elpx` + origen por URL con sincronización (patrón `mod_scorm`) → TAREA-016 |
| DEC-19-01 | **Aceptada** (2026-06-04) | Selector de categoría de calificación (`gradecat`) aplicado a todos los grade items vía `grade_item::set_parent` (`grade_update` ignora `categoryid`) → petición usabilidad INTEF #1 |
| DEC-19-02 | **Aceptada** (2026-06-04) | Coherencia profesor/alumno en `peritem`: excluir la nota overall oculta de la agregación (`grade_grade::set_excluded`) para que Moodle no vacíe el total del alumno → petición usabilidad INTEF #2 |
| DEC-0-17 | **Aceptada** (2026-06-08) | `contenttype_exelearning` (banco de contenidos, REPO-006) como plugin separado; mirroring intencional de extracción/sandbox `.elpx` (RIE-013) |
| DEC-13-10 | **Aceptada** (2026-06-08) | Detección de `isScorm` también en el div `*-DataGame` cifrado (`unescape` + XOR 146) → issue #13 "solo 12 de 30 detectados" |
| DEC-25-01 | **Aceptada** (2026-06-08) | Sin columna overall oculta en `peritem`: completion estilo workshop sobre un item por-iDevice (supersede de DEC-19-02) |
| DEC-26-01 | **Aceptada** (2026-06-09) | Parser `content.xml` híbrido: `DOMDocument` por `local-name()` para la estructura + descifrado/hash conservados + fallback regex; acepta `<!DOCTYPE SYSTEM>` externo, rechaza entidades internas |
| DEC-26-02 | **Aceptada** (2026-06-09) | API externa/móvil: 6 funciones en `MOODLE_OFFICIAL_MOBILE_SERVICE` (incl. `save_track` reusando `track::ingest()` con salvaguardas server-side) |
| DEC-26-03 | **Aceptada** (2026-06-09) | Eventos selectivos: `attempt_deleted` + `report_viewed` + `course_module_instance_list_viewed` (sin evento por commit de tracking, sería ruido) |
| DEC-13-11 | **Aceptada** (2026-06-09) | Parchear al servir el guard de guardado de `form`/`scrambled-list` (quitar `body.exe-scorm`) → issue #13 "form/scrambled reportan 0" |
| DEC-29-01 | **Aceptada** (2026-06-10) | Detectar GeoGebra calificable por la clase `auto-geogebra-scorm` (issue #29, PR #30) |
| DEC-34-01 | **Aceptada** (2026-06-10) | Auditoría de bugs críticos (workflow multi-agente, 9 confirmados + 2 rechazados): B1 destrucción de paquete, B2/B2b pérdida de notas + `update_grades`, B3 items fantasma, B5 clamp DML, B6 `save_track` 0-score, B7 finalización por nota, B8 XSS informe; BETA tras críticos |
| DEC-34-02 | **Propuesta** (2026-06-10) | Transformación del paquete en tiempo de servido (`content_transformer` + `pluginfile`): elimina la reescritura del HTML en extracción (deuda nº1 del informe); diferida, salida definitiva es xAPI DEC-17-01 |
| DEC-36-01 | **Aceptada** (2026-06-10) | Inyecciones SCORM-loader (`inject_scorm_loader`) y teacher-mode (`require_teacher_mode_hider`): análisis plugin vs upstream eXeLearning (ventajas/inconvenientes); híbrido = fix plugin-side DEC-34-02 (amplía alcance al teacher-mode) + opción upstream documentada (sin abrir issues); conservar workaround para `.elpx` heredados |
| DEC-37-01 | **Aceptada** (2026-06-11) | Clasificación funcional: mantener `MOD_ARCHETYPE_ASSIGNMENT` + `MOD_PURPOSE_ASSESSMENT` (sin cambio de código); `supports()` no ve la instancia → el archetype/purpose no puede variar por `gradeenabled` (DEC-13-07); `gradeenabled=0` es "modo recurso" dentro de un módulo evaluable. Cierra la observación del informe comparativo (`docs/AUDIT_FOLLOWUP.md`) |
| DEC-66-01 | **Aceptada** (2026-06-12) | Estrategia de cobertura de tests: mockear la red con `\curl::mock_response()` + mock parcial de `download_to_temp()` en vez de excluir; no excluir del scope código testeable (`excludelistfiles` vacío); xdebug/Codecov es la medida autoritativa (pcov local subacredita llamadas anidadas — artefacto, no límite); gate `codecov project: target: auto` (trinquete). Cobertura honesta 85.71%→87.2% (PR #65) |
| DEC-67-01 | **Aceptada** (2026-06-12) | Auditoría estándar de repositorio (2026-06-11, tras DEC-4-01/DEC-34-01): 9 mejoras P1–P3 implementadas (PRs #46–#54: hardening XML de estilos, thirdpartylibs en el ZIP, fidelidad backup/restore, lock de intentos, participación vs grademethod, recálculo de notas en lote, `zip_utils`, descarga del informe, Behat) + registro de **hallazgos descartados** y opciones de dirección para no re-auditar |
| DEC-13-12 | **Aceptada** (2026-06-12) | La herramienta de migración exeweb/exescorm vive en `mod_exelearning` (destino, dueño de los internals); orígenes como fuentes legacy de solo lectura tras `source_interface`. Endurecimiento de la rama issue #13: fix `mod_exeweb` itemid=revision (antes leía 0 → todo `nosource`); clasificación `mod_exescorm` (`.elpx` directo / 1 embebido / 0=nosource / >1=ambiguous / external+aiccurl+localsync=unsupported, `localsync` excluido por sincronización aunque tenga snapshot local); limpieza compensatoria con `course_delete_module` ante fallo parcial (sin transacción, caveat recycle bin); preservación de metadatos del cm (idnumber **nunca** se copia); validación post-extracción anti shell-vacío (`migrateextractfailed`); eventos (started/migrated/skipped/failed, patrón DEC-26-03); columnas `userid`/`timemodified` (upgrade 2026061201); preflight + `\core\progress\display`. Refactor a `classes/local/migration/` (elimina `import_service`). CLI diferido |
| (resto, 2026-06-12 → 2026-07-24) | (varias) | **Ver índice completo en `research/docs/indices/adrs.yaml`.** Resumen: DEC-68-01 eventos selectivos · DEC-69-01 completion por estado · DEC-70-01 búsqueda global · DEC-71-01 refactor `lib.php` (extracción a clases) · DEC-72-01 auditoría post-refactor · DEC-74-01 tests JS (Vitest) · DEC-77-01 extracción no-destructiva (BETA→STABLE) · DEC-78-01 fijar tag del editor en release · DEC-0-18 validación canónica del endpoint xAPI + política de versión (1.0.3 tolerante a 2.0) · DEC-85-01 implementación ingesta xAPI · DEC-106-01 editor solo empaquetado en release (sin instalador runtime) · DEC-108-01 interruptor global del editor (modo reproductor puro) · DEC-110-01 página de estilos solo-endpoint (cierra UX-01) · DEC-111-01 versión real y monótona en main (supersede DEC-13-08; empaquetado valida, no muta). *(La rama `feature/secure-iframe-scorm-bridge` (PR #80) lleva más decisiones aún no fusionadas; migra sus propios identificadores en su rama.)* |

## Restricciones inmutables

- **Sólo paquete v4 ODE 2.0** (con `content.xml`), aceptado como `.elpx` **o `.zip`** (DEC-16-01). NO `.elp` legacy, NO `iteexe_online`.
- **NO** vendorar repos externos.
- **NO** integración eXeLearning Online (DEC-0-09): no tocar `editormode`,
  `exeonlinebaseuri`, `hmackey1`, `APP_SECRET`, `EXELEARNING_WEB_*`.
- Sidebar nativa **siempre** preservada (técnica iframe de `mod_exeweb`).
- Repo público: `github.com/exelearning/moodle-mod_exelearning`.
- Organización: ATE = **Área de Tecnología Educativa** (no "Asistencia Técnica").

## Trampas conocidas (no repetir)

- **`itemnumber_mapping`**: Moodle 5 itera el mapeo entero → requiere strings
  `grade_overall_name` + `grade_idevice1..100_name` en `lang/en/exelearning.php`
  (loop generado, MAX=100 porque `test22.elpx` tenía 29 iDevices).
- **Pipwerks lazy**: eXeLearning v4 sólo llama `pipwerks.SCORM.init()` si lo
  inyectamos manualmente en el `<head>` de cada HTML (`exelearning_inject_scorm_loader`).
- **`lesson_status=passed`**: NO ponerlo en feedback (no es estándar; ya eliminado).
- **`enrol_manual->add_default_instance`**: falla en `erseco/alpine-moodle:v5.0.7`
  por defaults globales ausentes → usar `add_instance($course, [...status =>
  ENROL_INSTANCE_ENABLED...])` explícito.
- **`forum_announcementsubscription` undefined**: workaround en `setup_demo.php`
  setea `$CFG->forum_announcementsubscription=1` y `forum_announcementmaxattachments=9`
  antes de `create_course`.
- **Blueprint Playground**: `setLandingPage` requiere `?id=N`, NO `?shortname=`.
- **`monologo.svg`**: viewBox `0 0 78 78`, X ocupa todo, sin hamburguesa. Moodle 4+
  prefiere SVG → no recrear PNGs.
- **Sandbox iframe**: `allow-scripts allow-same-origin allow-popups allow-forms
  allow-popups-to-escape-sandbox` (sin `allow-top-navigation` ni `allow-modals`).
- **Switch-to-student**: en modo grading silencioso, no romper.

## Normas de codificación

- **SIEMPRE añadir tests con cada cambio.** Todo cambio de comportamiento (feature,
  fix, refactor) DEBE llegar con tests unitarios que cubran el caso feliz **y** los
  bordes/regresiones (p.ej. fallo de extracción que preserva el estado previo). PHP →
  PHPUnit en `tests/` (junto a `@covers`); JS → Vitest en `tests/js/`. Preferir TDD
  donde sea unit-testable. Correr localmente con `make test ARGS=mod/exelearning/tests/<archivo>.php`
  (varios archivos separados por espacio; **no** apuntar al directorio entero —el
  `vendor/phpunit` del plugin colisiona) y `make test-js`. Si el entorno PHPUnit dice
  "initialised for different version", ejecutar
  `docker compose exec moodle php /var/www/html/admin/tool/phpunit/cli/init.php`.
  (NOTA histórica: los tests del instalador del editor corrompían `/etc/passwd`
  del contenedor; ese instalador y sus tests se eliminaron en DEC-106-01.)
- **Comentarios de código en INGLÉS.** Todo `.php`/`.js` del plugin. La carpeta
  `research/` (ADRs, fichas, diario, notas) va en **español**. Las librerías de
  terceros vendoradas (`assets/scorm/*`, wrappers SCORM/pipwerks) no se tocan.
- **Documentar cada funcionalidad en el código fuente con base en la
  investigación** (en inglés): cada función/área no trivial lleva un docblock que
  explica *qué hace y por qué*, citando la decisión/fuente que la justifica
  (p.ej. `(DEC-0-08)`, `(see FTE-006)`, `(RIE-006)`). El "porqué" vive junto al
  código, no solo en `research/`.
- **`phpcs --standard=moodle` debe quedar limpio (0/0).** Validar SIEMPRE con
  `vendor/bin/phpcs --standard=moodle <archivos>`, NO con el ruleset local
  `.phpcs.xml.dist` (enmascara errores que la CI sí detecta).
- **PHPDoc completo** (`moodle-plugin-ci phpdoc`): `@param`/`@return` en cada función.
- **AMD**: tras tocar `amd/src/*.js` hay que regenerar `amd/build/` con el
  `grunt amd` de Moodle (rollup), no a mano.
- **Tests JS** (DEC-74-01): el tracker SCORM crítico para notas vive en
  `js/scorm_tracker.js` (fuente única; `view.php` lo inyecta inline para mantener
  `window.API` síncrono antes del iframe). Se testea con **Vitest** en `tests/js/`
  (`make test-js`), NO con el Jest de Moodle (solo ESM). La UI (`amd/src/fullscreen.js`,
  `resize.js`, …) y los wrappers pipwerks (`assets/scorm/*`) quedan fuera de alcance.
  La cobertura sube a Codecov bajo el flag `javascript` (job `jsunit`).
- **`lang/en/exelearning.php`**: strings en orden alfabético ESTRICTO por clave,
  sin código (`for`/variables) — `moodle.Files.LangFilesOrdering` lo rechaza.

## Layout

```
mod_exelearning/
├── lib.php                    # API pública + sync_grade_items + update_grades + inject_scorm_loader
├── view.php                   # iframe + SCORM 1.2 shim (autocommit 500ms)
├── track.php                  # AJAX endpoint (sesskey + mode preview/grading)
├── mod_form.php
├── settings.php               # Estilos + xAPI (el editor no se gestiona en runtime, DEC-106-01)
├── editor/index.php           # Página bootstrap del editor embebido por actividad
├── classes/
│   ├── grades/gradeitems.php  # itemnumber_mapping (MAX 100)
│   ├── local/package.php      # Parser content.xml
│   └── event/course_module_viewed.php
├── db/{install.xml,access.php,upgrade.php}
├── lang/en/exelearning.php
├── pix/                       # monologo.svg (sin hamburguesa)
├── scripts/setup_demo.php     # Idempotente
├── dist/static/               # Editor embebido (build de exelearning v4)
├── blueprint.json             # Playground (?id=2)
├── docker-compose.yml         # erseco/alpine-moodle:v5.0.7
├── .env.dist
├── composer.json              # require-dev: moodlehq/moodle-cs, phpmd, phpunit
├── .github/dependabot.yml
└── research/                  # ADRs, fuentes, fixtures (append-only)
```

## Agent skills (`.agents/`)

The `.agents/skills/` directory contains Claude Code project skills — reusable prompt workflows invoked with `/skill-name` in the Claude Code CLI. They are development tooling only and are excluded from release packages (covered by the `.*` rule in `.distignore`).

| Skill | File | Purpose |
|-------|------|---------|
| `changelog` | `.agents/skills/changelog/SKILL.md` | Draft or top up the `CHANGELOG.md` block from merged GitHub PRs. Mode A adds PRs merged since a given one to the existing draft; mode B opens a new version block from the last published release. Adapted from the sibling skill in `exelearning/exelearning` — keep both in sync. Required before a release (`docs/RELEASE_CHECKLIST.md` §10) |

## Atajos útiles

```bash
docker compose up -d && docker compose logs -f moodle
docker compose exec moodle php /var/www/html/mod/exelearning/scripts/setup_demo.php
python3 research/tools/build_indexes.py
python3 research/tools/test_schema_validation.py
```

Credenciales demo: admin `user/1234`, teacher `teacher_demo/Demo!2026`,
estudiantes `alumno1, alumno2/Demo!2026`. Curso `EXEDEMO` (id=2).
