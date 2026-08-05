---
id: DEC-66-01
title: "Estrategia de cobertura de tests: mockear en lugar de excluir, medir con xdebug y gatear con trinquete"
status: Accepted
date: 2026-06-12
tracking_issue: 66
legacy_id: DEC-0048
deciders:
  - erseco
  - claude-code
sources:
  - REPO-004
  - REPO-001
related:
  adrs: [DEC-0-04, DEC-34-01, DEC-4-01, DEC-36-01]
ai_assistance:
  tool: claude-code
  model: claude-opus-4-8
---

# DEC-66-01: Estrategia de cobertura de tests: mockear en lugar de excluir, medir con xdebug y gatear con trinquete

## Contexto

El informe técnico comparativo señaló que `mod_exelearning` tenía **menos cobertura de
tests** que `mod_exescorm` (REPO-001), y la promoción a `MATURITY_BETA` ([[DEC-34-01]])
elevó el listón de calidad esperado. Al subir la cobertura de ~50 % a **87,2 %** (PR #65)
aparecieron varias lecciones **no obvias** sobre la herramienta de medición y sobre cómo
testear el código de integración (red/ficheros). Este ADR las fija como **estándar de
testing del plugin** para no reaprenderlas ni reabrir las discusiones.

El detonante concreto: el adaptador de descarga del editor
(`classes/local/embedded_editor_installer.php`) estaba **excluido** de la cobertura en
`tests/coverage.php` con el argumento "es I/O de red, no se puede testear sin un mock
HTTP". Eso inflaba el porcentaje (85,71 % escondiendo el fichero) sin ser honesto.

## Problema

1. ¿Cómo testear código que hace **HTTP saliente** (descarga del editor desde GitHub) y
   **operaciones de ficheros**, sin red real ni fixtures gigantes?
2. ¿Qué se puede excluir legítimamente del *scope* de cobertura y qué no?
3. ¿Por qué pcov reportaba como **sin cubrir** funciones que demostrablemente se
   ejecutan, y en qué número podemos confiar?
4. ¿Cómo convertir la cobertura en un **requisito** que no se pueda erosionar?

## Lecciones y decisiones

### 1. Mockear la red con la API nativa de Moodle, no excluir

Moodle trae `\curl::mock_response($body)` (REPO-004, `lib/filelib.php`): bajo
`PHPUNIT_TEST`, `\curl::request()` saca de una pila **LIFO** la respuesta pre-cargada y la
devuelve fijando `info['http_code'] = 200`, sin tocar la red. Es el mismo mecanismo que
usan core, `mod_lti` y oauth2.

- Cubre `$curl->get()` → `fetch_releases_feed()` y `fetch_release_asset_sha256()` se
  testean de verdad (feed Atom y JSON de la API de releases simulados).
- **No** cubre `$curl->download_one()` (devuelve el string pero **no escribe el fichero**)
  → el único punto de descarga (`download_to_temp()`) se sustituye con un **mock parcial
  de PHPUnit** (`createMock`/`getMockBuilder(...)->onlyMethods(['download_to_temp'])`) que
  devuelve un ZIP de fixture; así corre el pipeline completo
  `install_version()`/`install_latest()`/`do_install()` offline y la verificación
  **SHA-256 real** ([[DEC-4-01]]) sigue ejecutándose.
- Quedan sin cubrir solo las ramas de error de curl (`get_errno()` / `http_code != 200`),
  inalcanzables porque el mock siempre resuelve con éxito. Se aceptan como residuo.

**Regla:** ante código de integración, **mockear con la API de Moodle antes que excluir**.

### 2. No excluir del scope código testeable

`tests/coverage.php` solo debe excluir lo que **no es lógica del plugin**: adaptadores de
UI de admin (`classes/admin`, render-only) y la infraestructura de test
(`tests/generator`). **No** se excluyen ficheros por "difíciles": se testean. Tras #65,
`excludelistfiles` queda **vacío**. El número refleja cobertura real, no *scope* recortado.

### 3. pcov local subacredita; el número que manda es xdebug (CI/Codecov)

El contenedor de desarrollo (`erseco/alpine-moodle`) solo trae **pcov**. Dos trampas:

- pcov **no instrumenta** si no se le pasa `-d pcov.directory=<plugin>` (sin eso el clover
  sale TODO a 0).
- pcov **subacredita llamadas anidadas**: `exelearning_extract_stored_package()` y
  `exelearning_inject_scorm_loader()` ([[DEC-36-01]]) aparecían "sin cubrir" aunque se
  ejecutan en cada `create_instance` del generator. Se verificó empíricamente que **sí se
  ejecutan** (un test aislado las cubre, el filearea `content` se puebla con el `index.html`
  y los wrappers SCORM). **No era un límite real ni código muerto: era un artefacto de
  atribución de pcov** con el call-stack a través del generator.

**El driver del CI es xdebug**, que sí las acredita. Por eso al incluir el instalador la
cobertura **subió** (85,71 % → 87,2 %) en vez de bajar. **Regla:** el número de referencia
es el de **Codecov (xdebug)**; pcov local es solo cota inferior orientativa — no perseguir
"líneas fantasma" contra pcov.

### 4. Gate con trinquete (`auto`), no umbral fijo

`codecov.yml` usa `project: target: auto` (+0,5 % de margen): fija la cobertura al nivel
que alcanza `main` y **falla cualquier PR que regrese**; solo puede subir. El `patch`
exige 80 % en líneas nuevas. Para que sea **requisito de merge** hay que añadir
`codecov/project` a los *required status checks* de la protección de rama de `main`
(acción de admin del repo, fuera del alcance del código).

## Evidencia

- `tests/embedded_editor_installer_test.php` — `\curl::mock_response()` + mock parcial de
  `download_to_temp()`; cubre discover/fetch/instalación completa offline.
- `tests/lib_extract_test.php` — asserts del efecto de extracción + inyección SCORM-loader
  ([[DEC-36-01]]).
- `tests/coverage.php` — `excludelistfiles` vacío (instalador ya no excluido).
- `codecov.yml` — `project: target: auto`.
- Números Codecov (xdebug): base `main` 85,71 % (19 ficheros, instalador excluido) →
  PR #65 **87,2 %** (20 ficheros, instalador incluido), `ci_passed: true`.
- REPO-004 — `\curl::mock_response()` (`lib/filelib.php`), `phpunit_coverage_info`, drivers
  pcov/xdebug.
- REPO-001 — `mod_exescorm`, referencia de cobertura del informe comparativo.

## Decisión

Adoptar como **estándar de testing** del plugin: (1) mockear la red con
`\curl::mock_response()` + mock parcial del único punto de descarga, en vez de excluir;
(2) no excluir del *scope* código que sea lógica del plugin; (3) tratar **xdebug/Codecov**
como la medida autoritativa y pcov local solo como cota inferior (con `pcov.directory`);
(4) gatear con `codecov project: target: auto` (trinquete) y enforcement vía branch
protection. Cierra la observación de "baja cobertura" del informe comparativo.

## Consecuencias

- **Positivas:** la cobertura es **honesta** (sin *scope* recortado); el código de red del
  instalador ([[DEC-4-01]]) queda cubierto; el trinquete impide regresiones; queda
  registrado el artefacto de pcov para no reinvestigarlo.
- **Negativas / coste:** persiste un residuo no cubierto (ramas de error de curl,
  cola larga de ramas en `styles_service`, y la WS `manage_embedded_editor` cuyo testeo de
  red exigiría inyectar el instalador — refactor de producción no abordado). El 90 % no se
  persiguió: se gatea "al nivel alcanzado".
- **Cambios que dispara:** ninguno funcional. Documentación y convención de tests.

## Riesgos

- Que un futuro PR vuelva a **excluir** un fichero para "subir el número". Mitigación: esta
  decisión + el trinquete `auto` (excluir no sube `main`, y el patch exige 80 %).
- Confiar en el pcov local y "arreglar" cobertura fantasma. Mitigación: regla explícita de
  usar el número de Codecov/xdebug.

## Validación

- `make test` → suite verde (205 tests, PHPUnit 11.5, Moodle 5.0.7).
- `phpcs --standard=moodle` → 0 errores / 0 warnings (CI con `--max-warnings 0`).
- `python3 research/tools/test_schema_validation.py` y `build_indexes.py` → ADR válido e
  índices regenerados.
- Codecov: `codecov/project` (auto) en verde en #65.

## Seguimiento

- Cierra la brecha de cobertura del informe comparativo (ver `docs/AUDIT_FOLLOWUP.md`).
- Acción pendiente del admin: añadir `codecov/project` a los required checks de `main`.
- Si en el futuro se quiere llegar al 90 %: refactor de `manage_embedded_editor` para
  inyectar el instalador (testear install/update/repair con el mismo mock) y cola larga de
  `styles_service`. No planificado.
