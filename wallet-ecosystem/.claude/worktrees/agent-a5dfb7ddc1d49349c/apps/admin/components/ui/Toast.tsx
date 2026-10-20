'use client';

import React, { useEffect } from 'react';

interface ToastProps {
  message: string;
  onDismiss: () => void;
  autoCloseMs?: number;
}

export function Toast({ message, onDismiss, autoCloseMs = 5000 }: ToastProps) {
  useEffect(() => {
    const timer = setTimeout(onDismiss, autoCloseMs);
    return () => clearTimeout(timer);
  }, [onDismiss, autoCloseMs]);

  return (
    <div role="status" aria-live="polite" aria-atomic="true">
      <p>{message}</p>
      <button type="button" onClick={onDismiss} aria-label="Dismiss notification">
        ×
      </button>
    </div>
  );
}
