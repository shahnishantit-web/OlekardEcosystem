'use client';

import { useState } from 'react';
import { Button } from '@/components/ui/Button';

// T070: Credential rotation form — accepts a new secret REFERENCE (path) only.
// NEVER shows old or new secret values. Only references to vault/secrets-manager paths.

interface Props {
  providerId: string;
  accountId: string;
  onRotate: (newSecretReference: string) => Promise<void>;
}

export function CredentialRotationForm({ providerId, accountId, onRotate }: Props) {
  const [newReference, setNewReference] = useState('');
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [success, setSuccess] = useState(false);

  async function handleSubmit(e: React.FormEvent) {
    e.preventDefault();
    if (!newReference.trim() || newReference.length < 8) {
      setError('Secret reference must be at least 8 characters (e.g. vault path or AWS secret ARN).');
      return;
    }

    setLoading(true);
    setError(null);
    try {
      await onRotate(newReference.trim());
      setSuccess(true);
      setNewReference('');
    } catch {
      setError('Failed to rotate credential reference. Please try again.');
    } finally {
      setLoading(false);
    }
  }

  return (
    <form onSubmit={(e) => void handleSubmit(e)} className="space-y-4">
      <div className="rounded border border-amber-300 bg-amber-50 px-4 py-3 text-sm text-amber-800">
        <strong>Credential Rotation</strong> — Enter the new secret manager reference path
        (e.g. <code>vault/payment/{providerId}/{accountId}/callback-secret</code>).
        The actual secret value is NEVER stored or shown here.
      </div>

      <div>
        <label htmlFor="new-secret-reference" className="block text-sm font-medium text-text-primary mb-1">
          New Secret Reference (path only)
        </label>
        <input
          id="new-secret-reference"
          type="text"
          value={newReference}
          onChange={(e) => setNewReference(e.target.value)}
          placeholder="e.g. vault/payment/providers/callback-secret-v2"
          autoComplete="off"
          spellCheck={false}
          className="w-full rounded border border-border px-3 py-2 text-sm bg-surface text-text-primary font-mono"
          disabled={loading}
          required
          minLength={8}
          maxLength={512}
        />
        <p className="mt-1 text-xs text-text-secondary">
          This is a reference path, not the secret value itself.
        </p>
      </div>

      {error && (
        <p role="alert" className="text-sm text-red-700">{error}</p>
      )}
      {success && (
        <p role="status" className="text-sm text-green-700">Credential reference rotated successfully.</p>
      )}

      <Button
        type="submit"
        variant="primary"
        disabled={loading || !newReference.trim()}
      >
        {loading ? 'Rotating…' : 'Rotate Credential Reference'}
      </Button>
    </form>
  );
}
