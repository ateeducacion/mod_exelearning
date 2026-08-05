---
id: DEC-11-01
titulo: "Traducciones es/ca/eu/gl: reuso de hermanos + marca «~» para traducción automática pendiente de revisión"
estado: Aceptada
fecha: 2026-06-02
tracking_issue: 11
legacy_id: DEC-0020
agentes:
  - erseco
  - claude-code
fuentes:
  - REPO-001
  - REPO-002
relacionados:
  - DEC-0005
  - DEC-0009
herramienta_ia:
  interfaz: claude-code
  modelo: claude-opus-4-8
---

# DEC-11-01: Traducciones es/ca/eu/gl: reuso de hermanos + marca «~» para traducción automática pendiente de revisión

## Contexto

`mod_exelearning` solo disponía de cadenas en inglés (`lang/en/exelearning.php`, 309
cadenas). Los plugins hermanos del mismo equipo —`mod_exeweb` (REPO-002) y
`mod_exescorm` (REPO-001)— ya ofrecen cinco idiomas: `en, es, ca, eu, gl`. El usuario
pidió alinear `mod_exelearning` con ese mismo conjunto de idiomas, reaprovechando las
traducciones humanas existentes en los hermanos donde fuera posible.

El reto de fondo: el grueso de las 309 claves es **específico** de `mod_exelearning`
(calificación por iDevice, intentos, privacidad, informes) y no existe traducido en los
hermanos, por lo que hay que generarlo. Si se traduce automáticamente, hace falta una
forma de que un traductor humano distinga de un vistazo lo ya revisado de lo provisional.

## Problema

¿Cómo entregar ficheros de idioma **completos** (paridad total con `lang/en`) sin
presentar traducción automática como si fuera definitiva, y manteniendo coherencia
terminológica con los plugins hermanos?

## Opciones consideradas

1. **Solo `en` + fallback de Moodle.** No añadir idiomas; Moodle cae a inglés. Descartada:
   no cumple el objetivo de cobertura multilingüe.
2. **Traducir las 309 cadenas a mano, sin marcas.** Máxima calidad pero inviable en una
   sola iteración (1.236 cadenas) y oculta qué se ha verificado de verdad.
3. **Ficheros parciales (solo cadenas nuevas) + fallback.** Mezcla idiomas en la UI y deja
   huecos silenciosos. Descartada.
4. **Reuso humano de hermanos + traducción automática marcada con `~` (ELEGIDA).**
   Ficheros completos; las claves que existen en los hermanos con el mismo significado se
   copian **verbatim** (sin marca); el resto se traduce automáticamente y se **prefija con
   `~`** en el valor.

## Evidencia

- REPO-002 (`mod_exeweb/lang/{es,ca,eu,gl}/exeweb.php`): traducciones humanas del bloque de
  gestión del editor embebido, guardado y `embeddededitor*` reutilizadas en los 4 idiomas.
- REPO-002/REPO-001 (`lang/es/*`): bloque completo de gestión de estilos traducido en
  castellano, reutilizado para `es`.
- REPO-001 (`mod_exescorm/lang/{ca,eu,gl}/exescorm.php`): términos genéricos
  `attempt`/`attempts`/`reports` reutilizados en ca/eu/gl.
- Solapamiento medido: ~119 de 309 claves tienen equivalente humano en algún hermano; las
  ~190 restantes requieren traducción automática.

## Decisión

Adoptar la **opción 4**. Convención del prefijo `~`:

- **Sin `~`** → valor reutilizado verbatim de un plugin hermano (traducción humana ya
  revisada). Mantiene terminología consistente entre los tres plugins.
- **Con `~`** (p. ej. `$string['attempt'] = '~Intento';`) → traducción automática
  **pendiente de revisión humana**. El prefijo es **visible a propósito en la interfaz**
  de Moodle (Moodle no filtra el carácter): una cadena provisional debe *parecer*
  provisional hasta que un traductor la valide y retire el `~`.

Idiomas añadidos: `es, ca, eu, gl`. En `ca/eu/gl` el bloque de estilos no existía en los
hermanos, por lo que va traducido y marcado con `~`; en `es` queda como reuso humano.

## Consecuencias

- Positivas: cobertura multilingüe inmediata y completa; cero huecos (fallback); ruta de
  revisión obvia (`grep '~'`); terminología alineada con los hermanos.
- Negativas / coste: las cadenas `~` se ven con el prefijo en la UI hasta su revisión
  (efecto buscado, no defecto); ~190 cadenas/idioma quedan pendientes de validación humana.
- No altera código PHP del plugin ni `lang/en`; solo añade `lang/{es,ca,eu,gl}/exelearning.php`.

## Riesgos

- Un release que olvide retirar los `~` mostraría cadenas con tilde a los usuarios finales.
  Mitigación: la revisión humana es un paso explícito previo a considerar el idioma
  «completo»; la marca es fácilmente localizable con `grep -n "= '~" lang/*/exelearning.php`.

## Validación

- `php -l` sin errores en los 4 ficheros.
- Paridad exacta de claves contra `lang/en`: 309/309 por idioma, sin claves de más ni de menos.
- Placeholders `{$a}` / `{$a->...}` conservados en todas las traducciones.
- Entrega: PR ateeducacion/mod_exelearning#11 (rama `feature/lang-es-ca-eu-gl`).

## Seguimiento

- Tarea recurrente de revisión humana por idioma: traducir/validar las cadenas `~` y
  retirar el prefijo. El idioma se considera «definitivo» cuando no quedan `~`.
