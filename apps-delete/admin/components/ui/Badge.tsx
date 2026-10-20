export type BadgeVariant = 'neutral' | 'success' | 'warning' | 'error' | 'info';

interface BadgeProps {
  label: string;
  variant?: BadgeVariant;
  className?: string;
}

const variantStyles: Record<BadgeVariant, string> = {
  neutral: 'bg-neutral-100 text-neutral-700',
  success: 'bg-ok-success-bg text-ok-success-fg',
  warning: 'bg-ok-warning-bg text-ok-warning-fg',
  error:   'bg-ok-error-bg text-ok-error-fg',
  info:    'bg-ok-info-bg text-ok-info-fg',
};

export function Badge({ label, variant = 'neutral', className = '' }: BadgeProps) {
  return (
    <span
      aria-label={label}
      className={`inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium ${variantStyles[variant]} ${className}`}
    >
      {label}
    </span>
  );
}
