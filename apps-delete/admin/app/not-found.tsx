import Link from 'next/link';

export default function NotFound() {
  return (
    <div className="flex min-h-screen items-center justify-center p-8">
      <div className="max-w-md text-center">
        <h1 className="text-6xl font-bold text-neutral-200 mb-4">404</h1>
        <h2 className="text-2xl font-semibold text-text-primary mb-4">
          Page not found
        </h2>
        <p className="text-text-secondary mb-8">
          The page you are looking for does not exist or has been moved.
        </p>
        <Link
          href="/dashboard"
          className="px-4 py-2 bg-brand-primary text-white rounded-md hover:opacity-90 focus-visible:outline-2"
          style={{ minHeight: '44px', display: 'inline-block', lineHeight: '44px', padding: '0 16px' }}
        >
          Go to Dashboard
        </Link>
      </div>
    </div>
  );
}
