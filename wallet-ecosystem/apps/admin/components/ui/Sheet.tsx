'use client';

import React, { useEffect } from 'react';

interface SheetProps {
  open: boolean;
  onClose: () => void;
  title?: string;
  children: React.ReactNode;
  side?: 'left' | 'right';
}

export function Sheet({ open, onClose, title, children, side = 'right' }: SheetProps) {
  useEffect(() => {
    const handleKeyDown = (e: KeyboardEvent) => {
      if (e.key === 'Escape' && open) onClose();
    };
    document.addEventListener('keydown', handleKeyDown);
    return () => document.removeEventListener('keydown', handleKeyDown);
  }, [open, onClose]);

  if (!open) return null;

  return (
    <>
      <div
        aria-hidden="true"
        onClick={onClose}
        style={{ position: 'fixed', inset: 0, background: 'rgba(0,0,0,0.5)' }}
      />
      <div
        role="dialog"
        aria-modal="true"
        aria-labelledby="sheet-title"
        data-side={side}
        style={{ position: 'fixed', top: 0, bottom: 0, [side]: 0 }}
      >
        <h2 id="sheet-title">{title}</h2>
        {children}
        <button type="button" onClick={onClose} aria-label="Close panel">
          Close
        </button>
      </div>
    </>
  );
}
