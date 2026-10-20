import type { Config } from 'tailwindcss';

const config: Config = {
  content: [
    './app/**/*.{ts,tsx}',
    './components/**/*.{ts,tsx}',
    './lib/**/*.{ts,tsx}',
    './src/**/*.{ts,tsx}',
  ],
  theme: {
    extend: {
      colors: {
        brand: {
          primary:    'var(--color-brand-primary)',
          navy:       'var(--ok-brand-navy)',
          gold:       'var(--ok-brand-gold)',
          'gold-light': 'var(--ok-brand-gold-light)',
          dark:       'var(--ok-brand-dark)',
        },
        error: {
          primary: 'var(--color-error-primary)',
        },
        success: {
          primary: 'var(--color-success-primary)',
        },
        warning: {
          primary: 'var(--color-warning-primary)',
        },
        text: {
          primary:   'var(--color-text-primary)',
          secondary: 'var(--color-text-secondary)',
        },
        bg: {
          primary:   'var(--color-bg-primary)',
          secondary: 'var(--color-bg-secondary)',
        },
        neutral: {
          0:   'var(--color-neutral-0)',
          50:  'var(--color-neutral-50)',
          100: 'var(--color-neutral-100)',
          200: 'var(--color-neutral-200)',
          300: 'var(--color-neutral-300)',
          400: 'var(--color-neutral-400)',
          500: 'var(--color-neutral-500)',
          600: 'var(--color-neutral-600)',
          700: 'var(--color-neutral-700)',
          800: 'var(--color-neutral-800)',
          900: 'var(--color-neutral-900)',
          950: 'var(--color-neutral-950)',
        },
        ok: {
          primary:      'var(--ok-primary)',
          'primary-dark':  'var(--ok-primary-dark)',
          'primary-light': 'var(--ok-primary-light)',
          background:   'var(--ok-background)',
          surface:      'var(--ok-surface)',
          accent:       'var(--ok-accent)',
          // Sidebar
          'sidebar-bg':     'var(--ok-sidebar-bg)',
          'sidebar-border': 'var(--ok-sidebar-border)',
          'nav-text':       'var(--ok-nav-text)',
          'nav-hover':      'var(--ok-nav-hover)',
          'nav-active':     'var(--ok-nav-active)',
          'nav-active-fg':  'var(--ok-nav-active-fg)',
          // Header
          'header-bg':     'var(--ok-header-bg)',
          'header-border': 'var(--ok-header-border)',
          // Cards & borders
          'card-bg':     'var(--ok-card-bg)',
          'card-border': 'var(--ok-card-border)',
          border:        'var(--ok-border)',
          'border-strong': 'var(--ok-border-strong)',
          // Interaction
          'hover-bg':    'var(--ok-hover-bg)',
          'input-bg':    'var(--ok-input-bg)',
          'input-border': 'var(--ok-input-border)',
          'focus-ring':  'var(--ok-focus-ring)',
          // Semantic
          success:       'var(--ok-success)',
          warning:       'var(--ok-warning)',
          error:         'var(--ok-error)',
          info:          'var(--ok-info)',
          'success-bg':  'var(--ok-success-bg)',
          'success-fg':  'var(--ok-success-fg)',
          'success-border': 'var(--ok-success-border)',
          'warning-bg':  'var(--ok-warning-bg)',
          'warning-fg':  'var(--ok-warning-fg)',
          'warning-border': 'var(--ok-warning-border)',
          'error-bg':    'var(--ok-error-bg)',
          'error-fg':    'var(--ok-error-fg)',
          'error-border': 'var(--ok-error-border)',
          'info-bg':     'var(--ok-info-bg)',
          'info-fg':     'var(--ok-info-fg)',
          'info-border': 'var(--ok-info-border)',
          // Charts
          'chart-primary':   'var(--ok-chart-primary)',
          'chart-secondary': 'var(--ok-chart-secondary)',
          'chart-grid':      'var(--ok-chart-grid)',
        },
      },
      spacing: {
        1:  'var(--spacing-1)',
        2:  'var(--spacing-2)',
        4:  'var(--spacing-4)',
        6:  'var(--spacing-6)',
        8:  'var(--spacing-8)',
        10: 'var(--spacing-10)',
        12: 'var(--spacing-12)',
        16: 'var(--spacing-16)',
        20: 'var(--spacing-20)',
        24: 'var(--spacing-24)',
      },
      fontSize: {
        xs:    'var(--font-size-xs)',
        sm:    'var(--font-size-sm)',
        base:  'var(--font-size-base)',
        lg:    'var(--font-size-lg)',
        xl:    'var(--font-size-xl)',
        '2xl': 'var(--font-size-2xl)',
        '3xl': 'var(--font-size-3xl)',
        '4xl': 'var(--font-size-4xl)',
      },
      borderRadius: {
        none: 'var(--radius-none)',
        sm:   'var(--radius-sm)',
        md:   'var(--radius-md)',
        lg:   'var(--radius-lg)',
        xl:   'var(--radius-xl)',
        full: 'var(--radius-full)',
      },
      boxShadow: {
        none: 'var(--elevation-none)',
        sm:   'var(--elevation-sm)',
        md:   'var(--elevation-md)',
        lg:   'var(--elevation-lg)',
        xl:   'var(--elevation-xl)',
      },
      transitionDuration: {
        fast:   'var(--motion-duration-fast)',
        normal: 'var(--motion-duration-normal)',
        slow:   'var(--motion-duration-slow)',
      },
      transitionTimingFunction: {
        default: 'var(--motion-easing-default)',
        in:      'var(--motion-easing-in)',
        out:     'var(--motion-easing-out)',
        'in-out': 'var(--motion-easing-in-out)',
      },
      width: {
        'sidebar-expanded':  'var(--sidebar-expanded)',
        'sidebar-collapsed': 'var(--sidebar-collapsed)',
      },
      height: {
        header: 'var(--header-height)',
      },
      fontFamily: {
        sans: ['Inter', 'system-ui', '-apple-system', 'BlinkMacSystemFont', 'Segoe UI', 'sans-serif'],
      },
    },
  },
  plugins: [],
};

export default config;
