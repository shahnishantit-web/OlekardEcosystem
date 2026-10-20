import Link from 'next/link';

export default function NotFound() {
  return (
    <main>
      <h1>404 — Page not found</h1>
      <p>The page you requested does not exist.</p>
      <Link href="/dashboard">Return to Dashboard</Link>
    </main>
  );
}
