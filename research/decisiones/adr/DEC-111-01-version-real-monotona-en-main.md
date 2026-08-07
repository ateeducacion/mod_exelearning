---
id: DEC-111-01
title: "Versión Moodle real y monótona en main: fin del centinela; releases reproducibles y retorno automático a desarrollo"
status: Accepted
date: 2026-07-24
tracking_issue: 111
legacy_id: DEC-0068
supersedes: DEC-13-08
deciders:
  - erseco
  - amanzano3ip
  - claude-code
sources:
  - REPO-004
related:
  adrs: [DEC-13-08, DEC-78-01, DEC-106-01]
ai_assistance:
  tool: claude-code
  model: claude-fable-5
---

# DEC-111-01: Versión Moodle real y monótona en main: fin del centinela; releases reproducibles y retorno automático a desarrollo

> Supersede a [DEC-13-08](DEC-13-08-version-sentinela-en-main.md).

## Contexto

[[DEC-13-08]] introdujo el centinela `$plugin->version = 9999999999` / `release = 'dev'` en
`main`, con la versión real estampada por `make package` a partir de la **fecha del
runner** (`date +%Y%m%d`). El objetivo era legítimo — dejar de subir la versión a mano en
cada PR — pero la validación externa de la release 4.0.2 (DIR-03) y el PR 100 de
amanzano3ip destaparon el coste real:

1. **El centinela máximo brickea instalaciones**: quien instala desde el checkout (clone o
   "Download ZIP", vías reales porque la ficha del Marketplace enlaza el repo) registra
   `9999999999` en su base de datos; toda release futura es menor y Moodle la rechaza como
   downgrade, sin recuperación desde el producto.
2. **Un centinela bajo tampoco vale** (`0`, `1`, `99999`…): `$plugin->version` participa
   del protocolo de instalación/upgrade — con savepoints ya en `2026072400`, una versión
   inferior deja el esquema "en el futuro" respecto del código. Por el mismo motivo, el
   `2026070701` propuesto originalmente en el PR 100 era conceptualmente inválido: menor
   que el último savepoint existente.
3. **El estampado por fecha rompe la reproducibilidad**: reconstruir el mismo tag otro día
   produce un `version.php` distinto, contra la garantía de build reproducible que
   [[DEC-78-01]] y [[DEC-106-01]] persiguen para el editor.
4. **Soporte a ciegas**: `9999999999` no delata qué código corre un sitio.

Tras adoptar versiones reales surgió una segunda fricción: el flujo inicial exigía dos
PRs por release (preparación y vuelta a `dev`) y el watcher diario solo sincronizaba el
pin del editor. Eso mantenía la reproducibilidad, pero hacía manual una secuencia que es
mecánica y repetible.

## Decisión

1. **`main` lleva siempre una versión Moodle real y monótona** (`YYYYMMDDXX`). El marcador
   de desarrollo vive en `$plugin->release = 'dev'`, que es informativo y no participa
   del protocolo de upgrade; nunca en `$plugin->version`.
2. **La versión se incrementa cuando Moodle deba detectar un cambio**: `db/`, `classes/`,
   JS (fuente o builds), settings, strings, tareas, capabilities, servicios externos y
   demás metadatos sensibles a caché. Siempre estrictamente mayor que la última release
   publicada y que todo `upgrade_mod_savepoint()` / guard `$oldversion <` de
   `db/upgrade.php`.
3. **El empaquetado valida y nunca muta**: `scripts/package.sh` no calcula la versión por
   fecha ni reescribe `version.php`; el ZIP lleva el `version.php` **committeado, byte a
   byte**. El tag de una release contiene exactamente los metadatos que se distribuyen.
4. **El watcher del editor prepara la release, no la publica directamente**. Cuando
   `exelearning/exelearning` publica `vX.Y.Z`, el workflow diario abre un único PR de
   preparación que actualiza conjuntamente `.editor-version`, el pin de Moodle
   Playground y `version.php` con una versión Moodle real y `$plugin->release = 'X.Y.Z'`.
   La publicación sigue requiriendo revisión humana y merge de ese PR.
5. **El merge del PR de preparación dispara la publicación por contenido, no por cierre
   de PR**. `release.yml` escucha únicamente pushes a `main` que cambien
   `.editor-version`; así los PRs no relacionados no generan ejecuciones `skipped`. El
   workflow valida los metadatos, crea `vX.Y.Z` sobre ese commit exacto, construye el
   editor desde el tag homónimo, genera el ZIP reproducible y publica la GitHub Release.
6. **El mismo workflow retorna `main` a desarrollo tras publicar correctamente**. Una vez
   creados tag, ZIP y GitHub Release, actualiza solo `version.php` en `main`: incrementa
   `$plugin->version` al siguiente valor real válido y cambia `$plugin->release` a
   `'dev'`. Valida ese estado antes del push. El tag permanece apuntando al commit de
   release y no se modifica.
7. **No hay bucle de release**: el commit post-release no cambia `.editor-version`, de
   modo que no satisface el filtro del workflow de publicación. El commit se denomina
   `Start development after vX.Y.Z` y representa explícitamente el comienzo del siguiente
   ciclo de desarrollo.
8. **Guard ejecutable**: `scripts/check-version.sh` (con self-test en CI) rechaza
   centinelas en ambas direcciones, exige 10 dígitos con estructura de fecha plausible,
   impone la cota de savepoints (estricta en dev, `>=` en release), y en release exige
   `release` = tag sin la `v`. `make package` depende de esa validación.

## Consecuencias

- Positivas: instalar desde el repo deja de brickear sitios; dos builds del mismo tag son
  idénticos también en `version.php`; el número instalado delata el código; cada release
  pasa por un PR revisable; la publicación y el retorno a desarrollo son automáticos.
- El historial queda explícito: el commit taggeado representa la release y el siguiente
  commit de `main` representa el nuevo estado `dev` con una versión Moodle superior.
- Los PRs ordinarios no provocan ejecuciones omitidas del workflow Release porque el
  trigger depende del cambio de `.editor-version` en `main`.
- Para contribuidores: un PR que toque algo que Moodle deba detectar debe subir
  `$plugin->version`; el estado normal de `main` tras cada publicación vuelve a ser
  `release = 'dev'` automáticamente.
- Si la protección de `main` impide el push del commit post-release, la release ya
  publicada sigue siendo válida e inmutable, pero el fallo debe resolverse antes de
  continuar el desarrollo para restaurar `main` a `dev`.
- Los sitios que instalaron el centinela `9999999999` siguen atascados hasta intervención
  manual; esta decisión evita crear nuevos casos.

## Alternativas consideradas

| Alternativa | Por qué se rechaza |
|---|---|
| Mantener DEC-13-08 tal cual | Brickea instalaciones reales y produce builds no reproducibles. |
| Centinela bajo (`0`, `1`, `99999`, `000001`) | Rompe el protocolo de upgrade en la otra dirección: versión < savepoints existentes. |
| Estampar `version.php` durante el empaquetado | El tag no contendría los mismos metadatos que el ZIP y reconstruirlo dejaría de ser una operación verificable. |
| Publicar directamente desde el watcher sin PR | Elimina la revisión humana justo en el punto que fija los metadatos finales y el contenido que se va a etiquetar. |
| Escuchar `pull_request: closed` y filtrar con `if` | Crea una ejecución `Release` omitida por cada PR cerrado que no sea una preparación de release. |
| Segundo PR automático para volver a `dev` | Añade ruido y otro ciclo de CI para una mutación completamente mecánica que puede validarse y aplicarse al final del mismo workflow. |
