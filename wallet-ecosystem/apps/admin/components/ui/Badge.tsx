import type { ReactNode } from 'react';

export type BadgeVariant = 'neutral' | 'success' | 'warning' | 'error' | 'info';

interface BadgeProps {
  label?: string;
  variant?: BadgeVariant;
  className?: string;
  children?: ReactNode;
}

const variantStyles: Record<BadgeVariant, string> = {
  neutral: 'bg-neutral-100 text-neutral-600 border border-neutral-200',
  success: 'bg-ok-success-bg text-ok-success-fg border border-ok-success-border',
  warning: 'bg-ok-warning-bg text-ok-warning-fg border border-ok-warning-border',
  error:   'bg-ok-error-bg text-ok-error-fg border border-ok-error-border',
  info:    'bg-ok-info-bg text-ok-info-fg border border-ok-info-border',
};

export function Badge({ label, children, variant = 'neutral', className = '' }: BadgeProps) {
  const content = label ?? children;
  const ariaText = label ?? (typeof children === 'string' ? children : undefined);
  return (
    <span
      aria-label={ariaText}
      className={`inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium ${variantStyles[variant]} ${className}`}
    >
      {content}
    </span>
  );
}
