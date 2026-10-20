import type { ReactNode } from 'react';

interface EmptyProps {
  title: string;
  description?: string;
  action?: ReactNode;
  /** Optional icon rendered above the title. */
  icon?: ReactNode;
}

/** Inline empty-state for use inside cards and list areas. */
export function Empty({ title, description, action, icon }: EmptyProps) {
  return (
    <div
      role="status"
      className="flex flex-col items-center justify-center py-12 px-6 text-center"
    >
      {icon && (
        <div className="mb-4 text-text-secondary/50" aria-hidden="true">
          {icon}
        </div>
      )}
      {!icon && (
        <div className="mb-4 w-10 h-10 rounded-full bg-ok-hover-bg flex items-center justify-center" aria-hidden="true">
          <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.8" strokeLinecap="round" strokeLinejoin="round" className="text-text-secondary">
            <circle cx="12" cy="12" r="10"/>
            <line x1="12" y1="8" x2="12" y2="12"/>
            <line x1="12" y1="16" x2="12.01" y2="16"/>
          </svg>
        </div>
      )}
      <h3 className="text-sm font-semibold text-text-primary mb-1">{title}</h3>
      {description && (
        <p className="text-sm text-text-secondary max-w-xs leading-relaxed">{description}</p>
      )}
      {action && <div className="mt-5">{action}</div>}
    </div>
  );
}
