---
id: DEC-26-01
title: "Parser de content.xml híbrido (DOM para estructura + descifrado/hash conservados)"
status: Accepted
date: 2026-06-09
tracking_issue: 26
legacy_id: DEC-0039
deciders:
  - erseco
  - claude-code
sources:
  - REPO-004
  - REPO-005
related:
  adrs: [DEC-13-01, DEC-13-10]
ai_assistance:
  tool: claude-code
  model: claude-opus-4-8
---

# DEC-26-01: Parser de content.xml híbrido (DOM para estructura + descifrado/hash conservados)

## Contexto

El informe técnico comparativo (`mod_exescorm`/`mod_exeweb`/`mod_exelearning`) y el
encargo de mejora señalan que `classes/local/package.php` extrae los iDevices
calificables de `content.xml` con **expresiones regulares** (6 patrones, cero APIs
XML). El docblock que lo justificaba afirmaba evitar `XMLReader` "para no requerir
libxml + ext-zip en backports".

Riesgos de la aproximación regex enumerados en el encargo: falsos positivos,
cambios de formato, **namespaces**, comillas/orden de atributos, **entidades XML**,
contenido multilínea/**CDATA**, XML grande y XML inválido/corrupto sin diagnóstico.

## Hallazgo

1. **La premisa del comentario es falsa.** `admin/environment.xml` de Moodle declara
   `dom`, `xml`, `simplexml` y `xmlreader` como `level="required"` en **todo** el
   rango soportado (4.5 LTS → 5.2). Un parser basado en `DOMDocument` está siempre
   disponible; no hay backport que lo impida.
2. **El formato real es XML válido y con particularidades.** Los `.elpx` v4 reales
   (`research/fixtures/elpx/*`):
   - Declaran un **DTD externo en el prólogo**: `<!DOCTYPE ode SYSTEM "content.dtd">`.
   - Usan **CDATA** para `jsonProperties`/`htmlView` (HTML/JSON embebido).
   - Usan **namespace por defecto** `<ode xmlns="http://www.intef.es/xsd/ode">`.
3. **El descifrado no es XML-parseable.** El flag `isScorm` de la familia "exe-game"
   vive en un div `*-DataGame` ofuscado (`unescape` + XOR 146, DEC-13-10) dentro de
   `htmlView`: eso es contenido de texto, no estructura XML.

## Decisión

Parser **híbrido**, no reescritura total:

- **Estructura → DOM.** `detect_gradable_idevices()` carga `content.xml` con
  `DOMDocument` y recorre los iDevices por `local-name()` (robusto a prefijos de
  namespace), atribuyendo cada uno a la página más reciente y reuniendo su "región"
  (nodo id + hermanos siguientes hasta el siguiente iDevice/página). Cubre los dos
  serializados de v4 (sueltos en `<odeNavStructure>` y envueltos en `<odeComponent>`).
- **Payload/descifrado/hash → conservados.** El `isScorm` (las tres fuentes de
  DEC-13-01/DEC-13-10), `decrypt_datagame()` y `hash_idevice_block()` se reutilizan **sin
  cambios de lógica**, operando sobre las cadenas que el DOM ya decodifica
  (entidades + CDATA) — el descifrado XOR sigue intacto.
- **Fallback resiliente.** Si el XML está **malformado**, `load_dom()` registra el
  primer error de libxml (`debugging`, traza técnica) y se degrada al **escáner
  regex** histórico (renombrado `detect_gradable_idevices_regex()`), de modo que un
  export raro o corrupto sigue funcionando lo mejor posible y el motivo queda en log.
- **Seguridad XXE/entity-expansion.** Se carga con `LIBXML_NONET` y **sin**
  `LIBXML_DTDLOAD`/`LIBXML_NOENT`: libxml nunca descarga el `content.dtd` externo ni
  sustituye entidades → XXE y entidades externas son inertes. El único vector
  restante (subconjunto **interno** de entidades, billion-laughs) se rechaza tras el
  parseo (`$dom->doctype->entities->length > 0`); un paquete legítimo no lo tiene.

## Consecuencias

- **Bug crítico detectado por los fixtures reales:** una primera versión rechazaba
  cualquier `<!DOCTYPE>`, lo que habría descartado **todos** los paquetes reales (que
  llevan `<!DOCTYPE ode SYSTEM "content.dtd">`). Corregido: se acepta el DTD externo
  y solo se rechazan entidades internas.
- **`contenthash` se recalcula una vez.** El hash pasa a computarse sobre la
  serialización DOM de la región (antes, sobre el corte de bytes crudo), así que su
  **valor** cambia respecto al guardado por el parser anterior. Efecto acotado y
  auto-sanado: el hash solo se recomputa cuando el paquete cambia de revisión
  (subida/edición real), y el aviso "las notas pueden estar obsoletas" (DEC-12-01) es
  **informativo** (nunca bloquea). No se añade migración: sería sobreingeniería para
  un aviso informativo de una sola vez.
- La detección deja de depender del orden de bytes: tolera namespaces con prefijo,
  entidades, comillas/orden de atributos y CDATA multilínea. El XML inválido ahora
  deja diagnóstico en log en vez de fallar en silencio.

## Implementación

- `classes/local/package.php`:
  - `detect_gradable_idevices()` despacha DOM→fallback.
  - `load_dom()` (carga segura + diagnóstico + rechazo de entidades internas),
    `detect_from_dom()`, `collect_region()`, `region_reports_score()`,
    `scan_isscorm_flag()`, `scan_datagame_isscorm()` (nuevos/refactor de estructura).
  - `detect_gradable_idevices_regex()` (escáner histórico como fallback),
    `extract_tag()`, `idevice_reports_score()` (puente para el fallback).
  - `decrypt_datagame()` y `hash_idevice_block()` sin cambios de lógica.
- Tests (`tests/package_test.php`, 22 verdes): los 9 de regresión + namespaces con
  prefijo, XML inválido con log+fallback, **entidades internas rechazadas**, **DTD
  externo aceptado**, atributos en distinto orden/comillas, entidades en pageName,
  CDATA multilínea, sin `content.xml`, paquete corrupto, paquete grande (250),
  **cross-check DOM↔regex** sobre paquetes reales, y dos **fixtures reales**
  (`tests/fixtures/real-multipage.content.xml`, `real-datagame.content.xml`).
