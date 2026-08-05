---
id: DEC-29-01
titulo: "Detectar GeoGebra calificable por la clase auto-geogebra-scorm"
estado: Aceptada
fecha: 2026-06-10
tracking_issue: 29
legacy_id: DEC-0043
agentes:
  - codex
fuentes:
  - REPO-004
relacionados:
  - DEC-13-01
  - DEC-13-10
  - DEC-26-01
  - DEC-13-11
herramienta_ia:
  interfaz: codex
  modelo: gpt-5
---

# DEC-29-01: Detectar GeoGebra calificable por la clase auto-geogebra-scorm

## Contexto

El issue #29 (<https://github.com/ateeducacion/mod_exelearning/issues/29>) reporta
que un paquete con **Video interactivo**, **Verdadero/Falso**, **Quiz adaptativo** y
**GeoGebra** marcados con "Guardar puntuación" crea columnas para los tres primeros,
pero **GeoGebra no aparece en la parrilla de calificación**. El usuario adjunta el
paquete `afectadosparche.zip`.

Este fallo es continuidad del eje del issue #13: el plugin debe detectar solo los
iDevices que el autor marcó para evaluar, pero debe reconocer todas las formas reales
en que eXeLearning serializa esa marca. DEC-13-01 cubrió `"isScorm"` en
`jsonProperties`/`htmlView`; DEC-13-10 cubrió `*-DataGame` cifrado; DEC-26-01 hizo el
recorrido estructural con DOM; DEC-13-11 parcheó un fallo distinto, de **guardado**,
para `form` y `scrambled-list`.

## Hallazgo

En el `content.xml` del paquete de #29 se observa:

```text
interactive-video | isScorm=1
trueorfalse       | isScorm=1
geogebra-activity | isScorm=- | auto-geogebra-scorm
adaptative-quiz   | isScorm=1
```

GeoGebra no serializa la marca evaluable como `"isScorm": 1|2`, ni en
`jsonProperties`, ni en `htmlView` plano, ni en un `DataGame` cifrado. La señal vive
en el HTML exportado como clase CSS `auto-geogebra-scorm`, junto al
`auto-geogebra-ideviceid-<objectid>`.

El runtime upstream lo confirma en
`/Users/ernesto/Downloads/git/exelearning/public/files/perm/idevices/base/geogebra-activity/export/geogebra-activity.js`:

- líneas 349-352: si existe `auto-geogebra-scorm` (o una evaluación externa con id
  válido), se añade la UI de guardado SCORM.
- líneas 373-376: si existe `auto-geogebra-scorm`, se llama a
  `registerActivity(options)`.
- línea 454: las opciones runtime se construyen con `isScorm: 2`.

[INTERPRETACION] Para GeoGebra, `auto-geogebra-scorm` es el equivalente persistido
del opt-in de autor que en otros iDevices aparece como `"isScorm": 1|2`.

## Decisión

Ampliar la lectura de señal evaluable con una cuarta fuente **acotada a
`geogebra-activity`**: si el `htmlView` contiene la clase `auto-geogebra-scorm`, el
iDevice se considera calificable, equivalente a `isScorm = 2`.

No se cambia el criterio general de DEC-13-01: el plugin sigue sin detectar por tipo ni
por whitelist. Un GeoGebra sin `auto-geogebra-scorm` queda fuera del libro.

No se añade `exe-scorm` al `<body>` ni se toca el runtime servido: DEC-13-11 ya
documentó que esa clase activa efectos colaterales del export SCORM. En GeoGebra el
fallo está antes, en la creación del grade item, no en el envío de puntuación.

## Consecuencias

- El paquete de #29 pasa de 3 a 4 iDevices detectados: se añade
  `idevice-1781071454245-o703gzzbq` (`geogebra-activity`).
- La solución mantiene el principio de #13: solo entra en el gradebook lo marcado por
  el autor para guardar puntuación.
- El runtime de tracking no cambia. Cuando el alumno pulse "Guardar puntuación",
  GeoGebra seguirá usando `sendScoreNew()` y el bridge del plugin lo enrutará por
  `objectid` como el resto (DEC-5-01/DEC-26-02).
- Si upstream cambiara el nombre de clase, habría que actualizar el detector. La
  degradación es segura: no se crea una columna espuria; el síntoma volvería a ser
  "GeoGebra no detectado".

## Implementación

- `classes/local/package.php`:
  - `region_reports_score()` recibe también el tipo de iDevice.
  - Nuevo helper `scan_geogebra_scorm_class($type, $html)` devuelve `2` solo para
    `geogebra-activity` con clase `auto-geogebra-scorm`.
  - El fallback regex llama al mismo flujo, pasando el `odeIdeviceTypeName`.
  - `GRADABLE_IDEVICE_TYPES` añade `geogebra-activity` como catálogo informativo.
- `tests/package_test.php`:
  - `test_geogebra_scorm_class_detected()` cubre GeoGebra marcado y no marcado.
  - `test_real_geogebra_fixture_detects_all_scored_idevices()` usa el
    `content.xml` real extraído del adjunto `afectadosparche.zip` del issue #29
    y espera 4 iDevices: interactive-video, trueorfalse, geogebra-activity y
    adaptative-quiz.

## Verificación

- Prueba roja esperada: antes del cambio, `test_geogebra_scorm_class_detected()`
  detectaba 0 iDevices en vez de 1.
- Verificación local de sintaxis y estilo:
  - `php -l classes/local/package.php`
  - `php -l tests/package_test.php`
  - `vendor/bin/phpcs --standard=moodle classes/local/package.php tests/package_test.php`
- Verificación funcional en entorno Moodle: `make test-unit` o `moodle-plugin-ci
  phpunit --fail-on-warning` cuando el contenedor/CI esté disponible.
