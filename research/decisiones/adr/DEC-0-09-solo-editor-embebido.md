---
id: DEC-0-09
tracking_issue: 0
legacy_id: DEC-0009
title: "Sólo modo editor embebido. Sin integración eXeLearning Online"
status: Accepted
date: 2026-05-28
deciders:
  - erseco
  - claude-code
sources:
  - REPO-002
  - REPO-005
experiments: []
ai_assistance:
  tool: claude-code
  model: claude-opus-4-7
supersedes: DEC-0-05
---

# DEC-0-09: Sólo modo editor embebido. Sin integración eXeLearning Online

## Contexto

`DEC-0-05` (Aceptada) heredaba la maquinaria del editor de `mod_exeweb` con
los **dos** modos que ese plugin soporta: editor embebido (estática compilada
con Bun) y eXeLearning Online (servicio remoto autenticado con HMAC).

Tras revisar la UX de admin (un toggle + un select + un campo URL + una clave
HMAC eran 4 settings interrelacionados), el mantenedor (erseco) decide
**simplificar a un único modo: el editor embebido**. Razones:

- El modo Online requiere infraestructura externa (instancia de eXeLearning
  Online + claves de firma) que la mayoría de despliegues de `mod_exelearning`
  no van a tener.
- Mantener dos rutas paralelas (`editor/index.php` redirige a un sitio externo
  vs sirve la estática local) duplica testing.
- El editor embebido ya cubre 100 % de las funcionalidades de autoría: editar
  un paquete subido y volver a guardarlo en Moodle.

## Problema

¿Mantenemos los dos modos o nos quedamos solo con embebido?

## Opciones consideradas

1. **Mantener ambos** (statu quo de DEC-0-05).
2. **Sólo embebido** (recomendada).
3. Sólo Online (descartada por dependencia externa obligatoria).

## Evidencia

- `mod_exeweb/settings.php`: ofrece ambos pero la mayoría de instalaciones
  reales que ATE ha implantado usan embebido (REPO-002).
- El editor embebido ya está completamente portado en
  `mod_exelearning/dist/static/` (178 MB compilados con Bun, accesibles vía
  `editor/static.php`).
- La página `Manage embedded editor` (clases
  `mod_exelearning\external\manage_embedded_editor` y
  `mod_exelearning\local\embedded_editor_installer`) ya permite descargar el
  editor desde GitHub Releases o subirlo manualmente si no está bundleado.

## Decisión

Adoptar **opción 2**. `mod_exelearning` ofrece **sólo el modo embebido**.

### Cambios en `settings.php`

- Eliminar `editormode` (select embedded/online).
- Eliminar `exeonlinebaseuri` (URL del servicio).
- Eliminar `hmackey1` (clave HMAC).
- Eliminar `tokenexpiration` (TTL del token Online).
- Eliminar `providername` / `providerversion` (anuncio del provider).
- Conservar `embeddededitor` (toggle on/off del editor).
- Conservar (futuro) `template` / `sendtemplate` (plantilla por defecto del
  editor, ver DEC-0-05).
- Conservar (futuro) `mandatoryfileslist` / `forbiddenfileslist` (validación
  de paquetes subidos).

### Comportamiento del toggle `embeddededitor`

| Estado del toggle | Editor instalado | UX |
|---|---|---|
| OFF | — | El botón "Edit with eXeLearning" no aparece en la actividad. |
| ON | sí (bundled o admin-installed) | El botón abre el modal con el editor embebido. |
| ON | no | El admin ve un aviso "Editor not installed" con un enlace a `Manage embedded editor` para descargarlo desde GitHub o subirlo. |

### Página `Manage embedded editor`

Conservar idéntica a `mod_exeweb`. Permite al admin:
- Ver el estado actual del editor (no instalado / bundled / admin-installed).
- Descargar la última release del editor desde
  `github.com/exelearning/exelearning/releases` (vía
  `mod_exelearning\external\manage_embedded_editor::install_from_github`).
- Subir un ZIP del editor a mano.
- Eliminar el editor instalado en moodledata (vuelve al bundled si existe).
- Configurar **temas/plantillas** del editor (igual que `mod_exeweb`):
  estilos personalizados, plantilla por defecto al crear actividad.

Capability `mod/exelearning:manageembeddededitor` (ya declarada).

### Cambios en `lang/en/exelearning.php`

- Eliminar strings: `editormode`, `editormode_*`, `exeonline_*`,
  `exeonline:*`, `editor_provider*`, `tokenexpiration*`.
- Conservar: `embeddededitor*`, `manage_*`, `download_*`, `install_*`.

### Cambios en `.env.dist`

- Eliminar `EXELEARNING_EDITOR_REPO_URL`, `EXELEARNING_EDITOR_DEFAULT_BRANCH`,
  `EXELEARNING_EDITOR_REF`, `EXELEARNING_EDITOR_REF_TYPE` SI no se usan en
  ninguna parte. **Conservarlos** porque el `Makefile` (`make build-editor`)
  los necesita para clonar el editor desde GitHub y compilar el bundle local.
- Eliminar `APP_PORT` / `APP_SECRET` si sólo se usaban para el editor Online.
  Tras revisión: `APP_PORT` se sigue usando para el puerto del propio Moodle
  → CONSERVAR. `APP_SECRET` queda huérfano → ELIMINAR.

### Cambios en `docker-compose.yml`

`POST_CONFIGURE_COMMANDS` ya invoca:

```bash
php admin/cli/cfg.php --component=exelearning --name=exeonlinebaseuri --set=...
php admin/cli/cfg.php --component=exelearning --name=hmackey1 --set=...
```

Estos dos `cfg.php` calls deben **eliminarse** porque las settings ya no
existen y darían warnings al arrancar el contenedor.

### Cambios en `README.md`

Sección _Configuration_:

- Antes: 4 settings (embeddededitor + editormode + exeonlinebaseuri +
  hmackey1).
- Después: 1 setting (`embeddededitor`) + enlace a la página _Manage embedded
  editor_ para instalación/actualización/templates.

## Consecuencias

Positivas:
- UX de admin más simple (1 toggle vs 4 settings entrelazados).
- Menos código y testing.
- Documentación coherente con el caso de uso real.

Negativas:
- Cierra la puerta a integraciones con eXeLearning Online por la vía actual.
  Si en el futuro surge la necesidad, se puede reabrir con un ADR superseder
  que reintroduzca el modo Online como **plugin separado**
  (`local_exelearningonline` o similar), no como ramificación dentro de
  `mod_exelearning`.

## Riesgos

- Si una organización tiene actualmente una instancia de eXeLearning Online
  en producción y esperaba conectarse, queda obligada a migrar al editor
  embebido. Mitigación: documentar en `CHANGELOG.md` cuando llegue la
  primera release.

## Validación

- `settings.php` con sólo el toggle compila + el plugin instala sin coding
  errors.
- `Site administration > Plugins > Activity modules > eXeLearning resource`
  muestra sólo "Embedded eXeLearning editor" + enlace a "Manage embedded
  editor".
- Con toggle ON y editor instalado: botón "Edit" en la actividad funciona.
- Con toggle ON y editor no instalado: aviso + enlace a la página de gestión.
- Con toggle OFF: botón "Edit" oculto.

## Seguimiento

- Tras aplicar esta decisión, marcar `DEC-0-05` como **Superseded** por
  `DEC-0-09` en `status.yaml`.
- TAREA-031 (próxima sesión si no se cierra ahora): pulir la página de gestión
  con i18n completa y un POC de "descargar release de GitHub".

## Confirmación (2026-05-29) por DEC-0-13

DEC-0-09 se tomó por simplicidad de UX de admin. **DEC-0-13 lo confirma** con el
análisis de fondo que faltaba (autenticación HMAC, flujo ida/vuelta, desconexión
/ ubicación de datos, permisos + co-edición). Decisión vigente: **solo embebido**.
La eventual reapertura (si surge co-edición real y GDPR lo permite) iría por la
opción D de DEC-0-13 (enlace a Online sin integrar datos), no por reintroducir el
modo Online con HMAC. Ver DEC-0-13 para el detalle.
