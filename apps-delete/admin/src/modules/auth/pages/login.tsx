'use client';

import { type FormEvent, useEffect, useId, useRef, useState } from 'react';
import { useRouter } from 'next/navigation';
import { Button } from '@/components/ui/Button';
import { Input } from '@/components/ui/Input';
import { Loading } from '@/components/ui/Loading';
import { useAuth } from '@/lib/auth/context';
import type { MfaMethod } from '@/lib/auth/context';

type LoginPhase = 'credentials' | 'mfa';

export function AdminLoginPage() {
  const { phase, mfaMethods, authError, login, completeMfa } = useAuth();
  const router = useRouter();
  const headingId = useId();
  const statusId = useId();

  const [uiPhase, setUiPhase] = useState<LoginPhase>('credentials');
  const [loading, setLoading] = useState(false);
  const [localError, setLocalError] = useState<string | null>(null);

  const [email, setEmail] = useState('');
  const [password, setPassword] = useState('');
  const [mfaCode, setMfaCode] = useState('');
  const [selectedMethod, setSelectedMethod] = useState<MfaMethod>('totp');

  const emailInputRef = useRef<HTMLInputElement>(null);
  const mfaInputRef = useRef<HTMLInputElement>(null);

  // Focus the email field on first render
  useEffect(() => {
    emailInputRef.current?.focus();
  }, []);

  // Sync ui phase with auth context phase — only fires when phase changes,
  // not when the user navigates back via the "Back to sign in" button.
  useEffect(() => {
    if (phase === 'mfa_pending') {
      setUiPhase('mfa');
      setTimeout(() => mfaInputRef.current?.focus(), 50);
    }
  }, [phase]); // eslint-disable-line react-hooks/exhaustive-deps

  // Navigate away when authenticated
  useEffect(() => {
    if (phase === 'authenticated') {
      router.replace('/dashboard');
    }
  }, [phase, router]);

  // Show only API errors in the banner; field-level validation errors are shown inline.
  const displayError = authError;
  const activeMethods = mfaMethods ?? ['totp'];

  async function handleCredentialsSubmit(e: FormEvent) {
    e.preventDefault();
    setLocalError(null);

    if (!email.trim()) {
      setLocalError('Email is required.');
      return;
    }
    if (!password) {
      setLocalError('Password is required.');
      return;
    }

    setLoading(true);
    try {
      await login(email, password);
    } finally {
      setLoading(false);
    }
  }

  async function handleMfaSubmit(e: FormEvent) {
    e.preventDefault();
    setLocalError(null);

    if (!mfaCode.trim()) {
      setLocalError('Verification code is required.');
      return;
    }

    setLoading(true);
    try {
      await completeMfa(selectedMethod, mfaCode);
    } finally {
      setLoading(false);
    }
  }

  return (
    <main
      className="min-h-screen flex items-center justify-center bg-bg-primary px-4 py-12"
      aria-labelledby={headingId}
    >
      <div className="w-full max-w-sm">

        {/* OleKard brand identity */}
        <div className="flex items-center justify-center gap-3 mb-8">
          <div
            className="w-9 h-9 bg-ok-accent rounded-lg flex items-center justify-center flex-shrink-0"
            aria-hidden="true"
          >
            <span className="text-sm font-black text-brand-navy select-none">OK</span>
          </div>
          <div className="text-left">
            <p className="text-base font-bold text-text-primary leading-none tracking-tight">
              OleKard
            </p>
            <p className="text-xs text-text-secondary leading-none mt-1 tracking-widest uppercase">
              Admin Platform
            </p>
          </div>
        </div>

        {/* Login card */}
        <div className="bg-bg-secondary border border-neutral-200 rounded-xl shadow-xl overflow-hidden">
          {/* Admin identity accent — teal bar */}
          <div className="h-1 bg-ok-primary" aria-hidden="true" />

          <div className="px-8 pb-8 pt-6">
            {/* Phase heading */}
            <div className="mb-6 text-center">
              <h1
                id={headingId}
                className="text-xl font-semibold text-text-primary"
              >
                {uiPhase === 'mfa' ? 'Two-Factor Authentication' : 'Admin Sign In'}
              </h1>
              <p className="mt-1.5 text-sm text-text-secondary">
                {uiPhase === 'mfa'
                  ? 'Enter the code from your authenticator app.'
                  : 'Sign in with your admin email and password.'}
              </p>
            </div>

            {loading && (
              <div aria-live="polite" aria-atomic="true">
                <Loading label="Signing in…" />
              </div>
            )}

            {!loading && displayError !== null && (
              <div
                id={statusId}
                role="alert"
                aria-live="assertive"
                aria-atomic="true"
                className="mb-4 rounded-md border border-ok-error bg-ok-error-bg px-4 py-3"
              >
                <p className="text-sm text-ok-error-fg">{displayError}</p>
              </div>
            )}

            {!loading && uiPhase === 'credentials' && (
              <form
                onSubmit={(e) => { void handleCredentialsSubmit(e); }}
                noValidate
                aria-describedby={displayError !== null ? statusId : undefined}
              >
                <div className="flex flex-col gap-4">
                  <Input
                    ref={emailInputRef}
                    label="Email address"
                    type="email"
                    autoComplete="email"
                    value={email}
                    onChange={(e) => setEmail(e.target.value)}
                    error={localError !== null && !email.trim() ? 'Email is required.' : undefined}
                    required
                  />
                  <Input
                    label="Password"
                    type="password"
                    autoComplete="current-password"
                    value={password}
                    onChange={(e) => setPassword(e.target.value)}
                    error={localError !== null && !password ? 'Password is required.' : undefined}
                    required
                  />
                  <Button type="submit" className="w-full mt-2">
                    Continue
                  </Button>
                </div>
                <p className="mt-4 text-xs text-center text-text-secondary">
                  Multi-factor authentication is required for all admin sign-ins.
                </p>
              </form>
            )}

            {!loading && uiPhase === 'mfa' && (
              <form
                onSubmit={(e) => { void handleMfaSubmit(e); }}
                noValidate
                aria-describedby={displayError !== null ? statusId : undefined}
              >
                <div className="flex flex-col gap-4">
                  {activeMethods.length > 1 && (
                    <fieldset>
                      <legend className="text-sm font-medium text-text-primary mb-2">
                        Authentication method
                      </legend>
                      <div className="flex gap-3">
                        {activeMethods.map((m) => (
                          <label
                            key={m}
                            className="flex items-center gap-2 text-sm text-text-primary cursor-pointer"
                          >
                            <input
                              type="radio"
                              name="mfa-method"
                              value={m}
                              checked={selectedMethod === m}
                              onChange={() => setSelectedMethod(m)}
                              className="accent-brand-primary"
                            />
                            {m === 'totp' ? 'Authenticator app (TOTP)' : 'Security key (FIDO2)'}
                          </label>
                        ))}
                      </div>
                    </fieldset>
                  )}

                  <Input
                    ref={mfaInputRef}
                    label={selectedMethod === 'totp' ? '6-digit code' : 'Security key code'}
                    type="text"
                    inputMode="numeric"
                    autoComplete="one-time-code"
                    maxLength={6}
                    value={mfaCode}
                    onChange={(e) => setMfaCode(e.target.value.replace(/\D/g, '').slice(0, 6))}
                    error={localError !== null && !mfaCode.trim() ? 'Verification code is required.' : undefined}
                    hint={
                      selectedMethod === 'totp'
                        ? 'Enter the 6-digit code shown in your authenticator app.'
                        : undefined
                    }
                    required
                  />

                  <Button type="submit" className="w-full mt-2">
                    Verify
                  </Button>
                  <button
                    type="button"
                    onClick={() => {
                      setUiPhase('credentials');
                      setMfaCode('');
                      setLocalError(null);
                    }}
                    className="text-sm text-text-secondary underline hover:text-text-primary focus-visible:outline-2 rounded"
                  >
                    Back to sign in
                  </button>
                </div>
              </form>
            )}
          </div>
        </div>

        {/* Security notice */}
        <p className="mt-6 text-center text-xs text-text-secondary">
          Authorized personnel only. All sessions are logged and monitored.
        </p>

      </div>
    </main>
  );
}
