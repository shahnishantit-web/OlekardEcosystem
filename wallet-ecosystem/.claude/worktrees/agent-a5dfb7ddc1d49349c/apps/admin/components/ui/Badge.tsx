import React from 'react';

interface BadgeProps {
  label: string;
  variant?: 'default' | 'success' | 'warning' | 'error';
}

export function Badge({ label, variant = 'default' }: BadgeProps) {
  return (
    <span
      role="status"
      aria-label={label}
      data-variant={variant}
    >
      {label}
    </span>
  );
}
