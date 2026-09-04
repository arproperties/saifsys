#!/usr/bin/env bash
# ARS Wave 0 — Tailwind build (local only; Node not required on Hostinger)
set -euo pipefail
ROOT="$(cd "$(dirname "$0")" && pwd)"
BIN="$ROOT/bin/tailwindcss"
IN="$ROOT/src/ars-app.css"
OUT_DEV="$ROOT/dist/ars-app.css"
OUT_MIN="$ROOT/dist/ars-app.min.css"
CFG="$ROOT/tailwind.runtime.config.js"

if [[ ! -x "$BIN" ]]; then
  echo "ERROR: Tailwind CLI missing at $BIN" >&2
  echo "Download: https://github.com/tailwindlabs/tailwindcss/releases (macos-x64 / linux-x64)" >&2
  echo "Save as modules/ars/assets/bin/tailwindcss and chmod +x" >&2
  exit 1
fi

mkdir -p "$ROOT/dist"

# Runtime config lives next to assets so content globs resolve correctly.
# Stay portal and customer APIs are intentionally excluded.
cat > "$CFG" <<'EOF'
/** @type {import('tailwindcss').Config} */
module.exports = {
  content: [
    '../views/components/**/*.php',
    '../includes/ars_ui.php',
    '../includes/ars_ds.php',
    '../includes/ars_shell.php',
    '../tools/ars_ui_showcase.php',
    '../tools/ars_ui_coexistence_probe.php',
    '../tools/ars_shell_preview.php',
    '../index.php',
    '../reports.php',
    '../calendar.php',
    '../housekeeping.php',
    '../maintenance.php',
    '../guests.php',
    '../guest_view.php',
    '../units.php',
    '../unit_profile.php',
    '../unit_edit.php',
    '../financial_reports.php',
    '../financial_document_view.php',
    '../settings.php',
    '../revenue.php',
    '../blocked_dates.php',
    '../pricing.php',
    '../booking_add.php',
    '../booking_view.php',
    '../bookings.php',
  ],
  important: '#ars-app',
  corePlugins: { preflight: false },
  // Tailwind `.collapse` = visibility:collapse; breaks Bootstrap Collapse (journal lines).
  blocklist: ['collapse'],
  theme: {
    extend: {
      colors: {
        ars: {
          ink: 'var(--ars-color-primary)',
          'ink-hover': 'var(--ars-color-primary-hover)',
          sand: 'var(--ars-color-accent)',
          bg: 'var(--ars-color-bg)',
          surface: 'var(--ars-color-surface)',
          elevated: 'var(--ars-color-elevated)',
          text: 'var(--ars-color-text)',
          muted: 'var(--ars-color-muted)',
          border: 'var(--ars-color-border)',
          focus: 'var(--ars-color-focus)',
          success: 'var(--ars-color-success)',
          warning: 'var(--ars-color-warning)',
          danger: 'var(--ars-color-danger)',
          info: 'var(--ars-color-info)',
        },
      },
      fontFamily: {
        ars: ['Plus Jakarta Sans', 'ui-sans-serif', 'system-ui', 'sans-serif'],
      },
      borderRadius: {
        'ars-sm': '0.375rem',
        'ars-md': '0.5rem',
        'ars-lg': '0.625rem',
        'ars-xl': '0.75rem',
      },
      boxShadow: {
        'ars-sm': 'var(--ars-elevation-sm)',
        'ars-md': 'var(--ars-elevation-md)',
      },
      transitionDuration: {
        'ars-fast': '150ms',
        'ars-base': '200ms',
        'ars-slow': '250ms',
      },
      minHeight: { 'ars-touch': '44px' },
      minWidth: { 'ars-touch': '44px' },
    },
  },
  plugins: [],
  safelist: [
    // Ensure common status/surface utilities survive if string-built
    'bg-green-50', 'bg-orange-50', 'bg-red-50', 'bg-sky-50', 'bg-teal-50',
    'border-green-200', 'border-orange-200', 'border-red-200', 'border-sky-200', 'border-teal-200',
    'border-l-ars-info', 'border-l-ars-warning', 'border-l-ars-danger', 'border-l-ars-success',
    'w-2/3', 'w-full', 'space-y-2', 'animate-pulse', 'divide-y', 'divide-ars-border',
  ],
};
EOF

MODE="${1:-prod}"

build_one() {
  local out="$1"
  local minify_flag="${2:-}"
  # shellcheck disable=SC2086
  (cd "$ROOT" && "$BIN" -c "$CFG" -i "$IN" -o "$out" $minify_flag)
  echo "Built $out ($(wc -c < "$out" | tr -d ' ') bytes)"
}

START=$(date +%s)
case "$MODE" in
  dev|development)
    build_one "$OUT_DEV"
    ;;
  prod|production|*)
    build_one "$OUT_DEV"
    build_one "$OUT_MIN" --minify
    ;;
esac
END=$(date +%s)
echo "Build time: $((END - START))s"
echo "OK — Stay paths excluded from content scan."
