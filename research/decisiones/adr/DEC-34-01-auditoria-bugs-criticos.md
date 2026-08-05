---
id: DEC-34-01
title: "Auditoría de bugs críticos (workflow multi-agente) y correcciones por temas"
status: Accepted
date: 2026-06-10
tracking_issue: 34
legacy_id: DEC-0044
deciders:
  - erseco
  - claude-code
sources:
  - REPO-004
related:
  adrs: [DEC-12-01, DEC-13-03, DEC-13-07, DEC-19-01, DEC-25-01, DEC-26-02, DEC-34-02]
ai_assistance:
  tool: claude-code
  model: claude-fable-5
---

# DEC-34-01: Auditoría de bugs críticos (workflow multi-agente) y correcciones por temas

## Contexto

A raíz de un **informe técnico comparativo** (`mod_exescorm` vs `mod_exeweb` vs
`mod_exelearning`, aportado por el usuario), se solicitó (a) atajar la deuda técnica
señalada para subir la puntuación de mantenibilidad y (b) **cazar todos los bugs
críticos** del plugin. Para (b) se ejecutó un **workflow multi-agente** (40 agentes,
7 dimensiones de riesgo: entradas web, pipeline de calificación, manejo de paquetes,
API externa, ciclo de vida de datos, editor/admin, runtime PHP/Moodle) con
**verificación adversarial de 3 jueces independientes por hallazgo** (voto mayoritario).

Resultado: **9 hallazgos confirmados** (1 crítico + 8 altos) y **2 rechazados**. Como
uno de los confirmados (B1) es **pérdida de datos irrecuperable**, se decidió
**corregir los críticos antes** de promocionar la madurez del plugin
(`MATURITY_ALPHA` → `MATURITY_BETA`).

Verificación previa: varias carencias que el informe marcó "no verificadas" (Privacy
API, Capability API, External API, matriz CI) **ya estaban resueltas** y no requirieron
código; el auditor no pudo abrir esos ficheros. La deuda nº1 del informe (reescritura
del HTML del paquete) es real y se aborda por separado en [[DEC-34-02]] (diferida).

## Hallazgos confirmados y correcciones

Identificadores B1–B8 usados en los commits/PRs.

### Crítico

- **B1 — Guardar ajustes tras "Editar con eXe" destruía el `.elpx` fuente.**
  `editor/save.php` guarda el paquete en `itemid=revision` y borra el `itemid 0`; pero
  `mod_form::data_preprocessing()` sembraba el draft del filemanager **solo** desde
  `itemid 0` → draft vacío. Al guardar ajustes,
  `exelearning_save_and_extract_package()` hacía `delete_area_files(...,'package')`
  (todos los itemids) y guardaba el draft vacío → revisión ya incrementada, sin
  contenido, self-heal desactivado → `packagenotfound` para los alumnos y paquete
  irrecuperable.
  **Fix (dos capas):** `data_preprocessing()` siembra el draft desde el itemid real
  (`exelearning_get_stored_package()->get_itemid()`); `save_and_extract_package()` no
  wipea cuando el draft entrante no trae fichero pero existe paquete almacenado
  (re-extrae a la revisión actual). Se descartó una "limpieza" explícita del paquete vía
  filemanager: no es un flujo soportado (DEC-13-03 cubre crear-desde-cero, no vaciar).

### Altos

- **B2 — Cambiar `grademodel`/`grademethod` borraba todas las notas publicadas.**
  `exelearning_sync_grade_items()` borra y recrea vacías las columnas del libro al
  cambiar de modelo; `recalculate_user_grades` solo se invocaba al borrar un intento, así
  que las notas desaparecían hasta que el alumno reenviaba (los intentos seguían en
  `exelearning_attempt`, que graba **tanto** la fila overall como las per-item en cada
  ingest, independientemente del modelo).
  **Fix:** republicar desde el historial cuando cambia modelo/método
  (`exelearning_update_grades`). Una **re-subida de paquete NO recalcula** (se mantiene
  la semántica snapshot+aviso de [[DEC-12-01]]).

- **B2b — Faltaba `exelearning_update_grades()` (contrato del gradebook).**
  Core `grade_update_mod_grades()` exige el par `grade_item_update`/`update_grades`; sin
  el segundo, el reset de gradebook, `grade_grab_course_grades()` y el unlock nunca
  repoblaban las notas (y core emitía "broken behaviour").
  **Fix:** `exelearning_update_grades($exe, $userid=0)` republica desde
  `exelearning_attempt` (respeta `gradeenabled=0`). **Follow-up (revisión
  adversarial):** completar el par **activa** `grade_update_mod_grades()`, que invoca
  `exelearning_grade_item_update()` incondicionalmente en cada regrade de core; esa
  función creaba el overall (itemnumber 0) siempre → reintroducía el item fantasma de
  B3 en `peritem` (y en actividades no calificables). Se hizo
  `exelearning_grade_item_update()` consciente del modelo: solo crea/actualiza el 0 en
  `overall` y graded; en otro caso borra el sobrante.

- **B3 — El reset de curso creaba grade items fantasma.**
  `exelearning_reset_gradebook()` iteraba `0..MAX(itemnumber)` con
  `grade_update(['reset'])`; core solo cortocircuita el item ausente con `'deleted'`, no
  con `'reset'`, así que **insertaba** una columna vacía de 100 pts por cada itemnumber
  sin item vivo (el overall en `peritem`, los per-item en `overall`, los soft-deleted).
  **Fix:** resetear solo los items existentes vía `grade_item::fetch_all([...])`.

- **B5 — Nombre de grade item sin recortar → excepción DML fatal.**
  `name` = nombre actividad (≤255) + `pageName` (de `content.xml`, ilimitado) + tipo,
  escrito a `char(255)` sin clamp; estallaba en add/update/editor-save y en el self-heal
  de `view.php` (pantalla en blanco a un **alumno**).
  **Fix:** `core_text::substr` recorta `name`→255, `idevicetype`→64,
  `objectid`/`pageid`→191 antes de escribir.

- **B6 — `save_track` (WS) convertía commits sin score en intentos reales de 0.**
  `scoreraw` con `VALUE_DEFAULT 0` siempre inyectaba `cmi.core.score.raw`, burlando el
  guard noop de `track::ingest()`; arrastraba GRADE_LAST/AVERAGE y quemaba `maxattempt`
  (la vía web `track.php` sí hacía noop con el mismo payload).
  **Fix:** `scoreraw` nullable (`VALUE_DEFAULT null` + `NULL_ALLOWED`) y omitir
  `cmi.core.score.raw` cuando es null → aplica el guard noop existente ([[DEC-26-02]]).

- **B7 — La finalización "recibir nota" no se podía guardar desde el formulario.**
  El mapeo de 101 itemnumbers (`gradeitems::MAX_ITEMNUMBER`) hace que core
  `moodleform_mod::validation()` exija campos `grade_ideviceN`/`assessed_ideviceN` que el
  form no define → `badcompletiongradeitemnumber` (clave `completionpassgrade`) **siempre**
  → la finalización por nota ([[DEC-25-01]]) solo era alcanzable por CLI.
  **Fix (stopgap):** limpiar ese error concreto cuando "exigir nota para aprobar" está
  **desactivado** y el itemnumber elegido es una columna real (per-iDevice registrada, o
  el overall en modo `overall`). El caso "exigir nota para aprobar" requiere un
  `core_grades\local\gradeitem\fieldname_mapping` y queda como **fix propio diferido**.

- **B8 — XSS almacenado en la cabecera del deep-link del informe.**
  `report.php` renderizaba `$OUTPUT->heading(fullname($filtereduser), 4)` sin escapar;
  `heading()` usa `html_writer::tag()` (no escapa) y los nombres vía LDAP/SAML/WS/CSV no
  garantizan strip de tags → XSS en la sesión del profesor/corrector con
  `mod/exelearning:viewreport`.
  **Fix:** envolver en `s()` (igual que la línea 245 del mismo fichero ya hacía).

## Hallazgos rechazados por los jueces (no se tocan)

- **gradepass nunca se asigna a items per-iDevice** → "aprobar para completar" se
  satisface con cualquier nota (incluido 0%): **inalcanzable hoy** porque B7 bloquea
  configurar esa finalización desde el form. A revisar si, al implementar el
  `fieldname_mapping` (el fix propio de B7 diferido), procede asignar `gradepass`
  per-item.
- **`sessiontoken` 255 vs `char(40)`**: el único productor es `random_string(20)` (20
  chars); sin cliente externo real que envíe tokens largos → no reproducible.

## Decisión

1. Corregir los 9 hallazgos confirmados, agrupados en **3 PRs por tema**:
   - *grade-pipeline integrity* (B2, B2b, B3, B5, B6, B7),
   - *data preservation* (B1, B4 — backup de `gradeenabled`/`gradecat`, ver nota),
   - *report XSS* (B8).
2. Cada fix lleva su **test de regresión** (PHPUnit; B8 reusa el patrón `s()` existente).
3. **Promocionar a `MATURITY_BETA` solo tras** corregir y verificar los críticos/altos.

> Nota: B4 (el backup omitía `gradeenabled`/`gradecat`, reactivando la calificación en
> actividades deliberadamente no calificables al restaurar/duplicar) se documenta aquí
> por pertenecer a la misma auditoría aunque no apareciera con etiqueta B-numérica en el
> resumen del workflow; se corrige junto a B1 en el PR de *data preservation*.

## Consecuencias

- Integridad del libro de calificaciones reforzada (sin pérdidas en cambios de
  modelo/método, sin columnas fantasma, contrato `update_grades` completo).
- Robustez ante paquetes adversariales/largos (sin fatales DML; el self-heal ya no puede
  romper la vista de un alumno).
- Superficie WS endurecida (B6) y XSS cerrado (B8).
- Limitación conocida documentada: "exigir nota para aprobar" desde el form sigue
  pendiente del `fieldname_mapping` (fix propio de B7).
- Verificación: `phpcs --standard=moodle` 0/0 en todos los ficheros tocados; revisión
  adversarial multi-agente del diff completo; la suite PHPUnit y la matriz CI (PHP
  8.1–8.4 × Moodle 4.5–5.2 × pgsql/mariadb) son la verificación de ejecución autoritativa.
