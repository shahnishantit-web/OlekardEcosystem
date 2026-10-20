'use client';

import { type FormEvent, useId, useState } from 'react';
import Link from 'next/link';
import { Button } from '@/components/ui/Button';
import { requestPasswordReset } from '@/src/modules/auth/api/password-recovery';
import { isApiError } from '@/lib/utils/errors';

const INPUT_BASE =
  'w-full rounded-lg border bg-ok-input-bg px-4 h-[42px] text-sm text-text-primary ' +
  'placeholder:text-text-secondary/50 transition-colors ' +
  'focus:outline-none focus:border-ok-primary focus:ring-1 focus:ring-ok-focus-ring';
const INPUT_NORMAL = `${INPUT_BASE} border-ok-input-border`;
const INPUT_ERROR  = `${INPUT_BASE} border-ok-error-fg bg-ok-error-bg`;
const LABEL_CLS    = 'block text-sm font-medium text-text-primary mb-1.5';

type Phase = 'form' | 'success';

function isValidEmail(v: string): boolean {
  return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(v.trim());
}

export function ForgotPasswordPage() {
  const headingId = useId();
  const statusId  = useId();

  const [phase,   setPhase]   = useState<Phase>('form');
  const [email,   setEmail]   = useState('');
  const [loading, setLoading] = useState(false);
  const [error,   setError]   = useState<string | null>(null);

  const emailInvalid = error !== null && !isValidEmail(email);

  async function handleSubmit(e: FormEvent) {
    e.preventDefault();
    setError(null);
    if (!email.trim()) { setError('Email is required.'); return; }
    if (!isValidEmail(email)) { setError('Enter a valid email address.'); return; }

    setLoading(true);
    try {
      await requestPasswordReset(email.trim());
      setPhase('success');
    } catch (err) {
      const message = isApiError(err)
        ? err.error.message
        : 'Failed to send reset email. Please try again.';
      setError(message);
    } finally {
      setLoading(false);
    }
  }

  return (
    <main className="min-h-screen flex items-center justify-center bg-white px-6 py-12" aria-labelledby={headingId}>
      <div className="w-full max-w-[420px]">

        {/* Logo */}
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

        {phase === 'form' ? (
          <>
            <div className="mb-8">
              <h1 id={headingId} className="text-[26px] font-bold text-text-primary leading-tight tracking-tight">
                Reset your password
              </h1>
              <p className="mt-2 text-sm text-text-secondary">
                Enter your email address and we&apos;ll send you a reset link.
              </p>
            </div>

            {/* Error banner */}
            {!loading && error !== null && !emailInvalid && (
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
                <p className="text-sm text-ok-error-fg">{error}</p>
              </div>
            )}

            <form
              onSubmit={(e) => { void handleSubmit(e); }}
              noValidate
              aria-describedby={error !== null && !emailInvalid ? statusId : undefined}
            >
              <div className="flex flex-col gap-5">
                <div>
                  <label htmlFor="fp-email" className={LABEL_CLS}>Email address</label>
                  <input
                    id="fp-email"
                    type="email"
                    autoComplete="email"
                    value={email}
                    onChange={(e) => setEmail(e.target.value)}
                    placeholder="you@example.com"
                    required
                    aria-invalid={emailInvalid}
                    aria-describedby={emailInvalid ? 'fp-email-error' : undefined}
                    className={emailInvalid ? INPUT_ERROR : INPUT_NORMAL}
                  />
                  {emailInvalid && (
                    <p id="fp-email-error" role="alert" className="mt-1.5 text-xs text-ok-error-fg">
                      {error}
                    </p>
                  )}
                </div>

                <Button type="submit" className="w-full" loading={loading}>
                  Send reset link
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
          </>
        ) : (
          <>
            {/* Success state */}
            <div className="text-center">
              <div
                className="mx-auto mb-6 w-16 h-16 rounded-full bg-ok-success-bg flex items-center justify-center"
                aria-hidden="true"
              >
                <svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.8" strokeLinecap="round" strokeLinejoin="round" className="text-ok-success-fg">
                  <path d="M22 16.92v3a2 2 0 01-2.18 2 19.79 19.79 0 01-8.63-3.07A19.5 19.5 0 013.07 13a19.79 19.79 0 01-3.07-8.67A2 2 0 012 2.18h3a2 2 0 012 1.72 12.84 12.84 0 00.7 2.81 2 2 0 01-.45 2.11L6.1 9.91a16 16 0 006 6l1.09-1.09a2 2 0 012.11-.45 12.84 12.84 0 002.81.7A2 2 0 0122 16.92z"/>
                </svg>
              </div>

              <h1 id={headingId} className="text-[24px] font-bold text-text-primary leading-tight tracking-tight mb-3">
                Check your email
              </h1>
              <p className="text-sm text-text-secondary leading-relaxed mb-8">
                If <span className="font-medium text-text-primary">{email}</span> is registered, you&apos;ll receive a password reset link within a few minutes. Check your spam folder if you don&apos;t see it.
              </p>

              <Link
                href="/login"
                className="text-sm text-text-secondary hover:text-text-primary transition-colors focus-visible:outline-2 rounded"
              >
                ← Back to sign in
              </Link>
            </div>
          </>
        )}

        <p className="mt-10 text-center text-xs text-text-secondary">
          Authorized personnel only. All sessions are logged and monitored.
        </p>
      </div>
    </main>
  );
}
