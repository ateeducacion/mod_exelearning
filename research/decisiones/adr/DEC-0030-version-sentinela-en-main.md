---
id: DEC-0030
titulo: "Versión 'sentinela' (9999999999/dev) en main; la versión real la inyecta make package (issue #13)"
estado: Superseded
fecha: 2026-06-03
agentes:
  - erseco
  - claude-code
fuentes:
  - REPO-001
  - REPO-004
relacionados:
  - DEC-0004
  - DEC-0068
herramienta_ia:
  interfaz: claude-code
  modelo: claude-opus-4-8
---

> **Superseded por [DEC-0068](DEC-0068-version-real-monotona-en-main.md) (2026-07-24).**
> El centinela `9999999999` brickeaba las instalaciones hechas desde el checkout (toda
> release futura es un downgrade) y el estampado por fecha hacía irreproducible el build
> del mismo tag. `main` pasa a llevar una versión real y monótona; el empaquetado valida y
> nunca reescribe `version.php`. Se conservan intactos el contexto y la decisión
> originales de abajo.

## Contexto

Hasta ahora `version.php` llevaba en el árbol una versión real con fecha (`$plugin->version` =
2026060xxx) y un `release` semver-dev (`0.x.0-dev`), que había que **subir a mano en cada PR**.
Esto provoca: (a) churn y conflictos de `version.php` entre PRs (en especial los apilados, p.ej.
#15 sobre #14), y (b) una versión "real" en `main` que en realidad nunca se distribuye así.

El hermano **mod_exescorm** (REPO-001) resolvió esto con una **versión sentinela** en `main`:
`$plugin->version = 9999999999;` y `$plugin->release = 'dev';`. La versión real (con fecha) y el
semver se **inyectan solo al empaquetar**.

Hallazgo clave: **mod_exelearning ya tiene exactamente la misma maquinaria** (heredada, DEC-0004):

- `Makefile` target `package:` — `make package RELEASE=x.y.z` pone
  `$plugin->version = $(date +%Y%m%d)00` y `$plugin->release = x.y.z`, crea el ZIP y **restaura
  `9999999999` / `dev`** en el árbol (líneas ~197-214).
- `.github/workflows/release.yml` — saca `RELEASE` del tag de git y llama a `make package`.
- `.distignore` presente.

Es decir, el Makefile **ya esperaba** el sentinela (lo restaura tras cada empaquetado), pero el
árbol llevaba versiones reales → estaba desalineado.

## Decisión

Adoptar el sentinela en `main`, idéntico a mod_exescorm:

- `$plugin->version = 9999999999;`
- `$plugin->release = 'dev';`

La versión real con fecha (`YYYYMMDDXX`) y el semver salen del **tag de git** vía `make package`
/ `release.yml`; nunca se commitean a mano. No se tocan `requires`, `supported`, `component` ni
`maturity`. **`upgrade.php` no cambia**: sus umbrales (p.ej. `2026060400` de `gradeenabled`,
DEC-0029) siguen siendo fechas; un release sellado con fecha ≥ umbral dispara la etapa, y el
sentinela (9999999999 > cualquier fecha instalada) dispara el upgrade al actualizar desde un
release previo.

Valores **confirmados con el usuario** (se descartó `99999`/`0.0.0`, que el propio Makefile
reescribiría a `9999999999`/`dev`, dejando el árbol desalineado de nuevo).

## Consecuencias

**A favor**

- Sin churn de `version.php` por PR; se acaban los conflictos de versión entre PRs apilados (#15
  deja de necesitar re-bump).
- La versión publicada es **única fuente de verdad** = el tag de git (semver) + la fecha de build.
- El árbol queda **alineado** con lo que el Makefile ya restauraba; consistencia con
  mod_exescorm / mod_exeweb.

**En contra / riesgos**

- Un desarrollador que ejecute desde un checkout de `main` **no recibe auto-upgrades de esquema**
  (Moodle no dispara `upgrade.php` porque 9999999999 no es `>` 9999999999): para probar un cambio
  de esquema en dev hay que reinstalar el plugin o bajar la versión a mano temporalmente. Mitiga:
  la CI instala en limpio (usa `install.xml`), así que los tests no se ven afectados.
- El sentinela **nunca debe distribuirse** a un sitio real; depende de `make package` (que lo
  sustituye por la fecha), ya garantizado por `release.yml`. Instalar `mod_exelearning` desde un
  clon de git en producción no está soportado (igual que en mod_exescorm).

## Implementación

`version.php` (`version = 9999999999`, `release = 'dev'`, con comentarios que apuntan a
`make package`). Sin cambios en `Makefile`, `release.yml`, `.distignore` (ya correctos) ni en
`db/upgrade.php`.
