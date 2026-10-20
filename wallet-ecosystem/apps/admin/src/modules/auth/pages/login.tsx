'use client';

import { type FormEvent, useEffect, useId, useRef, useState } from 'react';
import { useRouter, useSearchParams } from 'next/navigation';
import { Eye, EyeOff, ShieldCheck } from 'lucide-react';
import { Button } from '@/components/ui/Button';
import { Loading } from '@/components/ui/Loading';
import { useAuth } from '@/lib/auth/context';
import type { MfaMethod } from '@/lib/auth/context';

type LoginPhase = 'credentials' | 'mfa';

// Explicit hex — no CSS-variable dependencies at this unauthenticated route
const C = {
  primary:       '#596fb6',
  primaryDark:   '#435b9e',
  primaryLight:  '#eef1fb',
  text:          '#202636',
  textSub:       '#788194',
  textMuted:     '#98a1af',
  border:        '#dfe3eb',
  inputBg:       '#ffffff',
  errorFg:       '#c94d52',
  errorBg:       '#fff0f0',
  errorBorder:   '#f1c4c6',
  successFg:     '#16845e',
  successBg:     '#eaf8f1',
  successBorder: '#b6e8d0',
  infoBorder:    '#cbd4e8',
  panelBg:       '#eef1fb',
  white:         '#ffffff',
  focusRing:     '#99adeb',
};

const inputBase: React.CSSProperties = {
  display: 'block',
  width: '100%',
  height: '42px',
  padding: '0 12px',
  borderRadius: '8px',
  border: `1px solid ${C.border}`,
  background: C.inputBg,
  color: C.text,
  fontSize: '14px',
  outline: 'none',
  transition: 'border-color 0.15s, box-shadow 0.15s',
};
const inputError: React.CSSProperties = {
  ...inputBase,
  borderColor: C.errorFg,
  background: C.errorBg,
};

function XIcon() {
  return (
    <svg className="w-3.5 h-3.5" viewBox="0 0 16 16" fill="currentColor" aria-hidden="true">
      <path d="M3.22 3.22a.75.75 0 011.06 0L8 6.94l3.72-3.72a.75.75 0 111.06 1.06L9.06 8l3.72 3.72a.75.75 0 11-1.06 1.06L8 9.06l-3.72 3.72a.75.75 0 01-1.06-1.06L6.94 8 3.22 4.28a.75.75 0 010-1.06z" />
    </svg>
  );
}

// ── Right panel illustration ──────────────────────────────────────────────────
function BrandPanel() {
  return (
    <div
      style={{
        display: 'flex',
        flexDirection: 'column',
        alignItems: 'center',
        justifyContent: 'center',
        padding: '64px 48px',
        background: `linear-gradient(160deg, #dce4f5 0%, ${C.primaryLight} 100%)`,
        minHeight: '100vh',
        position: 'relative',
        overflow: 'hidden',
      }}
      aria-hidden="true"
    >
      {/* Background circles */}
      <div style={{
        position: 'absolute', top: '-80px', right: '-80px',
        width: '340px', height: '340px', borderRadius: '50%',
        background: 'rgba(89,111,182,0.08)',
      }} />
      <div style={{
        position: 'absolute', bottom: '-60px', left: '-60px',
        width: '260px', height: '260px', borderRadius: '50%',
        background: 'rgba(89,111,182,0.06)',
      }} />

      {/* Central illustration */}
      <svg
        width="220" height="220" viewBox="0 0 280 280" fill="none"
        xmlns="http://www.w3.org/2000/svg"
        style={{ marginBottom: '40px', position: 'relative' }}
      >
        <circle cx="140" cy="140" r="127" stroke="#b3bedd" strokeWidth="1" strokeDasharray="4 6" />
        <circle cx="140" cy="140" r="88" stroke="#c5cee8" strokeWidth="1" />
        <line x1="140" y1="52" x2="140" y2="102" stroke="#99adeb" strokeWidth="1.5" />
        <line x1="228" y1="140" x2="178" y2="140" stroke="#99adeb" strokeWidth="1.5" />
        <line x1="140" y1="228" x2="140" y2="178" stroke="#99adeb" strokeWidth="1.5" />
        <line x1="52" y1="140" x2="102" y2="140" stroke="#99adeb" strokeWidth="1.5" />
        <circle cx="140" cy="36" r="22" fill="white" stroke="#c5cee8" strokeWidth="1.5" />
        <circle cx="140" cy="30" r="5" fill="none" stroke="#596fb6" strokeWidth="1.5" />
        <path d="M130 46 q10-5 20 0" fill="none" stroke="#596fb6" strokeWidth="1.5" strokeLinecap="round" />
        <circle cx="244" cy="140" r="22" fill="white" stroke="#c5cee8" strokeWidth="1.5" />
        <rect x="234" y="133" width="20" height="14" rx="3" fill="none" stroke="#596fb6" strokeWidth="1.5" />
        <circle cx="249" cy="140" r="2.5" fill="#596fb6" />
        <circle cx="140" cy="244" r="22" fill="white" stroke="#c5cee8" strokeWidth="1.5" />
        <path d="M132 241 l8-5 8 5" fill="none" stroke="#596fb6" strokeWidth="1.5" strokeLinecap="round" strokeLinejoin="round" />
        <path d="M132 249 l8 5 8-5" fill="none" stroke="#596fb6" strokeWidth="1.5" strokeLinecap="round" strokeLinejoin="round" />
        <circle cx="36" cy="140" r="22" fill="white" stroke="#c5cee8" strokeWidth="1.5" />
        <circle cx="33" cy="136" r="4" fill="none" stroke="#596fb6" strokeWidth="1.5" />
        <circle cx="39" cy="136" r="4" fill="none" stroke="#596fb6" strokeWidth="1.5" />
        <path d="M26 147 q7-3 14 0" fill="none" stroke="#596fb6" strokeWidth="1.5" strokeLinecap="round" />
        <path d="M36 147 q7-3 14 0" fill="none" stroke="#596fb6" strokeWidth="1.5" strokeLinecap="round" />
        <circle cx="140" cy="140" r="42" fill="#dde4f5" />
        <circle cx="140" cy="140" r="36" fill="#eef1fb" />
        <rect x="122" y="122" width="36" height="36" rx="10" fill="#596fb6" />
        <text x="140" y="145" textAnchor="middle" fill="white" fontFamily="Inter,system-ui,sans-serif" fontSize="12" fontWeight="700" letterSpacing="-0.5">OK</text>
        <circle cx="186" cy="76" r="4" fill="#99adeb" opacity="0.5" />
        <circle cx="94" cy="76" r="3" fill="#99adeb" opacity="0.4" />
        <circle cx="216" cy="190" r="3" fill="#99adeb" opacity="0.4" />
        <circle cx="64" cy="190" r="4" fill="#99adeb" opacity="0.5" />
      </svg>

      {/* Copy */}
      <div style={{ textAlign: 'center', maxWidth: '300px', position: 'relative' }}>
        <h2 style={{ fontSize: '22px', fontWeight: 700, color: C.primaryDark, lineHeight: 1.3, margin: '0 0 12px' }}>
          One platform.<br />Complete control.
        </h2>
        <p style={{ fontSize: '13px', color: C.textSub, lineHeight: 1.6, margin: 0 }}>
          Manage digital wallets, monitor transactions, verify identities,
          and oversee the complete OleKard ecosystem from a single secure workspace.
        </p>
      </div>

      {/* Feature tags */}
      <div style={{ display: 'flex', flexWrap: 'wrap', gap: '8px', justifyContent: 'center', marginTop: '28px', maxWidth: '300px', position: 'relative' }}>
        {['Identity & KYC', 'Wallets', 'Payments', 'Agents', 'Compliance'].map((tag) => (
          <span
            key={tag}
            style={{
              padding: '4px 12px',
              borderRadius: '999px',
              fontSize: '11px',
              fontWeight: 500,
              background: C.white,
              color: C.primary,
              border: `1px solid rgba(89,111,182,0.25)`,
            }}
          >
            {tag}
          </span>
        ))}
      </div>
    </div>
  );
}

// ── Main component ────────────────────────────────────────────────────────────
export function AdminLoginPage() {
  const { phase, mfaMethods, authError, authErrorCode, login, completeMfa } = useAuth();
  const router    = useRouter();
  const headingId = useId();
  const statusId  = useId();

  const searchParams   = useSearchParams();
  const sessionExpired = searchParams.get('reason') === 'session_expired';
  const passwordReset  = searchParams.get('reset') === 'success';

  // Resolve the post-login destination. Only allow relative paths to prevent
  // open-redirect attacks — anything that looks like an absolute URL falls back
  // to /dashboard.
  const rawCallback   = searchParams.get('callbackUrl') ?? '';
  const callbackUrl   =
    rawCallback.startsWith('/') && !rawCallback.startsWith('//') && !rawCallback.startsWith('/\\')
      ? rawCallback
      : '/dashboard';

  const [uiPhase, setUiPhase]                       = useState<LoginPhase>('credentials');
  const [loading, setLoading]                       = useState(false);
  const [localError, setLocalError]                 = useState<string | null>(null);
  const [showPassword, setShowPassword]             = useState(false);
  const [bannerDismissed, setBannerDismissed]       = useState(false);
  const [resetBannerDismissed, setResetBannerDismissed] = useState(false);

  const [email,          setEmail]          = useState('');
  const [password,       setPassword]       = useState('');
  const [mfaCode,        setMfaCode]        = useState('');
  const [selectedMethod, setSelectedMethod] = useState<MfaMethod>('totp');

  const emailInputRef = useRef<HTMLInputElement>(null);
  const mfaInputRef   = useRef<HTMLInputElement>(null);

  useEffect(() => { emailInputRef.current?.focus(); }, []);

  useEffect(() => {
    if (phase === 'mfa_pending') {
      setUiPhase('mfa');
      setTimeout(() => mfaInputRef.current?.focus(), 50);
    }
  }, [phase]); // eslint-disable-line react-hooks/exhaustive-deps

  useEffect(() => {
    if (phase === 'authenticated') {
      // Use a full page navigation so the browser sends the freshly-written
      // admin_token cookie to the server, letting the middleware run clean and
      // server components hydrate in the authenticated state. A soft
      // router.replace() only changes the URL client-side and the middleware
      // never sees the new cookie, leaving the page stuck on the login shell.
      window.location.href = callbackUrl;
    }
  }, [phase, callbackUrl]);

  useEffect(() => {
    if (authErrorCode === 'ACCOUNT_LOCKED' || authErrorCode === 'IDENTITY_004')
      router.replace('/account-locked');
    if (authErrorCode === 'ACCOUNT_DISABLED') router.replace('/account-disabled');
    if (authErrorCode === 'EMAIL_UNVERIFIED') {
      const params = new URLSearchParams();
      if (email) params.set('email', email);
      router.replace(`/verify-email${params.size ? `?${params.toString()}` : ''}`);
    }
  }, [authErrorCode, email, router]);

  const displayError  = authError;
  const activeMethods = mfaMethods ?? ['totp'];
  const emailErr    = localError !== null && !email.trim();
  const passwordErr = localError !== null && !password;
  const codeErr     = localError !== null && !mfaCode.trim();

  async function handleCredentialsSubmit(e: FormEvent) {
    e.preventDefault();
    setLocalError(null);
    if (!email.trim()) { setLocalError('Email is required.');    return; }
    if (!password)     { setLocalError('Password is required.'); return; }
    setLoading(true);
    try { await login(email, password); } finally { setLoading(false); }
  }

  async function handleMfaSubmit(e: FormEvent) {
    e.preventDefault();
    setLocalError(null);
    if (!mfaCode.trim()) { setLocalError('Verification code is required.'); return; }
    setLoading(true);
    try { await completeMfa(selectedMethod, mfaCode); } finally { setLoading(false); }
  }

  return (
    <main
      style={{ display: 'flex', minHeight: '100vh', background: C.white }}
      aria-labelledby={headingId}
    >
      {/* ── Left: form panel ─────────────────────────────────────────────── */}
      <div style={{
        flex: 1,
        display: 'flex',
        flexDirection: 'column',
        justifyContent: 'center',
        alignItems: 'center',
        padding: '48px 24px',
        background: C.white,
      }}>
        <div style={{ width: '100%', maxWidth: '400px' }}>

          {/* Logo */}
          <div style={{ display: 'flex', alignItems: 'center', gap: '12px', marginBottom: '40px' }}>
            <div style={{
              width: '38px', height: '38px', borderRadius: '10px', flexShrink: 0,
              background: `linear-gradient(135deg, ${C.primary} 0%, ${C.primaryDark} 100%)`,
              display: 'flex', alignItems: 'center', justifyContent: 'center',
            }}>
              <span style={{ fontSize: '11px', fontWeight: 900, color: '#fff', letterSpacing: '-0.5px' }}>OK</span>
            </div>
            <div>
              <p style={{ margin: 0, fontSize: '15px', fontWeight: 700, color: C.text, lineHeight: 1 }}>OleKard</p>
              <p style={{ margin: '5px 0 0', fontSize: '10px', color: C.textMuted, textTransform: 'uppercase', letterSpacing: '0.12em', lineHeight: 1 }}>
                Admin Platform
              </p>
            </div>
          </div>

          {/* Heading */}
          <div style={{ marginBottom: '28px' }}>
            <h1 id={headingId} style={{ margin: 0, fontSize: '26px', fontWeight: 700, color: C.text, letterSpacing: '-0.5px', lineHeight: 1.2 }}>
              {uiPhase === 'mfa' ? 'Verify your identity' : 'Welcome back'}
            </h1>
            <p style={{ margin: '8px 0 0', fontSize: '14px', color: C.textSub }}>
              {uiPhase === 'mfa'
                ? 'Enter the code from your authenticator app.'
                : 'Sign in to your admin workspace'}
            </p>
          </div>

          {/* Password reset banner */}
          {passwordReset && !resetBannerDismissed && (
            <div role="status" aria-live="polite" style={{
              marginBottom: '20px', padding: '12px 14px', borderRadius: '8px',
              border: `1px solid ${C.successBorder}`, background: C.successBg,
              display: 'flex', alignItems: 'flex-start', gap: '10px',
            }}>
              <svg width="16" height="16" viewBox="0 0 16 16" fill={C.successFg} style={{ flexShrink: 0, marginTop: '1px' }} aria-hidden="true">
                <path d="M8 1a7 7 0 100 14A7 7 0 008 1zm3.78 5.78a.75.75 0 00-1.06-1.06L7 9.44 5.28 7.72a.75.75 0 00-1.06 1.06l2.25 2.25a.75.75 0 001.06 0l4.25-4.25z" />
              </svg>
              <p style={{ margin: 0, flex: 1, fontSize: '13px', color: C.successFg }}>Password reset successfully. Sign in with your new password.</p>
              <button type="button" onClick={() => setResetBannerDismissed(true)} aria-label="Dismiss" style={{ background: 'none', border: 'none', cursor: 'pointer', color: C.successFg, opacity: 0.7, padding: '2px' }}>
                <XIcon />
              </button>
            </div>
          )}

          {/* Session expired banner */}
          {sessionExpired && !bannerDismissed && (
            <div role="status" aria-live="polite" style={{
              marginBottom: '20px', padding: '12px 14px', borderRadius: '8px',
              border: `1px solid ${C.infoBorder}`, background: C.primaryLight,
              display: 'flex', alignItems: 'flex-start', gap: '10px',
            }}>
              <svg width="16" height="16" viewBox="0 0 16 16" fill={C.primaryDark} style={{ flexShrink: 0, marginTop: '1px' }} aria-hidden="true">
                <path d="M8 1a7 7 0 100 14A7 7 0 008 1zm.75 4.75a.75.75 0 00-1.5 0V8a.75.75 0 001.5 0V5.75zm-.75 5.5a.875.875 0 100-1.75.875.875 0 000 1.75z" />
              </svg>
              <p style={{ margin: 0, flex: 1, fontSize: '13px', color: C.primaryDark }}>Your session has expired. Please sign in again.</p>
              <button type="button" onClick={() => setBannerDismissed(true)} aria-label="Dismiss" style={{ background: 'none', border: 'none', cursor: 'pointer', color: C.primaryDark, opacity: 0.7, padding: '2px' }}>
                <XIcon />
              </button>
            </div>
          )}

          {/* Loading */}
          {loading && (
            <div aria-live="polite" aria-atomic="true" style={{ marginBottom: '20px' }}>
              <Loading label={uiPhase === 'mfa' ? 'Verifying…' : 'Signing in…'} />
            </div>
          )}

          {/* Error */}
          {!loading && displayError !== null && (
            <div id={statusId} role="alert" aria-live="assertive" aria-atomic="true" style={{
              marginBottom: '20px', padding: '12px 14px', borderRadius: '8px',
              border: `1px solid ${C.errorBorder}`, background: C.errorBg,
              display: 'flex', alignItems: 'flex-start', gap: '10px',
            }}>
              <svg width="16" height="16" viewBox="0 0 16 16" fill={C.errorFg} style={{ flexShrink: 0, marginTop: '1px' }} aria-hidden="true">
                <path fillRule="evenodd" d="M8 1a7 7 0 100 14A7 7 0 008 1zM7.25 5a.75.75 0 011.5 0v3.25a.75.75 0 01-1.5 0V5zm.75 7.25a.875.875 0 100-1.75.875.875 0 000 1.75z" />
              </svg>
              <p style={{ margin: 0, fontSize: '13px', color: C.errorFg }}>{displayError}</p>
            </div>
          )}

          {/* ── Credentials form ───────────────────────────────── */}
          {!loading && uiPhase === 'credentials' && (
            <form onSubmit={(e) => { void handleCredentialsSubmit(e); }} noValidate aria-describedby={displayError !== null ? statusId : undefined}>
              <div style={{ display: 'flex', flexDirection: 'column', gap: '18px' }}>

                {/* Email */}
                <div>
                  <label htmlFor="email-input" style={{ display: 'block', fontSize: '13px', fontWeight: 600, color: C.text, marginBottom: '6px' }}>
                    Email address
                  </label>
                  <input
                    ref={emailInputRef}
                    id="email-input"
                    type="email"
                    autoComplete="email"
                    value={email}
                    onChange={(e) => setEmail(e.target.value)}
                    placeholder="you@example.com"
                    required
                    aria-invalid={emailErr}
                    aria-describedby={emailErr ? 'email-error' : undefined}
                    style={emailErr ? inputError : inputBase}
                    onFocus={(e) => { e.currentTarget.style.borderColor = C.primary; e.currentTarget.style.boxShadow = `0 0 0 3px ${C.focusRing}40`; }}
                    onBlur={(e)  => { e.currentTarget.style.borderColor = emailErr ? C.errorFg : C.border; e.currentTarget.style.boxShadow = 'none'; }}
                  />
                  {emailErr && <p id="email-error" role="alert" style={{ margin: '5px 0 0', fontSize: '12px', color: C.errorFg }}>Email is required.</p>}
                </div>

                {/* Password */}
                <div>
                  <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', marginBottom: '6px' }}>
                    <label htmlFor="password-input" style={{ fontSize: '13px', fontWeight: 600, color: C.text }}>
                      Password
                    </label>
                    <a href="/forgot-password" style={{ fontSize: '12px', color: C.primary, textDecoration: 'none' }}>
                      Forgot password?
                    </a>
                  </div>
                  <div style={{ position: 'relative' }}>
                    <input
                      id="password-input"
                      type={showPassword ? 'text' : 'password'}
                      autoComplete="current-password"
                      value={password}
                      onChange={(e) => setPassword(e.target.value)}
                      required
                      aria-invalid={passwordErr}
                      aria-describedby={passwordErr ? 'password-error' : undefined}
                      style={{ ...(passwordErr ? inputError : inputBase), paddingRight: '42px' }}
                      onFocus={(e) => { e.currentTarget.style.borderColor = C.primary; e.currentTarget.style.boxShadow = `0 0 0 3px ${C.focusRing}40`; }}
                      onBlur={(e)  => { e.currentTarget.style.borderColor = passwordErr ? C.errorFg : C.border; e.currentTarget.style.boxShadow = 'none'; }}
                    />
                    <button
                      type="button"
                      onClick={() => setShowPassword((v) => !v)}
                      aria-label={showPassword ? 'Hide password' : 'Show password'}
                      style={{ position: 'absolute', right: '10px', top: '50%', transform: 'translateY(-50%)', background: 'none', border: 'none', cursor: 'pointer', color: C.textMuted, padding: '4px', display: 'flex' }}
                    >
                      {showPassword ? <EyeOff size={15} aria-hidden="true" /> : <Eye size={15} aria-hidden="true" />}
                    </button>
                  </div>
                  {passwordErr && <p id="password-error" role="alert" style={{ margin: '5px 0 0', fontSize: '12px', color: C.errorFg }}>Password is required.</p>}
                </div>

                <Button type="submit" style={{ width: '100%', marginTop: '4px' }}>
                  Sign in
                </Button>
              </div>

              {/* MFA notice */}
              <div style={{ marginTop: '28px', paddingTop: '20px', borderTop: `1px solid ${C.border}`, display: 'flex', alignItems: 'center', gap: '10px' }}>
                <div style={{ width: '30px', height: '30px', borderRadius: '50%', background: C.primaryLight, display: 'flex', alignItems: 'center', justifyContent: 'center', flexShrink: 0 }}>
                  <ShieldCheck size={14} color={C.primaryDark} aria-hidden="true" />
                </div>
                <p style={{ margin: 0, fontSize: '12px', color: C.textSub, lineHeight: 1.5 }}>
                  Multi-factor authentication is required for all admin sign-ins.
                </p>
              </div>
            </form>
          )}

          {/* ── MFA form ──────────────────────────────────────── */}
          {!loading && uiPhase === 'mfa' && (
            <form onSubmit={(e) => { void handleMfaSubmit(e); }} noValidate aria-describedby={displayError !== null ? statusId : undefined}>
              <div style={{ display: 'flex', flexDirection: 'column', gap: '18px' }}>

                {activeMethods.length > 1 && (
                  <fieldset style={{ border: 'none', padding: 0, margin: 0 }}>
                    <legend style={{ fontSize: '13px', fontWeight: 600, color: C.text, marginBottom: '8px' }}>Authentication method</legend>
                    <div style={{ display: 'flex', gap: '16px' }}>
                      {activeMethods.map((m) => (
                        <label key={m} style={{ display: 'flex', alignItems: 'center', gap: '6px', fontSize: '14px', color: C.text, cursor: 'pointer' }}>
                          <input type="radio" name="mfa-method" value={m} checked={selectedMethod === m} onChange={() => setSelectedMethod(m)} style={{ accentColor: C.primary }} />
                          {m === 'totp' ? 'Authenticator app' : 'Security key'}
                        </label>
                      ))}
                    </div>
                  </fieldset>
                )}

                <div>
                  <label htmlFor="mfa-input" style={{ display: 'block', fontSize: '13px', fontWeight: 600, color: C.text, marginBottom: '6px' }}>
                    {selectedMethod === 'totp' ? '6-digit verification code' : 'Security key code'}
                  </label>
                  {selectedMethod === 'totp' && (
                    <p style={{ margin: '0 0 8px', fontSize: '12px', color: C.textSub }}>
                      Open your authenticator app and enter the code shown.
                    </p>
                  )}
                  <input
                    ref={mfaInputRef}
                    id="mfa-input"
                    type="text"
                    inputMode="numeric"
                    autoComplete="one-time-code"
                    maxLength={6}
                    value={mfaCode}
                    onChange={(e) => setMfaCode(e.target.value.replace(/\D/g, '').slice(0, 6))}
                    placeholder="000000"
                    required
                    aria-invalid={codeErr}
                    aria-describedby={codeErr ? 'mfa-error' : undefined}
                    style={{ ...( codeErr ? inputError : inputBase), fontFamily: 'monospace', letterSpacing: '0.4em', textAlign: 'center' }}
                    onFocus={(e) => { e.currentTarget.style.borderColor = C.primary; e.currentTarget.style.boxShadow = `0 0 0 3px ${C.focusRing}40`; }}
                    onBlur={(e)  => { e.currentTarget.style.borderColor = codeErr ? C.errorFg : C.border; e.currentTarget.style.boxShadow = 'none'; }}
                  />
                  {codeErr && <p id="mfa-error" role="alert" style={{ margin: '5px 0 0', fontSize: '12px', color: C.errorFg }}>Verification code is required.</p>}
                </div>

                <Button type="submit" style={{ width: '100%', marginTop: '4px' }}>
                  Verify
                </Button>

                <button
                  type="button"
                  onClick={() => { setUiPhase('credentials'); setMfaCode(''); setLocalError(null); }}
                  aria-label="Back to sign in"
                  style={{ background: 'none', border: 'none', cursor: 'pointer', fontSize: '13px', color: C.textSub, textAlign: 'center' }}
                >
                  ← Back to sign in
                </button>
              </div>
            </form>
          )}

          <p style={{ marginTop: '40px', textAlign: 'center', fontSize: '11px', color: C.textMuted }}>
            Authorized personnel only · All sessions are monitored
          </p>
        </div>
      </div>

      {/* ── Right: brand panel (hidden on small screens) ─────────────────── */}
      <div className="hidden lg:block lg:w-[46%] xl:w-[47%]">
        <BrandPanel />
      </div>
    </main>
  );
}
