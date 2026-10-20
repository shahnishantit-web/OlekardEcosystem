import type { ReactNode } from 'react';

interface EmptyProps {
  title: string;
  description?: string;
  action?: ReactNode;
  icon?: ReactNode;
}

export function Empty({ title, description, action, icon }: EmptyProps) {
  return (
    <div className="flex flex-col items-center justify-center py-12 text-center px-4">
      {icon && (
        <div aria-hidden="true" className="mb-4 text-neutral-300 text-5xl">
          {icon}
        </div>
      )}
      <h3 className="text-base font-semibold text-text-primary mb-2">{title}</h3>
      {description && (
        <p className="text-sm text-text-secondary max-w-xs mb-6">{description}</p>
      )}
      {action && <div>{action}</div>}
    </div>
  );
}
