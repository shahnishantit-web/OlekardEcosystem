/**
 * US-FOUND-003 — Admin sees error boundaries without losing shell context.
 *
 * AC: Given a page-level React error occurs inside (admin) group,
 *     When the error boundary triggers,
 *     Then the AdminShell (sidebar + header) remains visible with the in-shell error page,
 *     And a "Try again" reset action is available.
 *
 * Tests the AdminError component (app/(admin)/error.tsx) and
 * the ErrorPage component (components/ui/ErrorPage.tsx).
 */
import React from 'react';
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';

jest.mock('next/link', () => {
  const MockLink = ({
    children,
    href,
    ...props
  }: {
    children: React.ReactNode;
    href: string;
    [key: string]: unknown;
  }) => (
    <a href={href} {...props}>
      {children}
    </a>
  );
  MockLink.displayName = 'MockLink';
  return MockLink;
});

import AdminError from '@/app/(admin)/error';
import { ErrorPage } from '@/components/ui/ErrorPage';

// ── AdminError (in-shell error boundary) ────────────────────────────────────

describe('AdminError — in-shell error boundary (US-FOUND-003)', () => {
  const makeError = (message: string, digest?: string): Error & { digest?: string } => {
    const err = new Error(message) as Error & { digest?: string };
    if (digest !== undefined) err.digest = digest;
    return err;
  };

  it('has role=alert for screen readers', () => {
    render(<AdminError error={makeError('boom')} reset={jest.fn()} />);
    expect(screen.getByRole('alert')).toBeInTheDocument();
  });

  it('renders "Something went wrong" heading', () => {
    render(<AdminError error={makeError('boom')} reset={jest.fn()} />);
    expect(screen.getByRole('heading', { name: /something went wrong/i })).toBeInTheDocument();
  });

  it('"Try again" button calls reset on click (AC: reset action available)', async () => {
    const reset = jest.fn();
    render(<AdminError error={makeError('boom')} reset={reset} />);
    await userEvent.click(screen.getByRole('button', { name: /try again/i }));
    expect(reset).toHaveBeenCalledTimes(1);
  });

  it('"Go to Dashboard" link points to /dashboard', () => {
    render(<AdminError error={makeError('boom')} reset={jest.fn()} />);
    const link = screen.getByRole('link', { name: /go to dashboard/i });
    expect(link).toHaveAttribute('href', '/dashboard');
  });

  it('renders the specific error message when it is meaningful', () => {
    render(
      <AdminError
        error={makeError('Wallet record not found')}
        reset={jest.fn()}
      />,
    );
    expect(screen.getByText('Wallet record not found')).toBeInTheDocument();
  });

  it('renders the fallback message for generic Next.js server-component errors', () => {
    render(
      <AdminError
        error={makeError('An error occurred in the Server Components render.')}
        reset={jest.fn()}
      />,
    );
    expect(
      screen.getByText(/an unexpected error occurred while loading this page/i),
    ).toBeInTheDocument();
  });

  it('shows reference code when error.digest is present', () => {
    render(
      <AdminError
        error={makeError('boom', 'abc-123-digest')}
        reset={jest.fn()}
      />,
    );
    expect(screen.getByText('abc-123-digest')).toBeInTheDocument();
    expect(screen.getByText(/reference/i)).toBeInTheDocument();
  });

  it('does not render reference section when error.digest is absent', () => {
    render(<AdminError error={makeError('boom')} reset={jest.fn()} />);
    expect(screen.queryByText(/reference/i)).not.toBeInTheDocument();
  });
});

// ── ErrorPage (standalone full-page error layout) ───────────────────────────

describe('ErrorPage component', () => {
  it('renders title and message', () => {
    render(<ErrorPage title="Page not found" message="The page you requested does not exist." />);
    expect(screen.getByRole('heading', { name: /page not found/i })).toBeInTheDocument();
    expect(screen.getByText(/the page you requested does not exist/i)).toBeInTheDocument();
  });

  it('renders the error code', () => {
    render(<ErrorPage code="404" title="Not Found" message="..." />);
    expect(screen.getByText(/error 404/i)).toBeInTheDocument();
  });

  it('primary action with onClick renders as a button and calls onClick', async () => {
    const onClick = jest.fn();
    render(
      <ErrorPage
        title="Error"
        message="msg"
        primaryAction={{ label: 'Try again', onClick }}
      />,
    );
    const btn = screen.getByRole('button', { name: /try again/i });
    await userEvent.click(btn);
    expect(onClick).toHaveBeenCalledTimes(1);
  });

  it('secondary action with href renders as a link', () => {
    render(
      <ErrorPage
        title="Error"
        message="msg"
        secondaryAction={{ label: 'Return to login', href: '/login' }}
      />,
    );
    const link = screen.getByRole('link', { name: /return to login/i });
    expect(link).toHaveAttribute('href', '/login');
  });

  it('renders correlationId when provided', () => {
    render(
      <ErrorPage
        title="Error"
        message="msg"
        correlationId="corr-abc-789"
      />,
    );
    expect(screen.getByText('corr-abc-789')).toBeInTheDocument();
  });

  it('does not render correlation block when correlationId is absent', () => {
    render(<ErrorPage title="Error" message="msg" />);
    expect(screen.queryByText(/reference/i)).not.toBeInTheDocument();
  });

  it('renders without actions when none provided', () => {
    render(<ErrorPage title="Error" message="msg" />);
    expect(screen.queryByRole('button')).not.toBeInTheDocument();
    expect(screen.queryByRole('link')).not.toBeInTheDocument();
  });
});
