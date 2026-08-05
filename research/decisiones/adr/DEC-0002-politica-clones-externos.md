---
id: DEC-0002
titulo: "Política de clones externos y compatibilidad de licencias"
estado: Aceptada
fecha: 2026-05-28
agentes:
  - erseco
  - claude-code
fuentes:
  - REPO-001
  - REPO-002
  - REPO-003
  - REPO-004
  - REPO-005
experimentos: []
herramienta_ia:
  interfaz: claude-code
  modelo: claude-opus-4-7
---

# DEC-0002: Política de clones externos y compatibilidad de licencias

## Contexto

El proyecto referencia constantemente código de cinco repositorios externos
(`mod_exescorm`, `mod_exeweb`, `wp-exelearning`, `moodle` core, `exelearning` upstream).
Vendorar cualquiera de ellos en este repo introduce ruido, riesgo de licencia y
divergencia respecto al upstream.

## Problema

¿Cómo se referencian repositorios externos sin contaminar este repositorio ni perder
trazabilidad de la versión exacta consultada?

## Opciones consideradas

1. **Vendoring** — copiar carpetas relevantes a este repo. Rechazado: viola
   trazabilidad, duplica licencias, envejece.
2. **Submódulos git** — formal pero pesado para repos sólo de referencia.
3. **Clones externos enlazados por ruta + commit** — referencias por ruta absoluta
   local (`../_repos/`) y por URL + commit en cada ficha REPO.

## Evidencia

- `learningml-ng` aplica la opción 3 con éxito documentado.
- Plugins Moodle se distribuyen tradicionalmente GPLv3+; mezclarlos en el mismo repo
  exigiría aclarar headers en cada archivo: complejidad adicional sin valor.

## Decisión

Adoptamos la **Opción 3**. Reglas:

- Carpeta convencional para clones de referencia: `../_repos/`. No se crea
  automáticamente.
- Cada ficha `REPO-NNN.md` registra: `ruta_local`, `url_upstream`, `commit_consultado`,
  `fecha_consulta`, `licencia`.
- Las rutas Dropbox actuales (`/Users/ernesto/Dropbox/Trabajo/ate/exelearning/...`) se
  aceptan como rutas locales mientras la organización del usuario las mantenga, pero
  toda nueva consulta debe registrar la URL upstream pública correspondiente
  (`[PENDIENTE]` mientras no se confirma).
- El plugin `mod_exelearning` se distribuirá bajo **GPLv3-or-later** para
  compatibilidad con Moodle.
- Cualquier dependencia con licencia GPL incompatible (AGPL, GPLv2-only sin "or later",
  comercial) bloquea la incorporación.

## Consecuencias

Positivas:
- Repo limpio, sin código de terceros.
- Trazabilidad de versión consultada en cada ficha.
- Compatibilidad de licencia garantizada por construcción.

Negativas:
- Cada agente debe poblar `../_repos/` por su cuenta.

## Riesgos

- Si el upstream desaparece, perdemos la fuente. Mitigación: registrar siempre
  `commit_consultado` y rutas locales.

## Validación

- Auditoría manual de licencias en cada ficha REPO antes de marcar `Aceptada`.
- CI futuro: script que falla si una ficha REPO declara una licencia incompatible.

## Seguimiento

- TAREA-001 ya pobló las cinco fichas iniciales.
- Cualquier nuevo REPO requiere checklist `checklist-auditoria-plugin-moodle.md`.
