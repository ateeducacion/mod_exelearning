---
id: DEC-13-10
title: "Detección de isScorm también en el DataGame cifrado (issue #13: solo 12 de 30)"
status: Accepted
date: 2026-06-08
tracking_issue: 13
legacy_id: DEC-0037
deciders:
  - erseco
  - claude-code
sources:
  - REPO-004
related:
  adrs: [DEC-13-01]
ai_assistance:
  tool: claude-code
  model: claude-opus-4-8
---

# DEC-13-10: Detección de isScorm también en el DataGame cifrado (issue #13: solo 12 de 30)

## Contexto

Tras DEC-13-01 (detección por `isScorm > 0`, leído de `<jsonProperties>` y, por la
enmienda, de `<htmlView>` en texto plano), un usuario reporta en el issue #13
(<https://github.com/ateeducacion/mod_exelearning/issues/13#issuecomment-4648392513>)
que **no todas las actividades evaluables pasan al libro de calificaciones**. En el
paquete de prueba `superelpx` con **30 iDevices** (uno de cada tipo, casi todos con
`isScorm:1`), el plugin solo detecta **12**: map, form, interactive-video,
trueorfalse, trivial, beforeafter, dragdrop, flipcards, relate, scrambled-list,
mathematicaloperations, periodic-table. Probado en Moodle Playground.

## Hallazgo

eXeLearning v4 serializa la config de los iDevices en **dos formas** dentro de
`content.xml`:

1. **Texto plano** (en `jsonProperties` o en `htmlView`): los 12 detectados. Su
   `"isScorm":N` es visible para el regex de DEC-13-01.
2. **Cifrada en un div oculto `*-DataGame`** dentro de `htmlView`: la familia
   "exe-game" (guess, discover, identify, classify, quick-questions(×3),
   az-quiz-game, crossword, word-search, padlock, challenge, select-media-files,
   complete, sort, mathproblems, puzzle, hidden-image). Su `jsonProperties` está
   vacío y el `htmlView` no contiene `isScorm` en claro, por lo que la detección
   nunca los veía.

El cifrado es el de eXeLearning, función `decrypt` en `libs/common.js`
(`idevices`): `unescape(str)` (entiende `%XX` y `%uXXXX`) seguido de **XOR con la
clave fija 146 (0x92)** por carácter. Cita literal del runtime upstream:

```js
decrypt: function (str) {
    str = unescape(str);
    const key = 146;
    let pos = 0, ostr = '';
    while (pos < str.length) {
        ostr += String.fromCharCode(key ^ str.charCodeAt(pos));
        pos += 1;
    }
    return ostr;
}
```

Al descifrar el `DataGame` de cada iDevice no detectado aparece `"isScorm":1`
(o `0` en los no evaluables: `puzzle`, `hidden-image`). El runtime ya reportaba
estos juegos al SCORM (llaman a `registerActivity` por tener `isScorm>0`); el único
fallo estaba en la **detección al subir**, que no creaba el grade item.

## Decisión

Añadir una **tercera fuente** de lectura del flag a `idevice_reports_score()`:
descifrar el/los div `*-DataGame` del `htmlView` con el mismo algoritmo que
eXeLearning (`unescape` + XOR 146) y buscar `"isScorm":N` en el resultado. El
iDevice se considera calificable si **cualquiera** de las tres fuentes
(jsonProperties, htmlView plano, DataGame descifrado) da `> 0`; se toma el máximo
para que un `0` en claro no oculte un `1` cifrado. No cambia el contrato ni el
runtime de scoring (`track.php` enruta por `objectid`, agnóstico al tipo).

## Consecuencias

- Conteos medidos sobre los paquetes reales del reporte (fixtures nuevos):
  `actividad-evaluable_2` 1→**2**, `_3` 3→**6** (2 son `text`), `4` 4→**6**,
  `superelpx` 12→**28** de 30. Los 2 restantes (`puzzle`, `hidden-image`) tienen
  `isScorm:0` real → se excluyen correctamente. **No** hay sobre-detección.
- La detección deja de depender de que eXeLearning materialice la config en claro;
  cubre la familia que la guarda ofuscada.
- Si upstream cambiara la clave XOR, habría que actualizar `decrypt_datagame()`
  (queda documentado y referenciado a `libs/common.js`).

## Implementación

- `classes/local/package.php`: nuevos helpers privados `extract_isscorm_datagame()`
  (localiza divs `*-DataGame`, descifra y lee el flag, máximo de varios) y
  `decrypt_datagame()` (réplica de `decrypt`: `unescape` con `%XX`/`%uXXXX` + XOR
  146 vía `core_text::code2utf8`). `idevice_reports_score()` combina las tres
  fuentes por máximo.
- Tests: `tests/package_test.php` — helpers `encrypt_datagame()` /
  `encrypted_datagame_htmlview()` y casos `test_isscorm_in_encrypted_datagame_detected`
  (cifrado `1` detectado, `0` excluido) y `test_encrypted_datagame_unicode_unescape_detected`
  (rama `%uXXXX`).
- Fixtures: `research/fixtures/elpx/actividad-evaluable_2|_3|4.elpx` y
  `superelpx.elpx` (export completo) como evidencia reproducible del 12→28.
