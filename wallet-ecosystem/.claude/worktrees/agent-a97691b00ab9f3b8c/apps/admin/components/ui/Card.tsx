import React from 'react';

interface CardProps {
  title?: string;
  children: React.ReactNode;
  as?: keyof React.JSX.IntrinsicElements;
}

export function Card({ title, children, as: Tag = 'article' }: CardProps) {
  return (
    <Tag>
      {title && <h3>{title}</h3>}
      {children}
    </Tag>
  );
}
