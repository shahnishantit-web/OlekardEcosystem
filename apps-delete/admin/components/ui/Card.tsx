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
      className={`rounded-lg border border-neutral-200 bg-bg-primary shadow-sm p-4 ${className}`}
      {...props}
    >
      {children}
    </Tag>
  );
}
