---
id: REPO-009
titulo: "Moodle-PHP-Libs (ADL) — vendor-bundle de Composer, DESCARTADO como dependencia"
tipo: libreria
ruta_local: "[no clonado; consultado vía GitHub API/raw]"
url_upstream: "https://github.com/adlnet/Moodle-PHP-Libs"
commit_consultado: "main @ 908d7298e8bf70700c2dcac74b6d2732617d3f4b (consultado 2026-06-17)"
fecha_consulta: 2026-06-17
licencia: "Sin licencia agregada declarada (GitHub API license:null; 404 en /LICENSE). Cada subcarpeta vendor conserva la suya."
rol_para_mod_exelearning: "Ninguno operativo. Ficha de DESCARTE: deja por escrito que NO es candidato a dependencia (regla 'no vendorar', DEC-0-02) y que no contiene código xAPI. Rol: ignore."
herramienta_ia:
  interfaz: claude-code
  modelo: claude-opus-4-8
---

## Hechos

- **No es una librería xAPI/cmi5/LRS.** Es un **bundle de dependencias Composer** para compilar Moodle.
  El README declara textualmente: *«A collection of libraries to support building the latest version of
  Moodle (Currently 5.2.0)»*, generado con `php composer.phar install --no-dev --optimize-autoloader`.
- La raíz contiene `README.md` + `autoload.php` (bootstrap estándar de Composer) y **~39 carpetas vendor**
  (`adodb`, `aws`, `firebase`, `guzzlehttp`, `monolog`, `phpmailer`, `symfony`, `slim`, `php-di`, `openspout`,
  `simplepie`, `mustache`, `nikic`, `psr`, `spatie`, …). **No hay clases propias** de statements/LRS/cmi5.
- El nombre es **engañoso**: pese a venir del ecosistema ADL, **no** aporta construcción ni validación de
  statements xAPI. La lógica real cmi5/xAPI vive en el plugin consumidor `mod_cmi5launch` (ver REPO-008),
  cuyo `classes/local/cmi5_connectors.php` habla por REST con un player+LRS externos (no construye statements).

## Rutas clave

| Ruta | Rol |
|---|---|
| `README.md` | Declara que es colección de librerías para compilar Moodle 5.2.0 vía `composer install` |
| `autoload.php` | Bootstrap estándar de Composer (`ComposerAutoloaderInit…::getLoader()`), sin API propia |
| `firebase/` | `firebase/php-jwt` (BSD-3) — único componente JWT potencialmente relevante a tracking |
| `guzzlehttp/` | cliente HTTP (MIT) |
| *(sin)* `LICENSE` | **HTTP 404** — no hay archivo de licencia a nivel de repo |

## Contratos relevantes

- Ninguno propio. El repo no expone API; es un volcado de `vendor/`.

## Modelo de datos

- N/A.

## Capacidades respecto a `mod_exelearning`

- **Tracking (xAPI/cmi5):** ninguna. No construye ni valida statements.
- Lo único reutilizable conceptualmente —`firebase/php-jwt` para firmar/verificar un token de sesión— **ya
  está en Moodle core** (`lib/php-jwt`, invocable como `\Firebase\JWT\JWT`), igual que `guzzlehttp`
  (`\core\http_client`). No hay que tomarlos de aquí.

## Riesgos / Limitaciones

- **Sin licencia agregada** (`license: null`, 404 en `LICENSE`): redistribuir el bloque es **legalmente
  ambiguo**, aunque cada paquete interno sea OSI. **Choca de frente con la regla «no vendorar»** del proyecto
  (DEC-0-02). → **No vendorar.**
- Su modelo de uso (cmi5-launch + LRS externo de `mod_cmi5launch`) es **justo el que `mod_exelearning` NO
  sigue** (recurso HTML embebido, grading multi-item server-side). Utilidad como referencia de diseño: baja.
- `[verificado parcialmente]` No se inspeccionó recursivamente cada subcarpeta vendor (límites API/WebFetch),
  pero las muestras (`firebase/php-jwt`, `adodb`, `aws`) confirman que son paquetes Composer estándar.

## Preguntas abiertas

- PREG: si en el futuro `mod_exelearning` necesitara firmar un token de sesión para un endpoint xAPI con
  actor delegado, usar `\Firebase\JWT\JWT` de **core** y declarar en `thirdpartylibs.xml` solo lo que el
  plugin realmente empaquete (hoy: `pipwerks`). **No** tomar JWT/guzzle de este repo.
