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
   `tracking_endpoint::xapi_config()`. El **valor persistido** del ajuste
   (`exelearning/xapiprimaryenabled` en `mdl_config_plugins`) lo borra la etapa 21 con
   `unset_config()`, igual que la etapa 20 hizo con los tres configs del instalador del
   editor: retirar el ajuste del código sin retirar su valor deja un huérfano que un futuro
   ajuste con el mismo nombre heredaría.
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
4. **`exelearning_tracking_events` se elimina.** Era el registro de auditoría/idempotencia del
   canal, y con el canal retirado nadie la escribe ni la lee. Desaparecen su definición en
   `db/install.xml` y todas sus declaraciones en `classes/privacy/provider.php` (metadatos,
   consulta de contextos, exportación y los cuatro caminos de borrado), junto con las ocho
   cadenas de idioma que las traducían, y una **nueva etapa de upgrade** (`2026082100`) la borra
   donde exista. La etapa 19, que la creaba, se deja **intacta**: el historial de upgrade es
   *append-only* y tiene que seguir funcionando para un sitio que la tenga — la crea al pasar y
   la nueva etapa la borra a continuación.

   **Sí hubo publicación, y aun así se borra.** Una revisión anterior de esta rama justificó el
   borrado diciendo que el plugin nunca se había publicado. Es falso, y conviene dejarlo escrito
   aquí para que nadie vuelva a apoyarse en ese argumento:

   ```
   $ git show v4.0.2:db/install.xml | grep -c exelearning_tracking_events
   1
   ```

   `v4.0.3` devuelve lo mismo, y `xapi_track.php` está en el árbol de las dos: las releases
   públicas llevan la tabla **y** un escritor que la llena. Un sitio que instalara v4.0.2 o
   v4.0.3 y usara el canal xAPI puede tener filas, y esta actualización se las borra sin copia
   de seguridad — la tabla no está en `backup/moodle2`.

   **La pérdida se asume a conciencia**, por tres motivos:
   - **Lo que se pierde es auditoría, no evaluación.** Las notas, los intentos y los informes
     viven en `exelearning_grade_item` y `exelearning_attempt`, que no se tocan. De esta tabla
     se pierden los metadatos de auditoría por statement —`statementid`, `verb`, `objectid`,
     `registration` y `scaled`— y con ellos la deduplicación por `statement.id`. La tabla
     **nunca guardó el JSON del statement**: era trazabilidad fina, que ningún cálculo de nota
     consulta.
   - **La ventana es muy breve.** v4.0.2 se publicó el 2026-07-07 y el canal se retira ahora, en
     un plugin en despliegue temprano y con el canal además desactivable por ajuste. El volumen
     de filas afectadas en instalaciones reales es despreciable frente al coste de arrastrar la
     tabla indefinidamente.
   - **Conservarla no salía gratis.** Ni `exelearning_delete_instance()` ni
     `exelearning_reset_userdata()` la limpiaban nunca, así que borrar una actividad o
     restaurar un curso dejaba filas huérfanas vinculadas a alumnos — y la API de privacidad
     las localiza uniendo por la fila de `{exelearning}` que se acaba de borrar, con lo que
     quedaban además inalcanzables. Mantener una tabla inerte obligaba a arreglar y a mantener
     para siempre esos dos caminos, a cambio de un registro que ya no consulta nadie.

   El borrado se anuncia en `CHANGELOG.md` bajo *Removed*, de modo que un administrador que
   quiera conservar esas filas pueda volcarlas antes de actualizar.
5. **Los paquetes antiguos siguen emitiendo, y es inocuo.** `_postToParent()` es *fire and
   forget* dentro de un `try`/`catch`: no hay acuse de recibo, ni reintento, ni rama de error,
   así que un `postMessage` que nadie escucha lo descarta el navegador. `_postToLrs()` sólo
   actúa con parámetros de lanzamiento xAPI, que este plugin nunca inyecta. Al desaparecer
   `config_injector`, los paquetes nuevos ya no llevan `parentOrigin`, con lo que el emisor
   difunde a `'*'` **anonimizando el actor** — el destino sigue siendo la ventana padre (la
   propia página de Moodle), de modo que no hay fuga.

## Consecuencias

- **Positivas:** un solo canal, una sola tubería y un overall ponderado correcto para todos los
  paquetes; un endpoint público menos y una tabla menos; menos superficie que auditar y
  traducir; el shim SCORM vuelve a su comportamiento previo a `DEC-85-01`, byte a byte, y
  `classes/privacy/provider.php` vuelve a declarar exactamente lo que el plugin almacena.
- **Negativas / coste:** se pierde la deduplicación por `statement.id` y la auditoría por
  statement (nadie dependía de ellas para la nota); vuelve la dependencia del `cmi.suspend_data`
  y del parche de guardas de `form`/`scrambled-list` (`DEC-13-11`), deuda ya conocida y con
  salida documentada en `DEC-34-02`/`DEC-36-01`. No queda ajuste huérfano: la etapa 21 borra
  también el valor de `xapiprimaryenabled`, de modo que un sitio que lo hubiera fijado no
  arrastra nada en `mdl_config_plugins`.
- **Migración de datos: destructiva sólo en la tabla de auditoría.** Las notas, intentos e
  informes existentes no se tocan — los dos canales escribían en las mismas tablas. La única
  que desaparece (`exelearning_tracking_events`) no alimentaba ni la nota ni la interfaz, y sus
  filas se borran en la actualización con la justificación del punto 4 de la decisión.
- **Nota operativa: actualizar con una pestaña abierta.** Un alumno que tuviera cargada una
  página de la época xAPI cuando se aplica la actualización se queda, en **esa pestaña**, con
  el shim SCORM inerte (`disableTracking`, tal como se sirvió) y con un listener posteando a
  `xapi_track.php`, que ya no existe. Las interacciones de esa sesión se pierden hasta que la
  página se recargue, momento en el que vuelve a servirse con el shim SCORM activo. Es la
  consideración habitual de una actualización en modo mantenimiento, **no** una vía de pérdida
  de datos: los intentos ya registrados no se ven afectados.

## Validación

`npx vitest run` (el tracker SCORM sigue verde; los tests del listener desaparecen con él),
la suite PHPUnit completa del plugin sobre el stack de evaluación, y `composer lint`
(phpcs, estándar moodle). Búsqueda de `xapi`/`xAPI`/`lrs`/`tincan`/`tracking_events` en todo el
árbol tras el cambio: sólo quedan el registro histórico de `research/`, la entrada de la v4.0.2
en `CHANGELOG.md`, la etapa 19 de `db/upgrade.php` (historial *append-only*), los métodos
`emit_tracking_events()`/`tracking_events()` —nombres que no se refieren a la tabla— y las
referencias explícitas a esta retirada. En `lang/` no queda **ninguna** cadena que mencione
xAPI, ni ninguna huérfana de las que traducían la tabla.

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
