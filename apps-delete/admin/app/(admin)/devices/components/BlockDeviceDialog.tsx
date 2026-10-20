'use client';

import { useEffect, useId, useRef, useState } from 'react';
import { Button } from '@/components/ui/Button';
import { Input } from '@/components/ui/Input';

interface BlockDeviceDialogProps {
  open: boolean;
  deviceName: string;
  onClose: () => void;
  onConfirm: (reason: string) => Promise<void>;
}

export function BlockDeviceDialog({ open, deviceName, onClose, onConfirm }: BlockDeviceDialogProps) {
  const [reason, setReason] = useState('');
  const [submitting, setSubmitting] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const headingId = useId();
  const reasonId = useId();
  const firstInputRef = useRef<HTMLInputElement>(null);

  useEffect(() => {
    if (!open) return;
    firstInputRef.current?.focus();
    const handleKeyDown = (e: KeyboardEvent) => {
      if (e.key === 'Escape') onClose();
    };
    document.addEventListener('keydown', handleKeyDown);
    return () => document.removeEventListener('keydown', handleKeyDown);
  }, [open, onClose]);

  if (!open) return null;

  async function handleSubmit(e: React.FormEvent) {
    e.preventDefault();
    if (!reason.trim()) {
      setError('Reason is required.');
      return;
    }
    setSubmitting(true);
    setError(null);
    try {
      await onConfirm(reason.trim());
      setReason('');
    } catch {
      setError('Failed to block device. Please try again.');
    } finally {
      setSubmitting(false);
    }
  }

  return (
    <div
      role="dialog"
      aria-modal="true"
      aria-labelledby={headingId}
      className="fixed inset-0 z-50 flex items-center justify-center bg-black/40"
    >
      <div className="bg-surface-default rounded-lg shadow-lg w-full max-w-md p-6">
        <h2 id={headingId} className="text-lg font-semibold text-text-primary mb-1">
          Block device
        </h2>
        <p className="text-sm text-text-secondary mb-4">
          You are about to block <strong>{deviceName}</strong>. All active sessions on this device will be revoked and the device will be prevented from signing in.
        </p>

        <form onSubmit={(e) => { void handleSubmit(e); }} noValidate>
          <Input
            id={reasonId}
            ref={firstInputRef}
            label="Reason"
            value={reason}
            onChange={(e) => setReason(e.target.value)}
            placeholder="e.g. Suspected compromise"
            maxLength={500}
            required
          />

          {error && (
            <p role="alert" className="mt-2 text-sm text-status-error">
              {error}
            </p>
          )}

          <div className="mt-6 flex justify-end gap-3">
            <Button variant="secondary" type="button" onClick={onClose} disabled={submitting}>
              Cancel
            </Button>
            <Button variant="danger" type="submit" disabled={submitting || !reason.trim()}>
              {submitting ? 'Blocking…' : 'Block device'}
            </Button>
          </div>
        </form>
      </div>
    </div>
  );
}
