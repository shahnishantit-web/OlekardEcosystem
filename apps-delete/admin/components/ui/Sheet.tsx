'use client';

import type { ReactNode } from 'react';
import { Dialog } from './Dialog';

interface SheetProps {
  open: boolean;
  onClose: () => void;
  title: string;
  children: ReactNode;
}

export function Sheet({ open, onClose, title, children }: SheetProps) {
  return (
    <Dialog
      open={open}
      onClose={onClose}
      title={title}
      className="fixed bottom-0 left-0 right-0 max-w-none rounded-b-none rounded-t-lg sm:relative sm:max-w-md sm:rounded-lg"
    >
      {children}
    </Dialog>
  );
}
