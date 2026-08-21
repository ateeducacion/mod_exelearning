---
id: DEC-122-01
title: "Retirada del canal de ingesta xAPI: SCORM 1.2 vuelve a ser el canal único de calificación"
status: Accepted
date: 2026-08-21
tracking_issue: 122
supersedes: [DEC-85-01, DEC-17-01, DEC-0-18]
deciders:
  - erseco
  - claude-code
sources:
  - REPO-005
  - FTE-011
experiments:
  - EXP-004
related:
  adrs: [DEC-0-03, DEC-0-14, DEC-5-01, DEC-6-01, DEC-13-07, DEC-26-02, DEC-69-01]
ai_assistance:
  tool: claude-code
  model: claude-opus-5
---

# DEC-122-01: Retirada del canal de ingesta xAPI: SCORM 1.2 vuelve a ser el canal único de calificación

## Contexto

`DEC-85-01` implementó un **segundo canal de calificación**, y lo hizo con carácter
**experimental**: los paquetes que traen el emisor upstream (`libs/xapi/exe_xapi.js`) emitían
statements por `postMessage`, un listener inline (`js/xapi_listener.js`) los reenviaba a
`xapi_track.php` y el ingestor los metía en la tubería existente. Mientras ese canal estaba
activo, el shim SCORM se arrancaba **inerte** (`disableTracking`) para esos paquetes, y el
interruptor de sitio `xapiprimaryenabled` — **activado por defecto** — decidía cuál de los dos
canales calificaba.

Nunca dejó de ser un experimento. No hubo un requisito de producto que lo pidiera —`DEC-0-14`
respondió «no por ahora» a la pregunta de si existía demanda de analítica LRS—, su alcance
excluía explícitamente cmi5 y cualquier LRS externo (`DEC-17-01` §6, `DEC-0-18` §9), y este
plugin ha sido siempre su único consumidor.

Con ambos caminos en producción se pudo **medir** el resultado de punta a punta en lugar de
razonarlo sobre el diseño. La medición no sostiene el canal.

## Problema

El problema de fondo es la **duplicidad**: dos canales calificando lo mismo, con dos
transportes, dos autenticaciones, dos validaciones y dos resoluciones de identidad, para
alimentar una única tubería de notas. Toda duplicidad se paga en mantenimiento, en superficie
de auditoría y en divergencias que aparecen con el tiempo; sólo se justifica si el segundo
camino aporta algo que el primero no puede dar.

¿Lo aporta?

## Evidencia

1. **Calificación por iDevice: idéntica.** El mismo `sendScoreNew()` dispara pipwerks **y**
   `gamification.track()` (`REPO-005`, `public/app/common/common.js`), así que los dos canales
   son **coextensivos**: ninguno captura una nota que el otro pierda. La paridad ya estaba
   fijada por el propio test del ingestor de `DEC-85-01`.
2. **El overall era peor.** Los `answered` por iDevice **no llevan peso**
   (`_buildIdeviceStatement`), de modo que el canal xAPI tenía que **tomar** el overall del
   statement de paquete. Medido sobre un par 25/75, SCORM recompone en servidor la media
   **ponderada** correcta (25) mientras que la derivación xAPI entrega una media **no
   ponderada** (50). Lo único que el canal hacía por su cuenta es justo lo que hacía mal.
3. **Todo lo demás ya era compartido.** El ingestor **llama** a
   `track::apply_item_scores()` y a `attempts::record_item()`; intentos, finalización,
   publicación en el libro de calificaciones y eventos salían del mismo código en ambos casos.
   El canal duplicaba transporte, `sesskey`, capabilities, validación canónica e identidad
   para llegar exactamente al mismo sitio.
4. **Coste de superficie.** El canal aportaba un endpoint público más (`xapi_track.php`), un
   listener inline, un normalizador/validador completo, una tabla de auditoría, un ajuste de
   administración y un modo inerte del shim SCORM — superficie de ataque y de mantenimiento
   cuyo beneficio medido es cero o negativo.

## Opciones consideradas

1. **Derivar el overall en servidor a partir de los `answered`** (lo que hacía el PR #122
   original). Recompone una media a partir de statements sin peso, así que sólo coincide con
   SCORM cuando todos los iDevices pesan igual. Cambia el síntoma, no la causa: sigue
   habiendo dos canales para una sola tubería.
2. **Dejar `xapiprimaryenabled` en 0 por defecto.** El código muerto se queda, el endpoint
   sigue publicado y la decisión queda sin tomar.
3. **Retirar el canal (elegida).** SCORM 1.2 vuelve a ser el canal único del navegador, con el
   servicio web móvil (`DEC-26-02`) como segunda entrada a la **misma** tubería.

## Decisión

1. **Se retira el canal de ingesta xAPI por completo:** `classes/local/xapi/` (ingestor,
   normalizador, `config_injector`), `xapi_track.php`, `js/xapi_listener.js`, sus tests, el
   cableado de `view.php`, los helpers `exelearning_package_emits_xapi()` /
   `exelearning_xapi_primary_enabled()`, la sección de ajustes y sus cadenas de idioma en los
   cinco idiomas, la llamada a `config_injector::inject()` en `package_manager` y
   `tracking_endpoint::xapi_config()`.
2. **`DEC-0-03` recupera su vigencia literal:** SCORM 1.2 es el estándar de tracking, y el
   overall se **recompone en servidor** desde las notas por iDevice (`DEC-6-01`), que sí
   llevan el peso en línea.
3. **Se elimina también el parámetro `disableTracking`** de `tracking_endpoint::scorm_config()`
   y de `js/scorm_tracker.js`. Nació con `DEC-85-01` para dejar el shim inerte en los paquetes
   xAPI-primary y, retirado el canal, **nadie lo pone a `true`**.
   - Se **rechazó** expresamente reutilizarlo como `empty($exe->gradeenabled)` ("una actividad
     no calificable no debería postear cada 500 ms"). Es incorrecto: `track::ingest()` no mira
     `gradeenabled`, escribe la fila de intento igualmente, recalcula finalización y emite los
     eventos de intento. Silenciar el cliente en las actividades no calificables **rompería**
     la finalización por estado (`DEC-69-01`, que consulta `exelearning_attempt` sin filtrar
     por calificación) y dejaría sin historial la promesa explícita de `DEC-13-07` de
     «reactivar `gradeenabled` re-detecta y recalcula desde el historial».
4. **`exelearning_tracking_events` se conserva**, inerte: su definición en `db/install.xml`, su
   etapa de upgrade y sus declaraciones en `classes/privacy/provider.php` se quedan como
   están. Contiene filas vinculadas a alumnos en los sitios que ejecutaron el canal, está
   declarada a la API de privacidad y **no** está en `backup/moodle2`, así que borrarla
   destruiría datos personales sin camino de restauración. Nadie escribe ya en ella; exportar
   y borrar por privacidad siguen funcionando.
5. **Los paquetes antiguos siguen emitiendo, y es inocuo.** `_postToParent()` es *fire and
   forget* dentro de un `try`/`catch`: no hay acuse de recibo, ni reintento, ni rama de error,
   así que un `postMessage` que nadie escucha lo descarta el navegador. `_postToLrs()` sólo
   actúa con parámetros de lanzamiento xAPI, que este plugin nunca inyecta. Al desaparecer
   `config_injector`, los paquetes nuevos ya no llevan `parentOrigin`, con lo que el emisor
   difunde a `'*'` **anonimizando el actor** — el destino sigue siendo la ventana padre (la
   propia página de Moodle), de modo que no hay fuga.

## Consecuencias

- **Positivas:** un solo canal, una sola tubería y un overall ponderado correcto para todos los
  paquetes; un endpoint público menos; menos superficie que auditar y traducir; el shim SCORM
  vuelve a su comportamiento previo a `DEC-85-01`, byte a byte.
- **Negativas / coste:** se pierde la deduplicación por `statement.id` y la auditoría por
  statement (nadie dependía de ellas para la nota); vuelve la dependencia del `cmi.suspend_data`
  y del parche de guardas de `form`/`scrambled-list` (`DEC-13-11`), deuda ya conocida y con
  salida documentada en `DEC-34-02`/`DEC-36-01`; los sitios que hubieran fijado
  `xapiprimaryenabled` se quedan con un ajuste huérfano en `mdl_config_plugins`, inofensivo.
- **Sin migración de datos.** Las notas, intentos e informes existentes no se tocan: los dos
  canales escribían en las mismas tablas.

## Validación

`npx vitest run` (el tracker SCORM sigue verde; los tests del listener desaparecen con él),
la suite PHPUnit completa del plugin sobre el stack de evaluación, y `composer lint`
(phpcs, estándar moodle). Búsqueda de `xapi`/`xAPI` en todo el árbol tras el cambio: sólo
quedan el registro histórico de `research/`, la entrada de la v4.0.2 en `CHANGELOG.md`, las
cadenas de privacidad de la tabla conservada y las referencias explícitas a esta retirada.

## Seguimiento

Esta decisión **no cierra la puerta a xAPI**. Descarta *este* canal —un segundo camino de
calificación que duplicaba el existente— y deja abierta una implementación futura, que sería
además una cosa distinta y mejor planteada:

- El **emisor sigue vivo aguas arriba**, en el propio eXeLearning: todo paquete exportado
  continúa emitiendo statements. Lo que se retira aquí es el *consumidor*, que es la parte
  fácil de rehacer; la difícil —hablar xAPI— no se toca.
- Si algún día aparece un requisito real de analítica, el punto de partida no es este canal de
  notas sino un handler `core_xapi` para **eventos**, junto a la tubería de calificación en
  lugar de compitiendo con ella. Sin duplicidad, no hay motivo para retirarlo.
- Fuera de alcance, igual que antes: **cmi5** y LRS externo.

Volver atrás no exige rehacer nada de lo aquí borrado: este registro y el historial de git
documentan qué había, por qué se fue y qué habría que resolver primero — sobre todo el peso por
iDevice, que no viaja en los statements y sin el cual no puede existir un overall correcto.
