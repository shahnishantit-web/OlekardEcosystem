'use client';

import { type FormEvent, useId, useRef, useState } from 'react';
import { useRouter } from 'next/navigation';
import { Button } from '@/components/ui/Button';
import { useAuth } from '@/lib/auth/context';
import {
  initiateTotpEnrollment,
  confirmTotpEnrollment,
  initiateFido2Enrollment,
  confirmFido2Enrollment,
  regenerateRecoveryCodes,
  createWebAuthnCredential,
  isWebAuthnSupported,
  base64ToBuffer,
  bufferToBase64,
} from '@/src/modules/auth/api/mfa-enrollment';
import { isApiError } from '@/lib/utils/errors';

type Step =
  | 'choose-method'
  | 'totp-start'
  | 'totp-verify'
  | 'fido2-start'
  | 'fido2-not-supported'
  | 'recovery-codes'
  | 'complete';

function clearMfaSetupCookie(): void {
  if (typeof document === 'undefined') return;
  document.cookie = 'admin_mfa_setup=; path=/; max-age=0; SameSite=Lax';
}

function extractTotpSecret(totpUri: string): string {
  try {
    const url    = new URL(totpUri);
    const secret = url.searchParams.get('secret');
    return secret ?? '';
  } catch {
    return '';
  }
}

export function MfaEnrollmentPage() {
  const headingId  = useId();
  const router     = useRouter();
  const { authenticatedClient, user } = useAuth();

  const [step,          setStep]         = useState<Step>('choose-method');
  const [error,         setError]        = useState<string | null>(null);
  const [loading,       setLoading]      = useState(false);
  const [totpUri,       setTotpUri]      = useState('');
  const [enrollmentId,  setEnrollmentId] = useState('');
  const [totpCode,      setTotpCode]     = useState('');
  const [recoveryCodes,  setRecoveryCodes]  = useState<string[]>([]);
  const [codesConfirmed, setCodesConfirmed] = useState(false);
  const [regenerating,   setRegenerating]   = useState(false);
  const didCopyRef = useRef(false);

  if (!authenticatedClient) {
    router.replace('/login');
    return null;
  }

  const totpSecret = extractTotpSecret(totpUri);

  async function handleChooseTotp() {
    setError(null);
    setLoading(true);
    try {
      const result = await initiateTotpEnrollment(authenticatedClient!);
      setTotpUri(result.totp_uri);
      setEnrollmentId(result.enrollment_id);
      setStep('totp-start');
    } catch (err) {
      setError(isApiError(err) ? err.error.message : 'Failed to start TOTP enrollment.');
    } finally {
      setLoading(false);
    }
  }

  function handleChooseFido2() {
    if (!isWebAuthnSupported()) {
      setStep('fido2-not-supported');
    } else {
      setStep('fido2-start');
    }
  }

  async function handleVerifyTotp(e: FormEvent) {
    e.preventDefault();
    if (!totpCode.trim()) return;
    setError(null);
    setLoading(true);
    try {
      const result = await confirmTotpEnrollment(authenticatedClient!, enrollmentId, totpCode.trim());
      setRecoveryCodes(result.backup_codes);
      setStep('recovery-codes');
    } catch (err) {
      setError(
        isApiError(err)
          ? err.error.message
          : 'Invalid code. Please check your authenticator app and try again.',
      );
    } finally {
      setLoading(false);
    }
  }

  async function handleFido2Enroll() {
    setError(null);
    setLoading(true);
    try {
      const started = await initiateFido2Enrollment(authenticatedClient!);

      const challengeBuffer = base64ToBuffer(started.challenge);
      const userIdBuffer    = new TextEncoder().encode(user?.id ?? 'admin');

      const credential = await createWebAuthnCredential({
        publicKey: {
          challenge:              challengeBuffer,
          rp:                     { name: 'OleKard Admin', id: started.rp_id },
          user:                   { id: userIdBuffer, name: user?.email ?? 'admin', displayName: user?.name ?? 'Admin' },
          pubKeyCredParams:       [{ type: 'public-key', alg: -7 }],
          timeout:                60_000,
          attestation:            'none',
          authenticatorSelection: { userVerification: 'preferred' },
        },
      });

      if (!credential || credential.type !== 'public-key') {
        throw new Error('Registration was cancelled or failed.');
      }

      const pkc = credential as PublicKeyCredential;
      const response = pkc.response as AuthenticatorAttestationResponse;

      const credentialId = bufferToBase64(pkc.rawId);
      const publicKey    = bufferToBase64(response.attestationObject);

      await confirmFido2Enrollment(
        authenticatedClient!,
        started.enrollment_id,
        credentialId,
        publicKey,
        0,
      );

      // Phase 12: no backup codes for FIDO2 — skip to complete
      clearMfaSetupCookie();
      setStep('complete');
    } catch (err) {
      if (err instanceof DOMException && err.name === 'NotAllowedError') {
        setError('Registration was cancelled. Please try again.');
      } else if (isApiError(err)) {
        setError(err.error.message);
      } else if (err instanceof Error) {
        setError(err.message);
      } else {
        setError('FIDO2 enrollment failed. Please try again.');
      }
    } finally {
      setLoading(false);
    }
  }

  async function handleRegenerateCodes() {
    setError(null);
    setRegenerating(true);
    setCodesConfirmed(false);
    try {
      const codes = await regenerateRecoveryCodes(authenticatedClient!);
      setRecoveryCodes(codes);
    } catch (err) {
      setError(isApiError(err) ? err.error.message : 'Failed to regenerate codes. Please try again.');
    } finally {
      setRegenerating(false);
    }
  }

  function handleDownloadCodes() {
    const content = recoveryCodes.join('\n');
    const blob    = new Blob([content], { type: 'text/plain' });
    const url     = URL.createObjectURL(blob);
    const a       = document.createElement('a');
    a.href        = url;
    a.download    = 'olekard-recovery-codes.txt';
    a.click();
    URL.revokeObjectURL(url);
    didCopyRef.current = true;
  }

  function handleContinueFromRecoveryCodes() {
    clearMfaSetupCookie();
    setStep('complete');
  }

  function handleGoToDashboard() {
    router.replace('/dashboard');
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
      <div className="w-full max-w-[440px]">
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

        {/* Global error */}
        {error && (
          <div role="alert" className="mb-6 rounded-lg border border-ok-error-border bg-ok-error-bg px-4 py-3">
            <p className="text-sm text-ok-error-fg">{error}</p>
          </div>
        )}

        {/* ── Step: Choose method ───────────────────────────────────────── */}
        {step === 'choose-method' && (
          <>
            <div
              className="mb-6 w-14 h-14 rounded-full bg-ok-primary/10 flex items-center justify-center"
              aria-hidden="true"
            >
              <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.8" strokeLinecap="round" strokeLinejoin="round" className="text-ok-primary">
                <rect x="5" y="11" width="14" height="10" rx="2"/><path d="M8 11V7a4 4 0 018 0v4"/>
              </svg>
            </div>
            <h1 id={headingId} className="text-[24px] font-bold text-text-primary leading-tight tracking-tight mb-2">
              Set up two-factor authentication
            </h1>
            <p className="text-sm text-text-secondary leading-relaxed mb-8">
              Your organisation requires MFA. Choose a method to protect your account.
            </p>

            <div className="space-y-3">
              <button
                type="button"
                onClick={() => { void handleChooseTotp(); }}
                disabled={loading}
                className="w-full flex items-start gap-4 rounded-xl border border-neutral-200 hover:border-ok-primary p-4 text-left transition-colors focus-visible:outline-2 focus-visible:outline-ok-primary disabled:opacity-50"
              >
                <div className="mt-0.5 w-10 h-10 rounded-lg bg-ok-primary/10 flex items-center justify-center flex-shrink-0">
                  <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.8" strokeLinecap="round" strokeLinejoin="round" className="text-ok-primary">
                    <rect x="5" y="2" width="14" height="20" rx="2"/><line x1="12" y1="18" x2="12.01" y2="18"/>
                  </svg>
                </div>
                <div>
                  <p className="text-sm font-semibold text-text-primary">Authenticator app</p>
                  <p className="text-xs text-text-secondary mt-0.5">
                    Use Google Authenticator, Authy, or any TOTP app.
                  </p>
                </div>
              </button>

              <button
                type="button"
                onClick={handleChooseFido2}
                disabled={loading}
                className="w-full flex items-start gap-4 rounded-xl border border-neutral-200 hover:border-ok-primary p-4 text-left transition-colors focus-visible:outline-2 focus-visible:outline-ok-primary disabled:opacity-50"
              >
                <div className="mt-0.5 w-10 h-10 rounded-lg bg-ok-primary/10 flex items-center justify-center flex-shrink-0">
                  <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.8" strokeLinecap="round" strokeLinejoin="round" className="text-ok-primary">
                    <path d="M21 2l-2 2m-7.61 7.61a5.5 5.5 0 11-7.778 7.778 5.5 5.5 0 017.777-7.777zm0 0L15.5 7.5m0 0l3 3L22 7l-3-3m-3.5 3.5L19 4"/>
                  </svg>
                </div>
                <div>
                  <p className="text-sm font-semibold text-text-primary">Security key or passkey</p>
                  <p className="text-xs text-text-secondary mt-0.5">
                    Use a hardware key (YubiKey) or built-in biometrics (Touch ID, Windows Hello).
                  </p>
                </div>
              </button>
            </div>
          </>
        )}

        {/* ── Step: TOTP start ──────────────────────────────────────────── */}
        {step === 'totp-start' && (
          <>
            <h1 id={headingId} className="text-[22px] font-bold text-text-primary leading-tight tracking-tight mb-2">
              Add to your authenticator app
            </h1>
            <p className="text-sm text-text-secondary leading-relaxed mb-6">
              Open your authenticator app and add a new account using the setup key below, then tap Next.
            </p>

            {totpSecret && (
              <div className="mb-6 rounded-xl border border-neutral-200 bg-ok-surface p-4">
                <p className="text-xs text-text-secondary uppercase tracking-widest mb-2">Setup key</p>
                <p className="font-mono text-sm font-semibold text-text-primary break-all select-all">{totpSecret}</p>
              </div>
            )}

            <div className="mb-6 rounded-lg bg-neutral-50 border border-neutral-200 px-4 py-3">
              <p className="text-xs text-text-secondary">
                <strong className="text-text-primary">Can&apos;t see a QR code?</strong>{' '}
                Copy the setup key above and paste it into your authenticator app as a &quot;Manual entry&quot; or &quot;Enter key manually&quot;.
              </p>
            </div>

            <Button className="w-full" onClick={() => setStep('totp-verify')}>
              Next — enter verification code
            </Button>
            <button
              type="button"
              onClick={() => setStep('choose-method')}
              className="mt-3 w-full text-center text-sm text-text-secondary hover:text-text-primary transition-colors focus-visible:outline-2 rounded py-1"
            >
              ← Back
            </button>
          </>
        )}

        {/* ── Step: TOTP verify ─────────────────────────────────────────── */}
        {step === 'totp-verify' && (
          <>
            <h1 id={headingId} className="text-[22px] font-bold text-text-primary leading-tight tracking-tight mb-2">
              Enter the 6-digit code
            </h1>
            <p className="text-sm text-text-secondary leading-relaxed mb-6">
              Open your authenticator app and enter the current code for OleKard.
            </p>

            <form onSubmit={(e) => { void handleVerifyTotp(e); }} noValidate>
              <label htmlFor="mfa-totp-code" className="block text-sm font-medium text-text-primary mb-1.5">
                Verification code
              </label>
              <input
                id="mfa-totp-code"
                type="text"
                inputMode="numeric"
                autoComplete="one-time-code"
                maxLength={6}
                value={totpCode}
                onChange={(e) => setTotpCode(e.target.value.replace(/\D/g, '').slice(0, 6))}
                placeholder="000000"
                required
                className={`${INPUT_BASE} border-ok-input-border mb-4 text-center tracking-[0.3em] text-[18px]`}
              />
              <Button type="submit" className="w-full" loading={loading} disabled={totpCode.length < 6}>
                Verify and activate
              </Button>
            </form>
            <button
              type="button"
              onClick={() => setStep('totp-start')}
              className="mt-3 w-full text-center text-sm text-text-secondary hover:text-text-primary transition-colors focus-visible:outline-2 rounded py-1"
            >
              ← Back
            </button>
          </>
        )}

        {/* ── Step: FIDO2 start ─────────────────────────────────────────── */}
        {step === 'fido2-start' && (
          <>
            <h1 id={headingId} className="text-[22px] font-bold text-text-primary leading-tight tracking-tight mb-2">
              Register your security key or passkey
            </h1>
            <p className="text-sm text-text-secondary leading-relaxed mb-6">
              Click the button below and follow your browser prompt to register your security key, Touch ID, or Windows Hello.
            </p>

            <Button
              className="w-full"
              onClick={() => { void handleFido2Enroll(); }}
              loading={loading}
            >
              Register passkey or security key
            </Button>
            <button
              type="button"
              onClick={() => setStep('choose-method')}
              className="mt-3 w-full text-center text-sm text-text-secondary hover:text-text-primary transition-colors focus-visible:outline-2 rounded py-1"
            >
              ← Back
            </button>
          </>
        )}

        {/* ── Step: FIDO2 not supported ─────────────────────────────────── */}
        {step === 'fido2-not-supported' && (
          <>
            <div
              className="mb-6 w-14 h-14 rounded-full bg-ok-warning-bg flex items-center justify-center"
              aria-hidden="true"
            >
              <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.8" strokeLinecap="round" strokeLinejoin="round" className="text-ok-warning-fg">
                <circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/>
              </svg>
            </div>
            <h1 id={headingId} className="text-[22px] font-bold text-text-primary leading-tight tracking-tight mb-2">
              Passkeys not supported
            </h1>
            <p className="text-sm text-text-secondary leading-relaxed mb-6">
              Your browser does not support hardware security keys. Use the authenticator app instead.
            </p>
            <Button className="w-full" onClick={() => setStep('choose-method')}>
              Use authenticator app instead
            </Button>
          </>
        )}

        {/* ── Step: Recovery codes ──────────────────────────────────────── */}
        {step === 'recovery-codes' && (
          <>
            <div
              className="mb-6 w-14 h-14 rounded-full bg-ok-success-bg flex items-center justify-center"
              aria-hidden="true"
            >
              <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.8" strokeLinecap="round" strokeLinejoin="round" className="text-ok-success-fg">
                <path d="M22 11.08V12a10 10 0 11-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/>
              </svg>
            </div>
            <h1 id={headingId} className="text-[22px] font-bold text-text-primary leading-tight tracking-tight mb-2">
              Save your recovery codes
            </h1>
            <p className="text-sm text-text-secondary leading-relaxed mb-4">
              Store these codes in a safe place. Each code can be used once to sign in if you lose access to your authenticator.
            </p>

            <div className="mb-4 rounded-xl border border-neutral-200 bg-neutral-50 p-4">
              <ul className="grid grid-cols-2 gap-2" aria-label="Recovery codes">
                {recoveryCodes.map((code) => (
                  <li key={code} className="font-mono text-sm font-medium text-text-primary text-center py-1.5 px-2 rounded bg-white border border-neutral-200">
                    {code}
                  </li>
                ))}
              </ul>
            </div>

            <div className="flex gap-2 mb-4">
              <Button
                variant="secondary"
                className="flex-1"
                onClick={handleDownloadCodes}
              >
                Download recovery codes (.txt)
              </Button>
              <Button
                variant="secondary"
                className="flex-shrink-0"
                onClick={() => { void handleRegenerateCodes(); }}
                loading={regenerating}
                aria-label="Regenerate recovery codes"
              >
                Regenerate
              </Button>
            </div>

            <label className="flex items-start gap-3 mb-4 cursor-pointer">
              <input
                id="codes-confirmed"
                type="checkbox"
                checked={codesConfirmed}
                onChange={(e) => setCodesConfirmed(e.target.checked)}
                className="mt-0.5 w-4 h-4 accent-ok-primary flex-shrink-0"
              />
              <span className="text-sm text-text-primary">
                I have saved my recovery codes in a safe place
              </span>
            </label>

            <Button
              className="w-full"
              onClick={handleContinueFromRecoveryCodes}
              disabled={!codesConfirmed}
            >
              Continue
            </Button>
          </>
        )}

        {/* ── Step: Complete ────────────────────────────────────────────── */}
        {step === 'complete' && (
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
              MFA is now active
            </h1>
            <p className="text-sm text-text-secondary leading-relaxed mb-8">
              Your account is protected. You&apos;ll be asked to verify with your second factor on every sign-in.
            </p>
            <Button className="w-full" onClick={handleGoToDashboard}>
              Go to dashboard
            </Button>
          </div>
        )}

        <p className="mt-10 text-center text-xs text-text-secondary">
          Authorized personnel only. All sessions are logged and monitored.
        </p>
      </div>
    </main>
  );
}
