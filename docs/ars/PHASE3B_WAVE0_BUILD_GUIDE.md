# Phase 3B Wave 0 — Build Guide

## Prerequisites (local only)

- macOS/Linux with curl  
- Tailwind standalone CLI saved at `modules/ars/assets/bin/tailwindcss` (gitignored; ~54MB)  
- **Node.js is not required on Hostinger.** Compile locally, upload `dist/` + `vendor/` + `js/`.

### Download Tailwind CLI

```bash
# macOS x64 example — Wave 0 used v3.4.17
mkdir -p modules/ars/assets/bin
curl -fsSL -o modules/ars/assets/bin/tailwindcss \
  https://github.com/tailwindlabs/tailwindcss/releases/download/v3.4.17/tailwindcss-macos-x64
chmod +x modules/ars/assets/bin/tailwindcss
```

Linux x64: use `tailwindcss-linux-x64` from the same release.

## Build commands

```bash
cd modules/ars/assets
./build.sh dev     # unminified dist/ars-app.css
./build.sh prod    # ars-app.css + ars-app.min.css
```

## Isolation

| Setting | Value |
|---------|-------|
| Root | `#ars-app` |
| `important` | `#ars-app` |
| Preflight | **disabled** |
| Content | Staff tools/components/`ars_ui.php` only |
| Excluded | `stay/**`, `api/customer/**` |

## Cache busting

`ars_ui_assets()` appends `?v=filemtime` for CSS/JS.

## Vendor versions

| Asset | Version | Path |
|-------|---------|------|
| Tailwind CLI | 3.4.17 | `assets/bin/tailwindcss` |
| Alpine.js | 3.14.8 | `assets/vendor/alpine.min.js` |
| Lucide | 0.469.0 | `assets/vendor/lucide.min.js` |
| Plus Jakarta Sans | self-hosted woff2 | `assets/vendor/fonts/plus-jakarta-sans/` |

## Update process

1. Replace vendor file(s) with pinned version.  
2. Rebuild CSS.  
3. Smoke showcase + coexistence probe.  
4. Document version bump in Wave completion notes.

## Hostinger deploy note

Upload compiled `dist/ars-app.min.css`, vendor JS/fonts, and `js/ars-ui.js`. Do not run Node or Tailwind CLI on the server.
