---
id: DEC-0-04
tracking_issue: 0
legacy_id: DEC-0004
title: "Build, empaquetado y CI: Makefile heredado + moodle-plugin-ci con matriz Moodle/PHP/DB"
status: Accepted
date: 2026-05-28
deciders:
  - erseco
  - claude-code
sources:
  - REPO-001
  - REPO-002
  - AN-006
experiments: []
ai_assistance:
  tool: claude-code
  model: claude-opus-4-7
---

# DEC-0-04: Build, empaquetado y CI: Makefile heredado + moodle-plugin-ci con matriz Moodle/PHP/DB

## Contexto

El usuario establece como **requisito duro**: `mod_exelearning` debe disponer
desde el primer commit de:

1. Un **Makefile** equivalente al de `mod_exescorm`/`mod_exeweb` (targets
   `up/down/lint/fix/phpmd/test/behat/build-editor/package`, …).
2. Pipelines de **GitHub Actions** para empaquetar y validar el plugin.
3. **Tests automáticos en CI** ejecutados contra **varias versiones de Moodle y
   PHP**, como ya hacen otros plugins maduros del ecosistema.

AN-006 ha identificado que los plugins hermanos no tienen aún un workflow de CI
matriz; sólo workflows de release. Hay que cerrar ese hueco para `mod_exelearning`.

## Problema

Definir un esquema de build/empaquetado/CI/tests reproducible, alineado con el
ecosistema Moodle, que (a) ejecute pruebas en cada PR, (b) detecte rupturas de
compatibilidad pronto y (c) produzca artefactos publicables firmados.

## Opciones consideradas

1. **Heredar Makefile + 3 workflows existentes, añadir `ci.yml` matriz con
   `moodle-plugin-ci`** ← recomendada.
2. Heredar tal cual sin matriz CI — descartado, regresivo respecto al ecosistema.
3. Migrar a Composer scripts puros + GitHub Actions de "moodlehq" oficiales — más
   limpio pero rompe simetría con `mod_exescorm`/`mod_exeweb` (¬ familiar).

## Evidencia

- `mod_exescorm/Makefile` y `mod_exeweb/Makefile`: targets idénticos (AN-006).
- Workflows existentes: `release.yml`, `check-editor-releases.yml`,
  `pr-playground-preview.yml`. **No hay `ci.yml`** (AN-006).
- [`moodle-plugin-ci`](https://moodlehq.github.io/moodle-plugin-ci/) es el estándar
  de facto: lo usan `mod_attendance`, `mod_customcert`, `mod_zoom`, etc.

## Decisión propuesta

`mod_exelearning` arrancará con la siguiente **infraestructura mínima**:

### Makefile

Mismos targets que `mod_exescorm`/`mod_exeweb`, adaptado al componente:

```
make up | upd | down | pull | build | shell | clean        # Docker harness
make install-deps                                          # Composer
make lint | fix | phpmd                                    # Calidad
make test | behat                                          # PHPUnit + Behat
make build-editor | clean-editor | fetch-editor-source     # Editor eXeLearning v4
make package RELEASE=<v>                                   # Genera mod_exelearning-<v>.zip
make help
```

Reglas:
- `make build-editor` clona desde `github.com/exelearning/exelearning`, ref
  configurable (default rama v4 estable).
- `make package` produce `mod_exelearning-<version>.zip`.

### GitHub Actions

`.github/workflows/`:

| Workflow | Disparador | Propósito |
|---|---|---|
| `ci.yml` | push/PR | **NUEVO**: matriz `moodle-plugin-ci` (lint, phpcs, phpmd, phpdoc, mustache, grunt, phpunit, behat) |
| `release.yml` | release.published + workflow_dispatch | `make package` + adjunta ZIP al release |
| `check-editor-releases.yml` | cron diario | Detecta nuevo release de eXeLearning y rebuilda |
| `pr-playground-preview.yml` | PR | Preview desplegable |
| `codeql.yml` | push/PR + cron semanal | Análisis estático de seguridad |
| `dependabot.yml` | (no es workflow, es config) | Actualización de dependencias |

### Matriz CI obligatoria

`ci.yml` ejecuta `moodle-plugin-ci` con esta matriz:

| Eje | Valores soportados |
|---|---|
| Moodle | `MOODLE_401_STABLE` (4.1 LTS), `MOODLE_403_STABLE`, `MOODLE_405_STABLE` (4.5 LTS), `main` (canary, allow-failure) |
| PHP | `8.1`, `8.2`, `8.3`, `8.4` (sólo combinaciones que Moodle declara soportar) |
| DB | `pgsql`, `mariadb` |

Combinaciones a excluir explícitamente con `exclude:` cuando una versión de Moodle
no soporta una versión de PHP (consultar
[Moodle Releases](https://moodledev.io/general/releases) antes de cada cambio).

### Pasos del job `ci.yml`

1. `actions/checkout@v4`
2. `setup-php` con `tools: composer:v2`
3. `setup-node` (para grunt + mustache lint)
4. `composer create-project moodlehq/moodle-plugin-ci ci`
5. `moodle-plugin-ci install --plugin=$GITHUB_WORKSPACE --db-type=$DB`
6. Steps: `phplint`, `phpcs`, `phpcpd`, `phpmd`, `phpdoc`, `validate`,
   `savepoints`, `mustache`, `grunt`, `phpunit`, `behat --profile chrome`.

Behat opcional inicial (puede activarse en una iteración posterior si ralentiza).

### Otros requisitos derivados

- Badges en `README.md`: build status, release, coverage, "compatible with Moodle X.Y".
- `composer.json` con dependencias dev (`moodlehq/moodle-cs`, `phpmd/phpmd`).
- `.gitignore` con `vendor/`, `node_modules/`, `moodle/`, `ci/`, `*.zip`.
- `.env.dist` con variables del Docker harness, idéntica forma a la de
  `mod_exescorm`/`mod_exeweb`.
- Tests con fixtures de `research/fixtures/elpx/` (ELPX v4) accesibles desde
  `tests/fixtures/` mediante symlink o copia controlada.

## Consecuencias

Positivas:
- Garantía de compatibilidad pre-release contra varias versiones de Moodle.
- Familiaridad: cualquiera que conozca `mod_exescorm`/`mod_exeweb` opera igual.
- Pipeline reproducible (`make`-driven, Docker-based).

Negativas:
- Tiempo de CI en cada PR aumenta (~10-15 min con matriz completa).
- Mantenimiento adicional: actualizar la matriz cada release de Moodle.

## Riesgos

- RIE-pendiente: rotura silenciosa con Moodle main si se ignora canary.
- RIE-pendiente: dependabot con upgrades agresivos rompiendo el plugin.

## Validación

Antes de aceptar:
- Existir un PR de prueba que falle si rompe lint y pase si todo verde.
- Comprobar que `make package` funciona en CI (job aparte).
- Documentar la matriz exacta en este ADR antes de pasar a Aceptada.

## Seguimiento

- TAREA-006: redactar `ci.yml` concreto con matriz.
- Esta decisión es **transversal**: no bloquea DEC-0-03 pero se ejecuta en
  paralelo desde el primer commit del plugin.

## Actualización (2026-05-29): Aceptada — CI implementada y verde

`.github/workflows/ci.yml` implementado con moodle-plugin-ci v4 y matriz de 18
combinaciones: Moodle 4.5 LTS (MOODLE_405_STABLE) × PHP 8.1/8.2/8.3, Moodle 5.0
y 5.1 × PHP 8.2/8.3/8.4, cada una con pgsql y mariadb. Pasos: phplint, phpmd,
phpcs (moodle-cs, `--max-warnings 0`), phpdoc, validate, savepoints, mustache,
grunt, phpunit; behat opcional/continue-on-error.

Trampas resueltas para que pase verde (ver diario 2026-05-29):
- `thirdpartylibs.xml` NO debe declarar `dist/static` (gitignored): `grunt
  ignorefiles` hace `stat` de cada `<location>` y aborta el `install` con ENOENT,
  tumbando toda la matriz en cascada. Se quitó esa entrada.
- phpcs estándar moodle: el árbol cumple `--max-warnings 0` (docblocks, orden
  alfabético de strings en `lang/en` — sniff `moodle.Files.LangFilesOrdering` —,
  sin `MOODLE_INTERNAL` en ficheros de clase/test, líneas ≤132).
- phpdoc: cada función documenta TODOS sus `@param`.
- grunt/eslint: AMD de `amd/src` con JSDoc en todas las funciones y sin
  variables sin usar; `amd/build/` regenerado.

## Actualización (2026-06-01): Behat bloqueante y matriz Moodle 5.2

La CI vigente ya no deja Behat como opcional. El PR #8 elimina el
`continue-on-error` del paso `moodle-plugin-ci behat --profile chrome`, añade
`$CFG->behat_increasetimeout = 3` tras `moodle-plugin-ci install` y deja Behat
como verificación bloqueante de cada combinación.

La matriz vigente pasa de 18 a 22 jobs: mantiene Moodle 4.5/5.0/5.1 y añade
Moodle 5.2 (`MOODLE_502_STABLE`) con PHP 8.3/8.4, pgsql y mariadb. PostgreSQL
16 queda como servicio de CI para cubrir Moodle 5.2.

Evidencia: GitHub Actions PR #8, run `26771584651`, 22/22 jobs `test (...)`
en verde, todos con `PHPUnit tests` y `Behat features` pasados. Esta revisión
supersede la frase anterior "behat opcional/continue-on-error" dentro de esta
misma decisión.

## Actualización (2026-06-08): empaquetado git-only (`scripts/package.sh`)

El `make package` heredado de `mod_exeweb`/`mod_exescorm` fallaba en **Git Bash
(Windows)**: caía a un `scripts/package.py` que este repo nunca copió, y la ruta
rsync rooteaba el ZIP en la carpeta equivocada `exeweb/` (debe ser `exelearning/`
para el componente `mod_exelearning`). Issue #22, PR #23.

Decisión: **divergir** de los hermanos y empaquetar usando **sólo `git`** (sin
`zip`, `rsync`, `python` ni `php`, ninguno presente en Git Bash base). El nuevo
`scripts/package.sh`:

- monta un índice temporal con el árbol de trabajo (incluido el editor compilado
  en `dist/static/`, que está en `.gitignore`), filtrando por `.distignore`
  (match por componente top o ruta completa, misma semántica que el `package.py`
  de los hermanos);
- estampa `version.php` **en ese índice** (la copia de trabajo no se toca; sigue
  con el sentinela de DEC-13-08);
- emite el ZIP con `git archive --format=zip --prefix=exelearning/`;
- escribe los objetos en un store temporal, dejando el `.git` real intacto.

`composer archive` se descartó (respeta `.gitignore` → se comería el editor, y
exige composer+php). `.distignore` sigue siendo la única fuente de exclusiones,
limpiada para no enviar config de dev/CI/playground y sí `README.md` y
`thirdpartylibs.xml`. CI (`release.yml` en `ubuntu-latest`) no cambia: sólo llama
a `make package`.
