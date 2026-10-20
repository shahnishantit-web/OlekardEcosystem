'use client';

import { useCallback, useEffect, useId, useState } from 'react';
import Link from 'next/link';
import { Card } from '@/components/ui/Card';
import { Button } from '@/components/ui/Button';
import { Input } from '@/components/ui/Input';
import { Badge } from '@/components/ui/Badge';
import { Loading } from '@/components/ui/Loading';
import { Toast } from '@/components/ui/Toast';
import { useAuth } from '@/lib/auth/context';
import { changePassword, listMfaMethods, disableMfaMethod } from '@/src/modules/profile/api/security';
import type { ChangePasswordPayload } from '@/src/modules/profile/api/security';
import { isApiError } from '@/lib/utils/errors';
import type { ApiError } from '@/lib/utils/errors';
import { PageContainer } from '@/components/layout/PageContainer';
import { PageHeader } from '@/components/layout/PageHeader';

const METHOD_LABELS: Record<string, string> = {
  totp: 'Authenticator App (TOTP)',
  fido2: 'Security Key / Passkey (FIDO2)',
};

export function SecuritySettingsPage() {
  const { authenticatedClient } = useAuth();
  const formId = useId();

  // ── Change password state ─────────────────────────────────────────────────
  const [cpForm, setCpForm] = useState<ChangePasswordPayload>({
    current_password: '',
    new_password: '',
    new_password_confirmation: '',
  });
  const [cpSaving, setCpSaving] = useState(false);
  const [cpError, setCpError] = useState<string | null>(null);
  const [cpSuccess, setCpSuccess] = useState(false);

  // ── MFA devices state ─────────────────────────────────────────────────────
  const [mfaMethods, setMfaMethods] = useState<string[]>([]);
  const [mfaLoading, setMfaLoading] = useState(true);
  const [mfaError, setMfaError] = useState<string | null>(null);
  const [removingMethod, setRemovingMethod] = useState<string | null>(null);

  const loadMfaMethods = useCallback(async () => {
    if (!authenticatedClient) return;
    setMfaLoading(true);
    setMfaError(null);
    try {
      const res = await listMfaMethods(authenticatedClient);
      setMfaMethods(res.methods);
    } catch {
      setMfaError('Failed to load MFA devices.');
    } finally {
      setMfaLoading(false);
    }
  }, [authenticatedClient]);

  useEffect(() => {
    void loadMfaMethods();
  }, [loadMfaMethods]);

  async function handleChangePassword(e: React.FormEvent) {
    e.preventDefault();
    if (!authenticatedClient) return;
    setCpSaving(true);
    setCpError(null);
    setCpSuccess(false);
    try {
      await changePassword(authenticatedClient, cpForm);
      setCpSuccess(true);
      setCpForm({ current_password: '', new_password: '', new_password_confirmation: '' });
    } catch (err) {
      setCpError(
        isApiError(err) ? (err as ApiError).error.message : 'Failed to change password.',
      );
    } finally {
      setCpSaving(false);
    }
  }

  async function handleRemoveMfa(method: string) {
    if (!authenticatedClient) return;
    setRemovingMethod(method);
    setMfaError(null);
    try {
      await disableMfaMethod(authenticatedClient, method as 'totp' | 'fido2');
      setMfaMethods((prev) => prev.filter((m) => m !== method));
    } catch (err) {
      setMfaError(
        isApiError(err) ? (err as ApiError).error.message : 'Failed to remove MFA device.',
      );
    } finally {
      setRemovingMethod(null);
    }
  }

  return (
    <PageContainer>
      <PageHeader title="Security Settings" description="Manage your password and MFA devices." />

      {cpSuccess && (
        <Toast
          message="Password changed successfully. Other active sessions have been revoked."
          onDismiss={() => setCpSuccess(false)}
        />
      )}

      {/* Change Password */}
      <Card className="mb-6">
        <h2 className="text-lg font-semibold text-text-primary mb-4">Change Password</h2>
        <form id={`${formId}-cp`} onSubmit={(e) => { void handleChangePassword(e); }} noValidate>
          <div className="space-y-4 max-w-md">
            <Input
              label="Current password"
              type="password"
              value={cpForm.current_password}
              onChange={(e) => setCpForm((f) => ({ ...f, current_password: e.target.value }))}
              autoComplete="current-password"
            />
            <Input
              label="New password"
              type="password"
              value={cpForm.new_password}
              onChange={(e) => setCpForm((f) => ({ ...f, new_password: e.target.value }))}
              autoComplete="new-password"
              hint="Min 10 chars · uppercase · number · special character"
            />
            <Input
              label="Confirm new password"
              type="password"
              value={cpForm.new_password_confirmation}
              onChange={(e) =>
                setCpForm((f) => ({ ...f, new_password_confirmation: e.target.value }))
              }
              autoComplete="new-password"
            />
            {cpError && (
              <p className="text-sm text-red-600" role="alert">
                {cpError}
              </p>
            )}
            <Button
              type="submit"
              loading={cpSaving}
              disabled={
                !cpForm.current_password ||
                !cpForm.new_password ||
                !cpForm.new_password_confirmation
              }
            >
              Change password
            </Button>
          </div>
        </form>
      </Card>

      {/* MFA Devices */}
      <Card>
        <div className="flex items-center justify-between mb-4">
          <h2 className="text-lg font-semibold text-text-primary">MFA Devices</h2>
          <Link
            href="/mfa-enrollment"
            className="text-sm text-ok-accent hover:underline"
          >
            Add device
          </Link>
        </div>

        {mfaLoading ? (
          <Loading label="Loading MFA devices…" />
        ) : mfaError ? (
          <p className="text-sm text-red-600" role="alert">{mfaError}</p>
        ) : mfaMethods.length === 0 ? (
          <p className="text-sm text-text-secondary">No MFA devices enrolled.</p>
        ) : (
          <ul className="space-y-3" aria-label="Enrolled MFA devices">
            {mfaMethods.map((method) => (
              <li
                key={method}
                className="flex items-center justify-between py-2 border-b border-neutral-700 last:border-0"
              >
                <div className="flex items-center gap-2">
                  <Badge variant="success">Active</Badge>
                  <span className="text-sm text-text-primary">
                    {METHOD_LABELS[method] ?? method}
                  </span>
                </div>
                <Button
                  variant="secondary"
                  size="sm"
                  loading={removingMethod === method}
                  onClick={() => { void handleRemoveMfa(method); }}
                  aria-label={`Remove ${METHOD_LABELS[method] ?? method}`}
                >
                  Remove
                </Button>
              </li>
            ))}
          </ul>
        )}
      </Card>
    </PageContainer>
  );
}
