'use client';

import { useEffect } from 'react';

export type ToastVariant = 'info' | 'success' | 'warning' | 'error';

interface ToastProps {
  message: string;
  variant?: ToastVariant;
  onDismiss: () => void;
  duration?: number;
}

const variantStyles: Record<ToastVariant, string> = {
  info: 'bg-neutral-800 text-white',
  success: 'bg-success-primary text-white',
  warning: 'bg-warning-primary text-neutral-900',
  error: 'bg-error-primary text-white',
};

export function Toast({
  message,
  variant = 'info',
  onDismiss,
  duration = 4000,
}: ToastProps) {
  useEffect(() => {
    const timer = setTimeout(onDismiss, duration);
    return () => clearTimeout(timer);
  }, [onDismiss, duration]);

  return (
    <div
      role="status"
      aria-live="polite"
      aria-atomic="true"
      className={`fixed bottom-4 right-4 z-50 max-w-sm rounded-md px-4 py-3 shadow-lg ${variantStyles[variant]}`}
    >
      <div className="flex items-center gap-3">
        <span className="text-sm">{message}</span>
        <button
          onClick={onDismiss}
          aria-label="Dismiss notification"
          className="ml-auto opacity-70 hover:opacity-100 focus-visible:outline-2"
          style={{ minHeight: '44px', minWidth: '44px' }}
        >
          ✕
        </button>
      </div>
    </div>
  );
}
