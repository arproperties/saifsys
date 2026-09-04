/** @type {import('tailwindcss').Config} */
/**
 * ARS staff UI — Wave 0 Tailwind config
 * Content scan: internal staff/admin ARS only. Stay portal EXCLUDED.
 * Isolation: important selector #ars-app; corePlugins.preflight = false
 */
module.exports = {
  content: [
    './modules/ars/views/components/**/*.php',
    './modules/ars/includes/ars_ui.php',
    './modules/ars/includes/ars_shell.php',
    './modules/ars/tools/ars_ui_showcase.php',
    './modules/ars/tools/ars_ui_coexistence_probe.php',
    './modules/ars/tools/ars_shell_preview.php',
    './modules/ars/index.php',
    './modules/ars/reports.php',
    // NEVER add: stay/**, api/customer/**
  ],
  // Resolve content paths relative to project root when CLI cwd is assets/
  // build.sh sets --config and runs from assets with content paths adjusted below.
  prefix: '',
  important: '#ars-app',
  corePlugins: {
    preflight: false,
  },
  // Avoid Tailwind `.collapse` (visibility:collapse) clashing with Bootstrap Collapse.
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
        ars: [
          'Plus Jakarta Sans',
          'ui-sans-serif',
          'system-ui',
          '-apple-system',
          'Segoe UI',
          'Roboto',
          'Helvetica Neue',
          'Arial',
          'sans-serif',
        ],
      },
      fontSize: {
        'ars-xs': ['0.75rem', { lineHeight: '1rem' }],
        'ars-sm': ['0.875rem', { lineHeight: '1.25rem' }],
        'ars-base': ['0.875rem', { lineHeight: '1.375rem' }],
        'ars-md': ['1rem', { lineHeight: '1.5rem' }],
        'ars-lg': ['1.125rem', { lineHeight: '1.75rem' }],
        'ars-xl': ['1.25rem', { lineHeight: '1.75rem' }],
        'ars-2xl': ['1.5rem', { lineHeight: '2rem' }],
      },
      spacing: {
        'ars-1': '0.25rem',
        'ars-2': '0.5rem',
        'ars-3': '0.75rem',
        'ars-4': '1rem',
        'ars-5': '1.25rem',
        'ars-6': '1.5rem',
        'ars-8': '2rem',
        'ars-10': '2.5rem',
        'ars-12': '3rem',
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
      screens: {
        'ars-sm': '640px',
        'ars-md': '768px',
        'ars-lg': '1024px',
        'ars-xl': '1280px',
      },
      minHeight: {
        'ars-touch': '44px',
      },
      minWidth: {
        'ars-touch': '44px',
      },
    },
  },
  plugins: [],
};
