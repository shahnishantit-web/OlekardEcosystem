'use client';

import { type FormEvent, useId, useState } from 'react';
import Link from 'next/link';
import { useRouter } from 'next/navigation';
import { Button } from '@/components/ui/Button';
import { resetPassword } from '@/src/modules/auth/api/password-recovery';
import { isApiError } from '@/lib/utils/errors';

const INPUT_BASE =
  'w-full rounded-lg border bg-ok-input-bg px-4 h-[42px] text-sm text-text-primary ' +
  'placeholder:text-text-secondary/50 transition-colors ' +
  'focus:outline-none focus:border-ok-primary focus:ring-1 focus:ring-ok-focus-ring';
const INPUT_NORMAL = `${INPUT_BASE} border-ok-input-border`;
const INPUT_ERROR  = `${INPUT_BASE} border-ok-error-fg bg-ok-error-bg`;
const LABEL_CLS    = 'block text-sm font-medium text-text-primary mb-1.5';

interface StrengthRule {
  label: string;
  test: (v: string) => boolean;
}

const STRENGTH_RULES: StrengthRule[] = [
  { label: 'At least 10 characters',              test: v => v.length >= 10 },
  { label: 'At least one uppercase letter (A–Z)',  test: v => /[A-Z]/.test(v) },
  { label: 'At least one number (0–9)',            test: v => /[0-9]/.test(v) },
  { label: 'At least one special character',       test: v => /[^A-Za-z0-9]/.test(v) },
];

interface Props {
  token: string | null;
}

export function ResetPasswordPage({ token }: Props) {
  const headingId = useId();
  const statusId  = useId();
  const router    = useRouter();

  const [password,  setPassword]  = useState('');
  const [confirm,   setConfirm]   = useState('');
  const [loading,   setLoading]   = useState(false);
  const [apiError,  setApiError]  = useState<string | null>(null);
  const [expired,   setExpired]   = useState(false);

  const allRulesMet  = STRENGTH_RULES.every(r => r.test(password));
  const confirmMatch = password === confirm;
  const confirmError = confirm.length > 0 && !confirmMatch;

  async function handleSubmit(e: FormEvent) {
    e.preventDefault();
    setApiError(null);

    if (!allRulesMet) return;
    if (!confirmMatch) return;

    setLoading(true);
    try {
      await resetPassword(token!, password);
      router.replace('/login?reset=success');
    } catch (err) {
      if (isApiError(err) && (
        err.error.code === 'TOKEN_EXPIRED' ||
        err.error.code === 'TOKEN_INVALID' ||
        err.error.code === 'INVALID_TOKEN'
      )) {
        setExpired(true);
      } else {
        const message = isApiError(err)
          ? err.error.message
          : 'Failed to reset password. Please try again.';
        setApiError(message);
      }
    } finally {
      setLoading(false);
    }
  }

  // ── Invalid / missing token ─────────────────────────────────────────────────
  if (!token) {
    return (
      <main className="min-h-screen flex items-center justify-center bg-white px-6 py-12" aria-labelledby={headingId}>
        <div className="w-full max-w-[420px] text-center">
          <Logo />
          <div
            className="mx-auto mb-6 w-16 h-16 rounded-full bg-ok-warning-bg flex items-center justify-center"
            aria-hidden="true"
          >
            <svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.8" strokeLinecap="round" strokeLinejoin="round" className="text-ok-warning-fg">
              <path d="M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/>
            </svg>
          </div>
          <h1 id={headingId} className="text-[24px] font-bold text-text-primary leading-tight tracking-tight mb-3">
            Invalid reset link
          </h1>
          <p className="text-sm text-text-secondary leading-relaxed mb-8">
            This password reset link is invalid. Request a new one.
          </p>
          <Link
            href="/forgot-password"
            className="inline-flex items-center justify-center rounded-lg bg-ok-primary px-5 py-2.5 text-sm font-semibold text-white hover:bg-ok-primary-dark transition-colors focus-visible:outline-2"
          >
            Request new link
          </Link>
          <div className="mt-8">
            <Link href="/login" className="text-sm text-text-secondary hover:text-text-primary transition-colors focus-visible:outline-2 rounded">
              ← Back to sign in
            </Link>
          </div>
        </div>
      </main>
    );
  }

  // ── Expired token ───────────────────────────────────────────────────────────
  if (expired) {
    return (
      <main className="min-h-screen flex items-center justify-center bg-white px-6 py-12" aria-labelledby={headingId}>
        <div className="w-full max-w-[420px] text-center">
          <Logo />
          <div
            className="mx-auto mb-6 w-16 h-16 rounded-full bg-ok-warning-bg flex items-center justify-center"
            aria-hidden="true"
          >
            <svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.8" strokeLinecap="round" strokeLinejoin="round" className="text-ok-warning-fg">
              <circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/>
            </svg>
          </div>
          <h1 id={headingId} className="text-[24px] font-bold text-text-primary leading-tight tracking-tight mb-3">
            This link has expired
          </h1>
          <p className="text-sm text-text-secondary leading-relaxed mb-8">
            Password reset links expire after 60 minutes. Request a new one to continue.
          </p>
          <Link
            href="/forgot-password"
            className="inline-flex items-center justify-center rounded-lg bg-ok-primary px-5 py-2.5 text-sm font-semibold text-white hover:bg-ok-primary-dark transition-colors focus-visible:outline-2"
          >
            Request new link
          </Link>
          <div className="mt-8">
            <Link href="/login" className="text-sm text-text-secondary hover:text-text-primary transition-colors focus-visible:outline-2 rounded">
              ← Back to sign in
            </Link>
          </div>
        </div>
      </main>
    );
  }

  // ── Reset form ──────────────────────────────────────────────────────────────
  return (
    <main className="min-h-screen flex items-center justify-center bg-white px-6 py-12" aria-labelledby={headingId}>
      <div className="w-full max-w-[420px]">
        <Logo />

        <div className="mb-8">
          <h1 id={headingId} className="text-[26px] font-bold text-text-primary leading-tight tracking-tight">
            Create new password
          </h1>
          <p className="mt-2 text-sm text-text-secondary">
            Choose a strong password for your admin account.
          </p>
        </div>

        {apiError !== null && (
          <div
            id={statusId}
            role="alert"
            aria-live="assertive"
            aria-atomic="true"
            className="mb-6 rounded-lg border border-ok-error-border bg-ok-error-bg px-4 py-3 flex items-start gap-2.5"
          >
            <svg className="w-4 h-4 text-ok-error-fg flex-shrink-0 mt-0.5" viewBox="0 0 16 16" fill="currentColor" aria-hidden="true">
              <path fillRule="evenodd" d="M8 1a7 7 0 100 14A7 7 0 008 1zM7.25 5a.75.75 0 011.5 0v3.25a.75.75 0 01-1.5 0V5zm.75 7.25a.875.875 0 100-1.75.875.875 0 000 1.75z" />
            </svg>
            <p className="text-sm text-ok-error-fg">{apiError}</p>
          </div>
        )}

        <form
          onSubmit={(e) => { void handleSubmit(e); }}
          noValidate
          aria-describedby={apiError !== null ? statusId : undefined}
        >
          <div className="flex flex-col gap-5">
            {/* Password field */}
            <div>
              <label htmlFor="rp-password" className={LABEL_CLS}>New password</label>
              <input
                id="rp-password"
                type="password"
                autoComplete="new-password"
                value={password}
                onChange={(e) => setPassword(e.target.value)}
                required
                aria-describedby="rp-strength"
                className={INPUT_NORMAL}
              />

              {/* Strength checklist */}
              <ul id="rp-strength" className="mt-2.5 flex flex-col gap-1" aria-label="Password requirements">
                {STRENGTH_RULES.map((rule) => {
                  const met = rule.test(password);
                  return (
                    <li key={rule.label} className={`flex items-center gap-1.5 text-xs ${met ? 'text-ok-success-fg' : 'text-text-secondary'}`}>
                      {met ? (
                        <svg width="12" height="12" viewBox="0 0 12 12" fill="currentColor" aria-hidden="true">
                          <path fillRule="evenodd" d="M10.28 2.28a.75.75 0 010 1.06l-5.5 5.5a.75.75 0 01-1.06 0l-2.5-2.5a.75.75 0 011.06-1.06L4.25 7.19l4.97-4.97a.75.75 0 011.06.06z" />
                        </svg>
                      ) : (
                        <svg width="12" height="12" viewBox="0 0 12 12" fill="none" stroke="currentColor" strokeWidth="1.5" aria-hidden="true">
                          <circle cx="6" cy="6" r="5" />
                        </svg>
                      )}
                      <span>{rule.label}</span>
                    </li>
                  );
                })}
              </ul>
            </div>

            {/* Confirm field */}
            <div>
              <label htmlFor="rp-confirm" className={LABEL_CLS}>Confirm new password</label>
              <input
                id="rp-confirm"
                type="password"
                autoComplete="new-password"
                value={confirm}
                onChange={(e) => setConfirm(e.target.value)}
                required
                aria-invalid={confirmError}
                aria-describedby={confirmError ? 'rp-confirm-error' : undefined}
                className={confirmError ? INPUT_ERROR : INPUT_NORMAL}
              />
              {confirmError && (
                <p id="rp-confirm-error" role="alert" className="mt-1.5 text-xs text-ok-error-fg">
                  Passwords do not match.
                </p>
              )}
            </div>

            <Button
              type="submit"
              className="w-full"
              loading={loading}
              disabled={!allRulesMet || confirmError || confirm.length === 0}
            >
              Set new password
            </Button>
          </div>
        </form>

        <div className="mt-8 pt-6 border-t border-ok-border text-center">
          <Link
            href="/login"
            className="text-sm text-text-secondary hover:text-text-primary transition-colors focus-visible:outline-2 rounded"
          >
            ← Back to sign in
          </Link>
        </div>

        <p className="mt-6 text-center text-xs text-text-secondary">
          Authorized personnel only. All sessions are logged and monitored.
        </p>
      </div>
    </main>
  );
}

function Logo() {
  return (
    <div className="flex items-center gap-3 mb-10">
      <div
        className="w-9 h-9 rounded-lg bg-ok-primary flex items-center justify-center flex-shrink-0"
        aria-hidden="true"
      >
        <span className="text-[11px] font-black text-white select-none tracking-tight">OK</span>
      </div>
      <div>
        <p className="text-[15px] font-bold text-text-primary leading-none tracking-tight">OleKard</p>
        <p className="text-[10px] text-text-secondary uppercase tracking-[0.12em] leading-none mt-[5px]">
          Admin Platform
        </p>
      </div>
    </div>
  );
}
