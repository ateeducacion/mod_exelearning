---
id: DEC-6-01
titulo: "Recálculo del overall desde itemscores (cierre residuo RIE-007) + hardening menor"
estado: Aceptada
fecha: 2026-06-01
tracking_issue: 6
legacy_id: DEC-0018
agentes:
  - erseco
  - claude-code
fuentes:
  - REPO-005
  - FTE-006
  - FTE-008
relacionados:
  - DEC-5-01
  - DEC-4-01
  - DEC-0008
  - DEC-0007
herramienta_ia:
  interfaz: claude-code
  modelo: claude-opus-4-8
---

# DEC-6-01: Recálculo del overall desde itemscores (cierre residuo RIE-007) + hardening menor

## Contexto

DEC-5-01 resolvió RIE-007 para el **per-iDevice** en paquetes multipágina (ruteo por
`objectid` estable), pero dejó documentado un **residuo**: la nota **global**
(`itemnumber=0`) se sigue derivando de `cmi.core.score.raw` (`getFinalScore` del
productor vendorado), que **queda corrupto** bajo la colisión multipágina de
`cmi.suspend_data` (ver `track.php`, antes del `record_item` de itemnumber=0). El
shim de `view.php` ya captura `itemscores` (mapa `objectid → {scorepct, weighted}`),
así que el servidor dispone de los datos para recomputar el overall sin tocar código
vendorado (DEC-0002) ni el esquema.

Una auditoría del código real tras DEC-5-01 identificó además un cluster de
**hardening menor** de bajo riesgo, y reconfirmó dos ítems que se **difieren**
explícitamente.

## Problema

1. **Overall corrupto en multipágina (residuo RIE-007).** En colisión, dos iDevices
   de páginas distintas comparten el `N` local de página; `getFinalScore()` del
   productor agrega sobre el `suspend_data` ya colisionado, por lo que
   `cmi.core.score.raw` es incorrecto. El per-iDevice ya se corrige (DEC-5-01), pero
   la columna overall no.
2. **Robustez de entradas.** `track.php` no acotaba el tamaño de `itemscores` ni la
   longitud de `sessiontoken`; el parser de `suspend_data` (PHP y JS) sólo aceptaba
   punto decimal, fallando en locales con coma (es_ES/fr_FR/de_DE) y cayendo al
   fallback con un `debugging()`.
3. **Validación de rangos de nota.** `mod_form.php` no validaba `grademin <= grademax`
   ni `gradepass` dentro del rango, dejando configuraciones que invierten el clamp de
   `track.php` o hacen inalcanzable la finalización por nota.

## Decisión

### 1. Recálculo del overall desde `itemscores` (cierra el residuo RIE-007)

Nuevo helper testeable `\mod_exelearning\local\track::recompute_overall_pct(array): ?float`:
media **ponderada** de `scorepct` (0..100) por el campo `weighted` de cada entrada;
si todos los pesos son 0, **media simple**; ignora entradas malformadas; `null` si no
hay items usables. En `track.php`, cuando el shim envió `itemscores`, el overall pasa
a `(recompute_overall_pct / 100) * grademax` (re-clampado a `grademin..grademax`) en
lugar de `cmi.core.score.raw`. Sin mapa de objectids se mantiene el camino CMI
(legacy / single-page).

- **No altera el single-page verificado:** sin colisión, la media ponderada de los
  per-item coincide con el `getFinalScore` del productor. Se emite
  `debugging(DEBUG_DEVELOPER)` cuando recomputado y CMI divergen >0.01, para observar
  una colisión productor↔plugin sin cambiar lo que se persiste cuando coinciden.
- **Limitación:** la equivalencia con `getFinalScore` se asume sobre el modelo
  ponderado de eXeLearning y se valida vía PHPUnit (consistencia interna) + behat/e2e
  (paridad real con el productor), no por inspección del vendorado.

### 2. Hardening menor

- **Decimales con coma** en ambos parsers (`track.php::parse_suspend_data` y el
  `parseSuspend` inline de `view.php`): regex `([\d.,]+)` + normalización coma→punto,
  manteniendo **paridad PHP↔JS** (`view.php` es JS inline, NO AMD → sin `grunt amd`).
- **Tope de `itemscores`** (`track.php`): si `count() > 1000` se ignora el mapa con
  `debugging()` (guard DoS barato; el fallback `suspend_data` sigue disponible).
- **Longitud de `sessiontoken`** (`track.php`): `substr(..., 0, 255)` tras `clean_param`.
- **Validación en `mod_form.php`**: nuevo `validation()` que exige `grademin <= grademax`
  y `0 <= gradepass <= grademax` (strings `err_grademinmax`, `err_gradepassrange`).

## Evidencia

- Overall derivado del CMI corrupto: `track.php` (`$score` → `record_item(…, 0, …)`).
- `itemscores` disponible cliente: `view.php` (`captureItemScores`, `send`).
- `record_item` es **upsert** por `(exelearningid, userid, attempt, itemnumber)`
  (`classes/local/attempts.php`): reenviar el mapa acumulado en cada autocommit es
  idempotente — descarta el supuesto "replay/doble conteo".
- Productor `N` local de página y formato `suspend_data`: DEC-5-01 (REPO-005, FTE-008).

## Consecuencias

- Positivas: cierra el residuo de RIE-007 (overall correcto en multipágina); soporte
  de locales con coma; menos superficie de entrada abusiva; configuraciones de nota
  inválidas se rechazan en el formulario. Cero cambios vendorados, sin migración de
  esquema.
- Negativas / coste: el overall en multipágina depende ahora del modelo ponderado del
  recálculo (validado por tests, no por el vendorado). Si una versión futura del
  productor cambia la fórmula de `getFinalScore`, el `debugging()` de divergencia lo
  hará visible.

## Riesgos

- **RIE-007 — residuo CERRADO** por este ADR (overall multipágina).

## Diferidos (documentados, NO se tocan ahora)

- **Guard de origen en el puente legacy (DEC-4-01 #10).** Confirmado: en
  `amd/src/editor_modal.js`, `handleLegacyBridgeMessage()` NO valida
  `event.source`/`event.origin` (el handler moderno sí). Una ventana maliciosa puede
  enviar `{source:'exeweb-editor', type:'editor-ready', packageUrl}` para sobreescribir
  `session.packageUrl` (→ `fetch` con credenciales) o `type:'request-save'`. **No se
  corrige aquí** porque tocar `amd/src/*.js` obliga a regenerar `amd/build/*.min.js`
  con `grunt amd`, no disponible en este entorno (sí en CI). Severidad media,
  probabilidad baja (requiere embeber/abrir la vista del editor desde un origen
  hostil). Seguimiento: RIE-010.
- **TOCTOU de `maxattempt`** (`track.php`): entre el conteo de intentos y el primer
  `record_item` de una sesión nueva, dos page-loads concurrentes podrían exceder el
  límite por un intento. Un cierre robusto requiere lock/constraint a nivel de esquema;
  impacto real bajo (un alumno abriendo cargas en paralelo). Seguimiento: RIE-011.

## Validación

- PHPUnit `tests/track_test.php`: `recompute_overall_pct` (media ponderada, media
  simple con pesos 0, clamp, entradas malformadas, `null`); overall correcto en el
  caso de colisión multipágina; parser acepta coma decimal.
- `node --check` + ejecución del `parseSuspend` extraído de `view.php` con entrada de
  coma: salida `{scorepct:60.5, weighted:12.5}` (paridad con el PHP).
- `php -l` en verde en todos los ficheros tocados.
- Suite completa y phpcs (Moodle Code Checker) delegados a CI (moodle-plugin-ci,
  matriz Moodle 4.5/5.0/5.1 × PHP 8.1–8.4 × mariadb/pgsql).
- e2e opcional (chrome-devtools / manual): paquete multipágina real, puntuar en dos
  páginas, confirmar que la columna overall del libro muestra el agregado correcto.

## Seguimiento

- RIE-010: regenerar `amd/build/editor_modal.min.js` con `grunt amd` y aplicar el guard
  de origen al puente legacy (DEC-4-01 #10).
- RIE-011: endurecer `maxattempt` con un mecanismo a nivel de esquema (constraint/lock).
- RIE-008 (DEC-4-01): pinning de checksum/firma del ZIP del editor — independiente.

## Revisión 2026-06-01 — RIE-010 mitigado

TAREA-008 cierra el diferido de esta ADR para el puente legacy `postMessage`:
`amd/src/editor_modal.js` centraliza la validación de `event.source` y
`event.origin` en `isEditorBridgeMessage()` y ambos handlers (`handleBridgeMessage`
y `handleLegacyBridgeMessage`) la aplican antes de leer el payload.

Evidencia local:

- Moodle Developer Resources 5.2 consultado con Context7
  (`/websites/moodledev_io_5_2`, consulta: "How should a Moodle plugin build AMD
  JavaScript modules locally with grunt amd..."): los módulos AMD de un plugin se
  compilan de `amd/src` a `amd/build`, y los ficheros generados deben versionarse.
- Build ejecutado en Moodle 5.2beta local (`../moodle`, commit `bf96e85aa`) con
  Node 22.22.3 y `grunt amd --root=public/mod/exelearning`. Se usó una copia
  temporal real del plugin bajo `public/mod/exelearning` porque un symlink hace
  que Moodle resuelva el path real fuera del árbol y ESLint pierda el override AMD.
- Verificación sintáctica posterior: `node --check` sobre `amd/src/editor_modal.js`
  y `amd/build/editor_modal.min.js`.

## Revisión 2026-06-01 — RIE-011 aceptado (paridad con core)

TAREA-009 cierra el diferido del TOCTOU de `maxattempt` **aceptando el riesgo**, tras
comparar la gestión de intentos del core de Moodle (`mod/scorm`, `mod/h5pactivity`).
Hallazgo: **ninguno de los dos protege este TOCTOU**, y `mod_exelearning` ya está al
nivel de `h5pactivity` y por encima de `scorm`.

- **`mod_scorm`**: tabla `scorm_attempt` **SIN UNIQUE** (`mod/scorm/db/install.xml`),
  número de intento por `MAX(attempt)` leído en memoria
  (`mod/scorm/locallib.php`, `scorm_get_last_attempt()`) e inserción sin lock ni
  transacción (`mod/scorm/locallib.php`, `scorm_get_attempt()` con `create=true`).
  El límite `maxattempt` se valida **solo en la UI** del player
  (`mod/scorm/lib.php`, `scorm_check_mode()`; `mod/scorm/player.php`). Es decir,
  más débil que `mod_exelearning`: puede duplicar el mismo número y exceder el límite.
- **`mod_h5pactivity`**: `attempt = count_records + 1` sin lock ni transacción
  (`mod/h5pactivity/classes/local/attempt.php`, `new_attempt()`), confiando
  **únicamente** en el UNIQUE `(h5pactivityid, userid, attempt)` como red; si dos
  cargas calculan el mismo número, la segunda `INSERT` lanza `dml_write_exception`.
  No tiene concepto de `maxattempt`.
- **`mod_exelearning`**: ya tiene el UNIQUE `(exelearningid, userid, attempt,
  itemnumber)` (`db/install.xml`) **más** la validación de `maxattempt` en
  `track.php` (líneas 156-175). Iguala o supera el estándar del ecosistema.

El UNIQUE evita duplicar el mismo `(attempt, itemnumber)`, pero **no** el caso de
exceder con números de intento distintos en dos sesiones nuevas concurrentes del
mismo alumno. Cerrar ese hueco requiere serializar check+create. La opción correcta
sería un lock `\core\lock\lock_config::get_lock_factory('mod_exelearning_maxattempt')`
por recurso `"user:{userid}:exe:{id}"`, **confinado a la rama `!sessionknown`** de
`track.php` (recount + recheck + primer `record_item` dentro del lock; HTTP 409 si no
se obtiene). Su coste sería **despreciable** —se adquiere ~1 vez por intento, no por
autocommit, y en MariaDB/PostgreSQL (matriz CI, DEC-0004) el factory `auto` usa locks
nativos `GET_LOCK`/advisory in-memory, no el fallback lento `db_record`/`file` que
motiva la advertencia de rendimiento de la Lock API.

**Decisión (erseco + claude-code):** **NO se implementa el lock**. Riesgo
**baja/baja** (requiere el mismo alumno con dos cargas en paralelo, en el límite
exacto de intentos; daño máximo = un intento de más), paridad con core ya alcanzada.
Queda como **mitigación futura opcional** si surge un caso de uso intensivo (p. ej.
exámenes masivos reintentando justo en el límite). RIE-011 pasa a estado `aceptado`.
