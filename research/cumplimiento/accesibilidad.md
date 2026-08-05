# Accesibilidad — mod_exelearning

Objetivo: cumplir **WCAG 2.2 AA** en la actividad rendida por el plugin.

> **[ACTUALIZACION 2026-06-17]** El plugin alcanzó `MATURITY_STABLE` (DEC-77-01) **sin** una pasada formal de
> `axe-core`. Esta auditoría sigue siendo el principal pendiente de cumplimiento (ver `research/TODO.md` →
> "Auditorías de cumplimiento pendientes"). Las `[HIPOTESIS]`/`[PENDIENTE]` de abajo siguen vigentes; no
> bloquea STABLE, pero conviene cerrarlo: pasar `axe-core` sobre la superficie propia (cabecera, toolbar,
> `mod_form.php`, página de admin) y registrar resultados o un `PREG`.

## Frontera de responsabilidad

- Lo que produce **eXeLearning** dentro del iframe (HTML del paquete, sidebar, formularios
  de iDevices) cae bajo responsabilidad de eXeLearning upstream. Si una plantilla
  oficial no cumple WCAG, se abre incidencia upstream y se documenta en `PREG`.
- Lo que produce **`mod_exelearning`** fuera del iframe (cabecera de actividad,
  toolbar, barra de progreso, ajustes en `mod_form.php`, página de configuración del
  admin) cae bajo responsabilidad nuestra y debe cumplir WCAG 2.2 AA por completo.

## Comprobaciones obligatorias antes de release

Aplicar [`../plantillas/checklists/checklist-accesibilidad-wcag22aa.md`](../plantillas/checklists/checklist-accesibilidad-wcag22aa.md).

## Hipótesis a validar

- [HIPOTESIS] La navegación por teclado dentro del iframe funciona sin interferir con
  los atajos globales de Moodle.
- [HIPOTESIS] El árbol de la sidebar de eXeLearning usa `role="tree"` con
  `aria-expanded` y `aria-current`. Verificar en EXP-001.

## Pendientes

- [PENDIENTE] Auditoría con `axe-core` sobre un paquete real (EXP-001 + extensión).
- [PENDIENTE] Decidir si la actividad expone un endpoint `?print=1` para imprimir.
