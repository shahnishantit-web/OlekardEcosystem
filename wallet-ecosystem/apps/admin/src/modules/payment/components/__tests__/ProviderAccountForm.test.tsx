import { render, screen } from '@testing-library/react';
import { ProviderAccountForm } from '../ProviderAccountForm';
import type { PaymentProviderAccountDetail } from '@/lib/api/payment';

const mockAccount: PaymentProviderAccountDetail = {
  id: 'acc-1',
  provider_id: 'prov-1',
  merchant_account_reference: null,
  supported_currencies: ['BWP'],
  supported_payment_methods: [],
  min_amount_minor: 100,
  max_amount_minor: 500000,
  enabled_organisations: [],
  enabled_modules: [],
  country_restrictions: [],
  callback_url: null,
  callback_secret_reference: null,
  capability_flags: [],
  fee_rule_ids: [],
  daily_limit_minor: null,
  is_active: true,
  enabled_purpose_types: [],
  account_lifecycle_status: 'active',
  config_version: 1,
  display_label: null,
  display_description: null,
  display_icon_key: null,
  display_badge: null,
  display_sort_order: 0,
  submitted_by_actor_id: null,
  approved_by_actor_id: null,
  approved_at: null,
  activated_at: null,
  suspended_at: null,
  retired_at: null,
  rejection_reason: null,
  created_at: new Date().toISOString(),
  updated_at: null,
};

describe('ProviderAccountForm', () => {
  it('does not render callback_url input', () => {
    render(<ProviderAccountForm account={mockAccount} onSubmit={jest.fn()} />);
    expect(screen.queryByDisplayValue('callback_url')).toBeNull();
    expect(document.querySelector('[name="callback_url"]')).toBeNull();
  });

  it('disables all inputs when readOnly', () => {
    render(<ProviderAccountForm account={mockAccount} onSubmit={jest.fn()} readOnly />);
    const inputs = screen.getAllByRole('spinbutton');
    inputs.forEach((input) => expect(input).toBeDisabled());
  });
});
