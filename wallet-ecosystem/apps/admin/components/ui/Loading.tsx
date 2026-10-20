import React from 'react';

interface LoadingProps {
  label?: string;
}

export function Loading({ label = 'Loading...' }: LoadingProps) {
  return (
    <div aria-live="polite" role="status" aria-label={label}>
      <span className="sr-only">{label}</span>
    </div>
  );
}
