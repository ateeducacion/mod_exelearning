# AGENTS.md — Reglas operativas para `research/`

Cualquier agente (humano o IA) que añada, modifique o cite contenido en este directorio
debe seguir estas reglas. Las reglas son **vinculantes**: una contribución que no las
cumpla debe rechazarse o corregirse antes de integrarse.

## Principios

1. **Evidencia antes que preferencia.** Toda afirmación técnica cita una fuente
   verificable: `repo + ruta + commit`, doc oficial (con URL, versión y fecha de
   consulta), o un experimento reproducible. Sin fuente no hay afirmación.
2. **Estándar de tracking (DEC-0003 `Aceptada`, 2026-05-29).** El motor vigente es el
   **bridge SCORM 1.2** + multi-grade-items por `objectid` (DEC-0003/DEC-5-01), respaldado
   por la matriz `analisis/matrices/matriz-estandar-tracking.yaml`. **xAPI** es la hoja de
   ruta aceptada como ingesta adicional sobre la **misma** tubería (DEC-0014/DEC-17-01 +
   reglas de validación y versión en DEC-0063), gated al contrato upstream `exelearning#1867`;
   **cmi5 y LTI 1.3 AGS quedan fuera de alcance**. (La "neutralidad de estándar" del bootstrap
   ya se resolvió; toda afirmación nueva sigue citando evidencia.)
3. **Separación de capas.** Hechos en `fuentes/`, interpretaciones en `analisis/`,
   decisiones en `decisiones/`. No mezclar. Una nota AN no decide; un ADR decide.
4. **Trazabilidad.** Cada `TAREA` enlaza ≥1 fuente/análisis/pregunta. Cada `DEC` cita
   evidencias (FTE/REPO/AN/EXP). Cada `EXP` registra comando, commit, entorno, métricas,
   limitaciones.
5. **Append-only.** `status.yaml`, ADRs y diario nunca se reescriben. Para invalidar un
   ADR se publica otro que lo supersede (`supersede: DEC-<nº>-<NN>`, y el antiguo pasa a
   `estado: Superseded` con `reemplazada_por:`).
6. **IDs estables.** `REPO-NNN`, `FTE-NNN`, `AN-NNN`, `EXP-NNN`, `TAREA-NNN`, `PREG-NNN`,
   `RIE-NNN` usan numeración monotónica y no se reutilizan. Las decisiones **no** llevan
   contador global: se identifican por el número de seguimiento de GitHub del cambio
   (issue, o PR si no hay issue) más una secuencia local de dos dígitos,
   `DEC-<nº-seguimiento>-<NN>`. Nunca se abre un issue sólo para obtener un número. Ver
   [`decisiones/README.md`](./decisiones/README.md) y
   [`decisiones/mapa-migracion-ids.md`](./decisiones/mapa-migracion-ids.md).
7. **Política de clones externos.** No se vendoran repositorios. Se enlazan por ruta
   local absoluta (zona de clones de referencia documentada en `DEC-0002`) y por URL +
   commit upstream. Carpeta convencional para clones: `../_repos/` (no se crea
   automáticamente; cada agente la gestiona).
8. **Idioma.** Español. Excepciones literales: IDs (`DEC-0003`), nombres de funciones y
   APIs (`grade_update`, `core_xapi`), nombres propios (Moodle, eXeLearning), fragmentos
   de código y rutas. Los términos técnicos sin traducción aceptada (gradebook,
   line-item) se mantienen en inglés.
9. **Context7 obligatorio** para documentar APIs de Moodle (grade API, core_xapi, mod
   API), estándares (xAPI, cmi5, LTI 1.3) y librerías. Registrar en la ficha FTE: query
   exacta, `library_id` resuelto, fecha de consulta, versión devuelta.
10. **Marcas explícitas.** `[INTERPRETACION]` cuando se interpreta evidencia,
    `[HIPOTESIS]` para conjeturas a validar, `[PENDIENTE: <qué>]` para huecos. Sin
    marcas, el lector asume hecho citado.
11. **Accesibilidad y privacidad desde el inicio.** WCAG 2.2 AA, GDPR, especial cuidado
    con datos de menores en statements xAPI. Ver
    [`cumplimiento/`](./cumplimiento/).
12. **Licencias.** Toda dependencia externa (plugin, librería, estándar) declara su
    licencia. Compatibilidad con GPLv3 de Moodle es requisito.
13. **Experimentos reproducibles.** Sin comando, commit, entorno y métricas, un POC no
    es un experimento; es una anécdota.
14. **Definition of Done (por tarea).** Evidencia enlazada · IDs coherentes · YAML/MD
    valida contra schema · índices regenerados (`python3 tools/build_indexes.py`) ·
    entrada en diario.
15. **Idempotencia.** Los scripts de `tools/` deben poder ejecutarse repetidamente sin
    efectos secundarios externos.
16. **Registro de IA.** Documentos generados con asistencia de IA registran en su
    frontmatter `herramienta_ia: { interfaz: <claude-code|copilot|...>,
    modelo: <model-id> }`.

## Flujo de trabajo recomendado

1. `git pull` y leer `status.yaml`.
2. Seleccionar o crear una `TAREA-NNN`.
3. Localizar fuentes (`fuentes/repositorios/`, `fuentes/tecnologia/`) y consultar
   Context7 si la tarea toca un estándar/API.
4. Crear/actualizar notas (`analisis/notas/`) o experimentos
   (`experimentos/resultados/`).
5. Si la tarea cierra una decisión, abrir o aceptar un ADR.
6. Actualizar `status.yaml` (append entrada nueva, no editar previas).
7. Añadir entrada al diario de hoy.
8. `python3 tools/build_indexes.py && python3 tools/test_schema_validation.py`.
9. Commit y push.

## Lo que NO se hace aquí

- Subir paquetes ELP/ELPX, ZIPs de SCORM ni binarios pesados al repo. Se referencian o
  se generan en `experimentos/` con instrucciones de obtención.
- Vendorar código de `mod_exescorm`, `mod_exeweb`, `wp-exelearning`, `moodle` ni de
  `eXeLearning`.
- Tomar decisiones técnicas sin ADR.
- Escribir código de producción del plugin (eso es fase 1+).
