---
id: REPO-010
titulo: "Procomún — plataforma de recursos educativos que abre .elpx de forma segura"
tipo: lms-consumer
ruta_local: /Users/ernesto/Downloads/git/procomun
rama_consultada: fix/apertura-segura-elpx
commit_consultado: "18b8d1a4 (clon local 2026-06-19)"
fecha_consulta: 2026-06-19
licencia: "[PENDIENTE: confirmar]"
rol_para_mod_exelearning: "Consumidor/player hermano: renderiza paquetes .elpx no confiables con origen opaco y promociona los embeds de vídeo/PDF al padre (mismo modelo de seguridad que DEC-80-03, variante click→modal). Punto de comparación de la solución de embeds."
herramienta_ia:
  interfaz: claude-code
  modelo: claude-opus-4-8
---

## Hechos

- **Rol:** plataforma (Astro/React + API) que cataloga y previsualiza recursos `.elpx`. Como
  `mod_exelearning`, es un **consumidor** de paquetes no confiables (no el productor).
- **Apertura segura del `.elpx`** (decisiones `docs/negocio/decisiones/0026`+`0027` del repo de
  Procomún, que conserva su propia numeración)**:** sirve el contenido del autor en un iframe cuyo
  `sandbox` **omite `allow-same-origin`** (origen opaco, `origin="null"`); además repite el sandbox
  como **directiva CSP `sandbox`** en la respuesta HTTP (`elpx-content.ts:224-225`), así que la
  opacidad aguanta aunque se abra en pestaña nueva. CSP también fija `frame-src 'self' https:`,
  `frame-ancestors 'self'`, `connect-src 'self'`.
- **Embeds promote-to-parent (variante click→modal):** la opacidad se propaga a los iframes anidados
  → YouTube/Vimeo/PDF salen en blanco. Solución = un **shim** en el contenido
  (`apps/api/static/elpx/embed-shim.js`) que detecta origen opaco, sustituye cada embed por una
  fachada y postMessea `{url, geometría}` al padre; un **relay** en el padre
  (`use-elpx-embed-relay.ts`) autentica por **identidad de ventana** (`event.source === contentWindow`,
  no por `event.origin` que es `"null"`), valida la URL (`classifyEmbed`, `elpx-embed-policy.ts`:
  https + sin userinfo + cross-origin) y abre un **modal/lightbox** (`EmbedModal.tsx`) con el player
  real en el origen del proveedor (SOP lo aísla del host).
- **YouTube:** canonicaliza a `youtube-nocookie` (`elpx-embed-policy.ts:64-78`). **Vimeo: SIN rama
  de canonicalización** (sólo funciona por promoción genérica open-mode, sin reescritura de privacidad).
- **Trampas/limitaciones:** el modo open no tiene allowlist (cualquier https cross-origin se promociona);
  el player del modal mantiene `allow-same-origin` (seguro **sólo** porque la URL está garantizada
  cross-origin); PDF sin sandbox (visor nativo); fachada en thumbnails que no monta modal (UI muerta);
  requiere `Access-Control-Allow-Origin: *` + `crossorigin=anonymous` en los `<script>` del paquete;
  interactive-video roto (misma limitación que DEC-80-03).
- **Documentación propia:** `docs/negocio/decisiones/0026-apertura-segura-elpx.md`,
  `0027-reproduccion-segura-embeds-elpx.md`. Commits `cda2d108`, `34aadfab`, `18b8d1a4`.

Comparado en detalle en **AN-015**.
