import type { HTMLAttributes } from 'react';

interface CardProps extends HTMLAttributes<HTMLElement> {
  as?: 'article' | 'section' | 'div';
  semanticLabel?: string;
}

export function Card({
  as: Tag = 'article',
  semanticLabel,
  className = '',
  children,
  ...props
}: CardProps) {
  return (
    <Tag
      aria-label={semanticLabel}
      className={`rounded-[11px] border border-ok-card-border bg-ok-card-bg shadow-[var(--ok-card-shadow,0_1px_3px_rgba(32,38,54,0.04))] p-4 ${className}`}
      {...props}
    >
      {children}
    </Tag>
  );
}
