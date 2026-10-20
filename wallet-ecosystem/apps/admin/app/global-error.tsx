'use client';

/**
 * global-error.tsx runs when the root layout itself throws — it must provide
 * its own <html> and <body> tags and cannot rely on any provider context.
 * Styles are inlined so this page renders correctly even if the CSS bundle failed.
 */
export default function GlobalError({
  error,
  reset,
}: {
  error: Error & { digest?: string };
  reset: () => void;
}) {
  return (
    <html lang="en" data-theme="admin">
      <head>
        <title>Application Error — OleKard Admin</title>
        <meta name="viewport" content="width=device-width, initial-scale=1" />
      </head>
      <body style={{ margin: 0, fontFamily: 'Inter, system-ui, sans-serif', background: '#f7f8fb', color: '#202636' }}>
        <div style={{ minHeight: '100vh', display: 'flex', flexDirection: 'column', alignItems: 'center', justifyContent: 'center', padding: '48px 24px', textAlign: 'center' }} role="alert">

          {/* Logo mark */}
          <div style={{ width: 40, height: 40, borderRadius: 10, background: '#596fb6', display: 'flex', alignItems: 'center', justifyContent: 'center', marginBottom: 32 }} aria-hidden="true">
            <span style={{ color: '#fff', fontWeight: 900, fontSize: 11, letterSpacing: '-0.5px' }}>OK</span>
          </div>

          {/* Icon */}
          <div style={{ width: 72, height: 72, borderRadius: '50%', background: '#fff0f0', display: 'flex', alignItems: 'center', justifyContent: 'center', marginBottom: 24 }} aria-hidden="true">
            <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="#c94d52" strokeWidth="1.8" strokeLinecap="round" strokeLinejoin="round">
              <path d="M10.29 3.86 1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/>
              <line x1="12" y1="9" x2="12" y2="13"/>
              <line x1="12" y1="17" x2="12.01" y2="17"/>
            </svg>
          </div>

          <p style={{ fontSize: 12, fontWeight: 700, textTransform: 'uppercase', letterSpacing: '0.12em', color: '#c94d52', marginBottom: 8 }}>
            Application Error
          </p>
          <h1 style={{ fontSize: 26, fontWeight: 700, margin: '0 0 12px', lineHeight: 1.3 }}>
            Something went wrong
          </h1>
          <p style={{ fontSize: 14, color: '#788194', lineHeight: 1.6, maxWidth: 360, margin: '0 0 32px' }}>
            An unexpected error occurred and the application could not recover. Refreshing the page usually resolves this.
          </p>

          {/* Actions */}
          <div style={{ display: 'flex', gap: 12, flexWrap: 'wrap', justifyContent: 'center' }}>
            <button
              type="button"
              onClick={reset}
              style={{ padding: '10px 24px', borderRadius: 8, background: '#596fb6', color: '#fff', fontSize: 14, fontWeight: 600, border: 'none', cursor: 'pointer' }}
            >
              Try again
            </button>
            <a
              href="/login"
              style={{ padding: '10px 24px', borderRadius: 8, background: 'transparent', color: '#788194', fontSize: 14, fontWeight: 500, border: '1px solid #e8ebf1', textDecoration: 'none', display: 'inline-flex', alignItems: 'center' }}
            >
              Return to login
            </a>
          </div>

          {error.digest && (
            <p style={{ marginTop: 32, fontSize: 12, color: '#98a1af' }}>
              Reference:{' '}
              <code style={{ fontFamily: 'monospace', background: '#f7f8fb', padding: '2px 6px', borderRadius: 4 }}>
                {error.digest}
              </code>
            </p>
          )}

          <p style={{ position: 'fixed', bottom: 24, fontSize: 11, color: '#98a1af' }}>
            Authorized personnel only. All sessions are logged and monitored.
          </p>
        </div>
      </body>
    </html>
  );
}
