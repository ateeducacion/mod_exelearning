---
id: DEC-67-01
title: "Auditoría estándar de repositorio (2026-06-11): 9 mejoras implementadas + hallazgos descartados"
status: Accepted
date: 2026-06-12
tracking_issue: 67
legacy_id: DEC-0049
deciders:
  - erseco
  - claude-code
sources:
  - REPO-004
related:
  adrs: [DEC-4-01, DEC-34-01, DEC-26-01, DEC-12-01, DEC-0-07, DEC-25-01, DEC-11-01, DEC-18-01, DEC-17-01, DEC-0-04]
ai_assistance:
  tool: claude-code
  model: claude-opus-4-8
---

# DEC-67-01: Auditoría estándar de repositorio (2026-06-11): 9 mejoras implementadas + hallazgos descartados

## Contexto

Tercera auditoría del repositorio, esta vez de **profundidad estándar y alcance
completo** (correctitud, seguridad, rendimiento, tests, deuda técnica, dependencias,
DX, docs, dirección), realizada el 2026-06-11 sobre el commit `568487b` mediante el
*improve skill*. Se apoya en las dos auditorías ya registradas: [[DEC-4-01]] (seguridad)
y [[DEC-34-01]] (bugs críticos).

Los planes de ejecución vivieron en la rama de trabajo `improve/audit-plans-2026-06-11`
(carpeta `plans/`, **no se publica**). Este ADR extrae de esos planes el **qué y el
porqué** para que el registro perdure en `research/`, ya que el código de cada mejora se
fusionó por separado.

**Alcance auditado:** todo el plugin salvo lo que es de terceros o build: `vendor/`,
`exelearning/` (checkout del editor), `dist/static/` (build del editor), `assets/scorm/`
(pipwerks de terceros), `amd/build/`, el contenido de `research/` más allá del
cruce con ADRs, y el carril e2e Behat-vs-navegador real (decisión deliberada del
mantenedor; ver cabecera del `.feature`).

## Decisión

Implementar **9 mejoras** (prioridades P1–P3), cada una en su PR, con tests y CI verde.
Todas fusionadas a `main` el 2026-06-12.

| Plan | Mejora | Pri | PR | Relacionada |
|------|--------|-----|----|-------------|
| 001 | Endurecer el parseo de `config.xml` de estilos (quitar `LIBXML_NOENT`) | P1 | #46 | [[DEC-26-01]] |
| 002 | Declarar el editor empaquetado en el `thirdpartylibs.xml` del ZIP de release | P1 | #47 | — |
| 003 | Fidelidad de backup/restore (`contenthash` + usuarios no mapeables) | P1 | #48 | [[DEC-12-01]] |
| 004 | Serializar la asignación de intentos (lock de concurrencia) | P2 | #49 | [[DEC-0-07]] |
| 005 | La participación del profesor respeta el `grademethod` | P2 | #50 | [[DEC-0-07]] |
| 006 | Recálculo masivo de notas en lote (matar el N+1) | P2 | #51 | [[DEC-25-01]] |
| 007 | Extracción ZIP compartida y endurecida (`zip_utils` + barrido) | P2 | #52 | [[DEC-4-01]] |
| 008 | Descarga del informe de intentos (`\core\dataformat`) | P3 | #53 | [[DEC-0-07]] |
| 009 | Behat: borrado de intento + separate-groups | P3 | #54 | — |

### Detalle (qué y por qué)

- **001 / #46 — `config.xml` de estilos.** `styles_service::parse_config_xml()` parseaba
  con `LIBXML_NOENT`, que pese a su nombre **habilita** la sustitución de entidades
  (billion-laughs, lectura de ficheros locales). Superficie solo-admin, pero incoherente
  con el parser endurecido de `content.xml` ([[DEC-26-01]]). Fix: quitar `NOENT` + rechazar
  `DOCTYPE`/`ENTITY` antes de parsear + 2 tests.
- **002 / #47 — `thirdpartylibs.xml`.** El ZIP de release empaqueta el editor en
  `dist/static/` pero no lo declaraba (la propia cabecera del fichero decía que "debería
  re-declararse en el artefacto"). `scripts/package.sh` ahora estampa la declaración
  `dist/static` solo en el índice del ZIP (árbol de trabajo intacto, para no romper
  `moodle-plugin-ci install`), con licencia `AGPL-3.0-or-later`. Solo build, cero runtime.
- **003 / #48 — backup/restore.** (1) `exelearning_grade_item.contenthash` (respalda el
  aviso de notas obsoletas, [[DEC-12-01]]) no se respaldaba → se restauraba a NULL; añadido
  al backup. (2) Un `userid` de intento no mapeable se insertaba como userid 0 (corrupción
  silenciosa); ahora se salta la fila, igual que ya hacía `usermodified`.
- **004 / #49 — race de intentos.** `MAX(attempt)+1` sin protección: dos primeras
  confirmaciones concurrentes del mismo usuario con sesiones distintas (dos pestañas)
  colisionan en el índice único. El shim web se autorrepara, pero el WS `save_track`
  propagaba la `dml_write_exception` al móvil. Fix: lock `\core\lock` por (instancia,
  usuario) alrededor de asignar-y-escribir; en timeout procede sin lock (= comportamiento
  actual), nunca bloquea.
- **005 / #50 — participación vs grademethod.** La línea de participación del profesor
  fijaba `MAX(scaledscore)`, mientras el libro de notas agrega según el `grademethod`
  ([[DEC-0-07]]); con cualquier método ≠ *highest* la media de portada divergía del libro.
  Fix: respeta el método. Extrae el `switch` a `attempts::aggregate_values()` (pura),
  que 006 reutiliza.
- **006 / #51 — N+1 de recálculo.** `exelearning_update_grades($exe, 0)` hacía
  `1 + usuarios×ítems` SELECTs + `usuarios×ítems` `grade_update()`. Fix: un SELECT para
  todos los intentos + group-by en memoria + un `grade_update()` por ítem con notas
  indexadas por userid; preserva exactamente las reglas de grademodel ([[DEC-25-01]]).
- **007 / #52 — extracción ZIP.** Dos sitios extraen ZIP (instalador del editor + estilos).
  Nueva clase `\mod_exelearning\local\zip_utils` con el validador de entradas (movido) +
  un **barrido post-extracción** (rechaza symlinks, verifica `realpath` contenido). Ambos
  sitios la usan; no se fusionan las dos estrategias de extracción (sirven a casos
  distintos). Defensa en profundidad sobre [[DEC-4-01]].
- **008 / #53 — descarga del informe.** El informe de intentos no tenía exportación.
  Fix: rama de descarga con `\core\dataformat::download_data()` (CSV/Excel/ODS/JSON),
  misma query y filtros (separate-groups, deep-link), antes de toda salida.
- **009 / #54 — Behat.** El borrado de intento (destructivo, con recálculo + capability +
  sesskey + grupos) y el límite separate-groups no tenían cobertura de UI. Dos escenarios
  no-`@javascript` los cubren.

## Hallazgos considerados y **descartados** (para no re-auditarlos)

- **`addslashes()` para la URL del shim en `view.php`:** `$trackurl` viene de `moodle_url`
  (id numérico + sesskey + modo); no influido por atacante. Cosmético.
- **Índice `(exelearningid, userid, sessiontoken)`:** el índice `(exelearningid, userid)`
  ya acota; ganancia marginal, no compensa la churn de esquema.
- **Duplicación `track.php` ↔ `save_track`:** el pipeline compartido ya existe
  (`track::ingest()`); la auth por endpoint es idiomática de Moodle.
- **Hardening del redirect de `grade.php`:** `report.php` revalida grupos en destino;
  defensa en profundidad ya presente.
- **XSS en el encabezado de `report.php`:** ya escapado con `s()` (B8, [[DEC-34-01]]).
- **Endurecer la regex de versión del instalador:** las versiones vienen de la API de
  releases de GitHub (fuente confiable) y alimentan una URL local; teórico.
- **Pico de memoria de `itemscores` antes del cap de 1000:** acotado por
  `post_max_size`/límites de PHP; el cap está bien colocado.
- **Commitear `composer.lock`:** tooling solo-dev; CI construye su propio entorno
  moodle-plugin-ci. Convención de repo-plugin aceptable.
- **PHP 8.1 en CI tras EOL:** la matriz sigue la política de soporte de Moodle 4.5 LTS
  (el plugin debe correr donde corre Moodle); por diseño ([[DEC-0-04]]).
- **Unificar manejo de errores entre endpoints:** tres endpoints sirven a tres audiencias
  (AJAX JSON, warnings WS, redirects web); abstraerlo añade indirección sin reducir bugs.
- **Migración `html_writer` → Mustache en view/report:** esfuerzo alto, hoy bien escapado;
  no compensa la churn.
- **Descomposición de `lib.php` (~1715 líneas):** real pero esfuerzo alto y parcialmente
  obligado por convenciones de Moodle (hooks de ciclo de vida, pluginfile, callbacks de
  nota deben quedarse). Presentado al mantenedor, no seleccionado esta ronda.
- **`exelearning_package_legacy` (solo usado por `editor/save.php`):** candidato real de
  limpieza, confianza media en su reemplazo por `classes/local/package.php`; investigar
  antes del próximo refactor mayor de `editor/save.php`.
- **Traducciones marcadas con `~`:** marcador deliberado [[DEC-11-01]]; necesita revisor
  humano, ya en el checklist pre-estable.

## Opciones de dirección registradas (no planificadas)

- **TAREA-016 / [[DEC-18-01]]** (origen de paquete por URL con sync, patrón mod_scorm):
  backlog nº1 del mantenedor, diseño ya decidido en el ADR. No planificado aquí; pedir una
  invocación `plan` dedicada cuando se vaya a implementar.
- **Ingesta xAPI (TAREA-015 / [[DEC-17-01]]):** bloqueada en upstream exelearning#1867
  (congelar el contrato). Monitorizar; sin plan.
- **Completion por iDevice:** rechazada — pelearía con la abstracción de completion de
  Moodle (un estado por módulo); documentar como limitación conocida.

## Consecuencias

- **Positivas:** 9 mejoras de correctitud/seguridad/rendimiento/DX entregadas con tests;
  el registro de **hallazgos descartados** evita re-auditar lo ya valorado; las opciones
  de dirección quedan trazadas para futuras invocaciones.
- **Coste:** ninguno funcional negativo; las mejoras son aditivas y cubiertas por CI.
- **Cambios que dispara:** ninguno pendiente de esta ronda. `docs/AUDIT_FOLLOWUP.md`
  añade una sección que enlaza estas mejoras.

## Validación

Cada mejora se fusionó con su suite verde (PHPUnit 11.5, Moodle 5.0.7) y `phpcs` 0/0; ver
los PRs #46–#54 para la evidencia por mejora. Sin cambio de comportamiento no cubierto por
tests.

## Seguimiento

- Cierra esta ronda de auditoría estándar; no abre tareas de implementación.
- Las opciones de dirección (TAREA-016, xAPI) siguen su propio ciclo.
