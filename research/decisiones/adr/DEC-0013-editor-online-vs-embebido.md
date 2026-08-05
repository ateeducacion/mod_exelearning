---
id: DEC-0013
titulo: "¿Integrar el editor eXeLearning Online o quedarnos solo con el embebido?"
estado: Aceptada
fecha: 2026-05-29
fecha_aceptacion: 2026-05-29
agentes:
  - erseco
  - claude-code
fuentes:
  - REPO-002
  - REPO-005
experimentos: []
herramienta_ia:
  interfaz: claude-code
  modelo: claude-opus-4-8
relacionados:
  - DEC-0005
  - DEC-0009
  - DEC-0012
revisa: DEC-0009
---

# DEC-0013: ¿Integrar el editor eXeLearning Online o quedarnos solo con el embebido?

## Contexto

`DEC-0009` (Aceptada) decidió **solo editor embebido**, descartando la
integración con **eXeLearning Online**. Esa decisión se tomó sobre todo por
**simplicidad de UX de administración** (evitar 4 settings interrelacionados:
toggle + select de modo + URL + clave HMAC) y porque el embebido ya cubre el
flujo de autoría básico.

El mantenedor reabre la cuestión para **sopesarla a fondo**, porque la decisión
de DEC-0009 no analizó los problemas de fondo del modo Online, sino la UX. Antes
de cerrar definitivamente conviene tener documentados los pros y contras reales
de cada arquitectura para poder decidir con criterio (este ADR **revisa**
DEC-0009; no lo deroga todavía).

Recordatorio de qué es cada cosa:

- **Editor embebido**: build estático de eXeLearning v4 (`dist/static/`) servido
  por el propio Moodle (`editor/index.php` + `editor/static.php`). Edita el
  `.elpx` **dentro de un iframe same-origin**, importa el paquete almacenado en
  la filearea de Moodle y, al guardar, **re-sube el `.elpx` a Moodle**
  (`editor/save.php`) con re-extracción + re-sync del libro (DEC-0012). Todo el
  estado (ficheros, permisos, versiones) vive **en Moodle**.
- **eXeLearning Online**: instancia externa del servicio eXeLearning (Symfony +
  almacenamiento propio + cuentas propias) a la que Moodle redirige con un token
  firmado (HMAC). La autoría ocurre **fuera de Moodle**, en el servidor Online,
  con su propia sesión, su propio sistema de ficheros y su propia edición
  colaborativa (Yjs).

## Problema

¿Mantenemos solo el editor embebido (statu quo DEC-0009) o reintroducimos —como
opción adicional configurable— la integración con eXeLearning Online? ¿Qué
arquitectura sirve mejor al caso de uso de `mod_exelearning` (autoría de
recursos `.elpx` calificables dentro de un curso Moodle) sin abrir agujeros de
seguridad, sincronización o gobernanza de datos?

## Ejes de decisión (los problemas reales que plantea el Online)

El mantenedor identifica cuatro problemas de fondo del modo Online; los usamos
como ejes de evaluación, más coste/operación y funcionalidad:

1. **Autenticación / identidad.** Online tiene su propio sistema de cuentas. El
   puente requiere firmar un token (HMAC con `hmackey1`) que mapee el usuario
   Moodle → una sesión/identidad en Online. Problemas: gestión y rotación de la
   clave; qué identidad se crea en Online (¿usuario efímero?, ¿persistente?);
   qué pasa si el usuario no existe; expiración del token; SSO real vs. token de
   un solo uso. Es la principal fuente de complejidad y de riesgo de seguridad.
2. **Flujo de la aplicación / "ida y vuelta".** Con el embebido el flujo es
   lineal y local (abrir iframe → editar → guardar a Moodle). Con Online el
   usuario **sale de Moodle** (o se embebe un servicio remoto), edita allí y hay
   que **traer el resultado de vuelta**. ¿Cuándo y cómo se devuelve el `.elpx` a
   Moodle? ¿Push desde Online (webhook/callback firmado) o pull desde Moodle?
   ¿Qué pasa si el usuario cierra la pestaña a medias? El flujo no está claro.
3. **Desconexión Moodle ↔ Online / ubicación de los archivos.** Con el embebido
   la "fuente de verdad" es la filearea de Moodle. Con Online el proyecto vive
   en el almacenamiento del servidor Online y Moodle solo tiene una copia del
   `.elpx` en el momento de exportar. Se duplica el estado: ¿qué versión es la
   buena si el autor sigue editando en Online tras exportar? ¿Se quedan copias
   huérfanas en Online? ¿Se borra el proyecto en Online al borrar la actividad
   en Moodle (privacidad/GDPR)?
4. **Permisos de guardado + edición colaborativa.** Online soporta co-edición en
   tiempo real (Yjs). Pero **quién puede guardar de vuelta en Moodle** lo
   gobierna Moodle (capability `moodle/course:manageactivities` sobre esa
   actividad). Hay un desajuste: en Online pueden co-editar N personas con
   cuentas Online, pero el guardado en Moodle es un acto de UN usuario Moodle
   con permiso sobre ESE módulo. ¿Cómo se concilian? ¿Un colaborador sin permiso
   en Moodle qué rol tiene? ¿La sesión de quién persiste el `.elpx`?

## Opciones consideradas

### A. Solo editor embebido (statu quo, DEC-0009)

| ✔ Pros | ✘ Contras |
|---|---|
| **Same-origin**: sin CORS, sin token entre dominios, sin clave HMAC que gestionar. | Sin co-edición en tiempo real (un autor a la vez por revisión). |
| **Una sola fuente de verdad**: el `.elpx` y su contenido viven en la filearea de Moodle; permisos y versionado los da Moodle. | El editor estático se sirve desde Moodle (bundle ~178 MB en `dist/static/` o instalado en moodledata); peso de despliegue. |
| Permisos triviales: edita quien tiene `moodle/course:manageactivities`; guardar = re-subir a Moodle (DEC-0012). | Versión del editor acoplada a la que el admin instale (mitigado: descarga desde GitHub Releases). |
| Privacidad limpia: borrar la actividad borra los datos; nada vive fuera. | Funcionalidades dependientes de servicios Online (p.ej. plantillas/temas remotos) no disponibles. |
| Ya **implementado, verificado y en CI verde**. Cero infraestructura externa. | |
| Funciona en Moodle Playground (WASM) y en cualquier despliegue sin red saliente. | |

### B. Solo eXeLearning Online

| ✔ Pros | ✘ Contras |
|---|---|
| Co-edición en tiempo real (Yjs); última versión del editor siempre. | **Dependencia dura** de una instancia Online disponible: sin ella, no hay autoría. |
| No hay que empaquetar/servir el editor desde Moodle. | Autenticación cross-domain (HMAC) obligatoria → superficie de ataque + clave a custodiar/rotar. |
| | Estado partido: fuente de verdad ambigua entre Online y Moodle (eje 3). |
| | Permisos de guardado y co-edición desalineados con las capabilities de Moodle (eje 4). |
| | Privacidad/GDPR: datos del alumno/autor fuera de Moodle, ciclo de vida no controlado por Moodle. |
| | No funciona en Playground ni en despliegues aislados. |

### C. Ambos, configurable (lo que tenía mod_exeweb / DEC-0005)

| ✔ Pros | ✘ Contras |
|---|---|
| Flexibilidad: cada despliegue elige según si tiene instancia Online. | **Doble ruta de código y doble testing** (la razón principal por la que DEC-0009 lo descartó). |
| Permite a organizaciones con Online ya montado reutilizarlo. | 4 settings interrelacionados (modo + URL + HMAC + expiración) → UX de admin compleja y propensa a errores. |
| | Hereda TODOS los contras del eje 1-4 del modo Online, solo que "opcionales". |
| | Mantener el puente HMAC + callbacks al día con cambios de la API de Online (REPO-005). |

### D. Embebido ahora + "Abrir en eXeLearning Online" como enlace externo opcional (sin integración de datos)

Variante ligera: el modo productivo es el embebido (fuente de verdad en Moodle),
y se ofrece un botón opcional "Abrir en eXeLearning Online" que **solo abre el
servicio Online en otra pestaña** (sin token, sin callback, sin traer datos de
vuelta automáticamente). El autor que quiera co-editar exporta/importa a mano.

| ✔ Pros | ✘ Contras |
|---|---|
| Conserva la simplicidad y seguridad de A (sin HMAC, sin callbacks). | La "vuelta" del trabajo a Moodle es manual (subir el `.elpx` editado). |
| Da una puerta a co-edición para quien la necesite, sin acoplar arquitecturas. | No es una integración "de verdad"; puede confundir si no se documenta bien. |
| Evita los ejes 1-4: no hay sesión compartida ni estado partido automático. | |

## Análisis frente a los cuatro ejes

| Eje | A (embebido) | B (online) | C (ambos) | D (embebido + enlace) |
|---|---|---|---|---|
| 1. Autenticación | Trivial (same-origin) | Complejo (HMAC) | Complejo (opcional) | Trivial (no hay sesión compartida) |
| 2. Flujo ida/vuelta | Lineal y local | Ambiguo | Ambiguo si Online | Manual y explícito |
| 3. Ubicación archivos | Solo Moodle | Partido | Partido si Online | Solo Moodle (la copia Online es del usuario) |
| 4. Permisos/colab. | Capabilities Moodle | Desalineado | Desalineado si Online | Capabilities Moodle |

## Recomendación (a validar por erseco)

**Mantener A (solo embebido) como decisión vigente**, confirmando DEC-0009 ahora
con el análisis de fondo que faltaba; y **considerar D** si en algún momento se
pide co-edición, por ser la única forma de ofrecer Online sin importar sus
problemas de autenticación, sincronización y gobernanza de datos.

Descartar B (dependencia dura inaceptable para un plugin que debe funcionar en
cualquier Moodle, incluido Playground) y C (el coste de la doble ruta + los
cuatro ejes de riesgo no se justifican mientras el embebido cubra la autoría).

Razón de fondo: los cuatro problemas que el mantenedor señala (autenticación,
flujo, desconexión/ubicación de datos, permisos+colaboración) son **intrínsecos
a partir el estado entre dos sistemas**. El embebido los elimina por diseño al
mantener Moodle como única fuente de verdad same-origin. El valor diferencial de
Online —co-edición en tiempo real— no es un requisito actual de
`mod_exelearning` y, si lo fuera, la opción D lo habilita sin acoplar.

## Consecuencias

Si se acepta la recomendación (A, confirmando DEC-0009):
- No se reintroduce `editormode`/`exeonlinebaseuri`/`hmackey1` (se mantiene la
  restricción inmutable del proyecto).
- Se cierra formalmente el debate Online con análisis documentado (no solo UX).
- Si más adelante se quiere co-edición, abrir un ADR específico para la opción D.

Si se decidiera reintroducir Online (C):
- Habría que diseñar: gestión/rotación de `hmackey1`, política de identidad en
  Online, callback firmado de "guardar de vuelta", reconciliación de versiones,
  borrado en cascada para GDPR, y mapeo colaboradores↔capability de guardado.
  Cada uno es un sub-ADR.

## Preguntas abiertas para decidir (rellenar en la decisión)

- ¿Hay un requisito real y presente de **co-edición en tiempo real** en los
  despliegues de ATE, o es hipotético?
- ¿Existe ya una instancia de eXeLearning Online operada por ATE que se quiera
  reutilizar, o habría que montarla/mantenerla?
- Para GDPR: ¿es aceptable que contenido editado viva fuera de Moodle aunque sea
  temporalmente?
- Si se quisiera Online, ¿se acepta la opción D (enlace, vuelta manual) como
  punto intermedio, o se exige integración de datos completa (C)?

## Decisión (2026-05-29, erseco)

**Aceptada la opción A: solo editor embebido**, confirmando DEC-0009 ahora con el
análisis de fondo de los cuatro ejes (no solo la UX de admin). Es una decisión
**vigente y revisable**, no un portazo definitivo, por los matices recogidos en
las respuestas del mantenedor:

- **Co-edición en tiempo real**: NO es un requisito presente, solo hipotético a
  futuro. Por eso no justifica hoy la complejidad del Online. Si se materializa,
  la vía preferente es la **opción D** (enlace "Abrir en eXeLearning Online" sin
  integrar datos), que evita los ejes 1-4; se abriría un ADR específico.
- **Instancia Online**: ATE **sí opera** una instancia de eXeLearning Online. Es
  un dato relevante (haría más barata una futura integración), pero NO cambia la
  decisión hoy: el plugin debe funcionar en cualquier Moodle (incluido
  Playground y despliegues aislados), así que el Online no puede ser dependencia
  ni siquiera opcional sin asumir los cuatro ejes de riesgo.
- **GDPR / datos fuera de Moodle**: **a consultar** (sin resolver). Mientras no
  haya una respuesta legal afirmativa, mantener todo el contenido dentro de
  Moodle (opción A) es lo prudente. Este punto es, por sí solo, bloqueante para
  B/C hasta que se aclare.

En resumen: A es la decisión correcta HOY; el camino de reapertura, si llega,
es D (no C), y quedaría condicionado a (a) un requisito real de co-edición y
(b) luz verde de GDPR.

## Seguimiento

- DEC-0009 queda **confirmado** por este análisis de fondo (se anota en su ADR).
- Restricción inmutable intacta: no se reintroducen `editormode` /
  `exeonlinebaseuri` / `hmackey1`.
- Si en el futuro se pide co-edición Y GDPR lo permite → abrir ADR de la opción
  D (enlace a la instancia Online de ATE, vuelta manual del `.elpx`).
