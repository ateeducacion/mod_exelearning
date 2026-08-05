---
id: DEC-13-04
titulo: "Importar actividades desde mod_exeweb y mod_exescorm (issue #13 #3)"
estado: Superseded
fecha: 2026-06-03
tracking_issue: 13
legacy_id: DEC-0025
reemplazada_por: DEC-13-05
agentes:
  - erseco
  - claude-code
fuentes:
  - REPO-004
relacionados:
  - DEC-0009
  - DEC-13-03
herramienta_ia:
  interfaz: claude-code
  modelo: claude-opus-4-8
---

# DEC-13-04: Importar actividades desde mod_exeweb y mod_exescorm (issue #13 #3)

> **Superseded por [DEC-13-05](DEC-13-05-migracion-masiva-desde-ajustes.md)** (2026-06-03): a
> petición del usuario, el import por-actividad (selector en el formulario + hook en
> `add_instance`) se sustituye por una **herramienta de migración masiva** en los Ajustes del
> plugin. El **motor** (`import_service::import_package` + resolución del `.elpx`) descrito aquí
> se conserva y se reutiliza; lo que cambia es el disparador y la UI. Ver DEC-13-05.

## Contexto

El issue #13 (punto 3) pide importar actividades creadas con los plugins hermanos
`mod_exeweb` y `mod_exescorm`. Se entrega en PR aparte (stacked sobre el PR núcleo) por ser
la parte de mayor riesgo y la más difícil de cubrir en CI (los hermanos no están instalados).

## Hallazgo

Ambos hermanos guardan su paquete subido con la **misma convención** que este plugin:
`filearea = 'package'`, `itemid = 0` del contexto del módulo (verificado en sus `lib.php`).
- `mod_exeweb` guarda un `.elpx` nativo.
- `mod_exescorm` guarda un `.zip` SCORM; el `.elpx` solo es recuperable cuando el paquete se
  exportó **con fuente editable** (iDevice `download-source-file`). No existe conversión
  inversa SCORM→eXe que no sea con pérdida.

## Decisión

- **mod_exeweb**: copiar el `.elpx` verbatim a la nueva instancia.
- **mod_exescorm**: extraer el `.elpx` embebido si está presente; si no, devolver `nosource`
  (no se hace importación con pérdida). Solo se acepta `.elpx` v4 (el `.elp` legacy queda
  fuera por la restricción inmutable del plugin).
- Reutilizar la tubería de producción: `exelearning_extract_stored_package()` +
  `exelearning_sync_grade_items()`.
- **UI**: selector "Importar desde actividad existente" en `mod_form.php` (solo en creación;
  oculto si no hay fuentes o si los hermanos no están instalados) + hook en
  `exelearning_add_instance()` cuando se elige fuente y no se sube paquete. Depende de
  DEC-13-03 (paquete opcional), de ahí el stacking sobre el PR núcleo.
- **Testabilidad**: el núcleo `import_service::import_package()` opera sobre el file storage,
  así que se prueba sin los hermanos instalados (fileareas simuladas + SCORM sintético con y
  sin fuente). `import_from_cm()` / `list_sources()` añaden la capa de course-module.

## Consecuencias

- Sin esquema nuevo. Si los hermanos no están instalados, `list_sources()` devuelve vacío y
  la UI se oculta (degradación elegante).
- Cobertura E2E limitada (hermanos ausentes en CI); por eso el copiado se cubre con tests
  unitarios sobre fileareas simuladas, no con Behat end-to-end.
- Seguridad: `import_from_cm()` valida que la fuente es un tipo soportado, del **mismo curso**
  que el destino y **visible** para el usuario (evita IDOR con un cmid manipulado).

## Implementación

- `classes/local/import_service.php` (servicio).
- `mod_form.php` (selector `importsource`), `lib.php` (hook en `exelearning_add_instance`).
- `lang/en/exelearning.php` (`importsource*`, `import_*`).
- `tests/import_test.php`.
