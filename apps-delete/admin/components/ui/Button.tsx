import { type ButtonHTMLAttributes, forwardRef } from 'react';

export type ButtonVariant = 'primary' | 'secondary' | 'danger' | 'ghost';

interface ButtonProps extends ButtonHTMLAttributes<HTMLButtonElement> {
  variant?: ButtonVariant;
  loading?: boolean;
}

export const Button = forwardRef<HTMLButtonElement, ButtonProps>(function Button(
  { variant = 'primary', loading = false, disabled, children, className = '', ...props },
  ref,
) {
  const isDisabled = disabled || loading;

  const variants: Record<ButtonVariant, string> = {
    primary: 'bg-brand-primary text-white hover:opacity-90',
    secondary: 'border border-neutral-300 text-text-primary hover:bg-neutral-50',
    danger: 'bg-error-primary text-white hover:opacity-90',
    ghost: 'text-text-primary hover:bg-neutral-50',
  };

  return (
    <button
      ref={ref}
      role="button"
      disabled={isDisabled}
      aria-disabled={isDisabled}
      aria-busy={loading}
      className={`inline-flex items-center justify-center px-4 rounded-md font-medium transition-opacity focus-visible:outline-2 disabled:opacity-50 disabled:cursor-not-allowed ${variants[variant]} ${className}`}
      style={{ minHeight: '44px' }}
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
