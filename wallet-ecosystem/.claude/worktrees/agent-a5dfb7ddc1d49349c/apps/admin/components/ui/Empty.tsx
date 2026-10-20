import React from 'react';

interface EmptyProps {
  title: string;
  description?: string;
  action?: React.ReactNode;
}

export function Empty({ title, description, action }: EmptyProps) {
  return (
    <div>
      <h3>{title}</h3>
      {description && <p>{description}</p>}
      {action && <div>{action}</div>}
    </div>
  );
}
