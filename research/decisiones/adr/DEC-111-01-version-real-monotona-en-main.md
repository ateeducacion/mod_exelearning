---
id: DEC-111-01
titulo: "Versión Moodle real y monótona en main: fin del centinela; el empaquetado valida y nunca reescribe"
estado: Aceptada
fecha: 2026-07-24
tracking_issue: 111
legacy_id: DEC-0068
supersede: DEC-13-08
agentes:
  - erseco
  - amanzano3ip
  - claude-code
fuentes:
  - REPO-004
relacionados:
  - DEC-13-08
  - DEC-78-01
  - DEC-106-01
herramienta_ia:
  interfaz: claude-code
  modelo: claude-fable-5
---

# DEC-111-01: Versión Moodle real y monótona en main: fin del centinela; el empaquetado valida y nunca reescribe

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

## Decisión

1. **`main` lleva siempre una versión Moodle real y monótona** (`YYYYMMDDXX`). En el
   momento del cambio: `2026072401` — el mínimo válido, estrictamente mayor que el
   savepoint más alto (`2026072400`). **El marcador de desarrollo vive en
   `$plugin->release = 'dev'`**, que es informativo y no participa del protocolo de
   upgrade; nunca en `$plugin->version`.
2. **La versión se incrementa cuando Moodle deba detectar un cambio**: `db/`, `classes/`,
   JS (fuente o builds), settings, strings, tareas, capabilities, servicios externos y
   demás metadatos sensibles a caché. Siempre estrictamente mayor que la última release
   publicada y que todo `upgrade_mod_savepoint()` / guard `$oldversion <` de
   `db/upgrade.php`.
3. **El empaquetado valida y nunca muta**: `scripts/package.sh` deja de calcular versión
   por fecha y de reescribir `version.php` en el índice temporal — el ZIP lleva el
   `version.php` **committeado, byte a byte** (lo asserta `check-package.sh`). El resto
   del índice temporal (editor en `dist/static/`, `thirdpartylibs.xml` del paquete,
   marcadores `~`, `.distignore`, `git archive`) no cambia.
4. **Flujo de release en orden estricto**: (1) PR de preparación que committea versión
   final + release semver en `version.php` → (2) merge → (3) tag sobre ese commit exacto
   → (4) build y publicación del ZIP desde el tag → (5) PR de vuelta a desarrollo
   (`release = 'dev'`, versión al siguiente valor válido). Los workflows **no** empujan a
   `main` ni modifican `version.php` tras crear el tag; `release.yml` verifica que el
   commit chequeado es el taggeado, que `release` no es `dev` y que casa con el tag.
5. **Guard ejecutable**: `scripts/check-version.sh` (con self-test en CI) rechaza
   centinelas en ambas direcciones, exige 10 dígitos con estructura de fecha plausible,
   impone la cota de savepoints (estricta en dev, `>=` en release), y en release exige
   `release` = tag sin la `v`. `make package` depende de esa validación.
6. El workflow diario del editor deja de crear releases automáticas y de empujar a
   `main`: ahora abre un **PR de sincronización** del pin (`.editor-version` +
   blueprint); la release del plugin es siempre una decisión humana con el flujo anterior.

## Consecuencias

- Positivas: instalar desde el repo deja de brickear sitios (el caso que el PR 100
  diagnosticó correctamente); dos builds del mismo tag son idénticos también en
  `version.php`; el número instalado delata el código; el flujo de release queda auditable
  (metadatos committeados antes del tag, workflows solo-lectura).
- Para contribuidores: un PR que toque algo que Moodle deba detectar **debe subir
  `$plugin->version`** (la CI lo recuerda vía guard cuando la cota de savepoints lo
  fuerce; para el resto es disciplina documentada en DEVELOPMENT.md).
- Para quien gestiona releases: dos PRs por release (preparación y vuelta a dev) a cambio
  de reproducibilidad y de no tener nunca un tag cuyo contenido no exista en `main`.
- Los sitios que instalaron el centinela `9999999999` siguen atascados hasta intervención
  manual (documentado; este cambio evita crear nuevos casos).

## Alternativas consideradas

| Alternativa | Por qué se rechaza |
|---|---|
| Mantener DEC-13-08 tal cual | Brickea instalaciones reales y produce builds no reproducibles; DIR-03 lo señala y el PR 100 lo demostró en su propio sitio de pruebas. |
| Centinela bajo (`0`, `1`, `99999`, `000001`) | Rompe el protocolo de upgrade en la otra dirección: versión < savepoints existentes. |
| El `2026070701` del PR 100 | Diagnóstico correcto, valor inválido: menor que el savepoint `2026072400` ya presente. |
| Estampar en el workflow en vez de en package.sh | Misma mutación con otro uniforme: el tag seguiría sin contener su propia versión y la reproducibilidad seguiría dependiendo de la fecha de ejecución. |
| Versión derivada del tag al empaquetar | El commit taggeado no contendría su versión real; imposible verificar el tag contra su contenido, y quien instala desde el checkout sigue sin versión válida. |
