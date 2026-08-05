---
id: DEC-<N>-01
titulo: "<Título conciso de la decisión>"
estado: Propuesta   # Propuesta | Aceptada | Rechazada | Superseded
fecha: YYYY-MM-DD
tracking_issue: <N>   # número del issue, o del PR si el cambio no tiene issue
agentes:
  - <nombre o handle>
fuentes:
  - REPO-NNN
  - FTE-NNN
experimentos:
  - EXP-NNN
# supersede: DEC-<N>-<NN>          # sólo si esta ADR reemplaza otra
# reemplazada_por: DEC-<N>-<NN>    # sólo si esta ADR ha sido reemplazada
herramienta_ia:
  interfaz: claude-code
  modelo: claude-opus-4-7
---

<!--
Cómo usar esta plantilla:

1. Buscar el NÚMERO DE SEGUIMIENTO del cambio en GitHub: su issue si lo tiene, y si
   no su pull request. GitHub numera issues y PRs desde una única secuencia, así que
   nunca colisionan. Ese número ES el identificador: no hay contador global ni nada
   que calcular. NUNCA abrir un issue sólo para obtener un número.
2. Copiar este archivo a `DEC-<N>-<NN>-<slug-de-la-decision>.md`, donde <NN> es la
   siguiente secuencia de dos dígitos libre PARA ESE NÚMERO DE SEGUIMIENTO (`01` si es
   la primera). El slug nombra la decisión, no el tema.
3. Poner `id: DEC-<N>-<NN>` y `tracking_issue: <N>`. Deben coincidir con el nombre del
   archivo; la validación lo comprueba.
4. El H1 de abajo debe ser `# <id>: <titulo>`.
5. Rellenar todas las secciones y borrar estos comentarios de guía.
6. Dejar `estado: Propuesta` hasta que se acepte. El estado vive sólo en el
   frontmatter: no añadir una sección `## Estado`.
7. Ejecutar `make architecture-check` para validar.

Política completa: ../../decisiones/README.md
-->

# DEC-<N>-01: <Título conciso de la decisión>

## Contexto

<Por qué surge esta decisión, qué problema o necesidad la motiva.>

## Problema

<Enunciado preciso del problema técnico.>

## Opciones consideradas

1. **Opción A** — descripción, ventajas, inconvenientes, evidencias.
2. **Opción B** — …
3. **Opción C** — …

## Evidencia

<Citas verificables: REPO/FTE/AN/EXP. Mínimo una por opción.>

## Decisión

<Opción elegida y por qué.>

## Consecuencias

- Positivas: …
- Negativas / coste: …
- Cambios que dispara en otros ADRs o tareas: …

## Riesgos

- RIE-NNN: …

## Validación

<Cómo se comprobará en la práctica (experimento, tests, métricas).>

## Seguimiento

<Tareas que esta decisión abre o cierra.>
