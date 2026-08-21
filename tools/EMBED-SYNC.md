# Shared embedder drift control

eXeLearning's secure-iframe support vendors the same bridge logic into several repos.
`tools/check-embed-sync.mjs` verifies the copies have not drifted (a required logic invariant
is present in every copy).

## Canonical sources

- **Promote-to-parent embed relay + shim** (`exe_embed_relay.js`, `exe_embed_shim.js`, sandbox
  PHP): **mod_exelearning** is canonical; `wp-exelearning`, `omeka-s-exelearning` and Procomún
  mirror it.
- **Modal media-bridge policy** (`exe_media_policy.js`): **eXeLearning core**
  (`public/app/common/exe_media_bridge/`) is canonical; mod/wp/omeka/procomun vendor it.
- **Modal media host** (`exe_media_host.js`): **mod_exelearning** is canonical for the
  raw-postMessage host that wp/omeka/procomun mirror. eXe **core** ships a *separate*
  SDK-based host fork (`exe-media-host.js`), so it is intentionally not compared for `mediahost`.

## Run

```bash
node tools/check-embed-sync.mjs \
  --core <eXe> --wp <wp-exelearning> --omeka <omeka-s-exelearning> --procomun <procomun>
```

Exits non-zero on drift. The check normalises whitespace and quote style, so tabs-vs-spaces and
the IIFE/dual-export wrapper do not count as drift; only missing logic invariants do.

## On a protocol change

Update the canonical file, copy the logic into each mirror (keeping each repo's wrapper and
comment header), add the new guarantee to the relevant `*_INVARIANTS` list in
`check-embed-sync.mjs`, and re-run the checker against every mirror.

## CI

There is no shared CI infra across these repos, so the checker is not yet a per-repo CI gate.
Until shared infra exists, running it (with the mirror flags) is a **required step in the PR
checklist** whenever a shared embedder file changes. A repo can wire it into CI by sparse-cloning
the canonical files of the other repos and running the checker with the mirror flags.
