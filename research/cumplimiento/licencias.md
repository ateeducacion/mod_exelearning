# Licencias — mod_exelearning

`mod_exelearning` se distribuye bajo **GPL-3.0-or-later** (decisión registrada en
DEC-0-02), compatible con Moodle. Lo declara también `composer.json`
(`"license": "GPL-3.0-or-later"`).

## Dependencias y su licencia

| Origen | Licencia | Estado |
|---|---|---|
| Moodle core (subsistemas reutilizados) | GPL-3.0-or-later | OK |
| `core_xapi` | GPL-3.0-or-later | OK |
| Patrones tomados de `mod_workshop`, `mod_h5pactivity`, `mod_scorm` | GPL-3.0-or-later | OK |
| pipwerks `SCORM_API_wrapper.js` / `SCOFunctions.js` (en `assets/scorm/`) | MIT | OK — declarado en `thirdpartylibs.xml` |
| Editor estático eXeLearning v4 (`dist/static/`, solo en el ZIP de release) | AGPL-3.0-or-later | OK — declarado por `scripts/package.sh` |
| Paquetes publicados por eXeLearning (JS/contenido del usuario) | Variable (autor) | No aplica al plugin |
| Patrones tomados de `mod_exescorm`, `mod_exeweb` | esperada GPL-3.0-or-later | Pendiente (auditoría formal) |
| `wp-exelearning` (inspiración) | esperada GPL-2.0-or-later | Pendiente (auditoría formal) |

## Reglas

- Cualquier dependencia con licencia GPL-incompatible (AGPL incluida **si** se enlazara
  como código del plugin, GPLv2-only, comercial) bloquea su inclusión en el árbol del plugin.
- Cualquier contribución se acepta solo si el autor declara compatibilidad con
  GPL-3.0-or-later.

> Nota sobre el editor AGPL: el editor eXeLearning v4 (`dist/static/`) es un artefacto
> externo que se **empaqueta** en el ZIP de release, no se enlaza con el código PHP del
> plugin. No está en el árbol versionado (ver `.gitignore`) y por eso `thirdpartylibs.xml`
> committeado no lo lista; `scripts/package.sh` estampa su declaración AGPL-3.0-or-later en
> la copia del ZIP. La cabecera de `thirdpartylibs.xml` documenta este mecanismo.

## Estado por bucket

### RESUELTO

- **Editor eXeLearning v4** (`dist/static/`): declarado **AGPL-3.0-or-later** por
  `scripts/package.sh` en el `thirdpartylibs.xml` del ZIP de release. Se distribuye como
  artefacto empaquetado, no como código enlazado.
- **pipwerks** `SCORM_API_wrapper.js` y `SCOFunctions.js` (`assets/scorm/`): declarados
  **MIT** en el `thirdpartylibs.xml` committeado. MIT es compatible con GPL-3.0-or-later.
- **Licencia del propio plugin**: GPL-3.0-or-later, declarada en `composer.json` y en las
  cabeceras de los ficheros (DEC-0-02).

### NO APLICA

- **JS y contenido de los paquetes publicados por el autor**: es contenido de usuario, no
  una dependencia del plugin; su licencia la fija el autor del paquete y no afecta a la
  licencia del plugin.

### PENDIENTE-REAL (baja prioridad)

- Auditoría formal de licencias de los **patrones** tomados de `mod_exescorm`, `mod_exeweb`
  y `wp-exelearning` (confirmar cabeceras y SHA de origen, TAREA-001). Se reutilizaron ideas
  y estructuras GPL, no ficheros tal cual, por lo que el riesgo es bajo; queda como debida
  diligencia documental.
