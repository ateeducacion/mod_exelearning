---
id: FTE-013
titulo: "Moodle: tipos de plugin, frankenstyle, subplugins, dependencias y empaquetado"
categoria: arquitectura-moodle
version_consultada: "Moodle 4.4–5.2 (docs dev)"
enlaces_oficiales:
  - https://moodledev.io/general/development/policies/codingstyle/frankenstyle
  - https://moodledev.io/docs/apis/plugintypes
  - https://docs.moodle.org/dev/Subplugins
  - https://docs.moodle.org/dev/Content_bank_content_types
  - https://moodledev.io/docs/apis/commonfiles/version.php
  - https://moodledev.io/general/community/plugincontribution
  - https://docs.moodle.org/dev/Git_repositories_for_contrib_modules
fecha_consulta: 2026-06-08
relevancia_para_mod_exelearning: "Base normativa de la decisión de empaquetado mod vs contenttype (DEC-0-17): por qué fusionar es inviable y cómo cooperan plugins de tipos distintos."
herramienta_ia:
  interfaz: claude-code
  modelo: claude-opus-4-8
---

## Qué es

Reglas oficiales de Moodle sobre cómo se nombran, ubican, anidan, dependen y distribuyen los
plugins. Determinan si dos plugins de **tipos distintos** (un módulo de actividad y un tipo de
contenido del banco) pueden fusionarse en un único plugin/repo o deben ir separados.

## Reglas clave

### 1. Frankenstyle = un componente, una carpeta fija por tipo
- Nombre `prefijo_nombre`; el prefijo lo fija el **tipo de plugin** y determina la ruta de
  instalación, que es **fija e inmutable**.
- `mod_exelearning` ⇒ `mod/exelearning`. `contenttype_exelearning` ⇒
  `contentbank/contenttype/exelearning`.
- Consecuencia: **no se puede servir un `contenttype` desde dentro de un `mod`**; son
  componentes distintos en ubicaciones distintas.
- Fuente: https://moodledev.io/general/development/policies/codingstyle/frankenstyle ·
  https://moodledev.io/docs/apis/plugintypes

### 2. Subplugins: lista cerrada de tipos anfitriones
- Solo pueden tener subplugins: **módulos de actividad**, editores HTML (≥2.4), plugins
  `local` (≥2.6) y `admin tools` (≥2.6).
- Un **content bank content type NO puede ser subplugin** de un módulo de actividad ⇒ no hay
  vía de anidamiento `db/subplugins.json`.
- Fuente: https://docs.moodle.org/dev/Subplugins

### 3. Content bank content types
- Extienden `\core_contentbank\contenttype` y se instalan en `contentbank/contenttype/<name>`.
  Se gestionan de forma independiente del resto de subsistemas.
- Fuente: https://docs.moodle.org/dev/Content_bank_content_types

### 4. Directorio de plugins de moodle.org: una entrada por plugin
- Cada plugin se sube por separado, con su propio `version.php`, descripción, repo y cadencia
  de release. No existe una entrada que "contenga" dos plugins.
- Se puede agrupar plugins relacionados como un *set* (contactando con los mantenedores del
  directorio), pero siguen siendo **entradas/componentes distintos**.
- Fuente: https://moodledev.io/general/community/plugincontribution

### 5. Dependencias entre plugins (cooperación entre tipos)
- Cuando dos plugins deben cooperar se declara `$plugin->dependencies` en `version.php`; el
  core impide instalar/actualizar si la dependencia no se cumple.
- Es **opcional**: solo procede cuando uno *requiere* al otro para funcionar.
- Fuente: https://moodledev.io/docs/apis/commonfiles/version.php

### 6. Git y distribución
- Recomendado: **un repo por plugin** (`moodle-<tipo>_<nombre>`).
- Se puede ofrecer un ZIP de conveniencia con varios plugins para descomprimir en la raíz de
  Moodle, pero **ese ZIP no es subible** como entrada única al directorio.
- Fuente: https://docs.moodle.org/dev/Git_repositories_for_contrib_modules

## Precedente: H5P en Moodle
- `mod_h5pactivity` (módulo de actividad) y el tipo de contenido H5P del banco de contenidos
  son **componentes separados que cooperan** (el contenido creado en el banco se añade como
  actividad), no un único plugin fusionado.
- Fuente: https://docs.moodle.org/en/Interactive_Content_-_H5P_activity ·
  https://docs.moodle.org/dev/Content_bank_content_types

## Conclusión aplicable a mod/contenttype eXeLearning
- "Fusionar `contenttype_exelearning` dentro de `mod_exelearning`" **no es posible** en el
  modelo de plugins de Moodle (tipos distintos, rutas fijas, sin anidamiento por subplugin, el
  directorio exige entradas separadas).
- La vía idiomática es **plugins separados**; la cooperación, si se necesitara, se expresa con
  `$plugin->dependencies` (aquí **no** procede: son independientes y complementarios).

## Preguntas abiertas

- Verificar IDs/versiones exactas en Context7 (`/websites/moodledev_io_5_2`) antes de cualquier
  implementación que toque empaquetado.
