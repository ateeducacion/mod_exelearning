---
id: REPO-006
titulo: "contenttype_exelearning — tipo de contenido del banco de contenidos para paquetes eXeLearning"
tipo: moodle-plugin
ruta_local: "[PENDIENTE: clonar y registrar ruta local]"
url_upstream: "https://github.com/ateeducacion/moodle-contenttype_exelearning"
commit_consultado: "[PENDIENTE: registrar sha; consultado vía web 2026-06-08]"
fecha_consulta: 2026-06-08
licencia: "GPL-3.0-or-later"
rol_para_mod_exelearning: "Plugin hermano de ATE (mismo ecosistema eXeLearning) que cubre el caso de uso visor/almacén en el banco de contenidos; referencia para la decisión de empaquetado (DEC-0-17) y para una posible librería .elpx común."
herramienta_ia:
  interfaz: claude-code
  modelo: claude-opus-4-8
---

## Hechos

- Component frankenstyle: `contenttype_exelearning`. **Tipo de plugin distinto** a `mod_exelearning`:
  es un *content bank content type*, se instala en `contentbank/contenttype/exelearning`
  (no en `mod/`).
- Metadatos (`version.php`, vía web 2026-06-08):
  - `$plugin->requires = 2024042200` (Moodle **4.4**).
  - `$plugin->supported = [404, 501]` (Moodle 4.4 LTS – 5.1).
  - `$plugin->maturity = MATURITY_STABLE`.
  - Release **v1.0.0** (tag 2026-06-07), `version 2026060700`.
  - **Sin `$plugin->dependencies`** (no depende de `mod_exelearning` ni al revés).
- Mantenedor: Área de Tecnología Educativa (ATE, Canarias) — mismo equipo que `mod_exelearning`.
- Alcance declarado en el README: **visor/almacén** de paquetes eXeLearning. *No* califica
  iDevices interactivos; remite a `mod_exelearning` para actividades calificables.
- Formatos: proyectos nativos `.elpx` y exportaciones web `.zip` (HTML5).

## Rutas clave

| Ruta | Rol |
|---|---|
| `version.php` | Metadatos del plugin (requires/supported/maturity, sin dependencies) |
| `classes/contenttype.php` | Define el tipo de contenido (extiende `\core_contentbank\contenttype`) |
| `classes/content.php` | Clase del ítem de contenido del banco |
| `classes/local/packager.php` | Validación, extracción y render del `.elpx`/`.zip` |
| `classes/privacy/provider.php` | Provider de privacidad (null provider) |

## Relación con `mod_exelearning`

- **Complementarios, no co-dependientes**: el banco de contenidos almacena/visualiza
  (`contenttype_exelearning`); la actividad califica y hace tracking (`mod_exelearning`).
  Cada uno funciona de forma autónoma.
- **Tipos de plugin distintos** ⇒ rutas de instalación fijas y separadas (ver FTE-013).
  No es técnicamente posible servir un `contenttype` desde dentro de un `mod`.
- Madurez divergente: `contenttype_exelearning` STABLE v1.0.0 vs `mod_exelearning` ALPHA.

## Solapamiento de código

- **Mirroring intencional, sin código compartido**: ambos extraen el ZIP `.elpx` al
  almacenamiento de ficheros de Moodle y sirven `index.html` en un `<iframe>` con la **misma
  política sandbox** (`allow-scripts allow-same-origin allow-popups allow-forms
  allow-popups-to-escape-sandbox`). El propio `packager.php` documenta que "el sandbox
  replica el de mod_exelearning".
- No hay `use mod_exelearning\*` ni jerarquía de clases compartida: cada plugin implementa su
  manejo de paquete por separado. Candidato a librería común futura (ver DEC-0-17 / RIE-013).

## Riesgos / Limitaciones

- Duplicación de la lógica de extracción/sandbox `.elpx` entre los dos plugins ⇒ riesgo de
  deriva (RIE-013).

## Preguntas abiertas

- ¿Conviene extraer una librería común de manejo `.elpx` (parser/extracción/sandbox) que
  ambos plugins consuman? (seguimiento de DEC-0-17).
