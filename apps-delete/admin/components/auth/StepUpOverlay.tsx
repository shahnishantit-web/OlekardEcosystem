'use client';

import { useCallback, useEffect, useId, useState } from 'react';
import { Dialog } from '@/components/ui/Dialog';
import { Button } from '@/components/ui/Button';
import { Input } from '@/components/ui/Input';
import { Loading } from '@/components/ui/Loading';
import { initiateStepUp, verifyStepUp } from '@/src/modules/auth/api/auth';
import { isApiError } from '@/lib/utils/errors';
import type { ApiError } from '@/lib/utils/errors';
import type { AuthenticatedApiClient } from '@/lib/api/authenticated-client';
import type { MfaMethod } from '@/lib/auth/context';

export interface StepUpResult {
  stepUpAccessToken: string;
  scope: string;
}

interface StepUpOverlayProps {
  open: boolean;
  scope: string;
  client: AuthenticatedApiClient;
  onSuccess: (result: StepUpResult) => void;
  onClose: () => void;
}

type OverlayPhase = 'loading' | 'verify' | 'success' | 'error' | 'not_required';

export function StepUpOverlay({ open, scope, client, onSuccess, onClose }: StepUpOverlayProps) {
  const codeInputId = useId();

  const [phase, setPhase] = useState<OverlayPhase>('loading');
  const [stepUpToken, setStepUpToken] = useState('');
  const [availableMethods, setAvailableMethods] = useState<MfaMethod[]>(['totp']);
  const [selectedMethod, setSelectedMethod] = useState<MfaMethod>('totp');
  const [code, setCode] = useState('');
  const [submitting, setSubmitting] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const initiate = useCallback(async () => {
    setPhase('loading');
    setError(null);
    setCode('');
    try {
      const response = await initiateStepUp(scope, client);
      if (!response.step_up_required) {
        setPhase('not_required');
        onSuccess({ stepUpAccessToken: '', scope });
        return;
      }
      setStepUpToken(response.step_up_token);
      const methods = response.methods.filter(
        (m): m is MfaMethod => m === 'totp' || m === 'fido2',
      );
      setAvailableMethods(methods.length ? methods : ['totp']);
      setSelectedMethod(methods[0] ?? 'totp');
      setPhase('verify');
    } catch (err) {
      const msg = isApiError(err)
        ? (err as ApiError).error.message
        : 'Step-up initiation failed. Please close and try again.';
      setError(msg);
      setPhase('error');
    }
  }, [scope, client, onSuccess]);

  useEffect(() => {
    if (open) {
      void initiate();
    } else {
      setPhase('loading');
      setCode('');
      setError(null);
    }
  }, [open, initiate]);

  async function handleSubmit() {
    if (!code.trim()) {
      setError('Please enter your verification code.');
      return;
    }
    setSubmitting(true);
    setError(null);
    try {
      const result = await verifyStepUp(stepUpToken, selectedMethod, code, client);
      setPhase('success');
      onSuccess({ stepUpAccessToken: result.step_up_access_token, scope: result.scope });
    } catch (err) {
      setError(
        isApiError(err)
          ? (err as ApiError).error.message
          : 'Verification failed. Please check your code.',
      );
    } finally {
      setSubmitting(false);
    }
  }

  return (
    <Dialog open={open} onClose={onClose} title="Verify Identity">
      <div className="min-h-[160px] flex flex-col gap-4">
        {phase === 'loading' && <Loading label="Preparing step-up challenge…" />}

        {phase === 'error' && (
          <div role="alert" aria-live="assertive" className="text-sm text-error-primary">
            {error ?? 'An error occurred. Please close and try again.'}
          </div>
        )}

        {phase === 'verify' && (
          <>
            <p className="text-sm text-text-secondary">
              This operation requires additional verification. Enter your{' '}
              {selectedMethod === 'totp' ? 'authenticator code' : 'security key code'} to
              continue.
            </p>

            {availableMethods.length > 1 && (
              <fieldset>
                <legend className="text-sm font-medium text-text-primary mb-2">
                  Verification method
                </legend>
                <div className="flex gap-3">
                  {availableMethods.map((m) => (
                    <label
                      key={m}
                      className="flex items-center gap-2 text-sm text-text-primary cursor-pointer"
                    >
                      <input
                        type="radio"
                        name="step-up-method"
                        value={m}
                        checked={selectedMethod === m}
                        onChange={() => setSelectedMethod(m)}
                        className="accent-brand-primary"
                      />
                      {m === 'totp' ? 'Authenticator app' : 'Security key'}
                    </label>
                  ))}
                </div>
              </fieldset>
            )}

            {error && (
              <p
                id={codeInputId}
                role="alert"
                aria-live="assertive"
                className="text-sm text-error-primary"
              >
                {error}
              </p>
            )}

            <Input
              label="Verification code"
              type="text"
              inputMode="numeric"
              autoComplete="one-time-code"
              maxLength={6}
              value={code}
              onChange={(e) => {
                setCode(e.target.value.replace(/\D/g, '').slice(0, 6));
                setError(null);
              }}
              required
            />

            <div className="flex gap-2 justify-end mt-2">
              <Button variant="secondary" onClick={onClose} disabled={submitting}>
                Cancel
              </Button>
              <Button onClick={() => { void handleSubmit(); }} loading={submitting}>
                Confirm
              </Button>
            </div>
          </>
        )}

        {phase === 'success' && (
          <p role="status" aria-live="polite" className="text-sm text-text-primary">
            Identity verified. Proceeding…
          </p>
        )}
      </div>
    </Dialog>
  );
}
