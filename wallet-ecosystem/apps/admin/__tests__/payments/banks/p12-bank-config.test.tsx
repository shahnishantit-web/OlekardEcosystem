// T095: TypeScript tests for P12 admin bank configuration components.
import { render, screen, fireEvent } from '@testing-library/react';
import { BankStatusBadge } from '@/app/(admin)/payments/components/BankStatusBadge';
import { SecretReferenceField } from '@/app/(admin)/payments/components/SecretReferenceField';
import { LifecyclePanel } from '@/app/(admin)/payments/components/LifecyclePanel';
import { ApiOperationEditor } from '@/app/(admin)/payments/components/ApiOperationEditor';
import type { AccountLifecycleStatus } from '@/lib/api/payments/banks';

// Minimal router mock
jest.mock('next/navigation', () => ({
  useRouter: () => ({ push: jest.fn(), back: jest.fn() }),
}));

describe('BankStatusBadge', () => {
  it('renders all bank lifecycle status badges', () => {
    const statuses = ['draft', 'active', 'suspended', 'retired'] as const;
    statuses.forEach(status => {
      const { unmount } = render(<BankStatusBadge status={status} type="bank" />);
      expect(screen.getByText(status)).toBeInTheDocument();
      unmount();
    });
  });

  it('renders all account lifecycle status badges', () => {
    const statuses: AccountLifecycleStatus[] = [
      'draft', 'validated', 'sandbox_testing', 'evidence_review',
      'approved', 'active', 'suspended', 'retired',
    ];
    statuses.forEach(status => {
      const { unmount } = render(<BankStatusBadge status={status} type="account" />);
      expect(screen.getByText(status.replace(/_/g, ' '))).toBeInTheDocument();
      unmount();
    });
  });
});

describe('SecretReferenceField', () => {
  it('never renders raw secret values', () => {
    render(
      <SecretReferenceField
        label="Callback Secret"
        value={{ key: 'payments/fnbz/sandbox/secret', masked: '****' }}
      />
    );

    const element = screen.getByText('payments/fnbz/sandbox/secret');
    expect(element).toBeInTheDocument();

    // The masked value should be shown, no raw secret
    expect(screen.getByText('****')).toBeInTheDocument();

    // No raw credential patterns in DOM
    expect(document.body.textContent).not.toMatch(/Bearer\s/);
    expect(document.body.textContent).not.toMatch(/sk_/);
  });

  it('renders copy button for key name only', () => {
    render(
      <SecretReferenceField
        label="API Key"
        value={{ key: 'payments/fnbz/sandbox/api_key', masked: '****' }}
      />
    );
    expect(screen.getByRole('button', { name: /copy key name/i })).toBeInTheDocument();
  });

  it('renders not-configured state when value is null', () => {
    render(<SecretReferenceField label="Missing Key" value={null} />);
    expect(screen.getByText(/not configured/i)).toBeInTheDocument();
  });
});

describe('LifecyclePanel', () => {
  const noop = async () => {};

  it('renders submit action for draft status', () => {
    render(<LifecyclePanel status="draft" bankId="b1" accountId="a1" onTransition={noop} />);
    expect(screen.getByRole('button', { name: /submit for validation/i })).toBeInTheDocument();
  });

  it('renders approve and reject for evidence_review status', () => {
    render(<LifecyclePanel status="evidence_review" bankId="b1" accountId="a1" onTransition={noop} />);
    expect(screen.getByRole('button', { name: /approve/i })).toBeInTheDocument();
    expect(screen.getByRole('button', { name: /reject/i })).toBeInTheDocument();
  });

  it('renders suspend for active status', () => {
    render(<LifecyclePanel status="active" bankId="b1" accountId="a1" onTransition={noop} />);
    expect(screen.getByRole('button', { name: /suspend/i })).toBeInTheDocument();
  });

  it('renders no actions for retired status', () => {
    render(<LifecyclePanel status="retired" bankId="b1" accountId="a1" onTransition={noop} />);
    expect(screen.getByText(/no further transitions/i)).toBeInTheDocument();
  });

  it('shows approval conflict error when approvalConflict is true', () => {
    render(
      <LifecyclePanel
        status="evidence_review"
        bankId="b1"
        accountId="a1"
        onTransition={noop}
        approvalConflict={true}
      />
    );
    expect(screen.getByText(/approval conflict/i)).toBeInTheDocument();
  });
});

describe('ApiOperationEditor', () => {
  it('shows HTTPS indicator for valid URL', () => {
    const onChange = jest.fn();
    render(<ApiOperationEditor initial={{ base_url: 'https://api.example.com' }} onChange={onChange} />);
    expect(screen.getByText('✓ HTTPS')).toBeInTheDocument();
  });

  it('shows error for non-HTTPS URL', () => {
    const onChange = jest.fn();
    render(<ApiOperationEditor initial={{ base_url: 'http://api.example.com' }} onChange={onChange} />);
    const input = screen.getByPlaceholderText('https://api.provider.com');
    fireEvent.blur(input);
    expect(screen.getByText(/must use https/i)).toBeInTheDocument();
  });

  it('renders operation type selector', () => {
    render(<ApiOperationEditor onChange={jest.fn()} />);
    expect(screen.getByText('Select…')).toBeInTheDocument();
  });
});
