/**
 * E48 — Payment Provider Admin UI Tests
 * T077: Jest tests for payment provider pages and shared components
 */
import React from 'react';
import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';

jest.mock('next/navigation', () => ({
  useRouter: () => ({ push: mockRouterPush }),
}));

// ── API client mock ───────────────────────────────────────────────────────────

const mockListProviders         = jest.fn();
const mockGetProvider           = jest.fn();
const mockCreateProvider        = jest.fn();
const mockUpdateProvider        = jest.fn();
const mockSuspendProvider       = jest.fn();
const mockRotateCredential      = jest.fn();
const mockListReconExceptions   = jest.fn();
const mockUpdateExceptionStatus = jest.fn();

jest.mock('@/lib/api/payment', () => {
  const actual = jest.requireActual('@/lib/api/payment');
  return {
    ...actual,
    PaymentApiClient: jest.fn().mockImplementation(() => ({
      listProviders:          mockListProviders,
      getProvider:            mockGetProvider,
      createProvider:         mockCreateProvider,
      updateProvider:         mockUpdateProvider,
      suspendProvider:        mockSuspendProvider,
      rotateCredential:       mockRotateCredential,
      listReconciliationExceptions: mockListReconExceptions,
      updateExceptionStatus:  mockUpdateExceptionStatus,
    })),
  };
});

jest.mock('@/lib/auth/context', () => ({ useAuth: jest.fn() }));

// ── Imports ───────────────────────────────────────────────────────────────────

import { PaymentProvidersPage }       from '@/src/modules/payment/pages/payment-providers';
import { PaymentProviderDetailPage }  from '@/src/modules/payment/pages/payment-provider-detail';
import { PaymentProviderCreatePage }  from '@/src/modules/payment/pages/payment-provider-create';
import { ReconciliationExceptionsPage } from '@/src/modules/payment/pages/reconciliation-exceptions';
import { ProviderStatusBadge }        from '@/src/modules/payment/components/ProviderStatusBadge';
import { MockBannerAlert }            from '@/src/modules/payment/components/MockBannerAlert';
import { CapabilityFlagsEditor }      from '@/src/modules/payment/components/CapabilityFlagsEditor';
import { CredentialRotationForm }     from '@/src/modules/payment/components/CredentialRotationForm';
import * as authContext from '@/lib/auth/context';

const mockUseAuth = authContext.useAuth as jest.MockedFunction<typeof authContext.useAuth>;
const MOCK_CLIENT = {} as never;
const mockRouterPush = jest.fn();

// ── Fixtures ──────────────────────────────────────────────────────────────────

const ACTIVE_PROVIDER = {
  id: 'prov-001',
  code: 'bank_abc',
  display_name: 'ABC Bank',
  adapter_identifier: 'mock_bank',
  adapter_version: '1.0.0',
  environment: 'sandbox' as const,
  is_enabled: true,
  is_suspended: false,
  is_default: true,
  sort_order: 1,
  created_at: '2026-01-01T00:00:00Z',
  updated_at: null,
};

const MOCK_PROVIDER = {
  ...ACTIVE_PROVIDER,
  id: 'prov-002',
  code: 'mock_bank',
  display_name: 'Mock Bank',
  environment: 'mock' as const,
  is_enabled: false,
  is_default: false,
};

const SUSPENDED_PROVIDER = {
  ...ACTIVE_PROVIDER,
  id: 'prov-003',
  code: 'bank_xyz',
  is_suspended: true,
};

const OPEN_EXCEPTION = {
  id: 'ex-001',
  type: 'missing_callback' as const,
  status: 'open' as const,
  provider_code: 'bank_abc',
  amount_minor: 100000,
  notes: null,
  created_at: '2026-01-01T00:00:00Z',
  updated_at: null,
};

// ── Setup ─────────────────────────────────────────────────────────────────────

beforeEach(() => {
  jest.clearAllMocks();
  mockUseAuth.mockReturnValue({
    authenticatedClient: MOCK_CLIENT,
    hasPermission: () => true,
    user: null,
  } as never);
});

// ── ProviderStatusBadge ───────────────────────────────────────────────────────

describe('ProviderStatusBadge', () => {
  it('shows ACTIVE for enabled non-suspended production provider', () => {
    render(
      <ProviderStatusBadge environment="production" is_enabled={true} is_suspended={false} />
    );
    expect(screen.getByText('ACTIVE')).toBeInTheDocument();
  });

  it('shows SUSPENDED for suspended provider', () => {
    render(
      <ProviderStatusBadge environment="sandbox" is_enabled={true} is_suspended={true} />
    );
    expect(screen.getByText('SUSPENDED')).toBeInTheDocument();
  });

  it('shows SANDBOX for sandbox environment', () => {
    render(
      <ProviderStatusBadge environment="sandbox" is_enabled={true} is_suspended={false} />
    );
    expect(screen.getByText('SANDBOX')).toBeInTheDocument();
  });

  it('shows MOCK/DEV for mock environment', () => {
    render(
      <ProviderStatusBadge environment="mock" is_enabled={false} is_suspended={false} />
    );
    expect(screen.getByText('MOCK/DEV')).toBeInTheDocument();
  });

  it('shows DISABLED for disabled non-suspended provider', () => {
    render(
      <ProviderStatusBadge environment="production" is_enabled={false} is_suspended={false} />
    );
    expect(screen.getByText('DISABLED')).toBeInTheDocument();
  });
});

// ── MockBannerAlert ───────────────────────────────────────────────────────────

describe('MockBannerAlert', () => {
  it('renders the non-dismissible DEVELOPMENT MOCK banner', () => {
    render(<MockBannerAlert />);
    expect(screen.getByRole('alert')).toBeInTheDocument();
    expect(screen.getByText(/DEVELOPMENT MOCK/)).toBeInTheDocument();
    expect(screen.getByText(/NOT FOR PRODUCTION/)).toBeInTheDocument();
  });

  it('shows provider code when provided', () => {
    render(<MockBannerAlert providerCode="mock_bank" />);
    expect(screen.getByText(/mock_bank/)).toBeInTheDocument();
  });

  it('has no dismiss/close button', () => {
    render(<MockBannerAlert />);
    expect(screen.queryByRole('button')).not.toBeInTheDocument();
  });
});

// ── CapabilityFlagsEditor ─────────────────────────────────────────────────────

describe('CapabilityFlagsEditor', () => {
  it('renders all 8 capability checkboxes', () => {
    render(<CapabilityFlagsEditor value={[]} onChange={jest.fn()} />);
    expect(screen.getAllByRole('checkbox')).toHaveLength(8);
  });

  it('checks boxes that are in the value array', () => {
    render(
      <CapabilityFlagsEditor
        value={['initiate_payment', 'full_refund']}
        onChange={jest.fn()}
      />
    );
    const initiateCheckbox = screen.getByLabelText('Initiate Payment');
    expect(initiateCheckbox).toBeChecked();
    const queryCheckbox = screen.getByLabelText('Query Payment Status');
    expect(queryCheckbox).not.toBeChecked();
  });

  it('calls onChange when a checkbox is toggled', async () => {
    const onChange = jest.fn();
    render(<CapabilityFlagsEditor value={[]} onChange={onChange} />);
    await userEvent.click(screen.getByLabelText('Initiate Payment'));
    expect(onChange).toHaveBeenCalledWith(['initiate_payment']);
  });

  it('disables all checkboxes when disabled=true', () => {
    render(
      <CapabilityFlagsEditor value={['initiate_payment']} onChange={jest.fn()} disabled={true} />
    );
    screen.getAllByRole('checkbox').forEach((cb) => {
      expect(cb).toBeDisabled();
    });
  });

  it('shows warning text when disabled', () => {
    render(<CapabilityFlagsEditor value={[]} onChange={jest.fn()} disabled={true} />);
    expect(screen.getByText(/read-only while the account is enabled/i)).toBeInTheDocument();
  });
});

// ── CredentialRotationForm ────────────────────────────────────────────────────

describe('CredentialRotationForm', () => {
  it('renders input for secret reference path (not value)', () => {
    render(
      <CredentialRotationForm
        providerId="prov-001"
        accountId="acc-001"
        onRotate={jest.fn()}
      />
    );
    expect(screen.getByLabelText(/New Secret Reference/i)).toBeInTheDocument();
  });

  it('calls onRotate with the entered reference when submitted', async () => {
    const onRotate = jest.fn().mockResolvedValue(undefined);
    render(
      <CredentialRotationForm
        providerId="prov-001"
        accountId="acc-001"
        onRotate={onRotate}
      />
    );
    await userEvent.type(
      screen.getByLabelText(/New Secret Reference/i),
      'vault/payment/providers/secret-v2'
    );
    await userEvent.click(screen.getByRole('button', { name: /Rotate/i }));
    expect(onRotate).toHaveBeenCalledWith('vault/payment/providers/secret-v2');
  });

  it('shows success message after rotation', async () => {
    const onRotate = jest.fn().mockResolvedValue(undefined);
    render(
      <CredentialRotationForm
        providerId="prov-001"
        accountId="acc-001"
        onRotate={onRotate}
      />
    );
    await userEvent.type(
      screen.getByLabelText(/New Secret Reference/i),
      'vault/payment/callback-secret-v2'
    );
    await userEvent.click(screen.getByRole('button', { name: /Rotate/i }));
    await waitFor(() => {
      expect(screen.getByRole('status')).toHaveTextContent(/rotated successfully/i);
    });
  });

  it('rejects references shorter than 8 chars', async () => {
    const onRotate = jest.fn();
    render(
      <CredentialRotationForm
        providerId="prov-001"
        accountId="acc-001"
        onRotate={onRotate}
      />
    );
    await userEvent.type(screen.getByLabelText(/New Secret Reference/i), 'short');
    await userEvent.click(screen.getByRole('button', { name: /Rotate/i }));
    expect(onRotate).not.toHaveBeenCalled();
    expect(screen.getByRole('alert')).toBeInTheDocument();
  });
});

// ── PaymentProvidersPage ──────────────────────────────────────────────────────

describe('PaymentProvidersPage', () => {
  it('renders providers list from API', async () => {
    mockListProviders.mockResolvedValue({ data: { data: [ACTIVE_PROVIDER, MOCK_PROVIDER] } });
    render(<PaymentProvidersPage />);
    await waitFor(() => {
      expect(screen.getByText('ABC Bank')).toBeInTheDocument();
      expect(screen.getByText('Mock Bank')).toBeInTheDocument();
    });
  });

  it('shows MOCK/DEV banner when mock providers exist', async () => {
    mockListProviders.mockResolvedValue({ data: { data: [MOCK_PROVIDER] } });
    render(<PaymentProvidersPage />);
    await waitFor(() => {
      expect(screen.getByRole('alert')).toBeInTheDocument();
      expect(screen.getByText(/DEVELOPMENT MOCK/)).toBeInTheDocument();
    });
  });

  it('does NOT show mock banner when no mock providers', async () => {
    mockListProviders.mockResolvedValue({ data: { data: [ACTIVE_PROVIDER] } });
    render(<PaymentProvidersPage />);
    await waitFor(() => {
      expect(screen.queryByRole('alert')).not.toBeInTheDocument();
    });
  });

  it('shows empty state when no providers', async () => {
    mockListProviders.mockResolvedValue({ data: { data: [] } });
    render(<PaymentProvidersPage />);
    await waitFor(() => {
      expect(screen.getByText(/No payment providers configured/i)).toBeInTheDocument();
    });
  });

  it('shows error state on API failure', async () => {
    mockListProviders.mockRejectedValue(new Error('Network error'));
    render(<PaymentProvidersPage />);
    await waitFor(() => {
      expect(screen.getByText(/Failed to load payment providers/i)).toBeInTheDocument();
    });
  });

  it('hides Add Provider button when user lacks payment.providers.manage', async () => {
    mockUseAuth.mockReturnValue({
      authenticatedClient: MOCK_CLIENT,
      hasPermission: (perm: string) => perm === 'payment.providers.read',
      user: null,
    } as never);
    mockListProviders.mockResolvedValue({ data: { data: [] } });
    render(<PaymentProvidersPage />);
    await waitFor(() => {
      expect(screen.queryByRole('button', { name: /Add Provider/i })).not.toBeInTheDocument();
    });
  });

  it('navigates to provider detail on row click', async () => {
    mockListProviders.mockResolvedValue({ data: { data: [ACTIVE_PROVIDER] } });
    render(<PaymentProvidersPage />);
    await waitFor(() => screen.getByText('ABC Bank'));
    await userEvent.click(screen.getByText('ABC Bank'));
    expect(mockRouterPush).toHaveBeenCalledWith('/payment/providers/prov-001');
  });
});

// ── PaymentProviderDetailPage ─────────────────────────────────────────────────

describe('PaymentProviderDetailPage', () => {
  it('renders provider details', async () => {
    mockGetProvider.mockResolvedValue({ data: { data: ACTIVE_PROVIDER } });
    render(<PaymentProviderDetailPage providerId="prov-001" />);
    await waitFor(() => {
      expect(screen.getByText('ABC Bank')).toBeInTheDocument();
      expect(screen.getAllByText('bank_abc')).toHaveLength(2);
    });
  });

  it('shows mock banner for mock providers', async () => {
    mockGetProvider.mockResolvedValue({ data: { data: MOCK_PROVIDER } });
    render(<PaymentProviderDetailPage providerId="prov-002" />);
    await waitFor(() => {
      expect(screen.getByRole('alert')).toBeInTheDocument();
    });
  });

  it('shows Suspend button when not suspended and user can manage', async () => {
    mockGetProvider.mockResolvedValue({ data: { data: ACTIVE_PROVIDER } });
    render(<PaymentProviderDetailPage providerId="prov-001" />);
    await waitFor(() => {
      expect(screen.getByRole('button', { name: /Suspend/i })).toBeInTheDocument();
    });
  });

  it('hides Suspend button when already suspended', async () => {
    mockGetProvider.mockResolvedValue({ data: { data: SUSPENDED_PROVIDER } });
    render(<PaymentProviderDetailPage providerId="prov-003" />);
    await waitFor(() => {
      expect(screen.queryByRole('button', { name: /Suspend/i })).not.toBeInTheDocument();
    });
  });
});

// ── ReconciliationExceptionsPage ──────────────────────────────────────────────

describe('ReconciliationExceptionsPage', () => {
  it('renders exceptions list', async () => {
    mockListReconExceptions.mockResolvedValue({ data: { data: [OPEN_EXCEPTION] } });
    render(<ReconciliationExceptionsPage />);
    await waitFor(() => {
      expect(screen.getByText(/missing callback/i)).toBeInTheDocument();
      expect(screen.getByText('bank_abc')).toBeInTheDocument();
    });
  });

  it('shows empty state when no exceptions', async () => {
    mockListReconExceptions.mockResolvedValue({ data: { data: [] } });
    render(<ReconciliationExceptionsPage />);
    await waitFor(() => {
      expect(screen.getByText(/No reconciliation exceptions/i)).toBeInTheDocument();
    });
  });

  it('has no mark-as-paid or direct financial state-change button', async () => {
    mockListReconExceptions.mockResolvedValue({ data: { data: [OPEN_EXCEPTION] } });
    render(<ReconciliationExceptionsPage />);
    await waitFor(() => screen.getByText(/missing callback/i));

    const buttons = screen.getAllByRole('button');
    const labels = buttons.map((b) => b.textContent?.toLowerCase() ?? '');
    expect(labels).not.toContain(expect.stringContaining('mark as paid'));
    expect(labels).not.toContain(expect.stringContaining('confirm payment'));
    expect(labels).not.toContain(expect.stringContaining('force complete'));
  });

  it('allows status update via edit flow', async () => {
    mockListReconExceptions.mockResolvedValue({ data: { data: [OPEN_EXCEPTION] } });
    mockUpdateExceptionStatus.mockResolvedValue({ data: { data: { ...OPEN_EXCEPTION, status: 'investigating' } } });
    render(<ReconciliationExceptionsPage />);
    await waitFor(() => screen.getByRole('button', { name: /Edit/i }));
    await userEvent.click(screen.getByRole('button', { name: /Edit/i }));
    await waitFor(() => {
      expect(screen.getByRole('button', { name: /Save/i })).toBeInTheDocument();
    });
  });
});

// ── PaymentProviderCreatePage ─────────────────────────────────────────────────

describe('PaymentProviderCreatePage', () => {
  it('renders create form with required fields', () => {
    render(<PaymentProviderCreatePage />);
    expect(screen.getByLabelText(/Provider Code/i)).toBeInTheDocument();
    expect(screen.getByLabelText(/Display Name/i)).toBeInTheDocument();
    expect(screen.getByLabelText(/Adapter Identifier/i)).toBeInTheDocument();
    expect(screen.getByLabelText(/Environment/i)).toBeInTheDocument();
  });

  it('shows mock environment warning when mock is selected', async () => {
    render(<PaymentProviderCreatePage />);
    const envSelect = screen.getByLabelText(/Environment/i);
    await userEvent.selectOptions(envSelect, 'mock');
    expect(screen.getByText(/Mock providers are permanently blocked in production/i)).toBeInTheDocument();
  });

  it('shows disabled state message', () => {
    render(<PaymentProviderCreatePage />);
    expect(screen.getByText('disabled', { selector: 'strong' }).parentElement)
      .toHaveTextContent(/created in.*disabled.*state/i);
  });

  it('calls createProvider and navigates to detail on success', async () => {
    mockCreateProvider.mockResolvedValue({ data: { data: { id: 'prov-new' } } });
    render(<PaymentProviderCreatePage />);
    await userEvent.type(screen.getByLabelText(/Provider Code/i), 'new_bank');
    await userEvent.type(screen.getByLabelText(/Display Name/i), 'New Bank');
    await userEvent.type(screen.getByLabelText(/Adapter Identifier/i), 'mock_bank');
    await userEvent.click(screen.getByRole('button', { name: /Create Provider/i }));
    await waitFor(() => {
      expect(mockCreateProvider).toHaveBeenCalled();
      expect(mockRouterPush).toHaveBeenCalledWith('/payment/providers/prov-new');
    });
  });
});
