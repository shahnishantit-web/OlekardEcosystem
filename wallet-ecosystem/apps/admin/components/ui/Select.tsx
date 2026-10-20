import { type SelectHTMLAttributes, forwardRef, useId } from 'react';

interface SelectOption {
  value: string;
  label: string;
}

interface SelectProps extends SelectHTMLAttributes<HTMLSelectElement> {
  label?: string;
  options?: SelectOption[];
  error?: string;
  placeholder?: string;
}

export const Select = forwardRef<HTMLSelectElement, SelectProps>(function Select(
  { label, options, error, placeholder, className = '', children, ...props },
  ref,
) {
  const id = useId();
  const errorId = `${id}-error`;

  const select = (
    <select
      ref={ref}
      id={id}
      aria-invalid={!!error}
      aria-describedby={error ? errorId : undefined}
      className={`border rounded-lg px-3 h-[35px] text-sm text-text-primary bg-ok-input-bg focus:outline-none focus:border-ok-primary focus:ring-1 focus:ring-ok-focus-ring transition-colors ${error ? 'border-ok-error-fg' : 'border-ok-input-border'} ${className}`}
      {...props}
    >
      {placeholder && <option value="">{placeholder}</option>}
      {options
        ? options.map((opt) => (
            <option key={opt.value} value={opt.value}>
              {opt.label}
            </option>
          ))
        : children}
    </select>
  );

  if (!label) return select;

  return (
    <div className="flex flex-col gap-1">
      <label htmlFor={id} className="text-sm font-medium text-text-primary">
        {label}
      </label>
      {select}
      {error && (
        <p id={errorId} role="alert" className="text-xs text-error-primary">
          {error}
        </p>
      )}
    </div>
  );
});
