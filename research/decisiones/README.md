# decisiones/

ADRs (Architecture Decision Records) del proyecto `mod_exelearning`.

Cada ADR registra **una** decisión arquitectónica duradera junto con su razonamiento:
contexto, problema, opciones consideradas, evidencia, decisión y consecuencias.

## Identificación

Los ADRs se identifican por el **número de seguimiento de GitHub** del cambio que los
motiva, no por un contador global. El número de seguimiento es el **issue** cuando el
cambio tiene uno, y su **pull request** cuando no lo tiene: GitHub reparte los números
de issues y de PRs desde una única secuencia por repositorio, así que nunca colisionan
(en el modelo de datos de GitHub un PR *es* un issue, por eso `/issues/<n>` resuelve a
un PR).

La convención procede de
[`exelearning/exelearning#2232`](https://github.com/exelearning/exelearning/issues/2232);
este repositorio la adopta conservando su prefijo `DEC-` y su idioma.

### Nombre de archivo

```text
DEC-<numero-de-seguimiento>-<secuencia-local>-<slug-de-la-decision>.md
```

Por ejemplo, el issue [#13](https://github.com/exelearning/moodle-mod_exelearning/issues/13)
produjo doce decisiones:

```text
DEC-13-01-deteccion-calificable-por-isscorm.md
DEC-13-02-deeplink-gradebook-grade-php.md
…
DEC-13-12-herramienta-migracion-en-mod-exelearning.md
```

### Reglas

- **El número de seguimiento es obligatorio** antes de dar un ADR por cerrado: el issue
  del cambio si lo tiene, y si no su pull request. **Nunca abrir un issue sólo para
  obtener un número.**
- `<numero-de-seguimiento>` va sin ceros a la izquierda.
- `<secuencia-local>` son dos dígitos, **acotados a ese número de seguimiento**,
  empezando en `01`. Está presente incluso cuando el cambio tiene un único ADR, para
  que añadir un segundo más adelante nunca renombre al primero.
- Una secuencia local no se reutiliza dentro del mismo número de seguimiento, aunque el
  registro se rechace o se elimine.
- `<slug-de-la-decision>` va en kebab-case y nombra la **decisión**, no el tema.
- El campo `id` del frontmatter debe ser igual a
  `DEC-<numero-de-seguimiento>-<secuencia-local>`, y `tracking_issue` debe ser igual al
  número de seguimiento. La validación comprueba ambos.
- El H1 del documento debe ser exactamente `# <id>: <titulo>`.
- **No hay contador global** ni «siguiente número libre» que calcular. Dos ramas sólo
  pueden colisionar si comparten número de seguimiento.
- Si un cambio nace como PR y más tarde recibe un issue, **se conserva el identificador
  original**. Los identificadores son estables una vez publicados.

### Numeración retirada

Hasta 2026-08 los ADRs usaban un contador global `DEC-NNNN`. Ese esquema está retirado y
no debe usarse en contenido nuevo:
[`mapa-migracion-ids.md`](./mapa-migracion-ids.md) mapea cada identificador retirado a su
identificador actual.

Dieciocho registros del arranque del repositorio (`DEC-0001`…`DEC-0015`, `DEC-0019`,
`DEC-0036`, `DEC-0063`) conservan la numeración retirada porque se subieron directamente
a `main` sin issue ni pull request y no tienen número de seguimiento verificable. Están
enumerados de forma explícita en `../tools/check_decisions.py`; cualquier archivo
`DEC-NNNN-*.md` **nuevo** hace fallar la validación.

## Campos del frontmatter

| Campo | Obligatorio | Fuente canónica de |
|---|---|---|
| `id` | sí | identidad del registro (debe coincidir con el nombre de archivo) |
| `titulo` | sí | título (replicado por el H1) |
| `estado` | sí | ciclo de vida |
| `fecha` | sí | fecha de creación, `YYYY-MM-DD` |
| `tracking_issue` | sí (migrados) | número de GitHub que identifica la decisión — issue, o PR si no hay issue |
| `legacy_id` | sólo migrados | identificador retirado |
| `agentes` | sí | quién decide |
| `fuentes` / `experimentos` | no | evidencia citada (`REPO-`/`FTE-`/`AN-`/`EXP-`) |
| `relacionados` | no | decisiones hermanas |
| `supersede` / `reemplazada_por` | no | historia de la decisión |
| `herramienta_ia.interfaz` / `.modelo` | sí | procedencia (`none` si no se usó IA) |

`tracking_issue` y `legacy_id` conservan su nombre en inglés a propósito: son los campos
compartidos con el resto de repositorios de eXeLearning que aplican esta misma
convención. La prosa, los títulos y el resto de campos siguen en español.

## Estados

`Propuesta`, `Aceptada`, `Rechazada`, `Superseded`.

El estado vive **sólo** en el frontmatter. No añadir una sección `## Estado` que lo
repita: una única fuente canónica por campo mutable.

## Superseder un ADR

Los ADRs aceptados son **append-only**. No se reescriben salvo para corregir erratas o
enlaces rotos. Para cambiar una decisión aceptada:

1. Crear un ADR nuevo bajo el número de seguimiento que motiva el cambio.
2. Poner `supersede: DEC-<id-antiguo>` en el ADR nuevo.
3. Poner `estado: Superseded` y `reemplazada_por: DEC-<id-nuevo>` en el ADR antiguo.
4. Ejecutar `make architecture-check` para validar la relación.

La validación rechaza una relación unilateral: deben estar las dos direcciones, y el ADR
superado debe llevar `estado: Superseded`.

## Documentos de diseño

Un cambio grande puede necesitar un diseño previo además de sus decisiones. Los
documentos de diseño viven en un directorio por número de seguimiento:

```text
research/decisiones/cambios/<numero-de-seguimiento>-<slug-del-cambio>/
```

con los archivos `proposal.md`, `spec.md`, `design.md`, `research.md` y `tasks.md`.
**Crear sólo los archivos que llevan contenido real**; los marcadores vacíos no aportan
nada. Cada documento lleva `tracking_issue`, que debe coincidir con el prefijo del
directorio. Las decisiones duraderas que aparezcan dentro de un diseño se extraen a
ADRs; el diseño registra el *cómo*, el ADR registra *qué se decidió y por qué*.

## Índices

Los índices son **generados**, nunca se mantienen a mano:

```bash
python3 research/tools/build_indexes.py   # regenera research/docs/indices/*.yaml
make architecture-records                 # imprime el índice de decisiones
make architecture-check                   # valida identificadores y metadatos
```

## Referencias

- Plantilla: [`../plantillas/markdown/plantilla-adr.md`](../plantillas/markdown/plantilla-adr.md).
- Checklist antes de aceptar: [`../plantillas/checklists/checklist-adr-tecnologica.md`](../plantillas/checklists/checklist-adr-tecnologica.md).
- Mapa de migración: [`mapa-migracion-ids.md`](./mapa-migracion-ids.md).
