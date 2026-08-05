---
id: DEC-77-01
title: "Extracción de paquete no-destructiva: validar la nueva revisión antes de podar la anterior (issue 73)"
status: Accepted
date: 2026-06-13
tracking_issue: 77
legacy_id: DEC-0057
deciders:
  - erseco
  - claude-code
sources:
  - REPO-004
related:
  adrs: [DEC-71-01, DEC-34-01, DEC-13-08, DEC-72-01]
ai_assistance:
  tool: claude-code
  model: claude-opus-4-8
---

# DEC-77-01: Extracción de paquete no-destructiva: validar la nueva revisión antes de podar la anterior (issue 73)

## Contexto

`package_manager::extract_stored()` re-extraía el `.elpx`/`.zip` almacenado a `content/{revision}/`
en cada reemplazo (formulario, editor embebido, self-heal de `view.php`, migración). El guard central
de [[DEC-72-01]] (#3, RIE-019) hace que un paquete sin `index.html` servible falle ruidosamente con
`migrateextractfailed` — bueno —, pero el flujo **borraba el `content` extraído anterior ANTES de
validar el reemplazo**: `delete_area_files(ctx, 'mod_exelearning', 'content')` **sin itemid** vaciaba
TODAS las revisiones, y la validación de `index.html` ocurría después. Resultado: un reemplazo corrupto
fallaba, pero el último contenido válido ya estaba destruido.

El esquema canónico de Moodle (contador `revision` + filearea por revisión, como `mod_scorm`) **ya
existía** (`db/install.xml`, `lib.php` scheme `package`/`content/{revision}`) pero **no se respetaba**.
Además, `exelearning_update_instance()` hacía `$DB->update_record` (puntero de revisión nuevo) **antes**
de la extracción y **sin transacción**, de modo que el puntero en BD avanzaba aunque la extracción
fallara.

**Caso peor — editor embebido (`editor/save.php`), irrecuperable:** guardaba el ZIP nuevo en
`package/{newrev}` → commit `revision=newrev` en BD → **borraba el package antiguo** → `extract_stored`
vaciaba todo `content` y fallaba → el `catch` borraba el package nuevo. Estado final: **sin package,
sin content, revisión incrementada → actividad rota e irrecuperable**. El editor **no pre-valida
`content.xml`** (a diferencia de `mod_form.php`), así que un export truncado pasaba directo.

Probabilidad baja (el formulario pre-valida `content.xml`; el editor suele generar ZIPs válidos) pero
impacto alto (pérdida irrecuperable). Es el último hueco conocido de pérdida de datos del plugin.

## Decisión

Adoptar el patrón **"stage → validate → swap"**: cada reemplazo estaciona la nueva revisión (paquete +
contenido) en su **nuevo itemid**, la valida, y **solo en éxito** avanza el puntero de BD y poda la
revisión anterior. Ante cualquier fallo se elimina lo estacionado y la revisión anterior queda **intacta
y servible**. Reutiliza la infraestructura `content/{revision}/` ya presente y alinea el filearea con el
avance del puntero en BD.

| Pieza | Cambio |
|---|---|
| `package_manager::extract_stored()` | Borra **solo** `content/{revision}/` (con itemid), no todas las revisiones; idempotente para el self-heal. Tras el guard de `index.html`, ante fallo **limpia su propia revisión parcial** y lanza, dejando las hermanas intactas. |
| `package_manager::save_and_extract()` | Estaciona el ZIP del formulario en `package/{revision}/` (no en itemid 0); ante fallo de extracción borra el itemid estacionado y relanza (el package previo sigue siendo el más reciente). Conserva el safety-net B1 [[DEC-34-01]]. |
| `package_manager::store_and_activate_revision()` (nuevo) | Orquestador del editor: `extract_stored(newrev)` → en éxito `update_record(revision=newrev)` → poda `content`/`package` superseded; en fallo no avanza el puntero. |
| `package_manager::prune_content_revisions()` / `prune_package_revisions()` (nuevos) | Borran todo itemid salvo el que se conserva. Se invocan **tras** mover el puntero (sin ventana de 404 para lectores concurrentes). |
| `lib.php::exelearning_update_instance()` | Reorden *extraer-validar → commit → podar*. La poda solo corre si la nueva revisión produjo `index.html` servible (un update programático sin paquete deriva al self-heal). |
| `editor/save.php` | Reorden vía `store_and_activate_revision`; el `catch` borra el package estacionado. Recuperable. |
| `migration/migration_service::install_package()` | Estaciona en `package/{revision}/` por simetría; el target es nuevo, `migrate_one` ya hace rollback si lanza. |

**Nota de madurez:** cerrado este último hueco de pérdida de datos, se promueve
`version.php` `$plugin->maturity` de `MATURITY_BETA` a `MATURITY_STABLE`. Es consecuencia de esta
decisión, no independiente; `version`/`requires`/`supported` no cambian (el `version` sigue siendo el
sentinel `9999999999` de dev, [[DEC-13-08]]).

## Consecuencias

- Positivas: un reemplazo corrupto deja **intacto y servible** el último contenido válido en TODAS las
  vías; el editor pasa de irrecuperable a recuperable; el puntero de revisión en BD solo avanza tras una
  extracción validada (consistencia filearea↔BD); el disco sigue acotado (poda a la revisión activa).
- Coste: el paquete del formulario pasa de itemid 0 a `itemid=revision` (lecturas ya itemid-agnósticas
  por B1 [[DEC-34-01]]); un test de migración que asumía itemid 0 se actualizó a `revision`.

## Riesgos

- RIE-019 (de [[DEC-72-01]]): pasa de **mitigado** ("nuestros fixtures tienen index.html") a **resuelto**:
  el fallo ruidoso ya no es destructivo: la revisión previa se preserva por diseño, no por suerte del
  fixture.
- Un update programático sin campo `package` no extrae y deja el puntero adelantado hasta el self-heal de
  `view.php` (comportamiento previo); por eso la poda se condiciona a que exista `content/{revision}/index.html`.

## Validación

- `phpcs --standard=moodle` 0/0 sobre `package_manager.php`, `lib.php`, `editor/save.php`,
  `migration_service.php`, `version.php` y los tests.
- PHPUnit local (entorno Docker `make test`, [[project-mod-exelearning-phpunit-local]] ya superado):
  **263 tests verdes** en toda la superficie tocada y adyacente — `package_manager_extract_test` (motor +
  `store_and_activate`: corrupto/sin-index preservan la revisión previa, swap+poda en éxito, self-heal
  idempotente), `lib_test` (formulario: corrupto mantiene revisión y contenido; válido avanza y poda),
  `migration_service_test`, backup/restore, grades/track/eventos/external, scorm-injector, etc.
- Tests nuevos: 6 en `package_manager_extract_test.php`, 2 en `lib_test.php`.

## Seguimiento

- Cierra el issue 73. Pendientes mayores siguen siendo TAREA-015 (xAPI) y TAREA-016 (origen por URL +
  sync), independientes de esta decisión.
