---
id: FTE-007
titulo: "Moodle core_xapi"
categoria: api-moodle
version_consultada: "Moodle 4.x"
enlaces_oficiales:
  - https://moodledev.io/docs/apis/subsystems/xapi
context7:
  library_id: /websites/moodledev_io_5_2
  query: "core_xapi handler statement_to_event activity module xapi statement processing"
  fecha: 2026-05-28
  version_devuelta: "Moodle 5.2 dev docs"
fecha_consulta: 2026-05-28
relevancia_para_mod_exelearning: "Pieza central si DEC-0-03 elige xAPI: handler que mod_exelearning extiende para recibir statements desde el iframe del paquete."
herramienta_ia:
  interfaz: claude-code
  modelo: claude-opus-4-7
---

## Qué es

Subsistema Moodle que estandariza la recepción y persistencia de xAPI statements desde
contenido embebido o externo.

## Conceptos clave

- `core_xapi\handler` (abstract base).
- Endpoints REST internos: `core_xapi_post_statement`, `core_xapi_post_state`, etc.
- Tabla `core_xapi_state` para persistir estados.
- `core_xapi\local\statement` (objeto statement validado).

## API / Puntos de extensión relevantes

Para un plugin componente `mod_exelearning`:

```
classes/xapi/handler.php
  namespace mod_exelearning\xapi;
  class handler extends \core_xapi\handler {
      protected function statement_to_event(local\statement $statement): ?\core\event\base { ... }
      protected function supports_group_actors(): bool { return false; }
  }
```

El handler:
- Filtra verbos relevantes (`answered`, `completed`).
- Parsea `object.id` para mapear a un `itemnumber` interno.
- Persiste interacción en tabla propia (`mdl_exelearning_attempt`).
- Llama `exelearning_grade_item_update(...)` para empujar al gradebook.

## Soporte para multi-grade-items

Indirecto pero excelente: el componente decide a qué `itemnumber` afecta cada
statement.

## Implementaciones de referencia consultadas

- REPO-004 — `public/lib/xapi/classes/handler.php`
- REPO-004 — `public/mod/h5pactivity/classes/xapi/handler.php`
- REPO-004 — `public/mod/h5pactivity/classes/local/grader.php`

## Webservice de entrada confirmado

Evidencia Context7 (`/websites/moodledev_io_5_2`): el endpoint canónico es
`core_xapi_statement_post`, no un endpoint propio del plugin. Recibe `component`
(frankenstyle, p.ej. `mod_exelearning`) y `statements` (string JSON, statement único
o array). Devuelve un array de booleanos paralelo a la entrada.

```http
POST /webservice/rest/server.php?wsfunction=core_xapi_statement_post
```

```json
{
  "component": "mod_exelearning",
  "statements": "[{\"actor\":{...},\"verb\":{\"id\":\"http://adlnet.gov/expapi/verbs/answered\"},\"object\":{\"id\":\"https://exelearning.net/xapi/activity/idevice/<uuid>\"},\"result\":{\"score\":{\"scaled\":0.8}}}]"
}
```

Plantilla canónica de handler (de moodledev.io 5.2):

```php
namespace mod_exelearning\xapi;

use core_xapi\handler;
use core_xapi\local\statement\statement;
use core\event\base;

class handler extends \core_xapi\handler {
    public function statement_to_event(statement $statement): base {
        // Convertir el statement en evento Moodle válido.
        return \mod_exelearning\event\idevice_answered::create_from_statement($statement);
    }
    public function supports_group_actors(): bool {
        return false;
    }
}
```

Responsabilidades de `statement_to_event()` (cita docs):
1. Verificar permisos del usuario sobre el statement.
2. Devolver un evento Moodle válido (que será disparado por el subsistema).
3. Procesar el `result` (score → gradebook).

Si el handler devuelve `null`, el statement queda como "no procesado".

## Riesgos / Limitaciones

- Requiere autenticación (Moodle session o token); CSRF debe gestionarse en el shim JS
  que el paquete usará para emitir statements.
- Versión mínima de Moodle: comprobar si `core_xapi` está en la versión target.

## Preguntas abiertas

- Versión mínima de Moodle objetivo. PREG futura.
