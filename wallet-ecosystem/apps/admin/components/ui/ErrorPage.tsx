import type { ReactNode } from 'react';
import Link from 'next/link';
// ── Types ────────────────────────────────────────────────────────────────────
export type ErrorPageVariant = 'error' | 'warning' | 'info' | 'neutral';
interface ActionProps {
    label: string;
    href?: string;
    onClick?: () => void;
}
interface ErrorPageProps {
    code?: string | number;
    embedded?: boolean;
    children?: ReactNode;
    title: string;
    message: string;
    variant?: ErrorPageVariant;
    icon?: ReactNode;
    primaryAction?: ActionProps;
    secondaryAction?: ActionProps;
    /** Digest / correlation id shown as a hint to support. */
    correlationId?: string;
}
// ── Variant styling maps ─────────────────────────────────────────────────────
const ICON_BG: Record<ErrorPageVariant, string> = {
    error: 'bg-ok-error-bg',
    warning: 'bg-ok-warning-bg',
    info: 'bg-ok-info-bg',
    neutral: 'bg-neutral-100',
};
const ICON_FG: Record<ErrorPageVariant, string> = {
    error: 'text-ok-error-fg',
    warning: 'text-ok-warning-fg',
    info: 'text-ok-info-fg',
    neutral: 'text-text-secondary',
};
const CODE_COLOR: Record<ErrorPageVariant, string> = {
    error: 'text-ok-error-fg',
    warning: 'text-ok-warning-fg',
    info: 'text-ok-info-fg',
    neutral: 'text-text-secondary',
};
// ── Default icons ─────────────────────────────────────────────────────────────
function IconShieldOff() {
    return (<svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.8" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true">
      <path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/>
      <line x1="2" y1="2" x2="22" y2="22"/>
    </svg>);
}
function IconFileQuestion() {
    return (<svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.8" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true">
      <path d="M14.5 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7.5L14.5 2z"/>
      <polyline points="14 2 14 8 20 8"/>
      <path d="M9.1 15a3 3 0 0 1 5.82 1c0 2-3 3-3 3"/>
      <line x1="12" y1="22" x2="12.01" y2="22"/>
    </svg>);
}
function IconServerCrash() {
    return (<svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.8" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true">
      <rect x="2" y="2" width="20" height="8" rx="2" ry="2"/>
      <rect x="2" y="14" width="20" height="8" rx="2" ry="2"/>
      <line x1="6" y1="6" x2="6.01" y2="6"/>
      <line x1="6" y1="18" x2="6.01" y2="18"/>
      <line x1="14" y1="6" x2="22" y2="6"/>
      <path d="m14 18 4-4 4 4"/>
    </svg>);
}
function IconAlertTriangle() {
    return (<svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.8" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true">
      <path d="M10.29 3.86 1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/>
      <line x1="12" y1="9" x2="12" y2="13"/>
      <line x1="12" y1="17" x2="12.01" y2="17"/>
    </svg>);
}
export const DEFAULT_ICONS: Record<string, ReactNode> = {
    '403': <IconShieldOff />,
    '404': <IconFileQuestion />,
    '500': <IconServerCrash />,
    error: <IconAlertTriangle />,
};
// ── ActionButton helper ───────────────────────────────────────────────────────
function ActionButton({ action, variant, }: {
    action: ActionProps;
    variant: 'primary' | 'secondary';
}) {
    const baseClass = variant === 'primary'
        ? 'inline-flex items-center justify-center w-full sm:w-auto px-6 py-2.5 rounded-lg text-sm font-semibold bg-ok-primary text-white hover:bg-ok-primary-dark focus-visible:outline-2 transition-colors'
        : 'inline-flex items-center justify-center w-full sm:w-auto px-6 py-2.5 rounded-lg text-sm font-medium text-text-secondary border border-ok-border hover:bg-ok-hover-bg focus-visible:outline-2 transition-colors';
    if (action.href !== undefined && action.href !== '') {
        return (<Link href={action.href} className={baseClass}>
        {action.label}
      </Link>);
    }
    return (<button type="button" onClick={action.onClick} className={baseClass}>
      {action.label}
    </button>);
}
// ── Component ─────────────────────────────────────────────────────────────────
/**
 * Full-page standalone error layout.
 * Renders WITHOUT AdminShell — safe for 403, 404, 500, global-error.
 */
export function ErrorPage({ code, title, message, variant = 'neutral', icon, primaryAction, secondaryAction, correlationId, embedded = false, children, }: ErrorPageProps) {
    const resolvedIcon = icon ?? (code !== undefined && code !== '' ? DEFAULT_ICONS[String(code)] : null) ?? DEFAULT_ICONS.error;
    return (<div role={embedded ? "alert" : undefined} className={embedded ? "relative min-h-[60vh] flex flex-col items-center justify-center px-6 py-12" : "relative min-h-screen bg-bg-primary flex flex-col items-center justify-center px-6 py-20"}>

      {/* Logo mark */}
      <div className={embedded ? "hidden" : "flex items-center gap-2.5 mb-12"}>
        <div className="w-8 h-8 rounded-lg bg-ok-primary flex items-center justify-center flex-shrink-0" aria-hidden="true">
          <span className="text-[10px] font-black text-white select-none tracking-tight">OK</span>
        </div>
        <span className="text-sm font-semibold text-text-primary hidden sm:block">OleKard</span>
      </div>

      {/* Error card */}
      <div className="w-full max-w-[460px] text-center">

        {/* Icon circle */}
        <div className={`mx-auto mb-6 w-[72px] h-[72px] rounded-full ${ICON_BG[variant]} ${ICON_FG[variant]} flex items-center justify-center`} aria-hidden="true">
          {resolvedIcon}
        </div>

        {/* Status code */}
        {code !== undefined && code !== '' && (<p className={`text-[13px] font-bold uppercase tracking-[0.12em] mb-2 ${CODE_COLOR[variant]}`}>
            Error {code}
          </p>)}

        {/* Title */}
        <h1 className="text-[26px] font-bold text-text-primary leading-tight tracking-tight mb-3">
          {title}
        </h1>

        {/* Message */}
        <p className="text-sm text-text-secondary leading-relaxed mb-8 max-w-[360px] mx-auto">
          {message}
        </p>

        {children}

        {/* Actions */}
        {(primaryAction || secondaryAction) && (<div className="flex flex-col sm:flex-row items-center justify-center gap-3">
            {primaryAction && <ActionButton action={primaryAction} variant="primary"/>}
            {secondaryAction && <ActionButton action={secondaryAction} variant="secondary"/>}
          </div>)}

        {/* Correlation ID hint */}
        {correlationId !== undefined && correlationId !== '' && (<p className="mt-8 text-xs text-text-secondary">
            Reference:{' '}
            <code className="font-mono bg-neutral-100 px-1.5 py-0.5 rounded text-[11px]">
              {correlationId}
            </code>
          </p>)}
      </div>

      {/* Footer */}
      <p className="mt-12 text-xs text-text-secondary text-center px-4">
        Authorized personnel only. All sessions are logged and monitored.
      </p>
    </div>);
}
