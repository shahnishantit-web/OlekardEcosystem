'use client';

import React from 'react';
import { Dialog } from './Dialog';

interface ConfirmationProps {
  open: boolean;
  onConfirm: () => void;
  onCancel: () => void;
  title: string;
  message: string;
  confirmLabel?: string;
  cancelLabel?: string;
}

export function Confirmation({
  open,
  onConfirm,
  onCancel,
  title,
  message,
  confirmLabel = 'Confirm',
  cancelLabel = 'Cancel',
}: ConfirmationProps) {
  return (
    <Dialog open={open} onClose={onCancel} title={title}>
      <p>{message}</p>
      <div>
        <button type="button" onClick={onCancel} aria-label={cancelLabel}>
          {cancelLabel}
        </button>
        <button type="button" onClick={onConfirm} aria-label={confirmLabel}>
          {confirmLabel}
        </button>
      </div>
    </Dialog>
  );
}
