'use client';

import { type FormEvent, useEffect, useId, useRef, useState } from 'react';
import Link from 'next/link';
import { useRouter } from 'next/navigation';
import { Button } from '@/components/ui/Button';
import { verifyEmail, resendVerification } from '@/src/modules/auth/api/password-recovery';
import { isApiError } from '@/lib/utils/errors';

type Phase =
  | 'verifying'
  | 'verified'
  | 'verify-error'
  | 'check-email'
  | 'resent';

interface Props {
  token: string | null;
  email: string | null;
}

export function VerifyEmailPage({ token, email: initialEmail }: Props) {
  const headingId = useId();
  const router    = useRouter();

  const [phase,        setPhase]        = useState<Phase>(token ? 'verifying' : 'check-email');
  const [resendEmail,  setResendEmail]  = useState(initialEmail ?? '');
  const [resendLoading, setResendLoading] = useState(false);
  const [resendError,  setResendError]  = useState<string | null>(null);
  const didVerify = useRef(false);

  useEffect(() => {
    if (!token || didVerify.current) return;
    didVerify.current = true;

    verifyEmail(token)
      .then(() => setPhase('verified'))
      .catch((err) => {
        if (
          isApiError(err) &&
          (err.error.code === 'TOKEN_EXPIRED' ||
            err.error.code === 'TOKEN_INVALID' ||
            err.error.code === 'INVALID_TOKEN')
        ) {
          setPhase('verify-error');
        } else {
          setPhase('verify-error');
        }
      });
  }, [token]);

  async function handleResend(e: FormEvent) {
    e.preventDefault();
    setResendError(null);
    if (!resendEmail.trim()) { setResendError('Email is required.'); return; }

    setResendLoading(true);
    try {
      await resendVerification(resendEmail.trim());
      setPhase('resent');
    } catch (err) {
      const message = isApiError(err)
        ? err.error.message
        : 'Failed to resend verification email. Please try again.';
      setResendError(message);
    } finally {
      setResendLoading(false);
    }
  }

  const INPUT_BASE =
    'w-full rounded-lg border bg-ok-input-bg px-4 h-[42px] text-sm text-text-primary ' +
    'placeholder:text-text-secondary/50 transition-colors ' +
    'focus:outline-none focus:border-ok-primary focus:ring-1 focus:ring-ok-focus-ring';

  return (
    <main
      className="min-h-screen flex items-center justify-center bg-white px-6 py-12"
      aria-labelledby={headingId}
    >
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

        {/* ── Verifying ─────────────────────────────────────────────────── */}
        {phase === 'verifying' && (
          <div className="text-center">
            <div
              className="mx-auto mb-6 w-16 h-16 rounded-full bg-ok-primary/10 flex items-center justify-center"
              aria-hidden="true"
            >
              <svg className="w-7 h-7 text-ok-primary animate-spin" viewBox="0 0 24 24" fill="none">
                <circle cx="12" cy="12" r="10" stroke="currentColor" strokeWidth="2" strokeDasharray="40 20" />
              </svg>
            </div>
            <h1 id={headingId} className="text-[24px] font-bold text-text-primary leading-tight tracking-tight mb-3">
              Verifying your email
            </h1>
            <p className="text-sm text-text-secondary">Please wait a moment…</p>
          </div>
        )}

        {/* ── Verified ──────────────────────────────────────────────────── */}
        {phase === 'verified' && (
          <div className="text-center">
            <div
              className="mx-auto mb-6 w-16 h-16 rounded-full bg-ok-success-bg flex items-center justify-center"
              aria-hidden="true"
            >
              <svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.8" strokeLinecap="round" strokeLinejoin="round" className="text-ok-success-fg">
                <path d="M22 11.08V12a10 10 0 11-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/>
              </svg>
            </div>
            <h1 id={headingId} className="text-[24px] font-bold text-text-primary leading-tight tracking-tight mb-3">
              Email verified
            </h1>
            <p className="text-sm text-text-secondary leading-relaxed mb-8">
              Your email address has been verified. You can now sign in to your account.
            </p>
            <Button className="w-full" onClick={() => router.replace('/login')}>
              Go to sign in
            </Button>
          </div>
        )}

        {/* ── Verify error ──────────────────────────────────────────────── */}
        {phase === 'verify-error' && (
          <div className="text-center">
            <div
              className="mx-auto mb-6 w-16 h-16 rounded-full bg-ok-warning-bg flex items-center justify-center"
              aria-hidden="true"
            >
              <svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.8" strokeLinecap="round" strokeLinejoin="round" className="text-ok-warning-fg">
                <circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/>
              </svg>
            </div>
            <h1 id={headingId} className="text-[24px] font-bold text-text-primary leading-tight tracking-tight mb-3">
              Link has expired
            </h1>
            <p className="text-sm text-text-secondary leading-relaxed mb-8">
              Verification links expire after 24 hours. Enter your email to receive a new one.
            </p>

            <form onSubmit={(e) => { void handleResend(e); }} noValidate className="text-left">
              {resendError && (
                <div role="alert" className="mb-4 rounded-lg border border-ok-error-border bg-ok-error-bg px-4 py-3">
                  <p className="text-sm text-ok-error-fg">{resendError}</p>
                </div>
              )}
              <label htmlFor="ve-resend-email" className="block text-sm font-medium text-text-primary mb-1.5">
                Email address
              </label>
              <input
                id="ve-resend-email"
                type="email"
                autoComplete="email"
                value={resendEmail}
                onChange={(e) => setResendEmail(e.target.value)}
                placeholder="you@example.com"
                required
                className={`${INPUT_BASE} border-ok-input-border mb-4`}
              />
              <Button type="submit" className="w-full" loading={resendLoading}>
                Resend verification email
              </Button>
            </form>

            <div className="mt-6">
              <Link href="/login" className="text-sm text-text-secondary hover:text-text-primary transition-colors focus-visible:outline-2 rounded">
                ← Back to sign in
              </Link>
            </div>
          </div>
        )}

        {/* ── Check email (no token) ─────────────────────────────────────── */}
        {phase === 'check-email' && (
          <div className="text-center">
            <div
              className="mx-auto mb-6 w-16 h-16 rounded-full bg-ok-primary/10 flex items-center justify-center"
              aria-hidden="true"
            >
              <svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.8" strokeLinecap="round" strokeLinejoin="round" className="text-ok-primary">
                <path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/>
              </svg>
            </div>
            <h1 id={headingId} className="text-[24px] font-bold text-text-primary leading-tight tracking-tight mb-3">
              Check your email
            </h1>
            <p className="text-sm text-text-secondary leading-relaxed mb-8">
              We&apos;ve sent a verification link to{' '}
              {initialEmail ? (
                <span className="font-medium text-text-primary">{initialEmail}</span>
              ) : (
                'your email address'
              )}
              . Click the link in the email to verify your account.
            </p>

            {/* Resend form — shows email input if no email prefilled */}
            <form onSubmit={(e) => { void handleResend(e); }} noValidate className="text-left">
              {resendError && (
                <div role="alert" className="mb-4 rounded-lg border border-ok-error-border bg-ok-error-bg px-4 py-3">
                  <p className="text-sm text-ok-error-fg">{resendError}</p>
                </div>
              )}
              {!initialEmail && (
                <>
                  <label htmlFor="ve-email" className="block text-sm font-medium text-text-primary mb-1.5">
                    Email address
                  </label>
                  <input
                    id="ve-email"
                    type="email"
                    autoComplete="email"
                    value={resendEmail}
                    onChange={(e) => setResendEmail(e.target.value)}
                    placeholder="you@example.com"
                    required
                    className={`${INPUT_BASE} border-ok-input-border mb-4`}
                  />
                </>
              )}
              <Button type="submit" className="w-full" loading={resendLoading} variant="secondary">
                Resend verification email
              </Button>
            </form>

            <div className="mt-6">
              <Link href="/login" className="text-sm text-text-secondary hover:text-text-primary transition-colors focus-visible:outline-2 rounded">
                ← Back to sign in
              </Link>
            </div>
          </div>
        )}

        {/* ── Resent ────────────────────────────────────────────────────── */}
        {phase === 'resent' && (
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
              Verification email sent
            </h1>
            <p className="text-sm text-text-secondary leading-relaxed mb-8">
              A new verification link has been sent to{' '}
              <span className="font-medium text-text-primary">{resendEmail}</span>.
              Check your spam folder if you don&apos;t see it.
            </p>
            <Link href="/login" className="text-sm text-text-secondary hover:text-text-primary transition-colors focus-visible:outline-2 rounded">
              ← Back to sign in
            </Link>
          </div>
        )}

        <p className="mt-10 text-center text-xs text-text-secondary">
          Authorized personnel only. All sessions are logged and monitored.
        </p>
      </div>
    </main>
  );
}
