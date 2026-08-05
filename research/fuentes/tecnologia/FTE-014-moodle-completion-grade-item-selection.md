---
id: FTE-014
titulo: "Moodle: selección del grade item para la finalización por nota (completiongradeitemnumber, modelo workshop)"
categoria: api-moodle
version_consultada: "Moodle 4.5 LTS (v4.5.0) y 5.x (main) — comportamiento equivalente"
enlaces_oficiales:
  - https://github.com/moodle/moodle/blob/v4.5.0/completion/classes/form/form_trait.php
  - https://github.com/moodle/moodle/blob/v4.5.0/course/moodleform_mod.php
  - https://docs.moodle.org/dev/Conditional_activities
context7:
  library_id: /moodle/moodle
  query: "completiongradeitemnumber select form_trait completion grade item component_gradeitems get_itemname_mapping_for_component completionpassgrade multiple grade items"
  fecha: 2026-06-08
  version_devuelta: "moodle/moodle (v4.5.0 + main) — leído del código del core vía raw.githubusercontent."
fecha_consulta: 2026-06-08
relevancia_para_mod_exelearning: "Fundamenta DEC-25-01: la finalización por nota puede apuntar a cualquier grade item declarado por el módulo (modelo workshop), por lo que mod_exelearning no necesita un overall oculto permanente en peritem; basta con apuntar completiongradeitemnumber a un iDevice, o usar el modo OVERALL."
herramienta_ia:
  interfaz: claude-code
  modelo: claude-opus-4-8
---

## Qué es

Ficha del mecanismo del core de Moodle que decide **contra qué grade item** se evalúa
la finalización por nota («exigir nota» / «exigir nota para aprobar») cuando un módulo
declara **varios** grade items (como `mod_workshop`: envío + evaluación, o
`mod_exelearning`: overall + N iDevices).

> Método: leído del código del core (rutas citadas) en `v4.5.0` y `main`.

## H1 — `completiongradeitemnumber` elige el item de finalización

`course_modules.completiongradeitemnumber` (unsigned int, default 0; `NULL` = la nota
no se usa para finalización) indica **qué grade item** del módulo conduce la
finalización: `0,1,…,N` = primer/segundo/N-ésimo item que suministra la actividad. Es
el equivalente moderno de «usa la primera nota» de la doc histórica, pero ahora **con
UI** para elegir.

## H2 — El `<select>` aparece cuando hay más de un grade item

El elemento de formulario lo crea el **trait de finalización** del core,
`completion/classes/form/form_trait.php` (no `course/moodleform_mod.php`, que solo
**valida**). La lógica:

- Si el módulo declara **un** grade item → se usa `completionusegrade`
  (checkbox simple, sin selector).
- Si declara **más de uno** (`else if (count($itemnames) > 1)`) → se añade un
  `<select>` **visible** `completiongradeitemnumber` con las opciones construidas desde
  `core_grades\component_gradeitems::get_itemname_mapping_for_component($component)`
  (cada `itemnumber → grade_<itemname>_name`).

```php
$itemnames = component_gradeitems::get_itemname_mapping_for_component($component);
// ...
$group[] =& $mform->createElement('select', $completiongradeitemnumberel, '', $options);
```

`course/moodleform_mod.php` valida la elección: `badcompletiongradeitemnumber` si el
item elegido no tiene nota configurada, y `activitygradetopassnotset` si
`completionpassgrade` está activo pero ese item no tiene `gradepass`.

## H3 — Consecuencias para un módulo multi-item

1. La finalización por nota **no** está atada al `itemnumber=0`: el profesor puede
   apuntarla a cualquier item declarado (modelo workshop).
2. Las opciones salen del **mapeo estático** del componente, no de los grade items que
   existen de verdad. En `mod_exelearning` el mapeo es `0..MAX_ITEMNUMBER` (101
   entradas en `classes/grades/gradeitems.php`), así que el `<select>` **siempre**
   aparece y lista 101 opciones, aunque el paquete tenga 2 iDevices (UX a mejorar:
   recortar el cap o filtrar a items existentes).
3. Un item **oculto** no se filtra del `<select>` (no hay lógica que lo excluya), pero
   ocultar no es necesario: en `peritem`, mod_exelearning deja los iDevices visibles y
   borra el overall (DEC-25-01).

## Aplicación a mod_exelearning

- **DEC-25-01**: como la finalización puede apuntar a un iDevice, se elimina el overall
  oculto en `peritem` (que solo existía para `completionpassgrade`, DEC-0-10). La
  finalización «aprobar la actividad entera» vive en el modo OVERALL (overall visible
  con `gradepass`); la finalización sobre un ejercicio concreto usa
  `completiongradeitemnumber` apuntando a ese iDevice.
