import Link from 'next/link';

/**
 * 404 within the (admin) route group — rendered inside AdminShell so
 * the sidebar and header remain visible.
 */
export default function AdminNotFound() {
  return (
    <div className="flex flex-col items-center justify-center min-h-[calc(100vh-var(--header-height))] px-6 py-12 text-center">
      <div className="w-full max-w-[400px]">

        {/* Icon */}
        <div
          className="mx-auto mb-6 w-[64px] h-[64px] rounded-full bg-ok-hover-bg flex items-center justify-center"
          aria-hidden="true"
        >
          <svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.8" strokeLinecap="round" strokeLinejoin="round" className="text-text-secondary">
            <path d="M14.5 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7.5L14.5 2z"/>
            <polyline points="14 2 14 8 20 8"/>
            <path d="M9.1 15a3 3 0 0 1 5.82 1c0 2-3 3-3 3"/>
            <line x1="12" y1="22" x2="12.01" y2="22"/>
          </svg>
        </div>

        <p className="text-[12px] font-bold uppercase tracking-[0.12em] text-text-secondary mb-2">
          Error 404
        </p>
        <h1 className="text-xl font-bold text-text-primary mb-2">Page not found</h1>
        <p className="text-sm text-text-secondary leading-relaxed mb-8 max-w-xs mx-auto">
          The page you&apos;re looking for doesn&apos;t exist or may have been moved.
        </p>

        <Link
          href="/dashboard"
          className="inline-flex items-center justify-center px-6 py-2.5 rounded-lg text-sm font-semibold bg-ok-primary text-white hover:bg-ok-primary-dark focus-visible:outline-2 transition-colors"
        >
          Go to Dashboard
        </Link>
      </div>
    </div>
  );
}
