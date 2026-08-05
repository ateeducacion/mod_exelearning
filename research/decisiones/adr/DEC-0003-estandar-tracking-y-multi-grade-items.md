---
id: DEC-0003
titulo: "Estándar de tracking y registro de múltiples grade items"
estado: Aceptada
fecha: 2026-05-28
agentes:
  - claude-code
fuentes:
  - FTE-001
  - FTE-002
  - FTE-003
  - FTE-004
  - FTE-005
  - FTE-006
  - FTE-007
  - FTE-008
  - REPO-001
  - REPO-002
  - REPO-004
experimentos:
  - EXP-001
  - EXP-002
herramienta_ia:
  interfaz: claude-code
  modelo: claude-opus-4-7
---

# DEC-0003: Estándar de tracking y registro de múltiples grade items

## Contexto

`mod_exelearning` debe satisfacer dos requisitos funcionales duros: **preservar la
sidebar nativa de eXeLearning** dentro de la actividad Moodle y **registrar varios
ítems en el libro de calificaciones** por cada recurso (uno por iDevice calificable).

## Problema

Elegir un estándar de tracking que (a) capture interacciones con granularidad por
item, (b) sea compatible con la técnica iframe + paquete servido tal cual, y (c) tenga
soporte robusto en Moodle nativo.

## Opciones consideradas

Resumen de la matriz [`../../analisis/matrices/matriz-estandar-tracking.yaml`](../../analisis/matrices/matriz-estandar-tracking.yaml).

1. **SCORM 1.2** — sólido y ampliamente exportado por eXeLearning, pero single-score
   por SCO; multi-item es un hack.
2. **SCORM 2004** — `cmi.objectives.{n}` permite varios, requiere convención eXeLearning.
3. **xAPI vía `core_xapi`** — soporte nativo Moodle, granularidad máxima, ortogonal a
   la sidebar (la sidebar la sigue pintando el paquete). Modelo confirmado en
   `mod_h5pactivity`.
4. **cmi5** — xAPI + estructura de curso, ideal en teoría pero baja adopción.
5. **LTI 1.3 AGS** — multi-item nativo, pero implica externalizar el contenido fuera de
   Moodle ⇒ rompe el caso de uso.

## Evidencia

- `mod_workshop` (REPO-004) demuestra multi-itemnumber en core (AN-002).
- `mod_h5pactivity` (REPO-004) demuestra xAPI → gradebook (AN-003).
- `mod_exeweb` (REPO-002) demuestra la técnica iframe que preserva la sidebar (AN-001).
- `mod_exescorm` (REPO-001) confirma que el camino SCORM colapsa en un único grade item.

## Decisión propuesta

**Adoptar xAPI (vía `core_xapi`) como canal de tracking y `grade_update` con
`itemnumber` variable como mecanismo de registro de N grade items**, replicando el
patrón `mod_workshop` + `mod_h5pactivity`. La sidebar se preserva sirviendo el paquete
publicado tal cual dentro de un iframe, igual que `mod_exeweb`.

Plan B: si EXP-001 demuestra que eXeLearning hoy no emite statements xAPI ni admite
un shim cliente en tiempo razonable, se cae a **SCORM 2004 con `cmi.objectives.{n}.id`
como clave de routing a `itemnumber`** — requiere convención con eXeLearning upstream
(PREG-002).

LTI AGS queda descartado para el caso de uso "subir paquete a Moodle", reservado para
un eventual modo "eXeLearning Online como tool externo".

## Consecuencias

Positivas:
- Solución elegante: un único canal de tracking moderno, multi-item por construcción.
- Cero código de SCORM API JS shim que mantener.
- Reutiliza `core_xapi` (testing, privacidad, OAuth ya cubiertos por Moodle core).

Negativas:
- Riesgo de que eXeLearning hoy no emita statements ⇒ requiere shim cliente o cambios
  aguas arriba (PREG-002).
- Dependencia de versión mínima de Moodle con `core_xapi` estable.

## Riesgos

- RIE-pendiente: el paquete publicado actual de eXeLearning podría no exponer
  identificadores estables por iDevice. Mitigación: PREG-001 + propuesta upstream
  (PREG-002).

## Validación

Antes de cerrar como `Aceptada`:

1. EXP-001 documenta la estructura de un paquete publicado real de eXeLearning, sus
   identificadores y si emite statements xAPI o no.
2. EXP-002 demuestra en una instalación de Moodle que un plugin mínimo registra dos
   grade items y los actualiza vía `grade_update`.
3. La matriz `matriz-estandar-tracking.yaml` pasa de cualitativa a cuantitativa.
4. Checklist `checklist-adr-tecnologica.md` superado.

## Seguimiento

- TAREA-003 (EXP-001), TAREA-004 (EXP-002), TAREA-005 (cierre).
- Tras `Aceptada`, abrir DEC-0004 (esquema de tablas) y DEC-0005 (formato de entrada en
  v1: publicado-only vs proyecto+publicado).

## Actualización (2026-05-29): Aceptada con el estado real (Plan B vigente)

Se marca **Aceptada** documentando lo que de facto está implementado y verificado:

- **Multi-grade-items**: implementado vía parseo server-side de `content.xml`
  (`classes/local/package.php`) + registro N `grade_items` con `itemnumber`
  (`exelearning_sync_grade_items`). El `objectid` es el `<odeIdeviceId>`; su
  (in)estabilidad entre exports está caracterizada en PREG-001 y RIE-006.
- **Tracking vigente = bridge SCORM 1.2** (NO xAPI todavía): shim `window.API`
  en `view.php` → `track.php` → `grade_update`, con parseo de `cmi.suspend_data`
  por iDevice. Es el "Plan B" de este ADR, hoy el camino productivo.
- **xAPI nativo (`core_xapi`)** queda como evolución futura (no implementado).
  Cuando se aborde, revisar PREG-001/002 (objectives SCORM 2004 / statements).

Por tanto: la DECISIÓN de modelo (multi-itemnumber + tracking estandarizado) está
aceptada y en producción con el bridge SCORM 1.2; la PARTE xAPI sigue siendo
trabajo futuro y se documentará en su propio ADR cuando se implemente.
