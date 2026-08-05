---
id: DEC-18-01
title: "Actualización de contenido: reemplazo del paquete .elpx y origen por URL con sincronización"
status: Proposed
date: 2026-06-04
tracking_issue: 18
legacy_id: DEC-0033
deciders:
  - erseco
  - claude-code
sources:
  - REPO-004
  - REPO-MOODLE-SCORM
  - REPO-EXE-V4
related:
  adrs: [DEC-0-16, DEC-12-01, DEC-13-03, DEC-16-01, DEC-13-08]
ai_assistance:
  tool: claude-code
  model: claude-opus-4-8
---

# DEC-18-01: Actualización de contenido: reemplazo del paquete .elpx y origen por URL con sincronización

## Contexto

El plugin guarda el paquete subido en el filearea `package/0/` y lo extrae a
`content/{revision}/` (`lib.php:561` `exelearning_save_and_extract_package()` →
`exelearning_extract_stored_package()`). El paquete entra **siempre** por el
`filemanager` del formulario (`mod_form.php:54`, `accepted_types => ['.elpx','.zip']`,
DEC-16-01) y se acepta cualquier paquete v4 ODE 2.0 (con `content.xml`).

Surgen dos necesidades del usuario, ambas sobre **mantener actualizado** el contenido
de una actividad ya creada:

1. **Reemplazo de un `.elpx`**: poder sustituir el paquete de una actividad existente
   para actualizar su contenido. El usuario duda de si esto ya está contemplado.
2. **Origen por URL**: poder añadir/actualizar el `.elpx` **desde una URL** (o
   mecanismo similar), y decidir si conviene un campo URL propio o el sistema nativo
   de Moodle de "abrir desde URL" (el *file picker* / drawer de ficheros), y si la
   actualización debe ser **manual (botón "Actualizar")** o **automática**.

Esta ADR investiga cómo lo resuelve el core de Moodle (`mod_scorm`), qué ofrece el
*File API* / repositorios de Moodle, y qué expone eXeLearning v4, para documentar las
opciones y recomendar una decisión. **Metodología**: 3 investigaciones paralelas sobre
fuentes oficiales (código `mod_scorm`, docs *File/Repository API* de Moodle, y
docs/REST de `github.com/exelearning/exelearning`) verificando rutas y nombres.

## Problema

### P1 — Reemplazo del paquete

¿Está soportado sustituir el paquete de una actividad existente? ¿Qué pasa con las
notas/intentos previos al reemplazar?

**Hallazgo: ya está soportado.** `exelearning_update_instance()` (`lib.php:132`) se
ejecuta al editar la actividad y al guardar:

- recalcula `revision = revision + 1` (`lib.php:137`),
- vuelve a guardar y extraer el paquete subido (`lib.php:169`,
  `exelearning_save_and_extract_package()` — que **borra** el filearea `package`
  anterior y re-extrae a `content/{nueva-revision}/`),
- re-sincroniza los grade items (`exelearning_sync_grade_items()`),
- avisa si el cambio deja notas obsoletas (`exelearning_warn_if_grades_stale()`,
  DEC-12-01).

Es decir: **subir un paquete nuevo en el formulario de edición YA reemplaza el
contenido**. La incógnita real no es "si se puede", sino: (a) hacerlo **explícito y
visible** (hoy es un efecto lateral de re-subir en el `filemanager`), y (b) confirmar
el comportamiento frente a intentos previos (no se recalculan; se avisa, DEC-12-01).

### P2 — Origen por URL y su actualización

¿Cuál es la mejor vía para alimentar/actualizar el paquete desde una URL? ¿Campo URL
propio vs. *file picker* de Moodle? ¿Actualización manual vs. automática?

## Opciones consideradas

### Bloque A — Reemplazo (P1)

- **A1. Statu quo (re-subida en el formulario de edición).** Ya funciona. Coste cero.
  Inconveniente: poco descubrible; el profesor puede no saber que re-subir reemplaza.
- **A2. Acción explícita "Reemplazar paquete"** en `view.php` (botón) que abra el
  formulario o un `filepicker` dedicado, reutilizando exactamente el mismo pipeline
  (`save_and_extract` + `sync_grade_items` + `warn_if_grades_stale`). Mejora la
  descubribilidad sin tocar el motor.
- **A3. Versionado de paquetes** (conservar `content/{rev}` antiguos como historial
  navegable). Sobreingeniería para la necesidad actual; `revision` ya existe sólo para
  *cache-busting* y el filearea viejo se borra. Descartada por coste/beneficio.

### Bloque B — Origen por URL (P2)

- **B1. *File picker* nativo de Moodle ("abrir desde URL", `repository_url`).**
  **No viable.** El repositorio *URL downloader* declara
  `supported_filetypes() => ['web_image']` (`repository/url/lib.php`), grupo **disjunto**
  de `archive`/`zip`; con `accepted_types => ['.elpx','.zip']` el filtro por mimetype de
  `repository::get_instances()` lo **oculta por completo** (no aparece en el drawer).
  Y aunque apareciera, `repository_url` sólo soporta `FILE_INTERNAL|FILE_EXTERNAL`
  (no `FILE_REFERENCE`): haría una **copia estática de una sola vez**, sin
  sincronización. Moodle **no** tiene mecanismo de re-descarga para ficheros añadidos
  por el *picker* URL. Las referencias vivas (`FILE_REFERENCE`/`FILE_CONTROLLED_LINK`)
  sólo las dan repos como `filesystem`/cloud, no una URL `http` arbitraria. Conclusión:
  el *picker* sirve para subir, no para "origen URL sincronizable".

- **B2. Campo "URL externa" propio, patrón `mod_scorm` (RECOMENDADA).** Replicar el
  modelo probado del core:
  - Selector de **origen del paquete** (`packagesource`: *Subir* | *URL externa*),
    análogo a `scormtype` (`SCORM_TYPE_LOCAL` / `SCORM_TYPE_LOCALSYNC`).
  - Campo de texto `packageurl` cuya URL se guarda en una **columna nueva
    `reference`** (paridad con `scorm.reference`).
  - Descarga server-side a `package/0/` con
    `$fs->create_file_from_url($filerecord, $reference, ['calctimeout'=>true], true)`
    y extracción con el packer ZIP — **reutilizando el pipeline existente**
    (`exelearning_extract_stored_package()`), igual que `scorm_parse()` para
    `SCORM_TYPE_LOCALSYNC` (descarga el ZIP y lo extrae a `mod_scorm/content`).
  - **Gating por hash**: comparar `contenthash` del fichero descargado con el último
    guardado; sólo re-extraer si cambió (evita trabajo y *revision bumps* inútiles),
    como hace `scorm_parse` con `sha1hash`.

- **B3. Referencia externa "viva" sin descarga (estilo `mod_url`/`SCORM_TYPE_EXTERNAL`).**
  No aplica: el contenido eXe debe servirse **localmente** desde `pluginfile.php` (y
  bajo el sandbox del iframe, DEC-0-16); no podemos enlazar a un `.elpx` remoto sin
  extraerlo. Descartada.

### Bloque C — Cadencia de actualización (P2, sobre B2)

- **C1. Sólo manual: botón "Actualizar ahora"** en `view.php` (cap. profesor) que
  re-descarga y re-extrae bajo demanda. Es lo que a `mod_scorm` le **falta** (no tiene
  botón; sólo refresca al guardar, por cron o por vista). Control total, sin tráfico
  de fondo, sin sorpresas con intentos.
- **C2. `updatefreq` estilo `mod_scorm`**: *Nunca* / *Diaria* (tarea programada) /
  *En cada vista*. Constantes `SCORM_UPDATE_NEVER|EVERYDAY|EVERYTIME`; *Diaria* vía
  `\mod_scorm\task\cron_task` (cada 5 min, auto-limitada a 1×/día); *EVERYTIME* re-parsea
  en cada `view`. Default del core: **Nunca**.
- **C3. Sólo al guardar el formulario** (como hoy para subida): se re-descarga cuando
  el profesor reedita y guarda. Mínimo, pero no "mantiene actualizado" por sí solo.

## Evidencia

- **REPO-004** (este repo): pipeline de subida/extracción/reemplazo
  `lib.php:132` (`update_instance`, `revision++`, re-extrae, avisa stale),
  `lib.php:561` (`save_and_extract_package`, borra+re-extrae),
  `lib.php:649` (`extract_stored_package`, idempotente),
  `mod_form.php:54-66` (`filemanager`, `accepted_types`), DEC-12-01 (notas obsoletas),
  DEC-16-01 (validación `content.xml`). El esquema `exelearning` (`db/install.xml`)
  **no** tiene columna `reference` ni hay `db/tasks.php` (no hay tareas programadas).
- **REPO-MOODLE-SCORM** (`github.com/moodle/moodle`, rutas `public/mod/scorm/`):
  `lib.php` constantes `SCORM_TYPE_LOCAL|LOCALSYNC|EXTERNAL|AICCURL`; `mod_form.php`
  selector `scormtype` + campos `packageurl`/`packagefile`, mapeo a `scorm.reference`;
  `locallib.php` constantes `SCORM_UPDATE_NEVER='0'|EVERYDAY='2'|EVERYTIME='3'`,
  `scorm_parse()` (LOCALSYNC: `create_file_from_url()` + `extract_to_storage()`, gating
  por `sha1hash`), `scorm_check_url()` (HEAD HTTP 200), gates EVERYTIME en
  `scorm_print_launch`/`scorm_simple_play`; `db/tasks.php` (`*/5`) +
  `classes/task/cron_task.php` → `scorm_cron_scheduled_task()` (1×/día, sólo
  `updatefreq==EVERYDAY`); `settings.php` `updatefreq` default `0` y
  `allowtypeexternal|localsync|externalaicc` default `0` (admin opt-in);
  `lib/filelib.php` `download_file_content()`/`create_file_from_url()` usan la clase
  `curl` con `\core\files\curl_security_helper` (bloqueo de hosts/puertos,
  `curlsecurityblockedhosts`/`curlsecurityallowedport`, rechazo de esquemas no http(s),
  límite de redirecciones). **No existe botón "actualizar ahora" en `mod_scorm`.**
- **REPO-EXE-V4** (`github.com/exelearning/exelearning`, docs):
  `.elpx` = ZIP con `content.xml` obligatorio (`doc/elpx-format/container.md`);
  endpoint de exportación **autenticado** `GET /api/v1/projects/:uuid/export/elpx`
  (Bearer JWT) — la vía soportada para sincronizar (`doc/development/rest-api.md`,
  `authentication.md`); **no hay permalink público/anónimo** para descargar el último
  `.elpx`; **no hay versionado de proyecto** (sólo `updatedAt`), así que la detección
  de cambios debe basarse en `updatedAt` o en hash del contenido descargado.

## Decisión

### D1 (P1 — Reemplazo): confirmar y hacer explícito

El reemplazo **ya está soportado** (A1) y no requiere cambios de motor. Se adopta
además **A2**: documentarlo en la guía de usuario y añadir una acción visible
"Reemplazar paquete" en `view.php` (cap. profesor) que reutilice el pipeline existente.
Se mantiene el comportamiento de DEC-12-01 (no se recalculan notas previas; se avisa).
Se descarta el versionado (A3).

### D2 (P2 — Origen por URL): campo propio estilo `mod_scorm` (B2), **no** el picker

Se **descarta B1** (el *file picker* URL no es viable: oculto para zip y sin
sincronización) y **B3** (el contenido debe servirse local). Se adopta **B2**: selector
de origen `packagesource` (*Subir* | *URL externa*) + campo `packageurl` persistido en
nueva columna `reference`, con descarga server-side (`create_file_from_url`) al filearea
`package` y **reutilización del pipeline de extracción/sync existente**, con **gating por
`contenthash`**. La descarga usa la clase `curl` de Moodle (hereda
`curl_security_helper`) y se valida la URL antes de guardar (HEAD 200 + esquema http(s)).
**El origen URL queda desactivado por defecto a nivel de sitio** (ajuste de admin
`allowexternalurl`, opt-in), igual que `mod_scorm`.

### D3 (P2 — Cadencia): manual primero, automática opcional

Decisión por fases:

- **Fase 1 (recomendada para el primer PR de implementación): C1 + C3.** Botón
  **"Actualizar ahora"** en `view.php` (la mejora que a `mod_scorm` le falta) + refresco
  al guardar el formulario. Da control total y es lo más simple y predecible. La
  actualización automática queda como **opt-in** explícito.
- **Fase 2 (opcional): C2 (`updatefreq`)** *Nunca* (default) / *Diaria* / *En cada
  vista*, con `db/tasks.php` + `\mod_exelearning\task\update_external_packages` (paridad
  `mod_scorm`). Sólo si hay demanda real de sincronización desatendida.

Se evita *EVERYTIME* como default por coste (descarga/parseo en cada vista) y por su
interacción con intentos en curso.

**Nota sobre eXeLearning v4**: como su endpoint de exportación exige Bearer JWT y no hay
permalink anónimo, el campo `packageurl` simple sólo sirve para URLs **públicamente
descargables** (un `.elpx` en un servidor web, enlace público de Nextcloud, etc.). Para
tirar directamente del REST de eXe v4 haría falta un **token/cabecera de autorización
opcional** — se deja explícitamente para Fase 2 (no bloquea la decisión).

## Consecuencias

- Positivas:
  - Reemplazo: sin cambios de motor; sólo descubribilidad + documentación.
  - URL: patrón probado del core, reutiliza extracción/sync/aviso de notas existentes;
    seguridad heredada de `curl_security_helper`.
  - "Actualizar ahora" cubre el hueco real de `mod_scorm` (refresco bajo demanda).
- Negativas / coste:
  - Nueva columna `reference` (+`db/upgrade.php`, paridad backup/restore) y, en Fase 2,
    `updatefreq` + `db/tasks.php`.
  - Validación/seguridad de URL remota (SSRF) — mitigada por `curl_security_helper` y
    opt-in de admin; debe quedar **desactivado por defecto**.
  - Reemplazar por URL hereda el aviso de notas obsoletas (DEC-12-01): un cambio remoto
    del paquete puede alterar iDevices calificables sin recalcular notas previas.
- Dispara/relaciona: extiende DEC-13-03/DEC-16-01 (vías de entrada del paquete); reusa
  DEC-12-01 (notas obsoletas); el contenido descargado sigue bajo el sandbox de DEC-0-16;
  el `revision` (cache-busting, DEC-13-08/`db/install.xml`) se incrementa sólo si el hash
  cambió.

## Riesgos

- **RIE-URL-1 (SSRF / fetch remoto)**: una URL apuntando a la red interna. Mitigación:
  clase `curl` + `curl_security_helper` (hosts/puertos bloqueados), sólo http(s),
  opt-in de admin desactivado por defecto.
- **RIE-URL-2 (paquete remoto inválido/malicioso)**: reutilizar la validación
  `exelearning_package_has_content_xml()` (DEC-16-01) tras descargar; rechazar si falta
  `content.xml`. Contenido servido bajo sandbox (DEC-0-16).
- **RIE-URL-3 (notas obsoletas tras refresco automático)**: un cambio remoto recalifica
  iDevices sin recomputar intentos previos. Mitigación: aviso DEC-12-01 + automático
  como opt-in (no default).
- **RIE-EXE-1 (auth eXe v4)**: el REST de eXe exige JWT; un campo URL simple no llega.
  Mitigación: documentar que la URL debe ser públicamente descargable; token opcional
  en Fase 2.

## Validación

- Reemplazo (D1): test de que re-subir/`update_instance` incrementa `revision`,
  re-extrae a `content/{rev}` y dispara el aviso stale (ya cubierto por el pipeline;
  añadir aserción explícita si falta).
- URL (D2): test unitario con `create_file_from_url` apuntando a un `.elpx` servido en
  fixture local → se guarda en `package`, pasa `has_content_xml`, extrae igual que una
  subida; un ZIP sin `content.xml` se rechaza; gating por `contenthash` no re-extrae si
  no cambia.
- Seguridad: test de que una URL a host bloqueado falla vía `curl_security_helper`.
- Cadencia (D3 Fase 2): test de la tarea programada que sólo procesa instancias con
  origen URL y `updatefreq==EVERYDAY`.

## Seguimiento

- Abre TAREA: PR de implementación Fase 1 (selector `packagesource` + `packageurl` +
  columna `reference` + descarga `create_file_from_url` + botón "Actualizar ahora" +
  ajuste admin `allowexternalurl` opt-in + tests + lang + backup/restore + `db/upgrade`).
- Abre TAREA (opcional Fase 2): `updatefreq` + `db/tasks.php` +
  `\mod_exelearning\task\update_external_packages` + token/cabecera opcional para el
  REST de eXeLearning v4.
- Cierra la duda del usuario sobre el reemplazo: **sí está contemplado** (A1), se hará
  más visible (A2).

## Resolución de alcance (erseco, 2026-06-17)

**Solo Fase 1.** Se implementará (TAREA-016): reemplazo **descubrible** (botón "Actualizar ahora", ya
soportado por `update_instance`) + **origen por URL externa detrás de un toggle de admin `allowexternalurl`
(opt-in, default off)**, con refresco **manual** (patrón B2 de este ADR). La **Fase 2** (auto-sync
`updatefreq` + `db/tasks.php` + token para el REST de eXeLearning v4) queda **descartada por ahora**; se
reabre solo si hay demanda. El ADR sigue `Propuesta` hasta implementar Fase 1.
