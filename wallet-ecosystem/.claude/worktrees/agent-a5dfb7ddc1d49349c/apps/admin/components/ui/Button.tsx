import React from 'react';

interface ButtonProps extends React.ButtonHTMLAttributes<HTMLButtonElement> {
  variant?: 'primary' | 'secondary' | 'destructive';
  size?: 'sm' | 'md' | 'lg';
  isLoading?: boolean;
  label: string;
}

export function Button({
  variant = 'primary',
  size = 'md',
  isLoading = false,
  label,
  disabled,
  children,
  ...props
}: ButtonProps) {
  return (
    <button
      role="button"
      aria-label={label}
      aria-disabled={disabled || isLoading}
      aria-busy={isLoading}
      disabled={disabled || isLoading}
      data-variant={variant}
      data-size={size}
      {...props}
    >
      {isLoading ? <span aria-hidden="true">Loading…</span> : children ?? label}
    </button>
  );
}
