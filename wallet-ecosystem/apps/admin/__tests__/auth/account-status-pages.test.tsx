/**
 * ADM-P1-001 US-P1-001-003 / US-P1-001-004
 * AccountLockedPage and AccountDisabledPage render correctly.
 */
import React from 'react';
import { render, screen } from '@testing-library/react';

import AccountLockedPage   from '@/app/account-locked/page';
import AccountDisabledPage from '@/app/account-disabled/page';

describe('AccountLockedPage (US-P1-001-003)', () => {
  it('renders heading', () => {
    render(<AccountLockedPage />);
    expect(screen.getByRole('heading', { name: /account locked/i })).toBeInTheDocument();
  });

  it('renders explanation message', () => {
    render(<AccountLockedPage />);
    expect(screen.getByText(/contact your administrator/i)).toBeInTheDocument();
  });

  it('renders back to sign in link', () => {
    render(<AccountLockedPage />);
    const link = screen.getByRole('link', { name: /back to sign in/i });
    expect(link).toBeInTheDocument();
    expect(link).toHaveAttribute('href', '/login');
  });
});

describe('AccountDisabledPage (US-P1-001-004)', () => {
  it('renders heading', () => {
    render(<AccountDisabledPage />);
    expect(screen.getByRole('heading', { name: /account disabled/i })).toBeInTheDocument();
  });

  it('renders explanation message', () => {
    render(<AccountDisabledPage />);
    expect(screen.getByText(/contact your system administrator/i)).toBeInTheDocument();
  });

  it('renders back to sign in link', () => {
    render(<AccountDisabledPage />);
    const link = screen.getByRole('link', { name: /back to sign in/i });
    expect(link).toBeInTheDocument();
    expect(link).toHaveAttribute('href', '/login');
  });
});
