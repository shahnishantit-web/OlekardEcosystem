import { type InputHTMLAttributes, forwardRef, useId } from 'react';

interface InputProps extends InputHTMLAttributes<HTMLInputElement> {
  label?: string;
  error?: string | undefined;
  hint?: string | undefined;
}

export const Input = forwardRef<HTMLInputElement, InputProps>(function Input(
  { label, error, hint, className = '', ...props },
  ref,
) {
  const id = useId();
  const errorId = `${id}-error`;
  const hintId = `${id}-hint`;

  const describedBy = [hint && hintId, error && errorId].filter(Boolean).join(' ') || undefined;

  return (
    <div className="flex flex-col gap-1">
      {label && (
        <label htmlFor={id} className="text-sm font-medium text-text-primary">
          {label}
        </label>
      )}
      {hint && (
        <p id={hintId} className="text-xs text-text-secondary">
          {hint}
        </p>
      )}
      <input
        ref={ref}
        id={id}
        aria-invalid={!!error}
        aria-describedby={describedBy}
        className={`border rounded-lg px-3 h-[35px] text-sm text-text-primary bg-ok-input-bg placeholder:text-text-secondary/70 focus:outline-none focus:border-ok-primary focus:ring-1 focus:ring-ok-focus-ring transition-colors ${error ? 'border-ok-error-fg' : 'border-ok-input-border'} ${className}`}
        {...props}
      />
      {error && (
        <p id={errorId} role="alert" className="text-xs text-error-primary">
          {error}
        </p>
      )}
    </div>
  );
});
