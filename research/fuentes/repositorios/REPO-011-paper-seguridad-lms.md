---
id: REPO-011
titulo: "Paper de seguridad — contenido no confiable en LMS (SoK / principio de origen opaco + promote-to-parent)"
tipo: paper
ruta_local: /Users/ernesto/Downloads/git/lms-untrusted-content-security-paper
rama_consultada: main
commit_consultado: "bb8fbc1 (clon local 2026-06-19)"
fecha_consulta: 2026-06-19
licencia: "CC-BY + MIT (dual)"
rol_para_mod_exelearning: "Marco teórico (SoK) del problema que DEC-80-01/0061 implementan: ejecutar JS de autor no confiable en el MISMO origen que la sesión LMS es el peligro; la mitigación es origen opaco + CSP sandbox + bridge postMessage validado. Nombra el modelo promote-to-parent para vídeo."
herramienta_ia:
  interfaz: claude-code
  modelo: claude-opus-4-8
---

## Hechos

- **Tesis central:** el peligro no es «JavaScript» sino ejecutar JS de **autor no confiable** en el
  **mismo origen** que la sesión autenticada del LMS. Mitigación: renderizar el recurso entero
  (`.elpx`/SCORM/`mod_page`) en un **iframe de origen opaco** (`sandbox allow-scripts` SIN
  `allow-same-origin` → `origin=null`) + directiva **CSP `sandbox`** en la respuesta + **bridge
  `postMessage` validado** para el estado.
- **Dilema central (§6.2, `:249`):** el origen opaco **rompe los embeds de terceros** (YouTube/Vimeo)
  porque los flags `sandbox` **se propagan** a los iframes anidados (el player hereda el origen opaco
  y pierde el suyo → blanco).
- **Solución de vídeo (§6.2/§6.3, `:295,297,299,303`):** **promote-to-parent** — el relay del padre
  (fuera del sandbox) valida el mensaje por **identidad de ventana** (`event.source === iframe.contentWindow`,
  NO `event.origin` que es `"null"`; contrasta con el origen-trust roto de H5P), revalida la URL
  (https + cross-origin), y monta el player real en el padre, donde el SOP lo aísla — «**el modelo de
  confianza que Moodle ya usa para incrustar YouTube**» (`:299`). Invariante **sin allowlist**
  (`https + cross-origin al LMS`, `:303`) que cubre YouTube/Vimeo/Dailymotion; alternativas
  conservadoras = modo estricto (allowlist) y oEmbed server-side.
- **Defensa en profundidad:** CSP de respuesta con `connect-src 'self'` (cierra el exfil principal),
  `object-src 'none'`, `frame-ancestors 'self'`, `base-uri 'none'`; residuo acotado = pixel GET que
  exfiltra el token de fichero de **sólo lectura** (no el `sesskey`, confinado al padre) vía
  `img/media/frame-src https:`; un perfil CSP estricto opcional lo cierra.
- Variante **fachada+modal** citada como más simple (sin sincronía de geometría), mismo modelo de
  seguridad (`:297`).
- Fuentes: `seguridad-html-js-recursos-educativos.md` (ES) + `security-html-js-educational-resources.en.md`
  (EN) + `anexos-tecnicos.md`. Verificación adversarial del bridge 7/7 (`:280`).

Es el marco del que parten DEC-80-01/DEC-80-02/DEC-80-03. Comparado en **AN-015**.
