---
id: DEC-78-01
title: "Build de release reproducible: fijar el editor al tag homónimo (semver-match), no a la rama main"
status: Accepted
date: 2026-06-13
tracking_issue: 78
legacy_id: DEC-0058
deciders:
  - erseco
  - claude-code
sources:
  - REPO-002
  - REPO-001
  - REPO-005
related:
  adrs: [DEC-72-01, DEC-13-08, DEC-0-02]
ai_assistance:
  tool: claude-code
  model: claude-opus-4-8
---

# DEC-78-01: Build de release reproducible: fijar el editor al tag homónimo (semver-match), no a la rama main

## Contexto

Hallazgo del followup post-refactor ([[DEC-72-01]], item 2): el workflow de release empaquetaba el
editor estático **desde la rama `main`**, no desde un tag fijo. En `.github/workflows/release.yml`,
ante un evento `release` se forzaba:

```
EXELEARNING_EDITOR_REF=main
EXELEARNING_EDITOR_REF_TYPE=branch
```

`main` es una referencia móvil, así que **dos builds del mismo tag del plugin podían empaquetar
editores distintos** (no reproducible) según cuándo se ejecutaran. Agravante: el `Makefile`
(`build-editor`) compilaba el editor **sin pasar `APP_VERSION`**, de modo que desde `main` el editor
reportaba su versión interna como `0.0.0-alpha`/nightly (su build script `scripts/build-static-bundle.ts`
lee `APP_VERSION`/`VERSION` y, en ausencia, cae a `package.json`), en lugar de la versión de release.

Los plugins hermanos resuelven esto con **semver-matching** y `APP_VERSION`. El ecosistema está
versionado en lockstep: editor `exelearning/exelearning` ([[REPO-005]]), `mod_exeweb` ([[REPO-002]]) y
`mod_exescorm` ([[REPO-001]]) están todos en `v4.0.1` (y antes `v4.0.0`, `v4.0.0-rc3`…). En sus
`release.yml`, ante un evento `release`, el tag del plugin determina el tag del editor:

```yaml
# Build the editor from the matching editor tag so the embedded
# editor reports the final release version (not a nightly/alpha build).
echo "EXELEARNING_EDITOR_REF=v${VERSION_TAG}" >> $GITHUB_ENV
echo "EXELEARNING_EDITOR_REF_TYPE=tag" >> $GITHUB_ENV
```

`mod_exelearning` **aún no tenía ninguna release**: su `release.yml` era el calco de la plantilla
familiar **salvo** esas dos líneas, que habían quedado en `main`/`branch` (anomalía heredada del
desarrollo inicial). Su primera release será `v4.0.1`, casando con el editor.

## Decisión

Alinear el build de release de `mod_exelearning` con los hermanos, con tres piezas y dos exclusiones
deliberadas.

| Pieza | Cambio |
|---|---|
| `.github/workflows/release.yml` | En el evento `release`, fijar `EXELEARNING_EDITOR_REF=v${VERSION_TAG}` y `EXELEARNING_EDITOR_REF_TYPE=tag` (semver-match, calco de [[REPO-002]]/[[REPO-001]]). La rama `workflow_dispatch` **no se toca**: conserva los inputs manuales (`editor_ref`/`editor_ref_type`, default `main`/`auto`) para pruebas. |
| `Makefile` (`build-editor`) | Resolver `APP_VERSION` (orden `APP_VERSION` → `VERSION` → `EXELEARNING_EDITOR_REF` (env/`.env`) → `git describe --exact-match` del checkout → vacío → alpha) y pasarlo a `bun run build:static`. Idéntico a los hermanos; `fetch-editor-source` ya era común. |
| `.github/workflows/ci.yml` + `scripts/check-release-workflow.sh` | Guard estático: un job `release-workflow-check` que aísla la rama del evento `release` del `release.yml` y **falla** si no fija un tag (`REF_TYPE=tag` + `REF=v${VERSION_TAG}`) o si reintroduce `main`/`branch`. Es el "test sugerido" del audit; previene la regresión. |

**Fuera de alcance (exclusiones deliberadas, NO bugs):**

- **`.editor-version`** — el borrado puntual del marker durante pruebas es intencional (forzar un
  paquete nuevo); el cron `check-editor-releases.yml` lo reconstruye con la última versión del editor
  en la siguiente iteración. No se restaura ni se eliminan sus referencias.
- **`blueprint.json`** — su pin del editor (`release=v4.0.1`) lo mantiene en sincronía el propio
  `check-editor-releases.yml` (vía `sed`) cuando sale una release nueva del editor. No se toca aquí.
- **`make build-editor` desde `main` en local** — es a propósito (pruebas locales). El cambio del
  `Makefile` lo preserva: con `REF=main`, `git describe --exact-match` no encuentra tag → fallback a
  `alpha`, sin romper el flujo local.

## Consecuencias

- Positivas: el ZIP de cada release del plugin incluye **exactamente** el editor del tag homónimo,
  reproducible (dos builds del mismo tag → mismo editor) y con la versión correcta embebida (no
  nightly/alpha). Cierra la divergencia con la plantilla familiar ([[REPO-002]]/[[REPO-001]]). El guard
  en CI impide que la regresión vuelva.
- Coste / invariante nuevo: el tag de release del plugin **debe** existir como tag del editor
  (`vX.Y.Z`); si se publica una release con un tag sin editor homólogo, el `make build-editor` del
  release falla ruidosamente (fetch del tag inexistente). Es el comportamiento buscado (lockstep), no
  un defecto. Queda anotado en `docs/RELEASE_CHECKLIST.md`.

## Riesgos

- Publicar una release con un esquema de tag propio (no homólogo del editor) rompería el build de
  release. Mitigación: invariante documentado + el lockstep del ecosistema (la 1ª release es `v4.0.1`).
- El editor no se vendora ([[DEC-0-02]]): se clona en build. El pin por tag no cambia esa política,
  solo la hace reproducible (antes, `main` la hacía no determinista).

## Validación

- `bash scripts/check-release-workflow.sh` → `OK` sobre el `release.yml` corregido; sobre una copia
  con `main`/`branch` reintroducido → `FAIL` con exit≠0 (regresión detectada en prueba).
- `make -n build-editor` parsea sin error; el bloque `APP_VERSION` aparece en la receta expandida.
- `release.yml` y `ci.yml` validan como YAML (`yaml.safe_load`).
- Paridad confirmada: la rama `release` de `release.yml` y la receta `build-editor` quedan idénticas a
  `mod_exeweb`/`mod_exescorm` salvo nombre del plugin y versiones de actions.
- `python3 research/tools/test_schema_validation.py` (ADR válido) y `build_indexes.py` (índices
  regenerados).

## Seguimiento

- En la primera release (`v4.0.1`) verificar que el ZIP publicado declara el editor `v4.0.1` en
  `thirdpartylibs.xml` (vía `.editor-version`, reconstruido por el cron) y que el editor embebido
  reporta `v4.0.1` (no alpha).
- Independiente de [[DEC-13-08]] (sentinela de `version.php`): aquel fija la versión del *plugin*; este
  fija la del *editor* empaquetado.
