---
id: DEC-16-01
titulo: "Aceptar también .zip (con content.xml) además de .elpx en la subida"
estado: Aceptada
fecha: 2026-06-03
tracking_issue: 16
legacy_id: DEC-0027
agentes:
  - erseco
  - claude-code
fuentes:
  - REPO-004
relacionados:
  - DEC-13-03
  - DEC-13-05
herramienta_ia:
  interfaz: claude-code
  modelo: claude-opus-4-8
---

# DEC-16-01: Aceptar también .zip (con content.xml) además de .elpx en la subida

## Contexto

Hoy el plugin solo acepta la extensión `.elpx` (`mod_form.php` →
`accepted_types => ['.elpx']`, y la restricción "sólo `.elpx` v4" en `AGENTS.md`). Pero un
`.elpx` **es** un ZIP que contiene `content.xml` (ODE 2.0) + el HTML pre-renderizado; la
extracción (`exelearning_extract_stored_package()` con
`get_file_packer('application/zip')`) ya trata cualquier ZIP — la única puerta que mira la
extensión es el `filemanager` del formulario.

Casos de uso donde el paquete llega como `.zip` y no como `.elpx`:

- El usuario descarga/re-empaqueta el contenido y le queda `.zip` (o un sistema externo lo
  entrega así); tener que renombrar a `.elpx` es fricción innecesaria.
- **Importación/migración (DEC-13-05):** la fuente embebida que se extrae de un SCORM de
  `mod_exescorm`, o cualquier export con `content.xml`, puede no llevar la extensión `.elpx`.
  Aceptar `.zip` unifica el camino y simplifica el motor.

## Decisión (propuesta)

Aceptar **también `.zip`** en la subida, **siempre que** el archivo contenga un `content.xml`
válido (estructura ODE 2.0 de eXeLearning v4) en la raíz. El `.elpx` sigue siendo el formato
**primario y recomendado**; el `.zip` es una conveniencia que pasa exactamente la misma
validación e idéntico pipeline de extracción/sincronización.

- `mod_form.php`: `accepted_types => ['.elpx', '.zip']`.
- **Validación obligatoria tras extraer:** confirmar que existe `content.xml` (y, en su caso,
  `index.html`). Si falta `content.xml`, **rechazar** con un mensaje claro ("no es un paquete
  eXeLearning"). Esto impide aceptar ZIPs arbitrarios y mantiene el invariante real: lo que
  importa no es la extensión, sino que sea un **paquete v4 ODE 2.0**.
- No cambia el formato interno, el editor embebido ni el contrato de `content.xml`; sólo la
  puerta de entrada.

## Consecuencias

- Relaja la restricción "sólo `.elpx`" de `AGENTS.md` → reformularla como "sólo paquetes v4
  ODE 2.0 (con `content.xml`), aceptados como `.elpx` o `.zip`".
- Riesgo: un `.zip` sin `content.xml` (o un zip malicioso). Mitigado por la validación
  post-extracción + el sandbox del iframe (DEC-0019) ya existente para el contenido servido.
- Simplifica DEC-13-05 (la resolución de fuente no depende de la extensión).

## Implementación

- `mod_form.php`: `accepted_types => ['.elpx', '.zip']` + validación en `validation()` que
  rechaza el envío si el archivo subido no contiene `content.xml`.
- `lib.php`: helper `exelearning_package_has_content_xml(\stored_file): bool` (lista las
  entradas del ZIP y busca `content.xml` en la raíz).
- `lang/en/exelearning.php`: `err_nocontentxml` + etiqueta/ayuda de `package` actualizadas.
- `AGENTS.md`: restricción reformulada (paquete v4 ODE 2.0, `.elpx` o `.zip`).
- Tests (`tests/lib_test.php`): el helper acepta un ZIP con `content.xml` y rechaza uno sin él;
  un paquete `.zip` válido se extrae y detecta igual que un `.elpx`.
- La extracción (`exelearning_extract_stored_package`, `get_file_packer('application/zip')`) no
  necesitó cambios: ya trataba cualquier ZIP; lo único que miraba la extensión era el
  `filemanager`.

> Implementado en su propio PR desde `main` (independiente de los PR de importación/migración).
