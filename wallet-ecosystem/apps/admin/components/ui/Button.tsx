import { type ButtonHTMLAttributes, forwardRef } from 'react';

export type ButtonVariant = 'primary' | 'secondary' | 'danger' | 'ghost' | 'outline' | 'default' | 'info';

interface ButtonProps extends ButtonHTMLAttributes<HTMLButtonElement> {
  variant?: ButtonVariant;
  loading?: boolean;
  size?: string;
}

const VARIANT_ALIAS: Partial<Record<ButtonVariant, ButtonVariant>> = {
  outline:  'secondary',
  default:  'primary',
  info:     'ghost',
};

export const Button = forwardRef<HTMLButtonElement, ButtonProps>(function Button(
  { variant = 'primary', loading = false, disabled, children, className = '', size: _size, ...props },
  ref,
) {
  const isDisabled = disabled || loading;
  const canonical = VARIANT_ALIAS[variant] ?? variant;

  const variants: Record<string, string> = {
    primary:   'bg-ok-primary-dark text-white hover:bg-ok-primary border border-ok-primary-dark hover:border-ok-primary',
    secondary: 'border border-ok-border-strong bg-ok-surface text-text-primary hover:bg-ok-hover-bg',
    danger:    'bg-ok-error-fg text-white hover:opacity-90 border border-ok-error-fg',
    ghost:     'text-text-primary hover:bg-ok-hover-bg border border-transparent',
  };

  return (
    <button
      ref={ref}
      disabled={isDisabled}
      aria-disabled={isDisabled}
      aria-busy={loading}
      className={`inline-flex items-center justify-center px-3 rounded-lg font-medium transition-colors focus-visible:outline-2 disabled:opacity-50 disabled:cursor-not-allowed min-h-[44px] sm:min-h-[35px] text-sm ${variants[canonical] ?? variants['primary']} ${className}`}
      {...props}
    >
      {loading ? (
        <>
          <span aria-hidden="true">Loading…</span>
          <span className="sr-only">{children}</span>
        </>
      ) : children}
    </button>
  );
});
