---
id: AN-006
titulo: "Análisis de la infraestructura de build/CI de mod_exescorm y mod_exeweb"
fecha: 2026-05-28
fuentes:
  - REPO-001
  - REPO-002
relacionados:
  - DEC-0-04
herramienta_ia:
  interfaz: claude-code
  modelo: claude-opus-4-7
---

## Resumen

`mod_exescorm` y `mod_exeweb` (REPO-001/002) comparten un **Makefile orientado a
Docker** que cubre desarrollo local, lint, tests y empaquetado, más **tres
workflows GitHub Actions** orientados al ciclo de release. **No tienen un
workflow de CI matriz que ejecute los tests contra varias versiones de Moodle/PHP**.
`mod_exelearning` debe heredar lo bueno y añadir esa pieza ausente.

## Hechos citados

### Makefile (idéntico en ambos plugins)

Targets observados (`grep -E '^[a-z_-]+:'`):

```
check-docker, check-env
up, upd, down, pull, build, shell, clean        # Docker harness
install-deps                                    # Composer
lint, fix, phpmd                                # Calidad
test, behat                                     # Tests (PHPUnit + Behat)
check-bun, fetch-editor-source,
build-editor, build-editor-no-update,
clean-editor                                    # Sub-app eXeLearning editor
package                                         # Release ZIP
help
```

Notas:
- Hace `make build-editor` que clona eXeLearning desde
  `EXELEARNING_EDITOR_REPO_URL` (default `github.com/exelearning/exelearning.git`)
  en `EXELEARNING_EDITOR_REF` (default `main`).
- `make package RELEASE=<v>` produce `mod_<plugin>-<version>.zip` listo para upload.
- `make test` y `make behat` corren dentro del contenedor Docker pre-existente,
  contra **una única versión** del stack (la que esté arriba localmente). No hay
  matriz.

### GitHub workflows existentes

`.github/workflows/`:

| Workflow | Disparador | Propósito |
|---|---|---|
| `release.yml` | `release.published` + `workflow_dispatch` | Compila el editor + `make package` + adjunta ZIP al release |
| `check-editor-releases.yml` | `cron 0 8 * * *` + `workflow_dispatch` | Detecta nuevo release de eXeLearning y rebuilda el editor |
| `pr-playground-preview.yml` | PR | Despliega preview |

**Ausente**: workflow `ci.yml` (o equivalente) con matriz Moodle × PHP que ejecute
`make lint`, `make phpmd`, `make test`, `make behat` en cada push/PR contra:
- Moodle 4.1 LTS · 4.3 · 4.5 LTS · main (futuro).
- PHP 8.1 · 8.2 · 8.3 · 8.4.
- Bases de datos: PostgreSQL · MariaDB.

Esta combinación es **estándar** en el ecosistema Moodle vía
[`moodle-plugin-ci`](https://moodlehq.github.io/moodle-plugin-ci/).

## [INTERPRETACION]

- Heredar el Makefile + los 3 workflows actuales es razonable: están probados y
  funcionan para `mod_exescorm`/`mod_exeweb` (también nuestros).
- El hueco a llenar es el **workflow de CI matriz**. Es la pieza que da garantías
  de calidad pre-release.
- Adicionalmente conviene añadir un workflow `codeql.yml` (análisis de seguridad
  estático) y/o `dependabot.yml`.

## Recomendaciones (entrada de DEC-0-04)

1. Reutilizar el Makefile (targets idénticos: `up/down/lint/fix/phpmd/test/behat/
   build-editor/package`). Adaptar nombre del componente.
2. Heredar los 3 workflows actuales (`release.yml`, `check-editor-releases.yml`,
   `pr-playground-preview.yml`).
3. Añadir **`ci.yml`** con `moodle-plugin-ci` y matriz Moodle × PHP × DB.
4. Añadir **`codeql.yml`** y **`dependabot.yml`**.
5. Añadir **fixtures de ELPX** (ya copiadas en `research/fixtures/`) consumibles
   por los tests Behat.
6. Documentar en `README.md` del plugin los comandos `make` principales y los
   badges de CI/release/coverage.

## [PENDIENTE]

- TAREA-006: redactar `ci.yml` concreto.
- Decidir versiones mínimas de Moodle (¿4.1 LTS o 4.3?) y máximas a soportar.
- Decidir si los Behat tests usan Moodle main como canary (en `continue-on-error`).
