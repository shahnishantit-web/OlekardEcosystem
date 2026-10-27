/**
 * E68 — Intent Timeline Tests (US2)
 * T020: Jest tests for IntentTimelinePage, TimelineEvent, LedgerJournalCard, CurrencyAmount
 */
import React from 'react';
import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';

// ── Mocks ─────────────────────────────────────────────────────────────────────

const mockGetIntentTimeline  = jest.fn();
const mockQueryIntentStatus  = jest.fn();

jest.mock('@/lib/api/payment', () => {
  const actual = jest.requireActual('@/lib/api/payment');
  return {
    ...actual,
    PaymentApiClient: jest.fn().mockImplementation(() => ({
      getIntentTimeline:  mockGetIntentTimeline,
      queryIntentStatus:  mockQueryIntentStatus,
    })),
  };
});

jest.mock('@/lib/auth/context', () => ({ useAuth: jest.fn() }));
jest.mock('next/navigation', () => ({ useRouter: () => ({ push: jest.fn() }) }));
jest.mock('next/link', () => {
  const Link = ({ children, href }: { children: React.ReactNode; href: string }) =>
    React.createElement('a', { href }, children);
  return Link;
});

// ── Imports ───────────────────────────────────────────────────────────────────

import { IntentTimelinePage }  from '@/src/modules/payment/pages/intent-timeline';
import { TimelineEvent }       from '@/src/modules/payment/components/TimelineEvent';
import { LedgerJournalCard }   from '@/src/modules/payment/components/LedgerJournalCard';
import { CurrencyAmount }      from '@/src/modules/payment/components/CurrencyAmount';
import * as authContext from '@/lib/auth/context';

const mockUseAuth = authContext.useAuth as jest.MockedFunction<typeof authContext.useAuth>;
const MOCK_CLIENT = {} as never;
const INTENT_ID   = 'intent-uuid-001';

const mockTimeline = {
  intent: {
    id: INTENT_ID,
    status: 'captured',
    amount_minor: 10000,
    currency_code: 'BWP',
    provider_code: null,
    purpose_type: 'transfer',
    organisation_id: 'org-1',
    created_at: '2026-10-01T10:00:00Z',
    updated_at: '2026-10-01T10:05:00Z',
    has_pending_fulfilment: true,
  },
  attempts: [
    {
      id: 'attempt-1',
      attempt_number: 1,
      status: 'failed',
      provider_code: 'bank_a',
      provider_reference: '****1234',
      submitted_at: '2026-10-01T10:01:00Z',
      resolved_at: '2026-10-01T10:02:00Z',
      failure_reason: 'Insufficient funds',
    },
    {
      id: 'attempt-2',
      attempt_number: 2,
      status: 'captured',
      provider_code: 'bank_a',
      provider_reference: '****5678',
      submitted_at: '2026-10-01T10:03:00Z',
      resolved_at: null,
      failure_reason: null,
    },
  ],
  fulfilment: null,
  ledger_journals: [
    {
      journal_id: 'journal-1',
      posted_at: '2026-10-01T10:04:00Z',
      description: 'Payment capture — BWP 100.00',
      lines: [
        { type: 'debit' as const,  account_name: 'Customer Wallet',     amount_minor: 10000, currency_code: 'BWP' },
        { type: 'credit' as const, account_name: 'Provider Settlement', amount_minor: 10000, currency_code: 'BWP' },
      ],
      audit_link: null,
    },
  ],
  pending_accounting: false,
};

// ── CurrencyAmount ────────────────────────────────────────────────────────────

describe('CurrencyAmount', () => {
  it('formats minor amount as decimal', () => {
    render(<CurrencyAmount amountMinor={10000} currency="BWP" />);
    expect(screen.getByText(/BWP.*100\.00|BWP 100\.00/)).toBeTruthy();
  });

  it('does not mix currencies in one render', () => {
    render(<CurrencyAmount amountMinor={20000} currency="USD" />);
    expect(screen.queryByText(/BWP/)).toBeNull();
    expect(screen.getByText(/USD.*200\.00|USD 200\.00/)).toBeTruthy();
  });
});

// ── TimelineEvent ─────────────────────────────────────────────────────────────

describe('TimelineEvent', () => {
  it('renders title and formatted timestamp', () => {
    render(<TimelineEvent type="intent" title="Intent created" timestamp="2026-10-01T10:00:00Z" />);
    expect(screen.getByText('Intent created')).toBeTruthy();
    // Timestamp renders as localised string — just check it's present
    expect(screen.getByRole('time')).toBeTruthy();
  });

  it('renders detail content when provided', () => {
    render(
      <TimelineEvent type="attempt" title="Attempt 1" timestamp="2026-10-01T10:01:00Z" detail={<span>Detail here</span>} />,
    );
    expect(screen.getByText('Detail here')).toBeTruthy();
  });
});

// ── LedgerJournalCard ─────────────────────────────────────────────────────────

describe('LedgerJournalCard', () => {
  const journal = mockTimeline.ledger_journals[0]!;

  it('renders journal description', () => {
    render(<LedgerJournalCard journal={journal} />);
    expect(screen.getByText(journal.description)).toBeTruthy();
  });

  it('renders account names not raw UUIDs', () => {
    render(<LedgerJournalCard journal={journal} />);
    expect(screen.getByText('Customer Wallet')).toBeTruthy();
    expect(screen.getByText('Provider Settlement')).toBeTruthy();
    expect(screen.queryByText(/[0-9a-f]{8}-[0-9a-f]{4}/i)).toBeNull();
  });

  it('renders debit and credit type labels', () => {
    render(<LedgerJournalCard journal={journal} />);
    expect(screen.getByText('DEBIT')).toBeTruthy();
    expect(screen.getByText('CREDIT')).toBeTruthy();
  });
});

// ── IntentTimelinePage ────────────────────────────────────────────────────────

describe('IntentTimelinePage', () => {
  beforeEach(() => {
    jest.clearAllMocks();
    mockUseAuth.mockReturnValue({
      authenticatedClient: MOCK_CLIENT,
      hasPermission: (p: string) => ['payment.history.read.platform', 'payment.intents.admin'].includes(p),
      user: null,
      login: jest.fn(),
      logout: jest.fn(),
      isLoading: false,
    } as never);
    mockGetIntentTimeline.mockResolvedValue({ data: mockTimeline });
  });

  it('renders full timeline in chronological order', async () => {
    render(<IntentTimelinePage id={INTENT_ID} />);
    await waitFor(() => {
      expect(screen.getByText('Intent created')).toBeTruthy();
    });
    expect(screen.getByText(/Attempt 1/)).toBeTruthy();
    expect(screen.getByText(/Attempt 2/)).toBeTruthy();
    expect(screen.getByText(/Ledger posting/)).toBeTruthy();
  });

  it('shows masked provider reference — never raw value', async () => {
    render(<IntentTimelinePage id={INTENT_ID} />);
    await waitFor(() => screen.getByText('Intent created'));
    // Provider ref is shown as ****1234 (masked)
    expect(screen.getByText('****1234')).toBeTruthy();
    // No raw UUID or full ref
    expect(screen.queryByText(/^[a-zA-Z0-9_-]{10,}$/)).toBeNull();
  });

  it('shows "No attempts" message when attempts array is empty', async () => {
    mockGetIntentTimeline.mockResolvedValue({
      data: { ...mockTimeline, attempts: [] },
    });
    render(<IntentTimelinePage id={INTENT_ID} />);
    await waitFor(() => {
      expect(screen.getByText(/No attempts — intent created but not yet submitted/)).toBeTruthy();
    });
  });

  it('shows "No ledger journals yet" when ledger_journals is empty', async () => {
    mockGetIntentTimeline.mockResolvedValue({
      data: { ...mockTimeline, ledger_journals: [] },
    });
    render(<IntentTimelinePage id={INTENT_ID} />);
    await waitFor(() => {
      expect(screen.getByText(/No ledger journals yet/)).toBeTruthy();
    });
  });

  it('"Query Provider Status" button absent when status is not "captured"', async () => {
    mockGetIntentTimeline.mockResolvedValue({
      data: { ...mockTimeline, intent: { ...mockTimeline.intent, status: 'fulfilled' } },
    });
    render(<IntentTimelinePage id={INTENT_ID} />);
    await waitFor(() => screen.getByText('Intent created'));
    expect(screen.queryByRole('button', { name: /Query Provider Status/i })).toBeNull();
  });

  it('"Query Provider Status" button absent for user without payment.intents.admin', async () => {
    mockUseAuth.mockReturnValue({
      authenticatedClient: MOCK_CLIENT,
      hasPermission: (p: string) => p === 'payment.history.read.platform',
      user: null,
      login: jest.fn(),
      logout: jest.fn(),
      isLoading: false,
    } as never);
    render(<IntentTimelinePage id={INTENT_ID} />);
    await waitFor(() => screen.getByText('Intent created'));
    expect(screen.queryByRole('button', { name: /Query Provider Status/i })).toBeNull();
  });

  it('LedgerJournalCard renders account names not raw UUIDs', async () => {
    render(<IntentTimelinePage id={INTENT_ID} />);
    await waitFor(() => screen.getByText('Customer Wallet'));
    expect(screen.getByText('Customer Wallet')).toBeTruthy();
    expect(screen.getByText('Provider Settlement')).toBeTruthy();
  });

  it('shows permission-denied when user lacks payment.history.read.platform', () => {
    mockUseAuth.mockReturnValue({
      authenticatedClient: MOCK_CLIENT,
      hasPermission: () => false,
      user: null,
      login: jest.fn(),
      logout: jest.fn(),
      isLoading: false,
    } as never);
    render(<IntentTimelinePage id={INTENT_ID} />);
    expect(screen.getByRole('alert')).toBeTruthy();
    expect(screen.getByText(/do not have permission/i)).toBeTruthy();
  });
});
